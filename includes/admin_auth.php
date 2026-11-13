<?php
// Shared by dashboard.php and bin/create_superadmin.php

function nx_hash_password(string $pw): string {
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_hash($pw, $algo);
}

function nx_password_problem(string $pw, string $email = '', string $username = ''): ?string {
    $len = strlen($pw);
    if ($len < 12)  return 'Password must be at least 12 characters.';
    if ($len > 128) return 'Password must be 128 characters or fewer.';
    $classes = (preg_match('/[a-z]/', $pw) ? 1 : 0) + (preg_match('/[A-Z]/', $pw) ? 1 : 0)
             + (preg_match('/\d/', $pw) ? 1 : 0)    + (preg_match('/[^a-zA-Z0-9]/', $pw) ? 1 : 0);
    if ($classes < 3) return 'Use at least 3 of: lowercase, uppercase, digits, symbols.';
    $lower = strtolower($pw);
    foreach (['password','qwerty','123456','letmein','welcome','admin','nymix','iloveyou','abc123'] as $bad) {
        if (strpos($lower, $bad) !== false) return 'Password contains a common or guessable word.';
    }
    $local = strtolower(explode('@', $email)[0] ?? '');
    foreach ([$local, strtolower($username)] as $part) {
        if (strlen($part) >= 3 && strpos($lower, $part) !== false) {
            return 'Password must not contain your username or email name.';
        }
    }
    return null;
}

function nx_admin_send_invite(string $name, string $email, string $token): bool {
    $link = NX_ADMIN_URL . '?action=accept_invite&token=' . $token;
    $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:auto;background:#13161e;color:#e8eaf0;border-radius:14px;padding:28px">'
          . '<h2 style="margin:0 0 10px;color:#f59e0b">NYMIX TECH</h2>'
          . "<p>Hi {$n},</p>"
          . '<p>You have been invited to the NYMIX TECH admin portal. Click below to set your password and activate your account. The link works once and expires in 48 hours.</p>'
          . "<p style=\"margin:22px 0\"><a href=\"{$l}\" style=\"background:#f59e0b;color:#000;padding:12px 22px;border-radius:9px;text-decoration:none;font-weight:700\">Set my password</a></p>"
          . '<p style="font-size:12px;color:#8b91a8">If you did not expect this, ignore this email and nothing will happen.</p></div>';
    return (bool)nxo_mail($email, $name, 'Set up your NYMIX TECH admin account', $html);
}