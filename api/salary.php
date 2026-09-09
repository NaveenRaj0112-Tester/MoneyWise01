<?php
/**
 * Salary API: employee list, set salary, history
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$u = require_login();

$action = param('action', '');

switch ($action) {

    // Admin: all employees with current salary
    case 'list':
        require_admin();
        $st = db()->query(
            'SELECT u.id, u.name, u.email, s.amount, s.month, s.year
             FROM users u
             LEFT JOIN salaries s ON s.user_id = u.id
             WHERE u.is_admin = 0
             ORDER BY u.name'
        );
        $rows = array_map(function ($r) {
            $r['amount'] = $r['amount'] !== null ? (float)$r['amount'] : null;
            return $r;
        }, $st->fetchAll());
        json_out(['ok' => true, 'employees' => $rows]);

    // Any logged-in user: own current salary
    case 'mine':
        $st = db()->prepare('SELECT amount, month, year FROM salaries WHERE user_id = ?');
        $st->execute([$u['id']]);
        $s = $st->fetch();
        json_out(['ok' => true, 'salary' => $s ? ['amount' => (float)$s['amount'], 'month' => (int)$s['month'], 'year' => (int)$s['year']] : null]);

    // Admin: salary history (optional filters: emp, month, year)
    case 'history':
        require_admin();
        $sql  = 'SELECT h.id, h.user_id AS userId, h.employee_name AS employeeName,
                        h.old_salary AS oldSalary, h.new_salary AS newSalary,
                        h.month, h.year, h.updated_at AS updatedAt
                 FROM salary_history h WHERE 1=1';
        $args = [];

        $emp = scalar_string(param('emp', ''));
        if ($emp !== '' && $emp !== 'all') {
            if (!is_numeric($emp)) {
                json_out(['ok' => false, 'error' => 'Invalid employee filter.'], 422);
            }
            $sql .= ' AND h.user_id = ?';
            $args[] = (int)$emp;
        }
        $month = scalar_string(param('month', ''));
        if ($month !== '' && $month !== 'all') {
            if (!is_numeric($month)) {
                json_out(['ok' => false, 'error' => 'Invalid month filter.'], 422);
            }
            $sql .= ' AND h.month = ?';
            $args[] = (int)$month;
        }
        $year = scalar_string(param('year', ''));
        if ($year !== '' && $year !== 'all') {
            if (!is_numeric($year)) {
                json_out(['ok' => false, 'error' => 'Invalid year filter.'], 422);
            }
            $sql .= ' AND h.year = ?';
            $args[] = (int)$year;
        }
        $sql .= ' ORDER BY h.updated_at DESC, h.id DESC';

        $st = db()->prepare($sql);
        $st->execute($args);
        $rows = array_map(function ($r) {
            $r['oldSalary'] = (float)$r['oldSalary'];
            $r['newSalary'] = (float)$r['newSalary'];
            return $r;
        }, $st->fetchAll());

        // Available years for the filter dropdown
        $years = db()->query('SELECT DISTINCT year FROM salary_history ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN);

        json_out(['ok' => true, 'history' => $rows, 'years' => $years]);

    // Admin: set / update employee salary (archives old salary to history)
    case 'set':
        require_admin();
        $userIdRaw = param('userId', 0);
        $amountRaw = param('amount', 0);
        $monthRaw  = param('month', 0);
        $yearRaw   = param('year', 0);

        $userId = is_numeric($userIdRaw) ? (int)$userIdRaw : 0;
        $amount = is_numeric($amountRaw) ? (float)$amountRaw : 0.0;
        $month  = is_numeric($monthRaw) ? (int)$monthRaw : 0;
        $year   = is_numeric($yearRaw) ? (int)$yearRaw : 0;

        if (!is_numeric($userIdRaw) || $userId <= 0) {
            json_out(['ok' => false, 'error' => 'Invalid employee.'], 422);
        }
        if (!is_numeric($amountRaw) || !is_finite($amount) || $amount <= 0 || $amount > 1000000000) {
            json_out(['ok' => false, 'error' => 'Please enter a valid salary amount.'], 422);
        }
        if (!is_numeric($monthRaw) || $month < 1 || $month > 12) {
            json_out(['ok' => false, 'error' => 'Invalid month.'], 422);
        }
        if (!is_numeric($yearRaw) || $year < 2000 || $year > 2100) {
            json_out(['ok' => false, 'error' => 'Invalid year.'], 422);
        }

        $st = db()->prepare('SELECT name FROM users WHERE id = ? AND is_admin = 0');
        $st->execute([$userId]);
        $emp = $st->fetch();
        if (!$emp) {
            json_out(['ok' => false, 'error' => 'Employee not found.'], 404);
        }

        $st = db()->prepare('SELECT amount, month, year FROM salaries WHERE user_id = ?');
        $st->execute([$userId]);
        $cur = $st->fetch();

        $oldAmount = $cur ? (float)$cur['amount'] : 0.0;

        // Archive to history whenever there is an existing salary OR the record differs
        if ($cur && (float)$cur['amount'] !== $amount || ($cur && ($cur['month'] !== $month || $cur['year'] !== $year))) {
            $st = db()->prepare(
                'INSERT INTO salary_history (user_id, employee_name, old_salary, new_salary, month, year)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$userId, $emp['name'], $oldAmount, $amount, $month, $year]);
        } elseif (!$cur) {
            $st = db()->prepare(
                'INSERT INTO salary_history (user_id, employee_name, old_salary, new_salary, month, year)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$userId, $emp['name'], $oldAmount, $amount, $month, $year]);
        }

        $st = db()->prepare(
            'INSERT INTO salaries (user_id, amount, month, year) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), month = VALUES(month), year = VALUES(year)'
        );
        $st->execute([$userId, $amount, $month, $year]);

        json_out(['ok' => true, 'message' => 'Salary updated for ' . $emp['name'] . '!']);

    default:
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}
