<?php
// NYMIX TECH — one-time first admin setup. Locks itself once any admin exists.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
session_start();

// EDIT THIS: a long random string only you know
define('NX_SETUP_KEY', 'CHANGE-ME-to-a-long-random-string');

require_once '../includes/db.php';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Locked as soon as any admin exists (also locked if the check fails)
$cnt_r = $conn->query("SELECT COUNT(*) AS c FROM super_admins");
$count = $cnt_r ? (int)$cnt_r->fetch_assoc()['c'] : 1;
if ($count > 0) { header('Location: dashboard.php'); exit; }

if (empty($_SESSION['setup_csrf'])) $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));

$err = ''; $done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $uname = strtolower(trim($_POST['username'] ?? ''));
    $email = strtolower(trim($_POST['email'] ?? ''));
    $p1    = $_POST['password'] ?? '';
    $p2    = $_POST['password2'] ?? '';

    if (!hash_equals($_SESSION['setup_csrf'], $_POST['csrf'] ?? '')) {
        $err = 'Invalid request. Reload the page and try again.';
    } elseif (!hash_equals(NX_SETUP_KEY, (string)($_POST['setup_key'] ?? ''))) {
        $err = 'Wrong setup key.';
    } elseif ($name === '' || mb_strlen($name) > 80) {
        $err = 'Enter a valid full name.';
    } elseif (!preg_match('/^[a-z0-9_.]{3,30}$/', $uname)) {
        $err = 'Username: 3-30 characters, letters, digits, "_" or "." only.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Enter a valid email address.';
    } elseif ($p1 !== $p2) {
        $err = 'Passwords do not match.';
    } elseif (strlen($p1) < 12) {
        $err = 'Password must be at least 12 characters.';
    } elseif (strtolower($p1) === $email || strtolower($p1) === $uname) {
        $err = 'Password cannot be your email or username.';
    } else {
        $hash = password_hash($p1, PASSWORD_DEFAULT);
        // NOT EXISTS makes sure only one admin can ever be created this way
        $ins = $conn->prepare("INSERT INTO super_admins
            (name, username, email, phone, password_hash, role, is_active, email_verified_at, created_at)
            SELECT ?, ?, ?, '', ?, 'superadmin', 1, NOW(), NOW() FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM super_admins)");
        $ins->bind_param('ssss', $name, $uname, $email, $hash);
        $ins->execute();
        $done = ($ins->affected_rows === 1);
        $ins->close();
        if (!$done) $err = 'Setup could not be completed (an admin may already exist).';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NYMIX TECH — First-time setup</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0d0f14;color:#e8eaf0;font-family:'Segoe UI',Arial,sans-serif}
.c{width:100%;max-width:420px;margin:20px;background:#13161e;border:1px solid #2a2f3f;border-radius:16px;padding:32px}
h1{font-size:20px;margin:0 0 6px}
p{color:#8b91a8;font-size:13px;margin:0 0 6px}
label{display:block;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#5a6075;margin:14px 0 6px}
input{width:100%;box-sizing:border-box;background:#1a1e28;border:1px solid #2a2f3f;color:#e8eaf0;padding:11px 13px;border-radius:9px;font-size:14px}
button,a.b{display:block;width:100%;box-sizing:border-box;text-align:center;margin-top:20px;background:#f59e0b;color:#000;border:0;padding:12px;border-radius:9px;font-weight:700;font-size:14px;text-decoration:none;cursor:pointer}
.e{background:rgba(247,85,85,.12);color:#f75555;padding:10px 12px;border-radius:9px;font-size:13px;margin:12px 0}
</style>
</head>
<body>
<div class="c">
<?php if ($done): ?>
  <h1>Super admin created ✓</h1>
  <p>Setup is now locked. Delete this file from the server.</p>
  <a class="b" href="dashboard.php">Go to sign in</a>
<?php else: ?>
  <h1>First-time setup</h1>
  <p>Create the first Super Admin. This page stops working once it is done.</p>
  <?php if ($err): ?><div class="e"><?= e($err) ?></div><?php endif; ?>
  <form method="POST" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['setup_csrf']) ?>">
    <label>Setup key</label><input type="password" name="setup_key" required>
    <label>Full name</label><input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
    <label>Username</label><input type="text" name="username" required value="<?= e($_POST['username'] ?? '') ?>">
    <label>Email</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
    <label>Password (min 12 characters)</label><input type="password" name="password" required autocomplete="new-password">
    <label>Repeat password</label><input type="password" name="password2" required autocomplete="new-password">
    <button type="submit">Create Super Admin</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>