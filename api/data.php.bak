<?php
/**
 * Data management endpoint — "Delete All Data" (Settings module).
 *
 * Permanently removes ALL user-owned data across every module:
 *   - transactions (+ expense_items cascade, cleared_items cascade)
 *   - categories
 *   - salaries
 *   - salary_history
 *   - events (+ event_expenses cascade)
 *   - ai_conversations (+ ai_messages cascade)
 *   - resets the "balance cleared" flag
 *
 * The user account itself is NEVER deleted.
 *
 * POST api/data.php  { "action": "delete_all" }
 *   -> { ok, deleted: { transactions, categories, salaries, salary_history, events, ai_conversations } }
 */
declare(strict_types=1);

// Read the action BEFORE require_login() so body()'s static cache doesn't eat it.
$rawBody = file_get_contents('php://input');
$bodyData = json_decode($rawBody ?: '', true);
$action = '';
if (is_array($bodyData) && isset($bodyData['action'])) {
    $action = trim((string)$bodyData['action']);
}
if ($action === '' && isset($_POST['action'])) {
    $action = trim((string)$_POST['action']);
}

require_once __DIR__ . '/config.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

if ($action !== 'delete_all') {
    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$pdo = db();
$uid = (int)$u['id'];
$deleted = [];

// 1. Transactions (cascades to expense_items + cleared_items via FK ON DELETE CASCADE)
$st = $pdo->prepare('DELETE FROM transactions WHERE user_id = ?');
$st->execute([$uid]);
$deleted['transactions'] = $st->rowCount();

// 2. Categories
$st = $pdo->prepare('DELETE FROM categories WHERE user_id = ?');
$st->execute([$uid]);
$deleted['categories'] = $st->rowCount();

// 3. Salaries
$st = $pdo->prepare('DELETE FROM salaries WHERE user_id = ?');
$st->execute([$uid]);
$deleted['salaries'] = $st->rowCount();

// 4. Salary history
$st = $pdo->prepare('DELETE FROM salary_history WHERE user_id = ?');
$st->execute([$uid]);
$deleted['salary_history'] = $st->rowCount();

// 5. Events (cascades to event_expenses via FK ON DELETE CASCADE)
$st = $pdo->prepare('DELETE FROM events WHERE user_id = ?');
$st->execute([$uid]);
$deleted['events'] = $st->rowCount();

// 6. AI conversations (cascades to ai_messages via FK ON DELETE CASCADE)
$st = $pdo->prepare('DELETE FROM ai_conversations WHERE user_id = ?');
$st->execute([$uid]);
$deleted['ai_conversations'] = $st->rowCount();

// 7. Reset the "balance cleared" flag
$pdo->prepare('UPDATE users SET dash_balance_cleared = 0 WHERE id = ?')->execute([$uid]);

json_out(['ok' => true, 'deleted' => $deleted]);