<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

const APP_NAME = 'Kuota Member Padel Sabtu';
const DEFAULT_ADMIN_PASSWORD = 'admin1234';
define('DB_PATH', __DIR__ . '/../database/kuota.sqlite');
const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_SECONDS = 900;

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

// Pages use inline <script> and on* handlers, so scripts/styles need 'unsafe-inline'.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $fresh = !file_exists(DB_PATH);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Same result types on PHP 7.4 and 8.x (PHP < 8.1 always returns strings).
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));

    if ($fresh) {
        $pdo->beginTransaction();
        $defaults = [
            'package_price'  => '160000',
            'quota_per_pkg'  => '4',
            'admin_password' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
        ];
        $st = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
        foreach ($defaults as $k => $v) {
            $st->execute([$k, $v]);
        }
        $seed = __DIR__ . '/../database/seed.sql';
        if (file_exists($seed)) {
            $pdo->exec(file_get_contents($seed));
        }
        $pdo->commit();
    }
    return $pdo;
}

function setting(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT OR REPLACE INTO settings(key, value) VALUES(?, ?)')
        ->execute([$key, $value]);
}

/** Quota summary per member. */
function member_quotas(bool $activeOnly = false): array
{
    $sql = "
        SELECT m.id, m.name, m.alias, m.active,
            COALESCE(p.packages, 0) AS packages,
            COALESCE(p.quota, 0)    AS quota,
            COALESCE(p.amount, 0)   AS amount,
            p.last_paid,
            COALESCE(u.played, 0)   AS played,
            COALESCE(u.refunded, 0) AS refunded,
            u.last_played
        FROM members m
        LEFT JOIN (
            SELECT member_id, SUM(packages) packages, SUM(quota) quota, SUM(amount) amount, MAX(paid_at) last_paid
            FROM payments GROUP BY member_id
        ) p ON p.member_id = m.id
        LEFT JOIN (
            SELECT member_id,
                SUM(type = 'play') played,
                SUM(type = 'refund') refunded,
                MAX(CASE WHEN type = 'play' THEN played_at END) last_played
            FROM usages GROUP BY member_id
        ) u ON u.member_id = m.id
        " . ($activeOnly ? 'WHERE m.active = 1' : '') . "
        ORDER BY (COALESCE(p.quota,0) - COALESCE(u.played,0) - COALESCE(u.refunded,0)) DESC, m.name COLLATE NOCASE";
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $r['remaining'] = (int) $r['quota'] - (int) $r['played'] - (int) $r['refunded'];
        $r['status'] = status_of($r['remaining']);
    }
    return $rows;
}

function status_of(int $remaining): array
{
    if ($remaining < 0) return ['Minus (cek)', 'neg'];
    if ($remaining === 0) return ['Habis', 'empty'];
    if ($remaining === 1) return ['Sisa 1', 'low'];
    return ['Aktif', 'ok'];
}

function member_history(int $memberId): array
{
    $st = db()->prepare("
        SELECT * FROM (
            SELECT 'pay' kind, paid_at d, packages, quota, amount, method src, note, id FROM payments WHERE member_id = :id
            UNION ALL
            SELECT type kind, played_at d, NULL, NULL, NULL, source src, note, id FROM usages WHERE member_id = :id2
        ) ORDER BY d IS NULL, d DESC, id DESC");
    $st->execute(['id' => $memberId, 'id2' => $memberId]);
    return $st->fetchAll();
}

// ---------- helpers ----------
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function rupiah($n): string
{
    return 'Rp' . number_format((float) $n, 0, ',', '.');
}

function tgl(?string $d, bool $short = false): string
{
    if (!$d) return '–';
    $ts = strtotime($d);
    if (!$ts) return e($d);
    $bln = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $s = date('j', $ts) . ' ' . $bln[(int) date('n', $ts) - 1];
    return $short ? $s : $s . ' ' . date('Y', $ts);
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

/** Seconds until this IP may try to log in again; 0 when not locked. */
function login_locked(string $ip): int
{
    $since = time() - LOGIN_LOCK_SECONDS;
    db()->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([$since]);
    $st = db()->prepare('SELECT COUNT(*), MIN(at) FROM login_attempts WHERE ip = ?');
    $st->execute([$ip]);
    [$fails, $first] = $st->fetch(PDO::FETCH_NUM);
    return (int) $fails >= LOGIN_MAX_FAILS ? max(1, (int) $first + LOGIN_LOCK_SECONDS - time()) : 0;
}

function login_failed(string $ip): void
{
    db()->prepare('INSERT INTO login_attempts(ip, at) VALUES(?, ?)')->execute([$ip, time()]);
}

function login_cleared(string $ip): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = [$msg, $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function valid_date(?string $d): ?string
{
    if (!$d) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d ? $d : null;
}

/** Default meet date: today if Saturday, otherwise the most recent Saturday. */
function recent_saturday(): string
{
    return date('N') == 6 ? date('Y-m-d') : date('Y-m-d', strtotime('last saturday'));
}
