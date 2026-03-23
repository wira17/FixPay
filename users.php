<?php
require_once 'config/database.php';
startSession();
requireLogin();
requireRole('admin');

$db = getDB();

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if ($action === 'toggle_status') {
        $tid = (int)($_POST['user_id'] ?? 0);
        if ($tid !== (int)$_SESSION['user_id']) {
            $cur = $db->prepare("SELECT status FROM users WHERE id=?"); $cur->execute([$tid]); $cur = $cur->fetchColumn();
            $new = $cur === 'active' ? 'inactive' : 'active';
            $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$new,$tid]);
        }
        header('Location: users.php?saved=1'); exit;
    }
    if ($action === 'change_role') {
        $tid  = (int)($_POST['user_id'] ?? 0);
        $newR = $_POST['new_role'] ?? '';
        if (in_array($newR,['admin','user','member']) && $tid !== (int)$_SESSION['user_id']) {
            $db->prepare("UPDATE users SET role=? WHERE id=?")->execute([$newR,$tid]);
        }
        header('Location: users.php?saved=1'); exit;
    }
    if ($action === 'delete') {
        $tid = (int)($_POST['user_id'] ?? 0);
        if ($tid !== (int)$_SESSION['user_id']) {
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$tid]);
        }
        header('Location: users.php?deleted=1'); exit;
    }
    if ($action === 'add') {
        $name    = sanitize($_POST['name']    ?? '');
        $email   = sanitize($_POST['email']   ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $company = sanitize($_POST['company'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $role2   = $_POST['role']     ?? 'member';
        if ($name && $email && strlen($pass) >= 6) {
            $chk = $db->prepare("SELECT id FROM users WHERE email=?"); $chk->execute([$email]);
            if (!$chk->fetch()) {
                $db->prepare("INSERT INTO users (name,email,password,role,phone,company,status,email_verified) VALUES (?,?,?,?,?,?,'active',1)")
                   ->execute([$name,$email,password_hash($pass,PASSWORD_BCRYPT),$role2,$phone,$company]);
                $newUserId = $db->lastInsertId();

                /* Auto-create client jika role = member */
                if ($role2 === 'member') {
                    $adminQ  = $db->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1");
                    $adminId = $adminQ ? $adminQ->fetchColumn() : false;
                    $ownerId = $adminId ?: $newUserId;
                    $db->prepare("INSERT INTO clients (user_id, name, email, phone, company, address) VALUES (?,?,?,?,?,?)")
                       ->execute([$ownerId, $name, $email, $phone ?: '', $company ?: '', '']);
                }
            }
        }
        header('Location: users.php?saved=1'); exit;
    }
}

/* ── Load users ── */
$search = trim($_GET['q'] ?? '');
$filter = trim($_GET['role'] ?? '');
$where  = []; $params = [];
if ($search) { $where[] = "(name LIKE ? OR email LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filter) { $where[] = "role=?"; $params[] = $filter; }
$wSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';
$qs   = $db->prepare("SELECT *, (SELECT COUNT(*) FROM invoices WHERE user_id=users.id) as inv_count FROM users $wSQL ORDER BY created_at DESC");
$qs->execute($params);
$users = $qs->fetchAll();

$roleColors = [
    'admin'  => ['bg'=>'rgba(239,68,68,.1)','color'=>'#f87171','border'=>'rgba(239,68,68,.2)'],
    'user'   => ['bg'=>'var(--gdim)','color'=>'var(--gold)','border'=>'var(--gring)'],
    'member' => ['bg'=>'rgba(99,102,241,.1)','color'=>'#a5b4fc','border'=>'rgba(99,102,241,.2)'],
];
$roleLabels2 = ['admin'=>'Super Admin','user'=>'User Bisnis','member'=>'Member'];

$pageTitle   = 'Kelola Pengguna';
$pageSubtitle = count($users).' pengguna';
$activeMenu  = 'users';
require_once 'includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success"><i class="ph ph-check-circle"></i> Berhasil disimpan.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success"><i class="ph ph-check-circle"></i> Pengguna berhasil dihapus.</div><?php endif; ?>

<!-- Toolbar -->
<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
  <form method="GET" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
    <div style="position:relative;flex:1;min-width:180px">
      <i class="ph ph-magnifying-glass" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--txt3);font-size:.9rem;pointer-events:none"></i>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama atau email..." class="form-input" style="padding-left:30px;height:34px">
    </div>
    <select name="role" onchange="this.form.submit()" class="form-select" style="height:34px;width:auto">
      <option value="">Semua Role</option>
      <option value="admin"  <?= $filter==='admin' ?'selected':'' ?>>Admin</option>
      <option value="user"   <?= $filter==='user'  ?'selected':'' ?>>User</option>
      <option value="member" <?= $filter==='member'?'selected':'' ?>>Member</option>
    </select>
    <?php if ($search||$filter): ?><a href="users.php" class="btn btn-ghost" style="height:34px;color:var(--red)"><i class="ph ph-x"></i></a><?php endif; ?>
  </form>
  <button onclick="document.getElementById('addUserModal').classList.add('open')" class="btn btn-primary" style="height:34px">
    <i class="ph ph-user-plus"></i><span>Tambah User</span>
  </button>
</div>

<!-- Users Table -->
<div class="card-box anim">
  <?php if (empty($users)): ?>
  <div class="empty-state"><i class="ph ph-users-three"></i><h3>Tidak ada pengguna</h3><p>Belum ada pengguna yang cocok.</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Pengguna</th>
          <th>Role</th>
          <th class="hide-mobile">Invoice</th>
          <th class="hide-mobile">Login Terakhir</th>
          <th>Status</th>
          <th style="width:100px;text-align:center">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
          $rc  = $roleColors[$u['role']] ?? $roleColors['member'];
          $isMe = $u['id'] == $_SESSION['user_id'];
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <div style="width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--goldh));display:grid;place-items:center;font-size:.78rem;font-weight:700;color:#05080e;flex-shrink:0">
                <?= strtoupper(mb_substr($u['name'],0,1)) ?>
              </div>
              <div>
                <div style="font-weight:500;font-size:.81rem"><?= htmlspecialchars($u['name']) ?> <?= $isMe ? '<span style="font-size:.65rem;color:var(--gold)">(Anda)</span>' : '' ?></div>
                <div style="font-size:.7rem;color:var(--txt3)"><?= htmlspecialchars($u['email']) ?></div>
              </div>
            </div>
          </td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="_action" value="change_role">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="new_role" onchange="this.form.submit()" <?= $isMe?'disabled':'' ?>
                style="padding:2px 24px 2px 8px;border-radius:99px;font-size:.68rem;font-weight:500;background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;border:1px solid <?= $rc['border'] ?>;cursor:pointer;appearance:none;background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E\");background-repeat:no-repeat;background-position:right 6px center;outline:none">
                <option value="admin"  <?= $u['role']==='admin' ?'selected':'' ?>>Admin</option>
                <option value="user"   <?= $u['role']==='user'  ?'selected':'' ?>>User</option>
                <option value="member" <?= $u['role']==='member'?'selected':'' ?>>Member</option>
              </select>
            </form>
          </td>
          <td class="hide-mobile" style="font-size:.79rem;color:var(--txt2)"><?= $u['inv_count'] ?> invoice</td>
          <td class="hide-mobile" style="font-size:.73rem;color:var(--txt3)"><?= $u['last_login'] ? date('d M Y H:i',strtotime($u['last_login'])) : 'Belum pernah' ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="_action" value="toggle_status">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" <?= $isMe?'disabled':'' ?>
                style="padding:2px 10px;border-radius:99px;font-size:.68rem;font-weight:500;border:1px solid <?= $u['status']==='active'?'rgba(34,197,94,.2)':'rgba(239,68,68,.2)' ?>;background:<?= $u['status']==='active'?'rgba(34,197,94,.1)':'rgba(239,68,68,.1)' ?>;color:<?= $u['status']==='active'?'var(--green)':'var(--red)' ?>;cursor:pointer;transition:all .15s">
                <?= $u['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?>
              </button>
            </form>
          </td>
          <td>
            <div style="display:flex;gap:4px;justify-content:center">
              <?php if (!$isMe): ?>
              <button onclick="confirmDeleteUser(<?= $u['id'] ?>,'<?= htmlspecialchars($u['name']) ?>')" class="btn btn-danger btn-sm"><i class="ph ph-trash"></i></button>
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

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title"><i class="ph ph-user-plus"></i> Tambah Pengguna Baru</span>
      <button class="modal-close" onclick="document.getElementById('addUserModal').classList.remove('open')"><i class="ph ph-x"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="add">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nama Lengkap <span>*</span></label>
            <input type="text" name="name" class="form-input" placeholder="Nama pengguna" required>
          </div>
          <div class="form-group">
            <label class="form-label">Role</label>
            <select name="role" id="addUserRole" class="form-select" onchange="toggleClientNote(this.value)">
              <option value="member">Member</option>
              <option value="user">User Bisnis</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">No. Telepon <span style="color:var(--txt3);font-weight:400">(opsional)</span></label>
            <input type="text" name="phone" class="form-input" placeholder="08xx-xxxx-xxxx">
          </div>
          <div class="form-group">
            <label class="form-label">Perusahaan <span style="color:var(--txt3);font-weight:400">(opsional)</span></label>
            <input type="text" name="company" class="form-input" placeholder="PT / CV / Nama Bisnis">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email <span>*</span></label>
          <input type="email" name="email" class="form-input" placeholder="email@domain.com" required>
        </div>
        <div class="form-group" style="margin-bottom:8px">
          <label class="form-label">Password <span>*</span></label>
          <input type="password" name="password" class="form-input" placeholder="Min. 6 karakter" required minlength="6">
        </div>
        <!-- Info auto-create client -->
        <div id="clientAutoNote" style="padding:8px 12px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:8px;font-size:.77rem;color:var(--green);display:flex;align-items:center;gap:7px">
          <i class="ph ph-check-circle" style="font-size:1rem;flex-shrink:0"></i>
          <span>Pengguna ini akan otomatis ditambahkan ke <strong>Daftar Klien</strong>.</span>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addUserModal').classList.remove('open')">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="ph ph-user-plus"></i> Tambah</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete User Modal -->
<div class="modal-overlay" id="deleteUserModal">
  <div class="modal" style="max-width:360px">
    <div class="modal-head">
      <span class="modal-title" style="color:var(--red)"><i class="ph ph-warning-circle"></i> Hapus Pengguna</span>
      <button class="modal-close" onclick="document.getElementById('deleteUserModal').classList.remove('open')"><i class="ph ph-x"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:.83rem;color:var(--txt2);line-height:1.6">Yakin hapus pengguna <strong id="delUserName" style="color:var(--txt)"></strong>?<br><span style="color:var(--red);font-size:.78rem">Semua invoice miliknya juga akan terhapus.</span></p>
    </div>
    <div class="modal-foot">
      <form method="POST">
        <input type="hidden" name="_action" value="delete">
        <input type="hidden" name="user_id" id="delUserId">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('deleteUserModal').classList.remove('open')">Batal</button>
        <button type="submit" class="btn btn-danger"><i class="ph ph-trash"></i> Hapus</button>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
function confirmDeleteUser(id, name) {
  document.getElementById('delUserId').value = id;
  document.getElementById('delUserName').textContent = name;
  document.getElementById('deleteUserModal').classList.add('open');
}

function toggleClientNote(role) {
  var note = document.getElementById('clientAutoNote');
  if (!note) return;
  note.style.display = role === 'member' ? 'flex' : 'none';
}
JS;
require_once 'includes/footer.php';
?>