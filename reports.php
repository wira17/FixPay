<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db   = getDB();
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? 0); // 0 = semua bulan

/* ── Helper: where clause ── */
$uidWhere = $role === 'admin' ? '' : "AND user_id = $uid";

/* ── Summary tahunan ── */
$sumQ = $db->prepare("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN status='paid'    THEN 1 ELSE 0 END) AS paid,
    SUM(CASE WHEN status='sent'    THEN 1 ELSE 0 END) AS sent,
    SUM(CASE WHEN status='overdue' THEN 1 ELSE 0 END) AS overdue,
    SUM(CASE WHEN status='draft'   THEN 1 ELSE 0 END) AS draft,
    COALESCE(SUM(CASE WHEN status='paid' THEN total ELSE 0 END),0) AS revenue,
    COALESCE(SUM(total),0) AS gross
    FROM invoices WHERE YEAR(created_at)=? $uidWhere");
$sumQ->execute([$year]);
$summary = $sumQ->fetch();

/* ── Bulanan (12 bulan dalam tahun) ── */
$monthlyQ = $db->prepare("SELECT
    MONTH(created_at) AS m,
    COUNT(*) AS cnt,
    COALESCE(SUM(CASE WHEN status='paid' THEN total ELSE 0 END),0) AS rev
    FROM invoices WHERE YEAR(created_at)=? $uidWhere
    GROUP BY MONTH(created_at) ORDER BY m");
$monthlyQ->execute([$year]);
$monthlyRaw = $monthlyQ->fetchAll();
$monthly = array_fill(1, 12, ['cnt'=>0,'rev'=>0]);
foreach ($monthlyRaw as $r) $monthly[(int)$r['m']] = ['cnt'=>(int)$r['cnt'],'rev'=>(float)$r['rev']];

/* ── Top klien ── */
$topQ = $db->prepare("SELECT client_name,
    COUNT(*) AS inv_count,
    COALESCE(SUM(CASE WHEN status='paid' THEN total ELSE 0 END),0) AS revenue
    FROM invoices WHERE YEAR(created_at)=? $uidWhere
    GROUP BY client_name ORDER BY revenue DESC LIMIT 5");
$topQ->execute([$year]);
$topClients = $topQ->fetchAll();

/* ── Recent paid ── */
$recentQ = $role === 'admin'
    ? $db->prepare("SELECT i.*, u.name AS owner FROM invoices i LEFT JOIN users u ON i.user_id=u.id WHERE i.status='paid' AND YEAR(i.created_at)=? ORDER BY i.paid_at DESC LIMIT 8")
    : $db->prepare("SELECT * FROM invoices WHERE status='paid' AND user_id=? AND YEAR(created_at)=? ORDER BY paid_at DESC LIMIT 8");
$role === 'admin' ? $recentQ->execute([$year]) : $recentQ->execute([$uid,$year]);
$recentPaid = $recentQ->fetchAll();

/* ── Available years ── */
$yQ = $role === 'admin'
    ? $db->query("SELECT DISTINCT YEAR(created_at) y FROM invoices ORDER BY y DESC")
    : $db->prepare("SELECT DISTINCT YEAR(created_at) y FROM invoices WHERE user_id=$uid ORDER BY y DESC");
if ($role !== 'admin') $yQ->execute();
$years = array_column($yQ->fetchAll(), 'y');
if (!in_array($year, $years)) $years[] = $year;
rsort($years);

/* ── Chart data (JSON for JS) ── */
$chartLabels  = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
$chartCounts  = array_map(fn($m) => $m['cnt'], $monthly);
$chartRevenue = array_map(fn($m) => $m['rev'], $monthly);
$maxRev       = max(array_merge([1], $chartRevenue));

$pageTitle   = 'Statistik & Laporan';
$pageSubtitle = "Tahun $year";
$activeMenu  = 'reports';
require_once 'includes/header.php';
?>

<style>
.report-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.stat-mini { background:var(--surf); border:1px solid var(--line); border-radius:var(--radius); padding:14px 16px; }
.sm-label  { font-size:.7rem; color:var(--txt3); margin-bottom:4px; }
.sm-value  { font-family:'Cormorant Garamond',serif; font-size:1.5rem; font-weight:400; line-height:1; }
.sm-sub    { font-size:.68rem; color:var(--txt3); margin-top:4px; }
.chart-bar { background:var(--surf3); border-radius:3px 3px 0 0; transition:height .4s ease, background .2s; cursor:pointer; position:relative; }
.chart-bar:hover { background:var(--gold) !important; }
.chart-bar .tip { display:none; position:absolute; bottom:calc(100% + 5px); left:50%; transform:translateX(-50%); background:var(--surf2); border:1px solid var(--line); border-radius:6px; padding:4px 8px; font-size:.7rem; white-space:nowrap; color:var(--txt); z-index:10; pointer-events:none; }
.chart-bar:hover .tip { display:block; }
.progress-bar { height:5px; border-radius:99px; background:var(--surf3); overflow:hidden; margin-top:6px; }
.progress-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--gold),var(--goldh)); transition:width .6s ease; }
@media(max-width:900px){ .report-grid{grid-template-columns:repeat(2,1fr)} }
@media(max-width:540px){ .report-grid{grid-template-columns:1fr 1fr} }
</style>

<!-- Year Filter -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap">
  <span style="font-size:.8rem;color:var(--txt3)">Tahun:</span>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php foreach ($years as $y): ?>
    <a href="reports.php?year=<?= $y ?>"
       style="padding:4px 14px;border-radius:99px;font-size:.78rem;text-decoration:none;font-weight:<?= $y==$year?'600':'400' ?>;background:<?= $y==$year?'linear-gradient(135deg,var(--gold),var(--goldh))':'var(--surf2)' ?>;color:<?= $y==$year?'#05080e':'var(--txt2)' ?>;border:1px solid <?= $y==$year?'transparent':'var(--line)' ?>;transition:all .15s">
      <?= $y ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($role !== 'admin'): ?>
  <div style="margin-left:auto">
    <a href="reports.php?year=<?= $year ?>&export=csv" class="btn btn-ghost btn-sm">
      <i class="ph ph-download-simple"></i> Export CSV
    </a>
  </div>
  <?php else: ?>
  <div style="margin-left:auto">
    <a href="reports.php?year=<?= $year ?>&export=csv" class="btn btn-ghost btn-sm">
      <i class="ph ph-download-simple"></i> Export CSV
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- Handle CSV Export -->
<?php
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_fixpay_'.$year.'.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['No Invoice','Klien','Tanggal','Jatuh Tempo','Total','Status','Dibayar Pada']);
    $expQ = $role === 'admin'
        ? $db->prepare("SELECT * FROM invoices WHERE YEAR(created_at)=? ORDER BY created_at DESC")
        : $db->prepare("SELECT * FROM invoices WHERE user_id=? AND YEAR(created_at)=? ORDER BY created_at DESC");
    $role === 'admin' ? $expQ->execute([$year]) : $expQ->execute([$uid,$year]);
    foreach ($expQ->fetchAll() as $r) {
        fputcsv($out, [$r['invoice_number'],$r['client_name'],$r['issue_date'],$r['due_date'],number_format($r['total'],0,',','.'),$r['status'],$r['paid_at']??'']);
    }
    fclose($out); exit;
}
?>

<!-- Summary Cards -->
<div class="report-grid">
  <div class="stat-mini" style="border-color:rgba(200,160,74,.2)">
    <div class="sm-label">Total Invoice</div>
    <div class="sm-value"><?= number_format($summary['total']) ?></div>
    <div class="sm-sub">Tahun <?= $year ?></div>
  </div>
  <div class="stat-mini" style="border-color:rgba(34,197,94,.2)">
    <div class="sm-label">Total Pendapatan</div>
    <div class="sm-value" style="font-size:1.1rem;color:var(--green)"><?= formatRupiah($summary['revenue']) ?></div>
    <div class="sm-sub"><?= $summary['paid'] ?> invoice lunas</div>
  </div>
  <div class="stat-mini" style="border-color:rgba(239,68,68,.2)">
    <div class="sm-label">Belum Terbayar</div>
    <div class="sm-value" style="color:var(--red)"><?= number_format($summary['sent'] + $summary['overdue']) ?></div>
    <div class="sm-sub"><?= $summary['overdue'] ?> jatuh tempo</div>
  </div>
  <div class="stat-mini" style="border-color:rgba(96,165,250,.2)">
    <div class="sm-label">Tingkat Konversi</div>
    <div class="sm-value" style="color:var(--blue)">
      <?= $summary['total'] > 0 ? round(($summary['paid']/$summary['total'])*100) : 0 ?>%
    </div>
    <div class="sm-sub">Invoice lunas / total</div>
  </div>
</div>

<!-- Charts Row -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">

  <!-- Bar Chart: Revenue per bulan -->
  <div class="card-box anim">
    <div class="card-head">
      <div class="card-headicon"><i class="ph ph-chart-bar"></i></div>
      <span class="card-title">Pendapatan per Bulan</span>
      <span style="font-size:.72rem;color:var(--txt3)"><?= $year ?></span>
    </div>
    <div style="padding:16px 16px 10px">
      <div style="display:flex;align-items:flex-end;gap:4px;height:120px;position:relative">
        <!-- Y-axis guide lines -->
        <?php for ($i=1;$i<=3;$i++): $yp = ($i/3)*100; ?>
        <div style="position:absolute;left:0;right:0;bottom:<?= $yp ?>%;border-top:1px dashed rgba(255,255,255,.04);pointer-events:none"></div>
        <?php endfor; ?>
        <?php foreach ($monthly as $m => $d):
          $h = $maxRev > 0 ? max(3, ($d['rev']/$maxRev)*100) : 3;
          $isCurrentMonth = ($m == date('n') && $year == date('Y'));
          $color = $isCurrentMonth ? 'var(--gold)' : 'var(--surf3)';
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;justify-content:flex-end">
          <div class="chart-bar" style="width:100%;height:<?= $h ?>%;background:<?= $color ?>">
            <div class="tip"><?= $chartLabels[$m-1] ?><br><?= formatRupiah($d['rev']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:4px;margin-top:6px">
        <?php foreach ($chartLabels as $l): ?>
        <div style="flex:1;text-align:center;font-size:.6rem;color:var(--txt3)"><?= $l ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Donut-like: Status breakdown -->
  <div class="card-box anim">
    <div class="card-head">
      <div class="card-headicon" style="background:rgba(99,102,241,.1);color:#a5b4fc;border-color:rgba(99,102,241,.2)"><i class="ph ph-chart-pie"></i></div>
      <span class="card-title">Status Invoice</span>
      <span style="font-size:.72rem;color:var(--txt3)"><?= $year ?></span>
    </div>
    <div style="padding:14px 16px">
      <?php
      $statuses = [
        ['key'=>'paid',    'label'=>'Lunas',       'count'=>(int)$summary['paid'],    'color'=>'var(--green)'],
        ['key'=>'sent',    'label'=>'Terkirim',    'count'=>(int)$summary['sent'],    'color'=>'var(--amber)'],
        ['key'=>'overdue', 'label'=>'Jatuh Tempo', 'count'=>(int)$summary['overdue'],'color'=>'var(--red)'],
        ['key'=>'draft',   'label'=>'Draft',       'count'=>(int)$summary['draft'],   'color'=>'var(--txt3)'],
      ];
      $total = max(1, (int)$summary['total']);
      foreach ($statuses as $s):
        $pct = round(($s['count']/$total)*100);
      ?>
      <div style="margin-bottom:11px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
          <div style="display:flex;align-items:center;gap:7px">
            <div style="width:8px;height:8px;border-radius:50%;background:<?= $s['color'] ?>;flex-shrink:0"></div>
            <span style="font-size:.78rem;color:var(--txt2)"><?= $s['label'] ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-size:.78rem;font-weight:600"><?= $s['count'] ?></span>
            <span style="font-size:.68rem;color:var(--txt3);width:28px;text-align:right"><?= $pct ?>%</span>
          </div>
        </div>
        <div class="progress-bar">
          <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $s['color'] ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- Bottom Row -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

  <!-- Top Klien -->
  <div class="card-box anim">
    <div class="card-head">
      <div class="card-headicon" style="background:rgba(167,139,250,.1);color:var(--purple);border-color:rgba(167,139,250,.2)"><i class="ph ph-trophy"></i></div>
      <span class="card-title">Top 5 Klien</span>
      <span style="font-size:.72rem;color:var(--txt3)"><?= $year ?></span>
    </div>
    <?php if (empty($topClients)): ?>
    <div class="empty-state" style="padding:24px"><i class="ph ph-users" style="font-size:1.5rem"></i><p style="font-size:.78rem">Belum ada data klien</p></div>
    <?php else: ?>
    <?php $maxRev2 = max(1, max(array_column($topClients, 'revenue'))); ?>
    <div style="padding:4px 0">
      <?php foreach ($topClients as $i => $cl): $pct2 = round(($cl['revenue']/$maxRev2)*100); ?>
      <div style="display:flex;align-items:center;gap:10px;padding:9px 15px;border-bottom:1px solid var(--line2)">
        <div style="width:22px;height:22px;border-radius:6px;background:<?= $i===0?'linear-gradient(135deg,var(--gold),var(--goldh))':'var(--surf3)' ?>;color:<?= $i===0?'#05080e':'var(--txt3)' ?>;display:grid;place-items:center;font-size:.7rem;font-weight:700;flex-shrink:0"><?= $i+1 ?></div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.8rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($cl['client_name']) ?></div>
          <div class="progress-bar" style="margin-top:4px">
            <div class="progress-fill" style="width:<?= $pct2 ?>%"></div>
          </div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div style="font-size:.78rem;font-weight:600;color:var(--goldh)"><?= formatRupiah($cl['revenue']) ?></div>
          <div style="font-size:.66rem;color:var(--txt3)"><?= $cl['inv_count'] ?> inv</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Transaksi Terbayar -->
  <div class="card-box anim">
    <div class="card-head">
      <div class="card-headicon" style="background:rgba(34,197,94,.1);color:var(--green);border-color:rgba(34,197,94,.2)"><i class="ph ph-check-circle"></i></div>
      <span class="card-title">Invoice Terbayar</span>
      <a href="invoices.php?status=paid" class="card-link">Lihat semua <i class="ph ph-arrow-right"></i></a>
    </div>
    <?php if (empty($recentPaid)): ?>
    <div class="empty-state" style="padding:24px"><i class="ph ph-receipt" style="font-size:1.5rem"></i><p style="font-size:.78rem">Belum ada pembayaran</p></div>
    <?php else: ?>
    <?php foreach ($recentPaid as $inv): ?>
    <a href="invoice_detail.php?id=<?= $inv['id'] ?>" style="display:flex;align-items:center;justify-content:space-between;padding:9px 15px;border-bottom:1px solid var(--line2);text-decoration:none;transition:background .12s" onmouseenter="this.style.background='rgba(255,255,255,.02)'" onmouseleave="this.style.background=''">
      <div>
        <div style="font-size:.79rem;font-weight:500;color:var(--txt)"><?= htmlspecialchars($inv['invoice_number']) ?></div>
        <div style="font-size:.7rem;color:var(--txt3)"><?= htmlspecialchars($inv['client_name']) ?></div>
      </div>
      <div style="text-align:right">
        <div style="font-size:.8rem;font-weight:600;color:var(--green)"><?= formatRupiah($inv['total']) ?></div>
        <div style="font-size:.67rem;color:var(--txt3)"><?= $inv['paid_at'] ? formatTanggal($inv['paid_at']) : formatTanggal($inv['updated_at']) ?></div>
      </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<style>
@media(max-width:720px){
  div[style*="grid-template-columns:1fr 1fr"]{grid-template-columns:1fr!important}
}
</style>

<?php require_once 'includes/footer.php'; ?>