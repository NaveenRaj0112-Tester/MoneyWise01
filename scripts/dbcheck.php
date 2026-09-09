<?php
/**
 * Standalone DB connectivity check used by docker-entrypoint.sh.
 * Mirrors the connection settings in api/config.php (incl. optional SSL).
 * Exit 0 on success, 1 + error message on failure.
 */
declare(strict_types=1);

$__dbOverride = [];
if (is_file(__DIR__ . '/../db-config.php')) {
    ob_start(); // discard any accidental BOM/whitespace
    $__dbOverride = (array)require __DIR__ . '/../db-config.php';
    ob_end_clean();
}
$host = getenv('DB_HOST') ?: ($__dbOverride['host'] ?? '127.0.0.1');
$port = getenv('DB_PORT') ?: ($__dbOverride['port'] ?? '3306');
$name = getenv('DB_NAME') ?: ($__dbOverride['name'] ?? 'moneywise');
$user = getenv('DB_USER') ?: ($__dbOverride['user'] ?? 'root');
$pass = getenv('DB_PASS') ?: ($__dbOverride['pass'] ?? '');
$ssl  = getenv('DB_SSL') ?: ($__dbOverride['ssl'] ?? '0');
$ca   = getenv('DB_CA') ?: ($__dbOverride['ca'] ?? '');
unset($__dbOverride);

try {
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    if ($ssl === '1' || strtolower((string)$ssl) === 'true') {
        // Encrypt the connection; skip cert verification so a CA file is optional.
        $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        if ($ca) {
            $opts[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        }
    }
    new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opts);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'DB check failed: ' . $e->getMessage() . "\n");
    exit(1);
}
