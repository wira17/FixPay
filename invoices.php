<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db   = getDB();
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

/* ── Handle DELETE ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    $delId = (int)($_POST['invoice_id'] ?? 0);
    if ($role === 'admin') {
        $s = $db->prepare("DELETE FROM invoices WHERE id=?");
        $s->execute([$delId]);
    } else {
        $s = $db->prepare("DELETE FROM invoices WHERE id=? AND user_id=?");
        $s->execute([$delId, $uid]);
    }
    header('Location: invoices.php?deleted=1'); exit;
}

/* ── Filters ── */
$search  = trim($_GET['q']     ?? '');
$status  = trim($_GET['status'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

/* Member melihat invoice berdasarkan email atau sebagai pembuat */
$memberEmail = $_SESSION['email'] ?? '';
if ($role === 'admin') {
    $where = []; $params = [];
} elseif ($role === 'member') {
    $where  = ['(i.user_id = ? OR i.client_email = ?)'];
    $params = [$uid, $memberEmail];
} else {
    $where  = ['i.user_id = ?'];
    $params = [$uid];
}

if ($search !== '') {
    $where[]  = "(i.invoice_number LIKE ? OR i.client_name LIKE ? OR i.client_email LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($status !== '') {
    $where[]  = "i.status = ?";
    $params[] = $status;
}

$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$countSQL = "SELECT COUNT(*) FROM invoices i $whereSQL";
$cs = $db->prepare($countSQL); $cs->execute($params);
$total = $cs->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

if ($role === 'admin') {
    $dataSQL = "SELECT i.*, u.name AS owner_name FROM invoices i LEFT JOIN users u ON i.user_id=u.id $whereSQL ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset";
} else {
    $dataSQL = "SELECT i.* FROM invoices i $whereSQL ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset";
}
$ds = $db->prepare($dataSQL); $ds->execute($params);
$invoices = $ds->fetchAll();

/* ── Summary counts ── */
if ($role === 'admin') {
    $summBase = "SELECT status, COUNT(*) as cnt FROM invoices GROUP BY status";
} elseif ($role === 'member') {
    $mEmail = $db->quote($_SESSION['email'] ?? '');
    $summBase = "SELECT status, COUNT(*) as cnt FROM invoices WHERE user_id=$uid OR client_email=$mEmail GROUP BY status";
} else {
    $summBase = "SELECT status, COUNT(*) as cnt FROM invoices WHERE user_id=$uid GROUP BY status";
}
$sumRows  = $db->query($summBase)->fetchAll();
$summary  = ['paid'=>0,'sent'=>0,'overdue'=>0,'draft'=>0,'cancelled'=>0];
foreach ($sumRows as $r) $summary[$r['status']] = $r['cnt'];

$statusMap = [
    'paid'      => ['bg'=>'#0d2818','color'=>'#4ade80','border'=>'#1a4d2e','dot'=>'#22c55e','label'=>'Lunas'],
    'sent'      => ['bg'=>'#2a1f06','color'=>'#fbbf24','border'=>'#4a3510','dot'=>'#f59e0b','label'=>'Terkirim'],
    'draft'     => ['bg'=>'#161b27','color'=>'#94a3b8','border'=>'#1e2535','dot'=>'#64748b','label'=>'Draft'],
    'overdue'   => ['bg'=>'#2a0d0d','color'=>'#f87171','border'=>'#4d1a1a','dot'=>'#ef4444','label'=>'Jatuh Tempo'],
    'cancelled' => ['bg'=>'#161b27','color'=>'#64748b','border'=>'#1e2535','dot'=>'#475569','label'=>'Batal'],
];

$pageTitle   = 'Invoice';
$pageSubtitle = 'Daftar Semua Invoice';
$activeMenu  = 'invoices';
$topbarBtn   = $role !== 'member' ? ['url'=>'invoice_form.php','icon'=>'ph-plus','label'=>'Buat Invoice'] : null;

require_once 'includes/header.php';
?>

<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success"><i class="ph ph-check-circle"></i> Invoice berhasil dihapus.</div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success"><i class="ph ph-check-circle"></i> Invoice berhasil disimpan.</div>
<?php endif; ?>

<!-- Summary Pills -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
  <?php
  $pills = [
    'all'       => ['label'=>'Semua',       'count'=>array_sum($summary), 'color'=>'var(--txt2)', 'bg'=>'var(--surf2)', 'border'=>'var(--line)'],
    'paid'      => ['label'=>'Lunas',       'count'=>$summary['paid'],    'color'=>'var(--green)', 'bg'=>'rgba(34,197,94,.1)',  'border'=>'rgba(34,197,94,.2)'],
    'sent'      => ['label'=>'Terkirim',    'count'=>$summary['sent'],    'color'=>'var(--amber)', 'bg'=>'rgba(245,158,11,.1)', 'border'=>'rgba(245,158,11,.2)'],
    'overdue'   => ['label'=>'Jatuh Tempo', 'count'=>$summary['overdue'],'color'=>'var(--red)',   'bg'=>'rgba(239,68,68,.1)',  'border'=>'rgba(239,68,68,.2)'],
    'draft'     => ['label'=>'Draft',       'count'=>$summary['draft'],   'color'=>'var(--txt3)',  'bg'=>'var(--surf2)',        'border'=>'var(--line)'],
  ];
  foreach ($pills as $key => $p):
    $isActive = ($status === $key) || ($key === 'all' && $status === '');
    $qs = $key === 'all' ? '?' : '?status='.$key;
    if ($search) $qs .= '&q='.urlencode($search);
  ?>
  <a href="invoices.php<?= $qs ?>" style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:99px;font-size:.75rem;text-decoration:none;font-weight:<?= $isActive?'600':'400' ?>;background:<?= $isActive?$p['bg']:'var(--surf2)' ?>;color:<?= $isActive?$p['color']:'var(--txt3)' ?>;border:1px solid <?= $isActive?$p['border']:'var(--line)' ?>;transition:all .15s">
    <span><?= $p['label'] ?></span>
    <span style="background:rgba(255,255,255,.08);padding:1px 6px;border-radius:99px;font-size:.66rem"><?= $p['count'] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Search & Filter Bar -->
<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
  <form method="GET" action="invoices.php" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>"><?php endif; ?>
    <div style="position:relative;flex:1;min-width:180px">
      <i class="ph ph-magnifying-glass" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:.9rem;pointer-events:none"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari no. invoice, nama klien..." class="form-input" style="padding-left:30px;height:34px">
    </div>
    <button type="submit" class="btn btn-ghost" style="height:34px">
      <i class="ph ph-funnel"></i><span class="hide-mobile">Cari</span>
    </button>
    <?php if ($search || $status): ?>
    <a href="invoices.php" class="btn btn-ghost" style="height:34px;color:var(--red);border-color:rgba(239,68,68,.2)">
      <i class="ph ph-x"></i><span class="hide-mobile">Reset</span>
    </a>
    <?php endif; ?>
  </form>
  <?php if ($role !== 'member'): ?>
  <?php if ($role !== 'member'): ?>
  <a href="invoice_form.php" class="btn btn-primary" style="height:34px">
    <i class="ph ph-plus"></i><span>Buat Invoice</span>
  </a>
  <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Invoice Table -->
<div class="card-box anim">
  <?php if (empty($invoices)): ?>
  <div class="empty-state">
    <i class="ph ph-receipt"></i>
    <h3>Belum ada invoice</h3>
    <p>Invoice yang Anda buat akan muncul di sini.<br>Mulai dengan membuat invoice pertama.</p>
    <?php if ($role !== 'member'): ?>
    <a href="invoice_form.php" class="btn btn-primary" style="margin-top:16px">
      <i class="ph ph-plus"></i> Buat Invoice Pertama
    </a>
    <?php endif; ?>
  </div>
  <?php else: ?>

  <!-- Desktop Table -->
  <div class="table-wrap hide-on-mobile">
    <table>
      <thead>
        <tr>
          <th>No. Invoice</th>
          <th>Klien</th>
          <?php if ($role === 'admin'): ?><th>Pemilik</th><?php endif; ?>
          <th>Tanggal</th>
          <th>Jatuh Tempo</th>
          <th>Total</th>
          <th>Status</th>
          <th style="width:80px;text-align:center">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoices as $inv):
          $st = $statusMap[$inv['status']] ?? $statusMap['draft'];
          $isOverdue = $inv['status']==='sent' && strtotime($inv['due_date']) < time();
        ?>
        <tr>
          <td>
            <a href="invoice_detail.php?id=<?= $inv['id'] ?>" style="color:var(--txt);text-decoration:none;font-weight:600;font-size:.79rem">
              <?= htmlspecialchars($inv['invoice_number']) ?>
            </a>
          </td>
          <td>
            <div style="font-size:.8rem"><?= htmlspecialchars($inv['client_name']) ?></div>
            <?php if ($inv['client_email']): ?>
            <div style="font-size:.68rem;color:var(--txt3)"><?= htmlspecialchars($inv['client_email']) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($role === 'admin'): ?>
          <td style="color:var(--txt3);font-size:.72rem"><?= htmlspecialchars($inv['owner_name'] ?? '-') ?></td>
          <?php endif; ?>
          <td style="color:var(--txt2);font-size:.75rem"><?= formatTanggal($inv['issue_date']) ?></td>
          <td style="font-size:.75rem;color:<?= $isOverdue?'var(--red)':'var(--txt2)' ?>">
            <?= formatTanggal($inv['due_date']) ?>
            <?php if ($isOverdue): ?><i class="ph ph-warning" style="margin-left:3px"></i><?php endif; ?>
          </td>
          <td style="font-weight:600"><?= formatRupiah($inv['total']) ?></td>
          <td>
            <span class="status-pill" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
              <span class="status-dot" style="background:<?= $st['dot'] ?>"></span><?= $st['label'] ?>
            </span>
          </td>
          <td>
            <div style="display:flex;gap:4px;justify-content:center">
              <a href="invoice_detail.php?id=<?= $inv['id'] ?>" class="btn btn-ghost btn-sm" title="Detail"><i class="ph ph-eye"></i></a>
              <?php if ($role !== 'member'): ?>
              <a href="invoice_form.php?id=<?= $inv['id'] ?>" class="btn btn-ghost btn-sm" title="Edit"><i class="ph ph-pencil-simple"></i></a>
              <button onclick="confirmDelete(<?= $inv['id'] ?>, '<?= htmlspecialchars($inv['invoice_number']) ?>')" class="btn btn-danger btn-sm" title="Hapus"><i class="ph ph-trash"></i></button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Card List -->
  <div style="display:none" class="show-on-mobile" id="mobileList">
    <?php foreach ($invoices as $inv):
      $st = $statusMap[$inv['status']] ?? $statusMap['draft'];
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--line2)">
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:3px">
          <span style="font-weight:600;font-size:.79rem"><?= htmlspecialchars($inv['invoice_number']) ?></span>
          <span class="status-pill" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
            <span class="status-dot" style="background:<?= $st['dot'] ?>"></span><?= $st['label'] ?>
          </span>
        </div>
        <div style="font-size:.72rem;color:var(--txt3)"><?= htmlspecialchars($inv['client_name']) ?></div>
        <div style="display:flex;justify-content:space-between;margin-top:4px">
          <span style="font-weight:600;font-size:.81rem"><?= formatRupiah($inv['total']) ?></span>
          <span style="font-size:.68rem;color:var(--txt3)"><?= formatTanggal($inv['due_date']) ?></span>
        </div>
      </div>
      <a href="invoice_detail.php?id=<?= $inv['id'] ?>" style="width:32px;height:32px;border-radius:8px;background:var(--surf3);border:1px solid var(--line);display:grid;place-items:center;color:var(--txt3);text-decoration:none;flex-shrink:0;transition:all .13s">
        <i class="ph ph-caret-right"></i>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;padding:11px 15px;border-top:1px solid var(--line2);gap:10px;flex-wrap:wrap">
    <span style="font-size:.73rem;color:var(--txt3)">
      Menampilkan <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> dari <?= $total ?> invoice
    </span>
    <div style="display:flex;gap:4px">
      <?php for ($p = 1; $p <= $totalPages; $p++):
        $qs2 = '?page='.$p;
        if ($status) $qs2 .= '&status='.$status;
        if ($search) $qs2 .= '&q='.urlencode($search);
        $isAct = $p === $page;
      ?>
      <a href="invoices.php<?= $qs2 ?>" style="display:grid;place-items:center;width:28px;height:28px;border-radius:6px;font-size:.75rem;text-decoration:none;background:<?= $isAct?'linear-gradient(135deg,var(--gold),var(--goldh))':'var(--surf2)' ?>;color:<?= $isAct?'#05080e':'var(--txt2)' ?>;border:1px solid <?= $isAct?'transparent':'var(--line)' ?>;font-weight:<?= $isAct?'600':'400' ?>">
        <?= $p ?>
      </a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<!-- Delete Confirm Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-head">
      <span class="modal-title" style="color:var(--red)"><i class="ph ph-warning-circle"></i> Hapus Invoice</span>
      <button class="modal-close" onclick="closeDeleteModal()"><i class="ph ph-x"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:.83rem;color:var(--txt2);line-height:1.6">
        Yakin ingin menghapus invoice <strong id="deleteInvNum" style="color:var(--txt)"></strong>?<br>
        Tindakan ini tidak bisa dibatalkan.
      </p>
    </div>
    <div class="modal-foot">
      <form method="POST" action="invoices.php" id="deleteForm">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="invoice_id" id="deleteInvId">
        <button type="button" class="btn btn-ghost" onclick="closeDeleteModal()">Batal</button>
        <button type="submit" class="btn btn-danger">
          <i class="ph ph-trash"></i> Hapus
        </button>
      </form>
    </div>
  </div>
</div>

<style>
@media(max-width:720px){
  .hide-on-mobile{display:none!important}
  #mobileList{display:block!important}
}
</style>

<?php
$extraJs = <<<JS
function confirmDelete(id, num) {
  document.getElementById('deleteInvId').value = id;
  document.getElementById('deleteInvNum').textContent = num;
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('open');
}
JS;
require_once 'includes/footer.php';
?>