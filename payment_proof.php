<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db          = getDB();
$uid         = (int)$_SESSION['user_id'];
$role        = $_SESSION['role'];
$memberEmail = $_SESSION['email'] ?? '';

/* Auto-create tabel */
try {
    $db->exec("CREATE TABLE IF NOT EXISTS payment_proofs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        user_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255),
        amount DECIMAL(15,2) DEFAULT 0,
        method VARCHAR(50) DEFAULT 'transfer',
        reference VARCHAR(100),
        notes TEXT,
        status ENUM('pending','verified','rejected') DEFAULT 'pending',
        verified_by INT DEFAULT NULL,
        verified_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$invId   = (int)($_GET['invoice_id'] ?? 0);
$error   = '';
$success = '';

/* Load invoice */
if ($role === 'admin') {
    $q = $db->prepare("SELECT * FROM invoices WHERE id=?");
    $q->execute([$invId]);
} else {
    $q = $db->prepare("SELECT * FROM invoices WHERE id=? AND (user_id=? OR client_email=?)");
    $q->execute([$invId, $uid, $memberEmail]);
}
$inv = $q->fetch();
if (!$inv) { header('Location: invoices.php'); exit; }

/* Load proofs */
$pq = $db->prepare("SELECT pp.*, u.name AS uploader FROM payment_proofs pp
                    LEFT JOIN users u ON pp.user_id=u.id
                    WHERE pp.invoice_id=? ORDER BY pp.created_at DESC");
$pq->execute([$invId]);
$proofs = $pq->fetchAll();

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'upload';

    if ($action === 'upload') {
        $amount = (float)($_POST['amount'] ?? 0);
        $method = sanitize($_POST['method']    ?? 'transfer');
        $ref    = sanitize($_POST['reference'] ?? '');
        $notes  = sanitize($_POST['notes']     ?? '');

        if (!isset($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'File bukti transfer wajib diunggah.';
        } elseif ($amount <= 0) {
            $error = 'Jumlah transfer wajib diisi.';
        } else {
            $file    = $_FILES['proof_file'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','pdf'];
            $maxSize = 5 * 1024 * 1024;

            if (!in_array($ext, $allowed)) {
                $error = 'Format file harus JPG, PNG, atau PDF.';
            } elseif ($file['size'] > $maxSize) {
                $error = 'Ukuran file maksimal 5MB.';
            } else {
                $uploadDir = __DIR__ . '/uploads/proofs/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $filename = 'proof_' . $invId . '_' . time() . '_' . $uid . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                    $db->prepare("INSERT INTO payment_proofs (invoice_id,user_id,filename,original_name,amount,method,reference,notes) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$invId, $uid, $filename, $file['name'], $amount, $method, $ref, $notes]);
                    if ($inv['status'] === 'draft') {
                        $db->prepare("UPDATE invoices SET status='sent' WHERE id=?")->execute([$invId]);
                    }
                    $admins = $db->query("SELECT id FROM users WHERE role='admin'")->fetchAll();
                    foreach ($admins as $a) {
                        addNotification($a['id'], 'Bukti Transfer Masuk',
                            "Bukti transfer invoice {$inv['invoice_number']} dari {$_SESSION['name']} diunggah. Klik untuk verifikasi.", 'warning',
                            "payment_proof.php?invoice_id={$invId}");
                    }
                    $success = 'Bukti transfer berhasil diunggah! Menunggu verifikasi admin.';
                    /* Notif ke diri sendiri (konfirmasi upload berhasil) */
                    addNotification($uid, 'Bukti Transfer Dikirim',
                        "Bukti transfer untuk invoice {$inv['invoice_number']} berhasil dikirim. Menunggu konfirmasi admin.",
                        'info', "payment_proof.php?invoice_id={$invId}");
                    $pq->execute([$invId]);
                    $proofs = $pq->fetchAll();
                } else {
                    $error = 'Gagal upload. Pastikan folder uploads/proofs/ dapat ditulis (chmod 755).';
                }
            }
        }
    }

    if (in_array($action, ['verify','reject']) && $role === 'admin') {
        $pid  = (int)($_POST['proof_id'] ?? 0);
        $stat = $action === 'verify' ? 'verified' : 'rejected';
        $db->prepare("UPDATE payment_proofs SET status=?,verified_by=?,verified_at=NOW() WHERE id=?")
           ->execute([$stat, $uid, $pid]);

        /* Kumpulkan semua penerima notif:
           1. User yang upload bukti
           2. Member berdasarkan client_email invoice
           3. Pemilik invoice (jika berbeda)
        */
        $notifTo = [];
        // Dari uploader
        $uploaderQ = $db->prepare("SELECT user_id FROM payment_proofs WHERE id=?");
        $uploaderQ->execute([$pid]);
        $uploaderRow = $uploaderQ->fetch();
        if ($uploaderRow) $notifTo[] = (int)$uploaderRow['user_id'];
        // Dari client_email
        if (!empty($inv['client_email'])) {
            $memberQ = $db->prepare("SELECT id FROM users WHERE email=?");
            $memberQ->execute([$inv['client_email']]);
            $memberId = $memberQ->fetchColumn();
            if ($memberId && !in_array((int)$memberId, $notifTo))
                $notifTo[] = (int)$memberId;
        }
        // Dari user_id invoice (jika bukan admin)
        if ($inv['user_id'] != 1 && !in_array((int)$inv['user_id'], $notifTo))
            $notifTo[] = (int)$inv['user_id'];

        if ($action === 'verify') {
            $db->prepare("UPDATE invoices SET status='paid',paid_at=NOW() WHERE id=?")->execute([$invId]);
            foreach ($notifTo as $tid) {
                addNotification($tid, '✓ Pembayaran Dikonfirmasi',
                    "Invoice {$inv['invoice_number']} telah diverifikasi LUNAS oleh admin. Terima kasih!",
                    'success', "invoice_detail.php?id={$invId}");
            }
        } else {
            foreach ($notifTo as $tid) {
                addNotification($tid, 'Bukti Transfer Ditolak',
                    "Bukti transfer invoice {$inv['invoice_number']} ditolak. Harap unggah ulang.",
                    'danger', "payment_proof.php?invoice_id={$invId}");
            }
        }
        header("Location: payment_proof.php?invoice_id=$invId&saved=1"); exit;
    }
}

$stMap = [
    'paid'    => ['bg'=>'#0d2818','color'=>'#4ade80','border'=>'#1a4d2e','label'=>'Lunas'],
    'sent'    => ['bg'=>'#2a1f06','color'=>'#fbbf24','border'=>'#4a3510','label'=>'Terkirim'],
    'draft'   => ['bg'=>'#161b27','color'=>'#94a3b8','border'=>'#1e2535','label'=>'Draft'],
    'overdue' => ['bg'=>'#2a0d0d','color'=>'#f87171','border'=>'#4d1a1a','label'=>'Jatuh Tempo'],
];
$st = $stMap[$inv['status']] ?? $stMap['draft'];

$psMap = [
    'pending'  => ['bg'=>'rgba(245,158,11,.12)','color'=>'#fbbf24','label'=>'Menunggu Verifikasi','icon'=>'ph-clock'],
    'verified' => ['bg'=>'rgba(34,197,94,.12)', 'color'=>'#4ade80','label'=>'Terverifikasi',      'icon'=>'ph-check-circle'],
    'rejected' => ['bg'=>'rgba(239,68,68,.12)', 'color'=>'#f87171','label'=>'Ditolak',            'icon'=>'ph-x-circle'],
];

/* Siapkan JS data */
$jsSuccess  = $success ? json_encode($success) : 'false';
$jsError    = $error   ? json_encode($error)   : 'false';

$pageTitle   = 'Bukti Transfer';
$pageSubtitle = $inv['invoice_number'];
$activeMenu  = 'payments';

/* ════ $extraJs — diset SEBELUM require_once footer ════ */
$extraJs = <<<ENDJS
/* ── Data dari PHP ── */
var PHP_SUCCESS = {$jsSuccess};
var PHP_ERROR   = {$jsError};

/* ── File Preview ── */
function previewFile(input) {
    var file = input.files[0];
    if (!file) return;
    document.getElementById('fileName').textContent = file.name;
    document.getElementById('fileSize').textContent = (file.size/1024/1024).toFixed(2) + ' MB';
    document.getElementById('filePreview').classList.add('show');
    document.getElementById('dropZone').style.borderColor = 'var(--gring)';
}
function clearFile() {
    document.getElementById('proofFileInput').value = '';
    document.getElementById('filePreview').classList.remove('show');
    document.getElementById('dropZone').style.borderColor = 'var(--line)';
}
function handleDrop(e) {
    e.preventDefault();
    document.getElementById('dropZone').classList.remove('drag');
    var files = e.dataTransfer.files;
    if (files.length) {
        document.getElementById('proofFileInput').files = files;
        previewFile(document.getElementById('proofFileInput'));
    }
}

/* ── Toast ── */
var _toastTimer = null;
function showToast(type, msg) {
    var icons   = { success:'ph-check-circle', warning:'ph-warning-circle', error:'ph-x-circle', info:'ph-info' };
    var colors  = { success:'var(--green)',     warning:'var(--amber)',       error:'var(--red)',   info:'var(--blue)' };
    var toast   = document.getElementById('fpToast');
    var icon    = document.getElementById('fpToastIcon');
    var msgEl   = document.getElementById('fpToastMsg');
    icon.className  = 'ph ' + (icons[type]  || icons.info);
    icon.style.color = colors[type] || colors.info;
    msgEl.innerHTML  = msg;
    toast.style.borderLeftColor = colors[type] || colors.info;
    toast.style.display = 'flex';
    toast.style.animation = 'none';
    void toast.offsetWidth;
    toast.style.animation = 'fpSlideUp .3s ease both';
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(hideToast, 5000);
}
function hideToast() {
    var t = document.getElementById('fpToast');
    if (t) t.style.display = 'none';
}

/* ── Confirm Dialog ── */
function showUploadConfirm() {
    var fileInput = document.getElementById('proofFileInput');
    var amountEl  = document.querySelector('[name="amount"]');
    if (!fileInput || !fileInput.files.length) {
        showToast('warning', 'Pilih file bukti transfer terlebih dahulu.');
        return;
    }
    if (!amountEl || parseFloat(amountEl.value) <= 0) {
        showToast('warning', 'Masukkan jumlah transfer yang valid.');
        return;
    }
    var file   = fileInput.files[0];
    var amount = parseFloat(amountEl.value);
    var method = document.querySelector('[name="method"]').value;
    var ref    = document.querySelector('[name="reference"]').value;
    var labels = { transfer:'Transfer Bank', qris:'QRIS', ewallet:'E-Wallet', cash:'Tunai', other:'Lainnya' };
    var fmt    = 'Rp ' + Math.round(amount).toLocaleString('id-ID');
    document.getElementById('confirmDetail').innerHTML =
        '<div style="text-align:left;background:rgba(255,255,255,.03);border-radius:9px;padding:12px 14px;font-size:.81rem;line-height:2">'
        + '<span style="color:var(--txt3)">File:</span> <strong>' + file.name + '</strong><br>'
        + '<span style="color:var(--txt3)">Jumlah:</span> <strong style="color:var(--goldh)">' + fmt + '</strong><br>'
        + '<span style="color:var(--txt3)">Metode:</span> ' + (labels[method] || method)
        + (ref ? '<br><span style="color:var(--txt3)">Referensi:</span> ' + ref : '')
        + '</div>';
    document.getElementById('uploadConfirm').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeUploadConfirm() {
    document.getElementById('uploadConfirm').style.display = 'none';
    document.body.style.overflow = '';
}
function doSubmit() {
    closeUploadConfirm();
    var btn = document.getElementById('submitBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ph ph-circle-notch"></i> Mengirim...'; }
    document.getElementById('uploadForm').submit();
}

/* ── ESC ── */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeUploadConfirm();
});

/* ── Auto toast dari PHP ── */
window.addEventListener('load', function() {
    if (PHP_SUCCESS) showToast('success', PHP_SUCCESS);
    if (PHP_ERROR)   showToast('error',   PHP_ERROR);
});
ENDJS;

require_once 'includes/header.php';
?>

<style>
/* ── Layout ── */
.proof-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start; }
@media(max-width:768px){ .proof-grid { grid-template-columns:1fr; } }

/* ── Upload zone ── */
.upload-zone {
  border:2px dashed var(--line); border-radius:10px; padding:28px 20px;
  text-align:center; cursor:pointer; transition:all .2s;
  background:rgba(255,255,255,.01);
}
.upload-zone:hover, .upload-zone.drag { border-color:var(--gring); background:var(--gdim); }
.file-preview { display:none; margin-top:10px; align-items:center; gap:10px;
  padding:10px 12px; background:var(--gdim); border:1px solid var(--gring); border-radius:8px; }
.file-preview.show { display:flex; }

/* ── Confirm overlay ── */
#uploadConfirm {
  display:none; position:fixed; inset:0; z-index:9999;
  background:rgba(0,0,0,.75); backdrop-filter:blur(6px);
  align-items:center; justify-content:center; padding:20px;
}
#confirmBox {
  background:var(--surf2); border:1px solid var(--gring); border-radius:16px;
  padding:30px 26px 24px; width:100%; max-width:400px; text-align:center;
  box-shadow:0 24px 64px rgba(0,0,0,.8);
  animation:fpPopIn .22s cubic-bezier(.34,1.56,.64,1) both;
}

/* ── Toast ── */
#fpToast {
  display:none; position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
  z-index:99999; min-width:280px; max-width:420px;
  background:var(--surf2); border:1px solid var(--gring);
  border-left:3px solid var(--gold);
  border-radius:10px; padding:12px 16px;
  box-shadow:0 8px 32px rgba(0,0,0,.6);
  align-items:center; gap:10px; font-size:.83rem;
}

@keyframes fpPopIn {
  from { opacity:0; transform:scale(.88); }
  to   { opacity:1; transform:scale(1); }
}
@keyframes fpSlideUp {
  from { opacity:0; transform:translateX(-50%) translateY(12px); }
  to   { opacity:1; transform:translateX(-50%) translateY(0); }
}
</style>

<!-- Back button -->
<div style="margin-bottom:14px">
  <a href="invoice_detail.php?id=<?= $invId ?>" class="btn btn-ghost btn-sm">
    <i class="ph ph-arrow-left"></i> Kembali ke Invoice
  </a>
  <?php if ($inv['status'] === 'paid'): ?>
  <a href="payment_receipt.php?invoice_id=<?= $invId ?>" target="_blank"
     class="btn btn-sm"
     style="background:linear-gradient(135deg,var(--green),#16a34a);color:#fff;
            display:inline-flex;align-items:center;gap:5px;padding:0 12px;height:32px;
            border-radius:8px;text-decoration:none;font-size:.79rem;font-weight:500">
    <i class="ph ph-receipt"></i> Cetak Kwitansi
  </a>
  <?php endif; ?>
</div>

<!-- Invoice summary card -->
<div class="card-box anim" style="margin-bottom:16px">
  <div style="padding:16px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;border-bottom:1px solid var(--line2)">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:40px;height:40px;border-radius:10px;background:var(--gdim);border:1px solid var(--gring);display:grid;place-items:center;color:var(--gold);font-size:1.1rem">
        <i class="ph ph-receipt"></i>
      </div>
      <div>
        <div style="font-weight:700;font-size:.95rem"><?= htmlspecialchars($inv['invoice_number']) ?></div>
        <div style="font-size:.75rem;color:var(--txt3)">Kepada: <?= htmlspecialchars($inv['client_name']) ?></div>
      </div>
    </div>
    <div style="text-align:right">
      <div style="font-family:'Cormorant Garamond',serif;font-size:1.5rem;color:var(--goldh)"><?= formatRupiah($inv['total']) ?></div>
      <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:99px;font-size:.7rem;font-weight:500;
                   background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;border:1px solid <?= $st['border'] ?>">
        <?= $st['label'] ?>
      </span>
    </div>
  </div>
  <div style="padding:11px 18px;display:flex;gap:24px;flex-wrap:wrap">
    <div>
      <span style="font-size:.7rem;color:var(--txt3)">Jatuh Tempo</span>
      <div style="font-size:.82rem;font-weight:500;margin-top:2px"><?= formatTanggal($inv['due_date']) ?></div>
    </div>
    <div>
      <span style="font-size:.7rem;color:var(--txt3)">Status Pembayaran</span>
      <?php
      $vCount = count(array_filter($proofs, fn($p) => $p['status']==='verified'));
      $pCount = count($proofs);
      ?>
      <div style="font-size:.82rem;margin-top:2px;color:<?= $vCount>0?'var(--green)':($pCount>0?'var(--amber)':'var(--txt3)') ?>">
        <?= $vCount>0 ? '✓ Sudah Diverifikasi' : ($pCount>0 ? '⏳ Menunggu Verifikasi' : '— Belum ada bukti') ?>
      </div>
    </div>
  </div>
</div>

<?php if ($error): ?>
<div class="alert alert-error"><i class="ph ph-warning-circle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><i class="ph ph-check-circle"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="proof-grid">

  <!-- KIRI: Form Upload -->
  <?php if ($inv['status'] !== 'paid'): ?>
  <div class="card-box">
    <div class="card-head">
      <div class="card-headicon" style="background:rgba(96,165,250,.1);color:var(--blue);border-color:rgba(96,165,250,.2)">
        <i class="ph ph-upload-simple"></i>
      </div>
      <span class="card-title">Upload Bukti Transfer</span>
    </div>
    <form id="uploadForm" method="POST"
          action="payment_proof.php?invoice_id=<?= $invId ?>"
          enctype="multipart/form-data"
          style="padding:16px">
      <input type="hidden" name="_action" value="upload">

      <!-- Drop zone -->
      <div class="upload-zone" id="dropZone"
           onclick="document.getElementById('proofFileInput').click()"
           ondragover="event.preventDefault();this.classList.add('drag')"
           ondragleave="this.classList.remove('drag')"
           ondrop="handleDrop(event)">
        <i class="ph ph-image" style="font-size:2rem;color:var(--txt3);display:block;margin-bottom:8px"></i>
        <p style="font-size:.82rem;color:var(--txt3)"><strong style="color:var(--gold)">Klik untuk pilih file</strong> atau seret ke sini</p>
        <p style="font-size:.72rem;color:var(--txt3);margin-top:4px">JPG, PNG, PDF — Maks. 5MB</p>
      </div>
      <input type="file" id="proofFileInput" name="proof_file"
             accept=".jpg,.jpeg,.png,.pdf" onchange="previewFile(this)" style="display:none">

      <div class="file-preview" id="filePreview">
        <i class="ph ph-file-image" style="font-size:1.3rem;color:var(--gold);flex-shrink:0"></i>
        <div style="flex:1;min-width:0">
          <div id="fileName" style="font-size:.8rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div>
          <div id="fileSize" style="font-size:.7rem;color:var(--txt3)"></div>
        </div>
        <button type="button" onclick="clearFile()"
                style="background:none;border:none;color:var(--txt3);cursor:pointer;font-size:.9rem">
          <i class="ph ph-x"></i>
        </button>
      </div>

      <div style="margin-top:14px;display:flex;flex-direction:column;gap:12px">
        <div class="form-group" style="margin:0">
          <label class="form-label">Jumlah Transfer (Rp) <span>*</span></label>
          <input type="number" name="amount" class="form-input"
                 value="<?= (float)$inv['total'] ?>" min="1" step="any" required>
          <div class="form-hint">Sesuaikan dengan jumlah yang Anda transfer</div>
        </div>

        <div class="form-row" style="margin:0">
          <div class="form-group" style="margin:0">
            <label class="form-label">Metode Pembayaran</label>
            <select name="method" class="form-select">
              <option value="transfer">Transfer Bank</option>
              <option value="qris">QRIS</option>
              <option value="ewallet">E-Wallet</option>
              <option value="cash">Tunai</option>
              <option value="other">Lainnya</option>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">No. Referensi</label>
            <input type="text" name="reference" class="form-input" placeholder="cth: TRF202603001">
          </div>
        </div>

        <div class="form-group" style="margin:0">
          <label class="form-label">Catatan <span style="color:var(--txt3);font-weight:400">(opsional)</span></label>
          <textarea name="notes" class="form-textarea" style="min-height:60px"
                    placeholder="Pesan untuk admin..."></textarea>
        </div>

        <button type="button" id="submitBtn" onclick="showUploadConfirm()"
                class="btn btn-primary"
                style="width:100%;height:42px;justify-content:center;font-size:.86rem">
          <i class="ph ph-upload-simple"></i> Kirim Bukti Transfer
        </button>
      </div>
    </form>
  </div>

  <?php else: ?>
  <div class="card-box" style="padding:32px;text-align:center">
    <div style="width:56px;height:56px;border-radius:50%;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);
                display:grid;place-items:center;margin:0 auto 12px;font-size:1.5rem;color:var(--green)">
      <i class="ph ph-check-circle"></i>
    </div>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:400;margin-bottom:6px">Invoice Sudah Lunas</h3>
    <p style="font-size:.8rem;color:var(--txt3)">Pembayaran telah dikonfirmasi oleh admin.</p>
  </div>
  <?php endif; ?>

  <!-- KANAN: Riwayat -->
  <div class="card-box">
    <div class="card-head">
      <div class="card-headicon"><i class="ph ph-clock-counter-clockwise"></i></div>
      <span class="card-title">Riwayat Bukti Transfer</span>
      <span style="font-size:.72rem;color:var(--txt3)"><?= count($proofs) ?> unggahan</span>
    </div>

    <?php if (empty($proofs)): ?>
    <div style="padding:40px 16px;text-align:center">
      <i class="ph ph-image" style="font-size:2rem;color:var(--txt3);opacity:.3;display:block;margin-bottom:8px"></i>
      <p style="font-size:.8rem;color:var(--txt3)">Belum ada bukti yang diunggah</p>
    </div>
    <?php else: foreach ($proofs as $proof):
      $ps   = $psMap[$proof['status']] ?? $psMap['pending'];
      $isImg = in_array(strtolower(pathinfo($proof['filename'], PATHINFO_EXTENSION)), ['jpg','jpeg','png']);
    ?>
    <div style="padding:14px 16px;border-bottom:1px solid var(--line2)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;gap:8px">
        <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;
                     font-size:.72rem;font-weight:500;background:<?= $ps['bg'] ?>;color:<?= $ps['color'] ?>">
          <i class="ph <?= $ps['icon'] ?>"></i> <?= $ps['label'] ?>
        </span>
        <span style="font-size:.7rem;color:var(--txt3)"><?= date('d M Y H:i', strtotime($proof['created_at'])) ?></span>
      </div>

      <?php if ($isImg): ?>
      <img src="uploads/proofs/<?= htmlspecialchars($proof['filename']) ?>" alt="Bukti"
           onclick="window.open(this.src,'_blank')"
           style="max-width:100%;max-height:180px;object-fit:cover;width:100%;border-radius:8px;
                  border:1px solid var(--line);cursor:pointer;margin-bottom:10px">
      <p style="font-size:.68rem;color:var(--txt3);text-align:center;margin-bottom:10px">Klik untuk lihat penuh</p>
      <?php else: ?>
      <a href="uploads/proofs/<?= htmlspecialchars($proof['filename']) ?>" target="_blank"
         class="btn btn-ghost btn-sm" style="margin-bottom:10px">
        <i class="ph ph-file-pdf"></i> Lihat PDF
      </a>
      <?php endif; ?>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:.77rem">
        <div><span style="color:var(--txt3)">Jumlah</span>
          <div style="font-weight:600;color:var(--goldh)"><?= formatRupiah($proof['amount']) ?></div></div>
        <div><span style="color:var(--txt3)">Metode</span>
          <div style="text-transform:capitalize"><?= htmlspecialchars($proof['method']) ?></div></div>
        <?php if ($proof['reference']): ?>
        <div style="grid-column:span 2"><span style="color:var(--txt3)">Referensi</span>
          <div><?= htmlspecialchars($proof['reference']) ?></div></div>
        <?php endif; ?>
        <?php if ($proof['notes']): ?>
        <div style="grid-column:span 2"><span style="color:var(--txt3)">Catatan</span>
          <div style="color:var(--txt2)"><?= htmlspecialchars($proof['notes']) ?></div></div>
        <?php endif; ?>
      </div>

      <?php if ($role === 'admin' && $proof['status'] === 'pending'): ?>
      <div style="display:flex;gap:8px;margin-top:12px">
        <form method="POST" style="flex:1">
          <input type="hidden" name="_action"  value="verify">
          <input type="hidden" name="proof_id" value="<?= $proof['id'] ?>">
          <button type="submit" class="btn btn-sm"
                  style="width:100%;justify-content:center;background:rgba(34,197,94,.15);
                         color:var(--green);border:1px solid rgba(34,197,94,.3)">
            <i class="ph ph-check-circle"></i> Verifikasi Lunas
          </button>
        </form>
        <form method="POST" style="flex:1">
          <input type="hidden" name="_action"  value="reject">
          <input type="hidden" name="proof_id" value="<?= $proof['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm"
                  style="width:100%;justify-content:center">
            <i class="ph ph-x-circle"></i> Tolak
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
  </div>

</div><!-- /proof-grid -->

<!-- ════ CONFIRM DIALOG ════ -->
<div id="uploadConfirm"
     style="display:none;position:fixed;inset:0;z-index:9999;
            background:rgba(0,0,0,.75);backdrop-filter:blur(6px);
            align-items:center;justify-content:center;padding:20px">
  <div id="confirmBox">
    <div style="width:52px;height:52px;border-radius:50%;background:var(--gdim);border:1px solid var(--gring);
                display:grid;place-items:center;margin:0 auto 16px;font-size:1.3rem;color:var(--gold)">
      <i class="ph ph-upload-simple"></i>
    </div>
    <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.3rem;font-weight:400;margin-bottom:8px">
      Kirim Bukti Transfer?
    </h3>
    <p style="font-size:.8rem;color:var(--txt3);margin-bottom:14px">Pastikan data berikut sudah benar:</p>
    <div id="confirmDetail" style="margin-bottom:20px"></div>
    <div style="display:flex;gap:10px">
      <button type="button" onclick="closeUploadConfirm()"
              class="btn btn-ghost" style="flex:1;height:40px;justify-content:center">
        <i class="ph ph-x"></i> Batal
      </button>
      <button type="button" onclick="doSubmit()"
              class="btn btn-primary" style="flex:1;height:40px;justify-content:center">
        <i class="ph ph-paper-plane-tilt"></i> Ya, Kirim
      </button>
    </div>
  </div>
</div>

<!-- ════ TOAST ════ -->
<div id="fpToast"
     style="display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);
            z-index:99999;min-width:280px;max-width:420px;
            background:var(--surf2);border:1px solid var(--gring);border-left:3px solid var(--gold);
            border-radius:10px;padding:12px 16px;
            box-shadow:0 8px 32px rgba(0,0,0,.6);
            align-items:center;gap:10px;font-size:.83rem">
  <i id="fpToastIcon" class="ph ph-info" style="font-size:1.1rem;flex-shrink:0"></i>
  <span id="fpToastMsg" style="flex:1"></span>
  <button onclick="hideToast()"
          style="background:none;border:none;color:var(--txt3);cursor:pointer;padding:0;font-size:.9rem;flex-shrink:0">
    <i class="ph ph-x"></i>
  </button>
</div>

<?php require_once 'includes/footer.php'; ?>