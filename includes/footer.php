</div><!-- /page-content -->
</div><!-- /main-area -->

<!-- Mobile Bottom Nav -->
<nav class="bottom-nav">
  <a href="dashboard.php" class="bnav-item <?= ($activeMenu??'')==='dashboard'?'active':'' ?>"><i class="ph ph-squares-four"></i>Beranda</a>
  <a href="invoices.php"  class="bnav-item <?= ($activeMenu??'')==='invoices'?'active':'' ?>"><i class="ph ph-receipt"></i><?= isset($_SESSION['role'])&&$_SESSION['role']==='member'?'Tagihan':'Invoice' ?></a>
  <?php if (isset($_SESSION['role']) && $_SESSION['role'] !== 'member'): ?>
  <a href="clients.php"   class="bnav-item <?= ($activeMenu??'')==='clients'?'active':'' ?>"><i class="ph ph-users"></i>Klien</a>
  <a href="services.php"  class="bnav-item <?= ($activeMenu??'')==='services'?'active':'' ?>"><i class="ph ph-wrench"></i>Layanan</a>
  <?php else: ?>
  <a href="payments.php"  class="bnav-item <?= ($activeMenu??'')==='payments'?'active':'' ?>"><i class="ph ph-credit-card"></i>Riwayat</a>
  <a href="notifications.php" class="bnav-item <?= ($activeMenu??'')==='notifications'?'active':'' ?>"><i class="ph ph-bell"></i>Notif</a>
  <?php endif; ?>
  <a href="profile.php"   class="bnav-item <?= ($activeMenu??'')==='profile'?'active':'' ?>"><i class="ph ph-user-circle"></i>Akun</a>
</nav>

<script>
function openSidebar(){
  document.getElementById('mainSidebar').classList.add('drawer-open');
  document.getElementById('sidebarOverlay').classList.add('visible');
  document.body.style.overflow='hidden';
}
function closeSidebar(){
  document.getElementById('mainSidebar').classList.remove('drawer-open');
  document.getElementById('sidebarOverlay').classList.remove('visible');
  document.body.style.overflow='';
}
window.addEventListener('resize',function(){if(window.innerWidth>720)closeSidebar();});

// Flash message auto-hide
document.querySelectorAll('.alert').forEach(function(el){
  setTimeout(function(){ el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(function(){ el.remove(); },400); }, 4000);
});
</script>
<?php if (!empty($extraJs)): ?>
<script><?= $extraJs ?></script>
<?php endif; ?>
</body>
</html>