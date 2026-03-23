<?php
require_once 'config/database.php';
startSession();
requireLogin();

/* Member tidak bisa buat invoice */
if ($_SESSION['role'] === 'member') {
    header('Location: invoices.php?error=noaccess'); exit;
}

$db     = getDB();
$uid    = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'];
$editId = (int)($_GET['id'] ?? 0);
$isEdit = $editId > 0;
$errors = [];

/* ── Load existing invoice untuk edit ── */
$inv = null; $items = [];
if ($isEdit) {
    $q = $role === 'admin'
        ? $db->prepare("SELECT * FROM invoices WHERE id=?")
        : $db->prepare("SELECT * FROM invoices WHERE id=? AND user_id=?");
    $role === 'admin' ? $q->execute([$editId]) : $q->execute([$editId, $uid]);
    $inv = $q->fetch();
    if (!$inv) { header('Location: invoices.php'); exit; }
    $qi = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
    $qi->execute([$editId]);
    $items = $qi->fetchAll();
}

/* ── Load clients ── */
if ($role === 'admin') {
    $cq = $db->query("SELECT c.*, u.name AS owner_name FROM clients c
                      LEFT JOIN users u ON c.user_id = u.id
                      ORDER BY c.name ASC");
    $clients = $cq->fetchAll();
} else {
    $cq = $db->prepare("SELECT * FROM clients WHERE user_id=? ORDER BY name ASC");
    $cq->execute([$uid]);
    $clients = $cq->fetchAll();
}

/* ── Load services + items aktif ── */
$services = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        description TEXT,
        base_price DECIMAL(15,2) DEFAULT 0.00,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS service_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_id INT NOT NULL,
        label VARCHAR(100) NOT NULL,
        price DECIMAL(15,2) DEFAULT 0,
        unit VARCHAR(50) DEFAULT 'paket',
        description TEXT,
        sort_order INT DEFAULT 0,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    ) CHARACTER SET utf8mb4");

    $cols = $db->query("SHOW COLUMNS FROM services LIKE 'base_price'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE services ADD COLUMN base_price DECIMAL(15,2) DEFAULT 0.00 AFTER description");
    }

    $sq = $db->query("SELECT * FROM services WHERE is_active=1 ORDER BY name ASC");
    $services = $sq->fetchAll();

    foreach ($services as &$svc) {
        $si = $db->prepare("SELECT * FROM service_items WHERE service_id=? ORDER BY sort_order ASC, id ASC");
        $si->execute([$svc['id']]);
        $svc['items'] = $si->fetchAll();
    }
    unset($svc);
} catch (PDOException $e) {
    $services = [];
}

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'client_name'    => sanitize($_POST['client_name']    ?? ''),
        'client_email'   => sanitize($_POST['client_email']   ?? ''),
        'client_phone'   => sanitize($_POST['client_phone']   ?? ''),
        'client_address' => sanitize($_POST['client_address'] ?? ''),
        'issue_date'     => $_POST['issue_date']  ?? date('Y-m-d'),
        'due_date'       => $_POST['due_date']     ?? '',
        'tax_percent'    => (float)($_POST['tax_percent'] ?? 0),
        'discount'       => (float)($_POST['discount']    ?? 0),
        'notes'          => sanitize($_POST['notes']      ?? ''),
        'status'         => $_POST['status']       ?? 'draft',
    ];

    if (empty($data['client_name'])) $errors[] = 'Nama klien wajib diisi.';
    if (empty($data['due_date']))    $errors[] = 'Jatuh tempo wajib diisi.';

    $descArr  = $_POST['item_desc']  ?? [];
    $qtyArr   = $_POST['item_qty']   ?? [];
    $priceArr = $_POST['item_price'] ?? [];
    if (empty(array_filter($descArr))) $errors[] = 'Minimal 1 item invoice.';

    if (empty($errors)) {
        $subtotal = 0;
        foreach ($descArr as $i => $desc) {
            if (empty(trim($desc))) continue;
            $subtotal += (float)($qtyArr[$i] ?? 0) * (float)($priceArr[$i] ?? 0);
        }
        $taxAmt = $subtotal * ($data['tax_percent'] / 100);
        $total  = max(0, $subtotal + $taxAmt - $data['discount']);

        if ($isEdit) {
            $db->prepare("UPDATE invoices SET client_name=?,client_email=?,client_phone=?,client_address=?,
                          issue_date=?,due_date=?,subtotal=?,tax_percent=?,tax_amount=?,discount=?,total=?,
                          status=?,notes=?,updated_at=NOW() WHERE id=?")
               ->execute([$data['client_name'],$data['client_email'],$data['client_phone'],$data['client_address'],
                          $data['issue_date'],$data['due_date'],$subtotal,$data['tax_percent'],$taxAmt,
                          $data['discount'],$total,$data['status'],$data['notes'],$editId]);
            $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$editId]);
            $newId = $editId;
        } else {
            $invNum = generateInvoiceNumber();
            $db->prepare("INSERT INTO invoices (invoice_number,user_id,client_name,client_email,client_phone,
                          client_address,issue_date,due_date,subtotal,tax_percent,tax_amount,discount,total,status,notes)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$invNum,$uid,$data['client_name'],$data['client_email'],$data['client_phone'],
                          $data['client_address'],$data['issue_date'],$data['due_date'],$subtotal,
                          $data['tax_percent'],$taxAmt,$data['discount'],$total,$data['status'],$data['notes']]);
            $newId = $db->lastInsertId();
            addNotification($uid,'Invoice Dibuat',"Invoice $invNum untuk {$data['client_name']} berhasil dibuat.",'info');
        }

        $insItem = $db->prepare("INSERT INTO invoice_items (invoice_id,description,quantity,unit_price,total) VALUES (?,?,?,?,?)");
        foreach ($descArr as $i => $desc) {
            if (empty(trim($desc))) continue;
            $qty = (float)($qtyArr[$i] ?? 1);
            $price = (float)($priceArr[$i] ?? 0);
            $insItem->execute([$newId, trim($desc), $qty, $price, $qty * $price]);
        }
        header("Location: invoice_detail.php?id=$newId&saved=1"); exit;
    }
}

/* ── JSON untuk JS ── */
$clientsJson = json_encode(array_values(array_map(fn($c) => [
    'id'      => (int)$c['id'],
    'name'    => $c['name'],
    'email'   => $c['email']   ?? '',
    'phone'   => $c['phone']   ?? '',
    'company' => $c['company'] ?? '',
    'address' => $c['address'] ?? '',
], $clients)), JSON_UNESCAPED_UNICODE);

$servicesData = [];
foreach ($services as $svc) {
    $basePrice = (float)($svc['base_price'] ?? 0);
    $svcEntry  = ['id'=>(int)$svc['id'],'name'=>$svc['name'],'base_price'=>$basePrice,'items'=>[]];
    foreach ($svc['items'] as $si) {
        $svcEntry['items'][] = ['label'=>$si['label'],'price'=>(float)$si['price'],'unit'=>$si['unit']];
    }
    if (empty($svcEntry['items']) && $basePrice > 0) {
        $svcEntry['items'][] = ['label'=>$svc['name'],'price'=>$basePrice,'unit'=>'paket'];
    }
    if (!empty($svcEntry['items'])) $servicesData[] = $svcEntry;
}
$servicesJson = json_encode($servicesData, JSON_UNESCAPED_UNICODE);

$pageTitle   = $isEdit ? 'Edit Invoice' : 'Buat Invoice';
$pageSubtitle = $isEdit ? ($inv['invoice_number'] ?? '') : 'Invoice Baru';
$activeMenu  = 'invoices';
require_once 'includes/header.php';
?>

<style>
.inv-grid { display:grid; grid-template-columns:1fr 300px; gap:16px; align-items:start; }
.cp-wrap { position:relative; }
.cp-input-row { position:relative; }
.cp-icon { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--txt3); font-size:.9rem; pointer-events:none; }
.cp-input { padding-left:32px !important; }
.cp-list { display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:9999; background:var(--surf2); border:1px solid var(--gring); border-radius:10px; box-shadow:0 8px 28px rgba(0,0,0,.5); max-height:260px; overflow-y:auto; }
.cp-list.open { display:block; }
.cp-row { display:flex; align-items:center; gap:10px; padding:9px 12px; cursor:pointer; transition:background .12s; }
.cp-row:hover { background:rgba(255,255,255,.05); }
.cp-av { width:30px; height:30px; border-radius:8px; background:linear-gradient(135deg,var(--gold),var(--goldh)); display:grid; place-items:center; font-size:.8rem; font-weight:700; color:#05080e; flex-shrink:0; }
.cp-name { font-size:.82rem; font-weight:500; line-height:1.2; }
.cp-sub { font-size:.7rem; color:var(--txt3); }
.cp-empty { padding:14px; text-align:center; font-size:.78rem; color:var(--txt3); }
.or-divider { display:flex; align-items:center; gap:10px; margin:12px 0; }
.or-divider::before,.or-divider::after { content:''; flex:1; height:1px; background:var(--line); }
.or-divider span { font-size:.7rem; color:var(--txt3); white-space:nowrap; }
.item-tbl { width:100%; border-collapse:collapse; }
.item-tbl th { padding:7px 8px; font-size:.63rem; font-weight:600; letter-spacing:.07em; text-transform:uppercase; color:var(--txt3); background:rgba(255,255,255,.015); border-bottom:1px solid var(--line2); text-align:left; white-space:nowrap; }
.item-tbl td { padding:5px 5px; border-bottom:1px solid var(--line2); vertical-align:middle; }
.item-tbl tr:last-child td { border-bottom:none; }
.ii { width:100%; padding:6px 8px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.09); border-radius:7px; color:var(--txt); font-family:'DM Sans',sans-serif; font-size:.8rem; outline:none; transition:border .13s,background .13s; }
.ii:focus { border-color:var(--gold); background:rgba(200,160,74,.05); }
.ii::placeholder { color:var(--txt3); }
.ii-total { font-weight:600; font-size:.8rem; color:var(--txt); padding:0 6px; white-space:nowrap; }
.del-row { width:26px; height:26px; border:none; background:none; color:var(--txt3); cursor:pointer; border-radius:5px; display:grid; place-items:center; font-size:.85rem; transition:all .13s; }
.del-row:hover { background:rgba(239,68,68,.1); color:var(--red); }
.sum-row { display:flex; justify-content:space-between; align-items:center; padding:7px 0; font-size:.8rem; }
.sum-row + .sum-row { border-top:1px solid var(--line2); }
.sum-total { font-family:'Cormorant Garamond',serif; font-size:1.45rem; font-weight:400; color:var(--goldh); }
.ref-row { display:flex; justify-content:space-between; align-items:center; padding:5px 14px; cursor:pointer; transition:background .12s; gap:8px; border-radius:4px; }
.ref-row:hover { background:rgba(255,255,255,.03); }
.ref-row:hover .ref-label { color:var(--gold); }
.ref-label { font-size:.75rem; color:var(--txt2); transition:color .12s; }
.ref-price { font-size:.75rem; font-weight:600; color:var(--goldh); white-space:nowrap; }
@media(max-width:900px){ .inv-grid { grid-template-columns:1fr; } }

/* ── Confirm Dialog ── */
#confirmOverlay {
  display:none; position:fixed; inset:0; z-index:99999;
  background:rgba(0,0,0,.7); backdrop-filter:blur(6px);
  align-items:center; justify-content:center; padding:16px;
}
#confirmOverlay.open { display:flex; }
#confirmBox {
  background:var(--surf2); border:1px solid var(--line);
  border-radius:14px; padding:28px 28px 22px;
  width:100%; max-width:380px;
  box-shadow:0 24px 64px rgba(0,0,0,.7);
  animation:popIn .22s cubic-bezier(.34,1.56,.64,1) both;
  text-align:center;
}
@keyframes popIn {
  from { opacity:0; transform:scale(.88); }
  to   { opacity:1; transform:scale(1); }
}
@keyframes svcFadeIn {
  from { opacity:0; transform:translate(-50%,-48%) scale(.95); }
  to   { opacity:1; transform:translate(-50%,-50%) scale(1); }
}
#svcPanel.open-anim { animation:svcFadeIn .22s cubic-bezier(.34,1.56,.64,1) both; }
</style>

<?php if (!empty($errors)): ?>
<div class="alert alert-error" style="margin-bottom:14px">
  <i class="ph ph-warning-circle"></i>
  <div><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
</div>
<?php endif; ?>

<form method="POST" action="invoice_form.php<?= $isEdit ? "?id=$editId" : '' ?>" id="invForm" autocomplete="off">
<div class="inv-grid">

  <!-- KIRI -->
  <div style="display:flex;flex-direction:column;gap:14px">

    <div class="card-box anim" style="overflow:visible">
      <div class="card-head">
        <div class="card-headicon"><i class="ph ph-user"></i></div>
        <span class="card-title">Informasi Klien</span>
        <?php if (!empty($clients)): ?>
        <span style="font-size:.71rem;color:var(--txt3)"><?= count($clients) ?> klien tersedia</span>
        <?php endif; ?>
      </div>
      <div style="padding:15px">
        <?php if (!empty($clients)): ?>
        <div class="form-group">
          <label class="form-label"><i class="ph ph-address-book" style="margin-right:4px;color:var(--gold)"></i>Pilih dari Daftar Klien</label>
          <div class="cp-wrap" id="cpWrap">
            <div class="cp-input-row">
              <i class="ph ph-magnifying-glass cp-icon"></i>
              <input type="text" id="cpSearch" class="form-input cp-input"
                     placeholder="Ketik nama / email klien..."
                     autocomplete="off"
                     oninput="cpFilter(this.value)"
                     onfocus="cpOpen()">
            </div>
            <div class="cp-list" id="cpList"></div>
          </div>
        </div>
        <div class="or-divider"><span>atau isi manual</span></div>
        <?php endif; ?>
        <div class="form-row">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Nama Klien <span>*</span></label>
            <input type="text" name="client_name" id="cName" class="form-input"
                   value="<?= htmlspecialchars($_POST['client_name'] ?? $inv['client_name'] ?? '') ?>"
                   placeholder="Nama / Perusahaan" required>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Email Klien</label>
            <input type="email" name="client_email" id="cEmail" class="form-input"
                   value="<?= htmlspecialchars($_POST['client_email'] ?? $inv['client_email'] ?? '') ?>"
                   placeholder="email@klien.com">
          </div>
        </div>
        <div class="form-row" style="margin-top:10px">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Telepon</label>
            <input type="text" name="client_phone" id="cPhone" class="form-input"
                   value="<?= htmlspecialchars($_POST['client_phone'] ?? $inv['client_phone'] ?? '') ?>"
                   placeholder="08xx-xxxx-xxxx">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Alamat</label>
            <input type="text" name="client_address" id="cAddress" class="form-input"
                   value="<?= htmlspecialchars($_POST['client_address'] ?? $inv['client_address'] ?? '') ?>"
                   placeholder="Jl. ...">
          </div>
        </div>
      </div>
    </div>

    <div class="card-box">
      <div class="card-head">
        <div class="card-headicon"><i class="ph ph-calendar-blank"></i></div>
        <span class="card-title">Detail Invoice</span>
      </div>
      <div style="padding:15px">
        <div class="form-row">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Tanggal Invoice <span>*</span></label>
            <input type="date" name="issue_date" class="form-input"
                   value="<?= $_POST['issue_date'] ?? $inv['issue_date'] ?? date('Y-m-d') ?>">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Jatuh Tempo <span>*</span></label>
            <input type="date" name="due_date" class="form-input" required
                   value="<?= $_POST['due_date'] ?? $inv['due_date'] ?? date('Y-m-d', strtotime('+30 days')) ?>">
          </div>
        </div>
        <div class="form-group" style="margin-top:10px;margin-bottom:0">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach(['draft'=>'Draft','sent'=>'Terkirim','paid'=>'Lunas','overdue'=>'Jatuh Tempo','cancelled'=>'Batal'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= (($_POST['status']??$inv['status']??'draft')===$v)?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="card-box" style="overflow:visible">
      <div class="card-head">
        <div class="card-headicon"><i class="ph ph-list-bullets"></i></div>
        <span class="card-title">Item / Layanan</span>
        <div style="display:flex;gap:6px;margin-left:auto">
          <?php if (!empty($servicesData)): ?>
          <button type="button" onclick="toggleSvcPanel(event)" class="btn btn-ghost btn-sm" id="svcPickerBtn">
            <i class="ph ph-wrench"></i> Pilih Layanan
          </button>
          <?php endif; ?>
          <button type="button" onclick="addRow()" class="btn btn-ghost btn-sm">
            <i class="ph ph-plus"></i> Tambah Baris
          </button>
        </div>
      </div>
      <div style="overflow-x:auto">
        <table class="item-tbl">
          <thead>
            <tr>
              <th style="min-width:200px">Deskripsi</th>
              <th style="width:75px">Qty</th>
              <th style="width:135px">Harga Satuan</th>
              <th style="width:120px">Subtotal</th>
              <th style="width:32px"></th>
            </tr>
          </thead>
          <tbody id="itemsBody">
            <?php
            $rows = !empty($items) ? $items : [['description'=>'','quantity'=>1,'unit_price'=>0,'total'=>0]];
            foreach ($rows as $it): ?>
            <tr class="item-row">
              <td><input type="text" name="item_desc[]" class="ii" value="<?= htmlspecialchars($it['description']) ?>" placeholder="Deskripsi / nama layanan..."></td>
              <td><input type="number" name="item_qty[]" class="ii" value="<?= (float)$it['quantity'] ?>" min="0.01" step="0.01" oninput="recalcRow(this)" style="text-align:center"></td>
              <td><input type="number" name="item_price[]" class="ii" value="<?= (float)$it['unit_price'] ?>" min="0" step="500" oninput="recalcRow(this)"></td>
              <td><span class="ii-total"><?= formatRupiah($it['total']) ?></span></td>
              <td><button type="button" class="del-row" onclick="removeRow(this)"><i class="ph ph-trash"></i></button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:10px 12px;border-top:1px solid var(--line2)">
        <button type="button" onclick="addRow()" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center">
          <i class="ph ph-plus"></i> Tambah Baris Manual
        </button>
      </div>
    </div>

    <div class="card-box">
      <div class="card-head">
        <div class="card-headicon"><i class="ph ph-note-pencil"></i></div>
        <span class="card-title">Catatan</span>
      </div>
      <div style="padding:15px">
        <textarea name="notes" class="form-textarea" placeholder="Catatan untuk klien (opsional)..."><?= htmlspecialchars($_POST['notes'] ?? $inv['notes'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <!-- KANAN -->
  <div style="display:flex;flex-direction:column;gap:13px;position:sticky;top:68px">
    <div class="card-box">
      <div class="card-head">
        <div class="card-headicon"><i class="ph ph-calculator"></i></div>
        <span class="card-title">Ringkasan</span>
      </div>
      <div style="padding:14px">
        <div class="sum-row">
          <span style="color:var(--txt2)">Subtotal</span>
          <span id="sumSubtotal" style="font-weight:500">Rp 0</span>
        </div>
        <div class="sum-row" style="align-items:center;gap:8px;margin-top:4px">
          <label style="color:var(--txt2);font-size:.79rem;flex:1">Pajak (%)</label>
          <input type="number" name="tax_percent" id="taxInput" class="ii"
                 value="<?= (float)($_POST['tax_percent'] ?? $inv['tax_percent'] ?? 0) ?>"
                 min="0" max="100" step="0.5" oninput="recalcTotal()"
                 style="width:72px;text-align:right;padding:4px 7px;border-radius:6px">
        </div>
        <div class="sum-row">
          <span style="color:var(--txt2)">Pajak</span>
          <span id="sumTax" style="color:var(--amber)">Rp 0</span>
        </div>
        <div class="sum-row" style="align-items:center;gap:8px;margin-top:4px">
          <label style="color:var(--txt2);font-size:.79rem;flex:1">Diskon (Rp)</label>
          <input type="number" name="discount" id="discInput" class="ii"
                 value="<?= (float)($_POST['discount'] ?? $inv['discount'] ?? 0) ?>"
                 min="0" step="1000" oninput="recalcTotal()"
                 style="width:112px;text-align:right;padding:4px 7px;border-radius:6px">
        </div>
        <div style="border-top:1px solid var(--line);margin:10px 0 6px;padding-top:10px">
          <div class="sum-row">
            <span style="font-weight:700;font-size:.88rem">TOTAL</span>
            <span id="sumTotal" class="sum-total">Rp 0</span>
          </div>
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;height:42px;justify-content:center;font-size:.86rem">
      <i class="ph ph-floppy-disk"></i>
      <?= $isEdit ? 'Simpan Perubahan' : 'Buat Invoice' ?>
    </button>
    <a href="invoices.php" class="btn btn-ghost" style="width:100%;height:36px;justify-content:center">
      <i class="ph ph-x"></i> Batal
    </a>
    <?php if (!empty($servicesData)): ?>
    <div class="card-box">
      <div class="card-head">
        <div class="card-headicon" style="background:rgba(167,139,250,.1);color:#a78bfa;border-color:rgba(167,139,250,.2)"><i class="ph ph-book-open"></i></div>
        <span class="card-title">Referensi Tarif</span>
        <a href="services.php" class="card-link" style="font-size:.68rem">Kelola <i class="ph ph-arrow-right"></i></a>
      </div>
      <div style="padding:6px 0;max-height:260px;overflow-y:auto">
        <?php foreach ($servicesData as $svc): ?>
        <div style="padding:6px 14px 2px">
          <span style="font-size:.67rem;font-weight:700;color:var(--gold);letter-spacing:.06em;text-transform:uppercase"><?= htmlspecialchars($svc['name']) ?></span>
        </div>
        <?php foreach ($svc['items'] as $si): ?>
        <div class="ref-row"
             onclick="pickService(<?= htmlspecialchars(json_encode($svc['name'].' — '.$si['label'])) ?>, <?= (float)$si['price'] ?>)"
             title="Klik untuk tambah ke invoice">
          <span class="ref-label"><?= htmlspecialchars($si['label']) ?></span>
          <span class="ref-price"><?= formatRupiah($si['price']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>
</form>

<!-- Service panel fixed -->
<?php if (!empty($servicesData)): ?>
<!-- Overlay + Modal Layanan (tengah layar) -->
<div id="svcOverlay" onclick="closeSvcPanel()"
     style="display:none;position:fixed;inset:0;z-index:8888;
            background:rgba(0,0,0,.6);backdrop-filter:blur(4px);
            align-items:center;justify-content:center;padding:16px"></div>
<div id="svcPanel"
     style="display:none;position:fixed;z-index:9999;
            top:50%;left:50%;transform:translate(-50%,-50%);
            width:100%;max-width:420px;max-height:80vh;overflow-y:auto;
            background:var(--surf2);border:1px solid var(--gring);border-radius:14px;
            box-shadow:0 24px 64px rgba(0,0,0,.75)">
  <div style="padding:11px 14px;border-bottom:1px solid var(--line2);display:flex;align-items:center;
              justify-content:space-between;position:sticky;top:0;background:var(--surf2);z-index:1">
    <span style="font-size:.8rem;font-weight:600;color:var(--gold)">
      <i class="ph ph-wrench" style="margin-right:5px"></i>Pilih Tarif Layanan
    </span>
    <button type="button" onclick="closeSvcPanel()"
            style="background:none;border:none;color:var(--txt3);cursor:pointer;font-size:1rem;padding:0">
      <i class="ph ph-x"></i>
    </button>
  </div>
  <?php foreach ($servicesData as $svc): ?>
  <div style="padding:8px 14px 3px;font-size:.65rem;font-weight:700;letter-spacing:.09em;
              text-transform:uppercase;color:var(--txt3)">
    <?= htmlspecialchars($svc['name']) ?>
  </div>
  <?php foreach ($svc['items'] as $si): ?>
  <div onclick="pickService(<?= htmlspecialchars(json_encode($svc['name'].' — '.$si['label'])) ?>, <?= (float)$si['price'] ?>)"
       style="display:flex;align-items:center;justify-content:space-between;padding:9px 14px;
              cursor:pointer;gap:10px;border-bottom:1px solid rgba(255,255,255,.03);transition:background .12s"
       onmouseenter="this.style.background='rgba(200,160,74,.09)'"
       onmouseleave="this.style.background=''">
    <div>
      <div style="font-size:.82rem;font-weight:500;color:var(--txt)"><?= htmlspecialchars($si['label']) ?></div>
      <div style="font-size:.68rem;color:var(--txt3)"><?= htmlspecialchars($si['unit']) ?></div>
    </div>
    <div style="font-size:.83rem;font-weight:700;color:var(--goldh);white-space:nowrap">
      <?= formatRupiah($si['price']) ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
const ALL_CLIENTS  = <?= $clientsJson ?>;
const ALL_SERVICES = <?= $servicesJson ?>;

function fmtRp(n){ return 'Rp\u00a0'+Math.max(0,Math.round(n)).toLocaleString('id-ID'); }

function recalcRow(el){
  const row=el.closest('tr');
  const qty=parseFloat(row.querySelector('[name="item_qty[]"]').value)||0;
  const price=parseFloat(row.querySelector('[name="item_price[]"]').value)||0;
  row.querySelector('.ii-total').textContent=fmtRp(qty*price);
  recalcTotal();
}

function recalcTotal(){
  let sub=0;
  document.querySelectorAll('.item-row').forEach(function(row){
    const qty=parseFloat(row.querySelector('[name="item_qty[]"]').value)||0;
    const price=parseFloat(row.querySelector('[name="item_price[]"]').value)||0;
    sub+=qty*price;
  });
  const taxPct=(parseFloat(document.getElementById('taxInput').value)||0)/100;
  const disc=parseFloat(document.getElementById('discInput').value)||0;
  const taxAmt=sub*taxPct;
  document.getElementById('sumSubtotal').textContent=fmtRp(sub);
  document.getElementById('sumTax').textContent=fmtRp(taxAmt);
  document.getElementById('sumTotal').textContent=fmtRp(Math.max(0,sub+taxAmt-disc));
}

function addRow(desc,price){
  const tbody=document.getElementById('itemsBody');
  const tr=document.createElement('tr');
  tr.className='item-row';
  const safeDesc=(desc||'').replace(/'/g,"\\'").replace(/"/g,'&quot;');
  const safePrice=parseFloat(price)||0;
  tr.innerHTML=
    '<td><input type="text" name="item_desc[]" class="ii" value="'+safeDesc+'" placeholder="Deskripsi / nama layanan..."></td>'+
    '<td><input type="number" name="item_qty[]" class="ii" value="1" min="0.01" step="0.01" oninput="recalcRow(this)" style="text-align:center"></td>'+
    '<td><input type="number" name="item_price[]" class="ii" value="'+safePrice+'" min="0" step="500" oninput="recalcRow(this)"></td>'+
    '<td><span class="ii-total">'+fmtRp(safePrice)+'</span></td>'+
    '<td><button type="button" class="del-row" onclick="removeRow(this)"><i class="ph ph-trash"></i></button></td>';
  tbody.appendChild(tr);
  recalcTotal();
  tr.querySelector('[name="item_desc[]"]').focus();
}

function removeRow(btn){
  if(document.querySelectorAll('.item-row').length<=1){alert('Minimal 1 item.');return;}
  btn.closest('tr').remove();
  recalcTotal();
}

function pickService(desc,price){
  closeSvcPanel();
  const rows=document.querySelectorAll('.item-row');
  const last=rows[rows.length-1];
  if(last&&last.querySelector('[name="item_desc[]"]').value.trim()===''){
    last.querySelector('[name="item_desc[]"]').value=desc;
    last.querySelector('[name="item_price[]"]').value=price;
    last.querySelector('.ii-total').textContent=fmtRp(price);
    recalcTotal();
  } else { addRow(desc,price); }
}

function renderCpList(filtered){
  const list=document.getElementById('cpList');
  if(!filtered.length){
    list.innerHTML='<div class="cp-empty">Klien tidak ditemukan</div>';
  } else {
    list.innerHTML=filtered.map(function(c){
      const sub=c.company||c.email||'';
      return '<div class="cp-row" onclick=\'cpSelect('+JSON.stringify(c)+')\'>'
        +'<div class="cp-av">'+c.name.charAt(0).toUpperCase()+'</div>'
        +'<div><div class="cp-name">'+c.name+'</div>'+(sub?'<div class="cp-sub">'+sub+'</div>':'')+'</div>'
        +'</div>';
    }).join('');
  }
}
function cpOpen(){
  renderCpList(ALL_CLIENTS);
  document.getElementById('cpList').classList.add('open');
}
function cpFilter(val){
  const q=val.trim().toLowerCase();
  const f=q?ALL_CLIENTS.filter(function(c){
    return c.name.toLowerCase().indexOf(q)>=0||c.email.toLowerCase().indexOf(q)>=0||(c.company||'').toLowerCase().indexOf(q)>=0;
  }):ALL_CLIENTS;
  renderCpList(f);
  document.getElementById('cpList').classList.add('open');
}
function cpSelect(c){
  document.getElementById('cName').value=c.name||'';
  document.getElementById('cEmail').value=c.email||'';
  document.getElementById('cPhone').value=c.phone||'';
  document.getElementById('cAddress').value=c.address||'';
  document.getElementById('cpSearch').value=c.name;
  document.getElementById('cpList').classList.remove('open');
}
document.addEventListener('click',function(e){
  const w=document.getElementById('cpWrap');
  if(w&&!w.contains(e.target)) document.getElementById('cpList').classList.remove('open');
});

function toggleSvcPanel(e){
  if(e) e.stopPropagation();
  const panel=document.getElementById('svcPanel');
  const overlay=document.getElementById('svcOverlay');
  if(!panel) return;
  if(panel.style.display==='block'){closeSvcPanel();return;}
  panel.style.display='block';
  overlay.style.display='block';
  document.body.style.overflow='hidden';
  // Trigger animation
  panel.classList.remove('open-anim');
  void panel.offsetWidth; // reflow
  panel.classList.add('open-anim');
}
function closeSvcPanel(){
  const p=document.getElementById('svcPanel');
  const o=document.getElementById('svcOverlay');
  if(p) p.style.display='none';
  if(o) o.style.display='none';
  document.body.style.overflow='';
}

recalcTotal();
</script>

<!-- ══ CONFIRM DIALOG ══ -->
<div id="confirmOverlay">
  <div id="confirmBox">
    <div style="width:52px;height:52px;border-radius:50%;background:var(--gdim);border:1px solid var(--gring);display:grid;place-items:center;margin:0 auto 16px">
      <i class="ph ph-floppy-disk" style="font-size:1.4rem;color:var(--gold)"></i>
    </div>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:400;margin-bottom:8px">
      Simpan Invoice?
    </h3>
    <p id="confirmMsg" style="font-size:.82rem;color:var(--txt2);line-height:1.6;margin-bottom:22px">
      Pastikan semua data sudah benar sebelum menyimpan.
    </p>
    <div style="display:flex;gap:10px;justify-content:center">
      <button onclick="closeConfirm()" class="btn btn-ghost" style="flex:1;height:40px;justify-content:center">
        <i class="ph ph-x"></i> Batal
      </button>
      <button onclick="doSubmit()" class="btn btn-primary" id="confirmSubmitBtn" style="flex:1;height:40px;justify-content:center">
        <i class="ph ph-floppy-disk"></i> Simpan
      </button>
    </div>
  </div>
</div>

<script>
/* ── Confirm before submit ── */
var _formReady = false;

document.getElementById('invForm').addEventListener('submit', function(e){
  if(_formReady) return; // sudah konfirmasi, biarkan submit
  e.preventDefault();

  // Validasi cepat sebelum tampil dialog
  var clientName = document.getElementById('cName').value.trim();
  var dueDate    = document.querySelector('[name="due_date"]').value;
  var hasItem    = false;
  document.querySelectorAll('[name="item_desc[]"]').forEach(function(el){
    if(el.value.trim()) hasItem = true;
  });

  if(!clientName){ alert('Nama klien wajib diisi!'); document.getElementById('cName').focus(); return; }
  if(!dueDate)   { alert('Jatuh tempo wajib diisi!'); return; }
  if(!hasItem)   { alert('Minimal 1 item invoice!'); return; }

  // Tampilkan konfirmasi
  var total = document.getElementById('sumTotal').textContent;
  var client = clientName;
  document.getElementById('confirmMsg').innerHTML =
    'Invoice untuk <strong style="color:var(--txt)">'+ client +'</strong> sebesar <strong style="color:var(--goldh)">'+ total +'</strong> akan disimpan. Lanjutkan?';

  document.getElementById('confirmOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
});

function closeConfirm(){
  document.getElementById('confirmOverlay').classList.remove('open');
  document.body.style.overflow = '';
  _formReady = false;
}

function doSubmit(){
  _formReady = true;
  document.getElementById('confirmOverlay').classList.remove('open');
  document.body.style.overflow = '';
  document.getElementById('invForm').submit();
}

// Tutup dengan ESC
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){
    closeSvcPanel();
    closeConfirm();
  }
});
</script>

<?php require_once 'includes/footer.php'; ?>