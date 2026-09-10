<?php
/**
 * Database configuration & shared helpers
 */
declare(strict_types=1);

// DB connection, overridable via environment (Docker Compose / Render).
// Defaults match local XAMPP (root / empty password / db `moneywise`).
// A db-config.php in the project root (see db-config.example.php) overrides
// these defaults for shared hosting like InfinityFree, where server env vars
// are not available. Priority: env var > db-config.php > local default.
$__dbOverride = [];
if (is_file(__DIR__ . '/../db-config.php')) {
    ob_start(); // discard any accidental BOM/whitespace so header() calls below stay clean
    $__dbOverride = (array)require __DIR__ . '/../db-config.php';
    ob_end_clean();
}
define('DB_HOST', getenv('DB_HOST') ?: ($__dbOverride['host'] ?? '127.0.0.1'));
define('DB_PORT', getenv('DB_PORT') ?: ($__dbOverride['port'] ?? '3306'));
define('DB_NAME', getenv('DB_NAME') ?: ($__dbOverride['name'] ?? 'moneywise'));
define('DB_USER', getenv('DB_USER') ?: ($__dbOverride['user'] ?? 'root'));
define('DB_PASS', getenv('DB_PASS') ?: ($__dbOverride['pass'] ?? ''));
// DB_SSL=1 enables an encrypted connection (required by providers like Aiven).
define('DB_SSL', getenv('DB_SSL') ?: ($__dbOverride['ssl'] ?? '0'));
define('DB_CA', getenv('DB_CA') ?: ($__dbOverride['ca'] ?? ''));
unset($__dbOverride);

define('APP_VERSION', '1.0.0');

/**
 * Security response headers (M3).
 * HSTS is only sent over HTTPS (D1 — production must terminate TLS).
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
send_security_headers();

/* -------------------------------------------------------------------------
 * CORS for the Capacitor mobile app.
 *
 * Capacitor WebViews always load the app from a local origin:
 *   Android -> https://localhost     iOS -> capacitor://localhost
 * and the API lives on a remote HTTPS host, so those origins must be allowed
 * to call it. Tokens are sent via the `Authorization` header (never cookies),
 * so the API stays usable from any configured origin while cookie sessions
 * continue to work unchanged for the same-origin web app.
 * ---------------------------------------------------------------------- */

function send_cors(): void
{
    if (headers_sent()) {
        return;
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $allowed = array_filter(array_map('trim', explode(',', getenv('CORS_ORIGINS') ?: '')));
        $allowed = array_merge($allowed, [
            'https://localhost',        // Android WebView
            'capacitor://localhost',    // iOS WebView
            'http://localhost',
            'http://localhost:8100',    // `npx cap serve` local dev
            'http://localhost:3000',
        ]);
        if (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
    }

    // Preflight requests are handled here before any endpoint logic runs.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
send_cors();

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            if (DB_SSL === '1') {
                $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                if (DB_CA !== '') {
                    $opts[PDO::MYSQL_ATTR_SSL_CA] = DB_CA;
                }
            }
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            json_out(['ok' => false, 'error' => 'Database connection failed. Run install.php first.'], 500);
        }
    }
    return $pdo;
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    static $data = null;
    if ($data === null) {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            $data = $_POST;
        }
    }
    return $data;
}

function param(string $key, $default = null)
{
    $b = body();
    return $b[$key] ?? $_GET[$key] ?? $default;
}

/**
 * Start a session with hardened cookie flags (M1):
 * HttpOnly, SameSite=Lax always; Secure only over HTTPS (so localhost HTTP still works).
 */
function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (ini_get('session.use_strict_mode') !== '1') {
        @ini_set('session.use_strict_mode', '1');
    }
    session_start();
}

/* -------------------------------------------------------------------------
 * Mobile API token authentication (used by the Capacitor app).
 *
 * Tokens are long random strings (64 hex chars). Only a SHA-256 hash is
 * stored server-side, so a leaked database never reveals live tokens.
 * A token is valid for TOKEN_LIFETIME_DAYS unless revoked at logout.
 * ---------------------------------------------------------------------- */
const TOKEN_LIFETIME_DAYS = 180;

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    // Apache may strip Authorization when PHP runs as CGI/FastCGI.
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', trim($h), $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Parse a UTC datetime (possibly with microseconds) into a float epoch.
 * Returns 0.0 on failure. Handles both 'Y-m-d H:i:s' and 'Y-m-d H:i:s.u'.
 */
function utc_micros(string $s): float
{
    if (strpos($s, '.') === false) {
        $s .= '.000000';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $s, new DateTimeZone('UTC'));
    if ($dt === false) {
        return 0.0;
    }
    return (float)$dt->format('U.u');
}

/**
 * Mint a new API token for a user. Returns the raw token (shown once),
 * stores only its SHA-256 hash. Timestamps are stored as UTC with
 * microsecond precision so the password-changed check below can order two
 * events that happen within the same second (e.g. login then password change).
 */
function issue_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, created_at, expires_at)
         VALUES (?, ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? DAY))'
    )->execute([$userId, hash('sha256', $token), TOKEN_LIFETIME_DAYS]);
    return $token;
}

function revoke_token(string $token): void
{
    try {
        db()->prepare('UPDATE api_tokens SET revoked = 1 WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
    } catch (Throwable $e) {
        // ignore
    }
}

function current_user(): ?array
{
    start_secure_session();
    if (empty($_SESSION['user_id'])) {
        return current_user_by_token();
    }
    $st = db()->prepare('SELECT id, name, gender, email, is_admin, password_changed_at, dash_balance_cleared FROM users WHERE id = ?');
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    if (!$u) {
        return null;
    }
    // L2: a session is only valid if it was created after the last password change.
    // password_changed_at is stored as UTC (UTC_TIMESTAMP in profile.php); parse
    // it as UTC so the comparison is timezone-independent.
    $authTime = (float)($_SESSION['auth_time'] ?? 0);
    if (!empty($u['password_changed_at'])) {
        $changed = utc_micros($u['password_changed_at']);
        if ($changed > 0 && ($authTime === 0 || $changed > $authTime)) {
            return null;
        }
    }
    return $u;
}

/**
 * Token fallback used when the request has no session cookie (the mobile app).
 * Returns the user only for a non-revoked, non-expired token issued after the
 * user's last password change; otherwise null.
 */
function current_user_by_token(): ?array
{
    $token = bearer_token();
    if (!$token) {
        return null;
    }
    try {
        $st = db()->prepare('SELECT user_id, created_at, expires_at, revoked FROM api_tokens WHERE token_hash = ?');
        $st->execute([hash('sha256', $token)]);
        $t = $st->fetch();
        if (!$t || (int)$t['revoked'] === 1 || strtotime($t['expires_at'] . ' UTC') <= time()) {
            return null;
        }
        $st = db()->prepare('SELECT id, name, gender, email, is_admin, password_changed_at, dash_balance_cleared FROM users WHERE id = ?');
        $st->execute([$t['user_id']]);
        $u = $st->fetch();
        if (!$u) {
            return null;
        }
        // A password change invalidates every token issued before it.
        // Microsecond precision means a token minted earlier in the SAME second
        // as the password change is still correctly rejected.
        $issued = utc_micros($t['created_at']);
        if (!empty($u['password_changed_at'])) {
            $changed = utc_micros($u['password_changed_at']);
            if ($changed > 0 && ($issued <= 0 || $changed > $issued)) {
                return null;
            }
        }
        db()->prepare('UPDATE api_tokens SET last_used_at = UTC_TIMESTAMP() WHERE token_hash = ?')
            ->execute([hash('sha256', $token)]);
        return $u;
    } catch (Throwable $e) {
        return null;
    }
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        json_out(['ok' => false, 'error' => 'Not authenticated.'], 401);
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ((int)$u['is_admin'] !== 1) {
        json_out(['ok' => false, 'error' => 'Admin access required.'], 403);
    }
    return $u;
}

function clean(string $s, int $max = 0): string
{
    $s = trim($s);
    if ($max > 0 && mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max);
    }
    return $s;
}

/**
 * Strict scalar string: rejects arrays/objects/bools/null (L4) by returning ''.
 */
function scalar_string($v, int $max = 0): string
{
    return is_string($v) ? clean($v, $max) : '';
}

function valid_date(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}

/* -------------------------------------------------------------------------
 * Login / registration rate limiting (M2, L1).
 * Persisted in the `auth_attempts` table. All helpers fail open (no block)
 * if the table or database is unavailable, so the app never breaks.
 * ---------------------------------------------------------------------- */

function client_ip(): string
{
    // Only trust the peer address — never X-Forwarded-For (spoofable).
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function rate_limit_hit(string $scope, ?string $key, string $ip, int $max, int $windowMin): bool
{
    try {
        $since = date('Y-m-d H:i:s', time() - $windowMin * 60);
        if ($key === null || $key === '*') {
            $st = db()->prepare('SELECT COUNT(*) FROM auth_attempts WHERE scope = ? AND ip = ? AND attempt_time > ?');
            $st->execute([$scope, $ip, $since]);
        } else {
            $st = db()->prepare('SELECT COUNT(*) FROM auth_attempts WHERE scope = ? AND email = ? AND ip = ? AND attempt_time > ?');
            $st->execute([$scope, $key, $ip, $since]);
        }
        return (int)$st->fetchColumn() >= $max;
    } catch (Throwable $e) {
        return false;
    }
}

function rate_limit_record(string $scope, string $key, string $ip): void
{
    if (mt_rand(1, 50) === 1) {
        try {
            db()->prepare('DELETE FROM auth_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute();
        } catch (Throwable $e) {
            // ignore
        }
    }
    try {
        db()->prepare('INSERT INTO auth_attempts (scope, email, ip, attempt_time) VALUES (?, ?, ?, ?)')
            ->execute([$scope, $key, $ip, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // ignore
    }
}

function rate_limit_clear(string $scope, string $key, string $ip): void
{
    try {
        db()->prepare('DELETE FROM auth_attempts WHERE scope = ? AND email = ? AND ip = ?')
            ->execute([$scope, $key, $ip]);
    } catch (Throwable $e) {
        // ignore
    }
}
