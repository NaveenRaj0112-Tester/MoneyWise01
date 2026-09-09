<?php
/**
 * Admin API: user management & viewing user financial records.
 * Every endpoint requires an admin session (server-side authorization).
 *
 * GET api/admin.php?action=users
 *   -> { ok, users: [{ id, name, email, is_admin }] }
 *
 * GET api/admin.php?action=view&userId=N&month=M&year=Y
 *   -> { ok, user: {id,name,email}, transactions: [...], summary: {...}, availableYears }
 *
 * GET api/admin.php?action=delete&userId=N
 *   -> { ok, message }  (removes the user + their transactions/salary records via cascade)
 *
 * Non-admin callers always receive 403 "Admin access required." and no data.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$u = require_admin();

$action = param('action', '');

switch ($action) {

    // Admin: list all users (id, name, email, role)
    case 'users':
        $st = db()->query('SELECT id, name, email, is_admin FROM users ORDER BY is_admin DESC, name');
        $rows = array_map(function ($r) {
            $r['is_admin'] = (int)$r['is_admin'];
            return $r;
        }, $st->fetchAll());
        json_out(['ok' => true, 'users' => $rows]);

    // Admin: view a specific user's transactions with month/year filters
    case 'view':
        $userId = (int)param('userId', 0);
        $month  = (int)param('month', 0);
        $year   = (int)param('year', 0);

        $st = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
        $st->execute([$userId]);
        $target = $st->fetch();
        if (!$target) {
            json_out(['ok' => false, 'error' => 'User not found.'], 404);
        }

        $where = ['user_id = ?'];
        $args  = [$userId];

        if ($month >= 1 && $month <= 12 && $year >= 1970 && $year <= 9999) {
            $start   = sprintf('%04d-%02d-01', $year, $month);
            $end     = date('Y-m-d', strtotime($start . ' +1 month'));
            $where[] = 'tdate >= ?'; $args[] = $start;
            $where[] = 'tdate < ?';  $args[] = $end;
        } elseif ($year >= 1970 && $year <= 9999) {
            $where[] = 'tdate >= ?'; $args[] = sprintf('%04d-01-01', $year);
            $where[] = 'tdate < ?';  $args[] = sprintf('%04d-01-01', $year + 1);
        } elseif ($month >= 1 && $month <= 12) {
            $where[] = 'MONTH(tdate) = ?'; $args[] = $month;
        }

        $sql = 'SELECT id, type, amount, category, category AS cat, notes, tdate AS date
                FROM transactions WHERE ' . implode(' AND ', $where) . ' ORDER BY tdate DESC, id DESC';
        $st = db()->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll();

        $totalIn = 0.0; $totalEx = 0.0; $incMap = []; $expMap = [];
        foreach ($rows as &$r) {
            $amt = (float)$r['amount'];
            $r['amount'] = $amt;
            if ($r['type'] === 'income') {
                $totalIn += $amt;
                $incMap[$r['category']] = round(($incMap[$r['category']] ?? 0) + $amt, 2);
            } else {
                $totalEx += $amt;
                $expMap[$r['category']] = round(($expMap[$r['category']] ?? 0) + $amt, 2);
            }
        }
        unset($r);
        ksort($incMap); ksort($expMap);

        $ySt = db()->prepare('SELECT DISTINCT YEAR(tdate) AS y FROM transactions WHERE user_id = ? ORDER BY y DESC');
        $ySt->execute([$userId]);
        $years = array_map('intval', array_column($ySt->fetchAll(), 'y'));

        json_out([
            'ok'             => true,
            'user'           => ['id' => (int)$target['id'], 'name' => $target['name'], 'email' => $target['email']],
            'transactions'   => $rows,
            'summary'        => [
                'income'       => round($totalIn, 2),
                'expense'      => round($totalEx, 2),
                'balance'      => round($totalIn - $totalEx, 2),
                'incomeByCat'  => $incMap,
                'expenseByCat' => $expMap,
            ],
            'availableYears' => $years,
        ]);

    // Admin: delete a user (cascade removes their transactions/salary records)
    case 'delete':
        $userId = (int)param('userId', 0);
        if ($userId <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid user.'], 422);
        }
        if ($userId === (int)$u['id']) {
            json_out(['ok' => false, 'error' => 'You cannot delete your own account.'], 422);
        }

        $st = db()->prepare('SELECT id, name, is_admin FROM users WHERE id = ?');
        $st->execute([$userId]);
        $target = $st->fetch();
        if (!$target) {
            json_out(['ok' => false, 'error' => 'User not found.'], 404);
        }
        if ((int)$target['is_admin'] === 1) {
            json_out(['ok' => false, 'error' => 'Admin accounts cannot be deleted.'], 422);
        }

        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        json_out(['ok' => true, 'message' => 'User deleted.']);

    default:
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}
