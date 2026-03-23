<?php
require_once 'config/database.php';
startSession();
requireLogin();
if ($_SESSION['role'] === 'member') { header('Location: dashboard.php'); exit; }

$db   = getDB();
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

/* ── Handle POST ── */
$errors = []; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'delete') {
        $did = (int)($_POST['client_id'] ?? 0);
        $d   = $role === 'admin' ? $db->prepare("DELETE FROM clients WHERE id=?") : $db->prepare("DELETE FROM clients WHERE id=? AND user_id=?");
        $role === 'admin' ? $d->execute([$did]) : $d->execute([$did, $uid]);
        header('Location: clients.php?deleted=1'); exit;
    }

    if (in_array($action, ['add','edit'])) {
        $cid     = (int)($_POST['client_id'] ?? 0);
        $name    = sanitize($_POST['name']    ?? '');
        $email   = sanitize($_POST['email']   ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $company = sanitize($_POST['company'] ?? '');
        $address = sanitize($_POST['address'] ?? '');

        if (empty($name)) $errors[] = 'Nama klien wajib diisi.';

        if (empty($errors)) {
            if ($action === 'add') {
                $ins = $db->prepare("INSERT INTO clients (user_id,name,email,phone,company,address) VALUES (?,?,?,?,?,?)");
                $ins->execute([$uid,$name,$email,$phone,$company,$address]);
            } else {
                $cond = $role === 'admin' ? "WHERE id=?" : "WHERE id=? AND user_id=?";
                $upd  = $db->prepare("UPDATE clients SET name=?,email=?,phone=?,company=?,address=? $cond");
                $role === 'admin' ? $upd->execute([$name,$email,$phone,$company,$address,$cid]) : $upd->execute([$name,$email,$phone,$company,$address,$cid,$uid]);
            }
            header('Location: clients.php?saved=1'); exit;
        }
    }
}

/* ── Load clients ── */
$search = trim($_GET['q'] ?? '');
$where  = $role === 'admin' ? [] : ["user_id = $uid"];
$params = [];
if ($search) { $where[] = "(name LIKE ? OR email LIKE ? OR company LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
$cs = $db->prepare("SELECT COUNT(*) FROM clients $whereSQL"); $cs->execute($params); $total = $cs->fetchColumn();
$qs = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM invoices i WHERE i.client_name=c.name) as inv_count FROM clients c $whereSQL ORDER BY c.name"); $qs->execute($params);
$clients = $qs->fetchAll();

/* ── Load single client for edit modal ── */
$editClient = null;
if (isset($_GET['edit'])) {
    $eq = $role === 'admin' ? $db->prepare("SELECT * FROM clients WHERE id=?") : $db->prepare("SELECT * FROM clients WHERE id=? AND user_id=?");
    $role === 'admin' ? $eq->execute([(int)$_GET['edit']]) : $eq->execute([(int)$_GET['edit'],$uid]);
    $editClient = $eq->fetch();
}

$pageTitle   = 'Klien';
$pageSubtitle = 'Manajemen Klien';
$activeMenu  = 'clients';
$topbarBtn   = ['url'=>'#','icon'=>'ph-plus','label'=>'Tambah Klien'];
require_once 'includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success"><i class="ph ph-check-circle"></i> Data klien berhasil disimpan.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success"><i class="ph ph-check-circle"></i> Klien berhasil dihapus.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-error"><i class="ph ph-warning-circle"></i><?= implode(' ', $errors) ?></div><?php endif; ?>

<!-- Search + Add -->
<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
  <form method="GET" style="display:flex;gap:8px;flex:1">
    <div style="position:relative;flex:1;min-width:180px">
      <i class="ph ph-magnifying-glass" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:.9rem;pointer-events:none"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, email, perusahaan..." class="form-input" style="padding-left:30px;height:34px">
    </div>
    <button class="btn btn-ghost" style="height:34px"><i class="ph ph-funnel"></i></button>
    <?php if ($search): ?><a href="clients.php" class="btn btn-ghost" style="height:34px;color:var(--red)"><i class="ph ph-x"></i></a><?php endif; ?>
  </form>
  <button onclick="openClientModal()" class="btn btn-primary" style="height:34px">
    <i class="ph ph-plus"></i><span>Tambah Klien</span>
  </button>
</div>

<!-- Summary -->
<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:stretch">
  <div style="padding:10px 16px;background:var(--surf);border:1px solid var(--line);border-radius:var(--radius);display:flex;align-items:center;gap:10px">
    <div style="width:32px;height:32px;border-radius:8px;background:var(--gdim);color:var(--gold);border:1px solid var(--gring);display:grid;place-items:center"><i class="ph ph-users"></i></div>
    <div><div style="font-size:.69rem;color:var(--txt3)">Total Klien</div><div style="font-weight:600;font-size:1rem"><?= $total ?></div></div>
  </div>
  <div style="padding:10px 16px;background:rgba(34,197,94,.06);border:1px solid rgba(34,197,94,.18);border-radius:var(--radius);display:flex;align-items:center;gap:8px;font-size:.78rem;color:var(--green)">
    <i class="ph ph-info" style="font-size:1rem;flex-shrink:0"></i>
    <span>Member yang mendaftar otomatis masuk ke daftar klien. Anda juga bisa <a href="#" onclick="openClientModal();return false;" style="color:var(--goldh);text-decoration:none;font-weight:500">tambah manual</a>.</span>
  </div>
</div>

<!-- Grid Klien -->
<?php if (empty($clients)): ?>
<div class="card-box"><div class="empty-state">
  <i class="ph ph-users"></i>
  <h3>Belum ada klien</h3>
  <p>Tambahkan klien untuk memudahkan pembuatan invoice.</p>
  <button onclick="openClientModal()" class="btn btn-primary" style="margin-top:14px"><i class="ph ph-plus"></i> Tambah Klien</button>
</div></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
  <?php foreach ($clients as $cl): ?>
  <div class="card-box anim" style="transition:border-color .2s,transform .2s;cursor:default" onmouseenter="this.style.borderColor='var(--gring)';this.style.transform='translateY(-2px)'" onmouseleave="this.style.borderColor='var(--line)';this.style.transform=''">
    <div style="padding:15px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--gold),var(--goldh));display:grid;place-items:center;font-size:.9rem;font-weight:700;color:#05080e;flex-shrink:0">
            <?= strtoupper(mb_substr($cl['name'],0,1)) ?>
          </div>
          <div>
            <div style="font-weight:600;font-size:.85rem"><?= htmlspecialchars($cl['name']) ?></div>
            <?php if ($cl['company']): ?>
            <div style="font-size:.72rem;color:var(--txt3)"><?= htmlspecialchars($cl['company']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0">
          <button onclick="openEditModal(<?= htmlspecialchars(json_encode($cl)) ?>)" class="btn btn-ghost btn-sm" title="Edit"><i class="ph ph-pencil-simple"></i></button>
          <button onclick="confirmDeleteClient(<?= $cl['id'] ?>, '<?= htmlspecialchars($cl['name']) ?>')" class="btn btn-danger btn-sm" title="Hapus"><i class="ph ph-trash"></i></button>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:5px">
        <?php if ($cl['email']): ?>
        <div style="display:flex;align-items:center;gap:7px;font-size:.77rem;color:var(--txt2)">
          <i class="ph ph-envelope" style="color:var(--txt3);font-size:.85rem"></i>
          <?= htmlspecialchars($cl['email']) ?>
        </div>
        <?php endif; ?>
        <?php if ($cl['phone']): ?>
        <div style="display:flex;align-items:center;gap:7px;font-size:.77rem;color:var(--txt2)">
          <i class="ph ph-phone" style="color:var(--txt3);font-size:.85rem"></i>
          <?= htmlspecialchars($cl['phone']) ?>
        </div>
        <?php endif; ?>
        <?php if ($cl['address']): ?>
        <div style="display:flex;align-items:center;gap:7px;font-size:.75rem;color:var(--txt3)">
          <i class="ph ph-map-pin" style="font-size:.85rem"></i>
          <?= htmlspecialchars(mb_strimwidth($cl['address'],0,45,'...')) ?>
        </div>
        <?php endif; ?>
      </div>
      <div style="margin-top:11px;padding-top:10px;border-top:1px solid var(--line2);display:flex;justify-content:space-between;align-items:center">
        <span style="font-size:.72rem;color:var(--txt3)">
          <i class="ph ph-receipt" style="margin-right:3px"></i><?= $cl['inv_count'] ?> Invoice
        </span>
        <span style="font-size:.68rem;color:var(--txt3)"><?= formatTanggal($cl['created_at']) ?></span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div class="modal-overlay" id="clientModal">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title" id="modalTitle"><i class="ph ph-user-plus"></i> Tambah Klien</span>
      <button class="modal-close" onclick="closeClientModal()"><i class="ph ph-x"></i></button>
    </div>
    <form method="POST" action="clients.php">
      <input type="hidden" name="_action" id="clientAction" value="add">
      <input type="hidden" name="client_id" id="clientId" value="0">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nama <span>*</span></label>
            <input type="text" name="name" id="c_name" class="form-input" placeholder="Nama lengkap / perusahaan" required>
          </div>
          <div class="form-group">
            <label class="form-label">Perusahaan</label>
            <input type="text" name="company" id="c_company" class="form-input" placeholder="PT / CV / Nama Bisnis">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="c_email" class="form-input" placeholder="email@domain.com">
          </div>
          <div class="form-group">
            <label class="form-label">Telepon</label>
            <input type="text" name="phone" id="c_phone" class="form-input" placeholder="08xx-xxxx-xxxx">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Alamat</label>
          <textarea name="address" id="c_address" class="form-textarea" style="min-height:64px" placeholder="Alamat lengkap..."></textarea>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="closeClientModal()">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="ph ph-floppy-disk"></i> Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal" style="max-width:360px">
    <div class="modal-head">
      <span class="modal-title" style="color:var(--red)"><i class="ph ph-warning-circle"></i> Hapus Klien</span>
      <button class="modal-close" onclick="document.getElementById('deleteModal').classList.remove('open')"><i class="ph ph-x"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:.83rem;color:var(--txt2);line-height:1.6">Yakin ingin menghapus klien <strong id="delClientName" style="color:var(--txt)"></strong>?</p>
    </div>
    <div class="modal-foot">
      <form method="POST">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="client_id" id="delClientId">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('deleteModal').classList.remove('open')">Batal</button>
        <button type="submit" class="btn btn-danger"><i class="ph ph-trash"></i> Hapus</button>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
function openClientModal() {
  document.getElementById('modalTitle').innerHTML = '<i class="ph ph-user-plus"></i> Tambah Klien';
  document.getElementById('clientAction').value = 'add';
  document.getElementById('clientId').value = 0;
  ['c_name','c_company','c_email','c_phone','c_address'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('clientModal').classList.add('open');
}
function openEditModal(data) {
  document.getElementById('modalTitle').innerHTML = '<i class="ph ph-pencil-simple"></i> Edit Klien';
  document.getElementById('clientAction').value = 'edit';
  document.getElementById('clientId').value    = data.id;
  document.getElementById('c_name').value      = data.name    || '';
  document.getElementById('c_company').value   = data.company || '';
  document.getElementById('c_email').value     = data.email   || '';
  document.getElementById('c_phone').value     = data.phone   || '';
  document.getElementById('c_address').value   = data.address || '';
  document.getElementById('clientModal').classList.add('open');
}
function closeClientModal() { document.getElementById('clientModal').classList.remove('open'); }
function confirmDeleteClient(id, name) {
  document.getElementById('delClientId').value = id;
  document.getElementById('delClientName').textContent = name;
  document.getElementById('deleteModal').classList.add('open');
}
JS;
require_once 'includes/footer.php';
?>