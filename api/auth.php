<?php
/**
 * Auth API: session, login, register, logout
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

start_secure_session();

$action = param('action', 'session');

switch ($action) {

    // GET / GET ?action=session  -> current logged-in user (or null)
    case 'session':
        json_out(['ok' => true, 'user' => current_user(), 'app_version' => defined('APP_VERSION') ? APP_VERSION : '1.0.0']);

    case 'login':
        $email = scalar_string(param('email', ''), 150);
        $pass  = scalar_string(param('password', ''));

        if ($email === '' || $pass === '') {
            json_out(['ok' => false, 'error' => 'Please enter your email and password.'], 422);
        }

        // M2: 5 failed attempts per email+IP in 15 min, plus 100 per IP.
        $ip  = client_ip();
        $key = mb_strtolower($email);
        if (rate_limit_hit('login', $key, $ip, 5, 15) || rate_limit_hit('login', null, $ip, 100, 15)) {
            json_out(['ok' => false, 'error' => 'Too many attempts. Please try again later.'], 429);
        }

        $st = db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();

        if (!$u || !password_verify($pass, $u['password'])) {
            rate_limit_record('login', $key, $ip);
            json_out(['ok' => false, 'error' => 'Incorrect email or password.'], 401);
        }

        rate_limit_clear('login', $key, $ip);
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$u['id'];
        $_SESSION['auth_time'] = microtime(true);
        $res = [
            'ok'   => true,
            'user' => ['id' => (int)$u['id'], 'name' => $u['name'], 'gender' => $u['gender'], 'email' => $u['email'], 'is_admin' => (int)$u['is_admin']],
        ];
        // Mobile clients (Capacitor) request a long-lived API token instead of cookies.
        if (param('want_token', '0')) {
            $res['token'] = issue_token((int)$u['id']);
        }
        json_out($res);

    case 'register':
        $name   = scalar_string(param('name', ''), 100);
        $gender = param('gender', 'male');
        if (!is_string($gender) || !in_array($gender, ['male', 'female'], true)) {
            $gender = 'male';
        }
        $email = scalar_string(param('email', ''), 150);
        $pass  = scalar_string(param('password', ''));

        if ($name === '' || $email === '' || $pass === '') {
            json_out(['ok' => false, 'error' => 'All fields are required.'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['ok' => false, 'error' => 'Please enter a valid email address.'], 422);
        }
        if (strlen($pass) < 8) {
            json_out(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 422);
        }

        $st = db()->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) {
            // L1: slow down repeated same-IP probes that confirm account existence.
            $ip = client_ip();
            rate_limit_record('register', mb_strtolower($email), $ip);
            if (rate_limit_hit('register', null, $ip, 30, 15)) {
                json_out(['ok' => false, 'error' => 'Too many registration attempts. Please try again later.'], 429);
            }
            json_out(['ok' => false, 'error' => 'Email already in use.'], 409);
        }

        $st = db()->prepare('INSERT INTO users (name, gender, email, password, is_admin) VALUES (?, ?, ?, ?, 0)');
        $st->execute([$name, $gender, $email, password_hash($pass, PASSWORD_DEFAULT)]);

        $res = [
            'ok'   => true,
            'message' => 'Account created. Please sign in.',
        ];
        json_out($res, 201);

    case 'logout':
        // Revoke the mobile token if one was used for this request.
        $token = bearer_token();
        if ($token) {
            revoke_token($token);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_out(['ok' => true]);

    default:
        json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
}
