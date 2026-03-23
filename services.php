<?php
require_once 'config/database.php';
startSession();
requireLogin();
if ($_SESSION['role'] === 'member') { header('Location: dashboard.php'); exit; }

$db   = getDB();
$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

/* ── Auto-create tabel jika belum ada ── */
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
    $chk = $db->query("SHOW COLUMNS FROM services LIKE 'base_price'")->fetchAll();
    if (empty($chk)) {
        $db->exec("ALTER TABLE services ADD COLUMN base_price DECIMAL(15,2) DEFAULT 0.00 AFTER description");
    }
} catch (PDOException $e) {}

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? '';

    if (in_array($action, ['add_service','edit_service'])) {
        $sid        = (int)($_POST['service_id']    ?? 0);
        $name       = sanitize($_POST['name']        ?? '');
        $desc       = sanitize($_POST['description'] ?? '');
        $base_price = (float)($_POST['base_price']   ?? 0);
        $actv       = isset($_POST['is_active']) ? 1 : 0;
        if (!empty($name)) {
            if ($action === 'add_service') {
                $db->prepare("INSERT INTO services (user_id,name,description,base_price,is_active) VALUES (?,?,?,?,?)")
                   ->execute([$uid,$name,$desc,$base_price,$actv]);
            } else {
                $db->prepare("UPDATE services SET name=?,description=?,base_price=?,is_active=? WHERE id=?")
                   ->execute([$name,$desc,$base_price,$actv,$sid]);
            }
        }
        header("Location: services.php?saved=1"); exit;
    }

    if ($action === 'delete_service') {
        $db->prepare("DELETE FROM services WHERE id=?")->execute([(int)($_POST['service_id']??0)]);
        header("Location: services.php?deleted=1"); exit;
    }

    if (in_array($action, ['add_item','edit_item'])) {
        $iid   = (int)($_POST['item_id']            ?? 0);
        $sid   = (int)($_POST['service_id']          ?? 0);
        $label = sanitize($_POST['label']            ?? '');
        $price = (float)($_POST['price']             ?? 0);
        $unit  = sanitize($_POST['unit']             ?? 'paket');
        $idesc = sanitize($_POST['item_description'] ?? '');
        $sort  = (int)($_POST['sort_order']          ?? 0);
        if (!empty($label) && $sid) {
            if ($action === 'add_item') {
                $db->prepare("INSERT INTO service_items (service_id,label,price,unit,description,sort_order) VALUES (?,?,?,?,?,?)")
                   ->execute([$sid,$label,$price,$unit,$idesc,$sort]);
            } else {
                $db->prepare("UPDATE service_items SET label=?,price=?,unit=?,description=?,sort_order=? WHERE id=?")
                   ->execute([$label,$price,$unit,$idesc,$sort,$iid]);
            }
        }
        header("Location: services.php?saved=1"); exit;
    }

    if ($action === 'delete_item') {
        $db->prepare("DELETE FROM service_items WHERE id=?")->execute([(int)($_POST['item_id']??0)]);
        header("Location: services.php?saved=1"); exit;
    }
}

/* ── Load services + items ── */
$services = $db->query("SELECT * FROM services ORDER BY name ASC")->fetchAll();
foreach ($services as &$svc) {
    $q = $db->prepare("SELECT * FROM service_items WHERE service_id=? ORDER BY sort_order ASC, id ASC");
    $q->execute([$svc['id']]);
    $svc['items'] = $q->fetchAll();
}
unset($svc);

$pageTitle   = 'Master Layanan';
$pageSubtitle = 'Pengaturan Tarif & Layanan';
$activeMenu  = 'services';
require_once 'includes/header.php';
?>

<style>
/* ─── Tab aktif ─── */
.tab-btns { display:flex; gap:2px; background:var(--surf2); border-radius:9px; padding:3px; margin-bottom:16px; }
.tab-btn  {
  flex:1; padding:6px 0; text-align:center; border:none; background:none;
  color:var(--txt3); font-size:.8rem; font-family:'DM Sans',sans-serif;
  border-radius:7px; cursor:pointer; transition:all .15s; font-weight:400;
}
.tab-btn.active { background:var(--surf); color:var(--txt); font-weight:500; box-shadow:0 1px 4px rgba(0,0,0,.3); }

/* ─── Tabel utama ─── */
.svc-group-row td {
  background:rgba(200,160,74,.05) !important;
  border-top:2px solid var(--gring) !important;
  border-bottom:1px solid var(--gring) !important;
}
.svc-group-name {
  display:flex; align-items:center; gap:8px;
  font-weight:600; font-size:.84rem; color:var(--txt);
}
.svc-group-price {
  font-family:'Cormorant Garamond',serif;
  font-size:1.05rem; color:var(--goldh); white-space:nowrap;
}
.item-row td { padding:9px 14px !important; }
.item-row:last-of-type td { border-bottom:2px solid var(--line) !important; }
.item-label-cell { padding-left:36px !important; }
.item-dot {
  display:inline-block; width:6px; height:6px; border-radius:50%;
  background:var(--txt3); margin-right:8px; vertical-align:middle; flex-shrink:0;
}
.price-cell { font-weight:700; color:var(--goldh); white-space:nowrap; }
.diff-badge {
  display:inline-flex; align-items:center; gap:2px;
  padding:1px 7px; border-radius:99px; font-size:.68rem; font-weight:500;
}
.diff-up   { background:rgba(34,197,94,.1);   color:var(--green); }
.diff-down { background:rgba(239,68,68,.1);    color:var(--red); }
.diff-same { background:rgba(148,163,184,.07); color:var(--txt3); }
</style>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success"><i class="ph ph-check-circle"></i> Perubahan berhasil disimpan.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="alert alert-success"><i class="ph ph-check-circle"></i> Berhasil dihapus.</div>
<?php endif; ?>

<!-- Toolbar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
  <div style="font-size:.8rem;color:var(--txt3)">
    <i class="ph ph-wrench" style="color:var(--gold);margin-right:4px"></i>
    <?= count($services) ?> layanan &nbsp;·&nbsp;
    <?= array_sum(array_column($services,'items') ? array_map(fn($s)=>count($s['items']),$services) : [0]) ?> total tarif
  </div>
  <button onclick="openAddService()" class="btn btn-primary">
    <i class="ph ph-plus"></i> Tambah Layanan
  </button>
</div>

<!-- TABEL MASTER LAYANAN -->
<?php if (empty($services)): ?>
<div class="card-box">
  <div class="empty-state">
    <i class="ph ph-wrench"></i>
    <h3>Belum ada layanan</h3>
    <p>Tambahkan master layanan, kemudian isi detail tarif per kategori.</p>
    <button onclick="openAddService()" class="btn btn-primary" style="margin-top:14px">
      <i class="ph ph-plus"></i> Tambah Layanan Pertama
    </button>
  </div>
</div>

<?php else: ?>
<div class="card-box anim">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th style="width:36px">#</th>
          <th>Nama Layanan / Detail Tarif</th>
          <th>Harga</th>
          <th class="hide-mobile">Satuan</th>
          <th class="hide-mobile">Selisih Harga Dasar</th>
          <th>Status</th>
          <th style="width:90px;text-align:center">Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($services as $idx => $svc):
          $basePrice = (float)($svc['base_price'] ?? 0);
          $itemCount = count($svc['items']);
        ?>

        <!-- ── Baris Layanan Utama ── -->
        <tr class="svc-group-row">
          <td style="font-size:.73rem;color:var(--txt3);font-weight:600"><?= $idx+1 ?></td>
          <td>
            <div class="svc-group-name">
              <div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,var(--gold),var(--goldh));display:grid;place-items:center;color:#05080e;font-size:.78rem;flex-shrink:0">
                <i class="ph ph-wrench"></i>
              </div>
              <div>
                <div><?= htmlspecialchars($svc['name']) ?></div>
                <?php if ($svc['description']): ?>
                <div style="font-size:.7rem;color:var(--txt3);font-weight:400;margin-top:1px"><?= htmlspecialchars(mb_strimwidth($svc['description'],0,60,'...')) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td>
            <div class="svc-group-price"><?= formatRupiah($basePrice) ?></div>
            <div style="font-size:.67rem;color:var(--txt3)">harga dasar</div>
          </td>
          <td class="hide-mobile">
            <span style="font-size:.75rem;color:var(--txt3)"><?= $itemCount ?> detail tarif</span>
          </td>
          <td class="hide-mobile">
            <button onclick="openAddItem(<?= $svc['id'] ?>,'<?= htmlspecialchars(addslashes($svc['name'])) ?>')"
                    class="btn btn-ghost btn-sm">
              <i class="ph ph-plus"></i> Tambah Tarif
            </button>
          </td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:99px;font-size:.67rem;font-weight:500;background:<?= $svc['is_active']?'rgba(34,197,94,.1)':'rgba(148,163,184,.07)' ?>;color:<?= $svc['is_active']?'var(--green)':'var(--txt3)' ?>;border:1px solid <?= $svc['is_active']?'rgba(34,197,94,.2)':'var(--line)' ?>">
              <i class="ph <?= $svc['is_active']?'ph-check-circle':'ph-x-circle' ?>"></i>
              <?= $svc['is_active']?'Aktif':'Nonaktif' ?>
            </span>
          </td>
          <td>
            <div style="display:flex;gap:4px;justify-content:center">
              <button onclick="openEditService(<?= htmlspecialchars(json_encode($svc)) ?>)"
                      class="btn btn-ghost btn-sm" title="Edit Layanan"><i class="ph ph-pencil-simple"></i></button>
              <button onclick="confirmDeleteSvc(<?= $svc['id'] ?>,'<?= htmlspecialchars(addslashes($svc['name'])) ?>')"
                      class="btn btn-danger btn-sm" title="Hapus"><i class="ph ph-trash"></i></button>
            </div>
          </td>
        </tr>

        <!-- ── Baris Detail Tarif ── -->
        <?php if (empty($svc['items'])): ?>
        <tr class="item-row">
          <td></td>
          <td colspan="5" style="font-size:.78rem;color:var(--txt3);padding-left:36px!important;font-style:italic">
            Belum ada detail tarif —
            <a href="#" onclick="event.preventDefault();openAddItem(<?= $svc['id'] ?>,'<?= htmlspecialchars(addslashes($svc['name'])) ?>')"
               style="color:var(--gold);text-decoration:none">tambah sekarang</a>
          </td>
          <td></td>
        </tr>

        <?php else: foreach ($svc['items'] as $i => $item):
          $diff = (float)$item['price'] - $basePrice;
          if ($diff > 0)     { $diffCls = 'diff-up';   $diffSign = '+'; }
          elseif ($diff < 0) { $diffCls = 'diff-down'; $diffSign = ''; }
          else               { $diffCls = 'diff-same';  $diffSign = '±'; }
        ?>
        <tr class="item-row" style="<?= $i % 2 === 1 ? 'background:rgba(255,255,255,.01)' : '' ?>">
          <td style="font-size:.7rem;color:var(--txt3);text-align:center"><?= $i+1 ?></td>
          <td class="item-label-cell">
            <div style="display:flex;align-items:center">
              <span class="item-dot"></span>
              <span style="font-size:.82rem;font-weight:500"><?= htmlspecialchars($item['label']) ?></span>
            </div>
            <?php if ($item['description']): ?>
            <div style="font-size:.7rem;color:var(--txt3);padding-left:14px;margin-top:2px"><?= htmlspecialchars($item['description']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="price-cell"><?= formatRupiah($item['price']) ?></span>
          </td>
          <td class="hide-mobile">
            <span style="font-size:.76rem;color:var(--txt2)"><?= htmlspecialchars($item['unit']) ?></span>
          </td>
          <td class="hide-mobile">
            <?php if ($basePrice > 0): ?>
            <span class="diff-badge <?= $diffCls ?>">
              <?= $diffSign ?><?= formatRupiah(abs($diff)) ?>
            </span>
            <?php else: ?>
            <span style="color:var(--txt3);font-size:.72rem">—</span>
            <?php endif; ?>
          </td>
          <td></td>
          <td>
            <div style="display:flex;gap:4px;justify-content:center">
              <button onclick="openEditItem(<?= htmlspecialchars(json_encode($item)) ?>,'<?= htmlspecialchars(addslashes($svc['name'])) ?>')"
                      class="btn btn-ghost btn-sm" title="Edit"><i class="ph ph-pencil-simple"></i></button>
              <button onclick="confirmDeleteItem(<?= $item['id'] ?>,'<?= htmlspecialchars(addslashes($item['label'])) ?>')"
                      class="btn btn-danger btn-sm" title="Hapus"><i class="ph ph-trash"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>

        <!-- ── Tombol tambah tarif (baris terakhir per layanan) ── -->
        <tr>
          <td colspan="7" style="padding:6px 14px 8px 36px;border-bottom:2px solid var(--line)">
            <button onclick="openAddItem(<?= $svc['id'] ?>,'<?= htmlspecialchars(addslashes($svc['name'])) ?>')"
                    style="background:none;border:1px dashed var(--line);border-radius:7px;color:var(--txt3);font-size:.75rem;padding:4px 12px;cursor:pointer;font-family:'DM Sans',sans-serif;transition:all .15s;display:inline-flex;align-items:center;gap:5px"
                    onmouseenter="this.style.borderColor='var(--gold)';this.style.color='var(--gold)'"
                    onmouseleave="this.style.borderColor='var(--line)';this.style.color='var(--txt3)'">
              <i class="ph ph-plus"></i> Tambah Detail Tarif untuk <?= htmlspecialchars($svc['name']) ?>
            </button>
          </td>
        </tr>

        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ══ MODAL: Tambah / Edit Layanan ══ -->
<div class="modal-overlay" id="svcModal">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title" id="svcModalTitle"><i class="ph ph-wrench"></i> Tambah Layanan</span>
      <button class="modal-close" onclick="document.getElementById('svcModal').classList.remove('open')"><i class="ph ph-x"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action"    id="svcAction" value="add_service">
      <input type="hidden" name="service_id" id="svcId"     value="0">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Nama Layanan <span>*</span></label>
          <input type="text" name="name" id="svcName" class="form-input"
                 placeholder="cth: Konsultasi IT, Pembuatan Website..." required>
        </div>
        <div class="form-group">
          <label class="form-label">Harga Dasar (Rp)
            <span style="font-size:.7rem;color:var(--txt3);font-weight:400"> — harga standar sebelum dipecah</span>
          </label>
          <input type="number" name="base_price" id="svcBasePrice" class="form-input"
                 placeholder="0" min="0" step="500" value="0">
          <div class="form-hint">Contoh: Pembuatan Website = Rp 5.000.000, lalu detail: Perusahaan Rp 3.000.000 / Karyawan Rp 2.000.000</div>
        </div>
        <div class="form-group">
          <label class="form-label">Deskripsi <span style="color:var(--txt3);font-weight:400">(opsional)</span></label>
          <textarea name="description" id="svcDesc" class="form-textarea" style="min-height:64px"
                    placeholder="Deskripsi singkat layanan..."></textarea>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem;color:var(--txt2)">
            <input type="checkbox" name="is_active" id="svcActive" checked style="accent-color:var(--gold);width:15px;height:15px">
            Aktif (dapat dipilih di form invoice)
          </label>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('svcModal').classList.remove('open')">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="ph ph-floppy-disk"></i> Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL: Tambah / Edit Detail Tarif ══ -->
<div class="modal-overlay" id="itemModal">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title" id="itemModalTitle"><i class="ph ph-tag"></i> Tambah Detail Tarif</span>
      <button class="modal-close" onclick="document.getElementById('itemModal').classList.remove('open')"><i class="ph ph-x"></i></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action"    id="itemAction" value="add_item">
      <input type="hidden" name="item_id"    id="itemId"     value="0">
      <input type="hidden" name="service_id" id="itemSvcId"  value="0">
      <div class="modal-body">
        <div style="padding:8px 12px;background:var(--gdim);border:1px solid var(--gring);border-radius:8px;margin-bottom:14px;font-size:.78rem;color:var(--gold)">
          <i class="ph ph-wrench" style="margin-right:4px"></i>Layanan: <strong id="itemSvcName"></strong>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Label / Kategori <span>*</span></label>
            <input type="text" name="label" id="itemLabel" class="form-input"
                   placeholder="cth: A. Perusahaan, B. Karyawan" required>
            <div class="form-hint">Gunakan A. B. C. untuk urutan yang rapi</div>
          </div>
          <div class="form-group">
            <label class="form-label">Satuan</label>
            <select name="unit" id="itemUnit" class="form-select">
              <option value="paket">Paket</option>
              <option value="per jam">Per Jam</option>
              <option value="per hari">Per Hari</option>
              <option value="per bulan">Per Bulan</option>
              <option value="per sesi">Per Sesi</option>
              <option value="per item">Per Item</option>
              <option value="per orang">Per Orang</option>
              <option value="per unit">Per Unit</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Harga (Rp) <span>*</span></label>
            <input type="number" name="price" id="itemPrice" class="form-input"
                   placeholder="0" min="0" step="500" required>
          </div>
          <div class="form-group">
            <label class="form-label">Urutan Tampil</label>
            <input type="number" name="sort_order" id="itemSort" class="form-input"
                   placeholder="0" min="0" value="0">
          </div>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Keterangan <span style="color:var(--txt3);font-weight:400">(opsional)</span></label>
          <input type="text" name="item_description" id="itemDesc" class="form-input"
                 placeholder="cth: Untuk perusahaan dengan lebih dari 50 karyawan">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('itemModal').classList.remove('open')">Batal</button>
        <button type="submit" class="btn btn-primary"><i class="ph ph-floppy-disk"></i> Simpan Tarif</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ MODAL: Hapus Layanan ══ -->
<div class="modal-overlay" id="delSvcModal">
  <div class="modal" style="max-width:380px">
    <div class="modal-head">
      <span class="modal-title" style="color:var(--red)"><i class="ph ph-warning-circle"></i> Hapus Layanan</span>
      <button class="modal-close" onclick="document.getElementById('delSvcModal').classList.remove('open')"><i class="ph ph-x"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:.83rem;color:var(--txt2);line-height:1.7">
        Yakin hapus layanan <strong id="delSvcName" style="color:var(--txt)"></strong>?<br>
        <span style="color:var(--red);font-size:.77rem">Semua detail tarif di dalamnya juga akan terhapus.</span>
      </p>
    </div>
    <div class="modal-foot">
      <form method="POST">
        <input type="hidden" name="_action"    value="delete_service">
        <input type="hidden" name="service_id" id="delSvcId">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('delSvcModal').classList.remove('open')">Batal</button>
        <button type="submit" class="btn btn-danger"><i class="ph ph-trash"></i> Hapus</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL: Hapus Tarif ══ -->
<div class="modal-overlay" id="delItemModal">
  <div class="modal" style="max-width:360px">
    <div class="modal-head">
      <span class="modal-title" style="color:var(--red)"><i class="ph ph-warning-circle"></i> Hapus Detail Tarif</span>
      <button class="modal-close" onclick="document.getElementById('delItemModal').classList.remove('open')"><i class="ph ph-x"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:.83rem;color:var(--txt2)">
        Hapus tarif <strong id="delItemName" style="color:var(--txt)"></strong>?
      </p>
    </div>
    <div class="modal-foot">
      <form method="POST">
        <input type="hidden" name="_action"  value="delete_item">
        <input type="hidden" name="item_id"  id="delItemId">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('delItemModal').classList.remove('open')">Batal</button>
        <button type="submit" class="btn btn-danger"><i class="ph ph-trash"></i> Hapus</button>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
function openAddService() {
  document.getElementById('svcModalTitle').innerHTML = '<i class="ph ph-plus"></i> Tambah Layanan Baru';
  document.getElementById('svcAction').value    = 'add_service';
  document.getElementById('svcId').value        = 0;
  document.getElementById('svcName').value      = '';
  document.getElementById('svcBasePrice').value = 0;
  document.getElementById('svcDesc').value      = '';
  document.getElementById('svcActive').checked  = true;
  document.getElementById('svcModal').classList.add('open');
  setTimeout(() => document.getElementById('svcName').focus(), 150);
}
function openEditService(svc) {
  document.getElementById('svcModalTitle').innerHTML = '<i class="ph ph-pencil-simple"></i> Edit Layanan';
  document.getElementById('svcAction').value    = 'edit_service';
  document.getElementById('svcId').value        = svc.id;
  document.getElementById('svcName').value      = svc.name;
  document.getElementById('svcBasePrice').value = svc.base_price || 0;
  document.getElementById('svcDesc').value      = svc.description || '';
  document.getElementById('svcActive').checked  = svc.is_active == 1;
  document.getElementById('svcModal').classList.add('open');
}
function confirmDeleteSvc(id, name) {
  document.getElementById('delSvcId').value         = id;
  document.getElementById('delSvcName').textContent = name;
  document.getElementById('delSvcModal').classList.add('open');
}
function openAddItem(svcId, svcName) {
  document.getElementById('itemModalTitle').innerHTML = '<i class="ph ph-tag"></i> Tambah Detail Tarif';
  document.getElementById('itemAction').value  = 'add_item';
  document.getElementById('itemId').value      = 0;
  document.getElementById('itemSvcId').value   = svcId;
  document.getElementById('itemSvcName').textContent = svcName;
  document.getElementById('itemLabel').value   = '';
  document.getElementById('itemPrice').value   = '';
  document.getElementById('itemUnit').value    = 'paket';
  document.getElementById('itemSort').value    = 0;
  document.getElementById('itemDesc').value    = '';
  document.getElementById('itemModal').classList.add('open');
  setTimeout(() => document.getElementById('itemLabel').focus(), 150);
}
function openEditItem(item, svcName) {
  document.getElementById('itemModalTitle').innerHTML = '<i class="ph ph-pencil-simple"></i> Edit Detail Tarif';
  document.getElementById('itemAction').value  = 'edit_item';
  document.getElementById('itemId').value      = item.id;
  document.getElementById('itemSvcId').value   = item.service_id;
  document.getElementById('itemSvcName').textContent = svcName;
  document.getElementById('itemLabel').value   = item.label;
  document.getElementById('itemPrice').value   = item.price;
  document.getElementById('itemUnit').value    = item.unit || 'paket';
  document.getElementById('itemSort').value    = item.sort_order || 0;
  document.getElementById('itemDesc').value    = item.description || '';
  document.getElementById('itemModal').classList.add('open');
}
function confirmDeleteItem(id, label) {
  document.getElementById('delItemId').value         = id;
  document.getElementById('delItemName').textContent = label;
  document.getElementById('delItemModal').classList.add('open');
}
JS;
require_once 'includes/footer.php';
?>