<?php
/**
 * Clear (persistent) API — hides entries from the Status module UI only.
 *
 * The original income/expense records in `transactions` are NEVER deleted or
 * modified. This endpoint only stores lightweight "hidden" markers (user_id,
 * tx_id) in the `cleared_items` table, so the cleared state survives page
 * refresh, browser reopen and logout/login while Reports / Daily / Monthly /
 * Yearly PDFs keep reading the untouched transaction data.
 *
 * GET  api/clear.php
 *   -> { ok, cleared: [txId, ...], balance_cleared: bool }
 *                        ids hidden for the current user + Dashboard balance flag
 *
 * POST { action: 'clear',   tx_ids: [...] }     mark entries as hidden
 *   -> { ok, cleared: [txId, ...] }             ids actually marked (owned only)
 *
 * POST { action: 'restore', tx_ids: [...] }     un-hide entries
 *   -> { ok, restored: [txId, ...] }
 *
 * POST { action: 'clear_balance' }              persist "balance erased" flag
 *   -> { ok, balance_cleared: true }
 *
 * POST { action: 'restore_balance' }            clear the "balance erased" flag
 *   -> { ok, balance_cleared: false }
 *
 * Authorization: a normal user may only mark records they own; an admin may
 * mark any record they are allowed to view (admin screen). Markers are always
 * scoped to the caller's own user_id, so one user's cleared state can never
 * affect another user's views. The balance flag is likewise per-user.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$u = require_login();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $st = db()->prepare('SELECT tx_id FROM cleared_items WHERE user_id = ?');
    $st->execute([$u['id']]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'tx_id'));
    json_out(['ok' => true, 'cleared' => $ids, 'balance_cleared' => (int)$u['dash_balance_cleared'] === 1]);
}

if ($method === 'POST') {
    $action = scalar_string(param('action', ''));
    $raw    = param('tx_ids', []);
    $ids    = [];
    if (is_array($raw)) {
        foreach ($raw as $id) {
            if (is_numeric($id)) {
                $i = (int)$id;
                if ($i > 0 && !in_array($i, $ids, true)) {
                    $ids[] = $i;
                }
            }
        }
    }

    if ($action === 'clear') {
        if (!$ids) {
            json_out(['ok' => true, 'cleared' => []]);
        }
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT id FROM transactions WHERE id IN (' . $in . ')';
        $args = $ids;
        if ((int)$u['is_admin'] !== 1) {
            $sql  .= ' AND user_id = ?';
            $args[] = $u['id'];
        }
        $st = db()->prepare($sql);
        $st->execute($args);
        $owned = array_map('intval', array_column($st->fetchAll(), 'id'));
        if ($owned) {
            $ins = db()->prepare('INSERT IGNORE INTO cleared_items (user_id, tx_id) VALUES (?, ?)');
            foreach ($owned as $tid) {
                $ins->execute([$u['id'], $tid]);
            }
        }
        json_out(['ok' => true, 'cleared' => $owned]);
    }

    if ($action === 'restore') {
        if (!$ids) {
            json_out(['ok' => true, 'restored' => []]);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare('DELETE FROM cleared_items WHERE user_id = ? AND tx_id IN (' . $in . ')')
            ->execute(array_merge([$u['id']], $ids));
        json_out(['ok' => true, 'restored' => $ids]);
    }

    if ($action === 'clear_balance') {
        db()->prepare('UPDATE users SET dash_balance_cleared = 1 WHERE id = ?')->execute([$u['id']]);
        json_out(['ok' => true, 'balance_cleared' => true]);
    }

    if ($action === 'restore_balance') {
        db()->prepare('UPDATE users SET dash_balance_cleared = 0 WHERE id = ?')->execute([$u['id']]);
        json_out(['ok' => true, 'balance_cleared' => false]);
    }

    json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}

json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
