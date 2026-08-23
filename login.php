<?php
declare(strict_types=1);
require_once __DIR__ . '/config/auth.php';
if (isset($_GET['logout'])) { logout(); }
if (is_logged_in()) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (attempt_login((string) ($_POST['password'] ?? ''))) { header('Location: index.php'); exit; }
    $error = 'Incorrect password.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LiVAR CRM login</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body><main style="max-width:420px;margin:12vh auto;padding:24px"><form method="post" class="panel"><h1>LiVAR CRM</h1><?php if ($error): ?><p role="alert"><?= htmlspecialchars($error) ?></p><?php endif ?><label class="field">Password<input name="password" type="password" required autofocus autocomplete="current-password"></label><button class="btn btn--primary" type="submit">Sign in</button></form></main></body></html>

