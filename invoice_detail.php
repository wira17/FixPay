<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db   = getDB();
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$id   = (int)($_GET['id'] ?? 0);

$memberEmail = $_SESSION['email'] ?? '';
if ($role === 'admin') {
    $q = $db->prepare("SELECT i.*, u.name AS owner_name, u.email AS owner_email FROM invoices i LEFT JOIN users u ON i.user_id=u.id WHERE i.id=?");
    $q->execute([$id]);
} elseif ($role === 'member') {
    /* Member akses via user_id ATAU client_email */
    $q = $db->prepare("SELECT i.* FROM invoices i WHERE i.id=? AND (i.user_id=? OR i.client_email=?)");
    $q->execute([$id, $uid, $memberEmail]);
} else {
    $q = $db->prepare("SELECT i.* FROM invoices i WHERE i.id=? AND i.user_id=?");
    $q->execute([$id, $uid]);
}
$inv = $q->fetch();
if (!$inv) { header('Location: invoices.php'); exit; }

$qi = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$qi->execute([$id]);
$items = $qi->fetchAll();

$statusMap = [
    'paid'      => ['bg'=>'#0d2818','color'=>'#4ade80','border'=>'#1a4d2e','dot'=>'#22c55e','label'=>'Lunas'],
    'sent'      => ['bg'=>'#2a1f06','color'=>'#fbbf24','border'=>'#4a3510','dot'=>'#f59e0b','label'=>'Terkirim'],
    'draft'     => ['bg'=>'#161b27','color'=>'#94a3b8','border'=>'#1e2535','dot'=>'#64748b','label'=>'Draft'],
    'overdue'   => ['bg'=>'#2a0d0d','color'=>'#f87171','border'=>'#4d1a1a','dot'=>'#ef4444','label'=>'Jatuh Tempo'],
    'cancelled' => ['bg'=>'#161b27','color'=>'#64748b','border'=>'#1e2535','dot'=>'#475569','label'=>'Batal'],
];
$st = $statusMap[$inv['status']] ?? $statusMap['draft'];

$pageTitle   = 'Detail Invoice';
$pageSubtitle = $inv['invoice_number'];
$activeMenu  = 'invoices';
require_once 'includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success"><i class="ph ph-check-circle"></i> Invoice berhasil disimpan.</div>
<?php endif; ?>

<!-- Action Bar -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <a href="invoices.php" class="btn btn-ghost btn-sm"><i class="ph ph-arrow-left"></i> Kembali</a>
  <div style="flex:1"></div>
  <?php if ($role !== 'member'): ?>
  <a href="invoice_form.php?id=<?= $id ?>" class="btn btn-ghost btn-sm"><i class="ph ph-pencil-simple"></i> <span class="hide-mobile">Edit</span></a>
  <?php endif; ?>
  <button onclick="window.print()" class="btn btn-ghost btn-sm"><i class="ph ph-printer"></i> <span class="hide-mobile">Cetak</span></button>
  <a href="invoice_print.php?id=<?= $id ?>" target="_blank" class="btn btn-ghost btn-sm"><i class="ph ph-file-pdf"></i> <span class="hide-mobile">Lihat PDF</span></a>
  <?php if ($inv['status'] === 'paid'): ?>
  <a href="payment_receipt.php?invoice_id=<?= $id ?>" target="_blank"
     class="btn btn-primary btn-sm"
     style="background:linear-gradient(135deg,var(--green),#16a34a);color:#fff">
    <i class="ph ph-receipt"></i>
    <span class="hide-mobile">Cetak Kwitansi</span>
  </a>
  <?php else: ?>
  <a href="payment_proof.php?invoice_id=<?= $id ?>" class="btn btn-primary btn-sm">
    <i class="ph ph-upload-simple"></i> <span class="hide-mobile"><?= $role === 'member' ? 'Upload Bukti Transfer' : 'Bukti Transfer' ?></span>
  </a>
  <?php endif; ?>
</div>

<!-- Banner Lunas untuk member -->
<?php if ($role === 'member' && $inv['status'] === 'paid'): ?>
<div style="max-width:860px;margin:0 auto 14px;padding:14px 18px;
            background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);
            border-radius:12px;display:flex;align-items:center;gap:12px">
  <div style="width:40px;height:40px;border-radius:50%;background:rgba(34,197,94,.15);
              border:1px solid rgba(34,197,94,.3);display:grid;place-items:center;
              color:var(--green);font-size:1.2rem;flex-shrink:0">
    <i class="ph ph-check-circle"></i>
  </div>
  <div style="flex:1">
    <div style="font-weight:600;font-size:.88rem;color:var(--green);margin-bottom:2px">
      ✓ Tagihan Ini Sudah Lunas
    </div>
    <div style="font-size:.77rem;color:var(--txt2)">
      Pembayaran Anda telah dikonfirmasi oleh admin.
      <?php if ($inv['paid_at']): ?>
      Dilunasi pada <strong><?= formatTanggal($inv['paid_at'], 'd M Y H:i') ?></strong>.
      <?php endif; ?>
    </div>
  </div>
  <a href="payment_proof.php?invoice_id=<?= $id ?>"
     style="font-size:.76rem;color:var(--green);text-decoration:none;
            display:flex;align-items:center;gap:4px;flex-shrink:0;
            padding:5px 10px;border-radius:7px;border:1px solid rgba(34,197,94,.25);
            background:rgba(34,197,94,.07)">
    <i class="ph ph-receipt"></i> Lihat Bukti
  </a>
</div>
<?php elseif ($role === 'member' && in_array($inv['status'], ['sent','overdue'])): ?>
<?php
/* Cek apakah ada proof pending */
$checkProof = false;
try {
    $cp = $db->prepare("SELECT COUNT(*) FROM payment_proofs WHERE invoice_id=? AND status='pending'");
    $cp->execute([$id]);
    $checkProof = $cp->fetchColumn() > 0;
} catch (PDOException $e) {}
?>
<?php if ($checkProof): ?>
<div style="max-width:860px;margin:0 auto 14px;padding:13px 18px;
            background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.22);
            border-radius:12px;display:flex;align-items:center;gap:12px">
  <div style="width:36px;height:36px;border-radius:50%;background:rgba(245,158,11,.15);
              display:grid;place-items:center;color:var(--amber);font-size:1rem;flex-shrink:0">
    <i class="ph ph-clock"></i>
  </div>
  <div style="flex:1">
    <div style="font-weight:600;font-size:.85rem;color:var(--amber);margin-bottom:2px">
      ⏳ Bukti Transfer Sedang Diverifikasi
    </div>
    <div style="font-size:.76rem;color:var(--txt2)">
      Admin sedang memeriksa bukti transfer Anda. Harap tunggu konfirmasi.
    </div>
  </div>
  <a href="payment_proof.php?invoice_id=<?= $id ?>"
     style="font-size:.76rem;color:var(--amber);text-decoration:none;
            display:flex;align-items:center;gap:4px;flex-shrink:0;
            padding:5px 10px;border-radius:7px;border:1px solid rgba(245,158,11,.25);
            background:rgba(245,158,11,.07)">
    <i class="ph ph-eye"></i> Lihat Status
  </a>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Invoice Detail Card -->
<div class="card-box anim" style="max-width:860px;margin:0 auto">

  <!-- Header Invoice -->
  <div style="padding:24px 24px 0;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px">
    <div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <div style="width:32px;height:32px;background:linear-gradient(135deg,var(--gold),var(--goldh));border-radius:8px;display:grid;place-items:center;color:#05080e;font-size:.9rem"><i class="ph-bold ph-receipt"></i></div>
        <span style="font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:600;background:linear-gradient(90deg,var(--gold),var(--goldh));-webkit-background-clip:text;-webkit-text-fill-color:transparent">FixPay</span>
      </div>
      <div style="font-size:.75rem;color:var(--txt3)">Platform Invoice Profesional</div>
    </div>
    <div style="text-align:right">
      <div style="font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;color:var(--txt);margin-bottom:6px"><?= htmlspecialchars($inv['invoice_number']) ?></div>
      <span class="status-pill" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>;font-size:.75rem;padding:4px 12px">
        <span class="status-dot" style="background:<?= $st['dot'] ?>"></span>
        <?= $st['label'] ?>
      </span>
    </div>
  </div>

  <div style="border-top:1px solid var(--line);margin:20px 24px 0"></div>

  <!-- Dates & From/To -->
  <div style="padding:20px 24px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px">
    <div>
      <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--txt3);margin-bottom:5px">Dari</div>
      <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($_SESSION['name']) ?></div>
      <div style="font-size:.75rem;color:var(--txt2)"><?= htmlspecialchars($_SESSION['email']) ?></div>
    </div>
    <div>
      <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--txt3);margin-bottom:5px">Kepada</div>
      <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($inv['client_name']) ?></div>
      <?php if ($inv['client_email']): ?>
      <div style="font-size:.75rem;color:var(--txt2)"><?= htmlspecialchars($inv['client_email']) ?></div>
      <?php endif; ?>
      <?php if ($inv['client_address']): ?>
      <div style="font-size:.72rem;color:var(--txt3);margin-top:2px"><?= htmlspecialchars($inv['client_address']) ?></div>
      <?php endif; ?>
    </div>
    <div>
      <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.08em;color:var(--txt3);margin-bottom:5px">Tanggal</div>
      <div style="font-size:.8rem;margin-bottom:5px">
        <span style="color:var(--txt3)">Diterbitkan:</span>
        <span style="font-weight:500;margin-left:4px"><?= formatTanggal($inv['issue_date']) ?></span>
      </div>
      <div style="font-size:.8rem">
        <span style="color:var(--txt3)">Jatuh Tempo:</span>
        <span style="font-weight:500;margin-left:4px;color:<?= (strtotime($inv['due_date'])<time()&&$inv['status']!='paid')?'var(--red)':'var(--txt)' ?>">
          <?= formatTanggal($inv['due_date']) ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Items Table -->
  <div style="margin:0 24px">
    <table style="width:100%;border-collapse:collapse;border:1px solid var(--line2);border-radius:8px;overflow:hidden">
      <thead>
        <tr style="background:rgba(255,255,255,.025)">
          <th style="padding:9px 12px;font-size:.64rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--txt3);text-align:left;border-bottom:1px solid var(--line2)">Deskripsi</th>
          <th style="padding:9px 12px;font-size:.64rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--txt3);text-align:center;border-bottom:1px solid var(--line2);width:80px">Qty</th>
          <th style="padding:9px 12px;font-size:.64rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--txt3);text-align:right;border-bottom:1px solid var(--line2);width:130px">Harga</th>
          <th style="padding:9px 12px;font-size:.64rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--txt3);text-align:right;border-bottom:1px solid var(--line2);width:130px">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
        <tr>
          <td style="padding:10px 12px;font-size:.81rem;border-bottom:1px solid var(--line2)"><?= htmlspecialchars($it['description']) ?></td>
          <td style="padding:10px 12px;font-size:.8rem;text-align:center;border-bottom:1px solid var(--line2);color:var(--txt2)"><?= $it['quantity'] ?></td>
          <td style="padding:10px 12px;font-size:.8rem;text-align:right;border-bottom:1px solid var(--line2);color:var(--txt2)"><?= formatRupiah($it['unit_price']) ?></td>
          <td style="padding:10px 12px;font-size:.81rem;text-align:right;border-bottom:1px solid var(--line2);font-weight:500"><?= formatRupiah($it['total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Totals -->
  <div style="padding:16px 24px;display:flex;justify-content:flex-end">
    <div style="width:260px">
      <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:.8rem;border-bottom:1px solid var(--line2)">
        <span style="color:var(--txt2)">Subtotal</span>
        <span style="font-weight:500"><?= formatRupiah($inv['subtotal']) ?></span>
      </div>
      <?php if ($inv['tax_percent'] > 0): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:.8rem;border-bottom:1px solid var(--line2)">
        <span style="color:var(--txt2)">Pajak (<?= $inv['tax_percent'] ?>%)</span>
        <span style="color:var(--amber)"><?= formatRupiah($inv['tax_amount']) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($inv['discount'] > 0): ?>
      <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:.8rem;border-bottom:1px solid var(--line2)">
        <span style="color:var(--txt2)">Diskon</span>
        <span style="color:var(--green)">- <?= formatRupiah($inv['discount']) ?></span>
      </div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;padding:10px 0 6px;border-top:1px solid var(--line)">
        <span style="font-weight:700;font-size:.9rem">TOTAL</span>
        <span style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:400;color:var(--goldh)"><?= formatRupiah($inv['total']) ?></span>
      </div>
    </div>
  </div>

  <!-- Notes -->
  <?php if ($inv['notes']): ?>
  <div style="margin:0 24px 20px;padding:12px;border-radius:8px;background:rgba(255,255,255,.025);border:1px solid var(--line2)">
    <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--txt3);margin-bottom:5px">Catatan</div>
    <div style="font-size:.8rem;color:var(--txt2);line-height:1.6"><?= nl2br(htmlspecialchars($inv['notes'])) ?></div>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div style="padding:14px 24px;border-top:1px solid var(--line2);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div style="font-size:.72rem;color:var(--txt3)">Dibuat dengan <span style="color:var(--gold)">FixPay</span> · <?= date('d M Y H:i', strtotime($inv['created_at'])) ?></div>
    <?php if ($role !== 'member' && $inv['status'] !== 'paid'): ?>
    <form method="POST" action="invoice_detail.php?id=<?= $id ?>">
      <input type="hidden" name="_action" value="mark_paid">
      <button type="submit" class="btn btn-primary btn-sm" style="background:linear-gradient(135deg,var(--green),#16a34a);color:#fff">
        <i class="ph ph-check-circle"></i> Tandai Lunas
      </button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php
/* Handle mark paid */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_action']??'')==='mark_paid') {
    $upd = $db->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?");
    $upd->execute([$id]);
    header("Location: invoice_detail.php?id=$id&saved=1"); exit;
}

require_once 'includes/footer.php';
?>