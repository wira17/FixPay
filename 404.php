<?php
require_once 'config/database.php';
startSession();
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>404 — Halaman Tidak Ditemukan · FixPay</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js" defer></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:#07090f;color:#e8e4db;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;-webkit-font-smoothing:antialiased}
body::before{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 50% at 50% 30%,rgba(200,160,74,.07),transparent 65%);pointer-events:none}
.wrap{text-align:center;max-width:420px;position:relative;z-index:1}
.num{font-family:'Cormorant Garamond',serif;font-size:clamp(6rem,20vw,9rem);font-weight:300;line-height:1;background:linear-gradient(135deg,#c8a04a,#e2be72);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:0;animation:fadeUp .5s ease both}
.icon{font-size:2.5rem;color:rgba(200,160,74,.4);margin-bottom:1rem;animation:fadeUp .5s .1s ease both}
h1{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;margin-bottom:.7rem;animation:fadeUp .5s .15s ease both}
p{font-size:.85rem;color:#8c97aa;line-height:1.7;margin-bottom:2rem;animation:fadeUp .5s .2s ease both}
.actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;animation:fadeUp .5s .25s ease both}
.btn{display:inline-flex;align-items:center;gap:6px;padding:0 18px;height:38px;border-radius:9px;font-size:.82rem;font-weight:500;font-family:'DM Sans',sans-serif;text-decoration:none;transition:all .15s;border:none;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,#c8a04a,#e2be72);color:#05080e}
.btn-primary:hover{opacity:.88;transform:translateY(-1px)}
.btn-ghost{background:rgba(255,255,255,.04);color:#8c97aa;border:1px solid rgba(255,255,255,.08)}
.btn-ghost:hover{color:#e8e4db;border-color:rgba(200,160,74,.3)}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<div class="wrap">
  <div class="num">404</div>
  <div class="icon"><i class="ph ph-map-trifold"></i></div>
  <h1>Halaman Tidak Ditemukan</h1>
  <p>Halaman yang Anda cari tidak ada atau telah dipindahkan. Periksa kembali URL atau kembali ke dashboard.</p>
  <div class="actions">
    <?php if (isLoggedIn()): ?>
    <a href="dashboard.php" class="btn btn-primary"><i class="ph ph-squares-four"></i> Ke Dashboard</a>
    <a href="javascript:history.back()" class="btn btn-ghost"><i class="ph ph-arrow-left"></i> Kembali</a>
    <?php else: ?>
    <a href="index.php"  class="btn btn-primary"><i class="ph ph-house"></i> Beranda</a>
    <a href="login.php"  class="btn btn-ghost"><i class="ph ph-sign-in"></i> Masuk</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>