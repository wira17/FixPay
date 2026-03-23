<?php
// ============================================
// FixPay — Database & App Configuration
// File: config/database.php
// ============================================

// ── Koneksi Database ──
define('DB_HOST',    'localhost');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_NAME',    'fixpay_db');
define('DB_CHARSET', 'utf8mb4');

// ── App Config ──
define('APP_NAME',    'FixPay');
define('APP_URL',     'http://localhost/fixpay');
define('APP_VERSION', '2.0.0');

// ── Session timeout = 2 jam ──
define('SESSION_TIMEOUT', 7200);

// ── Timezone ──
date_default_timezone_set('Asia/Jakarta');

// ─────────────────────────────────────────────
// Database Connection (Singleton)
// ─────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Tampilkan error connection yang ramah
            http_response_code(500);
            die('
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Database Error</title>
<style>body{font-family:sans-serif;background:#07090f;color:#e8e4db;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
.box{text-align:center;padding:2rem;max-width:480px}h1{color:#f87171;font-size:1.4rem;margin-bottom:.8rem}
p{color:#8c97aa;font-size:.85rem;line-height:1.7}code{background:#111827;padding:2px 8px;border-radius:4px;font-size:.8rem;color:#fbbf24}</style>
</head><body><div class="box">
<h1>⚠ Koneksi Database Gagal</h1>
<p>Tidak dapat terhubung ke database. Pastikan:</p>
<p>• MySQL / MariaDB sudah berjalan<br>
• Database <code>' . DB_NAME . '</code> sudah dibuat<br>
• Kredensial di <code>config/database.php</code> benar</p>
<p style="font-size:.75rem;color:#505a6c;margin-top:1rem">' . htmlspecialchars($e->getMessage()) . '</p>
</div></body></html>');
        }
    }
    return $pdo;
}

// ─────────────────────────────────────────────
// Session
// ─────────────────────────────────────────────
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_name('FIXPAY_SESSION');
        session_start();
    }
    // Timeout check
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: ' . APP_URL . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// ─────────────────────────────────────────────
// Auth Helpers
// ─────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $back = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . APP_URL . '/login.php' . ($back ? "?redirect=$back" : ''));
        exit;
    }
}

function requireRole($roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'], (array)$roles)) {
        header('Location: ' . APP_URL . '/dashboard.php?error=unauthorized');
        exit;
    }
}

// ─────────────────────────────────────────────
// Formatting Helpers
// ─────────────────────────────────────────────
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function formatRupiah(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function formatTanggal(string $date, string $format = 'd F Y'): string {
    if (empty($date) || $date === '0000-00-00') return '-';
    $ts = strtotime($date);
    if ($ts === false) return '-';
    $out = date($format, $ts);
    // Bulan panjang
    $out = str_replace(
        ['January','February','March','April','May','June','July','August','September','October','November','December'],
        ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'],
        $out
    );
    // Bulan pendek
    $out = str_replace(
        ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'],
        $out
    );
    // Hari
    $out = str_replace(
        ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
        ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'],
        $out
    );
    return $out;
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Baru saja';
    if ($diff < 3600)   return floor($diff/60)   . ' menit lalu';
    if ($diff < 86400)  return floor($diff/3600)  . ' jam lalu';
    if ($diff < 604800) return floor($diff/86400) . ' hari lalu';
    return formatTanggal($datetime, 'd M Y');
}

function statusLabel(string $status): string {
    $map = ['paid'=>'Lunas','sent'=>'Terkirim','draft'=>'Draft','overdue'=>'Jatuh Tempo','cancelled'=>'Batal'];
    return $map[$status] ?? ucfirst($status);
}

// ─────────────────────────────────────────────
// Invoice Number Generator
// ─────────────────────────────────────────────
function generateInvoiceNumber(): string {
    $db    = getDB();
    $year  = date('Y');
    $month = date('m');
    // Cari nomor urut tertinggi bulan ini (bukan COUNT, biar aman dari gap)
    $stmt  = $db->prepare("SELECT invoice_number FROM invoices
                            WHERE YEAR(created_at)=? AND MONTH(created_at)=?
                            ORDER BY id DESC LIMIT 1");
    $stmt->execute([$year, $month]);
    $last = $stmt->fetchColumn();
    $seq  = 1;
    if ($last) {
        // Ambil 4 digit terakhir
        $parts = explode('-', $last);
        $seq   = (int)end($parts) + 1;
    }
    return "INV-{$year}{$month}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ─────────────────────────────────────────────
// Notification Helper
// ─────────────────────────────────────────────
function addNotification(int $userId, string $title, string $message, string $type = 'info', string $linkUrl = ''): void {
    try {
        $db = getDB();
        /* Auto-create kolom link_url jika belum ada */
        try {
            $db->query("SELECT link_url FROM notifications LIMIT 1");
        } catch (PDOException $e2) {
            $db->exec("ALTER TABLE notifications ADD COLUMN link_url VARCHAR(255) DEFAULT '' AFTER type");
        }
        $db->prepare("INSERT INTO notifications (user_id,title,message,type,link_url) VALUES (?,?,?,?,?)")
           ->execute([$userId, $title, $message, $type, $linkUrl]);
    } catch (Exception $e) {
        // Gagal notif jangan sampai break flow utama
    }
}
?>