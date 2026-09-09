<?php
/**
 * Transactions API: list (with optional type/month/year filters), add, edit, delete
 * Only ever touches the logged-in user's own records.
 *
 * GET    api/transactions.php
 *          ?type=all|income|expense
 *          ?mode=all|monthly|yearly
 *          ?month=1..12
 *          ?year=YYYY
 *        -> { transactions: [...], summary: { total, count, average, categories }, availableYears }
 * POST   { type, amount, category, notes, date,
 *          payeeName?, upiId?, paymentMethod?, time?, txnRef?, status?,
 *          items?: [{name, qty, unit, unitPrice}], discount?, tax? }   add a record
 * PUT    { id, ...same fields as POST }                                edit a record
 * DELETE ?id=N        delete one record
 * DELETE (no id)      erase ALL of the user's records (Erase Balance)
 *
 * The UPI/payment-tracking fields (payeeName, upiId, paymentMethod, time, txnRef,
 * status, items) are all optional — a plain income/expense entry (including every
 * write from the mobile app, which never sends them) leaves them null/default.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$u = require_login();
$method = $_SERVER['REQUEST_METHOD'];

const PAYMENT_METHODS = ['cash', 'upi', 'card', 'other'];
const TXN_STATUSES    = ['completed', 'pending', 'failed'];

/**
 * Normalize + validate the optional item list from the request body.
 * Returns ['items' => [[name,qty,unit,unitPrice,total], ...], 'total' => float] or null if no items given.
 */
function parse_items($raw): ?array
{
    if (!is_array($raw) || !$raw) {
        return null;
    }
    $items = [];
    $total = 0.0;
    foreach ($raw as $it) {
        if (!is_array($it)) {
            continue;
        }
        $name = scalar_string($it['name'] ?? '', 120);
        $qtyRaw = $it['qty'] ?? 1;
        $unit = scalar_string($it['unit'] ?? '', 20);
        $priceRaw = $it['unitPrice'] ?? 0;
        $qty = is_numeric($qtyRaw) ? (float)$qtyRaw : 0.0;
        $price = is_numeric($priceRaw) ? (float)$priceRaw : 0.0;
        if ($name === '' || $qty <= 0 || $price < 0) {
            continue;
        }
        $lineTotal = round($qty * $price, 2);
        $items[] = ['name' => $name, 'qty' => $qty, 'unit' => $unit, 'unitPrice' => $price, 'total' => $lineTotal];
        $total += $lineTotal;
    }
    if (!$items) {
        return null;
    }
    return ['items' => $items, 'total' => round($total, 2)];
}

switch ($method) {

    case 'GET':
        $type  = scalar_string(param('type', 'all'));
        $mode  = scalar_string(param('mode', 'all'));
        $month = is_numeric(param('month', 0)) ? (int)param('month', 0) : 0;
        $year  = is_numeric(param('year', 0)) ? (int)param('year', 0) : 0;

        $where = ['user_id = ?'];
        $args  = [$u['id']];

        if ($type === 'income' || $type === 'expense') {
            $where[] = 'type = ?';
            $args[]  = $type;
        }

        if ($mode === 'monthly' && $month >= 1 && $month <= 12 && $year >= 1970 && $year <= 9999) {
            $start  = sprintf('%04d-%02d-01', $year, $month);
            $end    = date('Y-m-d', strtotime($start . ' +1 month'));
            $where[] = 'tdate >= ?';
            $args[]  = $start;
            $where[] = 'tdate < ?';
            $args[]  = $end;
        } elseif ($mode === 'yearly' && $year >= 1970 && $year <= 9999) {
            $where[] = 'tdate >= ?';
            $args[]  = sprintf('%04d-01-01', $year);
            $where[] = 'tdate < ?';
            $args[]  = sprintf('%04d-01-01', $year + 1);
        }

        $sql = 'SELECT id, type, amount, category, category AS cat, notes, tdate AS date,
                       payee_name AS payeeName, upi_id AS upiId, payment_method AS paymentMethod,
                       ttime AS time, txn_ref AS txnRef, status
                FROM transactions WHERE ' . implode(' AND ', $where) .
               ' ORDER BY tdate DESC, id DESC';
        $st = db()->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $r['amount'] = (float)$r['amount'];
            $r['items']  = [];
        }
        unset($r);

        // Attach item lines (one extra query, not N+1).
        if ($rows) {
            $ids = array_column($rows, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $ist = db()->prepare(
                "SELECT id, tx_id AS txId, product_name AS name, quantity AS qty, unit,
                        unit_price AS unitPrice, total_price AS total
                 FROM expense_items WHERE tx_id IN ($in) ORDER BY id"
            );
            $ist->execute($ids);
            $byTx = [];
            foreach ($ist->fetchAll() as $it) {
                $it['qty']       = (float)$it['qty'];
                $it['unitPrice'] = (float)$it['unitPrice'];
                $it['total']     = (float)$it['total'];
                $byTx[(int)$it['txId']][] = $it;
            }
            foreach ($rows as &$r) {
                $r['items'] = $byTx[(int)$r['id']] ?? [];
            }
            unset($r);
        }

        $total  = 0.0;
        $catMap = [];
        foreach ($rows as $r) {
            $total += $r['amount'];
            $catMap[$r['category']] = round(($catMap[$r['category']] ?? 0) + $r['amount'], 2);
        }
        ksort($catMap);
        $count = count($rows);
        $avg   = $count > 0 ? $total / $count : 0.0;

        $ySt = db()->prepare('SELECT DISTINCT YEAR(tdate) AS y FROM transactions WHERE user_id = ? ORDER BY y DESC');
        $ySt->execute([$u['id']]);
        $years = array_map('intval', array_column($ySt->fetchAll(), 'y'));

        json_out([
            'ok'              => true,
            'transactions'    => $rows,
            'summary'         => [
                'total'      => round($total, 2),
                'count'      => $count,
                'average'    => round($avg, 2),
                'categories' => $catMap,
            ],
            'availableYears'  => $years,
        ]);

    case 'POST':
        $typeRaw     = param('type', '');
        $amountRaw   = param('amount', 0);
        $categoryRaw = param('category', '');
        $notesRaw    = param('notes', '');
        $dateRaw     = param('date', '');

        $type     = scalar_string($typeRaw);
        $amount   = is_numeric($amountRaw) ? (float)$amountRaw : 0.0;
        $category = scalar_string($categoryRaw, 50);
        $notes    = scalar_string($notesRaw, 255);
        $date     = scalar_string($dateRaw);

        $payeeName = scalar_string(param('payeeName', ''), 120);
        $upiId     = scalar_string(param('upiId', ''), 150);
        $paymentMethod = scalar_string(param('paymentMethod', 'cash'), 20);
        if (!in_array($paymentMethod, PAYMENT_METHODS, true)) {
            $paymentMethod = 'cash';
        }
        $timeRaw = scalar_string(param('time', ''));
        $time = (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $timeRaw)) ? $timeRaw : null;
        $txnRef = scalar_string(param('txnRef', ''), 60);
        $status = scalar_string(param('status', 'completed'), 12);
        if (!in_array($status, TXN_STATUSES, true)) {
            $status = 'completed';
        }
        $discount = is_numeric(param('discount', 0)) ? (float)param('discount', 0) : 0.0;
        $tax      = is_numeric(param('tax', 0)) ? (float)param('tax', 0) : 0.0;
        $itemsParsed = parse_items(param('items', null));

        if (!in_array($type, ['income', 'expense'], true)) {
            json_out(['ok' => false, 'error' => 'Invalid transaction type.'], 422);
        }
        if ($itemsParsed !== null) {
            // Items are authoritative for the stored amount when supplied.
            $amount = round(max(0, $itemsParsed['total'] - $discount + $tax), 2);
        }
        if (!is_finite($amount) || $amount <= 0 || $amount > 1000000000) {
            json_out(['ok' => false, 'error' => 'Please enter a valid amount greater than 0.'], 422);
        }
        if ($category === '') {
            json_out(['ok' => false, 'error' => 'Please select a category.'], 422);
        }
        if (!valid_date($date)) {
            json_out(['ok' => false, 'error' => 'Please select a valid date.'], 422);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO transactions
                    (user_id, type, amount, category, notes, tdate,
                     payee_name, upi_id, payment_method, ttime, txn_ref, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $u['id'], $type, $amount, $category, $notes === '' ? null : $notes, $date,
                $payeeName === '' ? null : $payeeName, $upiId === '' ? null : $upiId,
                $paymentMethod, $time, $txnRef === '' ? null : $txnRef, $status,
            ]);
            $newId = (int)$pdo->lastInsertId();

            if ($itemsParsed !== null) {
                $ins = $pdo->prepare(
                    'INSERT INTO expense_items (tx_id, product_name, quantity, unit, unit_price, total_price)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($itemsParsed['items'] as $it) {
                    $ins->execute([$newId, $it['name'], $it['qty'], $it['unit'] === '' ? null : $it['unit'], $it['unitPrice'], $it['total']]);
                }
            }

            // Adding a transaction lifts any erased Dashboard balance (display-only flag).
            $pdo->prepare('UPDATE users SET dash_balance_cleared = 0 WHERE id = ?')->execute([$u['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'error' => 'Could not save the transaction.'], 500);
        }

        json_out(['ok' => true, 'id' => $newId, 'message' => ucfirst($type) . ' added successfully!'], 201);

    case 'PUT':
        $idRaw       = param('id', 0);
        $typeRaw     = param('type', '');
        $amountRaw   = param('amount', 0);
        $categoryRaw = param('category', '');
        $notesRaw    = param('notes', '');
        $dateRaw     = param('date', '');

        $id       = is_numeric($idRaw) ? (int)$idRaw : 0;
        $type     = scalar_string($typeRaw);
        $amount   = is_numeric($amountRaw) ? (float)$amountRaw : 0.0;
        $category = scalar_string($categoryRaw, 50);
        $notes    = scalar_string($notesRaw, 255);
        $date     = scalar_string($dateRaw);

        $payeeName = scalar_string(param('payeeName', ''), 120);
        $upiId     = scalar_string(param('upiId', ''), 150);
        $paymentMethod = scalar_string(param('paymentMethod', 'cash'), 20);
        if (!in_array($paymentMethod, PAYMENT_METHODS, true)) {
            $paymentMethod = 'cash';
        }
        $timeRaw = scalar_string(param('time', ''));
        $time = (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $timeRaw)) ? $timeRaw : null;
        $txnRef = scalar_string(param('txnRef', ''), 60);
        $status = scalar_string(param('status', 'completed'), 12);
        if (!in_array($status, TXN_STATUSES, true)) {
            $status = 'completed';
        }
        $discount = is_numeric(param('discount', 0)) ? (float)param('discount', 0) : 0.0;
        $tax      = is_numeric(param('tax', 0)) ? (float)param('tax', 0) : 0.0;
        $itemsParsed = parse_items(param('items', null));

        if (!is_numeric($idRaw) || $id <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid transaction id.'], 422);
        }
        if (!in_array($type, ['income', 'expense'], true)) {
            json_out(['ok' => false, 'error' => 'Invalid transaction type.'], 422);
        }
        if ($itemsParsed !== null) {
            $amount = round(max(0, $itemsParsed['total'] - $discount + $tax), 2);
        }
        if (!is_finite($amount) || $amount <= 0 || $amount > 1000000000) {
            json_out(['ok' => false, 'error' => 'Please enter a valid amount greater than 0.'], 422);
        }
        if ($category === '') {
            json_out(['ok' => false, 'error' => 'Please select a category.'], 422);
        }
        if (!valid_date($date)) {
            json_out(['ok' => false, 'error' => 'Please select a valid date.'], 422);
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'UPDATE transactions
                 SET type = ?, amount = ?, category = ?, notes = ?, tdate = ?,
                     payee_name = ?, upi_id = ?, payment_method = ?, ttime = ?, txn_ref = ?, status = ?
                 WHERE id = ? AND user_id = ?'
            );
            $st->execute([
                $type, $amount, $category, $notes === '' ? null : $notes, $date,
                $payeeName === '' ? null : $payeeName, $upiId === '' ? null : $upiId,
                $paymentMethod, $time, $txnRef === '' ? null : $txnRef, $status,
                $id, $u['id'],
            ]);
            if ($st->rowCount() === 0) {
                // Might be a no-op update (values unchanged) rather than "not found" —
                // confirm ownership before reporting an error.
                $chk = $pdo->prepare('SELECT id FROM transactions WHERE id = ? AND user_id = ?');
                $chk->execute([$id, $u['id']]);
                if (!$chk->fetch()) {
                    $pdo->rollBack();
                    json_out(['ok' => false, 'error' => 'Transaction not found.'], 404);
                }
            }

            // Items are always fully replaced on edit (simplest correct behaviour).
            $pdo->prepare('DELETE FROM expense_items WHERE tx_id = ?')->execute([$id]);
            if ($itemsParsed !== null) {
                $ins = $pdo->prepare(
                    'INSERT INTO expense_items (tx_id, product_name, quantity, unit, unit_price, total_price)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($itemsParsed['items'] as $it) {
                    $ins->execute([$id, $it['name'], $it['qty'], $it['unit'] === '' ? null : $it['unit'], $it['unitPrice'], $it['total']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['ok' => false, 'error' => 'Could not update the transaction.'], 500);
        }

        json_out(['ok' => true, 'message' => 'Transaction updated successfully!']);

    case 'DELETE':
        $idRaw = param('id', 0);
        $id    = is_numeric($idRaw) ? (int)$idRaw : 0;
        if ($id > 0) {
            $st = db()->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?');
            $st->execute([$id, $u['id']]);
            if ($st->rowCount() === 0) {
                json_out(['ok' => false, 'error' => 'Transaction not found.'], 404);
            }
            db()->prepare('DELETE FROM cleared_items WHERE user_id = ? AND tx_id = ?')->execute([$u['id'], $id]);
            json_out(['ok' => true, 'deleted' => 1, 'message' => 'Transaction deleted!']);
        }
        $st = db()->prepare('DELETE FROM transactions WHERE user_id = ?');
        $st->execute([$u['id']]);
        db()->prepare('DELETE FROM cleared_items WHERE user_id = ?')->execute([$u['id']]);
        json_out(['ok' => true, 'deleted' => $st->rowCount(), 'message' => 'Balance erased successfully!']);

    default:
        json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
