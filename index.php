<?php
require_once 'config/database.php';
startSession();
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FixPay — Solusi Invoice Profesional</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js"></script>
<style>
  :root {
    --navy: #0a0e1a;
    --navy-2: #111827;
    --navy-3: #1a2235;
    --gold: #c9a84c;
    --gold-light: #e8c97a;
    --gold-dim: rgba(201,168,76,0.15);
    --white: #f4f1eb;
    --muted: #8892a4;
    --border: rgba(201,168,76,0.2);
    --radius: 12px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { scroll-behavior: smooth; }
  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--navy);
    color: var(--white);
    min-height: 100vh;
    overflow-x: hidden;
  }

  /* Background mesh */
  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
      radial-gradient(ellipse 80% 50% at 20% 20%, rgba(201,168,76,0.07) 0%, transparent 60%),
      radial-gradient(ellipse 60% 60% at 80% 80%, rgba(201,168,76,0.05) 0%, transparent 60%);
    pointer-events: none;
    z-index: 0;
  }

  /* NAV */
  nav {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 100;
    padding: 1.2rem 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    backdrop-filter: blur(20px);
    background: rgba(10,14,26,0.85);
    border-bottom: 1px solid var(--border);
  }
  .logo {
    display: flex;
    align-items: center;
    gap: .6rem;
    text-decoration: none;
  }
  .logo-icon {
    width: 34px; height: 34px;
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    border-radius: 8px;
    display: grid; place-items: center;
    font-size: 1rem;
    color: var(--navy);
    font-weight: 700;
  }
  .logo-text {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.5rem;
    font-weight: 600;
    background: linear-gradient(to right, var(--gold), var(--gold-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    letter-spacing: .03em;
  }
  .nav-links { display: flex; align-items: center; gap: 1rem; }
  .btn-outline {
    padding: .5rem 1.2rem;
    border: 1px solid var(--border);
    color: var(--white);
    border-radius: 8px;
    text-decoration: none;
    font-size: .85rem;
    font-family: 'DM Sans', sans-serif;
    transition: all .25s;
    background: transparent;
    cursor: pointer;
  }
  .btn-outline:hover { border-color: var(--gold); color: var(--gold); }
  .btn-gold {
    padding: .5rem 1.4rem;
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    color: var(--navy);
    border-radius: 8px;
    text-decoration: none;
    font-size: .85rem;
    font-weight: 500;
    font-family: 'DM Sans', sans-serif;
    transition: all .25s;
    border: none;
    cursor: pointer;
  }
  .btn-gold:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(201,168,76,.3); }

  /* HERO */
  .hero {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 6rem 1.5rem 4rem;
  }
  .hero-inner { max-width: 760px; }
  .badge {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .35rem .9rem;
    border: 1px solid var(--border);
    border-radius: 99px;
    font-size: .75rem;
    color: var(--gold);
    margin-bottom: 1.8rem;
    letter-spacing: .06em;
    text-transform: uppercase;
  }
  .hero h1 {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(2.6rem, 7vw, 5rem);
    font-weight: 300;
    line-height: 1.1;
    margin-bottom: 1.4rem;
    letter-spacing: -.01em;
  }
  .hero h1 em {
    font-style: normal;
    background: linear-gradient(to right, var(--gold), var(--gold-light));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .hero p {
    font-size: 1rem;
    color: var(--muted);
    line-height: 1.7;
    max-width: 520px;
    margin: 0 auto 2.5rem;
  }
  .hero-cta { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
  .btn-hero {
    padding: .85rem 2rem;
    font-size: .95rem;
    border-radius: 10px;
    text-decoration: none;
    font-family: 'DM Sans', sans-serif;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    transition: all .25s;
  }
  .btn-hero.primary {
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    color: var(--navy);
  }
  .btn-hero.primary:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(201,168,76,.35); }
  .btn-hero.secondary {
    border: 1px solid var(--border);
    color: var(--white);
    background: transparent;
  }
  .btn-hero.secondary:hover { border-color: var(--gold); color: var(--gold); }

  /* STATS */
  .stats {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: center;
    gap: 0;
    max-width: 700px;
    margin: 0 auto;
    padding: 2rem 1.5rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: rgba(255,255,255,.02);
    backdrop-filter: blur(10px);
  }
  .stat { flex: 1; text-align: center; padding: 0 1.5rem; }
  .stat + .stat { border-left: 1px solid var(--border); }
  .stat-num {
    font-family: 'Cormorant Garamond', serif;
    font-size: 2rem;
    font-weight: 600;
    color: var(--gold);
    line-height: 1;
    margin-bottom: .3rem;
  }
  .stat-label { font-size: .78rem; color: var(--muted); letter-spacing: .04em; text-transform: uppercase; }

  /* FEATURES */
  .section {
    position: relative;
    z-index: 1;
    padding: 5rem 1.5rem;
  }
  .section-header { text-align: center; margin-bottom: 3.5rem; }
  .section-tag {
    font-size: .72rem;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: .8rem;
  }
  .section-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(1.8rem, 4vw, 2.8rem);
    font-weight: 300;
    line-height: 1.2;
  }
  .section-title em { font-style: normal; color: var(--gold-light); }
  .features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 1.2rem;
    max-width: 1000px;
    margin: 0 auto;
  }
  .feature-card {
    padding: 1.8rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: rgba(255,255,255,.02);
    transition: all .3s;
    position: relative;
    overflow: hidden;
  }
  .feature-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, var(--gold-dim), transparent);
    opacity: 0;
    transition: opacity .3s;
  }
  .feature-card:hover { border-color: var(--gold); transform: translateY(-3px); }
  .feature-card:hover::before { opacity: 1; }
  .feature-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    background: var(--gold-dim);
    border: 1px solid var(--border);
    display: grid; place-items: center;
    margin-bottom: 1.2rem;
    color: var(--gold);
    font-size: 1.2rem;
    position: relative;
  }
  .feature-card h3 {
    font-size: .95rem;
    font-weight: 500;
    margin-bottom: .5rem;
    position: relative;
  }
  .feature-card p {
    font-size: .83rem;
    color: var(--muted);
    line-height: 1.6;
    position: relative;
  }

  /* ROLES */
  .roles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.2rem;
    max-width: 780px;
    margin: 0 auto;
  }
  .role-card {
    padding: 2rem 1.5rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: rgba(255,255,255,.02);
    text-align: center;
    transition: all .3s;
  }
  .role-card:hover { border-color: var(--gold); transform: translateY(-3px); }
  .role-badge {
    display: inline-block;
    padding: .3rem .9rem;
    border-radius: 99px;
    font-size: .7rem;
    font-weight: 500;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 1rem;
  }
  .badge-admin { background: rgba(239,68,68,.15); color: #f87171; border: 1px solid rgba(239,68,68,.3); }
  .badge-user  { background: rgba(201,168,76,.15); color: var(--gold); border: 1px solid var(--border); }
  .badge-member { background: rgba(99,102,241,.15); color: #a5b4fc; border: 1px solid rgba(99,102,241,.3); }
  .role-card h3 { font-size: 1rem; margin-bottom: .5rem; }
  .role-card p { font-size: .8rem; color: var(--muted); line-height: 1.6; }

  /* CTA SECTION */
  .cta-section {
    position: relative;
    z-index: 1;
    padding: 4rem 1.5rem 6rem;
    text-align: center;
  }
  .cta-box {
    max-width: 600px;
    margin: 0 auto;
    padding: 3rem 2rem;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(201,168,76,.06), rgba(255,255,255,.02));
    backdrop-filter: blur(10px);
  }
  .cta-box h2 {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(1.6rem, 4vw, 2.4rem);
    font-weight: 300;
    margin-bottom: 1rem;
  }
  .cta-box p { color: var(--muted); font-size: .9rem; margin-bottom: 2rem; line-height: 1.7; }

  /* FOOTER */
  footer {
    position: relative;
    z-index: 1;
    border-top: 1px solid var(--border);
    padding: 1.5rem 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: .8rem;
    color: var(--muted);
  }

  @media (max-width: 600px) {
    nav { padding: 1rem; }
    .stats { flex-direction: column; gap: 1.2rem; }
    .stat + .stat { border-left: none; border-top: 1px solid var(--border); padding-top: 1.2rem; }
    footer { flex-direction: column; align-items: center; text-align: center; }
  }
</style>
</head>
<body>

<!-- NAV -->
<nav>
  <a href="index.php" class="logo">
    <div class="logo-icon"><i class="ph-bold ph-receipt"></i></div>
    <span class="logo-text">FixPay</span>
  </a>
  <div class="nav-links">
    <a href="login.php" class="btn-outline">Masuk</a>
    <a href="register.php" class="btn-gold">Daftar Gratis</a>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-inner">
    <div class="badge">
      <i class="ph ph-sparkle"></i>
      Platform Invoice Profesional #1
    </div>
    <h1>Kelola Invoice<br><em>Tanpa Repot,</em><br>Bayar Tepat Waktu</h1>
    <p>FixPay membantu bisnis Anda membuat, mengirim, dan melacak invoice secara profesional. Kelola pembayaran dengan lebih cerdas dan efisien.</p>
    <div class="hero-cta">
      <a href="register.php" class="btn-hero primary">
        <i class="ph ph-rocket-launch"></i>
        Mulai Gratis Sekarang
      </a>
      <a href="login.php" class="btn-hero secondary">
        <i class="ph ph-sign-in"></i>
        Sudah punya akun?
      </a>
    </div>
  </div>
</section>

<!-- STATS -->
<div style="position:relative;z-index:1;padding:0 1.5rem 4rem;">
  <div class="stats">
    <div class="stat">
      <div class="stat-num">10K+</div>
      <div class="stat-label">Invoice Dibuat</div>
    </div>
    <div class="stat">
      <div class="stat-num">99%</div>
      <div class="stat-label">Uptime</div>
    </div>
    <div class="stat">
      <div class="stat-num">500+</div>
      <div class="stat-label">Bisnis Aktif</div>
    </div>
    <div class="stat">
      <div class="stat-num">Rp2M+</div>
      <div class="stat-label">Total Diproses</div>
    </div>
  </div>
</div>

<!-- FEATURES -->
<section class="section">
  <div class="section-header">
    <div class="section-tag">Fitur Unggulan</div>
    <h2 class="section-title">Semua yang Anda Butuhkan<br>untuk <em>Kelola Pembayaran</em></h2>
  </div>
  <div class="features-grid">
    <?php
    $features = [
      ['ph-receipt', 'Invoice Instan', 'Buat invoice profesional dalam hitungan detik dengan template siap pakai.'],
      ['ph-chart-line-up', 'Laporan Real-time', 'Pantau status pembayaran dan pendapatan secara langsung dari dashboard.'],
      ['ph-users', 'Manajemen Klien', 'Simpan data klien dan riwayat transaksi lengkap di satu tempat.'],
      ['ph-bell-ringing', 'Notifikasi Otomatis', 'Kirim pengingat pembayaran otomatis ke klien yang belum membayar.'],
      ['ph-shield-check', 'Aman & Terenkripsi', 'Data Anda terlindungi dengan enkripsi tingkat enterprise.'],
      ['ph-device-mobile-camera', 'Akses Mobile', 'Kelola invoice dari mana saja melalui tampilan yang responsif.'],
    ];
    foreach ($features as $f): ?>
    <div class="feature-card">
      <div class="feature-icon"><i class="ph ph-<?= $f[0] ?>"></i></div>
      <h3><?= $f[1] ?></h3>
      <p><?= $f[2] ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ROLES -->
<section class="section" style="padding-top:0">
  <div class="section-header">
    <div class="section-tag">Akses Bertingkat</div>
    <h2 class="section-title">Tiga <em>Level Akses</em><br>Sesuai Kebutuhan</h2>
  </div>
  <div class="roles-grid">
    <div class="role-card">
      <span class="role-badge badge-admin"><i class="ph ph-crown-simple"></i> Admin</span>
      <h3>Super Admin</h3>
      <p>Kendali penuh: kelola semua pengguna, laporan global, pengaturan sistem, dan monitoring transaksi.</p>
    </div>
    <div class="role-card">
      <span class="role-badge badge-user"><i class="ph ph-user-gear"></i> User</span>
      <h3>Pengguna Bisnis</h3>
      <p>Buat & kelola invoice sendiri, manajemen klien, laporan pendapatan, dan pembayaran.</p>
    </div>
    <div class="role-card">
      <span class="role-badge badge-member"><i class="ph ph-user"></i> Member</span>
      <h3>Member</h3>
      <p>Lihat tagihan yang diterima, riwayat pembayaran, dan konfirmasi pembayaran.</p>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta-section">
  <div class="cta-box">
    <h2>Siap Mulai Kelola<br>Invoice Anda?</h2>
    <p>Bergabung dengan ratusan bisnis yang telah mempercayakan manajemen invoice mereka kepada FixPay.</p>
    <a href="register.php" class="btn-hero primary" style="display:inline-flex">
      <i class="ph ph-rocket-launch"></i>
      Daftar Sekarang — Gratis
    </a>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div style="display:flex;align-items:center;gap:.5rem;">
    <div class="logo-icon" style="width:24px;height:24px;font-size:.7rem;border-radius:5px"><i class="ph-bold ph-receipt"></i></div>
    <span style="font-family:'Cormorant Garamond',serif;font-size:1rem;color:var(--gold)">FixPay</span>
  </div>
  <span>© <?= date('Y') ?> FixPay. Semua hak dilindungi.</span>
</footer>

</body>
</html>