<?php
/**
 * MoneyWise FinanceData service.
 *
 * Predefined, read-only financial query functions for the AI Financial Assistant.
 * Every function:
 *   - forces the caller's authenticated user id (never trusts a client-supplied id),
 *   - uses prepared statements only (no string interpolation of values),
 *   - runs behind the shared db() connection (strict mode).
 *
 * The AI layer is deliberately NOT given arbitrary SQL — it can only compose
 * answers from these structured results.
 */
declare(strict_types=1);

if (!function_exists('fi_transaction_total')) {

    /**
     * Aggregate helper: SUM / COUNT / AVG / MIN / MAX for one user within a date
     * range and (optionally) a transaction type. Returns 0 values when no rows.
     */
    function fi_aggregate(int $userId, ?string $start, ?string $end, string $type): array
    {
        $where = ['user_id = ?'];
        $args  = [$userId];
        if ($start !== null) {
            $where[] = 'tdate >= ?';
            $args[]  = $start;
        }
        if ($end !== null) {
            $where[] = 'tdate < ?';
            $args[]  = $end;
        }
        if ($type === 'income' || $type === 'expense') {
            $where[] = 'type = ?';
            $args[]  = $type;
        }
        $st = db()->prepare(
            'SELECT COALESCE(SUM(amount),0) AS total,
                    COUNT(*) AS count,
                    COALESCE(AVG(amount),0) AS avg,
                    COALESCE(MAX(amount),0) AS max,
                    COALESCE(MIN(amount),0) AS min
             FROM transactions WHERE ' . implode(' AND ', $where)
        );
        $st->execute($args);
        return map_float($st->fetch() ?: []);
    }

    /** Total amount spent (expenses) for a user in a date range. */
    function fi_spent(int $userId, ?string $start, ?string $end): float
    {
        return fi_aggregate($userId, $start, $end, 'expense')['total'];
    }

    /** Total income received by a user in a date range. */
    function fi_income(int $userId, ?string $start, ?string $end): float
    {
        return fi_aggregate($userId, $start, $end, 'income')['total'];
    }

    /** Income by category for a user in a date range, highest first. */
    function fi_income_by_category(int $userId, ?string $start, ?string $end): array
    {
        return fi_category_totals($userId, $start, $end, 'income');
    }

    /** Expenses grouped by category for a user in a date range, highest first. */
    function fi_category_totals(int $userId, ?string $start, ?string $end, string $type = 'expense'): array
    {
        $where = ['user_id = ?'];
        $args  = [$userId];
        if ($start !== null) {
            $where[] = 'tdate >= ?';
            $args[]  = $start;
        }
        if ($end !== null) {
            $where[] = 'tdate < ?';
            $args[]  = $end;
        }
        $where[] = 'type = ?';
        $args[]  = $type === 'income' ? 'income' : 'expense';
        $st = db()->prepare(
            'SELECT category, ROUND(SUM(amount),2) AS total, COUNT(*) AS count
             FROM transactions WHERE ' . implode(' AND ', $where) . '
             GROUP BY category ORDER BY total DESC'
        );
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = ['category' => $r['category'], 'total' => (float)$r['total'], 'count' => (int)$r['count']];
        }
        return $rows;
    }

    /** Expense transactions for a user in a date range, most recent first, capped. */
    function fi_recent_transactions(int $userId, ?string $start, ?string $end, int $limit = 10): array
    {
        $limit = max(1, min(25, $limit));
        $where = ['user_id = ?'];
        $args  = [$userId];
        if ($start !== null) {
            $where[] = 'tdate >= ?';
            $args[]  = $start;
        }
        if ($end !== null) {
            $where[] = 'tdate < ?';
            $args[]  = $end;
        }
        $args[] = $limit;
        $st = db()->prepare(
            'SELECT id, type, amount, category, notes, tdate
             FROM transactions WHERE ' . implode(' AND ', $where) . '
             ORDER BY tdate DESC, id DESC LIMIT ?'
        );
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'id'       => (int)$r['id'],
                'type'     => $r['type'],
                'amount'   => (float)$r['amount'],
                'category' => $r['category'],
                'notes'    => $r['notes'],
                'date'     => $r['tdate'],
            ];
        }
        return $rows;
    }

    /** All transactions (any type) matching an exact category, most recent first. */
    function fi_transactions_by_category(int $userId, string $category, int $limit = 10): array
    {
        $limit = max(1, min(25, $limit));
        $st = db()->prepare(
            'SELECT id, type, amount, category, notes, tdate
             FROM transactions WHERE user_id = ? AND category = ?
             ORDER BY tdate DESC, id DESC LIMIT ?'
        );
        $st->execute([$userId, $category, $limit]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'id'       => (int)$r['id'],
                'type'     => $r['type'],
                'amount'   => (float)$r['amount'],
                'category' => $r['category'],
                'notes'    => $r['notes'],
                'date'     => $r['tdate'],
            ];
        }
        return $rows;
    }

    /** Recent transactions within a date range (either type), most recent first. */
    function fi_transactions_by_date(int $userId, string $start, string $end, int $limit = 15): array
    {
        $limit = max(1, min(25, $limit));
        $st = db()->prepare(
            'SELECT id, type, amount, category, notes, tdate
             FROM transactions WHERE user_id = ? AND tdate >= ? AND tdate <= ?
             ORDER BY tdate DESC, id DESC LIMIT ?'
        );
        $st->execute([$userId, $start, $end, $limit]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'id'       => (int)$r['id'],
                'type'     => $r['type'],
                'amount'   => (float)$r['amount'],
                'category' => $r['category'],
                'notes'    => $r['notes'],
                'date'     => $r['tdate'],
            ];
        }
        return $rows;
    }

    /** Net cashflow (income − expense) for a user in a date range. */
    function fi_net(int $userId, ?string $start, ?string $end): float
    {
        return round(fi_income($userId, $start, $end) - fi_spent($userId, $start, $end), 2);
    }

    /** Percentage change helper; guards against zero-dividend and sign ambiguity. */
    function fi_percent_change(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null; // null => "no previous data to compare"
        }
        return round(($current - $previous) / $previous * 100.0, 1);
    }

    /** The distinct years that contain transactions for a user (descending). */
    function fi_available_years(int $userId): array
    {
        $st = db()->prepare('SELECT DISTINCT YEAR(tdate) AS y FROM transactions WHERE user_id = ? ORDER BY y DESC');
        $st->execute([$userId]);
        return array_map('intval', array_column($st->fetchAll(), 'y'));
    }

    /** The user's configured salary, if set. */
    function fi_salary(int $userId): ?array
    {
        $st = db()->prepare('SELECT amount, month, year FROM salaries WHERE user_id = ?');
        $st->execute([$userId]);
        $s = $st->fetch();
        return $s
            ? ['amount' => (float)$s['amount'], 'month' => (int)$s['month'], 'year' => (int)$s['year']]
            : null;
    }

    /** Overall net balance (all income − all expense) — mirrors the Dashboard
     *  "Available Balance" when no period filter is applied. Never negative unless
     *  expenses really exceed income. */
    function fi_balance(int $userId): float
    {
        return round(fi_income($userId, null, null) - fi_spent($userId, null, null), 2);
    }

    /**
     * The latest 'YYYY-MM-01' month that actually contains transactions for the
     * user, or null when there are none. Used as the "effective current month":
     * relative “this month” questions report on REAL data even when the current
     * calendar month has no entries yet, instead of guessing ₹0.
     */
    function fi_latest_month_start(int $userId): ?string
    {
        $st = db()->prepare('SELECT DATE_FORMAT(MAX(tdate), "%Y-%m-01") AS m FROM transactions WHERE user_id = ?');
        $st->execute([$userId]);
        $m = $st->fetchColumn();
        return $m ? (string)$m : null;
    }

    /**
     * True when the user has at least one transaction in the half-open range
     * [start, end). Used to decide whether a relative period ("this month",
     * "this year") can be answered from the real calendar period, or whether we
     * must fall back to the most recent period that actually holds data.
     */
    function fi_has_rows(int $userId, ?string $start, ?string $end): bool
    {
        $where = ['user_id = ?'];
        $args  = [$userId];
        if ($start !== null) {
            $where[] = 'tdate >= ?';
            $args[]  = $start;
        }
        if ($end !== null) {
            $where[] = 'tdate < ?';
            $args[]  = $end;
        }
        $st = db()->prepare('SELECT 1 FROM transactions WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $st->execute($args);
        return (bool)$st->fetchColumn();
    }

    /**
     * The distinct category names the user actually has on record, with their
     * type and total. This is what lets the assistant resolve a typed subject
     * ("grocerys", "veg", "salry") against the user's REAL categories instead of
     * a hardcoded guess list.
     *
     * @return array<int, array{name:string,type:string,total:float,count:int}>
     */
    function fi_user_categories(int $userId): array
    {
        $st = db()->prepare(
            'SELECT category AS name, type, ROUND(SUM(amount),2) AS total, COUNT(*) AS count
             FROM transactions WHERE user_id = ?
             GROUP BY category, type ORDER BY total DESC'
        );
        $st->execute([$userId]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = [
                'name'  => (string)$r['name'],
                'type'  => (string)$r['type'],
                'total' => (float)$r['total'],
                'count' => (int)$r['count'],
            ];
        }
        return $out;
    }

    /**
     * Total for one exact category name (case-insensitive) within an optional
     * range. Used once a typed subject has been resolved to a real category, so
     * the number is an exact category total rather than a LIKE-search estimate.
     */
    function fi_exact_category_total(int $userId, string $category, string $type, ?string $start = null, ?string $end = null): array
    {
        $where = ['user_id = ?', 'type = ?', 'LOWER(category) = LOWER(?)'];
        $args  = [$userId, $type === 'income' ? 'income' : 'expense', $category];
        if ($start !== null) {
            $where[] = 'tdate >= ?';
            $args[]  = $start;
        }
        if ($end !== null) {
            $where[] = 'tdate < ?';
            $args[]  = $end;
        }
        $sql = 'FROM transactions WHERE ' . implode(' AND ', $where);
        $st  = db()->prepare('SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS count, COALESCE(MAX(amount),0) AS max ' . $sql);
        $st->execute($args);
        $agg = $st->fetch() ?: ['total' => 0, 'count' => 0, 'max' => 0];

        $st = db()->prepare('SELECT id, type, amount, category, notes, payee_name, tdate ' . $sql . ' ORDER BY tdate DESC, id DESC LIMIT 10');
        $st->execute($args);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'id'       => (int)$r['id'],
                'type'     => $r['type'],
                'amount'   => (float)$r['amount'],
                'category' => $r['category'],
                'notes'    => $r['notes'],
                'payee'    => $r['payee_name'],
                'date'     => $r['tdate'],
            ];
        }
        return [
            'total' => round((float)$agg['total'], 2),
            'count' => (int)$agg['count'],
            'max'   => round((float)$agg['max'], 2),
            'rows'  => $rows,
        ];
    }

    /** The single largest expense record (optionally within a range), or null. */
    function fi_top_expense_record(int $userId, ?string $start = null, ?string $end = null): ?array
    {
        $where = ['user_id = ?', 'type = "expense"'];
        $args  = [$userId];
        if ($start !== null) {
            $where[] = 'tdate >= ?';
            $args[]  = $start;
        }
        if ($end !== null) {
            $where[] = 'tdate < ?';
            $args[]  = $end;
        }
        $st = db()->prepare(
            'SELECT id, amount, category, notes, tdate FROM transactions
             WHERE ' . implode(' AND ', $where) . ' ORDER BY amount DESC, tdate DESC, id DESC LIMIT 1'
        );
        $st->execute($args);
        $r = $st->fetch();
        return $r ? [
            'id'       => (int)$r['id'],
            'amount'   => (float)$r['amount'],
            'category' => $r['category'],
            'notes'    => $r['notes'],
            'date'     => $r['tdate'],
        ] : null;
    }

    /** The latest year that contains transactions for the user, or null. */
    function fi_latest_year(int $userId): ?int
    {
        $st = db()->prepare('SELECT MAX(YEAR(tdate)) AS y FROM transactions WHERE user_id = ?');
        $st->execute([$userId]);
        $y = $st->fetchColumn();
        return $y ? (int)$y : null;
    }

    /** The earliest and latest transaction dates for the user (or nulls). */
    function fi_date_span(int $userId): array
    {
        $st = db()->prepare('SELECT MIN(tdate) AS mn, MAX(tdate) AS mx FROM transactions WHERE user_id = ?');
        $st->execute([$userId]);
        $r = $st->fetch();
        return [
            'earliest' => $r && $r['mn'] ? (string)$r['mn'] : null,
            'latest'   => $r && $r['mx'] ? (string)$r['mx'] : null,
        ];
    }

    /** The single largest expense for a user (matching a category/term if given). */
    function fi_max_expense(int $userId, ?string $term = null): ?float
    {
        if ($term !== null && $term !== '') {
            return fi_search_totals($userId, $term, 'expense')['max'] ?? null;
        }
        return fi_aggregate($userId, null, null, 'expense')['max'] ?: null;
    }

    /** The single smallest (non-zero) expense for a user. */
    function fi_min_expense(int $userId): ?float
    {
        $st = db()->prepare('SELECT MIN(amount) FROM transactions WHERE user_id = ? AND type = "expense" AND amount > 0');
        $st->execute([$userId]);
        $m = $st->fetchColumn();
        return $m !== null && $m !== false ? (float)$m : null;
    }

    /**
     * Search the user's expense/income records by a free-text term (category,
     * notes, payee_name). Returns a total, a count, and the matching rows.
     * Uses a prepared statement with bound LIKE (no interpolation).
     */
    function fi_search_records(int $userId, string $term, string $type = 'expense', int $limit = 10): array
    {
        $limit = max(1, min(25, $limit));
        $like  = '%' . $term . '%';
        $st = db()->prepare(
            'SELECT id, type, amount, category, notes, payee_name, tdate
             FROM transactions
             WHERE user_id = ? AND type = ?
               AND (LOWER(category) LIKE LOWER(?)
                    OR LOWER(COALESCE(notes, \'\')) LIKE LOWER(?)
                    OR LOWER(COALESCE(payee_name, \'\')) LIKE LOWER(?))
             ORDER BY tdate DESC, id DESC LIMIT ?'
        );
        $st->execute([$userId, $type, $like, $like, $like, $limit]);
        $rows  = [];
        $total = 0.0;
        $max   = 0.0;
        foreach ($st->fetchAll() as $r) {
            $amt = (float)$r['amount'];
            $total += $amt;
            if ($amt > $max) {
                $max = $amt;
            }
            $rows[] = [
                'id'       => (int)$r['id'],
                'type'     => $r['type'],
                'amount'   => $amt,
                'category' => $r['category'],
                'notes'    => $r['notes'],
                'payee'    => $r['payee_name'],
                'date'     => $r['tdate'],
            ];
        }
        return ['total' => round($total, 2), 'count' => count($rows), 'max' => round($max, 2), 'rows' => $rows];
    }

    /** Just the aggregated total/max/count for a search term. */
    function fi_search_totals(int $userId, string $term, string $type = 'expense'): array
    {
        $like = '%' . $term . '%';
        $st = db()->prepare(
            'SELECT COALESCE(SUM(amount),0) AS total,
                    COUNT(*) AS count,
                    COALESCE(MAX(amount),0) AS max
             FROM transactions
             WHERE user_id = ? AND type = ?
               AND (LOWER(category) LIKE LOWER(?)
                    OR LOWER(COALESCE(notes, \'\')) LIKE LOWER(?)
                    OR LOWER(COALESCE(payee_name, \'\')) LIKE LOWER(?))'
        );
        $st->execute([$userId, $type, $like, $like, $like]);
        $r = $st->fetch();
        return [
            'total' => round((float)$r['total'], 2),
            'count' => (int)$r['count'],
            'max'   => round((float)$r['max'], 2),
        ];
    }

    /**
     * Multi-term search: matches rows whose category/notes/payee contain ANY of
     * the given alias terms (unioned OR, all bound as parameters). Lets the AI
     * catch spelling and synonym variants of one concept (e.g. vegetable,
     * vegetables, veg, veggies) without double-counting. Returns total/count/max
     * plus the matching rows, restricted to the authenticated user and an
     * optional date range.
     */
    function fi_search_multi(int $userId, array $terms, string $type = 'expense', int $limit = 10, ?string $start = null, ?string $end = null): array
    {
        $terms = array_values(array_unique(array_filter(array_map('trim', $terms), fn($t) => $t !== '')));
        if (!$terms) {
            return ['total' => 0.0, 'count' => 0, 'max' => 0.0, 'rows' => []];
        }
        $limit = max(1, min(25, $limit));
        $cond  = [];
        $args  = [$userId, $type];
        foreach ($terms as $t) {
            $like  = '%' . $t . '%';
            $cond[] = '(LOWER(category) LIKE LOWER(?) OR LOWER(COALESCE(notes,\'\')) LIKE LOWER(?) OR LOWER(COALESCE(payee_name,\'\')) LIKE LOWER(?))';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }
        $wheres = ['user_id = ?', 'type = ?', '(' . implode(' OR ', $cond) . ')'];
        if ($start !== null) {
            $wheres[] = 'tdate >= ?';
            $args[]   = $start;
        }
        if ($end !== null) {
            $wheres[] = 'tdate < ?';
            $args[]   = $end;
        }
        $st = db()->prepare(
            'SELECT id, type, amount, category, notes, payee_name, tdate
             FROM transactions WHERE ' . implode(' AND ', $wheres) . ' ORDER BY tdate DESC, id DESC LIMIT ?'
        );
        $args[] = $limit;
        $st->execute($args);
        $rows  = [];
        $total = 0.0;
        $max   = 0.0;
        foreach ($st->fetchAll() as $r) {
            $amt = (float)$r['amount'];
            $total += $amt;
            if ($amt > $max) {
                $max = $amt;
            }
            $rows[] = [
                'id'       => (int)$r['id'],
                'type'     => $r['type'],
                'amount'   => $amt,
                'category' => $r['category'],
                'notes'    => $r['notes'],
                'payee'    => $r['payee_name'],
                'date'     => $r['tdate'],
            ];
        }
        return ['total' => round($total, 2), 'count' => count($rows), 'max' => round($max, 2), 'rows' => $rows];
    }

    /** All-time expense totals grouped by category (see fi_category_totals). */
    function fi_all_top_categories(int $userId, int $limit = 8, string $type = 'expense'): array
    {
        return array_slice(fi_category_totals($userId, null, null, $type), 0, $limit);
    }

    /**
     * Monthly spending/income series for the user, most recent first, across the
     * last `months` calendar months that occur at/after the earliest transaction.
     * Returns rows of { ym:'YYYY-MM', label, spent, income, count }. Used for
     * "which month did I spend the most", trends and monthly averages. Only real
     * stored records contribute — a month with no rows still yields 0 (it is a
     * genuine calendar month the user did not spend in).
     */
    function fi_monthly_series(int $userId, int $months = 12): array
    {
        $months = max(3, min(24, $months));
        $span   = fi_date_span($userId);
        $earliest = $span['earliest'] ? substr((string)$span['earliest'], 0, 7) : date('Y-m');
        $latest   = $span['latest'] ? substr((string)$span['latest'], 0, 7) : date('Y-m');
        // Never walk into the future: a trend, a monthly average or a "which
        // month did I spend the most" answer must describe months that have
        // actually happened, even when the account holds post-dated records.
        // (If EVERY record is post-dated we keep the latest so the series is
        // not empty.)
        $nowYm = date('Y-m');
        if ($latest > $nowYm && $earliest <= $nowYm) {
            $latest = $nowYm;
        }
        // Backwards from the latest data month, up to $months months.
        $cursor = $latest;
        $out    = [];
        for ($i = 0; $i < $months; $i++) {
            $start = $cursor . '-01';
            $end   = date('Y-m-01', strtotime($start . ' +1 month'));
            $goods = 'SELECT COALESCE(SUM(CASE WHEN type="expense" THEN amount ELSE 0 END),0) AS spent,
                             COALESCE(SUM(CASE WHEN type="income" THEN amount ELSE 0 END),0) AS income,
                             COUNT(*) AS cnt
                      FROM transactions WHERE user_id = ? AND tdate >= ? AND tdate < ?';
            $st = db()->prepare($goods);
            $st->execute([$userId, $start, $end]);
            $r = $st->fetch() ?: [];
            $out[] = [
                'ym'     => $cursor,
                'label'  => substr($cursor, 0, 7),
                'spent'  => round((float)($r['spent'] ?? 0), 2),
                'income' => round((float)($r['income'] ?? 0), 2),
                'count'  => (int)($r['cnt'] ?? 0),
            ];
            if ($cursor === $earliest) {
                break;
            }
            $cursor = date('Y-m', strtotime($start . ' -1 day'));
        }
        return $out;
    }

    /** Biggest individual expense records (amount desc), most recent first. */
    function fi_biggest_expenses(int $userId, int $limit = 6): array
    {
        $limit = max(1, min(12, $limit));
        $st = db()->prepare(
            'SELECT id, amount, category, notes, tdate FROM transactions
             WHERE user_id = ? AND type = "expense" ORDER BY amount DESC, tdate DESC, id DESC LIMIT ?'
        );
        $st->execute([$userId, $limit]);
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'id'       => (int)$r['id'],
                'amount'   => (float)$r['amount'],
                'category' => $r['category'],
                'notes'    => $r['notes'],
                'date'     => $r['tdate'],
            ];
        }
        return $rows;
    }

    /* ---------------- Events (isolated module) ---------------- */

    /** Number + total of the user's EVENT transactions (the isolated events module). */
    function fi_event_summary(int $userId, ?string $start = null, ?string $end = null): array
    {
        $where = ['e.user_id = ?'];
        $args  = [$userId];
        if ($start !== null) {
            $where[] = 'e.event_date >= ?';
            $args[]  = $start;
        }
        if ($end !== null) {
            $where[] = 'e.event_date <= ?';
            $args[]  = $end;
        }
        $st = db()->prepare(
            'SELECT COUNT(DISTINCT e.id) AS events,
                    COUNT(x.id) AS items,
                    COALESCE(SUM(x.amount),0) AS total
             FROM events e
             LEFT JOIN event_expenses x ON x.event_id = e.id AND x.user_id = e.user_id
             WHERE ' . implode(' AND ', $where)
        );
        $st->execute($args);
        $r = $st->fetch();
        return [
            'events' => (int)$r['events'],
            'items'  => (int)$r['items'],
            'total'  => round((float)$r['total'], 2),
        ];
    }

    /** Sum of event expenses grouped by event type (Marriage, Birthday, ...). */
    function fi_event_by_type(int $userId, ?string $start = null, ?string $end = null): array
    {
        $where = ['e.user_id = ?'];
        $args  = [$userId];
        if ($start !== null) {
            $where[] = 'e.event_date >= ?';
            $args[]  = $start;
        }
        if ($end !== null) {
            $where[] = 'e.event_date <= ?';
            $args[]  = $end;
        }
        $st = db()->prepare(
            'SELECT e.event_type AS type,
                    COALESCE(SUM(CASE WHEN x.id IS NOT NULL THEN x.amount END),0) AS total,
                    COUNT(DISTINCT e.id) AS events
             FROM events e
             LEFT JOIN event_expenses x ON x.event_id = e.id AND x.user_id = e.user_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY e.event_type ORDER BY total DESC'
        );
        $st->execute($args);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = ['type' => $r['type'], 'total' => round((float)$r['total'], 2), 'events' => (int)$r['events']];
        }
        return $out;
    }

    /** Total spent on events whose NAME or TYPE loosely matches a term. */
    function fi_event_search(int $userId, string $term): array
    {
        $like = '%' . $term . '%';
        $st = db()->prepare(
            'SELECT e.id, e.event_name, e.event_type, e.event_date,
                    COALESCE(SUM(x.amount),0) AS total,
                    COUNT(x.id) AS items
             FROM events e
             LEFT JOIN event_expenses x ON x.event_id = e.id AND x.user_id = e.user_id
             WHERE e.user_id = ?
               AND (LOWER(e.event_name) LIKE LOWER(?)
                    OR LOWER(e.event_type) LIKE LOWER(?))
             GROUP BY e.id ORDER BY e.event_date DESC'
        );
        $st->execute([$userId, $like, $like]);
        $rows = [];
        $total = 0.0;
        foreach ($st->fetchAll() as $r) {
            $amt = round((float)$r['total'], 2);
            $total += $amt;
            $rows[] = [
                'id'    => (int)$r['id'],
                'name'  => $r['event_name'],
                'type'  => $r['event_type'],
                'date'  => $r['event_date'],
                'total' => $amt,
                'items' => (int)$r['items'],
            ];
        }
        return ['total' => round($total, 2), 'count' => count($rows), 'rows' => $rows];
    }

    /** Cast every amount field to a float (decimals arrive as strings in PDO). */
    function map_float(array $row): array
    {
        foreach ($row as $k => $v) {
            $row[$k] = is_numeric($v) ? (float)$v : $v;
        }
        return $row;
    }
}