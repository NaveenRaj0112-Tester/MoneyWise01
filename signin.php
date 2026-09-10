<?php
declare(strict_types=1);
require_once __DIR__ . '/api/config.php';

start_secure_session();

// Already signed in? Straight to the app.
if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = scalar_string($_POST['email'] ?? '', 150);
    $pass  = scalar_string($_POST['password'] ?? '');

    if ($email === '' || $pass === '') {
        $error = 'Please enter your email and password.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();

        if (!$u || !password_verify($pass, $u['password'])) {
            $error = 'Incorrect email or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']  = (int)$u['id'];
            $_SESSION['auth_time'] = microtime(true);
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — MoneyWise</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
<link rel="stylesheet" href="assets/app.css?v=<?php echo @filemtime(__DIR__ . '/assets/app.css'); ?>"/>
<?php require __DIR__ . '/includes/pwa-head.php'; ?>
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card fade">
    <div class="auth-logo">
      <div class="logo-icon"><img class="logo-img" src="Mlogo/MoneywiseLOGO.png?v=<?php echo @filemtime(__DIR__ . '/Mlogo/MoneywiseLOGO.png'); ?>" alt="MoneyWise"></div>
      <h1>Money<span>Wise</span></h1>
    </div>
    <div class="auth-title">
      <h2>Welcome Back!</h2>
      <p>Sign in to your account</p>
    </div>

    <?php if (!empty($_GET['signup'])): ?>
      <div class="al al-s"><i class="fas fa-circle-check"></i> Account created successfully! Please sign in.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="al al-e"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="signin.php" novalidate>
      <div class="fg">
        <label for="email">Email Address</label>
        <div class="iw">
          <i class="fas fa-envelope ic"></i>
          <input class="inp" id="email" type="email" name="email" placeholder="you@example.com"
                 value="<?= htmlspecialchars($email) ?>" required>
        </div>
      </div>
      <div class="fg">
        <label for="password">Password</label>
        <div class="iw">
          <i class="fas fa-lock ic"></i>
          <input class="inp inp-pw" id="password" type="password" name="password" placeholder="Your password" required>
          <button type="button" class="pw-toggle" id="pwToggle" title="Show / hide password" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
        </div>
      </div>
      <button class="btn btn-p" type="submit"><i class="fas fa-sign-in-alt"></i> Sign In</button>
    </form>

    <div class="auth-footer">Don't have an account? <a href="signup.php">Sign Up</a></div>
  </div>
</div>
</body>
<script>
  var pw = document.getElementById('password');
  var tg = document.getElementById('pwToggle');
  tg.addEventListener('click', function () {
    var show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    tg.querySelector('i').className = 'fas fa-eye' + (show ? '-slash' : '');
  });
</script>
</html>
