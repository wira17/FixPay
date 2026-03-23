<?php
require_once 'config/database.php';
startSession();
requireLogin();

$db  = getDB();
$uid = (int)$_SESSION['user_id'];

// Mark all read
if (isset($_GET['read_all'])) {
    $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
    header('Location: notifications.php'); exit;
}
// Mark single read + redirect ke link_url jika ada
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];
    $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$nid, $uid]);
    /* Coba ambil link_url */
    try {
        $nrow = $db->prepare("SELECT link_url FROM notifications WHERE id=? AND user_id=?");
        $nrow->execute([$nid, $uid]);
        $nrow = $nrow->fetch();
        if ($nrow && !empty($nrow['link_url'])) {
            header('Location: ' . $nrow['link_url']); exit;
        }
    } catch (PDOException $e2) { /* kolom belum ada, lanjut */ }
}

/* Auto-add kolom link_url jika belum ada */
try {
    $db->query("SELECT link_url FROM notifications LIMIT 1");
} catch (PDOException $e) {
    $db->exec("ALTER TABLE notifications ADD COLUMN link_url VARCHAR(255) DEFAULT '' AFTER type");
}

$notifs = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$notifs->execute([$uid]); $notifs = $notifs->fetchAll();

$typeIcon  = ['info'=>'ph-info','success'=>'ph-check-circle','warning'=>'ph-warning','danger'=>'ph-warning-circle'];
$typeColor = ['info'=>'var(--blue)','success'=>'var(--green)','warning'=>'var(--amber)','danger'=>'var(--red)'];
$typeBg    = ['info'=>'rgba(96,165,250,.1)','success'=>'rgba(34,197,94,.1)','warning'=>'rgba(245,158,11,.1)','danger'=>'rgba(239,68,68,.1)'];

$pageTitle  = 'Notifikasi';
$activeMenu = 'notifications';
require_once 'includes/header.php';
?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
  <div style="font-size:.8rem;color:var(--txt3)"><?= count($notifs) ?> notifikasi</div>
  <?php $unread = array_filter($notifs, fn($n) => !$n['is_read']); ?>
  <?php if ($unread): ?>
  <a href="notifications.php?read_all=1" class="btn btn-ghost btn-sm">
    <i class="ph ph-checks"></i> Tandai semua dibaca
  </a>
  <?php endif; ?>
</div>

<div class="card-box anim">
  <?php if (empty($notifs)): ?>
  <div class="empty-state">
    <i class="ph ph-bell-slash"></i>
    <h3>Tidak ada notifikasi</h3>
    <p>Anda akan mendapat notifikasi ketika ada aktivitas baru.</p>
  </div>
  <?php else: ?>
  <?php foreach ($notifs as $n):
    $ic = $typeIcon[$n['type']]  ?? 'ph-info';
    $cl = $typeColor[$n['type']] ?? 'var(--blue)';
    $bg = $typeBg[$n['type']]    ?? 'rgba(96,165,250,.1)';
  ?>
  <?php $hasLink = !empty($n['link_url'] ?? ''); ?>
  <a href="notifications.php?read=<?= $n['id'] ?>"
     style="display:flex;align-items:flex-start;gap:11px;padding:13px 15px;
            border-bottom:1px solid var(--line2);text-decoration:none;
            background:<?= $n['is_read']?'transparent':'rgba(200,160,74,.03)' ?>;
            transition:background .12s"
     onmouseenter="this.style.background='rgba(255,255,255,.025)'"
     onmouseleave="this.style.background='<?= $n['is_read']?'transparent':'rgba(200,160,74,.03)' ?>'">
    <!-- Icon -->
    <div style="width:34px;height:34px;border-radius:9px;background:<?= $bg ?>;color:<?= $cl ?>;
                border:1px solid rgba(255,255,255,.07);display:grid;place-items:center;
                font-size:.95rem;flex-shrink:0;margin-top:1px">
      <i class="ph <?= $ic ?>"></i>
    </div>
    <!-- Content -->
    <div style="flex:1;min-width:0">
      <?php if ($n['title']): ?>
      <div style="font-weight:<?= $n['is_read']?'400':'600' ?>;font-size:.83rem;
                  color:var(--txt);margin-bottom:3px;
                  display:flex;align-items:center;gap:6px">
        <?= htmlspecialchars($n['title']) ?>
        <?php if (!$n['is_read']): ?>
        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;
                     background:var(--gold);flex-shrink:0"></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div style="font-size:.77rem;color:var(--txt2);line-height:1.55"><?= htmlspecialchars($n['message']) ?></div>
      <div style="margin-top:5px;display:flex;align-items:center;gap:8px">
        <span style="font-size:.68rem;color:var(--txt3)"><?= date('d M Y H:i', strtotime($n['created_at'])) ?></span>
        <?php if ($hasLink): ?>
        <span style="font-size:.68rem;color:var(--gold);display:flex;align-items:center;gap:3px">
          <i class="ph ph-arrow-right"></i> Lihat detail
        </span>
        <?php endif; ?>
      </div>
    </div>
    <!-- Arrow jika ada link -->
    <?php if ($hasLink): ?>
    <div style="color:var(--txt3);font-size:.85rem;flex-shrink:0;margin-top:8px">
      <i class="ph ph-caret-right"></i>
    </div>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>