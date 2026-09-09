<?php
/**
 * Profile API: update name/email, change password
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$u   = require_login();
$row = json_decode(file_get_contents('php://input') ?: '', true) ?: $_POST;

$action = $row['action'] ?? '';

switch ($action) {

    case 'profile':
        $name   = scalar_string($row['name'] ?? '', 100);
        $gender = $row['gender'] ?? '';
        if (!is_string($gender) || !in_array($gender, ['male', 'female'], true)) {
            $gender = $u['gender'];
        }
        $email = scalar_string($row['email'] ?? '', 150);

        if ($name === '' || $email === '') {
            json_out(['ok' => false, 'error' => 'Name and email are required.'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['ok' => false, 'error' => 'Please enter a valid email address.'], 422);
        }

        $st = db()->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $st->execute([$email, $u['id']]);
        if ($st->fetch()) {
            json_out(['ok' => false, 'error' => 'Email already in use.'], 409);
        }

        $st = db()->prepare('UPDATE users SET name = ?, gender = ?, email = ? WHERE id = ?');
        $st->execute([$name, $gender, $email, $u['id']]);

        json_out([
            'ok'      => true,
            'user'    => ['id' => (int)$u['id'], 'name' => $name, 'gender' => $gender, 'email' => $email, 'is_admin' => (int)$u['is_admin']],
            'message' => 'Profile updated!',
        ]);

    case 'password':
        $current = scalar_string($row['current'] ?? '');
        $new     = scalar_string($row['new'] ?? '');

        if ($current === '' || $new === '') {
            json_out(['ok' => false, 'error' => 'All fields are required.'], 422);
        }

        $st = db()->prepare('SELECT password FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        $hash = $st->fetchColumn();

        if (!password_verify($current, $hash)) {
            json_out(['ok' => false, 'error' => 'Current password is incorrect.'], 401);
        }
        if (strlen($new) < 8) {
            json_out(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 422);
        }

        $st = db()->prepare('UPDATE users SET password = ?, password_changed_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $st->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);

        // L2: invalidate all other sessions for this user (keep this one).
        // auth_time is stored as a float (Unix epoch incl. microseconds) so a
        // password change in the same second still invalidates older sessions
        // while keeping this one.
        session_regenerate_id(true);
        $_SESSION['auth_time'] = microtime(true);

        json_out(['ok' => true, 'message' => 'Password changed!']);

    default:
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}
