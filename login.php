<?php
require_once 'config/database.php';
startSession();
if (isLoggedIn()) { header('Location: dashboard.php'); exit; }

$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email dan password wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['name']          = $user['name'];
            $_SESSION['email']         = $user['email'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['last_activity'] = time();
            $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
            header('Location: dashboard.php'); exit;
        } else {
            $error = 'Email atau password salah, atau akun tidak aktif.';
        }
    }
}

if (isset($_GET['timeout']))    $success = 'Sesi Anda telah berakhir. Silakan masuk kembali.';
if (isset($_GET['registered'])) $success = 'Registrasi berhasil! Silakan masuk.';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Masuk — FixPay</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

html,body{
  height:100%; overflow:hidden;
  font-family:'DM Sans',sans-serif;
  background:#07090f; color:#e8e4db;
}

/* ══ LAYOUT ══ */
.split{
  display:grid;
  grid-template-columns:1fr 1fr;
  height:100vh;
  overflow:hidden;
}

/* ══ LEFT PANEL ══ */
.left{
  position:relative;
  background:linear-gradient(145deg,#07090f 0%,#0c1829 50%,#081a12 100%);
  display:flex;flex-direction:column;
  justify-content:space-between;
  padding:48px;
  overflow:hidden;
}

/* Background decorations */
.left::before{
  content:'';position:absolute;top:-100px;right:-100px;
  width:400px;height:400px;border-radius:50%;
  background:radial-gradient(circle,rgba(200,160,74,.1) 0%,transparent 70%);
  pointer-events:none;
}
.left::after{
  content:'';position:absolute;bottom:-80px;left:-80px;
  width:320px;height:320px;border-radius:50%;
  background:radial-gradient(circle,rgba(34,197,94,.06) 0%,transparent 70%);
  pointer-events:none;
}

.left-top{}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:0}
.brand-icon{
  width:42px;height:42px;border-radius:11px;
  background:linear-gradient(135deg,#c8a04a,#e2be72);
  display:grid;place-items:center;color:#05080e;
  font-size:1.1rem;flex-shrink:0;
}
.brand-name{
  font-family:'Cormorant Garamond',serif;
  font-size:1.7rem;font-weight:600;
  background:linear-gradient(to right,#c8a04a,#e2be72);
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;
}

.left-center{flex:1;display:flex;flex-direction:column;justify-content:center;padding:40px 0}
.left-tagline{
  font-family:'Cormorant Garamond',serif;
  font-size:2.6rem;font-weight:300;
  line-height:1.2;color:#e8e4db;
  margin-bottom:20px;
}
.left-tagline em{
  font-style:italic;color:#e2be72;
  font-weight:400;
}
.left-desc{font-size:.85rem;color:#8c97aa;line-height:1.75;max-width:340px;margin-bottom:32px}

/* Feature list */
.features{display:flex;flex-direction:column;gap:12px}
.feature{
  display:flex;align-items:center;gap:12px;
  padding:11px 14px;
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.06);
  border-radius:10px;
  transition:border-color .2s;
}
.feature:hover{border-color:rgba(200,160,74,.2)}
.feat-icon{
  width:32px;height:32px;border-radius:8px;
  display:grid;place-items:center;font-size:.9rem;flex-shrink:0;
}
.fi-gold  {background:rgba(200,160,74,.12);color:#c8a04a;border:1px solid rgba(200,160,74,.2)}
.fi-green {background:rgba(34,197,94,.1); color:#22c55e;border:1px solid rgba(34,197,94,.2)}
.fi-blue  {background:rgba(96,165,250,.1);color:#60a5fa;border:1px solid rgba(96,165,250,.2)}
.fi-purple{background:rgba(167,139,250,.1);color:#a78bfa;border:1px solid rgba(167,139,250,.2)}
.feat-text{font-size:.8rem;color:#c8d4e8;font-weight:400}

/* Stats row */
.stats{display:flex;gap:24px;margin-top:32px}
.stat{text-align:center}
.stat-val{
  font-family:'Cormorant Garamond',serif;
  font-size:1.6rem;font-weight:400;color:#e2be72;
}
.stat-lbl{font-size:.67rem;color:#505a6c;text-transform:uppercase;letter-spacing:.06em;margin-top:1px}

.left-bottom{font-size:.72rem;color:#505a6c}

/* Floating card decoration */
.float-card{
  position:absolute;right:48px;top:50%;
  transform:translateY(-50%);
  opacity:0;pointer-events:none;
  /* decorative only via pseudo */
}

/* ══ RIGHT PANEL ══ */
.right{
  background:#0c1120;
  border-left:1px solid rgba(255,255,255,.04);
  display:flex;flex-direction:column;
  justify-content:center;
  padding:48px 52px;
  overflow-y:auto;
}

.form-header{margin-bottom:28px}
.form-title{
  font-family:'Cormorant Garamond',serif;
  font-size:2rem;font-weight:300;color:#e8e4db;
  margin-bottom:6px;
}
.form-sub{font-size:.83rem;color:#8c97aa}

/* Alerts */
.alert{
  padding:10px 14px;border-radius:9px;
  font-size:.8rem;margin-bottom:18px;
  display:flex;align-items:center;gap:8px;
}
.alert-error  {background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);color:#f87171}
.alert-success{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);color:#4ade80}

/* Form */
.form-group{margin-bottom:16px}
.form-label{
  display:block;font-size:.75rem;color:#8c97aa;
  margin-bottom:6px;letter-spacing:.02em;
}
.input-wrap{position:relative;display:flex;align-items:center}
.input-icon{
  position:absolute;left:12px;color:#505a6c;
  font-size:.95rem;pointer-events:none;transition:color .2s;
}
.form-input{
  width:100%;padding:11px 14px 11px 38px;
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.08);
  border-radius:9px;color:#e8e4db;
  font-family:'DM Sans',sans-serif;font-size:.85rem;
  outline:none;transition:border .2s,background .2s;
}
.form-input:focus{
  border-color:#c8a04a;
  background:rgba(200,160,74,.05);
}
.form-input:focus~.input-icon,
.input-wrap:focus-within .input-icon{color:#c8a04a}
.pass-toggle{
  position:absolute;right:12px;background:none;border:none;
  color:#505a6c;cursor:pointer;font-size:.95rem;padding:0;
  transition:color .2s;
}
.pass-toggle:hover{color:#c8a04a}

.form-row-2{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.remember{display:flex;align-items:center;gap:6px;font-size:.78rem;color:#8c97aa;cursor:pointer}
.remember input{accent-color:#c8a04a;width:13px;height:13px}
.forgot{font-size:.78rem;color:#c8a04a;text-decoration:none}
.forgot:hover{text-decoration:underline}

.btn-submit{
  width:100%;padding:12px;
  background:linear-gradient(135deg,#c8a04a,#e2be72);
  color:#05080e;border:none;border-radius:10px;
  font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;
  cursor:pointer;transition:all .22s;
  display:flex;align-items:center;justify-content:center;gap:8px;
  margin-bottom:20px;letter-spacing:.02em;
}
.btn-submit:hover{opacity:.88;transform:translateY(-1px);box-shadow:0 8px 24px rgba(200,160,74,.3)}
.btn-submit:active{transform:translateY(0)}

.divider{
  text-align:center;font-size:.75rem;color:#505a6c;
  position:relative;margin-bottom:16px;
}
.divider::before,.divider::after{
  content:'';position:absolute;top:50%;
  width:42%;height:1px;background:rgba(255,255,255,.06);
}
.divider::before{left:0}.divider::after{right:0}

.auth-link{text-align:center;font-size:.82rem;color:#8c97aa}
.auth-link a{color:#c8a04a;text-decoration:none;font-weight:500}
.auth-link a:hover{text-decoration:underline}

/* Demo accounts */
.demo-box{
  margin-top:20px;padding:14px;
  border:1px solid rgba(255,255,255,.05);border-radius:10px;
  background:rgba(255,255,255,.02);
}
.demo-title{
  font-size:.65rem;color:#505a6c;
  text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;
}
.demo-row{
  display:flex;justify-content:space-between;align-items:center;
  padding:5px 0;font-size:.75rem;
  border-bottom:1px solid rgba(255,255,255,.03);gap:8px;
  cursor:pointer;transition:background .12s;border-radius:5px;padding:5px 6px;
}
.demo-row:last-child{border-bottom:none}
.demo-row:hover{background:rgba(255,255,255,.03)}
.demo-badge{
  padding:2px 7px;border-radius:99px;font-size:.62rem;
  font-weight:600;text-transform:uppercase;letter-spacing:.04em;flex-shrink:0;
}
.b-admin {background:rgba(239,68,68,.12);color:#f87171}
.b-user  {background:rgba(200,160,74,.12);color:#c8a04a}
.b-member{background:rgba(167,139,250,.12);color:#a78bfa}
.demo-email{color:#8c97aa;font-size:.73rem}
.demo-pass {color:#e8e4db;font-family:monospace;font-size:.73rem}
.demo-hint {font-size:.65rem;color:#505a6c;margin-top:8px;text-align:center}

/* ══ RESPONSIVE ══ */
@media(max-width:768px){
  .split{grid-template-columns:1fr}
  .left{display:none}
  .right{padding:36px 28px}
  html,body{overflow:auto}
}
</style>
</head>
<body>

<div class="split">

  <!-- ══ LEFT: BRANDING ══ -->
  <div class="left">
    <div class="left-top">
      <div class="brand">
        <div class="brand-icon"><i class="ph-bold ph-receipt"></i></div>
        <span class="brand-name">FixPay</span>
      </div>
    </div>

    <div class="left-center">
      <h2 class="left-tagline">
        Invoice profesional,<br>
        pembayaran <em>lebih cepat</em>
      </h2>
      <p class="left-desc">
        Kelola tagihan, pantau pembayaran, dan terima bukti transfer dari klien
        — semua dalam satu platform yang elegan.
      </p>

      <div class="features">
        <div class="feature">
          <div class="feat-icon fi-gold"><i class="ph ph-receipt"></i></div>
          <span class="feat-text">Buat invoice profesional dalam hitungan detik</span>
        </div>
        <div class="feature">
          <div class="feat-icon fi-green"><i class="ph ph-check-circle"></i></div>
          <span class="feat-text">Verifikasi bukti transfer langsung dari dashboard</span>
        </div>
        <div class="feature">
          <div class="feat-icon fi-blue"><i class="ph ph-bell"></i></div>
          <span class="feat-text">Notifikasi real-time saat pembayaran masuk</span>
        </div>
        <div class="feature">
          <div class="feat-icon fi-purple"><i class="ph ph-chart-bar"></i></div>
          <span class="feat-text">Laporan & statistik pendapatan otomatis</span>
        </div>
      </div>

      <div class="stats">
        <div class="stat">
          <div class="stat-val">100%</div>
          <div class="stat-lbl">Simple</div>
        </div>
        <div class="stat">
          <div class="stat-val">3</div>
          <div class="stat-lbl">Role Akses</div>
        </div>
        <div class="stat">
          <div class="stat-val">∞</div>
          <div class="stat-lbl">Invoice</div>
        </div>
      </div>
    </div>

    <div class="left-bottom">
      © <?= date('Y') ?> FixPay · Platform Invoice Profesional
    </div>
  </div>

  <!-- ══ RIGHT: FORM ══ -->
  <div class="right">
    <div class="form-header">
      <h1 class="form-title">Selamat Datang<br>Kembali 👋</h1>
      <p class="form-sub">Masuk untuk mengakses dashboard Anda</p>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><i class="ph ph-warning-circle"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="ph ph-check-circle"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php" novalidate>
      <div class="form-group">
        <label class="form-label">Alamat Email</label>
        <div class="input-wrap">
          <i class="ph ph-envelope input-icon"></i>
          <input type="email" name="email" class="form-input"
                 placeholder="nama@email.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 required autocomplete="email">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Password</label>
        <div class="input-wrap">
          <i class="ph ph-lock input-icon"></i>
          <input type="password" name="password" id="passInput" class="form-input"
                 placeholder="Masukkan password" required>
          <button type="button" class="pass-toggle" onclick="togglePass()">
            <i class="ph ph-eye" id="passEye"></i>
          </button>
        </div>
      </div>

      <div class="form-row-2">
        <label class="remember">
          <input type="checkbox" name="remember"> Ingat saya
        </label>
        <a href="#" class="forgot">Lupa password?</a>
      </div>

      <button type="submit" class="btn-submit">
        <i class="ph ph-sign-in"></i> Masuk ke Dashboard
      </button>
    </form>

    <div class="divider">atau</div>
    <div class="auth-link">
      Belum punya akun? <a href="register.php">Daftar sekarang</a>
    </div>

  
</div>

<script>
function togglePass(){
  var i = document.getElementById('passInput');
  var e = document.getElementById('passEye');
  i.type = i.type==='password'?'text':'password';
  e.className = i.type==='text'?'ph ph-eye-slash':'ph ph-eye';
}
function fillDemo(email, pass){
  document.querySelector('[name="email"]').value = email;
  document.getElementById('passInput').value     = pass;
  document.querySelector('.btn-submit').focus();
}
</script>
</body>
</html>