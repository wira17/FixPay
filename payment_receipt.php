<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db          = getDB();
$uid         = (int)$_SESSION['user_id'];
$role        = $_SESSION['role'];
$memberEmail = $_SESSION['email'] ?? '';
$invId       = (int)($_GET['invoice_id'] ?? 0);

/* Load invoice — member hanya bisa akses invoice miliknya */
if ($role === 'admin') {
    $q = $db->prepare("SELECT * FROM invoices WHERE id=?");
    $q->execute([$invId]);
} else {
    $q = $db->prepare("SELECT * FROM invoices WHERE id=? AND (user_id=? OR client_email=?)");
    $q->execute([$invId, $uid, $memberEmail]);
}
$inv = $q->fetch();
if (!$inv) die('Invoice tidak ditemukan atau Anda tidak memiliki akses.');
if ($inv['status'] !== 'paid') {
    header("Location: invoice_detail.php?id=$invId");
    exit;
}

/* Load items */
$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$items->execute([$invId]); $items = $items->fetchAll();

/* Load bukti transfer */
$proofQ = $db->prepare("SELECT pp.*, u.name AS uploader FROM payment_proofs pp
                         LEFT JOIN users u ON pp.user_id=u.id
                         WHERE pp.invoice_id=? AND pp.status='verified'
                         ORDER BY pp.verified_at DESC LIMIT 1");
$proofQ->execute([$invId]);
$proof = $proofQ->fetch();

/* Load owner (penerbit invoice) */
$ownerQ = $db->prepare("SELECT name,email,company,phone,address FROM users WHERE id=?");
$ownerQ->execute([$inv['user_id']]); $owner = $ownerQ->fetch();

/* Nomor kwitansi */
$receiptNum = 'RCP-' . strtoupper(substr(md5($inv['id'] . $inv['paid_at']), 0, 8));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bukti Pembayaran — <?= htmlspecialchars($inv['invoice_number']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }

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
  width:100%; max-width:680px;
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:20px; gap:10px; flex-wrap:wrap;
}
.toolbar-info { font-size:.78rem; color:#8c97aa; }
.toolbar-info strong { color:#e8e4db; }
.toolbar-btns { display:flex; gap:8px; }
.tb-btn {
  display:inline-flex; align-items:center; gap:6px;
  padding:8px 16px; border-radius:9px;
  font-family:'DM Sans',sans-serif; font-size:.79rem; font-weight:500;
  cursor:pointer; text-decoration:none; border:none; transition:all .15s;
}
.tb-gold  { background:linear-gradient(135deg,#c8a04a,#e2be72); color:#05080e; }
.tb-gold:hover { opacity:.88; transform:translateY(-1px); box-shadow:0 5px 16px rgba(200,160,74,.3); }
.tb-ghost { background:rgba(255,255,255,.05); color:#8c97aa; border:1px solid rgba(255,255,255,.08); }
.tb-ghost:hover { color:#e8e4db; }

/* ── Receipt Card ── */
.receipt {
  width:100%; max-width:680px;
  background:#0c1120;
  border:1px solid rgba(255,255,255,.06);
  border-radius:16px;
  overflow:hidden;
  box-shadow:0 24px 64px rgba(0,0,0,.6);
}

/* ── Receipt Header ── */
.receipt-header {
  background:linear-gradient(135deg, #0f1829, #0d2b1a);
  border-bottom:1px solid rgba(34,197,94,.2);
  padding:28px 36px;
  display:flex; justify-content:space-between; align-items:flex-start; gap:16px;
  position:relative; overflow:hidden;
}
.receipt-header::after {
  content:'';
  position:absolute; bottom:-40px; right:-40px;
  width:160px; height:160px; border-radius:50%;
  background:radial-gradient(circle, rgba(34,197,94,.08) 0%, transparent 70%);
  pointer-events:none;
}

.rcp-brand { display:flex; align-items:center; gap:10px; }
.rcp-icon {
  width:40px; height:40px; border-radius:11px;
  background:linear-gradient(135deg,#c8a04a,#e2be72);
  display:grid; place-items:center; color:#05080e;
  font-size:1rem; font-weight:700; flex-shrink:0;
}
.rcp-name {
  font-family:'Cormorant Garamond',serif;
  font-size:1.5rem; font-weight:600;
  background:linear-gradient(to right,#c8a04a,#e2be72);
  -webkit-background-clip:text; -webkit-text-fill-color:transparent;
}
.rcp-sub { font-size:.68rem; color:#505a6c; margin-top:2px; }

.rcp-meta { text-align:right; }
.rcp-title {
  font-family:'Cormorant Garamond',serif;
  font-size:1.1rem; font-weight:300; color:#e8e4db;
  letter-spacing:.05em; text-transform:uppercase;
}
.rcp-num { font-size:.75rem; color:#8c97aa; margin-top:4px; }
.rcp-lunas {
  display:inline-flex; align-items:center; gap:5px;
  padding:4px 12px; border-radius:99px; margin-top:8px;
  font-size:.68rem; font-weight:700; letter-spacing:.08em;
  background:rgba(34,197,94,.15); color:#4ade80;
  border:1px solid rgba(34,197,94,.3);
}

/* ── Body ── */
.receipt-body { padding:28px 36px; }

/* Amount hero */
.amount-hero {
  text-align:center;
  padding:24px 20px;
  margin-bottom:24px;
  background:linear-gradient(135deg, rgba(200,160,74,.06), rgba(34,197,94,.04));
  border:1px solid rgba(200,160,74,.12);
  border-radius:12px;
  position:relative;
}
.amount-label { font-size:.7rem; color:#505a6c; letter-spacing:.1em; text-transform:uppercase; margin-bottom:8px; }
.amount-val {
  font-family:'Cormorant Garamond',serif;
  font-size:2.8rem; font-weight:300; color:#e2be72;
  letter-spacing:.02em;
}
.amount-words { font-size:.72rem; color:#8c97aa; margin-top:6px; font-style:italic; }
.paid-badge {
  position:absolute; top:14px; right:14px;
  display:flex; align-items:center; gap:4px;
  font-size:.65rem; font-weight:700; letter-spacing:.06em;
  color:#4ade80; background:rgba(34,197,94,.1);
  border:1px solid rgba(34,197,94,.25);
  padding:3px 9px; border-radius:99px;
}

/* Info grid */
.info-grid {
  display:grid; grid-template-columns:1fr 1fr;
  gap:16px; margin-bottom:22px;
}
.info-box {
  background:rgba(255,255,255,.02);
  border:1px solid rgba(255,255,255,.05);
  border-radius:9px; padding:12px 14px;
}
.info-box-label {
  font-size:.6rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase;
  color:#505a6c; margin-bottom:6px;
  display:flex; align-items:center; gap:5px;
}
.info-box-label::before { content:''; display:block; width:10px; height:1px; background:#505a6c; }
.info-val  { font-size:.83rem; font-weight:500; color:#e8e4db; margin-bottom:2px; }
.info-sub  { font-size:.73rem; color:#8c97aa; line-height:1.5; }

/* Items */
.items-section { margin-bottom:22px; }
.items-label {
  font-size:.6rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase;
  color:#505a6c; margin-bottom:10px;
  display:flex; align-items:center; gap:8px;
}
.items-label::after { content:''; flex:1; height:1px; background:rgba(255,255,255,.05); }

.item-row {
  display:flex; justify-content:space-between; align-items:center;
  padding:8px 12px; border-radius:7px; gap:10px;
  transition:background .12s;
}
.item-row:hover { background:rgba(255,255,255,.02); }
.item-row + .item-row { border-top:1px solid rgba(255,255,255,.03); }
.item-desc { font-size:.8rem; color:#c8d4e8; flex:1; }
.item-qty  { font-size:.73rem; color:#505a6c; white-space:nowrap; }
.item-amt  { font-size:.82rem; font-weight:600; color:#e8e4db; white-space:nowrap; }

/* Summary */
.summary-box {
  background:rgba(255,255,255,.02);
  border:1px solid rgba(255,255,255,.05);
  border-radius:9px; overflow:hidden;
  margin-bottom:22px;
}
.sum-row {
  display:flex; justify-content:space-between;
  padding:9px 14px; font-size:.79rem;
  border-bottom:1px solid rgba(255,255,255,.04);
}
.sum-row:last-child { border-bottom:none; }
.sum-lbl { color:#8c97aa; }
.sum-val { font-weight:500; color:#c8d4e8; }
.sum-tax  { color:#f59e0b; }
.sum-disc { color:#4ade80; }
.grand-row {
  display:flex; justify-content:space-between; align-items:center;
  padding:12px 14px;
  background:linear-gradient(135deg, rgba(200,160,74,.08), rgba(34,197,94,.05));
  border-top:1px solid rgba(200,160,74,.2);
}
.grand-lbl { font-weight:700; font-size:.84rem; color:#e8e4db; }
.grand-val {
  font-family:'Cormorant Garamond',serif;
  font-size:1.6rem; font-weight:400; color:#4ade80;
}

/* Payment proof info */
.proof-section {
  background:rgba(34,197,94,.04);
  border:1px solid rgba(34,197,94,.12);
  border-radius:9px; padding:13px 15px;
  margin-bottom:22px;
  display:flex; align-items:center; gap:12px;
}
.proof-icon {
  width:36px; height:36px; border-radius:9px;
  background:rgba(34,197,94,.1); color:#4ade80;
  border:1px solid rgba(34,197,94,.2);
  display:grid; place-items:center; font-size:.95rem; flex-shrink:0;
}
.proof-detail { flex:1; }
.proof-title { font-size:.8rem; font-weight:600; color:#4ade80; margin-bottom:3px; }
.proof-meta  { font-size:.73rem; color:#8c97aa; line-height:1.5; }

/* Signature area */
.signature {
  display:grid; grid-template-columns:1fr 1fr;
  gap:16px; margin-bottom:8px;
}
.sig-box {
  text-align:center;
  padding:16px 12px 12px;
  border:1px solid rgba(255,255,255,.05);
  border-radius:9px; background:rgba(255,255,255,.01);
}
.sig-label { font-size:.65rem; color:#505a6c; text-transform:uppercase; letter-spacing:.08em; margin-bottom:40px; }
.sig-line  { border-top:1px dashed rgba(255,255,255,.1); margin-bottom:6px; }
.sig-name  { font-size:.75rem; color:#8c97aa; }

/* ── Footer ── */
.receipt-footer {
  border-top:1px solid rgba(255,255,255,.05);
  padding:14px 36px;
  display:flex; justify-content:space-between; align-items:center;
  background:rgba(255,255,255,.01);
  font-size:.68rem; color:#505a6c;
  gap:10px; flex-wrap:wrap;
}

/* ── Watermark ── */
.watermark {
  position:fixed; top:50%; left:50%;
  transform:translate(-50%,-50%) rotate(-25deg);
  font-family:'Cormorant Garamond',serif;
  font-size:100px; font-weight:600;
  color:rgba(34,197,94,.04);
  pointer-events:none; white-space:nowrap; z-index:0;
  letter-spacing:.1em;
}

/* ── PRINT ── */
@media print {
  body { background:#fff !important; color:#1a1a2e !important; padding:0 !important; display:block !important; }
  .toolbar, .watermark { display:none !important; }
  .receipt { border:none !important; border-radius:0 !important; box-shadow:none !important; background:#fff !important; max-width:100% !important; }
  .receipt-header { background:#f0fdf4 !important; border-bottom:2px solid #86efac !important; padding:20px 28px !important; }
  .rcp-name { -webkit-text-fill-color:#c8a04a !important; }
  .rcp-lunas { background:#dcfce7 !important; color:#16a34a !important; border-color:#86efac !important; }
  .receipt-body { padding:20px 28px !important; }
  .amount-hero { background:#f0fdf4 !important; border-color:#86efac !important; }
  .amount-val  { color:#16a34a !important; }
  .amount-words, .paid-badge { color:#16a34a !important; }
  .paid-badge  { background:#dcfce7 !important; border-color:#86efac !important; }
  .info-box    { background:#f8fafc !important; border-color:#e2e8f0 !important; }
  .info-box-label, .info-box-label::before { color:#9aa3b2 !important; background:#9aa3b2 !important; }
  .info-val    { color:#1a1a2e !important; }
  .info-sub    { color:#64748b !important; }
  .item-desc   { color:#334155 !important; }
  .item-amt    { color:#1a1a2e !important; }
  .summary-box { background:#f8fafc !important; border-color:#e2e8f0 !important; }
  .sum-row     { border-color:#f0f0f0 !important; }
  .sum-lbl     { color:#64748b !important; }
  .sum-val     { color:#334155 !important; }
  .grand-row   { background:#f0fdf4 !important; border-color:#86efac !important; }
  .grand-lbl   { color:#1a1a2e !important; }
  .grand-val   { color:#16a34a !important; }
  .proof-section { background:#f0fdf4 !important; border-color:#86efac !important; }
  .proof-title { color:#16a34a !important; }
  .proof-meta  { color:#64748b !important; }
  .proof-icon  { background:#dcfce7 !important; color:#16a34a !important; border-color:#86efac !important; }
  .sig-box     { background:#f8fafc !important; border-color:#e2e8f0 !important; }
  .sig-label   { color:#9aa3b2 !important; }
  .sig-name    { color:#64748b !important; }
  .receipt-footer { background:#f8fafc !important; border-color:#e2e8f0 !important; color:#9aa3b2 !important; }
  .items-label { color:#9aa3b2 !important; }
  .items-label::after { background:#e2e8f0 !important; }
}
</style>
</head>
<body>

<div class="watermark">LUNAS</div>

<!-- Toolbar -->
<div class="toolbar">
  <div class="toolbar-info">
    <strong>Bukti Pembayaran</strong> &nbsp;·&nbsp;
    <?= htmlspecialchars($inv['invoice_number']) ?> &nbsp;·&nbsp;
    <?= formatRupiah($inv['total']) ?>
  </div>
  <div class="toolbar-btns">
    <button onclick="window.print()" class="tb-btn tb-gold">
      🖨&nbsp; Cetak / Simpan PDF
    </button>
    <a href="invoice_detail.php?id=<?= $invId ?>" class="tb-btn tb-ghost">
      ← Kembali
    </a>
  </div>
</div>

<!-- Receipt -->
<div class="receipt">

  <!-- Header -->
  <div class="receipt-header">
    <div class="rcp-brand">
      <div class="rcp-icon">✦</div>
      <div>
        <div class="rcp-name">FixPay</div>
        <div class="rcp-sub">Bukti Pembayaran Resmi</div>
      </div>
    </div>
    <div class="rcp-meta">
      <div class="rcp-title">Kwitansi Pembayaran</div>
      <div class="rcp-num"><?= $receiptNum ?></div>
      <div><span class="rcp-lunas">✓ LUNAS</span></div>
    </div>
  </div>

  <!-- Body -->
  <div class="receipt-body">

    <!-- Amount Hero -->
    <div class="amount-hero">
      <div class="paid-badge">✓ VERIFIED</div>
      <div class="amount-label">Total Pembayaran</div>
      <div class="amount-val"><?= formatRupiah($inv['total']) ?></div>
      <div class="amount-words">
        Ref. Invoice: <?= htmlspecialchars($inv['invoice_number']) ?>
        &nbsp;·&nbsp;
        Dilunasi <?= date('d M Y', strtotime($inv['paid_at'])) ?>
      </div>
    </div>

    <!-- Info Grid -->
    <div class="info-grid">
      <!-- Penerbit -->
      <div class="info-box">
        <div class="info-box-label">Diterima Oleh</div>
        <div class="info-val"><?= htmlspecialchars($owner['name'] ?? 'N/A') ?></div>
        <div class="info-sub">
          <?= htmlspecialchars($owner['email'] ?? '') ?>
          <?php if ($owner['company']): ?><br><?= htmlspecialchars($owner['company']) ?><?php endif; ?>
          <?php if ($owner['phone']): ?><br><?= htmlspecialchars($owner['phone']) ?><?php endif; ?>
        </div>
      </div>
      <!-- Pembayar -->
      <div class="info-box">
        <div class="info-box-label">Dibayar Oleh</div>
        <div class="info-val"><?= htmlspecialchars($inv['client_name']) ?></div>
        <div class="info-sub">
          <?= htmlspecialchars($inv['client_email'] ?? '') ?>
          <?php if ($inv['client_phone']): ?><br><?= htmlspecialchars($inv['client_phone']) ?><?php endif; ?>
          <?php if ($inv['client_address']): ?><br><?= htmlspecialchars($inv['client_address']) ?><?php endif; ?>
        </div>
      </div>
      <!-- Tanggal -->
      <div class="info-box">
        <div class="info-box-label">Tanggal Invoice</div>
        <div class="info-val"><?= formatTanggal($inv['issue_date']) ?></div>
        <div class="info-sub">Diterbitkan</div>
      </div>
      <!-- Lunas -->
      <div class="info-box">
        <div class="info-box-label">Tanggal Lunas</div>
        <div class="info-val" style="color:#4ade80"><?= formatTanggal($inv['paid_at']) ?></div>
        <div class="info-sub">
          <?= date('H:i', strtotime($inv['paid_at'])) ?> WIB
          <?php if ($proof): ?>
          &nbsp;·&nbsp; <?= ucfirst(htmlspecialchars($proof['method'])) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Detail Layanan -->
    <div class="items-section">
      <div class="items-label">Detail Layanan</div>
      <?php foreach ($items as $item): ?>
      <div class="item-row">
        <span class="item-desc"><?= htmlspecialchars($item['description']) ?></span>
        <span class="item-qty">× <?= (float)$item['quantity'] == (int)$item['quantity'] ? (int)$item['quantity'] : $item['quantity'] ?></span>
        <span class="item-amt"><?= formatRupiah($item['total']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Summary -->
    <div class="summary-box">
      <div class="sum-row">
        <span class="sum-lbl">Subtotal</span>
        <span class="sum-val"><?= formatRupiah($inv['subtotal']) ?></span>
      </div>
      <?php if ((float)$inv['tax_percent'] > 0): ?>
      <div class="sum-row">
        <span class="sum-lbl">Pajak (<?= $inv['tax_percent'] ?>%)</span>
        <span class="sum-val sum-tax"><?= formatRupiah($inv['tax_amount']) ?></span>
      </div>
      <?php endif; ?>
      <?php if ((float)$inv['discount'] > 0): ?>
      <div class="sum-row">
        <span class="sum-lbl">Diskon</span>
        <span class="sum-val sum-disc">− <?= formatRupiah($inv['discount']) ?></span>
      </div>
      <?php endif; ?>
      <div class="grand-row">
        <span class="grand-lbl">TOTAL DIBAYAR</span>
        <span class="grand-val"><?= formatRupiah($inv['total']) ?></span>
      </div>
    </div>

    <!-- Info bukti transfer jika ada -->
    <?php if ($proof): ?>
    <div class="proof-section">
      <div class="proof-icon">✓</div>
      <div class="proof-detail">
        <div class="proof-title">Pembayaran Diverifikasi Admin</div>
        <div class="proof-meta">
          Metode: <strong><?= ucfirst(htmlspecialchars($proof['method'])) ?></strong>
          <?php if ($proof['reference']): ?>
          &nbsp;·&nbsp; Ref: <strong><?= htmlspecialchars($proof['reference']) ?></strong>
          <?php endif; ?>
          &nbsp;·&nbsp; Diverifikasi <?= date('d M Y H:i', strtotime($proof['verified_at'])) ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Tanda Tangan -->
    <div class="signature">
      <div class="sig-box">
        <div class="sig-label">Pembayar</div>
        <div class="sig-line"></div>
        <div class="sig-name"><?= htmlspecialchars($inv['client_name']) ?></div>
      </div>
      <div class="sig-box">
        <div class="sig-label">Penerima</div>
        <div class="sig-line"></div>
        <div class="sig-name"><?= htmlspecialchars($owner['name'] ?? 'Admin') ?></div>
      </div>
    </div>

  </div><!-- /body -->

  <!-- Footer -->
  <div class="receipt-footer">
    <span>Dicetak via <strong style="color:#c8a04a">FixPay</strong> · Dokumen ini sah sebagai bukti pembayaran</span>
    <span>Dicetak <?= date('d M Y H:i') ?></span>
  </div>

</div><!-- /receipt -->

</body>
</html>