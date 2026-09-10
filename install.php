<?php
/**
 * MoneyWise installer — run once:
 *   php install.php            (create tables + seed demo data if empty)
 *   php install.php reset      (drop everything and re-seed fresh demo data)
 * or open it in the browser.
 *
 * Safe to re-run: existing data is never deleted unless you pass "reset".
 */
declare(strict_types=1);

// DB connection, overridable via environment (Docker Compose / Render).
// Defaults match local XAMPP. A db-config.php in the project root (see
// db-config.example.php) overrides these for shared hosting like InfinityFree,
// where server env vars are not available.
$__dbOverride = [];
if (is_file(__DIR__ . '/db-config.php')) {
    ob_start(); // discard any accidental BOM/whitespace so header() calls below stay clean
    $__dbOverride = (array)require __DIR__ . '/db-config.php';
    ob_end_clean();
}
define('DB_HOST', getenv('DB_HOST') ?: ($__dbOverride['host'] ?? '127.0.0.1'));
define('DB_PORT', getenv('DB_PORT') ?: ($__dbOverride['port'] ?? '3306'));
define('DB_USER', getenv('DB_USER') ?: ($__dbOverride['user'] ?? 'root'));
define('DB_PASS', getenv('DB_PASS') ?: ($__dbOverride['pass'] ?? ''));
define('DB_NAME', getenv('DB_NAME') ?: ($__dbOverride['name'] ?? 'moneywise'));
define('DB_SSL', getenv('DB_SSL') ?: ($__dbOverride['ssl'] ?? '0'));
define('DB_CA', getenv('DB_CA') ?: ($__dbOverride['ca'] ?? ''));
// True when a production db-config.php is in use. Shared hosts (InfinityFree)
// pre-provision the database and never allow CREATE DATABASE, so install.php
// must connect straight to the existing database in that case.
$__isProductionConfig = (bool)$__dbOverride;
unset($__dbOverride);

header('Content-Type: text/plain; charset=utf-8');

$reset = ($argv[1] ?? '') === 'reset';

try {
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];
    if (DB_SSL === '1') {
        $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        if (DB_CA !== '') {
            $opts[PDO::MYSQL_ATTR_SSL_CA] = DB_CA;
        }
    }
    // Connect directly to the configured database. This is the ONLY path used
    // in production (db-config.php present), so no CREATE DATABASE/USE runs.
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4;dbname=' . DB_NAME, DB_USER, DB_PASS, $opts);
} catch (PDOException $e) {
    if (!$__isProductionConfig && strpos($e->getMessage(), 'Unknown database') !== false) {
        // Local XAMPP convenience: the `moneywise` database does not exist yet.
        // Connect without a database and create it. Never attempted for a
        // production db-config.php (hosting users lack CREATE DATABASE rights).
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, $opts);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . DB_NAME . '`');
        } catch (Throwable $e2) {
            die("Cannot connect to MySQL / create database '" . DB_NAME . "': " . $e2->getMessage() . "\n");
        }
    } else {
        die("Cannot connect to MySQL database '" . DB_NAME . "' at " . DB_HOST . ':' . DB_PORT . ': ' . $e->getMessage() . "\n");
    }
}

if ($reset) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['app_updates', 'ai_messages', 'ai_conversations', 'event_expenses', 'events', 'expense_items', 'categories', 'salary_history', 'salaries', 'cleared_items', 'transactions', 'api_tokens', 'auth_attempts', 'users'] as $t) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "[OK] Existing tables dropped (reset mode).\n";
}

// Create tables if they don't exist (never touches existing data)
$tables = file_get_contents(__DIR__ . '/database.sql');
$pdo->exec($tables);
echo "[OK] Database '" . DB_NAME . "' ready (tables exist).\n";

// ---- Idempotent migrations for pre-existing databases ----------------------
// v1.x -> v2: users.dash_balance_cleared (persistent Dashboard balance-erase flag).
$cols = $pdo->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "' . DB_NAME . '" AND TABLE_NAME = "users"')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('dash_balance_cleared', $cols, true)) {
    $pdo->exec('ALTER TABLE users ADD COLUMN dash_balance_cleared TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin');
    echo "[OK] Migration: users.dash_balance_cleared added.\n";
}

// v2 -> v3: microsecond-precision UTC timestamps so a token issued in the same
// second as a password change is still correctly invalidated (same-second race).
$pdo->exec('ALTER TABLE users MODIFY password_changed_at DATETIME(6) NULL');
$pdo->exec('ALTER TABLE api_tokens MODIFY created_at DATETIME(6) NOT NULL');
$pdo->exec('ALTER TABLE api_tokens MODIFY expires_at DATETIME(6) NOT NULL');
$pdo->exec('ALTER TABLE api_tokens MODIFY last_used_at DATETIME(6) NULL');
echo "[OK] Migration: datetime columns upgraded to DATETIME(6) (UTC microseconds).\n";

// v3 -> v4: UPI / payment-tracking metadata on transactions, plus expense_items
// and categories tables. All new columns are nullable or defaulted so existing
// rows and the mobile app (which never sends them) are unaffected.
$txCols = $pdo->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "' . DB_NAME . '" AND TABLE_NAME = "transactions"')->fetchAll(PDO::FETCH_COLUMN);
$txMigrations = [
    'payee_name'     => "ALTER TABLE transactions ADD COLUMN payee_name VARCHAR(120) NULL AFTER category",
    'upi_id'         => "ALTER TABLE transactions ADD COLUMN upi_id VARCHAR(150) NULL AFTER payee_name",
    'payment_method' => "ALTER TABLE transactions ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER upi_id",
    'ttime'          => "ALTER TABLE transactions ADD COLUMN ttime TIME NULL AFTER tdate",
    'txn_ref'        => "ALTER TABLE transactions ADD COLUMN txn_ref VARCHAR(60) NULL AFTER payment_method",
    'status'         => "ALTER TABLE transactions ADD COLUMN status VARCHAR(12) NOT NULL DEFAULT 'completed' AFTER txn_ref",
];
foreach ($txMigrations as $col => $sql) {
    if (!in_array($col, $txCols, true)) {
        $pdo->exec($sql);
        echo "[OK] Migration: transactions.$col added.\n";
    }
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS expense_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tx_id INT NOT NULL,
        product_name VARCHAR(120) NOT NULL,
        quantity DECIMAL(10,3) NOT NULL DEFAULT 1,
        unit VARCHAR(20) NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        total_price DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (tx_id) REFERENCES transactions(id) ON DELETE CASCADE,
        INDEX idx_ei_tx (tx_id)
    ) ENGINE=InnoDB'
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        name VARCHAR(60) NOT NULL,
        type ENUM(\'income\',\'expense\') NOT NULL DEFAULT \'expense\',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uq_cat (user_id, name, type)
    ) ENGINE=InnoDB'
);
echo "[OK] Migration: expense_items and categories tables ready.\n";

// v4 -> v5: Event expense management — a SEPARATE module. Two new tables
// (events + event_expenses). Event expenses live only in these tables and are
// never written to `transactions`, so they don't affect the Dashboard, overall
// reports, Statistics, or Recent Transactions.
$eventTables = [
    'events' => "CREATE TABLE IF NOT EXISTS events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        event_name VARCHAR(120) NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        event_date DATE NULL,
        location VARCHAR(150) NULL,
        description TEXT NULL,
        budget DECIMAL(12,2) NULL,
        enable_tanglish TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_ev_user (user_id, event_date)
    ) ENGINE=InnoDB",
    'event_expenses' => "CREATE TABLE IF NOT EXISTS event_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        user_id INT NOT NULL,
        expense_item VARCHAR(120) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        paid_to VARCHAR(120) NOT NULL,
        payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
        expense_date DATE NULL,
        expense_time TIME NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_ee_event (event_id),
        INDEX idx_ee_user (user_id, expense_date)
    ) ENGINE=InnoDB",
];
foreach ($eventTables as $tn => $sql) {
    $pdo->exec($sql);
    echo "[OK] Migration: $tn table ready.\n";
}

// v5 -> v6: per-event Tanglish->Tamil toggle (enable_tanglish). Default OFF so
// existing events and plain-English flows are completely unaffected.
$evCols = $pdo->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "' . DB_NAME . '" AND TABLE_NAME = "events"')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('enable_tanglish', $evCols, true)) {
    $pdo->exec('ALTER TABLE events ADD COLUMN enable_tanglish TINYINT(1) NOT NULL DEFAULT 0 AFTER budget');
    echo "[OK] Migration: events.enable_tanglish added.\n";
}

// Drop the now-unused mirror column (added by the earlier Option-B integration)
// so event expenses no longer leak into the `transactions`-based reports. Events
// are fully isolated in the events/event_expenses tables.
$txCols = $pdo->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "' . DB_NAME . '" AND TABLE_NAME = "transactions"')->fetchAll(PDO::FETCH_COLUMN);
if (in_array('event_expense_id', $txCols, true)) {
    $pdo->exec('ALTER TABLE transactions DROP COLUMN event_expense_id');
    echo "[OK] Migration: transactions.event_expense_id removed (events are now isolated).\n";
}

// v6 -> v7: AI Financial Assistant chat tables (conversations + messages).
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ai_conversations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(120) NOT NULL DEFAULT 'New chat',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_ai_conv_user (user_id, updated_at)
    ) ENGINE=InnoDB"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS ai_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT NOT NULL,
        user_id INT NOT NULL,
        role ENUM('user','assistant') NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_ai_msg_conv (conversation_id, id),
        INDEX idx_ai_msg_user (user_id)
    ) ENGINE=InnoDB"
);
echo "[OK] Migration: ai_conversations and ai_messages tables ready.\n";

$catCount = (int)$pdo->query('SELECT COUNT(*) FROM categories WHERE user_id IS NULL')->fetchColumn();
if ($catCount === 0) {
    $defaultCats = [
        'Vegetables', 'Groceries', 'Food', 'Restaurants', 'Transportation', 'Fuel',
        'Shopping', 'Medical', 'Electricity', 'Water', 'Rent', 'Education',
        'Entertainment', 'Mobile/Internet', 'Bills', 'Travel', 'Other',
    ];
    $catIns = $pdo->prepare('INSERT IGNORE INTO categories (user_id, name, type) VALUES (NULL, ?, \'expense\')');
    foreach ($defaultCats as $c) {
        $catIns->execute([$c]);
    }
    echo "[OK] Migration: default expense categories seeded.\n";
}

// ---- Seed demo data only when the users table is empty ---------------------
$count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count > 0) {
    echo "[SKIP] Users table already has $count row(s) — demo data left untouched.\n";
    echo "\nDone! Open the app:\n  http://localhost/MoneyManagement1/\n";
    exit;
}

$users = [
    ['Demo User',     'male',   'Admin0112@gmail.com',  'Admin@0112', 1],
    ['Priya Sharma',  'female', 'priya@example.com', 'pass123', 0],
    ['Rahul Verma',   'male',   'rahul@example.com', 'pass123', 0],
    ['Aisha Nair',    'female', 'aisha@example.com', 'pass123', 0],
];
$ins = $pdo->prepare('INSERT INTO users (name, gender, email, password, is_admin) VALUES (?, ?, ?, ?, ?)');
foreach ($users as $u) {
    $ins->execute([$u[0], $u[1], $u[2], password_hash($u[3], PASSWORD_DEFAULT), $u[4]]);
}
echo "[OK] Demo users seeded (Admin0112@gmail.com / Admin@0112, is Admin).\n";

$sal = $pdo->prepare('INSERT INTO salaries (user_id, amount, month, year) VALUES (?, ?, ?, ?)');
$sal->execute([2, 55000, 5, 2026]);
$sal->execute([3, 42000, 5, 2026]);
$sal->execute([4, 68000, 4, 2026]);
echo "[OK] Salaries seeded.\n";

$hist = $pdo->prepare(
    'INSERT INTO salary_history (user_id, employee_name, old_salary, new_salary, month, year, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$hist->execute([2, 'Priya Sharma', 48000, 55000, 5, 2026, '2026-05-01 09:00:00']);
$hist->execute([3, 'Rahul Verma',  38000, 42000, 5, 2026, '2026-05-01 09:15:00']);
$hist->execute([4, 'Aisha Nair',   60000, 68000, 4, 2026, '2026-04-01 10:00:00']);
$hist->execute([2, 'Priya Sharma', 44000, 48000, 4, 2026, '2026-04-01 09:30:00']);
echo "[OK] Salary history seeded.\n";

$tx = $pdo->prepare(
    'INSERT INTO transactions (user_id, type, amount, category, notes, tdate) VALUES (?, ?, ?, ?, ?, ?)'
);
$demoTxs = [
    ['income',   45000, 'Salary',       'Monthly salary',        '2026-05-01'],
    ['expense',   3200, 'Groceries',    'Monthly groceries',     '2026-05-03'],
    ['expense',   1500, 'Food',         'Zomato & restaurants',  '2026-05-06'],
    ['income',    8000, 'Freelance',    'Web project',           '2026-05-08'],
    ['expense',   2200, 'Bills',        'Electricity & internet', '2026-05-10'],
    ['expense',    800, 'Entertainment','Movie tickets',         '2026-05-14'],
];
foreach ($demoTxs as $t) {
    $tx->execute([1, $t[0], $t[1], $t[2], $t[3], $t[4]]);
}
echo "[OK] Demo transactions seeded.\n";

echo "\nDone! Open the app:\n  http://localhost/MoneyManagement1/\n";
