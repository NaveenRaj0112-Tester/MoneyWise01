<?php
/**
 * App Update API — admin-only delta ZIP upload, compare, and apply.
 *
 * Actions (POST JSON { "action": "..." }):
 *   upload   — upload a .zip, extract to tmp/, return session id
 *   compare  — compare extracted ZIP against live app, return changeset
 *   apply    — apply the changeset (backup + overwrite)
 *   history  — list past updates
 *   rollback — restore files from a backup
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$u = require_admin();
$action = body()['action'] ?? $_POST['action'] ?? '';

/* ── Helpers ────────────────────────────────────────────────────────────── */

function app_root(): string { return dirname(__DIR__); }
function tmp_dir(): string  { $d = app_root() . '/tmp'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function backup_dir(): string { $d = app_root() . '/backups'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function downloads_dir(): string { $d = 'C:\Users\Magdyn Pc\Downloads'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }

/** Paths that must NEVER be overwritten by an update. */
function protected_paths(): array {
    return [
        'db-config.php',
        '.git',
        '.gitignore',
        '.dockerignore',
        'tmp',
        'backups',
        'cache',
        'storage',
        '.env',
        'php_error.log',
        'docker-compose.yml',
        'Dockerfile',
        'docker-entrypoint.sh',
        'render.yaml',
        'start-server.bat',
        'api/app-update.php',
    ];
}

function is_protected(string $relPath): bool {
    $norm = str_replace('\\', '/', $relPath);
    $norm = preg_replace('#/+#', '/', trim($norm, '/'));
    foreach (protected_paths() as $p) {
        if ($norm === $p || str_starts_with($norm, $p . '/')) return true;
    }
    return false;
}

/** Normalize and validate a path — blocks zip-slip traversal. */
function safe_rel_path(string $raw): ?string {
    $norm = str_replace('\\', '/', $raw);
    $norm = preg_replace('#/+#', '/', trim($norm, '/'));
    if ($norm === '' || str_starts_with($norm, '/') || str_starts_with($norm, '..') || str_contains($norm, '/../') || str_contains($norm, '/..')) {
        return null;
    }
    return $norm;
}

/* ── Action: upload ─────────────────────────────────────────────────────── */
if ($action === 'upload') {
    if (!class_exists('ZipArchive')) {
        json_out(['ok' => false, 'error' => 'Server ZIP extension not installed. Contact server admin.'], 500);
    }

    if (empty($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['zip']['error'] ?? -1;
        json_out(['ok' => false, 'error' => 'File upload failed (error code ' . $errCode . ').'], 400);
    }

    $f = $_FILES['zip'];
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'zip') {
        json_out(['ok' => false, 'error' => 'Only .zip files are allowed.'], 400);
    }

    if ($f['size'] > 200 * 1024 * 1024) {
        json_out(['ok' => false, 'error' => 'File too large (max 200 MB).'], 400);
    }

    // Create a session directory for this upload
    $sessionId = 'upd_' . time() . '_' . bin2hex(random_bytes(4));
    $sessionDir = tmp_dir() . '/' . $sessionId;
    @mkdir($sessionDir, 0755, true);

    $zipPath = $sessionDir . '/upload.zip';
    if (!move_uploaded_file($f['tmp_name'], $zipPath)) {
        json_out(['ok' => false, 'error' => 'Failed to save uploaded file.'], 500);
    }

    // Save a copy to uploads/delta-zips/ for reference
    $savedName = date('Y-m-d_H-i-s') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $f['name']);
    @copy($zipPath, downloads_dir() . '/' . $savedName);

    // Extract
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        @unlink($zipPath);
        @rmdir($sessionDir);
        json_out(['ok' => false, 'error' => 'Invalid or corrupted ZIP file.'], 400);
    }

    $extractDir = $sessionDir . '/extracted';
    @mkdir($extractDir, 0755, true);
    $zip->extractTo($extractDir);
    $zip->close();

    // Build file list with validation
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) continue;
        $fullPath = $item->getPathname();
        $relPath = substr($fullPath, strlen($extractDir) + 1);
        $safePath = safe_rel_path($relPath);
        if ($safePath === null) {
            json_out(['ok' => false, 'error' => "Rejected: path traversal detected in ZIP entry: {$relPath}"], 400);
        }
        if (is_protected($safePath)) continue; // silently skip protected files

        $files[] = [
            'path' => $safePath,
            'size' => $item->getSize(),
            'hash' => hash_file('sha256', $fullPath),
        ];
    }

    // Save session metadata
    file_put_contents($sessionDir . '/meta.json', json_encode([
        'files' => $files,
        'uploaded_at' => date('c'),
        'admin_id' => $u['id'],
    ]));

    json_out([
        'ok' => true,
        'session_id' => $sessionId,
        'file_count' => count($files),
        'files' => $files,
    ]);
}

/* ── Action: compare ────────────────────────────────────────────────────── */
if ($action === 'compare') {
    $sessionId = body()['session_id'] ?? '';
    $sessionDir = tmp_dir() . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $sessionId);
    $metaFile = $sessionDir . '/meta.json';

    if (!$sessionId || !is_file($metaFile)) {
        json_out(['ok' => false, 'error' => 'Invalid or expired session.'], 400);
    }

    $meta = json_decode(file_get_contents($metaFile), true);
    $extractDir = $sessionDir . '/extracted';
    $root = app_root();

    $changeset = ['add' => [], 'replace' => [], 'skip' => []];

    foreach ($meta['files'] as $f) {
        $rel = $f['path'];
        $livePath = $root . '/' . $rel;
        $zipPath = $extractDir . '/' . $rel;

        if (!is_file($livePath)) {
            $changeset['add'][] = ['path' => $rel, 'size' => $f['size'], 'hash' => $f['hash']];
        } else {
            $liveHash = hash_file('sha256', $livePath);
            if ($liveHash === $f['hash']) {
                $changeset['skip'][] = ['path' => $rel, 'reason' => 'identical'];
            } else {
                $changeset['replace'][] = [
                    'path' => $rel,
                    'size_new' => $f['size'],
                    'hash_new' => $f['hash'],
                    'hash_live' => $liveHash,
                ];
            }
        }
    }

    // Cache changeset for the apply step
    file_put_contents($sessionDir . '/changeset.json', json_encode($changeset));

    json_out([
        'ok' => true,
        'session_id' => $sessionId,
        'changeset' => $changeset,
        'summary' => [
            'to_add' => count($changeset['add']),
            'to_replace' => count($changeset['replace']),
            'to_skip' => count($changeset['skip']),
        ],
    ]);
}

/* ── Action: apply ──────────────────────────────────────────────────────── */
if ($action === 'apply') {
    $sessionId = body()['session_id'] ?? '';
    $sessionDir = tmp_dir() . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $sessionId);
    $changesetFile = $sessionDir . '/changeset.json';
    $metaFile = $sessionDir . '/meta.json';

    if (!$sessionId || !is_file($changesetFile)) {
        json_out(['ok' => false, 'error' => 'Invalid or expired session. Compare first.'], 400);
    }

    // Prevent concurrent updates
    $lockFile = tmp_dir() . '/update.lock';
    if (is_file($lockFile)) {
        $lockAge = time() - (int)filemtime($lockFile);
        if ($lockAge < 300) { // 5-minute TTL
            json_out(['ok' => false, 'error' => 'Another update is in progress. Try again later.'], 409);
        }
    }
    @file_put_contents($lockFile, (string)$u['id']);

    $changeset = json_decode(file_get_contents($changesetFile), true);
    $meta = json_decode(file_get_contents($metaFile), true);
    $extractDir = $sessionDir . '/extracted';
    $root = app_root();

    $ts = date('Y-m-d_H-i-s');
    $bakDir = backup_dir() . '/update_' . $ts;
    @mkdir($bakDir, 0755, true);

    $applied = ['replaced' => 0, 'added' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    $allFiles = array_merge($changeset['replace'] ?? [], $changeset['add'] ?? []);

    foreach ($allFiles as $f) {
        $rel = $f['path'];
        $src = $extractDir . '/' . $rel;
        $dst = $root . '/' . $rel;

        try {
            // Backup existing file before overwrite
            if (is_file($dst)) {
                $bakSub = dirname($rel);
                $bakPath = $bakDir . '/' . $rel;
                @mkdir(dirname($bakPath), 0755, true);
                @copy($dst, $bakPath);
            }

            // Ensure destination directory exists
            @mkdir(dirname($dst), 0755, true);

            // Copy new file
            if (!@copy($src, $dst)) {
                $applied['failed']++;
                $applied['errors'][] = "Failed to write: {$rel}";
                continue;
            }

            if (is_file($dst) && !isset($f['hash_live'])) {
                $applied['added']++;
            } else {
                $applied['replaced']++;
            }
        } catch (\Throwable $e) {
            $applied['failed']++;
            $applied['errors'][] = "Exception on {$rel}: " . $e->getMessage();
        }
    }

    $applied['skipped'] = count($changeset['skip'] ?? []);

    // Record in database
    try {
        $pdo = db();
        $st = $pdo->prepare('INSERT INTO app_updates (admin_user_id, admin_name, version_from, version_to, files_replaced, files_added, files_skipped, files_failed, backup_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $u['id'],
            $u['name'] ?? 'admin',
            constant('APP_VERSION'),
            body()['version_to'] ?? null,
            $applied['replaced'],
            $applied['added'],
            $applied['skipped'],
            $applied['failed'],
            $bakDir,
            $applied['failed'] > 0 ? 'failed' : 'applied',
        ]);
    } catch (\Throwable $e) {
        // DB logging failure is non-fatal
    }

    // Cleanup
    @unlink($lockFile);

    json_out([
        'ok' => $applied['failed'] === 0,
        'summary' => $applied,
        'backup_path' => $bakDir,
    ]);
}

/* ── Action: history ────────────────────────────────────────────────────── */
if ($action === 'history') {
    try {
        $pdo = db();
        $rows = $pdo->query('SELECT id, admin_name, version_from, version_to, files_replaced, files_added, files_skipped, files_failed, status, created_at FROM app_updates ORDER BY created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        json_out(['ok' => true, 'updates' => $rows]);
    } catch (\Throwable $e) {
        json_out(['ok' => true, 'updates' => []]);
    }
}

/* ── Action: rollback ───────────────────────────────────────────────────── */
if ($action === 'rollback') {
    $updateId = (int)(body()['update_id'] ?? 0);
    if ($updateId <= 0) json_out(['ok' => false, 'error' => 'Invalid update id.'], 400);

    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT backup_path, status FROM app_updates WHERE id = ?');
        $st->execute([$updateId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) json_out(['ok' => false, 'error' => 'Update not found.'], 404);
        if ($row['status'] === 'rolled_back') json_out(['ok' => false, 'error' => 'Already rolled back.']);
        if (!$row['backup_path'] || !is_dir($row['backup_path'])) {
            json_out(['ok' => false, 'error' => 'Backup directory not found. Cannot rollback.'], 404);
        }
    } catch (\Throwable $e) {
        json_out(['ok' => false, 'error' => 'Database error.'], 500);
    }

    $root = app_root();
    $bakDir = $row['backup_path'];
    $restored = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($bakDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) continue;
        $rel = substr($item->getPathname(), strlen($bakDir) + 1);
        $dst = $root . '/' . $rel;
        @mkdir(dirname($dst), 0755, true);
        if (@copy($item->getPathname(), $dst)) $restored++;
    }

    try {
        $pdo->prepare('UPDATE app_updates SET status = ? WHERE id = ?')->execute(['rolled_back', $updateId]);
    } catch (\Throwable $e) { /* non-fatal */ }

    json_out(['ok' => true, 'restored' => $restored]);
}

json_out(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
