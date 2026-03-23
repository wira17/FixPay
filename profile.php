<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];
$user = $db->prepare("SELECT * FROM users WHERE id=?"); $user->execute([$uid]); $user = $user->fetch();

$errors = []; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'profile';

    if ($action === 'profile') {
        $name    = sanitize($_POST['name']    ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $company = sanitize($_POST['company'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        if (empty($name)) $errors[] = 'Nama wajib diisi.';
        if (empty($errors)) {
            $db->prepare("UPDATE users SET name=?,phone=?,company=?,address=? WHERE id=?")->execute([$name,$phone,$company,$address,$uid]);
            $_SESSION['name'] = $name;
            $success = 'Profil berhasil diperbarui.';
            $user['name'] = $name; $user['phone'] = $phone; $user['company'] = $company; $user['address'] = $address;
        }
    }

    if ($action === 'password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (!password_verify($oldPass, $user['password'])) $errors[] = 'Password lama tidak benar.';
        elseif (strlen($newPass) < 6) $errors[] = 'Password baru minimal 6 karakter.';
        elseif ($newPass !== $confirm) $errors[] = 'Konfirmasi password tidak cocok.';
        if (empty($errors)) {
            $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPass,PASSWORD_BCRYPT),$uid]);
            $success = 'Password berhasil diubah.';
        }
    }
}

$pageTitle  = 'Pengaturan Akun';
$activeMenu = 'profile';
require_once 'includes/header.php';
?>

<?php if ($success): ?><div class="alert alert-success"><i class="ph ph-check-circle"></i><?= $success ?></div><?php endif; ?>
<?php if ($errors):  ?><div class="alert alert-error"><i class="ph ph-warning-circle"></i><?= implode(' ',$errors) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;max-width:860px">

  <!-- Profile Card -->
  <div class="card-box anim">
    <div class="card-head">
      <div class="card-headicon"><i class="ph ph-user"></i></div>
      <span class="card-title">Informasi Profil</span>
    </div>
    <div style="padding:18px;text-align:center;border-bottom:1px solid var(--line2)">
      <div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,var(--gold),var(--goldh));display:grid;place-items:center;font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:400;color:#05080e;margin:0 auto 10px">
        <?= strtoupper(mb_substr($user['name'],0,1)) ?>
      </div>
      <div style="font-weight:600;font-size:.9rem"><?= htmlspecialchars($user['name']) ?></div>
      <div style="font-size:.75rem;color:var(--txt3);margin-top:3px"><?= htmlspecialchars($user['email']) ?></div>
      <div style="margin-top:8px">
        <?php
        $rc = ['admin'=>['bg'=>'rgba(239,68,68,.1)','c'=>'#f87171','b'=>'rgba(239,68,68,.2)','l'=>'Super Admin'],
               'user' =>['bg'=>'var(--gdim)','c'=>'var(--gold)','b'=>'var(--gring)','l'=>'User Bisnis'],
               'member'=>['bg'=>'rgba(99,102,241,.1)','c'=>'#a5b4fc','b'=>'rgba(99,102,241,.2)','l'=>'Member']];
        $r = $rc[$user['role']] ?? $rc['member'];
        ?>
        <span style="padding:3px 10px;border-radius:99px;font-size:.7rem;font-weight:500;background:<?= $r['bg'] ?>;color:<?= $r['c'] ?>;border:1px solid <?= $r['b'] ?>">
          <?= $r['l'] ?>
        </span>
      </div>
    </div>
    <form method="POST" style="padding:15px">
      <input type="hidden" name="_action" value="profile">
      <div class="form-group">
        <label class="form-label">Nama Lengkap <span>*</span></label>
        <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($user['name']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Email <span style="color:var(--txt3);font-weight:400">(tidak bisa diubah)</span></label>
        <input type="email" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" disabled style="opacity:.5;cursor:not-allowed">
      </div>
      <div class="form-group">
        <label class="form-label">Telepon</label>
        <input type="text" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone']??'') ?>" placeholder="08xx-xxxx-xxxx">
      </div>
      <div class="form-group">
        <label class="form-label">Perusahaan / Bisnis</label>
        <input type="text" name="company" class="form-input" value="<?= htmlspecialchars($user['company']??'') ?>" placeholder="Nama perusahaan">
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Alamat</label>
        <textarea name="address" class="form-textarea" style="min-height:68px"><?= htmlspecialchars($user['address']??'') ?></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
        <i class="ph ph-floppy-disk"></i> Simpan Perubahan
      </button>
    </form>
  </div>

  <!-- Password Card -->
  <div style="display:flex;flex-direction:column;gap:14px">
    <div class="card-box">
      <div class="card-head">
        <div class="card-headicon" style="background:rgba(239,68,68,.1);color:var(--red);border-color:rgba(239,68,68,.2)"><i class="ph ph-lock-key"></i></div>
        <span class="card-title">Ubah Password</span>
      </div>
      <form method="POST" style="padding:15px">
        <input type="hidden" name="_action" value="password">
        <div class="form-group">
          <label class="form-label">Password Lama <span>*</span></label>
          <input type="password" name="old_password" class="form-input" placeholder="Masukkan password saat ini" required>
        </div>
        <div class="form-group">
          <label class="form-label">Password Baru <span>*</span></label>
          <input type="password" name="new_password" class="form-input" placeholder="Min. 6 karakter" required minlength="6">
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label">Konfirmasi Password Baru <span>*</span></label>
          <input type="password" name="confirm_password" class="form-input" placeholder="Ulangi password baru" required>
        </div>
        <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center">
          <i class="ph ph-lock-key"></i> Ubah Password
        </button>
      </form>
    </div>

    <!-- Account Info -->
    <div class="card-box">
      <div class="card-head">
        <div class="card-headicon" style="background:rgba(96,165,250,.1);color:var(--blue);border-color:rgba(96,165,250,.2)"><i class="ph ph-info"></i></div>
        <span class="card-title">Info Akun</span>
      </div>
      <div style="padding:14px">
        <?php
        $infos = [
          ['label'=>'Bergabung sejak','val'=>formatTanggal($user['created_at']),'icon'=>'ph-calendar-blank'],
          ['label'=>'Login terakhir', 'val'=>$user['last_login']?date('d M Y H:i',strtotime($user['last_login'])):'Tidak tersedia','icon'=>'ph-clock'],
          ['label'=>'Status akun',    'val'=>$user['status']==='active'?'Aktif':'Nonaktif','icon'=>'ph-shield-check'],
        ];
        foreach ($infos as $info): ?>
        <div style="display:flex;align-items:center;gap:9px;padding:7px 0;border-bottom:1px solid var(--line2)">
          <i class="ph <?= $info['icon'] ?>" style="color:var(--txt3);font-size:.88rem;flex-shrink:0;width:16px;text-align:center"></i>
          <span style="flex:1;font-size:.78rem;color:var(--txt2)"><?= $info['label'] ?></span>
          <span style="font-size:.78rem;font-weight:500"><?= $info['val'] ?></span>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:12px">
          <a href="logout.php" class="btn btn-danger" style="width:100%;justify-content:center">
            <i class="ph ph-sign-out"></i> Keluar dari Akun
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<style>@media(max-width:720px){div[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important}}</style>

<?php require_once 'includes/footer.php'; ?>