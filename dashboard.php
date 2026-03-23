<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db   = getDB();
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

/* ── Stats sesuai role ── */
if ($role === 'admin') {
    $totalUsers    = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalInvoices = $db->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
    $totalRevenue  = $db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid'")->fetchColumn();
    $pending       = $db->query("SELECT COUNT(*) FROM invoices WHERE status IN ('sent','overdue')")->fetchColumn();
    $cntPaid       = $db->query("SELECT COUNT(*) FROM invoices WHERE status='paid'")->fetchColumn();
    $cntSent       = $db->query("SELECT COUNT(*) FROM invoices WHERE status='sent'")->fetchColumn();
    $cntOver       = $db->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();
    $cntDraft      = $db->query("SELECT COUNT(*) FROM invoices WHERE status='draft'")->fetchColumn();
    $recentInv     = $db->query("SELECT i.*, u.name AS uname FROM invoices i
                                  LEFT JOIN users u ON i.user_id=u.id
                                  ORDER BY i.created_at DESC LIMIT 8")->fetchAll();
    /* Chart 6 bulan terakhir */
    $chartQ = $db->query("SELECT DATE_FORMAT(created_at,'%b') AS lbl,
                           MONTH(created_at) AS mo, YEAR(created_at) AS yr,
                           COUNT(*) AS cnt,
                           COALESCE(SUM(CASE WHEN status='paid' THEN total ELSE 0 END),0) AS rev
                           FROM invoices
                           WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                           GROUP BY yr,mo ORDER BY yr,mo");
} else {
    /* Member: lihat invoice berdasarkan email atau sebagai pembuat */
    $memberEmail = $_SESSION['email'];

    $qm = function($sql) use ($db,$uid,$memberEmail){
        $s = $db->prepare($sql);
        $s->execute([$uid, $memberEmail]);
        return $s->fetchColumn();
    };
    $totalUsers    = null;
    $totalInvoices = $qm("SELECT COUNT(*) FROM invoices WHERE user_id=? OR client_email=?");
    $totalRevenue  = $qm("SELECT COALESCE(SUM(total),0) FROM invoices WHERE (user_id=? OR client_email=?) AND status='paid'");
    $pending       = $qm("SELECT COUNT(*) FROM invoices WHERE (user_id=? OR client_email=?) AND status IN ('sent','overdue')");
    $cntPaid       = $qm("SELECT COUNT(*) FROM invoices WHERE (user_id=? OR client_email=?) AND status='paid'");
    $cntSent       = $qm("SELECT COUNT(*) FROM invoices WHERE (user_id=? OR client_email=?) AND status='sent'");
    $cntOver       = $qm("SELECT COUNT(*) FROM invoices WHERE (user_id=? OR client_email=?) AND status='overdue'");
    $cntDraft      = $qm("SELECT COUNT(*) FROM invoices WHERE (user_id=? OR client_email=?) AND status='draft'");
    $st = $db->prepare("SELECT * FROM invoices WHERE user_id=? OR client_email=? ORDER BY created_at DESC LIMIT 8");
    $st->execute([$uid, $memberEmail]); $recentInv = $st->fetchAll();
    $chartQ = $db->prepare("SELECT DATE_FORMAT(created_at,'%b') AS lbl,
                             MONTH(created_at) AS mo, YEAR(created_at) AS yr,
                             COUNT(*) AS cnt,
                             COALESCE(SUM(CASE WHEN status='paid' THEN total ELSE 0 END),0) AS rev
                             FROM invoices
                             WHERE (user_id=? OR client_email=?) AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                             GROUP BY yr,mo ORDER BY yr,mo");
    $chartQ->execute([$uid, $memberEmail]);
}
$chartRows = isset($chartQ) ? $chartQ->fetchAll() : [];
$chartMax  = max(1, max(array_column($chartRows,'rev') ?: [1]));

/* ── Aktivitas terbaru dari notifikasi ── */
$actQ = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
$actQ->execute([$uid]);
$activities = $actQ->fetchAll();

/* ── Status map ── */
$statusMap = [
    'paid'      => ['bg'=>'#0d2818','color'=>'#4ade80','border'=>'#1a4d2e','dot'=>'#22c55e','label'=>'Lunas'],
    'sent'      => ['bg'=>'#2a1f06','color'=>'#fbbf24','border'=>'#4a3510','dot'=>'#f59e0b','label'=>'Terkirim'],
    'draft'     => ['bg'=>'#161b27','color'=>'#94a3b8','border'=>'#1e2535','dot'=>'#64748b','label'=>'Draft'],
    'overdue'   => ['bg'=>'#2a0d0d','color'=>'#f87171','border'=>'#4d1a1a','dot'=>'#ef4444','label'=>'Jatuh Tempo'],
    'cancelled' => ['bg'=>'#161b27','color'=>'#64748b','border'=>'#1e2535','dot'=>'#475569','label'=>'Batal'],
];

/* ── Greeting ── */
$h      = (int)date('H');
$greet  = $h < 12 ? 'Selamat pagi' : ($h < 17 ? 'Selamat siang' : 'Selamat malam');
$fName  = explode(' ', $_SESSION['name'])[0];

/* ── Page config ── */
$pageTitle   = 'Dashboard';
$pageSubtitle = ['admin'=>'Super Admin','user'=>'User Bisnis','member'=>'Member'][$role] ?? '';
$activeMenu  = 'dashboard';
$topbarBtn   = $role !== 'member'
    ? ['url'=>'invoice_form.php','icon'=>'ph-plus','label'=>'Invoice Baru']
    : null;

require_once 'includes/header.php';
?>

<style>
/* ── Stats Grid ── */
.dash-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.dstat {
  background:var(--surf); border:1px solid var(--line);
  border-radius:var(--radius); padding:14px 15px;
  position:relative; overflow:hidden;
  transition:border-color .2s, transform .18s; cursor:default;
  animation:fadeUp .35s ease both;
}
.dstat:hover { border-color:var(--gring); transform:translateY(-2px); }
.dstat::after { content:''; position:absolute; top:-18px; right:-18px; width:65px; height:65px; border-radius:50%; opacity:.07; pointer-events:none; }
.dstat.ca::after{background:var(--blue)} .dstat.cb::after{background:var(--gold)}
.dstat.cc::after{background:var(--green)} .dstat.cd::after{background:var(--red)}
.dstat:nth-child(1){animation-delay:.04s} .dstat:nth-child(2){animation-delay:.08s}
.dstat:nth-child(3){animation-delay:.12s} .dstat:nth-child(4){animation-delay:.16s}
.dstat-top  { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:10px; }
.dstat-ico  { width:33px; height:33px; border-radius:8px; display:grid; place-items:center; font-size:.88rem; flex-shrink:0; }
.ico-blue  { background:rgba(96,165,250,.1);  color:var(--blue);  border:1px solid rgba(96,165,250,.18); }
.ico-gold  { background:var(--gdim);          color:var(--gold);  border:1px solid var(--gring); }
.ico-green { background:rgba(34,197,94,.1);   color:var(--green); border:1px solid rgba(34,197,94,.18); }
.ico-red   { background:rgba(239,68,68,.1);   color:var(--red);   border:1px solid rgba(239,68,68,.18); }
.dstat-badge { display:inline-flex; align-items:center; gap:3px; padding:2px 7px; border-radius:99px; font-size:.65rem; font-weight:500; }
.b-up  { background:rgba(34,197,94,.1);    color:var(--green); }
.b-dn  { background:rgba(239,68,68,.1);    color:var(--red); }
.b-neu { background:rgba(148,163,184,.07); color:var(--txt3); }
.dstat-lbl  { font-size:.7rem; color:var(--txt3); margin-bottom:3px; }
.dstat-val  { font-family:'Cormorant Garamond',serif; font-size:1.55rem; font-weight:400; line-height:1; }
.dstat-val.sm { font-size:1.05rem; }
.dstat-note { font-size:.66rem; color:var(--txt3); margin-top:3px; }

/* ── Two Column ── */
.dash-grid  { display:grid; grid-template-columns:1fr 268px; gap:13px; align-items:start; }
.right-col  { display:flex; flex-direction:column; gap:13px; }

/* ── Chart ── */
.chart-wrap  { padding:14px 16px 10px; }
.bars        { display:flex; align-items:flex-end; gap:5px; height:110px; position:relative; }
.bar-col     { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; height:100%; justify-content:flex-end; }
.bar-fill    { width:100%; border-radius:3px 3px 0 0; background:var(--surf3); transition:height .5s ease, background .2s; cursor:pointer; position:relative; min-height:3px; }
.bar-fill:hover { background:var(--gold) !important; }
.bar-fill .tooltip {
  display:none; position:absolute; bottom:calc(100% + 6px); left:50%;
  transform:translateX(-50%); background:var(--surf2); border:1px solid var(--line);
  border-radius:7px; padding:5px 9px; font-size:.7rem; white-space:nowrap;
  color:var(--txt); z-index:10; pointer-events:none;
  box-shadow:0 4px 12px rgba(0,0,0,.4);
}
.bar-fill:hover .tooltip { display:block; }
.bar-lbl { font-size:.62rem; color:var(--txt3); }

/* ── Quick Stats ── */
.qstat       { display:flex; align-items:center; gap:9px; padding:8px 14px; border-bottom:1px solid var(--line2); transition:background .12s; }
.qstat:last-child { border-bottom:none; }
.qstat:hover { background:rgba(255,255,255,.018); }
.qstat-ico   { width:28px; height:28px; border-radius:7px; display:grid; place-items:center; font-size:.8rem; flex-shrink:0; }
.qstat-lbl   { font-size:.72rem; color:var(--txt2); flex:1; }
.qstat-val   { font-size:.82rem; font-weight:600; }
.qstat-arr   { color:var(--txt3); font-size:.77rem; }

/* ── Activity ── */
.act-item    { display:flex; gap:9px; padding:9px 14px; border-bottom:1px solid var(--line2); }
.act-item:last-child { border-bottom:none; }
.act-dot     { width:7px; height:7px; border-radius:50%; margin-top:5px; flex-shrink:0; }
.act-text    { font-size:.75rem; color:var(--txt2); line-height:1.45; }
.act-time    { font-size:.65rem; color:var(--txt3); margin-top:2px; }

/* ── Table tweaks ── */
.td-inv      { font-weight:600; font-size:.79rem; color:var(--txt); text-decoration:none; }
.td-inv:hover{ color:var(--gold); }

/* ── Mobile card list ── */
.inv-cards   { display:none; }
.inv-card-item {
  display:flex; align-items:center; gap:10px;
  padding:10px 14px; border-bottom:1px solid var(--line2);
}
.inv-card-item:last-child { border-bottom:none; }
.inv-card-body { flex:1; min-width:0; }
.icb-row1 { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:3px; }
.icb-num  { font-weight:600; font-size:.79rem; color:var(--txt); }
.icb-sub  { font-size:.7rem; color:var(--txt3); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.icb-row2 { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:3px; }
.icb-amt  { font-weight:600; font-size:.81rem; }
.icb-date { font-size:.67rem; color:var(--txt3); }
.icb-arr  { width:32px; height:32px; border-radius:8px; background:var(--surf3); border:1px solid var(--line); display:grid; place-items:center; color:var(--txt3); text-decoration:none; flex-shrink:0; transition:all .13s; }
.icb-arr:hover { border-color:var(--gring); color:var(--gold); }

/* ── Greeting ── */
.dash-greet { display:flex; align-items:flex-end; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
.dg-title   { font-family:'Cormorant Garamond',serif; font-size:1.45rem; font-weight:300; line-height:1.2; margin-bottom:3px; }
.dg-title em{ font-style:italic; color:var(--goldh); }
.dg-sub     { font-size:.76rem; color:var(--txt3); }
.dg-date    { display:flex; align-items:center; gap:5px; padding:5px 12px; border-radius:99px; border:1px solid var(--line); background:var(--surf2); font-size:.73rem; color:var(--txt2); white-space:nowrap; }
.dg-date i  { color:var(--gold); font-size:.8rem; }

/* ── Responsive ── */
@media(max-width:1080px){ .dash-stats{grid-template-columns:repeat(2,1fr)} .dash-grid{grid-template-columns:1fr} .right-col{flex-direction:row} .right-col .card-box{flex:1} }
@media(max-width:720px){
  .dash-stats{gap:9px}
  .dstat{padding:11px 12px}
  .dstat-val{font-size:1.3rem} .dstat-val.sm{font-size:.92rem}
  .dstat-badge{display:none}
  .dash-grid{grid-template-columns:1fr}
  .right-col{flex-direction:column}
  .dash-greet{margin-bottom:14px} .dg-title{font-size:1.2rem} .dg-date{display:none}
  .table-wrap{display:none}
  .inv-cards{display:block}
}
@media(max-width:480px){ .dash-stats{grid-template-columns:1fr 1fr;gap:8px} }
</style>

<!-- Greeting -->
<div class="dash-greet">
  <div>
    <h1 class="dg-title"><?= $greet ?>, <em><?= htmlspecialchars($fName) ?></em></h1>
    <p class="dg-sub">Ringkasan aktivitas bisnis Anda hari ini</p>
  </div>
  <div class="dg-date">
    <i class="ph ph-calendar-blank"></i>
    <?= formatTanggal(date('Y-m-d'), 'd F Y') ?>
  </div>
</div>

<!-- Stats -->
<div class="dash-stats">

  <?php if ($role === 'admin'): ?>
  <div class="dstat ca">
    <div class="dstat-top">
      <div class="dstat-ico ico-blue"><i class="ph ph-users-three"></i></div>
      <span class="dstat-badge b-up"><i class="ph ph-trend-up"></i> Aktif</span>
    </div>
    <div class="dstat-lbl">Total Pengguna</div>
    <div class="dstat-val"><?= number_format($totalUsers) ?></div>
    <div class="dstat-note">Semua role</div>
  </div>
  <?php endif; ?>

  <div class="dstat cb">
    <div class="dstat-top">
      <div class="dstat-ico ico-gold"><i class="ph ph-receipt"></i></div>
      <span class="dstat-badge b-neu"><i class="ph ph-minus"></i> —</span>
    </div>
    <div class="dstat-lbl"><?= $role==='member' ? 'Total Tagihan Saya' : 'Total Invoice' ?></div>
    <div class="dstat-val"><?= number_format($totalInvoices) ?></div>
    <div class="dstat-note"><?= $role==='admin'?'Semua pengguna':($role==='member'?'Tagihan Anda':'Invoice Anda') ?></div>
  </div>

  <div class="dstat cc">
    <div class="dstat-top">
      <div class="dstat-ico ico-green"><i class="ph ph-money"></i></div>
      <span class="dstat-badge b-up"><i class="ph ph-trend-up"></i> Lunas</span>
    </div>
    <?php if ($role === 'member'): ?>
    <div class="dstat-lbl">Total Sudah Dibayar</div>
    <div class="dstat-val sm"><?= formatRupiah($totalRevenue) ?></div>
    <div class="dstat-note">Tagihan yang lunas</div>
    <?php else: ?>
    <div class="dstat-lbl">Total Pendapatan</div>
    <div class="dstat-val sm"><?= formatRupiah($totalRevenue) ?></div>
    <div class="dstat-note">Invoice terbayar</div>
    <?php endif; ?>
  </div>

  <div class="dstat cd">
    <div class="dstat-top">
      <div class="dstat-ico ico-red"><i class="ph ph-clock"></i></div>
      <?php if ($pending > 0): ?>
      <span class="dstat-badge b-dn"><i class="ph ph-warning"></i> Aksi</span>
      <?php else: ?>
      <span class="dstat-badge b-up"><i class="ph ph-check"></i> Bersih</span>
      <?php endif; ?>
    </div>
    <div class="dstat-lbl"><?= $role==='member' ? 'Tagihan Belum Lunas' : 'Menunggu Bayar' ?></div>
    <div class="dstat-val"><?= number_format($pending) ?></div>
    <div class="dstat-note"><?= $role==='member' ? 'Perlu segera dibayar' : 'Terkirim + jatuh tempo' ?></div>
  </div>

</div><!-- /dash-stats -->

<!-- Main Grid -->
<div class="dash-grid">

  <!-- LEFT: Invoice terbaru -->
  <div class="card-box anim">
    <div class="card-head">
      <div class="card-headicon"><i class="ph ph-receipt"></i></div>
      <span class="card-title">Invoice Terbaru</span>
      <a href="invoices.php" class="card-link">Lihat semua <i class="ph ph-arrow-right"></i></a>
    </div>

    <!-- Desktop Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>No. Invoice</th>
            <th>Klien</th>
            <?php if ($role==='admin'): ?><th>User</th><?php endif; ?>
            <th>Tanggal</th>
            <th>Total</th>
            <th>Status</th>
            <th style="width:64px"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentInv)): ?>
          <tr><td colspan="7">
            <div style="text-align:center;padding:36px;color:var(--txt3)">
              <i class="ph ph-receipt" style="font-size:1.8rem;display:block;margin-bottom:8px;opacity:.3"></i>
              <span style="font-size:.8rem">Belum ada invoice</span>
            </div>
          </td></tr>
          <?php else: foreach ($recentInv as $inv):
            $st = $statusMap[$inv['status']] ?? $statusMap['draft'];
          ?>
          <tr>
            <td>
              <a href="invoice_detail.php?id=<?= $inv['id'] ?>" class="td-inv">
                <?= htmlspecialchars($inv['invoice_number']) ?>
              </a>
            </td>
            <td>
              <div style="font-size:.8rem"><?= htmlspecialchars($inv['client_name']) ?></div>
              <?php if ($inv['client_email']): ?>
              <div style="font-size:.68rem;color:var(--txt3)"><?= htmlspecialchars($inv['client_email']) ?></div>
              <?php endif; ?>
            </td>
            <?php if ($role==='admin'): ?>
            <td style="font-size:.72rem;color:var(--txt3)"><?= htmlspecialchars($inv['uname']??'-') ?></td>
            <?php endif; ?>
            <td style="font-size:.74rem;color:var(--txt2)"><?= formatTanggal($inv['issue_date']) ?></td>
            <td style="font-weight:600"><?= formatRupiah($inv['total']) ?></td>
            <td>
              <span class="status-pill" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
                <span class="status-dot" style="background:<?= $st['dot'] ?>"></span>
                <?= $st['label'] ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:4px;align-items:center">
                <a href="invoice_detail.php?id=<?= $inv['id'] ?>" class="btn btn-ghost btn-sm" title="Lihat Detail"><i class="ph ph-eye"></i></a>
                <?php if ($role==='member' && $inv['status']==='paid'): ?>
                <a href="invoice_detail.php?id=<?= $inv['id'] ?>"
                   style="display:inline-flex;align-items:center;gap:4px;padding:0 9px;height:28px;
                          border-radius:7px;font-size:.71rem;font-weight:600;text-decoration:none;
                          background:rgba(34,197,94,.12);color:var(--green);border:1px solid rgba(34,197,94,.25)">
                  <i class="ph ph-check-circle"></i> Lunas
                </a>
                <?php elseif ($role==='member' && $inv['status']!=='paid'): ?>
                <a href="payment_proof.php?invoice_id=<?= $inv['id'] ?>"
                   class="btn btn-primary btn-sm"
                   style="font-size:.71rem;padding:0 10px;height:28px">
                  <i class="ph ph-upload-simple"></i> Bayar
                </a>
                <?php elseif ($role!=='member'): ?>
                <a href="invoice_form.php?id=<?= $inv['id'] ?>" class="btn btn-ghost btn-sm" title="Edit"><i class="ph ph-pencil-simple"></i></a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card List -->
    <div class="inv-cards">
      <?php if (empty($recentInv)): ?>
      <div style="text-align:center;padding:32px;color:var(--txt3);font-size:.8rem">Belum ada invoice</div>
      <?php else: foreach ($recentInv as $inv):
        $st = $statusMap[$inv['status']] ?? $statusMap['draft'];
      ?>
      <div class="inv-card-item">
        <div class="inv-card-body">
          <div class="icb-row1">
            <span class="icb-num"><?= htmlspecialchars($inv['invoice_number']) ?></span>
            <span class="status-pill" style="background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
              <span class="status-dot" style="background:<?= $st['dot'] ?>"></span><?= $st['label'] ?>
            </span>
          </div>
          <div class="icb-sub"><?= htmlspecialchars($inv['client_name']) ?></div>
          <div class="icb-row2">
            <span class="icb-amt"><?= formatRupiah($inv['total']) ?></span>
            <span class="icb-date"><i class="ph ph-calendar-blank" style="font-size:.68rem"></i> <?= formatTanggal($inv['due_date']) ?></span>
          </div>
        </div>
        <?php if ($role==='member' && $inv['status']!=='paid'): ?>
      <a href="payment_proof.php?invoice_id=<?= $inv['id'] ?>"
         style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:7px;font-size:.72rem;font-weight:600;background:linear-gradient(135deg,var(--gold),var(--goldh));color:#05080e;text-decoration:none">
        <i class="ph ph-upload-simple"></i> Bayar
      </a>
      <?php else: ?>
      <a href="invoice_detail.php?id=<?= $inv['id'] ?>" class="icb-arr"><i class="ph ph-caret-right"></i></a>
      <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Chart 6 bulan -->
    <?php if (!empty($chartRows)): ?>
    <div style="border-top:1px solid var(--line2)">
      <div style="padding:12px 15px 4px;font-size:.75rem;font-weight:500;color:var(--txt2)">
        <i class="ph ph-chart-bar" style="color:var(--gold);margin-right:5px"></i>Pendapatan 6 Bulan Terakhir
      </div>
      <div class="chart-wrap">
        <div class="bars">
          <?php foreach ($chartRows as $cr):
            $barH = $chartMax > 0 ? max(4, ($cr['rev']/$chartMax)*100) : 4;
            $isNow = ($cr['mo']==(int)date('n') && $cr['yr']==(int)date('Y'));
          ?>
          <div class="bar-col">
            <div class="bar-fill" style="height:<?= $barH ?>%;background:<?= $isNow?'var(--gold)':'var(--surf3)' ?>">
              <div class="tooltip">
                <strong><?= $cr['lbl'].' '.$cr['yr'] ?></strong><br>
                <?= $cr['cnt'] ?> invoice<br>
                <?= formatRupiah($cr['rev']) ?>
              </div>
            </div>
            <span class="bar-lbl"><?= $cr['lbl'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT column -->
  <div class="right-col">

    <!-- Status Summary -->
    <div class="card-box anim">
      <div class="card-head">
        <div class="card-headicon" style="background:rgba(96,165,250,.1);color:var(--blue);border-color:rgba(96,165,250,.2)">
          <i class="ph ph-lightning"></i>
        </div>
        <span class="card-title">Ringkasan Status</span>
        <a href="reports.php" class="card-link"><i class="ph ph-arrow-right"></i></a>
      </div>
      <div>
        <?php
        $qstats = [
          ['ico'=>'ph-check-circle','bg'=>'rgba(34,197,94,.1)','color'=>'var(--green)','border'=>'rgba(34,197,94,.18)','label'=>'Lunas',       'val'=>$cntPaid,  'href'=>'invoices.php?status=paid'],
          ['ico'=>'ph-paper-plane-tilt','bg'=>'rgba(245,158,11,.1)','color'=>'var(--amber)','border'=>'rgba(245,158,11,.18)','label'=>'Terkirim',    'val'=>$cntSent,  'href'=>'invoices.php?status=sent'],
          ['ico'=>'ph-warning-circle','bg'=>'rgba(239,68,68,.1)','color'=>'var(--red)','border'=>'rgba(239,68,68,.18)','label'=>'Jatuh Tempo', 'val'=>$cntOver,  'href'=>'invoices.php?status=overdue'],
          ['ico'=>'ph-note-pencil','bg'=>'rgba(148,163,184,.07)','color'=>'var(--txt2)','border'=>'var(--line)','label'=>'Draft',       'val'=>$cntDraft, 'href'=>'invoices.php?status=draft'],
        ];
        foreach ($qstats as $qs): ?>
        <a href="<?= $qs['href'] ?>" class="qstat" style="text-decoration:none">
          <div class="qstat-ico" style="background:<?= $qs['bg'] ?>;color:<?= $qs['color'] ?>;border:1px solid <?= $qs['border'] ?>">
            <i class="ph <?= $qs['ico'] ?>"></i>
          </div>
          <span class="qstat-lbl"><?= $qs['label'] ?></span>
          <span class="qstat-val" style="color:<?= $qs['color'] ?>"><?= $qs['val'] ?></span>
          <i class="ph ph-caret-right qstat-arr"></i>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Aktivitas Terbaru -->
    <div class="card-box anim">
      <div class="card-head">
        <div class="card-headicon" style="background:rgba(99,102,241,.1);color:#a5b4fc;border-color:rgba(99,102,241,.2)">
          <i class="ph ph-activity"></i>
        </div>
        <span class="card-title">Aktivitas Terbaru</span>
        <a href="notifications.php" class="card-link"><i class="ph ph-arrow-right"></i></a>
      </div>
      <?php
      $typeColor = ['success'=>'var(--green)','danger'=>'var(--red)','warning'=>'var(--amber)','info'=>'var(--blue)'];
      if (empty($activities)): ?>
      <div style="padding:20px;text-align:center;color:var(--txt3);font-size:.78rem">
        <i class="ph ph-bell-slash" style="font-size:1.4rem;display:block;margin-bottom:6px;opacity:.3"></i>
        Belum ada aktivitas
      </div>
      <?php else: foreach ($activities as $act):
        $dotC = $typeColor[$act['type']] ?? 'var(--blue)';
      ?>
      <div class="act-item">
        <div class="act-dot" style="background:<?= $dotC ?>"></div>
        <div>
          <?php if ($act['title']): ?>
          <div style="font-size:.76rem;font-weight:500;color:var(--txt);margin-bottom:1px"><?= htmlspecialchars($act['title']) ?></div>
          <?php endif; ?>
          <div class="act-text"><?= htmlspecialchars(mb_strimwidth($act['message'],0,72,'...')) ?></div>
          <div class="act-time"><?= timeAgo($act['created_at']) ?></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Quick Actions (non-member) -->
    <?php if ($role !== 'member'): ?>
    <div class="card-box anim">
      <div class="card-head">
        <div class="card-headicon" style="background:rgba(167,139,250,.1);color:var(--purple,#a78bfa);border-color:rgba(167,139,250,.2)">
          <i class="ph ph-rocket-launch"></i>
        </div>
        <span class="card-title">Aksi Cepat</span>
      </div>
      <div style="padding:12px;display:flex;flex-direction:column;gap:7px">
        <a href="invoice_form.php" class="btn btn-primary" style="justify-content:center;height:36px">
          <i class="ph ph-plus"></i> Buat Invoice Baru
        </a>
        <a href="clients.php" class="btn btn-ghost" style="justify-content:center;height:36px">
          <i class="ph ph-user-plus"></i> Tambah Klien
        </a>
        <a href="reports.php" class="btn btn-ghost" style="justify-content:center;height:36px">
          <i class="ph ph-chart-bar"></i> Lihat Laporan
        </a>
        <?php if ($role==='admin'): ?>
        <a href="users.php" class="btn btn-ghost" style="justify-content:center;height:36px">
          <i class="ph ph-users-three"></i> Kelola Pengguna
        </a>
        <?php endif; ?>
      </div>
    </div>

    <?php else: ?>
    <!-- Panduan Member -->
    <div class="card-box anim">
      <div class="card-head">
        <div class="card-headicon" style="background:rgba(96,165,250,.1);color:var(--blue);border-color:rgba(96,165,250,.2)">
          <i class="ph ph-info"></i>
        </div>
        <span class="card-title">Cara Melunasi Tagihan</span>
      </div>
      <div style="padding:14px;display:flex;flex-direction:column;gap:10px">
        <?php
        $steps = [
          ['num'=>'1','icon'=>'ph-receipt','color'=>'var(--gold)',  'bg'=>'var(--gdim)',             'title'=>'Lihat Tagihan',       'desc'=>'Klik tagihan di tabel untuk melihat detail dan nominal yang harus dibayar.'],
          ['num'=>'2','icon'=>'ph-bank',   'color'=>'var(--blue)',  'bg'=>'rgba(96,165,250,.1)',      'title'=>'Transfer Pembayaran', 'desc'=>'Lakukan transfer ke rekening yang tertera pada invoice.'],
          ['num'=>'3','icon'=>'ph-upload-simple','color'=>'var(--amber)', 'bg'=>'rgba(245,158,11,.1)', 'title'=>'Upload Bukti',        'desc'=>'Klik tombol <strong>Bayar</strong> lalu unggah foto/screenshot bukti transfer.'],
          ['num'=>'4','icon'=>'ph-check-circle','color'=>'var(--green)', 'bg'=>'rgba(34,197,94,.1)',  'title'=>'Tunggu Konfirmasi',   'desc'=>'Admin akan memverifikasi dan status tagihan berubah jadi <strong>Lunas</strong>.'],
        ];
        foreach ($steps as $step): ?>
        <div style="display:flex;align-items:flex-start;gap:10px">
          <div style="width:28px;height:28px;border-radius:8px;background:<?= $step['bg'] ?>;color:<?= $step['color'] ?>;display:grid;place-items:center;font-size:.85rem;flex-shrink:0;border:1px solid <?= $step['bg'] ?>">
            <i class="ph <?= $step['icon'] ?>"></i>
          </div>
          <div>
            <div style="font-size:.8rem;font-weight:600;margin-bottom:2px"><?= $step['title'] ?></div>
            <div style="font-size:.74rem;color:var(--txt3);line-height:1.5"><?= $step['desc'] ?></div>
          </div>
        </div>
        <?php endforeach; ?>

        <?php
        /* Tagihan belum bayar */
        $unpaidQ = $db->prepare("SELECT * FROM invoices WHERE (user_id=? OR client_email=?) AND status IN ('sent','overdue') ORDER BY due_date ASC LIMIT 3");
        $unpaidQ->execute([$uid, $memberEmail]);
        $unpaid = $unpaidQ->fetchAll();
        if (!empty($unpaid)): ?>
        <div style="margin-top:4px;padding-top:12px;border-top:1px solid var(--line2)">
          <div style="font-size:.72rem;font-weight:600;color:var(--red);margin-bottom:8px;display:flex;align-items:center;gap:5px">
            <i class="ph ph-warning-circle"></i> Tagihan Menunggu Pembayaran
          </div>
          <?php foreach ($unpaid as $u2): ?>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--line2);gap:8px">
            <div>
              <div style="font-size:.77rem;font-weight:500"><?= htmlspecialchars($u2['invoice_number']) ?></div>
              <div style="font-size:.69rem;color:var(--txt3)">Jatuh tempo: <?= formatTanggal($u2['due_date']) ?></div>
            </div>
            <a href="payment_proof.php?invoice_id=<?= $u2['id'] ?>"
               class="btn btn-primary btn-sm"
               style="font-size:.72rem;white-space:nowrap">
              <i class="ph ph-upload-simple"></i> Bayar
            </a>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

  </div><!-- /right-col -->
</div><!-- /dash-grid -->

<?php require_once 'includes/footer.php'; ?>