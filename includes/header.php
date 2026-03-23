<?php
// includes/header.php
// Dipanggil di setiap halaman setelah startSession() & requireLogin()
// Wajib set $pageTitle dan $activeMenu sebelum include

$roleLabels = ['admin'=>'Super Admin','user'=>'User Bisnis','member'=>'Member'];
$roleIcons  = ['admin'=>'ph-crown-simple','user'=>'ph-user-gear','member'=>'ph-user'];
$role       = $_SESSION['role'];
$firstName  = explode(' ', $_SESSION['name'])[0];

$db2 = getDB();
$ns2 = $db2->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$ns2->execute([$_SESSION['user_id']]);
$notifCount = $ns2->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($pageTitle ?? 'FixPay') ?> — FixPay</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1/src/index.js" defer></script>
<style>
/* ── RESET ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{height:100%;-webkit-text-size-adjust:100%}
:root{
  --bg:#07090f;--surf:#0c1120;--surf2:#101827;--surf3:#141f30;
  --line:rgba(255,255,255,.06);--line2:rgba(255,255,255,.04);
  --gold:#c8a04a;--goldh:#e2be72;--gdim:rgba(200,160,74,.10);--gring:rgba(200,160,74,.22);
  --txt:#e8e4db;--txt2:#8c97aa;--txt3:#505a6c;
  --green:#22c55e;--red:#ef4444;--blue:#60a5fa;--amber:#f59e0b;--purple:#a78bfa;
  --radius:11px;--sidebar:232px;--topbar:52px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--txt);font-size:13px;line-height:1.55;-webkit-font-smoothing:antialiased;min-height:100vh;overflow-x:hidden}

/* ── LAYOUT ── */
.layout{display:flex;min-height:100vh}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar);background:var(--surf);border-right:1px solid var(--line);position:fixed;top:0;left:0;bottom:0;z-index:300;display:flex;flex-direction:column;transition:transform .28s cubic-bezier(.4,0,.2,1);overflow-y:auto;overflow-x:hidden;scrollbar-width:thin;scrollbar-color:var(--line) transparent}
.sidebar::-webkit-scrollbar{width:3px}
.sidebar::-webkit-scrollbar-thumb{background:var(--line);border-radius:3px}
.sb-logo{display:flex;align-items:center;gap:8px;padding:16px 15px 12px;text-decoration:none;border-bottom:1px solid var(--line2);flex-shrink:0}
.sb-logomark{width:30px;height:30px;background:linear-gradient(135deg,var(--gold),var(--goldh));border-radius:8px;display:grid;place-items:center;color:#05080e;font-size:.85rem;flex-shrink:0}
.sb-logotext{font-family:'Cormorant Garamond',serif;font-size:1.25rem;font-weight:600;background:linear-gradient(90deg,var(--gold),var(--goldh));-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.sb-rolewrap{padding:9px 14px;border-bottom:1px solid var(--line2)}
.sb-rolepill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:99px;font-size:.67rem;font-weight:500;letter-spacing:.05em;text-transform:uppercase}
.sb-rolepill.admin{background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.2)}
.sb-rolepill.user{background:var(--gdim);color:var(--gold);border:1px solid var(--gring)}
.sb-rolepill.member{background:rgba(99,102,241,.1);color:#a5b4fc;border:1px solid rgba(99,102,241,.2)}
.sb-nav{flex:1;padding:8px 0}
.sb-navlabel{padding:10px 15px 3px;font-size:.62rem;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--txt3)}
.sb-navitem{display:flex;align-items:center;gap:9px;padding:7px 15px;color:var(--txt2);text-decoration:none;font-size:.82rem;border-left:2px solid transparent;transition:color .15s,background .15s,border-color .15s}
.sb-navitem:hover{color:var(--txt);background:rgba(255,255,255,.025)}
.sb-navitem.active{color:var(--gold);background:var(--gdim);border-left-color:var(--gold);font-weight:500}
.sb-navitem i{font-size:.9rem;flex-shrink:0;width:16px;text-align:center}
.sb-navbadge{margin-left:auto;background:var(--red);color:#fff;font-size:.6rem;font-weight:700;padding:1px 5px;border-radius:99px;line-height:1.4}
.sb-footer{padding:10px 13px;border-top:1px solid var(--line2);flex-shrink:0}
.sb-userrow{display:flex;align-items:center;gap:8px}
.sb-avatar{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--gold),var(--goldh));display:grid;place-items:center;font-size:.78rem;font-weight:700;color:#05080e;flex-shrink:0}
.sb-userinfo{flex:1;min-width:0}
.sb-username{font-size:.79rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-useremail{font-size:.67rem;color:var(--txt3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logoutbtn{width:28px;height:28px;border-radius:6px;display:grid;place-items:center;color:var(--txt3);text-decoration:none;font-size:.9rem;flex-shrink:0;transition:all .15s}
.sb-logoutbtn:hover{background:rgba(239,68,68,.12);color:#f87171}

/* ── MAIN ── */
.main-area{margin-left:var(--sidebar);flex:1;display:flex;flex-direction:column;min-width:0}

/* ── TOPBAR ── */
.topbar{position:sticky;top:0;z-index:200;height:var(--topbar);background:rgba(7,9,15,.92);backdrop-filter:blur(18px) saturate(200%);-webkit-backdrop-filter:blur(18px);border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 18px;gap:10px;flex-shrink:0}
.tb-hamburger{display:none;background:none;border:none;color:var(--txt2);cursor:pointer;font-size:1.1rem;padding:4px;border-radius:6px;transition:color .15s}
.tb-hamburger:hover{color:var(--txt)}
.tb-breadcrumb{flex:1;display:flex;align-items:center;gap:6px;font-size:.8rem}
.tb-bpage{font-weight:500;color:var(--txt)}
.tb-bsep,.tb-bsub{color:var(--txt3)}
.tb-actions{display:flex;align-items:center;gap:6px}
.tb-iconbtn{position:relative;width:32px;height:32px;border-radius:8px;border:1px solid var(--line);background:var(--surf2);color:var(--txt2);display:grid;place-items:center;font-size:.88rem;cursor:pointer;text-decoration:none;transition:all .15s}
.tb-iconbtn:hover{border-color:var(--gring);color:var(--gold)}
.tb-notifdot{position:absolute;top:6px;right:6px;width:6px;height:6px;background:var(--red);border-radius:50%;border:1.5px solid var(--bg)}
.tb-newbtn{display:inline-flex;align-items:center;gap:5px;padding:0 13px;height:32px;background:linear-gradient(135deg,var(--gold),var(--goldh));color:#05080e;border-radius:8px;font-size:.79rem;font-weight:600;font-family:'DM Sans',sans-serif;border:none;cursor:pointer;text-decoration:none;white-space:nowrap;transition:opacity .15s,transform .15s,box-shadow .15s}
.tb-newbtn:hover{opacity:.88;transform:translateY(-1px);box-shadow:0 5px 16px rgba(200,160,74,.3)}
.tb-newbtn i{font-size:.85rem}

/* ── PAGE CONTENT ── */
.page-content{padding:20px;flex:1}

/* ── COMMON COMPONENTS ── */
.card-box{background:var(--surf);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
.card-head{display:flex;align-items:center;padding:12px 15px;gap:9px;border-bottom:1px solid var(--line2)}
.card-headicon{width:28px;height:28px;border-radius:7px;display:grid;place-items:center;font-size:.8rem;flex-shrink:0;background:var(--gdim);color:var(--gold);border:1px solid var(--gring)}
.card-title{font-size:.84rem;font-weight:500;flex:1}
.card-link{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;color:var(--gold);text-decoration:none;white-space:nowrap}
.card-link:hover{opacity:.75}

/* Pill / Badge */
.status-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:.67rem;font-weight:500;white-space:nowrap}
.status-dot{width:5px;height:5px;border-radius:50%;flex-shrink:0}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:0 14px;height:34px;border-radius:8px;font-size:.8rem;font-weight:500;font-family:'DM Sans',sans-serif;border:none;cursor:pointer;text-decoration:none;transition:all .15s;white-space:nowrap}
.btn i{font-size:.88rem}
.btn-primary{background:linear-gradient(135deg,var(--gold),var(--goldh));color:#05080e}
.btn-primary:hover{opacity:.88;transform:translateY(-1px);box-shadow:0 5px 16px rgba(200,160,74,.3)}
.btn-ghost{background:rgba(255,255,255,.04);color:var(--txt2);border:1px solid var(--line)}
.btn-ghost:hover{border-color:var(--gring);color:var(--gold)}
.btn-danger{background:rgba(239,68,68,.1);color:var(--red);border:1px solid rgba(239,68,68,.2)}
.btn-danger:hover{background:rgba(239,68,68,.18)}
.btn-sm{height:28px;padding:0 10px;font-size:.75rem}

/* Table */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{padding:8px 14px;font-size:.64rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--txt3);background:rgba(255,255,255,.015);border-bottom:1px solid var(--line2);text-align:left;white-space:nowrap}
tbody td{padding:9px 14px;font-size:.8rem;border-bottom:1px solid var(--line2);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr{transition:background .12s}
tbody tr:hover td{background:rgba(255,255,255,.018)}

/* Form Elements */
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:.77rem;color:var(--txt2);margin-bottom:5px;font-weight:500}
.form-label span{color:var(--red);margin-left:2px}
.form-input,.form-select,.form-textarea{width:100%;padding:8px 11px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:8px;color:var(--txt);font-family:'DM Sans',sans-serif;font-size:.83rem;outline:none;transition:border .15s,background .15s}
.form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--gold);background:rgba(200,160,74,.05)}
.form-input::placeholder,.form-textarea::placeholder{color:var(--txt3)}
.form-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23505a6c' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
.form-select option{background:var(--surf2);color:var(--txt)}
.form-textarea{resize:vertical;min-height:80px}
.form-hint{font-size:.7rem;color:var(--txt3);margin-top:4px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}

/* Alert */
.alert{padding:10px 13px;border-radius:8px;font-size:.8rem;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.alert-success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:var(--green)}
.alert-error{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:var(--red)}
.alert-warning{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);color:var(--amber)}

/* Empty state */
.empty-state{text-align:center;padding:50px 20px;color:var(--txt3)}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:10px;opacity:.3}
.empty-state h3{font-size:.9rem;font-weight:500;color:var(--txt2);margin-bottom:6px}
.empty-state p{font-size:.78rem;line-height:1.6}

/* Overlay */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:250;backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
.sidebar-overlay.visible{display:block}

/* Mobile bottom nav */
.bottom-nav{display:none;position:fixed;bottom:0;left:0;right:0;height:58px;background:var(--surf);border-top:1px solid var(--line);z-index:200;padding:0 4px;padding-bottom:env(safe-area-inset-bottom,0px);align-items:center;justify-content:space-around}
.bnav-item{display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 10px;min-width:48px;text-align:center;color:var(--txt3);text-decoration:none;border-radius:8px;font-size:.59rem;transition:color .13s}
.bnav-item i{font-size:1.1rem}
.bnav-item.active{color:var(--gold)}

/* Animation */
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.anim{animation:fadeUp .3s ease both}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:500;backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px}
.modal-overlay.open{display:flex}
.modal{background:var(--surf);border:1px solid var(--line);border-radius:14px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;animation:fadeUp .25s ease both}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:15px 18px;border-bottom:1px solid var(--line2)}
.modal-title{font-size:.9rem;font-weight:600}
.modal-close{width:28px;height:28px;border-radius:6px;border:none;background:rgba(255,255,255,.05);color:var(--txt2);cursor:pointer;font-size:.9rem;display:grid;place-items:center;transition:all .15s}
.modal-close:hover{background:rgba(239,68,68,.1);color:var(--red)}
.modal-body{padding:18px}
.modal-foot{padding:12px 18px;border-top:1px solid var(--line2);display:flex;gap:8px;justify-content:flex-end}

/* Responsive */
@media(max-width:1080px){.form-row-3{grid-template-columns:1fr 1fr}}
@media(max-width:720px){
  .sidebar{transform:translateX(-100%)}
  .sidebar.drawer-open{transform:translateX(0);box-shadow:24px 0 60px rgba(0,0,0,.55)}
  .main-area{margin-left:0}
  .tb-hamburger{display:flex}
  .topbar{padding:0 14px}
  .tb-bsub{display:none}
  .tb-newbtn span{display:none}
  .tb-newbtn{padding:0 10px}
  .page-content{padding:13px;padding-bottom:72px}
  .form-row,.form-row-3{grid-template-columns:1fr}
  .bottom-nav{display:flex}
  .hide-mobile{display:none}
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="mainSidebar">
  <a href="index.php" class="sb-logo">
    <div class="sb-logomark"><i class="ph-bold ph-receipt"></i></div>
    <span class="sb-logotext">FixPay</span>
  </a>
  <div class="sb-rolewrap">
    <span class="sb-rolepill <?= $role ?>">
      <i class="ph <?= $roleIcons[$role] ?>"></i>
      <?= $roleLabels[$role] ?>
    </span>
  </div>
  <nav class="sb-nav">
    <div class="sb-navlabel">Utama</div>
    <a href="dashboard.php" class="sb-navitem <?= ($activeMenu??'')==='dashboard'?'active':'' ?>"><i class="ph ph-squares-four"></i>Dashboard</a>

    <?php if ($role === 'member'): ?>
    <!-- ── Member: hanya lihat tagihan & riwayat ── -->
    <a href="invoices.php"  class="sb-navitem <?= ($activeMenu??'')==='invoices'?'active':'' ?>"><i class="ph ph-receipt"></i>Tagihan Saya</a>
    <a href="payments.php"  class="sb-navitem <?= ($activeMenu??'')==='payments'?'active':'' ?>"><i class="ph ph-clock-counter-clockwise"></i>Riwayat Bayar</a>

    <?php else: ?>
    <!-- ── User & Admin ── -->
    <a href="invoices.php"  class="sb-navitem <?= ($activeMenu??'')==='invoices'?'active':'' ?>"><i class="ph ph-receipt"></i>Invoice</a>
    <a href="clients.php"   class="sb-navitem <?= ($activeMenu??'')==='clients'?'active':'' ?>"><i class="ph ph-users"></i>Klien</a>
    <a href="payments.php"  class="sb-navitem <?= ($activeMenu??'')==='payments'?'active':'' ?>"><i class="ph ph-credit-card"></i>Pembayaran</a>
    <a href="services.php"  class="sb-navitem <?= ($activeMenu??'')==='services'?'active':'' ?>"><i class="ph ph-wrench"></i>Master Layanan</a>

    <div class="sb-navlabel" style="margin-top:5px">Laporan</div>
    <a href="reports.php"   class="sb-navitem <?= ($activeMenu??'')==='reports'?'active':'' ?>"><i class="ph ph-chart-bar"></i>Statistik</a>
    <a href="reports.php?export=csv" class="sb-navitem"><i class="ph ph-download-simple"></i>Export CSV</a>

    <?php if ($role === 'admin'): ?>
    <div class="sb-navlabel" style="margin-top:5px">Administrasi</div>
    <a href="users.php"     class="sb-navitem <?= ($activeMenu??'')==='users'?'active':'' ?>"><i class="ph ph-users-three"></i>Kelola Pengguna</a>
    <?php endif; ?>
    <?php endif; ?>

    <div class="sb-navlabel" style="margin-top:5px">Akun</div>
    <a href="notifications.php" class="sb-navitem <?= ($activeMenu??'')==='notifications'?'active':'' ?>">
      <i class="ph ph-bell"></i>Notifikasi
      <?php if ($notifCount > 0): ?><span class="sb-navbadge"><?= $notifCount ?></span><?php endif; ?>
    </a>
    <a href="profile.php" class="sb-navitem <?= ($activeMenu??'')==='profile'?'active':'' ?>"><i class="ph ph-gear"></i>Pengaturan</a>
  </nav>
  <div class="sb-footer">
    <div class="sb-userrow">
      <div class="sb-avatar"><?= strtoupper(mb_substr($_SESSION['name'],0,1)) ?></div>
      <div class="sb-userinfo">
        <div class="sb-username"><?= htmlspecialchars($_SESSION['name']) ?></div>
        <div class="sb-useremail"><?= htmlspecialchars($_SESSION['email']) ?></div>
      </div>
      <a href="logout.php" class="sb-logoutbtn" title="Keluar"><i class="ph ph-sign-out"></i></a>
    </div>
  </div>
</aside>

<!-- MAIN -->
<div class="main-area">
  <header class="topbar">
    <button class="tb-hamburger" onclick="openSidebar()"><i class="ph ph-list"></i></button>
    <div class="tb-breadcrumb">
      <span class="tb-bpage"><?= htmlspecialchars($pageTitle ?? 'Halaman') ?></span>
      <?php if (!empty($pageSubtitle)): ?>
      <span class="tb-bsep">/</span>
      <span class="tb-bsub"><?= htmlspecialchars($pageSubtitle) ?></span>
      <?php endif; ?>
    </div>
    <div class="tb-actions">
      <a href="notifications.php" class="tb-iconbtn">
        <i class="ph ph-bell"></i>
        <?php if ($notifCount > 0): ?><span class="tb-notifdot"></span><?php endif; ?>
      </a>
      <a href="profile.php" class="tb-iconbtn"><i class="ph ph-gear"></i></a>
      <?php if (!empty($topbarBtn)): ?>
      <a href="<?= $topbarBtn['url'] ?>" class="tb-newbtn">
        <i class="ph <?= $topbarBtn['icon'] ?>"></i>
        <span><?= $topbarBtn['label'] ?></span>
      </a>
      <?php endif; ?>
    </div>
  </header>
  <div class="page-content">