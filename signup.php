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
$name  = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = scalar_string($_POST['name'] ?? '', 100);
    $email   = scalar_string($_POST['email'] ?? '', 150);
    $pass    = scalar_string($_POST['password'] ?? '');
    $confirm = scalar_string($_POST['confirm'] ?? '');

    if ($name === '' || $email === '' || $pass === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $st = db()->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) {
            $error = 'Email already in use.';
        } else {
            $st = db()->prepare('INSERT INTO users (name, gender, email, password, is_admin) VALUES (?, ?, ?, ?, 0)');
            $st->execute([$name, 'male', $email, password_hash($pass, PASSWORD_DEFAULT)]);
            header('Location: signin.php?signup=1');
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
<title>Sign Up — MoneyWise</title>
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
      <h2>Create Account</h2>
      <p>Start managing your finances today</p>
    </div>

    <?php if ($error !== ''): ?>
      <div class="al al-e"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="signup.php" novalidate>
      <div class="fg">
        <label for="name">Full Name</label>
        <div class="iw">
          <i class="fas fa-user ic"></i>
          <input class="inp" id="name" type="text" name="name" placeholder="John Doe"
                 value="<?= htmlspecialchars($name) ?>" required>
        </div>
      </div>
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
          <input class="inp inp-pw" id="password" type="password" name="password" placeholder="Min. 8 characters" required>
          <button type="button" class="pw-toggle" id="pwToggle" title="Show / hide password" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
        </div>
      </div>
      <div class="fg">
        <label for="confirm">Confirm Password</label>
        <div class="iw">
          <i class="fas fa-lock ic"></i>
          <input class="inp inp-pw" id="confirm" type="password" name="confirm" placeholder="Repeat password" required>
          <button type="button" class="pw-toggle" id="cfToggle" title="Show / hide password" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
        </div>
      </div>
      <button class="btn btn-p" type="submit"><i class="fas fa-user-plus"></i> Create Account</button>
    </form>

    <div class="auth-footer">Already have an account? <a href="signin.php">Sign In</a></div>
  </div>
</div>
</body>
<script>
  function bindPwToggle(inpId, btnId) {
    var pw = document.getElementById(inpId);
    var tg = document.getElementById(btnId);
    tg.addEventListener('click', function () {
      var show = pw.type === 'password';
      pw.type = show ? 'text' : 'password';
      tg.querySelector('i').className = 'fas fa-eye' + (show ? '-slash' : '');
    });
  }
  bindPwToggle('password', 'pwToggle');
  bindPwToggle('confirm', 'cfToggle');
</script>
</html>
