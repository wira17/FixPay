<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db   = getDB();
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

/* ── Handle mark paid manual ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'mark_paid') {
        $invId  = (int)($_POST['invoice_id'] ?? 0);
        $method = sanitize($_POST['method']    ?? '');
        $ref    = sanitize($_POST['reference'] ?? '');
        $qi = $db->prepare("SELECT total FROM invoices WHERE id=?");
        $qi->execute([$invId]);
        $row = $qi->fetch();
        if ($row) {
            $db->prepare("UPDATE invoices SET status='paid',paid_at=NOW() WHERE id=?")->execute([$invId]);
            $db->prepare("INSERT INTO payments (invoice_id,amount,method,reference) VALUES (?,?,?,?)")
               ->execute([$invId, $row['total'], $method, $ref]);
        }
        header('Location: payments.php?saved=1'); exit;
    }

    /* Admin verifikasi / tolak dari tab pending */
    if (in_array($action, ['verify','reject']) && $role === 'admin') {
        $proofId = (int)($_POST['proof_id']   ?? 0);
        $invId   = (int)($_POST['invoice_id'] ?? 0);
        $newStat = $action === 'verify' ? 'verified' : 'rejected';

        try {
            $db->prepare("UPDATE payment_proofs SET status=?,verified_by=?,verified_at=NOW() WHERE id=?")
               ->execute([$newStat, $uid, $proofId]);
        } catch (PDOException $e) {}

        $invRow = $db->prepare("SELECT user_id,invoice_number,client_email FROM invoices WHERE id=?");
        $invRow->execute([$invId]); $invRow = $invRow->fetch();
        /* Kumpulkan penerima notif */
        $notifTo = [];
        $proofUQ = $db->prepare("SELECT user_id FROM payment_proofs WHERE id=?");
        $proofUQ->execute([$proofId]); $proofUQ = $proofUQ->fetch();
        if ($proofUQ) $notifTo[] = (int)$proofUQ['user_id'];
        if ($invRow && !empty($invRow['client_email'])) {
            $mu = $db->prepare("SELECT id FROM users WHERE email=?");
            $mu->execute([$invRow['client_email']]); $mu = $mu->fetchColumn();
            if ($mu && !in_array((int)$mu, $notifTo)) $notifTo[] = (int)$mu;
        }
        if ($invRow && !in_array((int)$invRow['user_id'], $notifTo))
            $notifTo[] = (int)$invRow['user_id'];

        if ($action === 'verify') {
            $db->prepare("UPDATE invoices SET status='paid',paid_at=NOW() WHERE id=?")->execute([$invId]);
            foreach ($notifTo as $tid) {
                addNotification($tid, '✓ Pembayaran Dikonfirmasi',
                    "Invoice {$invRow['invoice_number']} telah diverifikasi LUNAS oleh admin!",
                    'success', "invoice_detail.php?id={$invId}");
            }
        } else {
            foreach ($notifTo as $tid) {
                addNotification($tid, 'Bukti Transfer Ditolak',
                    "Bukti transfer invoice {$invRow['invoice_number']} ditolak. Harap unggah ulang.",
                    'danger', "payment_proof.php?invoice_id={$invId}");
            }
        }
        header('Location: payments.php?tab=pending&saved=1'); exit;
    }
}

/* ── Tab aktif ── */
$tab = $_GET['tab'] ?? 'all';

/* ── Load pending proofs (admin only) ── */
$pendingProofs = [];
$pendingCount  = 0;
if ($role === 'admin') {
    try {
        $pq = $db->query("SELECT pp.*, i.invoice_number, i.client_name, i.total AS inv_total,
                           i.id AS invoice_id, u.name AS uploader_name, u.email AS uploader_email
                           FROM payment_proofs pp
                           LEFT JOIN invoices i ON pp.invoice_id = i.id
                           LEFT JOIN users u ON pp.user_id = u.id
                           WHERE pp.status = 'pending'
                           ORDER BY pp.created_at DESC");
        $pendingProofs = $pq->fetchAll();
        $pendingCount  = count($pendingProofs);
    } catch (PDOException $e) {}
}

/* ── Filters untuk tab all ── */
$search = trim($_GET['q']      ?? '');
$status = trim($_GET['status'] ?? '');

if ($role === 'admin') {
    $base  = "FROM invoices i LEFT JOIN users u ON i.user_id=u.id";
    $where = []; $params = [];
} else {
    $memberEmail = $_SESSION['email'] ?? '';
    $base  = "FROM invoices i";
    $where = ["(i.user_id=$uid OR i.client_email=" . $db->quote($memberEmail) . ")"]; $params = [];
}
if ($search) { $where[] = "(i.invoice_number LIKE ? OR i.client_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($status) { $where[] = "i.status=?"; $params[] = $status; }
$wSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
$sel  = $role === 'admin' ? "i.*, u.name AS owner_name" : "i.*";
$qs   = $db->prepare("SELECT $sel $base $wSQL ORDER BY i.created_at DESC LIMIT 60");
$qs->execute($params);
$invoices = $qs->fetchAll();

/* ── Summary ── */
if ($role === 'admin') {
    $totalAll    = $db->query("SELECT COALESCE(SUM(total),0) FROM invoices")->fetchColumn();
    $totalPaid   = $db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid'")->fetchColumn();
    $totalUnpaid = $db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status IN ('sent','overdue')")->fetchColumn();
} else {
    $mq = function($sql) use ($db,$uid,$memberEmail){
        $s = $db->prepare($sql); $s->execute([$uid,$memberEmail]); return $s->fetchColumn();
    };
    $totalAll    = $mq("SELECT COALESCE(SUM(total),0) FROM invoices WHERE user_id=? OR client_email=?");
    $totalPaid   = $mq("SELECT COALESCE(SUM(total),0) FROM invoices WHERE (user_id=? OR client_email=?) AND status='paid'");
    $totalUnpaid = $mq("SELECT COALESCE(SUM(total),0) FROM invoices WHERE (user_id=? OR client_email=?) AND status IN ('sent','overdue')");
}

$statusMap = [
    'paid'    =>['bg'=>'#0d2818','color'=>'#4ade80','border'=>'#1a4d2e','dot'=>'#22c55e','label'=>'Lunas'],
    'sent'    =>['bg'=>'#2a1f06','color'=>'#fbbf24','border'=>'#4a3510','dot'=>'#f59e0b','label'=>'Terkirim'],
    'draft'   =>['bg'=>'#161b27','color'=>'#94a3b8','border'=>'#1e2535','dot'=>'#64748b','label'=>'Draft'],
    'overdue' =>['bg'=>'#2a0d0d','color'=>'#f87171','border'=>'#4d1a1a','dot'=>'#ef4444','label'=>'Jatuh Tempo'],
];

$pageTitle   = 'Pembayaran';
$pageSubtitle = 'Riwayat & Kelola Pembayaran';
$activeMenu  = 'payments';

$extraJs = <<<'JS'
function openPayModal(id, num, amt) {
    document.getElementById('payInvId').value      = id;
    document.getElementById('payInvNum').textContent = num;
    document.getElementById('payInvAmt').textContent = 'Rp ' + Math.round(amt).toLocaleString('id-ID');
    document.getElementById('payModal').classList.add('open');
}
JS;

require_once 'includes/header.php';
?>

<style>
/* ── Tabs ── */
.pay-tabs { display:flex; gap:4px; margin-bottom:16px; background:var(--surf2); border-radius:10px; padding:4px; }
.pay-tab  {
  flex:1; padding:8px 12px; border:none; background:none; border-radius:7px;
  font-family:'DM Sans',sans-serif; font-size:.8rem; font-weight:400;
  color:var(--txt3); cursor:pointer; transition:all .15s;
  display:flex; align-items:center; justify-content:center; gap:6px;
}
.pay-tab.active { background:var(--surf); color:var(--txt); font-weight:500; box-shadow:0 1px 4px rgba(0,0,0,.3); }
.pay-tab .badge {
  display:inline-flex; align-items:center; justify-content:center;
  width:18px; height:18px; border-radius:50%; font-size:.65rem; font-weight:700;
  background:rgba(245,158,11,.2); color:var(--amber);
}

/* ── Proof card ── */
.proof-card {
  border:1px solid var(--line); border-radius:var(--radius);
  background:var(--surf); margin-bottom:10px;
  transition:border-color .15s;
}
.proof-card:hover { border-color:var(--gring); }
</style>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success"><i class="ph ph-check-circle"></i> Berhasil disimpan.</div>
<?php endif; ?>

<!-- Summary Cards -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px">
  <?php
  $cards = [
    ['icon'=>'ph-money',        'label'=>'Total Tagihan',   'val'=>formatRupiah($totalAll),    'bg'=>'rgba(96,165,250,.1)', 'color'=>'var(--blue)'],
    ['icon'=>'ph-check-circle', 'label'=>'Sudah Dibayar',   'val'=>formatRupiah($totalPaid),   'bg'=>'rgba(34,197,94,.1)',  'color'=>'var(--green)'],
    ['icon'=>'ph-clock',        'label'=>'Belum Dibayar',   'val'=>formatRupiah($totalUnpaid), 'bg'=>'rgba(239,68,68,.1)',  'color'=>'var(--red)'],
  ];
  foreach ($cards as $card): ?>
  <div class="card-box anim" style="padding:14px 16px">
    <div style="width:34px;height:34px;border-radius:9px;background:<?= $card['bg'] ?>;color:<?= $card['color'] ?>;display:grid;place-items:center;font-size:.95rem;margin-bottom:10px">
      <i class="ph <?= $card['icon'] ?>"></i>
    </div>
    <div style="font-size:.7rem;color:var(--txt3);margin-bottom:3px"><?= $card['label'] ?></div>
    <div style="font-family:'Cormorant Garamond',serif;font-size:1.15rem"><?= $card['val'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tabs (admin only) -->
<?php if ($role === 'admin'): ?>
<div class="pay-tabs">
  <button class="pay-tab <?= $tab==='all'?'active':'' ?>" onclick="location.href='payments.php?tab=all'">
    <i class="ph ph-list"></i> Semua Invoice
  </button>
  <button class="pay-tab <?= $tab==='pending'?'active':'' ?>" onclick="location.href='payments.php?tab=pending'">
    <i class="ph ph-clock"></i> Menunggu Verifikasi
    <?php if ($pendingCount > 0): ?>
    <span class="badge"><?= $pendingCount ?></span>
    <?php endif; ?>
  </button>
</div>
<?php endif; ?>

<!-- ════ TAB: MENUNGGU VERIFIKASI ════ -->
<?php if ($tab === 'pending' && $role === 'admin'): ?>

<?php if (empty($pendingProofs)): ?>
<div class="card-box">
  <div class="empty-state">
    <i class="ph ph-check-circle" style="color:var(--green)"></i>
    <h3>Tidak ada yang pending</h3>
    <p>Semua bukti transfer sudah diverifikasi.</p>
  </div>
</div>

<?php else: ?>
<div style="font-size:.8rem;color:var(--txt3);margin-bottom:10px">
  <i class="ph ph-warning-circle" style="color:var(--amber);margin-right:4px"></i>
  <?= $pendingCount ?> bukti transfer menunggu verifikasi Anda
</div>

<?php foreach ($pendingProofs as $proof):
  $isImg = in_array(strtolower(pathinfo($proof['filename'],PATHINFO_EXTENSION)),['jpg','jpeg','png']);
?>
<div class="proof-card anim">
  <!-- Header -->
  <div style="padding:13px 16px;border-bottom:1px solid var(--line2);
              display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:36px;height:36px;border-radius:9px;background:rgba(245,158,11,.1);
                  color:var(--amber);border:1px solid rgba(245,158,11,.2);
                  display:grid;place-items:center;font-size:.9rem;flex-shrink:0">
        <i class="ph ph-clock"></i>
      </div>
      <div>
        <div style="font-weight:600;font-size:.85rem">
          <a href="invoice_detail.php?id=<?= $proof['invoice_id'] ?>"
             style="color:var(--txt);text-decoration:none">
            <?= htmlspecialchars($proof['invoice_number']) ?>
          </a>
        </div>
        <div style="font-size:.73rem;color:var(--txt3)">
          Klien: <?= htmlspecialchars($proof['client_name']) ?>
        </div>
      </div>
    </div>
    <div style="text-align:right">
      <div style="font-family:'Cormorant Garamond',serif;font-size:1.1rem;color:var(--goldh)">
        <?= formatRupiah($proof['amount']) ?>
      </div>
      <div style="font-size:.7rem;color:var(--txt3)">
        Dikirim oleh: <strong style="color:var(--txt)"><?= htmlspecialchars($proof['uploader_name']) ?></strong>
      </div>
    </div>
  </div>

  <!-- Body -->
  <div style="padding:14px 16px;display:grid;grid-template-columns:auto 1fr;gap:14px;align-items:start">

    <!-- Preview -->
    <div style="width:140px;flex-shrink:0">
      <?php if ($isImg): ?>
      <img src="uploads/proofs/<?= htmlspecialchars($proof['filename']) ?>"
           alt="Bukti" onclick="window.open(this.src,'_blank')"
           style="width:140px;height:100px;object-fit:cover;border-radius:8px;
                  border:1px solid var(--line);cursor:pointer;display:block">
      <p style="font-size:.65rem;color:var(--txt3);text-align:center;margin-top:3px">Klik untuk perbesar</p>
      <?php else: ?>
      <a href="uploads/proofs/<?= htmlspecialchars($proof['filename']) ?>" target="_blank"
         style="display:flex;flex-direction:column;align-items:center;justify-content:center;
                width:140px;height:100px;border-radius:8px;border:1px solid var(--line);
                text-decoration:none;color:var(--txt3);gap:6px;font-size:.75rem;
                background:rgba(255,255,255,.02)">
        <i class="ph ph-file-pdf" style="font-size:1.8rem;color:var(--red)"></i>
        Lihat PDF
      </a>
      <?php endif; ?>
    </div>

    <!-- Detail -->
    <div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.78rem;margin-bottom:12px">
        <div>
          <span style="color:var(--txt3)">Jumlah Transfer</span>
          <div style="font-weight:600;color:var(--goldh)"><?= formatRupiah($proof['amount']) ?></div>
        </div>
        <div>
          <span style="color:var(--txt3)">Metode</span>
          <div style="text-transform:capitalize"><?= htmlspecialchars($proof['method']) ?></div>
        </div>
        <?php if ($proof['reference']): ?>
        <div>
          <span style="color:var(--txt3)">No. Referensi</span>
          <div style="font-family:monospace;font-size:.8rem"><?= htmlspecialchars($proof['reference']) ?></div>
        </div>
        <?php endif; ?>
        <div>
          <span style="color:var(--txt3)">Total Invoice</span>
          <div style="font-weight:500"><?= formatRupiah($proof['inv_total']) ?></div>
        </div>
        <div>
          <span style="color:var(--txt3)">Diunggah</span>
          <div><?= date('d M Y H:i', strtotime($proof['created_at'])) ?></div>
        </div>
        <?php if ($proof['notes']): ?>
        <div style="grid-column:span 2">
          <span style="color:var(--txt3)">Catatan dari member</span>
          <div style="color:var(--txt2);font-style:italic">"<?= htmlspecialchars($proof['notes']) ?>"</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Selisih warning -->
      <?php $diff = abs((float)$proof['amount'] - (float)$proof['inv_total']); ?>
      <?php if ($diff > 0): ?>
      <div style="padding:7px 10px;border-radius:7px;font-size:.75rem;margin-bottom:12px;
                  background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);color:var(--amber);
                  display:flex;align-items:center;gap:6px">
        <i class="ph ph-warning-circle"></i>
        Selisih <?= formatRupiah($diff) ?> dari total invoice
        <?= $proof['amount'] > $proof['inv_total'] ? '(lebih bayar)' : '(kurang bayar)' ?>
      </div>
      <?php endif; ?>

      <!-- Action buttons -->
      <div style="display:flex;gap:8px">
        <form method="POST" style="flex:1">
          <input type="hidden" name="_action"    value="verify">
          <input type="hidden" name="proof_id"   value="<?= $proof['id'] ?>">
          <input type="hidden" name="invoice_id" value="<?= $proof['invoice_id'] ?>">
          <button type="submit" class="btn btn-sm"
                  style="width:100%;justify-content:center;height:36px;
                         background:rgba(34,197,94,.15);color:var(--green);
                         border:1px solid rgba(34,197,94,.3);font-size:.8rem">
            <i class="ph ph-check-circle"></i> Verifikasi Lunas
          </button>
        </form>
        <a href="payment_proof.php?invoice_id=<?= $proof['invoice_id'] ?>"
           class="btn btn-ghost btn-sm" style="height:36px" title="Lihat detail">
          <i class="ph ph-arrow-square-out"></i>
        </a>
        <form method="POST">
          <input type="hidden" name="_action"    value="reject">
          <input type="hidden" name="proof_id"   value="<?= $proof['id'] ?>">
          <input type="hidden" name="invoice_id" value="<?= $proof['invoice_id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm" style="height:36px" title="Tolak">
            <i class="ph ph-x-circle"></i>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<?php else: ?>
<!-- ════ TAB: SEMUA INVOICE ════ -->

<!-- Filter -->
<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
  <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
    <input type="hidden" name="tab" value="all">
    <div style="position:relative;flex:1;min-width:180px">
      <i class="ph ph-magnifying-glass" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:.9rem;pointer-events:none"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
             placeholder="Cari invoice / klien..." class="form-input" style="padding-left:30px;height:34px">
    </div>
    <select name="status" onchange="this.form.submit()" class="form-select" style="height:34px;width:auto">
      <option value="">Semua Status</option>
      <option value="paid"    <?= $status==='paid'   ?'selected':'' ?>>Lunas</option>
      <option value="sent"    <?= $status==='sent'   ?'selected':'' ?>>Terkirim</option>
      <option value="overdue" <?= $status==='overdue'?'selected':'' ?>>Jatuh Tempo</option>
      <option value="draft"   <?= $status==='draft'  ?'selected':'' ?>>Draft</option>
    </select>
    <?php if ($search||$status): ?>
    <a href="payments.php" class="btn btn-ghost" style="height:34px;color:var(--red)"><i class="ph ph-x"></i></a>
    <?php endif; ?>
  </form>
</div>

<div class="card-box anim">
  <?php if (empty($invoices)): ?>
  <div class="empty-state"><i class="ph ph-credit-card"></i><h3>Tidak ada data</h3><p>Belum ada invoice ditemukan.</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>No. Invoice</th>
          <th>Klien</th>
          <?php if ($role==='admin'): ?><th>Pengguna</th><?php endif; ?>
          <th>Total</th>
          <th>Status</th>
          <th class="hide-mobile">Jatuh Tempo</th>
          <th class="hide-mobile">Dibayar</th>
          <th style="width:80px;text-align:center">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv):
          $st = $statusMap[$inv['status']] ?? $statusMap['draft'];
          /* Cek apakah ada proof pending untuk invoice ini */
          $hasPending = false;
          try {
            $chk = $db->prepare("SELECT COUNT(*) FROM payment_proofs WHERE invoice_id=? AND status='pending'");
            $chk->execute([$inv['id']]);
            $hasPending = $chk->fetchColumn() > 0;
          } catch (PDOException $e) {}
        ?>
        <tr>
          <td>
            <a href="invoice_detail.php?id=<?= $inv['id'] ?>"
               style="color:var(--txt);text-decoration:none;font-weight:600;font-size:.79rem">
              <?= htmlspecialchars($inv['invoice_number']) ?>
            </a>
            <?php if ($hasPending): ?>
            <span style="display:inline-flex;align-items:center;gap:3px;margin-left:5px;
                         padding:1px 6px;border-radius:99px;font-size:.62rem;font-weight:600;
                         background:rgba(245,158,11,.15);color:var(--amber);
                         border:1px solid rgba(245,158,11,.25)">
              <i class="ph ph-clock"></i> Bukti masuk
            </span>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem"><?= htmlspecialchars($inv['client_name']) ?></td>
          <?php if ($role==='admin'): ?>
          <td style="font-size:.72rem;color:var(--txt3)"><?= htmlspecialchars($inv['owner_name']??'—') ?></td>
          <?php endif; ?>
          <td style="font-weight:600"><?= formatRupiah($inv['total']) ?></td>
          <td>
            <span class="status-pill"
                  style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
              <span class="status-dot" style="background:<?= $st['dot'] ?>"></span>
              <?= $st['label'] ?>
            </span>
          </td>
          <td class="hide-mobile" style="font-size:.75rem;color:var(--txt2)"><?= formatTanggal($inv['due_date']) ?></td>
          <td class="hide-mobile" style="font-size:.75rem;color:var(--txt2)">
            <?= $inv['paid_at'] ? formatTanggal($inv['paid_at']) : '—' ?>
          </td>
          <td>
            <div style="display:flex;gap:4px;justify-content:center;align-items:center">
              <a href="invoice_detail.php?id=<?= $inv['id'] ?>" class="btn btn-ghost btn-sm">
                <i class="ph ph-eye"></i>
              </a>
              <?php if ($hasPending && $role==='admin'): ?>
              <a href="payments.php?tab=pending" class="btn btn-sm"
                 style="background:rgba(245,158,11,.15);color:var(--amber);border:1px solid rgba(245,158,11,.25);height:28px;padding:0 8px;font-size:.72rem">
                <i class="ph ph-clock"></i> Cek
              </a>
              <?php elseif ($inv['status'] !== 'paid' && $role !== 'member'): ?>
              <button onclick="openPayModal(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number']) ?>', <?= $inv['total'] ?>)"
                      class="btn btn-primary btn-sm">
                <i class="ph ph-check"></i>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Mark Paid Modal -->
<div class="modal-overlay" id="payModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-head">
      <span class="modal-title" style="color:var(--green)"><i class="ph ph-check-circle"></i> Catat Pembayaran Manual</span>
      <button class="modal-close" onclick="document.getElementById('payModal').classList.remove('open')"><i class="ph ph-x"></i></button>
    </div>
    <form method="POST" action="payments.php">
      <input type="hidden" name="_action"    value="mark_paid">
      <input type="hidden" name="invoice_id" id="payInvId">
      <div class="modal-body">
        <p style="font-size:.83rem;color:var(--txt2);margin-bottom:14px">
          Invoice <strong id="payInvNum" style="color:var(--txt)"></strong> —
          <strong id="payInvAmt" style="color:var(--goldh)"></strong>
        </p>
        <div class="form-group">
          <label class="form-label">Metode Pembayaran</label>
          <select name="method" class="form-select">
            <option value="transfer">Transfer Bank</option>
            <option value="cash">Tunai</option>
            <option value="qris">QRIS</option>
            <option value="ewallet">E-Wallet</option>
            <option value="other">Lainnya</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Nomor Referensi <span style="color:var(--txt3);font-weight:400">(opsional)</span></label>
          <input type="text" name="reference" class="form-input" placeholder="No. bukti / kode transaksi">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost"
                onclick="document.getElementById('payModal').classList.remove('open')">Batal</button>
        <button type="submit" class="btn btn-primary"
                style="background:linear-gradient(135deg,var(--green),#16a34a);color:#fff">
          <i class="ph ph-check-circle"></i> Konfirmasi Lunas
        </button>
      </div>
    </form>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>