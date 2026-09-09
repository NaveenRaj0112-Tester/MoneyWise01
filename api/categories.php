<?php
/**
 * Categories API: built-in + per-user custom expense categories.
 *
 * GET  api/categories.php?type=expense|income   (default expense)
 *   -> { ok, categories: [{ id, name }, ...] }   built-in (user_id NULL) first, then this user's own
 *
 * POST { action:'add', name, type? }             add a custom category for the caller
 *   -> { ok, category: { id, name } }
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$u = require_login();
$method = $_SERVER['REQUEST_METHOD'];

function valid_cat_type(string $t): string
{
    return in_array($t, ['income', 'expense'], true) ? $t : 'expense';
}

if ($method === 'GET') {
    $type = valid_cat_type(scalar_string(param('type', 'expense')));
    $st = db()->prepare(
        'SELECT id, name FROM categories
         WHERE type = ? AND (user_id IS NULL OR user_id = ?)
         ORDER BY (user_id IS NULL) DESC, name'
    );
    $st->execute([$type, $u['id']]);
    json_out(['ok' => true, 'categories' => $st->fetchAll()]);
}

if ($method === 'POST') {
    $action = scalar_string(param('action', ''));
    if ($action !== 'add') {
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
    $type = valid_cat_type(scalar_string(param('type', 'expense')));
    $name = scalar_string(param('name', ''), 60);
    if ($name === '') {
        json_out(['ok' => false, 'error' => 'Please enter a category name.'], 422);
    }

    $st = db()->prepare('SELECT id FROM categories WHERE type = ? AND (user_id IS NULL OR user_id = ?) AND name = ?');
    $st->execute([$type, $u['id'], $name]);
    if ($st->fetch()) {
        json_out(['ok' => false, 'error' => 'That category already exists.'], 409);
    }

    $ins = db()->prepare('INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)');
    $ins->execute([$u['id'], $name, $type]);
    json_out(['ok' => true, 'category' => ['id' => (int)db()->lastInsertId(), 'name' => $name]], 201);
}

json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
