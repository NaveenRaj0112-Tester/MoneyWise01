<?php
/**
 * Event Expense Management API.
 *
 * Events and their expenses are stored in dedicated tables (events +
 * event_expenses) and belong to a single user. The Events feature is a SEPARATE
 * module: its expenses live only in the event tables and are NEVER written to
 * the `transactions` table, so they do NOT appear on the Dashboard, the
 * overall expense/income reports, Statistics, or Recent Transactions. All event
 * spending is viewed and reported exclusively inside the Events module.
 *
 * Every read/write validates ownership on the backend (WHERE user_id = ?) and
 * uses prepared statements only.
 *
 * GET   api/events.php?action=list&type=&name=&month=&year=
 *        -> { events: [{ id, event_name, event_type, event_date, location,
 *                       description, budget, expense_count, total_expense }],
 *             availableYears }
 * GET   api/events.php?action=get&id=N
 *        -> { event: {...}, expenses: [...], total, count, itemTotals,
 *             paymentTotals, remaining, budgetExceeded }
 * POST  action=create        { event_name, event_type, event_date, location, description, budget }
 * POST  action=update        { id, ...   }
 * POST  action=delete        { id }
 * POST  action=add_expense   { event_id, expense_item, amount, paid_to, payment_method, expense_date, expense_time, notes }
 * POST  action=update_expense{ id, event_id, ... }
 * POST  action=delete_expense{ id }
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$u = require_login();
$method = $_SERVER['REQUEST_METHOD'];
$action = scalar_string(param('action', ''));

const EVENT_TYPES = [
    'Marriage', 'Birthday', 'Housewarming', 'Travel', 'Festival', 'Party',
    'Function', 'Medical', 'Education', 'Shopping', 'Business', 'Other',
];
// Granular payment methods for event expenses (stored as display strings).
const EVENT_PAYMENT_METHODS = [
    'Cash', 'UPI', 'GPay', 'PhonePe', 'Bank Transfer', 'Debit Card', 'Credit Card', 'Other',
];

function ev_list(array $u, string $type, string $name, int $month, int $year): void
{
    $where = ['e.user_id = ?'];
    $args  = [$u['id']];

    if ($type !== '') {
        $where[] = 'e.event_type = ?';
        $args[]  = $type;
    }
    if ($name !== '') {
        $where[] = 'e.event_name LIKE ?';
        $args[]  = '%' . $name . '%';
    }
    if ($year >= 1970 && $year <= 9999) {
        if ($month >= 1 && $month <= 12) {
            $start  = sprintf('%04d-%02d-01', $year, $month);
            $end    = date('Y-m-d', strtotime($start . ' +1 month'));
            $where[] = 'e.event_date >= ?';
            $args[]  = $start;
            $where[] = 'e.event_date < ?';
            $args[]  = $end;
        } else {
            $where[] = 'e.event_date >= ?';
            $args[]  = sprintf('%04d-01-01', $year);
            $where[] = 'e.event_date < ?';
            $args[]  = sprintf('%04d-01-01', $year + 1);
        }
    }

    $sql = 'SELECT e.id, e.event_name, e.event_type, e.event_date, e.location, e.description, e.budget, e.enable_tanglish,
                   COUNT(x.id) AS expense_count, COALESCE(SUM(x.amount), 0) AS total_expense
            FROM events e
            LEFT JOIN event_expenses x ON x.event_id = e.id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY e.id
            ORDER BY e.event_date DESC, e.id DESC';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['expense_count'] = (int)$r['expense_count'];
        $r['total_expense'] = (float)$r['total_expense'];
        $r['budget']        = $r['budget'] === null ? null : (float)$r['budget'];
        $r['remaining']     = $r['budget'] === null ? null : round($r['budget'] - $r['total_expense'], 2);
        $r['enable_tanglish'] = (int)$r['enable_tanglish'] === 1;
    }
    unset($r);

    $ySt = db()->prepare('SELECT DISTINCT YEAR(event_date) AS y FROM events WHERE user_id = ? AND event_date IS NOT NULL ORDER BY y DESC');
    $ySt->execute([$u['id']]);
    $years = array_map('intval', array_column($ySt->fetchAll(), 'y'));

    json_out(['ok' => true, 'events' => $rows, 'availableYears' => $years]);
}

function ev_get(array $u, int $id): void
{
    $st = db()->prepare('SELECT id, event_name, event_type, event_date, location, description, budget, enable_tanglish FROM events WHERE id = ? AND user_id = ?');
    $st->execute([$id, $u['id']]);
    $ev = $st->fetch();
    if (!$ev) {
        json_out(['ok' => false, 'error' => 'Event not found.'], 404);
    }
    $ev['budget'] = $ev['budget'] === null ? null : (float)$ev['budget'];
    $ev['enable_tanglish'] = (int)$ev['enable_tanglish'] === 1;

    $xst = db()->prepare(
        'SELECT id, event_id, expense_item, amount, paid_to, payment_method,
                expense_date, expense_time, notes
         FROM event_expenses WHERE event_id = ? AND user_id = ? ORDER BY expense_date DESC, id DESC'
    );
    $xst->execute([$id, $u['id']]);
    $rows = $xst->fetchAll();
    foreach ($rows as &$r) {
        $r['amount']  = (float)$r['amount'];
        $r['expense_time'] = $r['expense_time'] ?? null;
    }
    unset($r);

    $total = 0.0;
    $itemTotals = [];
    $paymentTotals = [];
    foreach ($rows as $r) {
        $total += $r['amount'];
        $itemTotals[$r['expense_item']] = round(($itemTotals[$r['expense_item']] ?? 0) + $r['amount'], 2);
        $p = $r['payment_method'] ?: 'Other';
        $paymentTotals[$p] = round(($paymentTotals[$p] ?? 0) + $r['amount'], 2);
    }
    arsort($itemTotals);
    arsort($paymentTotals);

    $remaining = ($ev['budget'] !== null) ? round($ev['budget'] - $total, 2) : null;

    json_out([
        'ok'          => true,
        'event'       => $ev,
        'expenses'    => $rows,
        'total'       => round($total, 2),
        'count'       => count($rows),
        'itemTotals'  => $itemTotals,
        'paymentTotals' => $paymentTotals,
        'remaining'   => $remaining,
        'budgetExceeded' => ($ev['budget'] !== null && $total > $ev['budget']),
    ]);
}

function clean_event_fields($raw): array
{
    $name = scalar_string($raw['event_name'] ?? '', 120);
    $type = scalar_string($raw['event_type'] ?? '', 40);
    $dateRaw = scalar_string($raw['event_date'] ?? '');
    $location = scalar_string($raw['location'] ?? '', 150);
    $description = scalar_string($raw['description'] ?? '', 500);
    $budgetRaw = $raw['budget'] ?? '';

    if ($name === '') {
        json_out(['ok' => false, 'error' => 'Please enter the event name.'], 422);
    }
    // event_type can be a standard type from the dropdown OR a free-form custom
    // type entered when the user picks "Other" (e.g. "My Family Custom Event").
    if ($type === '') {
        json_out(['ok' => false, 'error' => 'Please select an event type.'], 422);
    }
    if ($dateRaw === '') {
        json_out(['ok' => false, 'error' => 'Please select the event date.'], 422);
    }
    if ($dateRaw !== '' && !valid_date($dateRaw)) {
        json_out(['ok' => false, 'error' => 'Please select a valid event date.'], 422);
    }
    $budget = null;
    if (is_numeric($budgetRaw) && (float)$budgetRaw > 0) {
        $budget = round((float)$budgetRaw, 2);
        if ($budget > 1000000000) {
            json_out(['ok' => false, 'error' => 'Budget amount is too large.'], 422);
        }
    }

    return [
        'event_name'  => $name,
        'event_type'  => $type,
        'event_date'  => $dateRaw === '' ? null : $dateRaw,
        'location'    => $location === '' ? null : $location,
        'description' => $description === '' ? null : $description,
        'budget'      => $budget,
        'enable_tanglish' => (isset($raw['enable_tanglish']) && ((int)$raw['enable_tanglish'] === 1 || $raw['enable_tanglish'] === true || $raw['enable_tanglish'] === '1')) ? 1 : 0,
    ];
}

function clean_expense_fields($raw): array
{
    $item  = scalar_string($raw['expense_item'] ?? '', 120);
    $amount  = is_numeric($raw['amount'] ?? 0) ? (float)$raw['amount'] : 0.0;
    $paidTo  = scalar_string($raw['paid_to'] ?? '', 120);
    $method  = scalar_string($raw['payment_method'] ?? 'Cash', 20);
    $dateRaw = scalar_string($raw['expense_date'] ?? '');
    $timeRaw = scalar_string($raw['expense_time'] ?? '');
    $notes   = scalar_string($raw['notes'] ?? '', 500);

    if ($item === '') {
        json_out(['ok' => false, 'error' => 'Please enter what you spent the money on.'], 422);
    }
    if (!is_finite($amount) || $amount <= 0 || $amount > 1000000000) {
        json_out(['ok' => false, 'error' => 'Amount must be greater than ₹0.'], 422);
    }
    if ($paidTo === '') {
        json_out(['ok' => false, 'error' => 'Please enter who received the payment.'], 422);
    }
    if (!in_array($method, EVENT_PAYMENT_METHODS, true)) {
        $method = 'Cash';
    }
    // Default date = current server date; allow the user to override it.
    if ($dateRaw === '' ) {
        $dateRaw = date('Y-m-d');
    }
    if (!valid_date($dateRaw)) {
        json_out(['ok' => false, 'error' => 'Please select a valid expense date.'], 422);
    }
    $time = (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $timeRaw)) ? substr($timeRaw, 0, 5) : null;

    return [
        'expense_item'  => $item,
        'amount'        => round($amount, 2),
        'paid_to'       => $paidTo,
        'payment_method'=> $method,
        'expense_date'  => $dateRaw,
        'expense_time'  => $time,
        'notes'         => $notes === '' ? null : $notes,
    ];
}

/**
 * Assert an event belongs to the caller; returns the event id.
 */
function require_event(int $eventId, int $userId): void
{
    $st = db()->prepare('SELECT id FROM events WHERE id = ? AND user_id = ?');
    $st->execute([$eventId, $userId]);
    if (!$st->fetch()) {
        json_out(['ok' => false, 'error' => 'Event not found.'], 404);
    }
}

/* ------------------------------------------------------------------------- */

if ($method === 'GET') {
    if ($action === 'get') {
        $id = (int)param('id', 0);
        if ($id <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid event id.'], 422);
        }
        ev_get($u, $id);
    }
    // default: list
    $type = scalar_string(param('type', ''), 40);
    $name = scalar_string(param('name', ''), 120);
    $month = is_numeric(param('month', 0)) ? (int)param('month', 0) : 0;
    $year  = is_numeric(param('year', 0)) ? (int)param('year', 0) : 0;
    ev_list($u, $type, $name, $month, $year);
}

if ($method === 'POST') {
    if ($action === 'create') {
        $f = clean_event_fields(body());
        $st = db()->prepare(
            'INSERT INTO events (user_id, event_name, event_type, event_date, location, description, budget, enable_tanglish)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$u['id'], $f['event_name'], $f['event_type'], $f['event_date'], $f['location'], $f['description'], $f['budget'], $f['enable_tanglish']]);
        json_out(['ok' => true, 'id' => (int)db()->lastInsertId(), 'message' => 'Event created successfully!'], 201);
    }

    if ($action === 'update') {
        $id = (int)param('id', 0);
        if ($id <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid event id.'], 422);
        }
        require_event($id, $u['id']);
        $f = clean_event_fields(body());
        $st = db()->prepare(
            'UPDATE events SET event_name = ?, event_type = ?, event_date = ?, location = ?, description = ?, budget = ?, enable_tanglish = ?
             WHERE id = ? AND user_id = ?'
        );
        $st->execute([$f['event_name'], $f['event_type'], $f['event_date'], $f['location'], $f['description'], $f['budget'], $f['enable_tanglish'], $id, $u['id']]);
        json_out(['ok' => true, 'message' => 'Event updated successfully!']);
    }

    if ($action === 'delete') {
        $id = (int)param('id', 0);
        if ($id <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid event id.'], 422);
        }
        require_event($id, $u['id']);
        // Deleting the event cascades to its event_expenses rows (FK).
        $st = db()->prepare('DELETE FROM events WHERE id = ? AND user_id = ?');
        $st->execute([$id, $u['id']]);
        if ($st->rowCount() === 0) {
            json_out(['ok' => false, 'error' => 'Event not found.'], 404);
        }
        json_out(['ok' => true, 'message' => 'Event deleted successfully!']);
    }

    if ($action === 'add_expense') {
        $eventId = (int)param('event_id', 0);
        if ($eventId <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid event id.'], 422);
        }
        require_event($eventId, $u['id']);
        $f = clean_expense_fields(body());
        $st = db()->prepare(
            'INSERT INTO event_expenses (event_id, user_id, expense_item, amount, paid_to, payment_method, expense_date, expense_time, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$eventId, $u['id'], $f['expense_item'], $f['amount'], $f['paid_to'], $f['payment_method'], $f['expense_date'], $f['expense_time'], $f['notes']]);
        $expId = (int)db()->lastInsertId();
        json_out(['ok' => true, 'id' => $expId, 'message' => 'Expense added successfully!'], 201);
    }

    if ($action === 'update_expense') {
        $id = (int)param('id', 0);
        $eventId = (int)param('event_id', 0);
        if ($id <= 0 || $eventId <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid expense id.'], 422);
        }
        require_event($eventId, $u['id']);
        $f = clean_expense_fields(body());
        $st = db()->prepare(
            'UPDATE event_expenses SET expense_item = ?, amount = ?, paid_to = ?, payment_method = ?, expense_date = ?, expense_time = ?, notes = ?
             WHERE id = ? AND event_id = ? AND user_id = ?'
        );
        $st->execute([$f['expense_item'], $f['amount'], $f['paid_to'], $f['payment_method'], $f['expense_date'], $f['expense_time'], $f['notes'], $id, $eventId, $u['id']]);
        if ($st->rowCount() === 0) {
            $chk = db()->prepare('SELECT id FROM event_expenses WHERE id = ? AND event_id = ? AND user_id = ?');
            $chk->execute([$id, $eventId, $u['id']]);
            if (!$chk->fetch()) {
                json_out(['ok' => false, 'error' => 'Expense not found.'], 404);
            }
        }
        json_out(['ok' => true, 'message' => 'Expense updated successfully!']);
    }

    if ($action === 'delete_expense') {
        $id = (int)param('id', 0);
        if ($id <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid expense id.'], 422);
        }
        $st = db()->prepare('DELETE FROM event_expenses WHERE id = ? AND user_id = ?');
        $st->execute([$id, $u['id']]);
        if ($st->rowCount() === 0) {
            json_out(['ok' => false, 'error' => 'Expense not found.'], 404);
        }
        json_out(['ok' => true, 'message' => 'Expense deleted successfully!']);
    }

    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
