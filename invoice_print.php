<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db   = getDB();
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$id   = (int)($_GET['id'] ?? 0);

/* Load invoice */
if ($role === 'admin') {
    $q = $db->prepare("SELECT * FROM invoices WHERE id=?");
    $q->execute([$id]);
} else {
    $q = $db->prepare("SELECT * FROM invoices WHERE id=? AND (user_id=? OR client_email=?)");
    $q->execute([$id, $uid, $_SESSION['email'] ?? '']);
}
$inv = $q->fetch();
if (!$inv) die('Invoice tidak ditemukan.');

$qi = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$qi->execute([$id]); $items = $qi->fetchAll();

/* Owner info */
$owner = $db->prepare("SELECT name,email,company,phone,address FROM users WHERE id=?");
$owner->execute([$inv['user_id']]); $owner = $owner->fetch();

/* Status */
$stMap = [
    'paid'      => ['label'=>'LUNAS',        'color'=>'#22c55e', 'bg'=>'rgba(34,197,94,.15)',  'border'=>'rgba(34,197,94,.3)'],
    'sent'      => ['label'=>'TERKIRIM',     'color'=>'#f59e0b', 'bg'=>'rgba(245,158,11,.15)', 'border'=>'rgba(245,158,11,.3)'],
    'draft'     => ['label'=>'DRAFT',        'color'=>'#94a3b8', 'bg'=>'rgba(148,163,184,.15)','border'=>'rgba(148,163,184,.3)'],
    'overdue'   => ['label'=>'JATUH TEMPO',  'color'=>'#ef4444', 'bg'=>'rgba(239,68,68,.15)',  'border'=>'rgba(239,68,68,.3)'],
    'cancelled' => ['label'=>'DIBATALKAN',   'color'=>'#64748b', 'bg'=>'rgba(100,116,139,.15)','border'=>'rgba(100,116,139,.3)'],
];
$st = $stMap[$inv['status']] ?? $stMap['draft'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoice <?= htmlspecialchars($inv['invoice_number']) ?> — FixPay</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }

/* ── Screen ── */
body {
  font-family:'DM Sans',sans-serif;
  background:#07090f;
  color:#e8e4db;
  font-size:13px;
  line-height:1.55;
  -webkit-print-color-adjust:exact;
  print-color-adjust:exact;
  min-height:100vh;
  display:flex;
  flex-direction:column;
  align-items:center;
  padding:32px 16px;
}

/* ── Toolbar ── */
.toolbar {
  width:100%;
  max-width:800px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:20px;
  gap:10px;
  flex-wrap:wrap;
}
.toolbar-left { font-size:.78rem; color:#8c97aa; }
.toolbar-left strong { color:#e8e4db; }
.toolbar-right { display:flex; gap:8px; }
.tb-btn {
  display:inline-flex; align-items:center; gap:6px;
  padding:8px 16px; border-radius:9px;
  font-family:'DM Sans',sans-serif; font-size:.79rem; font-weight:500;
  cursor:pointer; text-decoration:none; border:none; transition:all .15s;
}
.tb-primary { background:linear-gradient(135deg,#c8a04a,#e2be72); color:#05080e; }
.tb-primary:hover { opacity:.88; transform:translateY(-1px); box-shadow:0 5px 16px rgba(200,160,74,.3); }
.tb-ghost { background:rgba(255,255,255,.05); color:#8c97aa; border:1px solid rgba(255,255,255,.08); }
.tb-ghost:hover { color:#e8e4db; border-color:rgba(255,255,255,.15); }

/* ── Invoice Page ── */
.page {
  width:100%;
  max-width:800px;
  background:#0c1120;
  border:1px solid rgba(255,255,255,.06);
  border-radius:16px;
  overflow:hidden;
  box-shadow:0 24px 64px rgba(0,0,0,.6);
}

/* ── Page Header Band ── */
.page-header {
  background:linear-gradient(135deg, #0f1829 0%, #111e35 100%);
  border-bottom:1px solid rgba(200,160,74,.15);
  padding:32px 40px;
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:20px;
  position:relative;
  overflow:hidden;
}
.page-header::before {
  content:'';
  position:absolute; top:-60px; right:-60px;
  width:220px; height:220px; border-radius:50%;
  background:radial-gradient(circle, rgba(200,160,74,.08) 0%, transparent 70%);
  pointer-events:none;
}

/* Brand */
.brand { display:flex; align-items:center; gap:12px; }
.brand-icon {
  width:44px; height:44px; border-radius:12px;
  background:linear-gradient(135deg,#c8a04a,#e2be72);
  display:grid; place-items:center;
  color:#05080e; font-size:1.1rem; font-weight:700;
  flex-shrink:0;
}
.brand-name {
  font-family:'Cormorant Garamond',serif;
  font-size:1.8rem; font-weight:600;
  background:linear-gradient(to right,#c8a04a,#e2be72);
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
  line-height:1;
}
.brand-tagline { font-size:.7rem; color:#505a6c; margin-top:3px; letter-spacing:.04em; }

/* Invoice meta */
.inv-meta { text-align:right; }
.inv-number {
  font-family:'Cormorant Garamond',serif;
  font-size:1.6rem; font-weight:300;
  color:#e8e4db; letter-spacing:.02em;
}
.inv-status {
  display:inline-flex; align-items:center; gap:5px;
  padding:4px 12px; border-radius:99px;
  font-size:.68rem; font-weight:700; letter-spacing:.08em;
  margin-top:8px;
  background:<?= $st['bg'] ?>;
  color:<?= $st['color'] ?>;
  border:1px solid <?= $st['border'] ?>;
}
.inv-status::before {
  content:''; display:inline-block; width:6px; height:6px;
  border-radius:50%; background:<?= $st['color'] ?>;
}

/* ── Body ── */
.page-body { padding:32px 40px; }

/* Parties grid */
.parties {
  display:grid; grid-template-columns:1fr 1fr 1fr;
  gap:24px; margin-bottom:32px;
  padding-bottom:28px;
  border-bottom:1px solid rgba(255,255,255,.05);
}
.party-label {
  font-size:.6rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase;
  color:#505a6c; margin-bottom:7px;
  display:flex; align-items:center; gap:5px;
}
.party-label::before { content:''; display:block; width:12px; height:1px; background:#505a6c; }
.party-name { font-weight:600; font-size:.88rem; color:#e8e4db; margin-bottom:4px; }
.party-info { font-size:.76rem; color:#8c97aa; line-height:1.65; }

/* Items table */
.items-table { width:100%; border-collapse:collapse; margin-bottom:24px; }
.items-table thead th {
  padding:10px 12px;
  font-size:.6rem; font-weight:600; letter-spacing:.09em; text-transform:uppercase;
  color:#505a6c;
  background:rgba(255,255,255,.02);
  border-top:1px solid rgba(255,255,255,.05);
  border-bottom:1px solid rgba(255,255,255,.05);
  text-align:left;
}
.items-table tbody td {
  padding:11px 12px;
  font-size:.82rem;
  border-bottom:1px solid rgba(255,255,255,.03);
  vertical-align:middle;
  color:#c8d4e8;
}
.items-table tbody tr:last-child td { border-bottom:none; }
.items-table tbody tr:hover td { background:rgba(255,255,255,.015); }
.items-table .desc { color:#e8e4db; font-weight:500; }
.items-table .num  { text-align:right; color:#8c97aa; }
.items-table .sub  { text-align:right; font-weight:600; color:#e8e4db; }

/* Totals */
.totals-wrap { display:flex; justify-content:flex-end; margin-bottom:28px; }
.totals-box {
  width:280px;
  background:rgba(255,255,255,.02);
  border:1px solid rgba(255,255,255,.05);
  border-radius:10px;
  overflow:hidden;
}
.total-row {
  display:flex; justify-content:space-between; align-items:center;
  padding:9px 16px;
  font-size:.79rem;
  border-bottom:1px solid rgba(255,255,255,.04);
}
.total-row:last-child { border-bottom:none; }
.total-label { color:#8c97aa; }
.total-val   { font-weight:500; color:#c8d4e8; }
.total-tax   { color:#f59e0b; }
.total-disc  { color:#22c55e; }
.grand-row {
  display:flex; justify-content:space-between; align-items:center;
  padding:13px 16px;
  background:linear-gradient(135deg, rgba(200,160,74,.08), rgba(226,190,114,.05));
  border-top:1px solid rgba(200,160,74,.2);
}
.grand-label { font-weight:700; font-size:.84rem; letter-spacing:.04em; color:#e8e4db; }
.grand-val {
  font-family:'Cormorant Garamond',serif;
  font-size:1.5rem; font-weight:400; color:#e2be72;
}

/* Notes */
.notes-section { margin-bottom:28px; }
.section-label {
  font-size:.6rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase;
  color:#505a6c; margin-bottom:8px;
}
.notes-box {
  background:rgba(255,255,255,.02);
  border:1px solid rgba(255,255,255,.05);
  border-left:2px solid rgba(200,160,74,.3);
  border-radius:0 8px 8px 0;
  padding:11px 14px;
  font-size:.78rem; color:#8c97aa; line-height:1.65;
}

/* Footer */
.page-footer {
  border-top:1px solid rgba(255,255,255,.05);
  padding:16px 40px;
  display:flex; justify-content:space-between; align-items:center;
  background:rgba(255,255,255,.01);
  font-size:.69rem; color:#505a6c;
  gap:12px; flex-wrap:wrap;
}
.footer-brand { color:#8c97aa; }
.footer-brand span { color:#c8a04a; font-weight:500; }

/* Paid watermark */
<?php if ($inv['status'] === 'paid'): ?>
.paid-stamp {
  position:absolute; bottom:24px; right:40px;
  font-family:'Cormorant Garamond',serif;
  font-size:2.2rem; font-weight:600; letter-spacing:.08em;
  color:rgba(34,197,94,.12);
  border:3px solid rgba(34,197,94,.1);
  border-radius:8px;
  padding:4px 18px;
  transform:rotate(-6deg);
  pointer-events:none;
  text-transform:uppercase;
}
<?php endif; ?>

/* ── PRINT STYLES ── */
@media print {
  body {
    background:#fff !important;
    color:#1a1a2e !important;
    padding:0 !important;
    display:block !important;
  }
  .toolbar { display:none !important; }
  .page {
    border:none !important;
    border-radius:0 !important;
    box-shadow:none !important;
    background:#fff !important;
    max-width:100% !important;
  }
  .page-header {
    background:#f8f7f4 !important;
    border-bottom:2px solid #e8c97a !important;
    padding:20px 28px !important;
  }
  .brand-name {
    -webkit-text-fill-color:#c8a04a !important;
    color:#c8a04a !important;
  }
  .inv-number { color:#1a1a2e !important; }
  .page-body { padding:20px 28px !important; }
  .party-label { color:#9aa3b2 !important; }
  .party-name  { color:#1a1a2e !important; }
  .party-info  { color:#64748b !important; }
  .parties     { border-bottom:1px solid #e8e4db !important; }
  .items-table thead th { background:#f8f7f4 !important; color:#9aa3b2 !important; border-color:#e8e4db !important; }
  .items-table tbody td { color:#334155 !important; border-color:#f0eee8 !important; }
  .items-table .desc    { color:#1a1a2e !important; }
  .items-table .sub     { color:#1a1a2e !important; }
  .totals-box { background:#f8f7f4 !important; border-color:#e8e4db !important; }
  .total-row  { border-color:#f0eee8 !important; }
  .total-label{ color:#64748b !important; }
  .total-val  { color:#334155 !important; }
  .grand-row  { background:#fdf6e3 !important; border-color:#e8c97a !important; }
  .grand-label{ color:#1a1a2e !important; }
  .grand-val  { color:#c8a04a !important; }
  .notes-box  { background:#f8f7f4 !important; color:#64748b !important; border-color:#e8e4db !important; }
  .section-label{ color:#9aa3b2 !important; }
  .page-footer{ background:#f8f7f4 !important; border-color:#e8e4db !important; color:#9aa3b2 !important; }
  .footer-brand span { color:#c8a04a !important; }
  .page-header::before { display:none !important; }
}
</style>
</head>
<body>

<!-- Toolbar (hide on print) -->
<div class="toolbar">
  <div class="toolbar-left">
    <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong>
    &nbsp;·&nbsp; <?= htmlspecialchars($inv['client_name']) ?>
    &nbsp;·&nbsp; <?= formatRupiah($inv['total']) ?>
  </div>
  <div class="toolbar-right">
    <button onclick="window.print()" class="tb-btn tb-primary">
      🖨&nbsp; Cetak / Simpan PDF
    </button>
    <a href="invoice_detail.php?id=<?= $id ?>" class="tb-btn tb-ghost">
      ← Kembali
    </a>
  </div>
</div>

<!-- Invoice Page -->
<div class="page">

  <!-- Header -->
  <div class="page-header">
    <div class="brand">
      <div class="brand-icon">✦</div>
      <div>
        <div class="brand-name">FixPay</div>
        <div class="brand-tagline">Platform Invoice Profesional</div>
      </div>
    </div>
    <div class="inv-meta">
      <div class="inv-number"><?= htmlspecialchars($inv['invoice_number']) ?></div>
      <div><span class="inv-status"><?= $st['label'] ?></span></div>
    </div>
    <?php if ($inv['status'] === 'paid'): ?>
    <div class="paid-stamp">LUNAS</div>
    <?php endif; ?>
  </div>

  <!-- Body -->
  <div class="page-body">

    <!-- Parties -->
    <div class="parties">
      <div>
        <div class="party-label">Dari</div>
        <div class="party-name"><?= htmlspecialchars($owner['name'] ?? 'N/A') ?></div>
        <div class="party-info">
          <?= htmlspecialchars($owner['email'] ?? '') ?>
          <?php if ($owner['company']): ?><br><?= htmlspecialchars($owner['company']) ?><?php endif; ?>
          <?php if ($owner['phone']): ?><br><?= htmlspecialchars($owner['phone']) ?><?php endif; ?>
          <?php if ($owner['address']): ?><br><?= htmlspecialchars(mb_strimwidth($owner['address'],0,60,'...')) ?><?php endif; ?>
        </div>
      </div>
      <div>
        <div class="party-label">Kepada</div>
        <div class="party-name"><?= htmlspecialchars($inv['client_name']) ?></div>
        <div class="party-info">
          <?= htmlspecialchars($inv['client_email'] ?? '') ?>
          <?php if ($inv['client_phone']): ?><br><?= htmlspecialchars($inv['client_phone']) ?><?php endif; ?>
          <?php if ($inv['client_address']): ?><br><?= htmlspecialchars($inv['client_address']) ?><?php endif; ?>
        </div>
      </div>
      <div>
        <div class="party-label">Tanggal</div>
        <div class="party-info">
          <span style="color:#505a6c;font-size:.68rem">Diterbitkan</span><br>
          <span style="color:#c8d4e8;font-weight:500"><?= formatTanggal($inv['issue_date']) ?></span>
          <br><br>
          <span style="color:#505a6c;font-size:.68rem">Jatuh Tempo</span><br>
          <span style="color:<?= strtotime($inv['due_date'])<time()&&$inv['status']!='paid'?'#ef4444':'#c8d4e8' ?>;font-weight:500">
            <?= formatTanggal($inv['due_date']) ?>
          </span>
          <?php if ($inv['paid_at']): ?>
          <br><br>
          <span style="color:#505a6c;font-size:.68rem">Dilunasi</span><br>
          <span style="color:#22c55e;font-weight:500"><?= formatTanggal($inv['paid_at']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Items -->
    <table class="items-table">
      <thead>
        <tr>
          <th style="min-width:200px">Deskripsi Layanan</th>
          <th class="num" style="width:70px">Qty</th>
          <th class="num" style="width:140px">Harga Satuan</th>
          <th class="num" style="width:140px">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $it): ?>
        <tr>
          <td class="desc">
            <?= htmlspecialchars($it['description']) ?>
          </td>
          <td class="num"><?= (float)$it['quantity'] == (int)$it['quantity'] ? (int)$it['quantity'] : $it['quantity'] ?></td>
          <td class="num"><?= formatRupiah($it['unit_price']) ?></td>
          <td class="sub"><?= formatRupiah($it['total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Totals -->
    <div class="totals-wrap">
      <div class="totals-box">
        <div class="total-row">
          <span class="total-label">Subtotal</span>
          <span class="total-val"><?= formatRupiah($inv['subtotal']) ?></span>
        </div>
        <?php if ((float)$inv['tax_percent'] > 0): ?>
        <div class="total-row">
          <span class="total-label">Pajak (<?= $inv['tax_percent'] ?>%)</span>
          <span class="total-val total-tax"><?= formatRupiah($inv['tax_amount']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ((float)$inv['discount'] > 0): ?>
        <div class="total-row">
          <span class="total-label">Diskon</span>
          <span class="total-val total-disc">− <?= formatRupiah($inv['discount']) ?></span>
        </div>
        <?php endif; ?>
        <div class="grand-row">
          <span class="grand-label">TOTAL</span>
          <span class="grand-val"><?= formatRupiah($inv['total']) ?></span>
        </div>
      </div>
    </div>

    <!-- Notes -->
    <?php if ($inv['notes']): ?>
    <div class="notes-section">
      <div class="section-label">Catatan</div>
      <div class="notes-box"><?= nl2br(htmlspecialchars($inv['notes'])) ?></div>
    </div>
    <?php endif; ?>

  </div><!-- /page-body -->

  <!-- Footer -->
  <div class="page-footer">
    <span class="footer-brand">Dibuat dengan <span>FixPay</span> · Platform Invoice Profesional</span>
    <span><?= formatTanggal($inv['created_at']) ?></span>
  </div>

</div><!-- /page -->

</body>
</html>