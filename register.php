<?php
require_once 'config/database.php';
startSession();
if (isLoggedIn()) { header('Location: dashboard.php'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = sanitize($_POST['name']     ?? '');
    $email    = sanitize($_POST['email']    ?? '');
    $phone    = sanitize($_POST['phone']    ?? '');
    $company  = sanitize($_POST['company']  ?? '');
    $password = $_POST['password']          ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';
    $role     = 'member';

    if (empty($name) || empty($email) || empty($password)) {
        $error = 'Nama, email, dan password wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Password dan konfirmasi tidak sama.';
    } else {
        $db  = getDB();
        $chk = $db->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = 'Email sudah terdaftar.';
        } else {
            $db->prepare("INSERT INTO users (name,email,password,phone,company,role) VALUES (?,?,?,?,?,?)")
               ->execute([$name,$email,password_hash($password,PASSWORD_BCRYPT),$phone,$company,$role]);
            $newId = $db->lastInsertId();

            /* Auto-create client */
            $adminId = $db->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
            $db->prepare("INSERT INTO clients (user_id,name,email,phone,company,address) VALUES (?,?,?,?,?,?)")
               ->execute([$adminId ?: $newId, $name, $email, $phone ?: '', $company ?: '', '']);

            header('Location: login.php?registered=1'); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daftar — FixPay</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;font-family:'DM Sans',sans-serif;background:#07090f;color:#e8e4db}

.split{display:grid;grid-template-columns:1fr 1fr;height:100vh;overflow:hidden}

/* ══ LEFT ══ */
.left{
  position:relative;
  background:linear-gradient(145deg,#07090f 0%,#0d1a2e 50%,#07090f 100%);
  display:flex;flex-direction:column;justify-content:space-between;
  padding:48px;overflow:hidden;
}
.left::before{
  content:'';position:absolute;top:-80px;right:-80px;
  width:380px;height:380px;border-radius:50%;
  background:radial-gradient(circle,rgba(200,160,74,.09) 0%,transparent 70%);pointer-events:none;
}
.left::after{
  content:'';position:absolute;bottom:-60px;left:-60px;
  width:280px;height:280px;border-radius:50%;
  background:radial-gradient(circle,rgba(96,165,250,.06) 0%,transparent 70%);pointer-events:none;
}

.brand{display:flex;align-items:center;gap:12px}
.brand-icon{
  width:42px;height:42px;border-radius:11px;
  background:linear-gradient(135deg,#c8a04a,#e2be72);
  display:grid;place-items:center;color:#05080e;font-size:1.1rem;flex-shrink:0;
}
.brand-name{
  font-family:'Cormorant Garamond',serif;font-size:1.7rem;font-weight:600;
  background:linear-gradient(to right,#c8a04a,#e2be72);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}

.left-center{flex:1;display:flex;flex-direction:column;justify-content:center;padding:36px 0}
.left-tagline{
  font-family:'Cormorant Garamond',serif;
  font-size:2.4rem;font-weight:300;line-height:1.2;color:#e8e4db;margin-bottom:16px;
}
.left-tagline em{font-style:italic;color:#e2be72}
.left-desc{font-size:.83rem;color:#8c97aa;line-height:1.75;max-width:340px;margin-bottom:28px}

/* Steps */
.steps{display:flex;flex-direction:column;gap:14px}
.step{display:flex;align-items:flex-start;gap:14px}
.step-num{
  width:28px;height:28px;border-radius:8px;flex-shrink:0;margin-top:1px;
  background:linear-gradient(135deg,#c8a04a,#e2be72);
  display:grid;place-items:center;
  font-size:.72rem;font-weight:700;color:#05080e;
}
.step-content{}
.step-title{font-size:.83rem;font-weight:500;color:#e8e4db;margin-bottom:2px}
.step-desc{font-size:.74rem;color:#8c97aa;line-height:1.5}

/* Testimonial */
.testimonial{
  margin-top:28px;padding:16px 18px;
  background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);
  border-left:2px solid #c8a04a;
  border-radius:0 10px 10px 0;
}
.testi-text{font-size:.8rem;color:#c8d4e8;line-height:1.6;font-style:italic;margin-bottom:8px}
.testi-author{font-size:.72rem;color:#c8a04a;font-weight:500}

.left-bottom{font-size:.72rem;color:#505a6c}

/* ══ RIGHT ══ */
.right{
  background:#0c1120;border-left:1px solid rgba(255,255,255,.04);
  display:flex;flex-direction:column;justify-content:center;
  padding:40px 52px;overflow-y:auto;
}

.form-header{margin-bottom:22px}
.form-title{font-family:'Cormorant Garamond',serif;font-size:1.9rem;font-weight:300;color:#e8e4db;margin-bottom:5px}
.form-sub{font-size:.82rem;color:#8c97aa}

.alert-error{
  padding:10px 14px;border-radius:9px;font-size:.79rem;margin-bottom:16px;
  display:flex;align-items:center;gap:8px;
  background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#f87171;
}

.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-group{margin-bottom:13px}
.form-label{display:block;font-size:.73rem;color:#8c97aa;margin-bottom:5px;letter-spacing:.02em}
.input-wrap{position:relative;display:flex;align-items:center}
.input-icon{position:absolute;left:11px;color:#505a6c;font-size:.9rem;pointer-events:none;transition:color .2s}
.form-input{
  width:100%;padding:10px 13px 10px 36px;
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);
  border-radius:9px;color:#e8e4db;
  font-family:'DM Sans',sans-serif;font-size:.84rem;
  outline:none;transition:border .2s,background .2s;
}
.form-input:focus{border-color:#c8a04a;background:rgba(200,160,74,.05)}
.input-wrap:focus-within .input-icon{color:#c8a04a}
.pass-toggle{
  position:absolute;right:11px;background:none;border:none;
  color:#505a6c;cursor:pointer;font-size:.9rem;padding:0;transition:color .2s;
}
.pass-toggle:hover{color:#c8a04a}

.strength-bar{height:2px;border-radius:2px;margin-top:5px;background:rgba(255,255,255,.06);overflow:hidden}
.strength-fill{height:100%;width:0;border-radius:2px;transition:width .3s,background .3s}
.strength-text{font-size:.67rem;color:#8c97aa;margin-top:3px}

.terms{display:flex;align-items:flex-start;gap:8px;font-size:.77rem;color:#8c97aa;margin-bottom:16px;margin-top:4px}
.terms input{accent-color:#c8a04a;width:13px;height:13px;margin-top:2px;flex-shrink:0}
.terms a{color:#c8a04a;text-decoration:none}

.btn-submit{
  width:100%;padding:11px;
  background:linear-gradient(135deg,#c8a04a,#e2be72);
  color:#05080e;border:none;border-radius:10px;
  font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:600;
  cursor:pointer;transition:all .22s;
  display:flex;align-items:center;justify-content:center;gap:8px;
  margin-bottom:14px;letter-spacing:.02em;
}
.btn-submit:hover{opacity:.88;transform:translateY(-1px);box-shadow:0 8px 24px rgba(200,160,74,.3)}

.auth-link{text-align:center;font-size:.81rem;color:#8c97aa}
.auth-link a{color:#c8a04a;text-decoration:none;font-weight:500}
.auth-link a:hover{text-decoration:underline}

.optional{font-size:.67rem;color:rgba(140,151,170,.5);margin-left:4px}

@media(max-width:768px){
  .split{grid-template-columns:1fr}
  .left{display:none}
  .right{padding:32px 24px}
  html,body{overflow:auto}
}
</style>
</head>
<body>
<div class="split">

  <!-- ══ LEFT ══ -->
  <div class="left">
    <div class="brand">
      <div class="brand-icon"><i class="ph-bold ph-receipt"></i></div>
      <span class="brand-name">FixPay</span>
    </div>

    <div class="left-center">
      <h2 class="left-tagline">Mulai kelola<br>tagihan <em>dengan mudah</em></h2>
      <p class="left-desc">Daftar gratis dan langsung gunakan semua fitur FixPay untuk mengelola tagihan bisnis Anda.</p>

      <div class="steps">
        <div class="step">
          <div class="step-num">1</div>
          <div class="step-content">
            <div class="step-title">Buat Akun</div>
            <div class="step-desc">Daftar dalam 30 detik, tidak perlu kartu kredit</div>
          </div>
        </div>
        <div class="step">
          <div class="step-num">2</div>
          <div class="step-content">
            <div class="step-title">Terima Tagihan</div>
            <div class="step-desc">Lihat tagihan yang dikirimkan untuk Anda secara langsung</div>
          </div>
        </div>
        <div class="step">
          <div class="step-num">3</div>
          <div class="step-content">
            <div class="step-title">Upload Bukti Bayar</div>
            <div class="step-desc">Kirim bukti transfer dan pantau status pembayaran</div>
          </div>
        </div>
        <div class="step">
          <div class="step-num">4</div>
          <div class="step-content">
            <div class="step-title">Cetak Kwitansi</div>
            <div class="step-desc">Unduh kwitansi resmi setelah pembayaran diverifikasi</div>
          </div>
        </div>
      </div>

      <div class="testimonial">
        <div class="testi-text">"FixPay sangat memudahkan proses pembayaran tagihan. Saya bisa upload bukti transfer dan langsung dapat konfirmasi!"</div>
        <div class="testi-author">— Pengguna FixPay</div>
      </div>
    </div>

    <div class="left-bottom">© <?= date('Y') ?> FixPay · Platform Invoice Profesional</div>
  </div>

  <!-- ══ RIGHT ══ -->
  <div class="right">
    <div class="form-header">
      <h1 class="form-title">Buat Akun Baru ✨</h1>
      <p class="form-sub">Gratis selamanya · Tidak perlu kartu kredit</p>
    </div>

    <?php if ($error): ?>
    <div class="alert-error"><i class="ph ph-warning-circle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="register.php" novalidate>
      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label">Nama Lengkap <span style="color:#f87171">*</span></label>
          <div class="input-wrap">
            <i class="ph ph-user input-icon"></i>
            <input type="text" name="name" class="form-input" placeholder="Nama Anda"
                   required value="<?= htmlspecialchars($_POST['name']??'') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Telepon <span class="optional">opsional</span></label>
          <div class="input-wrap">
            <i class="ph ph-phone input-icon"></i>
            <input type="tel" name="phone" class="form-input" placeholder="08xx-xxxx-xxxx"
                   value="<?= htmlspecialchars($_POST['phone']??'') ?>">
          </div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Perusahaan / Bisnis <span class="optional">opsional</span></label>
        <div class="input-wrap">
          <i class="ph ph-buildings input-icon"></i>
          <input type="text" name="company" class="form-input" placeholder="PT / CV / Nama Usaha"
                 value="<?= htmlspecialchars($_POST['company']??'') ?>">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Alamat Email <span style="color:#f87171">*</span></label>
        <div class="input-wrap">
          <i class="ph ph-envelope input-icon"></i>
          <input type="email" name="email" class="form-input" placeholder="nama@email.com"
                 required value="<?= htmlspecialchars($_POST['email']??'') ?>">
        </div>
      </div>

      <div class="form-row-2">
        <div class="form-group">
          <label class="form-label">Password <span style="color:#f87171">*</span></label>
          <div class="input-wrap">
            <i class="ph ph-lock input-icon"></i>
            <input type="password" name="password" id="p1" class="form-input"
                   placeholder="Min. 6 karakter" required oninput="checkStr(this.value)">
            <button type="button" class="pass-toggle" onclick="tp('p1','e1')"><i class="ph ph-eye" id="e1"></i></button>
          </div>
          <div class="strength-bar"><div class="strength-fill" id="sf"></div></div>
          <div class="strength-text" id="st"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Konfirmasi <span style="color:#f87171">*</span></label>
          <div class="input-wrap">
            <i class="ph ph-lock-key input-icon"></i>
            <input type="password" name="confirm_password" id="p2" class="form-input"
                   placeholder="Ulangi password" required>
            <button type="button" class="pass-toggle" onclick="tp('p2','e2')"><i class="ph ph-eye" id="e2"></i></button>
          </div>
        </div>
      </div>

      <div class="terms">
        <input type="checkbox" name="terms" id="terms" required>
        <label for="terms">Saya menyetujui <a href="#">Syarat & Ketentuan</a> dan <a href="#">Kebijakan Privasi</a> FixPay.</label>
      </div>

      <button type="submit" class="btn-submit">
        <i class="ph ph-user-plus"></i> Buat Akun Sekarang
      </button>
    </form>

    <div class="auth-link">Sudah punya akun? <a href="login.php">Masuk di sini</a></div>
  </div>

</div>

<script>
function tp(id, eyeId){
  var i=document.getElementById(id), e=document.getElementById(eyeId);
  i.type=i.type==='password'?'text':'password';
  e.className=i.type==='text'?'ph ph-eye-slash':'ph ph-eye';
}
function checkStr(v){
  var sf=document.getElementById('sf'), st=document.getElementById('st');
  var s=0;
  if(v.length>=6)s++;if(v.length>=10)s++;
  if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
  var lvl=[
    {w:'0%',c:'transparent',t:''},
    {w:'25%',c:'#f87171',t:'Sangat lemah'},
    {w:'50%',c:'#fb923c',t:'Lemah'},
    {w:'75%',c:'#facc15',t:'Cukup kuat'},
    {w:'100%',c:'#4ade80',t:'Kuat'},
    {w:'100%',c:'#22c55e',t:'Sangat kuat'}
  ][Math.min(s,5)];
  sf.style.width=lvl.w;sf.style.background=lvl.c;
  st.textContent=lvl.t;st.style.color=lvl.c;
}
</script>
</body>
</html>