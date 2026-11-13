<?php
// ============================================================
//  NYMIX WMS — Warehouse Management System  v1.0
//  warehouse.php  (single-file, session-based, PDO)
//  Same db.php + pesapal_config.php as staff_portal.php
//  Multi-tenant: scoped to client_id on every query
// ============================================================

define('APP_ENV', 'production'); // change to 'development' only when actively debugging

if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
    ini_set('log_errors', 1);
}
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => APP_ENV === 'development' ? 'Fatal: ' . $e['message'] : 'A system error occurred. Please try again or contact support.']);
    }
});

// ── SESSION ───────────────────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.save_handler', 'files');
ini_set('session.lazy_write', 1);
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}
ob_start();
session_start();
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/nx_shield.php';
nx_shield_guard('warehouse');   // NYMIX Shield: inspect the request, block attackers

// ── ROLE CONSTANTS ────────────────────────────────────────────
define('WH_ROLE_OWNER',     1);
define('WH_ROLE_WH_MGR',    6);
define('WH_ROLE_MANAGER',   WH_ROLE_WH_MGR);
define('WH_ROLE_RECEIVING',  7);
define('WH_ROLE_PICKER',    8);
define('WH_ROLE_PACKER',    9);
define('WH_ROLE_QC',        10);

// ── HELPERS ───────────────────────────────────────────────────
function wh_logged_in(): bool  { return isset($_SESSION['wh_staff_id']); }
function wh_me(): array        { return $_SESSION['wh_staff'] ?? []; }
function wh_role(): int        { return (int)($_SESSION['wh_staff']['role_id'] ?? 0); }
function wh_client(): int      { return (int)($_SESSION['wh_staff']['client_id'] ?? 0); }
function wh_warehouse(): int   { return (int)($_SESSION['wh_warehouse_id'] ?? 0); }
function wh_can(array $roles): bool { return in_array(wh_role(), $roles); }
function wh_esc(mixed $v): string   { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function wh_fmt(float $n): string   { return 'KSh ' . number_format($n, 2); }
function wh_today(): string         { return date('Y-m-d'); }

function wh_redirect(string $to): void { header('Location: ' . $to); exit; }
function wh_json(mixed $data): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data); exit;
}

// ── WAREHOUSE MAIL ENGINE ──────────────────────────────────────
function wh_email_theme(string $type): array {
    return match ($type) {
        'alert'   => ['grad' => 'linear-gradient(135deg,#ef4444,#dc2626)', 'icon' => '⚠️', 'label' => 'Alert'],
        'warning' => ['grad' => 'linear-gradient(135deg,#f97316,#ea580c)', 'icon' => '🔔', 'label' => 'Warning'],
        'success' => ['grad' => 'linear-gradient(135deg,#22c55e,#16a34a)', 'icon' => '✅', 'label' => 'Success'],
        'security'=> ['grad' => 'linear-gradient(135deg,#3b82f6,#2563eb)', 'icon' => '🔒', 'label' => 'Security'],
        default   => ['grad' => 'linear-gradient(135deg,#00d4aa,#0ea5e9)', 'icon' => '📦', 'label' => 'Notice'],
    };
}
function wh_email_row(string $label, string $value, bool $strong = false): string {
    $valStyle = $strong ? 'font-weight:700;color:#0f172a;' : 'font-weight:600;color:#334155;';
    return '<tr>
      <td style="padding:10px 14px;border-bottom:1px solid #eef1f5;font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap">' . $label . '</td>
      <td style="padding:10px 14px;border-bottom:1px solid #eef1f5;font-size:14px;text-align:right;' . $valStyle . '">' . $value . '</td>
    </tr>';
}
function wh_email_table(array $rowsHtml): string {
    $rowsHtml[count($rowsHtml)-1] = str_replace('border-bottom:1px solid #eef1f5;', '', end($rowsHtml));
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #eef1f5;border-radius:10px;overflow:hidden;margin:14px 0">' . implode('', $rowsHtml) . '</table>';
}
function wh_email_template(string $subject, string $bodyContent, string $type = 'info', string $businessName = 'NYMIX WMS'): string {
    $theme = wh_email_theme($type);
    $isHtml   = (bool)preg_match('/<[a-z][\s\S]*>/i', $bodyContent);
    $safeBody = $isHtml ? $bodyContent : nl2br(htmlspecialchars($bodyContent, ENT_QUOTES, 'UTF-8'));
    $year = date('Y');
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#eef1f6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;padding:36px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(15,23,42,.08);">
  <tr><td style="background:' . $theme['grad'] . ';padding:26px 32px;">
    <table role="presentation" cellpadding="0" cellspacing="0"><tr>
      <td width="42" valign="middle"><div style="width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.22);text-align:center;font-size:19px;line-height:40px">' . $theme['icon'] . '</div></td>
      <td valign="middle" style="padding-left:12px">
        <div style="font-size:12px;letter-spacing:1.4px;color:rgba(255,255,255,.9);font-weight:700">' . htmlspecialchars(strtoupper($businessName), ENT_QUOTES, 'UTF-8') . '</div>
        <div style="font-size:12px;color:rgba(255,255,255,.7);margin-top:1px">' . strtoupper($theme['label']) . ' NOTIFICATION</div>
      </td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:24px 32px 6px;"><div style="font-size:18px;font-weight:700;color:#0f172a;line-height:1.35">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</div></td></tr>
  <tr><td style="padding:12px 32px 28px;color:#334155;font-size:14px;line-height:1.7;">' . $safeBody . '</td></tr>
  <tr><td style="background:#f8fafc;padding:16px 32px;border-top:1px solid #eef1f5;">
    <span style="font-size:11px;color:#94a3b8;">Automated message from NYMIX Warehouse Management System. Do not reply directly.</span>
  </td></tr>
</table>
<div style="font-size:11px;color:#a3adc2;margin-top:16px">© ' . $year . ' NYMIX TECH · Powered by NYMIX WMS</div>
</td></tr>
</table>
</body></html>';
}
function wh_mail(string $toEmail, string $toName, string $subject, string $body, string $type = 'info', string $businessName = 'NYMIX WMS'): bool {
    if (!$toEmail) return false;
    $probe = @fsockopen('ssl://smtp.gmail.com', 465, $errno, $errstr, 5);
    if (!$probe) { error_log("[WH MAIL] Cannot reach smtp.gmail.com — {$errstr}"); return false; }
    fclose($probe);
    require_once __DIR__ . '/phpmailer/Exception.php';
    require_once __DIR__ . '/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/SMTP.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-smtp-user@example.com';
        $mail->Password   = 'YOUR_SMTP_APP_PASSWORD';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->Timeout    = 8;
        $mail->SMTPOptions = ['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],'socket'=>['connect_timeout'=>5]];
        $mail->setFrom('your-smtp-user@example.com', 'NYMIX Warehouse Portal');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = wh_email_template($subject, $body, $type, $businessName);
        $mail->AltBody = preg_match('/<[a-z][\s\S]*>/i', $body)
            ? trim(preg_replace('/\n{3,}/', "\n\n", strip_tags(str_replace(['<tr>','</tr>','<br>','<br/>','<br />'], "\n", $body))))
            : $body;
        $mail->send();
        return true;
    } catch (\Throwable $e) { error_log('[WH MAIL] '.$e->getMessage()); return false; }
}

// Notify the warehouse manager(s) for the given warehouse + the client owner
function wh_notify(PDO $pdo, int $wh_id, string $subject, string $body, string $type = 'info'): void {
    try {
        // Warehouse-assigned managers (WH_ROLE_WH_MGR) + owner
        $mgrs = $pdo->prepare("
            SELECT DISTINCT u.email, u.full_name
            FROM users u
            JOIN wh_user_warehouse uw ON uw.user_id = u.id
            WHERE uw.warehouse_id = ? AND u.role_id = ? AND u.email IS NOT NULL AND u.email != ''
        ");
        $mgrs->execute([$wh_id, WH_ROLE_WH_MGR]);
        foreach ($mgrs->fetchAll() as $m) wh_mail($m['email'], $m['full_name'], $subject, $body, $type);

        // Also the warehouse's designated manager_id if set
        $wm = $pdo->prepare("SELECT u.email, u.full_name FROM wh_warehouses w JOIN users u ON u.id = w.manager_id WHERE w.id = ? AND u.email IS NOT NULL AND u.email != ''");
        $wm->execute([$wh_id]);
        if ($row = $wm->fetch()) wh_mail($row['email'], $row['full_name'], $subject, $body, $type);

        // Owner
        $own = $pdo->prepare("
            SELECT cpu.email, c.business_name
            FROM client_portal_users cpu JOIN clients c ON c.id = cpu.client_id
            WHERE cpu.client_id = ? LIMIT 1
        ");
        $own->execute([wh_client()]);
        if ($o = $own->fetch()) if ($o['email']) wh_mail($o['email'], $o['business_name'], $subject, $body, $type, $o['business_name']);
    } catch (Throwable $e) { error_log('[WH NOTIFY] ' . $e->getMessage()); }
}

// ── CSRF ──────────────────────────────────────────────────────
function wh_csrf(): string {
    if (empty($_SESSION['wh_csrf'])) {
        $_SESSION['wh_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['wh_csrf'];
}
function wh_csrf_ok(): bool {
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($t) && hash_equals($_SESSION['wh_csrf'] ?? '', $t);
}
function wh_csrf_check(): void {
    if (!wh_csrf_ok()) wh_json(['ok' => false, 'msg' => 'Invalid CSRF token.']);
}

// ── LOGIN RATE LIMITER ────────────────────────────────────────
function wh_login_rate_check(string $ip): bool {
    $key = 'wh_login_attempts_' . md5($ip);
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first' => time()];
    }
    $att = &$_SESSION[$key];
    if ((time() - $att['first']) > 900) { // reset after 15 min
        $att = ['count' => 0, 'first' => time()];
    }
    return $att['count'] < 5;
}
function wh_login_rate_increment(string $ip): void {
    $key = 'wh_login_attempts_' . md5($ip);
    $_SESSION[$key]['count'] = ($_SESSION[$key]['count'] ?? 0) + 1;
}
function wh_login_rate_reset(string $ip): void {
    unset($_SESSION['wh_login_attempts_' . md5($ip)]);
}

// ── AUDIT LOG ─────────────────────────────────────────────────
function wh_audit(PDO $pdo, string $action, string $entity_type = '', int $entity_id = 0, array $old = [], array $new = []): void {
    try {
        $pdo->prepare("
            INSERT INTO wh_audit_log (client_id, user_id, warehouse_id, action, entity_type, entity_id, old_value, new_value, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            wh_client(), wh_me()['id'] ?? null, wh_warehouse(),
            $action, $entity_type, $entity_id ?: null,
            $old ? json_encode($old) : null,
            $new  ? json_encode($new)  : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { /* non-blocking */ }
}

// ── LEDGER ENTRY ─────────────────────────────────────────────
function wh_ledger(PDO $pdo, array $entry): void {
    $pdo->prepare("
        INSERT INTO wh_stock_ledger
          (client_id, warehouse_id, product_id, batch_id, location_id,
           movement_type, qty_in, qty_out, qty_balance,
           reference_type, reference_id, unit_cost, performed_by, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $entry['client_id']      ?? wh_client(),
        $entry['warehouse_id']   ?? wh_warehouse(),
        $entry['product_id'],
        $entry['batch_id']       ?? null,
        $entry['location_id']    ?? null,
        $entry['movement_type'],
        $entry['qty_in']         ?? 0,
        $entry['qty_out']        ?? 0,
        $entry['qty_balance']    ?? 0,
        $entry['reference_type'] ?? null,
        $entry['reference_id']   ?? null,
        $entry['unit_cost']      ?? null,
        $entry['performed_by']   ?? (wh_me()['id'] ?? null),
        $entry['notes']          ?? null,
    ]);
}

// ── GRN NUMBER GENERATOR ──────────────────────────────────────
function gen_grn(PDO $pdo, int $wh_id): string {
    $prefix = 'GRN-' . date('Ymd') . '-';
    $last   = $pdo->prepare("SELECT grn_number FROM wh_grn WHERE warehouse_id = ? AND grn_number LIKE ? ORDER BY id DESC LIMIT 1");
    $last->execute([$wh_id, $prefix . '%']);
    $row    = $last->fetchColumn();
    $seq    = $row ? ((int)substr($row, -4) + 1) : 1;
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}
function gen_outbound(PDO $pdo, int $wh_id): string {
    $prefix = 'OB-' . date('Ymd') . '-';
    $last   = $pdo->prepare("SELECT order_number FROM wh_outbound_orders WHERE warehouse_id = ? AND order_number LIKE ? ORDER BY id DESC LIMIT 1");
    $last->execute([$wh_id, $prefix . '%']);
    $row    = $last->fetchColumn();
    $seq    = $row ? ((int)substr($row, -4) + 1) : 1;
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}
function gen_production(PDO $pdo, int $wh_id): string {
    $prefix = 'PRD-' . date('Ymd') . '-';
    $last   = $pdo->prepare("SELECT order_number FROM wh_production_orders WHERE warehouse_id = ? AND order_number LIKE ? ORDER BY id DESC LIMIT 1");
    $last->execute([$wh_id, $prefix . '%']);
    $row    = $last->fetchColumn();
    $seq    = $row ? ((int)substr($row, -4) + 1) : 1;
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}
function gen_count(PDO $pdo, int $wh_id): string {
    $prefix = 'CNT-' . date('Ymd') . '-';
    $last   = $pdo->prepare("SELECT count_number FROM wh_stock_counts WHERE warehouse_id = ? AND count_number LIKE ? ORDER BY id DESC LIMIT 1");
    $last->execute([$wh_id, $prefix . '%']);
    $row    = $last->fetchColumn();
    $seq    = $row ? ((int)substr($row, -4) + 1) : 1;
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}
function gen_transfer(PDO $pdo, int $client_id): string {
    $prefix = 'IWT-' . date('Ymd') . '-';
    $last   = $pdo->prepare("SELECT transfer_number FROM wh_transfers WHERE client_id = ? AND transfer_number LIKE ? ORDER BY id DESC LIMIT 1");
    $last->execute([$client_id, $prefix . '%']);
    $row    = $last->fetchColumn();
    $seq    = $row ? ((int)substr($row, -4) + 1) : 1;
    return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── SUBSCRIPTION CHECK ────────────────────────────────────────
function wh_check_subscription(PDO $pdo, int $client_id): bool {
    $stmt = $pdo->prepare("
        SELECT COALESCE((
            SELECT CASE
                WHEN c.status = 'suspended' THEN 'suspended'
                WHEN CURDATE() <= s.end_date THEN 'active'
                WHEN CURDATE() <= s.grace_end_date THEN 'grace'
                ELSE 'expired'
            END
            FROM subscriptions s WHERE s.client_id = c.id ORDER BY s.id DESC LIMIT 1
        ), 'no_sub') AS sub_status
        FROM clients c WHERE c.id = ?
    ");
    $stmt->execute([$client_id]);
    $row = $stmt->fetchColumn();
    return in_array($row, ['active', 'grace']);
}

// ── LOGOUT ────────────────────────────────────────────────────
$action = $_REQUEST['action'] ?? '';

if ($action === 'logout') {
    session_unset(); session_destroy();
    session_start(); session_regenerate_id(true);
    setcookie(session_name(), '', time() - 3600, '/');
    wh_redirect('/nymix_hardwares/warehouse.php');
}

// ── LOGIN ─────────────────────────────────────────────────────
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!wh_csrf_ok()) wh_json(['ok' => false, 'msg' => 'Session expired. Refresh the page and try again.']);
    if (!wh_login_rate_check($ip)) wh_json(['ok' => false, 'msg' => 'Too many failed attempts. Please wait 15 minutes.']);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$username || !$password) wh_json(['ok' => false, 'msg' => 'Enter username and password.']);

    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT u.*, r.name AS role_name,
               b.branch_name, b.client_id, b.is_active AS branch_active,
               c.status AS client_status, c.business_name, c.logo
        FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN branches b ON b.id = u.branch_id
        LEFT JOIN clients c ON c.id = b.client_id
        WHERE u.username = ? AND u.is_active = 1 LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        wh_login_rate_increment($ip);
        wh_json(['ok' => false, 'msg' => 'Invalid username or password.']);
    }
    if (!(int)$user['branch_active']) {
        wh_json(['ok' => false, 'msg' => 'Your branch is inactive.']);
    }
    $client_id = (int)$user['client_id'];
    if (!wh_check_subscription($pdo, $client_id)) {
        wh_json(['ok' => false, 'msg' => 'Subscription expired. Contact your administrator.']);
    }

    // Only WMS roles are allowed into the warehouse portal
    $allowed_wms_roles = [WH_ROLE_OWNER, WH_ROLE_WH_MGR,
                          WH_ROLE_RECEIVING, WH_ROLE_PICKER, WH_ROLE_PACKER, WH_ROLE_QC];
    $role_id = (int)($user['role_id'] ?? 0);
    if (!in_array($role_id, $allowed_wms_roles)) {
        wh_json(['ok' => false, 'msg' => 'Your role does not have access to the Warehouse Portal.']);
    }

    wh_login_rate_reset($ip);
    session_regenerate_id(true);
    $_SESSION['wh_staff_id'] = $user['id'];
    $_SESSION['wh_staff']    = $user;

    // Owners/managers get first active warehouse; others only assigned ones
    $role_id = (int)($user['role_id'] ?? 0);
    if (in_array($role_id, [WH_ROLE_OWNER, WH_ROLE_WH_MGR])) {
        $wh = $pdo->prepare("SELECT id FROM wh_warehouses WHERE client_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1");
        $wh->execute([$client_id]);
    } else {
        $wh = $pdo->prepare("
            SELECT w.id FROM wh_warehouses w
            JOIN wh_user_warehouse uw ON uw.warehouse_id = w.id
            WHERE w.client_id = ? AND w.is_active = 1 AND uw.user_id = ?
            ORDER BY w.id ASC LIMIT 1
        ");
        $wh->execute([$client_id, $user['id']]);
    }
    $wh_id = (int)($wh->fetchColumn() ?: 0);
    $_SESSION['wh_warehouse_id'] = $wh_id;

    // Block non-owner/manager staff who have no warehouse assigned
    if (!in_array($role_id, [WH_ROLE_OWNER, WH_ROLE_WH_MGR]) && !$wh_id) {
        session_unset();
        session_destroy();
        wh_json(['ok' => false, 'msg' => 'You have not been assigned to any warehouse. Contact your administrator.']);
    }

    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    wh_json(['ok' => true]);
}

// ── FORGOT PASSWORD (OTP via email) ────────────────────────────
if ($action === 'wh_forgot_check' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    if (!$username) wh_json(['ok' => false, 'msg' => 'Enter your username.']);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, full_name, email, role_id FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    $allowed = [WH_ROLE_OWNER, WH_ROLE_WH_MGR, WH_ROLE_RECEIVING, WH_ROLE_PICKER, WH_ROLE_PACKER, WH_ROLE_QC];
    if (!$u || !in_array((int)$u['role_id'], $allowed)) wh_json(['ok' => false, 'msg' => 'Username not found or has no warehouse access.']);
    wh_json(['ok' => true, 'has_email' => !empty($u['email']), 'masked_email' => $u['email'] ? substr($u['email'],0,2).'***@'.explode('@',$u['email'])[1] : null]);
}

if ($action === 'wh_forgot_send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    if (!$username || !$email) wh_json(['ok' => false, 'msg' => 'Username and email are required.']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) wh_json(['ok' => false, 'msg' => 'Enter a valid email address.']);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if (!$u) wh_json(['ok' => false, 'msg' => 'Username not found.']);
    $otp = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $pdo->prepare("UPDATE users SET email = ?, otp_code = ?, otp_expires_at = ?, otp_attempts = 0 WHERE id = ?")
        ->execute([$email, $otp, $expires, $u['id']]);
    $sent = wh_mail($email, $u['full_name'], 'Your NYMIX Warehouse Reset Code',
        "Hi {$u['full_name']},\n\nYour password reset code is: {$otp}\n\nThis code expires in 10 minutes. If you didn't request this, ignore this email.", 'security');
    if (!$sent) wh_json(['ok' => false, 'msg' => 'Could not send email right now.']);
    wh_json(['ok' => true, 'msg' => 'Reset code sent to your email.']);
}

if ($action === 'wh_forgot_reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $otp       = trim($_POST['otp'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    if (!$username || !$otp || !$password) wh_json(['ok' => false, 'msg' => 'All fields are required.']);
    if (strlen($password) < 8) wh_json(['ok' => false, 'msg' => 'Password must be at least 8 characters.']);
    if ($password !== $password2) wh_json(['ok' => false, 'msg' => 'Passwords do not match.']);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, otp_code, otp_expires_at, otp_attempts FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if (!$u) wh_json(['ok' => false, 'msg' => 'Invalid request. Start over.']);
    if ((int)$u['otp_attempts'] >= 5) wh_json(['ok' => false, 'msg' => 'Too many attempts. Request a new code.']);
    if (!$u['otp_code'] || $u['otp_code'] !== $otp || strtotime($u['otp_expires_at']) < time()) {
        $pdo->prepare("UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?")->execute([$u['id']]);
        wh_json(['ok' => false, 'msg' => 'Invalid or expired code.']);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ?, otp_code = NULL, otp_expires_at = NULL, otp_attempts = 0 WHERE id = ?")
        ->execute([$hash, $u['id']]);
    wh_json(['ok' => true, 'msg' => 'Password reset! You can now sign in.']);
}

// ── AUTH GUARD ────────────────────────────────────────────────
if ($action && $action !== 'login' && !wh_logged_in()) {
    wh_json(['ok' => false, 'msg' => 'Not authenticated.']);
}

// ── WAREHOUSE ACCESS GUARD ────────────────────────────────────
$_wh_guarded = [
    'wh_dashboard','zones_list','zone_save','locations_list','location_save',
    'batches_list','batch_release','grn_list','grn_create','grn_detail','grn_qc',
    'outbound_list','outbound_create','outbound_detail','outbound_pick','outbound_dispatch',
    'bom_list','bom_detail','bom_save','production_list','production_create','production_detail',
    'production_start','production_complete','delivery_list','delivery_save',
    'count_list','count_create','count_items','count_submit','count_approve',
    'ledger_list','wh_transfer_list','wh_transfer_create','warehouses_list',
];
if ($action && in_array($action, $_wh_guarded) && wh_logged_in()) {
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_WH_MGR])) {

        $wh_id_check = wh_warehouse();
        if (!$wh_id_check) {
            wh_json(['ok' => false, 'msg' => 'No warehouse selected or you have no warehouse access.']);
        }
        $pdo = db();
        $acc = $pdo->prepare("SELECT 1 FROM wh_user_warehouse WHERE user_id = ? AND warehouse_id = ?");
        $acc->execute([wh_me()['id'], $wh_id_check]);
        if (!$acc->fetch()) {
            wh_json(['ok' => false, 'msg' => 'You do not have access to this warehouse.']);
        }
    }
}
if ($action === 'switch_warehouse' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $wh_id = (int)($_POST['warehouse_id'] ?? 0);
    $pdo   = db();
    if (wh_can([WH_ROLE_OWNER, WH_ROLE_WH_MGR])) {
        $check = $pdo->prepare("SELECT id FROM wh_warehouses WHERE id = ? AND client_id = ? AND is_active = 1");
        $check->execute([$wh_id, wh_client()]);
    } else {
        $check = $pdo->prepare("
            SELECT w.id FROM wh_warehouses w
            JOIN wh_user_warehouse uw ON uw.warehouse_id = w.id
            WHERE w.id = ? AND w.client_id = ? AND w.is_active = 1 AND uw.user_id = ?
        ");
        $check->execute([$wh_id, wh_client(), wh_me()['id']]);
    }
    if (!$check->fetch()) wh_json(['ok' => false, 'msg' => 'Invalid warehouse.']);
    $_SESSION['wh_warehouse_id'] = $wh_id;
    wh_json(['ok' => true]);
}

// ════════════════════════════════════════════════════════════
//  AJAX HANDLERS
// ════════════════════════════════════════════════════════════

// ── DASHBOARD ─────────────────────────────────────────────────
if ($action === 'wh_dashboard') {
    $pdo   = db();
    $cid   = wh_client();
    $wh_id = wh_warehouse();

    // Today's GRNs
    $grn = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total_qty_received),0) AS qty FROM wh_grn WHERE warehouse_id = ? AND delivery_date = CURDATE()");
    $grn->execute([$wh_id]); $grn_today = $grn->fetch();

    // Open outbound orders
    $ob = $pdo->prepare("SELECT COUNT(*) AS cnt FROM wh_outbound_orders WHERE warehouse_id = ? AND status NOT IN ('dispatched','delivered','cancelled')");
    $ob->execute([$wh_id]); $open_orders = $ob->fetchColumn();

    // Active production orders
    $prod = $pdo->prepare("SELECT COUNT(*) AS cnt FROM wh_production_orders WHERE warehouse_id = ? AND status IN ('ready','in_progress')");
    $prod->execute([$wh_id]); $active_prod = $prod->fetchColumn();

    // Batches expiring in 30 days
    $exp = $pdo->prepare("SELECT COUNT(*) AS cnt FROM wh_batches WHERE warehouse_id = ? AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 'available'");
    $exp->execute([$wh_id]); $expiring = $exp->fetchColumn();

    // Batches in quarantine
    $quar = $pdo->prepare("SELECT COUNT(*) AS cnt FROM wh_batches WHERE warehouse_id = ? AND status = 'quarantine'");
    $quar->execute([$wh_id]); $quarantine = $quar->fetchColumn();

    // Today's deliveries scheduled
    $ds = $pdo->prepare("SELECT COUNT(*) AS cnt FROM wh_delivery_schedule WHERE warehouse_id = ? AND scheduled_date = CURDATE() AND status IN ('scheduled','confirmed','arrived')");
    $ds->execute([$wh_id]); $deliveries_today = $ds->fetchColumn();

    // Recent ledger movements (last 10)
    $ledger = $pdo->prepare("
        SELECT l.*, p.name AS product_name, loc.label AS location_label
        FROM wh_stock_ledger l
        JOIN products p ON p.id = l.product_id
        LEFT JOIN wh_locations loc ON loc.id = l.location_id
        WHERE l.warehouse_id = ?
        ORDER BY l.created_at DESC LIMIT 10
    ");
    $ledger->execute([$wh_id]); $recent = $ledger->fetchAll();

    // Zone utilization
    $zones = $pdo->prepare("
        SELECT z.name, z.code, z.type,
               COUNT(l.id) AS total_bins,
               SUM(CASE WHEN l.current_qty > 0 THEN 1 ELSE 0 END) AS occupied_bins,
               COALESCE(SUM(l.current_qty),0) AS total_qty
        FROM wh_zones z
        LEFT JOIN wh_locations l ON l.zone_id = z.id
        WHERE z.warehouse_id = ?
        GROUP BY z.id ORDER BY z.name
    ");
    $zones->execute([$wh_id]); $zone_stats = $zones->fetchAll();

    // Upcoming deliveries (next 7 days)
    $upcoming = $pdo->prepare("
        SELECT ds.*, s.name AS supplier_name
        FROM wh_delivery_schedule ds
        LEFT JOIN suppliers s ON s.id = ds.supplier_id
        WHERE ds.warehouse_id = ? AND ds.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND ds.status NOT IN ('completed','cancelled')
        ORDER BY ds.scheduled_date ASC LIMIT 5
    ");
    $upcoming->execute([$wh_id]); $upcoming_deliveries = $upcoming->fetchAll();

    wh_json([
        'ok'                 => true,
        'grn_today'          => $grn_today,
        'open_orders'        => (int)$open_orders,
        'active_production'  => (int)$active_prod,
        'expiring_batches'   => (int)$expiring,
        'quarantine_batches' => (int)$quarantine,
        'deliveries_today'   => (int)$deliveries_today,
        'recent_movements'   => $recent,
        'zone_stats'         => $zone_stats,
        'upcoming_deliveries'=> $upcoming_deliveries,
    ]);
}

// ── WAREHOUSES ────────────────────────────────────────────────
if ($action === 'warehouses_list') {
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT w.*, u.full_name AS manager_name, b.branch_name
        FROM wh_warehouses w
        LEFT JOIN users u ON u.id = w.manager_id
        LEFT JOIN branches b ON b.id = w.branch_id
        WHERE w.client_id = ? ORDER BY w.name
    ");
    $stmt->execute([wh_client()]);
    wh_json(['ok' => true, 'warehouses' => $stmt->fetchAll()]);
}

if ($action === 'warehouse_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    if (!$name || !$code) wh_json(['ok' => false, 'msg' => 'Name and code are required.']);
    $data = [
        $name, $code,
        trim($_POST['address'] ?? ''),
        trim($_POST['phone'] ?? ''),
        !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null,
        !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null,
        (int)($_POST['is_active'] ?? 1),
        wh_client(),
    ];
    if ($id) {
        $pdo->prepare("UPDATE wh_warehouses SET name=?,code=?,address=?,phone=?,branch_id=?,manager_id=?,is_active=? WHERE id=? AND client_id=?")
            ->execute([...$data, $id, wh_client()]);
    } else {
        $pdo->prepare("INSERT INTO wh_warehouses (name,code,address,phone,branch_id,manager_id,is_active,client_id) VALUES (?,?,?,?,?,?,?,?)")
            ->execute($data);
        $id = (int)$pdo->lastInsertId();
        // Auto-create default zones
        $zones = [
            ['Raw Materials', 'RM', 'raw_material'],
            ['Finished Goods', 'FG', 'finished_goods'],
            ['Work In Progress', 'WIP', 'work_in_progress'],
            ['Quarantine', 'QT', 'quarantine'],
            ['Dispatch', 'DS', 'dispatch'],
        ];
        $zs = $pdo->prepare("INSERT INTO wh_zones (warehouse_id, client_id, name, code, type) VALUES (?,?,?,?,?)");
        foreach ($zones as $z) $zs->execute([$id, wh_client(), ...$z]);
    }
    wh_audit(db(), $id ? 'warehouse_updated' : 'warehouse_created', 'warehouse', $id);
    wh_json(['ok' => true, 'id' => $id]);
}

// ── ZONES ─────────────────────────────────────────────────────
if ($action === 'zones_list') {
    $pdo   = db();
    $wh_id = (int)($_GET['warehouse_id'] ?? wh_warehouse());
    $stmt  = $pdo->prepare("
        SELECT z.*,
               COUNT(l.id) AS total_locations,
               SUM(CASE WHEN l.current_qty > 0 THEN 1 ELSE 0 END) AS occupied
        FROM wh_zones z
        LEFT JOIN wh_locations l ON l.zone_id = z.id
        WHERE z.warehouse_id = ? AND z.client_id = ?
        GROUP BY z.id ORDER BY z.name
    ");
    $stmt->execute([$wh_id, wh_client()]);
    wh_json(['ok' => true, 'zones' => $stmt->fetchAll()]);
}

if ($action === 'zone_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo   = db();
    $id    = (int)($_POST['id'] ?? 0);
    $wh_id = wh_warehouse();
    $name  = trim($_POST['name'] ?? '');
    $code  = strtoupper(trim($_POST['code'] ?? ''));
    $type  = $_POST['type'] ?? 'general';
    if (!$name || !$code) wh_json(['ok' => false, 'msg' => 'Name and code required.']);
    if ($id) {
        $pdo->prepare("UPDATE wh_zones SET name=?,code=?,type=?,description=?,is_active=? WHERE id=? AND warehouse_id=?")
            ->execute([$name, $code, $type, trim($_POST['description'] ?? ''), (int)($_POST['is_active'] ?? 1), $id, $wh_id]);
    } else {
        $pdo->prepare("INSERT INTO wh_zones (warehouse_id,client_id,name,code,type,description) VALUES (?,?,?,?,?,?)")
            ->execute([$wh_id, wh_client(), $name, $code, $type, trim($_POST['description'] ?? '')]);
        $id = (int)$pdo->lastInsertId();
    }
    wh_json(['ok' => true, 'id' => $id]);
}

// ── LOCATIONS ─────────────────────────────────────────────────
if ($action === 'locations_list') {
    $pdo     = db();
    $zone_id = (int)($_GET['zone_id'] ?? 0);
    $wh_id   = wh_warehouse();
    $q       = '%' . trim($_GET['q'] ?? '') . '%';
    $params  = [$wh_id, wh_client(), $q];
    $extra   = '';
    if ($zone_id) { $extra = ' AND l.zone_id = ?'; $params[] = $zone_id; }
    $stmt = $pdo->prepare("
        SELECT l.*, z.name AS zone_name, z.code AS zone_code,
               ROUND((l.current_qty / NULLIF(l.capacity,0)) * 100, 1) AS utilization_pct
        FROM wh_locations l
        JOIN wh_zones z ON z.id = l.zone_id
        WHERE l.warehouse_id = ? AND l.client_id = ? AND l.label LIKE ? $extra
        ORDER BY l.label ASC
    ");
    $stmt->execute($params);
    wh_json(['ok' => true, 'locations' => $stmt->fetchAll()]);
}

if ($action === 'location_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo     = db();
    $id      = (int)($_POST['id'] ?? 0);
    $wh_id   = wh_warehouse();
    $zone_id = (int)$_POST['zone_id'];
    $label   = strtoupper(trim($_POST['label'] ?? ''));
    if (!$label || !$zone_id) wh_json(['ok' => false, 'msg' => 'Zone and label are required.']);
    $data = [
        $wh_id, $zone_id, wh_client(),
        trim($_POST['aisle'] ?? ''), trim($_POST['rack'] ?? ''),
        trim($_POST['shelf'] ?? ''), trim($_POST['bin'] ?? ''),
        $label, trim($_POST['barcode'] ?? '') ?: null,
        !empty($_POST['capacity']) ? (float)$_POST['capacity'] : null,
        $_POST['location_type'] ?? 'bin',
        (int)($_POST['is_active'] ?? 1),
    ];
    if ($id) {
        $pdo->prepare("UPDATE wh_locations SET warehouse_id=?,zone_id=?,client_id=?,aisle=?,rack=?,shelf=?,bin=?,label=?,barcode=?,capacity=?,location_type=?,is_active=? WHERE id=?")
            ->execute([...$data, $id]);
    } else {
        $pdo->prepare("INSERT INTO wh_locations (warehouse_id,zone_id,client_id,aisle,rack,shelf,bin,label,barcode,capacity,location_type,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute($data);
        $id = (int)$pdo->lastInsertId();
    }
    wh_json(['ok' => true, 'id' => $id]);
}

// ── BATCHES ───────────────────────────────────────────────────
if ($action === 'batches_list') {
    $pdo    = db();
    $wh_id  = wh_warehouse();
    $q      = '%' . trim($_GET['q'] ?? '') . '%';
    $status = $_GET['status'] ?? '';
    $params = [$wh_id, wh_client(), $q, $q];
    $extra  = '';
    if ($status) { $extra = ' AND b.status = ?'; $params[] = $status; }
    $stmt = $pdo->prepare("
        SELECT b.*, p.name AS product_name, p.sku,
               l.label AS location_label, z.name AS zone_name
        FROM wh_batches b
        JOIN products p ON p.id = b.product_id
        LEFT JOIN wh_locations l ON l.id = b.location_id
        LEFT JOIN wh_zones z ON z.id = l.zone_id
        WHERE b.warehouse_id = ? AND b.client_id = ?
          AND (b.batch_number LIKE ? OR p.name LIKE ?) $extra
        ORDER BY b.expiry_date ASC, b.created_at DESC
    ");
    $stmt->execute($params);
    wh_json(['ok' => true, 'batches' => $stmt->fetchAll()]);
}

if ($action === 'batch_release' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR, WH_ROLE_QC])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo      = db();
    $batch_id = (int)$_POST['batch_id'];
    $decision = in_array($_POST['decision'] ?? '', ['available', 'rejected']) ? $_POST['decision'] : 'available';
    $notes    = trim($_POST['notes'] ?? '');
    $pdo->prepare("UPDATE wh_batches SET status = ?, notes = CONCAT(COALESCE(notes,''), ?) WHERE id = ? AND warehouse_id = ?")
        ->execute([$decision, $notes ? "\nQC: $notes" : '', $batch_id, wh_warehouse()]);
    wh_audit($pdo, "batch_$decision", 'batch', $batch_id, [], ['status' => $decision, 'notes' => $notes]);
    wh_json(['ok' => true]);
}

// ── GRN ───────────────────────────────────────────────────────
if ($action === 'grn_list') {
    $pdo   = db();
    $wh_id = wh_warehouse();
    $from  = $_GET['from'] ?? wh_today();
    $to    = $_GET['to']   ?? wh_today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = wh_today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = wh_today();
    $stmt  = $pdo->prepare("
        SELECT g.*, s.name AS supplier_name, u.full_name AS received_by_name,
               qc.full_name AS qc_by_name
        FROM wh_grn g
        LEFT JOIN suppliers s ON s.id = g.supplier_id
        LEFT JOIN users u ON u.id = g.received_by
        LEFT JOIN users qc ON qc.id = g.qc_by
        WHERE g.warehouse_id = ? AND g.delivery_date BETWEEN ? AND ?
        ORDER BY g.created_at DESC
    ");
    $stmt->execute([$wh_id, $from, $to]);
    wh_json(['ok' => true, 'grns' => $stmt->fetchAll()]);
}

if ($action === 'grn_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR, WH_ROLE_RECEIVING])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo       = db();
    $wh_id     = wh_warehouse();
    $items     = json_decode($_POST['items'] ?? '[]', true);
    $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $po_id       = !empty($_POST['po_id'])       ? (int)$_POST['po_id']       : null;
    $date        = $_POST['delivery_date'] ?? wh_today();
    if (empty($items)) wh_json(['ok' => false, 'msg' => 'Add at least one item.']);

    $grn_no = gen_grn($pdo, $wh_id);
    $total_qty = array_reduce($items, fn($c, $i) => $c + (float)($i['qty_received'] ?? 0), 0);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO wh_grn
              (client_id, warehouse_id, grn_number, po_id, supplier_id, supplier_invoice,
               supplier_do, delivery_date, received_by, vehicle_reg, driver_name,
               status, total_items, total_qty_received, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            wh_client(), $wh_id, $grn_no, $po_id, $supplier_id,
            trim($_POST['supplier_invoice'] ?? '') ?: null,
            trim($_POST['supplier_do'] ?? '') ?: null,
            $date, wh_me()['id'],
            trim($_POST['vehicle_reg'] ?? '') ?: null,
            trim($_POST['driver_name'] ?? '') ?: null,
            'receiving', count($items), $total_qty,
            trim($_POST['notes'] ?? '') ?: null,
        ]);
        $grn_id = (int)$pdo->lastInsertId();

        $gi = $pdo->prepare("
            INSERT INTO wh_grn_items
              (grn_id, product_id, po_item_id, qty_ordered, qty_received,
               qty_accepted, unit_cost, batch_number, expiry_date, location_id, qc_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");

        foreach ($items as $it) {
            $qty_received = (float)($it['qty_received'] ?? 0);
            $gi->execute([
                $grn_id,
                (int)$it['product_id'],
                !empty($it['po_item_id']) ? (int)$it['po_item_id'] : null,
                (float)($it['qty_ordered'] ?? $qty_received),
                $qty_received,
                $qty_received, // accepted = received initially; QC can reduce
                !empty($it['unit_cost']) ? (float)$it['unit_cost'] : null,
                trim($it['batch_number'] ?? '') ?: ('B-' . date('Ymd') . '-' . rand(1000,9999)),
                !empty($it['expiry_date']) ? $it['expiry_date'] : null,
                !empty($it['location_id']) ? (int)$it['location_id'] : null,
                'pending',
            ]);
        }

        $pdo->prepare("UPDATE wh_grn SET status = 'qc_pending' WHERE id = ?")->execute([$grn_id]);
        $pdo->commit();
        wh_audit($pdo, 'grn_created', 'grn', $grn_id, [], ['grn_number' => $grn_no]);

        // ── EMAIL: goods received, awaiting QC ──
        $supName = 'Walk-in / No Supplier';
        if ($supplier_id) {
            $sn = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?"); $sn->execute([$supplier_id]);
            $supName = $sn->fetchColumn() ?: $supName;
        }
        $bodyRows = wh_email_table([
            wh_email_row('GRN Number', $grn_no, true),
            wh_email_row('Supplier', htmlspecialchars($supName, ENT_QUOTES, 'UTF-8')),
            wh_email_row('Delivery Date', $date),
            wh_email_row('Total Items', count($items)),
            wh_email_row('Total Qty Received', number_format($total_qty, 2)),
            wh_email_row('Received By', htmlspecialchars(wh_me()['full_name'] ?? '', ENT_QUOTES, 'UTF-8')),
        ]);
        wh_notify(db(), $wh_id, "📦 Goods Received — {$grn_no}",
            "<div style='font-size:13px;color:#64748b;margin-bottom:4px'>New stock has arrived and is awaiting QC inspection.</div>" . $bodyRows, 'info');

        wh_json(['ok' => true, 'grn_id' => $grn_id, 'grn_number' => $grn_no]);
    } catch (Exception $e) {
        $pdo->rollBack();
        wh_json(['ok' => false, 'msg' => APP_ENV === 'development' ? $e->getMessage() : 'GRN creation failed.']);
    }
}

if ($action === 'grn_detail') {
    $pdo    = db();
    $grn_id = (int)($_GET['id'] ?? 0);
    $g      = $pdo->prepare("SELECT g.*, s.name AS supplier_name FROM wh_grn g LEFT JOIN suppliers s ON s.id = g.supplier_id WHERE g.id = ?");
    $g->execute([$grn_id]); $grn = $g->fetch();
    $gi     = $pdo->prepare("SELECT gi.*, p.name AS product_name, p.sku, l.label AS location_label FROM wh_grn_items gi JOIN products p ON p.id = gi.product_id LEFT JOIN wh_locations l ON l.id = gi.location_id WHERE gi.grn_id = ?");
    $gi->execute([$grn_id]);
    wh_json(['ok' => true, 'grn' => $grn, 'items' => $gi->fetchAll()]);
}

if ($action === 'grn_qc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR, WH_ROLE_QC])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo    = db();
    $grn_id = (int)$_POST['grn_id'];
    $items  = json_decode($_POST['items'] ?? '[]', true);

    $pdo->beginTransaction();
    try {
        $total_accepted = 0; $total_rejected = 0;
        foreach ($items as $it) {
            $item_id      = (int)$it['item_id'];
            $qty_accepted = (float)$it['qty_accepted'];
            $qty_rejected = (float)$it['qty_rejected'];
            $qc_status    = $qty_rejected <= 0 ? 'passed' : ($qty_accepted <= 0 ? 'failed' : 'partial');
            $total_accepted += $qty_accepted;
            $total_rejected += $qty_rejected;

            $pdo->prepare("UPDATE wh_grn_items SET qty_accepted=?,qty_rejected=?,qc_status=?,qc_notes=?,rejection_reason=? WHERE id=?")
                ->execute([$qty_accepted, $qty_rejected, $qc_status, $it['qc_notes'] ?? '', $it['rejection_reason'] ?? '', $item_id]);
        }

        $overall = $total_rejected <= 0 ? 'qc_passed' : ($total_accepted <= 0 ? 'qc_failed' : 'partially_accepted');
        $pdo->prepare("UPDATE wh_grn SET status=?,qc_by=?,total_qty_accepted=?,total_qty_rejected=?,qc_notes=? WHERE id=?")
            ->execute([$overall, wh_me()['id'], $total_accepted, $total_rejected, trim($_POST['qc_notes'] ?? ''), $grn_id]);

        // If QC passed (or partial) — create batches and move stock
        if (in_array($overall, ['qc_passed', 'partially_accepted'])) {
            $gi = $pdo->prepare("SELECT gi.*, p.buying_price FROM wh_grn_items gi JOIN products p ON p.id = gi.product_id WHERE gi.grn_id = ? AND gi.qty_accepted > 0");
            $gi->execute([$grn_id]);
            foreach ($gi->fetchAll() as $item) {
                // Create batch
                $pdo->prepare("
                    INSERT INTO wh_batches
                      (client_id, warehouse_id, product_id, batch_number, manufacture_date,
                       expiry_date, qty_received, qty_available, status, grn_id, location_id, created_by)
                    VALUES (?,?,?,?,NULL,?,?,?,'available',?,?,?)
                    ON DUPLICATE KEY UPDATE qty_available = qty_available + VALUES(qty_available), status = 'available'
                ")->execute([
                    wh_client(), wh_warehouse(),
                    $item['product_id'], $item['batch_number'],
                    $item['expiry_date'],
                    $item['qty_accepted'], $item['qty_accepted'],
                    $grn_id, $item['location_id'],
                    wh_me()['id'],
                ]);
                $batch_id = (int)$pdo->lastInsertId();

                // Update location current_qty
                if ($item['location_id']) {
                    $pdo->prepare("UPDATE wh_locations SET current_qty = current_qty + ? WHERE id = ?")
                        ->execute([$item['qty_accepted'], $item['location_id']]);
                    // Batch location map
                    $pdo->prepare("INSERT INTO wh_batch_locations (batch_id, location_id, quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity = quantity + ?")
                        ->execute([$batch_id, $item['location_id'], $item['qty_accepted'], $item['qty_accepted']]);
                }

                // Ledger entry
                wh_ledger($pdo, [
                    'product_id'     => $item['product_id'],
                    'batch_id'       => $batch_id,
                    'location_id'    => $item['location_id'],
                    'movement_type'  => 'grn_receive',
                    'qty_in'         => $item['qty_accepted'],
                    'qty_balance'    => $item['qty_accepted'],
                    'reference_type' => 'grn',
                    'reference_id'   => $grn_id,
                    'unit_cost'      => $item['unit_cost'] ?? $item['buying_price'],
                    'notes'          => 'GRN QC passed',
                ]);

                // NOTE: branch stock sync removed from here — it now happens
                // in outbound_dispatch, when goods actually leave the warehouse.

                // ── EMAIL: reorder threshold check (fires once, not every GRN) ──
                $rl = $pdo->prepare("SELECT reorder_level, name FROM products WHERE id = ?"); $rl->execute([$item['product_id']]);
                $prow = $rl->fetch();
                $avail = $pdo->prepare("SELECT SUM(qty_available) FROM wh_batches WHERE product_id = ? AND warehouse_id = ? AND status = 'available'");
                $avail->execute([$item['product_id'], wh_warehouse()]);
                $totalAvail = (float)($avail->fetchColumn() ?: 0);
                if ($prow && $prow['reorder_level'] > 0 && $totalAvail <= $prow['reorder_level']) {
                    $flagFile = __DIR__ . '/uploads/cache/wh_low_' . wh_warehouse() . '_' . $item['product_id'] . '.flag';
                    if (!file_exists($flagFile) || (time() - filemtime($flagFile)) > 6 * 3600) {
                        @mkdir(dirname($flagFile), 0755, true); @touch($flagFile);
                        wh_notify(db(), wh_warehouse(), "🔻 Low Stock — {$prow['name']}",
                            wh_email_table([
                                wh_email_row('Product', htmlspecialchars($prow['name'], ENT_QUOTES, 'UTF-8'), true),
                                wh_email_row('Available Qty', number_format($totalAvail, 2)),
                                wh_email_row('Reorder Level', number_format($prow['reorder_level'], 2)),
                            ]), 'warning');
                    }
                }
            }

            $pdo->prepare("UPDATE wh_grn SET status = 'accepted' WHERE id = ?")->execute([$grn_id]);
        }

        // ── EMAIL: QC decision ──
        $gn = $pdo->prepare("SELECT grn_number FROM wh_grn WHERE id = ?"); $gn->execute([$grn_id]);
        $grn_no = $gn->fetchColumn() ?: "#$grn_id";
        $mailType = $overall === 'qc_failed' ? 'alert' : ($overall === 'partially_accepted' ? 'warning' : 'success');
        $bodyRows = wh_email_table([
            wh_email_row('GRN Number', $grn_no, true),
            wh_email_row('Qty Accepted', number_format($total_accepted, 2)),
            wh_email_row('Qty Rejected', number_format($total_rejected, 2)),
            wh_email_row('QC By', htmlspecialchars(wh_me()['full_name'] ?? '', ENT_QUOTES, 'UTF-8')),
        ]);
        wh_notify(db(), wh_warehouse(), ($mailType === 'alert' ? '⚠ QC Failed — ' : '✅ QC Complete — ') . $grn_no, $bodyRows, $mailType);

        $pdo->commit();
        wh_audit($pdo, 'grn_qc_completed', 'grn', $grn_id, [], ['status' => $overall]);
        wh_json(['ok' => true, 'status' => $overall]);
    } catch (Exception $e) {
        $pdo->rollBack();
        wh_json(['ok' => false, 'msg' => APP_ENV === 'development' ? $e->getMessage() : 'QC submission failed.']);
    }
}

// ── OUTBOUND ORDERS ───────────────────────────────────────────
if ($action === 'outbound_list') {
    $pdo    = db();
    $wh_id  = wh_warehouse();
    $status = $_GET['status'] ?? '';
    $params = [$wh_id];
    $extra  = '';
    if ($status) { $extra = ' AND o.status = ?'; $params[] = $status; }
    $stmt = $pdo->prepare("
        SELECT o.*, u.full_name AS requested_by_name,
               pk.full_name AS picker_name, pa.full_name AS packer_name,
               b.branch_name AS dest_branch_name
        FROM wh_outbound_orders o
        LEFT JOIN users u  ON u.id  = o.requested_by
        LEFT JOIN users pk ON pk.id = o.assigned_picker
        LEFT JOIN users pa ON pa.id = o.assigned_packer
        LEFT JOIN branches b ON b.id = o.dest_branch_id
        WHERE o.warehouse_id = ? $extra
        ORDER BY FIELD(o.priority,'urgent','high','normal','low'), o.created_at DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    wh_json(['ok' => true, 'orders' => $stmt->fetchAll()]);
}

if ($action === 'outbound_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo   = db();
    $wh_id = wh_warehouse();
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (empty($items)) wh_json(['ok' => false, 'msg' => 'Add at least one item.']);

    $order_no = gen_outbound($pdo, $wh_id);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO wh_outbound_orders
              (client_id, warehouse_id, order_number, order_type, dest_branch_id,
               customer_id, requested_by, priority, status, required_date, total_items)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            wh_client(), $wh_id, $order_no,
            $_POST['order_type'] ?? 'branch_replenishment',
            !empty($_POST['dest_branch_id']) ? (int)$_POST['dest_branch_id'] : null,
            !empty($_POST['customer_id'])    ? (int)$_POST['customer_id']    : null,
            wh_me()['id'],
            $_POST['priority'] ?? 'normal',
            'confirmed',
            !empty($_POST['required_date']) ? $_POST['required_date'] : null,
            count($items),
        ]);
        $order_id = (int)$pdo->lastInsertId();

        // FEFO (First Expired First Out) — auto-assign batches per item
        $pi = $pdo->prepare("INSERT INTO wh_pick_items (outbound_id,product_id,batch_id,location_id,qty_requested,status) VALUES (?,?,?,?,?,'pending')");
        $batch_q = $pdo->prepare("
            SELECT b.id, b.qty_available, bl.location_id
            FROM wh_batches b
            LEFT JOIN wh_batch_locations bl ON bl.batch_id = b.id
            WHERE b.product_id = ? AND b.warehouse_id = ? AND b.status = 'available' AND b.qty_available > 0
            ORDER BY ISNULL(b.expiry_date), b.expiry_date ASC
        ");

        foreach ($items as $it) {
            $qty_needed = (float)$it['qty'];
            $batch_q->execute([(int)$it['product_id'], $wh_id]);
            $batches = $batch_q->fetchAll();
            $allocated = 0;
            foreach ($batches as $b) {
                if ($allocated >= $qty_needed) break;
                $take = min($qty_needed - $allocated, (float)$b['qty_available']);
                $pi->execute([$order_id, (int)$it['product_id'], $b['id'], $b['location_id'], $take]);
                $allocated += $take;
            }
            if ($allocated < $qty_needed) {
                // Short pick — add remainder with no batch
                $pi->execute([$order_id, (int)$it['product_id'], null, null, $qty_needed - $allocated]);
            }
        }

        $pdo->commit();
        wh_audit($pdo, 'outbound_created', 'outbound_order', $order_id, [], ['order_number' => $order_no]);
        wh_json(['ok' => true, 'order_id' => $order_id, 'order_number' => $order_no]);
    } catch (Exception $e) {
        $pdo->rollBack();
        wh_json(['ok' => false, 'msg' => APP_ENV === 'development' ? $e->getMessage() : 'Order creation failed.']);
    }
}

if ($action === 'outbound_detail') {
    $pdo      = db();
    $order_id = (int)($_GET['id'] ?? 0);
    $o = $pdo->prepare("SELECT o.*, b.branch_name AS dest_branch FROM wh_outbound_orders o LEFT JOIN branches b ON b.id = o.dest_branch_id WHERE o.id = ?");
    $o->execute([$order_id]); $order = $o->fetch();
    $pi = $pdo->prepare("SELECT pi.*, p.name AS product_name, p.sku, l.label AS location_label, b.batch_number, b.expiry_date FROM wh_pick_items pi JOIN products p ON p.id = pi.product_id LEFT JOIN wh_locations l ON l.id = pi.location_id LEFT JOIN wh_batches b ON b.id = pi.batch_id WHERE pi.outbound_id = ?");
    $pi->execute([$order_id]);
    wh_json(['ok' => true, 'order' => $order, 'items' => $pi->fetchAll()]);
}

if ($action === 'outbound_pick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR, WH_ROLE_PICKER])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo      = db();
    $order_id = (int)$_POST['order_id'];
    $picks    = json_decode($_POST['picks'] ?? '[]', true);
    $pdo->beginTransaction();
    try {
        foreach ($picks as $pk) {
            $item_id    = (int)$pk['item_id'];
            $qty_picked = (float)$pk['qty_picked'];

            // Lock the pick item row so two pickers can't process the same line twice
            $row = $pdo->prepare("SELECT * FROM wh_pick_items WHERE id = ? FOR UPDATE");
            $row->execute([$item_id]);
            $item = $row->fetch();
            if (!$item) continue;

            if ($qty_picked > 0 && $item['batch_id']) {
                // ── LOCK the batch row before reading/deducting qty_available ──
                $bl = $pdo->prepare("SELECT qty_available FROM wh_batches WHERE id = ? FOR UPDATE");
                $bl->execute([$item['batch_id']]);
                $batch = $bl->fetch();
                $available = (float)($batch['qty_available'] ?? 0);

                if ($available < $qty_picked) {
                    $pdo->rollBack();
                    wh_json(['ok' => false, 'msg' => "Not enough stock left in batch for this item — only {$available} available. Someone else may have just picked it. Please refresh and try again."]);
                }

                $pdo->prepare("UPDATE wh_batches SET qty_available = qty_available - ?, qty_reserved = qty_reserved + ? WHERE id=?")
                    ->execute([$qty_picked, $qty_picked, $item['batch_id']]);

                if ($item['location_id']) {
                    $pdo->prepare("UPDATE wh_locations SET current_qty = GREATEST(0, current_qty - ?) WHERE id=?")
                        ->execute([$qty_picked, $item['location_id']]);
                    $pdo->prepare("UPDATE wh_batch_locations SET quantity = GREATEST(0, quantity - ?) WHERE batch_id=? AND location_id=?")
                        ->execute([$qty_picked, $item['batch_id'], $item['location_id']]);
                }
                wh_ledger($pdo, [
                    'product_id'     => $item['product_id'],
                    'batch_id'       => $item['batch_id'],
                    'location_id'    => $item['location_id'],
                    'movement_type'  => 'pick',
                    'qty_out'        => $qty_picked,
                    'qty_balance'    => 0,
                    'reference_type' => 'outbound_order',
                    'reference_id'   => $order_id,
                    'notes'          => "Pick for order #$order_id",
                ]);
            }

            $qty_short = max(0, (float)$item['qty_requested'] - $qty_picked);
            $status    = $qty_short > 0 ? 'partial' : 'picked';
            $pdo->prepare("UPDATE wh_pick_items SET qty_picked=?,qty_short=?,status=?,picked_by=?,picked_at=NOW() WHERE id=?")
                ->execute([$qty_picked, $qty_short, $status, wh_me()['id'], $item_id]);
        }

        // Update order status
        $all = $pdo->prepare("SELECT status FROM wh_pick_items WHERE outbound_id = ?");
        $all->execute([$order_id]); $statuses = array_column($all->fetchAll(), 'status');
        $new_status = count(array_filter($statuses, fn($s) => $s === 'picked')) === count($statuses) ? 'picked' : 'picking';
        $pdo->prepare("UPDATE wh_outbound_orders SET status=?,assigned_picker=? WHERE id=?")
            ->execute([$new_status, wh_me()['id'], $order_id]);

        $pdo->commit();
        wh_json(['ok' => true, 'status' => $new_status]);
    } catch (Exception $e) {
        $pdo->rollBack();
        wh_json(['ok' => false, 'msg' => APP_ENV === 'development' ? $e->getMessage() : 'Pick failed.']);
    }
}

if ($action === 'outbound_dispatch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR, WH_ROLE_PACKER])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo      = db();
    $order_id = (int)$_POST['order_id'];

    $ord = $pdo->prepare("SELECT * FROM wh_outbound_orders WHERE id = ?");
    $ord->execute([$order_id]);
    $order = $ord->fetch();
    if (!$order) wh_json(['ok' => false, 'msg' => 'Order not found.']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE wh_outbound_orders SET status='dispatched', dispatched_at=NOW(), dispatch_notes=?, assigned_packer=? WHERE id=?")
            ->execute([trim($_POST['notes'] ?? ''), wh_me()['id'], $order_id]);

        // Release reserved batches + ledger entry
        $picks = $pdo->prepare("SELECT * FROM wh_pick_items WHERE outbound_id = ? AND batch_id IS NOT NULL AND qty_picked > 0");
        $picks->execute([$order_id]);
        foreach ($picks->fetchAll() as $pk) {
            $pdo->prepare("UPDATE wh_batches SET qty_reserved = GREATEST(0, qty_reserved - ?), qty_consumed = qty_consumed + ? WHERE id=?")
                ->execute([$pk['qty_picked'], $pk['qty_picked'], $pk['batch_id']]);
            wh_ledger($pdo, [
                'product_id'     => $pk['product_id'],
                'batch_id'       => $pk['batch_id'],
                'movement_type'  => 'dispatch',
                'qty_out'        => $pk['qty_picked'],
                'qty_balance'    => 0,
                'reference_type' => 'outbound_order',
                'reference_id'   => $order_id,
            ]);
        }

        // ── BRANCH STOCK SYNC — moved here from grn_qc/production_complete ──
        // Only push into the till's sellable stock now, at the moment goods
        // actually leave the warehouse, and only for orders genuinely
        // restocking a branch (not customer orders, inter-warehouse moves,
        // production supply, or supplier returns).
        if ($order['order_type'] === 'branch_replenishment' && $order['dest_branch_id']) {
            $totals = $pdo->prepare("
                SELECT product_id, SUM(qty_picked) AS qty
                FROM wh_pick_items
                WHERE outbound_id = ? AND qty_picked > 0
                GROUP BY product_id
            ");
            $totals->execute([$order_id]);
            $su = $pdo->prepare("
                INSERT INTO stock (branch_id, product_id, quantity)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ");
            foreach ($totals->fetchAll() as $t) {
                $su->execute([$order['dest_branch_id'], $t['product_id'], $t['qty'], $t['qty']]);
            }
        }

        // ── EMAIL: dispatch confirmation ──
        $destName = $order['dest_branch_id'] ? (function() use ($pdo, $order) {
            $b = $pdo->prepare("SELECT branch_name FROM branches WHERE id = ?"); $b->execute([$order['dest_branch_id']]);
            return $b->fetchColumn() ?: 'Branch';
        })() : ucwords(str_replace('_',' ', $order['order_type']));
        $bodyRows = wh_email_table([
            wh_email_row('Order Number', $order['order_number'], true),
            wh_email_row('Type', ucwords(str_replace('_',' ', $order['order_type']))),
            wh_email_row('Destination', htmlspecialchars($destName, ENT_QUOTES, 'UTF-8')),
            wh_email_row('Dispatched By', htmlspecialchars(wh_me()['full_name'] ?? '', ENT_QUOTES, 'UTF-8')),
        ]);
        wh_notify(db(), $order['warehouse_id'], "🚚 Order Dispatched — {$order['order_number']}", $bodyRows, 'success');

        $pdo->commit();
        wh_audit($pdo, 'order_dispatched', 'outbound_order', $order_id);
        wh_json(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        wh_json(['ok' => false, 'msg' => APP_ENV === 'development' ? $e->getMessage() : 'Dispatch failed.']);
    }
}

// ── BOM ───────────────────────────────────────────────────────
if ($action === 'bom_list') {
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT b.*, p.name AS product_name, p.sku,
               COUNT(bi.id) AS ingredient_count
        FROM wh_bom b
        JOIN products p ON p.id = b.finished_product_id
        LEFT JOIN wh_bom_items bi ON bi.bom_id = b.id
        WHERE b.client_id = ?
        GROUP BY b.id ORDER BY p.name
    ");
    $stmt->execute([wh_client()]);
    wh_json(['ok' => true, 'boms' => $stmt->fetchAll()]);
}

if ($action === 'bom_detail') {
    $pdo    = db();
    $bom_id = (int)($_GET['id'] ?? 0);
    $b  = $pdo->prepare("SELECT b.*, p.name AS product_name FROM wh_bom b JOIN products p ON p.id = b.finished_product_id WHERE b.id = ?");
    $b->execute([$bom_id]); $bom = $b->fetch();
    $bi = $pdo->prepare("SELECT bi.*, p.name AS input_name, p.sku FROM wh_bom_items bi JOIN products p ON p.id = bi.input_product_id WHERE bi.bom_id = ?");
    $bi->execute([$bom_id]);
    wh_json(['ok' => true, 'bom' => $bom, 'items' => $bi->fetchAll()]);
}

if ($action === 'bom_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo   = db();
    $id    = (int)($_POST['id'] ?? 0);
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (empty($items)) wh_json(['ok' => false, 'msg' => 'Add at least one ingredient.']);
    $data = [
        wh_client(),
        (int)$_POST['finished_product_id'],
        trim($_POST['bom_name'] ?? ''),
        trim($_POST['version'] ?? 'v1'),
        (float)($_POST['qty_produced'] ?? 1),
        (int)($_POST['is_active'] ?? 1),
        trim($_POST['notes'] ?? '') ?: null,
        wh_me()['id'],
    ];
    if ($id) {
        $pdo->prepare("UPDATE wh_bom SET client_id=?,finished_product_id=?,bom_name=?,version=?,qty_produced=?,is_active=?,notes=? WHERE id=?")
            ->execute([...$data, $id]);
        $pdo->prepare("DELETE FROM wh_bom_items WHERE bom_id = ?")->execute([$id]);
    } else {
        $pdo->prepare("INSERT INTO wh_bom (client_id,finished_product_id,bom_name,version,qty_produced,is_active,notes,created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute($data);
        $id = (int)$pdo->lastInsertId();
    }
    $bi = $pdo->prepare("INSERT INTO wh_bom_items (bom_id,input_product_id,qty_required,unit,waste_pct,notes) VALUES (?,?,?,?,?,?)");
    foreach ($items as $it) {
        $bi->execute([$id, (int)$it['product_id'], (float)$it['qty'], trim($it['unit'] ?? ''), (float)($it['waste_pct'] ?? 0), trim($it['notes'] ?? '') ?: null]);
    }
    wh_json(['ok' => true, 'id' => $id]);
}

// ── PRODUCTION ORDERS ─────────────────────────────────────────
if ($action === 'production_list') {
    $pdo   = db();
    $wh_id = wh_warehouse();
    $stmt  = $pdo->prepare("
        SELECT po.*, b.bom_name, p.name AS product_name,
               u.full_name AS assigned_name,
               l.label AS output_location
        FROM wh_production_orders po
        JOIN wh_bom b ON b.id = po.bom_id
        JOIN products p ON p.id = po.finished_product_id
        LEFT JOIN users u ON u.id = po.assigned_to
        LEFT JOIN wh_locations l ON l.id = po.output_location_id
        WHERE po.warehouse_id = ?
        ORDER BY po.created_at DESC LIMIT 50
    ");
    $stmt->execute([$wh_id]);
    wh_json(['ok' => true, 'orders' => $stmt->fetchAll()]);
}

if ($action === 'production_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo    = db();
    $wh_id  = wh_warehouse();
    $bom_id = (int)$_POST['bom_id'];
    $qty    = (float)$_POST['qty_planned'];

    // Fetch BOM
    $bom = $pdo->prepare("SELECT b.*, p.id AS fp_id FROM wh_bom b JOIN products p ON p.id = b.finished_product_id WHERE b.id = ? AND b.client_id = ?");
    $bom->execute([$bom_id, wh_client()]); $bom_row = $bom->fetch();
    if (!$bom_row) wh_json(['ok' => false, 'msg' => 'BOM not found.']);

    $order_no = gen_production($pdo, $wh_id);
    $multiplier = $qty / max(1, (float)$bom_row['qty_produced']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO wh_production_orders
              (client_id, warehouse_id, order_number, bom_id, finished_product_id,
               qty_planned, status, planned_start, planned_end, output_location_id, assigned_to, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            wh_client(), $wh_id, $order_no, $bom_id, $bom_row['fp_id'],
            $qty, 'draft',
            $_POST['planned_start'] ?? null,
            $_POST['planned_end']   ?? null,
            !empty($_POST['output_location_id']) ? (int)$_POST['output_location_id'] : null,
            !empty($_POST['assigned_to'])         ? (int)$_POST['assigned_to']        : null,
            wh_me()['id'],
        ]);
        $prod_id = (int)$pdo->lastInsertId();

        // Pre-populate inputs from BOM
        $ingredients = $pdo->prepare("SELECT * FROM wh_bom_items WHERE bom_id = ?");
        $ingredients->execute([$bom_id]);
        $pi = $pdo->prepare("INSERT INTO wh_production_inputs (production_id,bom_item_id,product_id,qty_planned) VALUES (?,?,?,?)");
        foreach ($ingredients->fetchAll() as $ing) {
            $qty_with_waste = $ing['qty_required'] * $multiplier * (1 + $ing['waste_pct'] / 100);
            $pi->execute([$prod_id, $ing['id'], $ing['input_product_id'], round($qty_with_waste, 4)]);
        }

        $pdo->commit();
        wh_audit($pdo, 'production_created', 'production_order', $prod_id, [], ['order_number' => $order_no]);
        wh_json(['ok' => true, 'prod_id' => $prod_id, 'order_number' => $order_no]);
    } catch (Exception $e) {
        $pdo->rollBack();
        wh_json(['ok' => false, 'msg' => APP_ENV === 'development' ? $e->getMessage() : 'Production order failed.']);
    }
}

if ($action === 'production_detail') {
    $pdo     = db();
    $prod_id = (int)($_GET['id'] ?? 0);
    $po = $pdo->prepare("SELECT po.*, b.bom_name, p.name AS product_name, l.label AS output_location FROM wh_production_orders po JOIN wh_bom b ON b.id = po.bom_id JOIN products p ON p.id = po.finished_product_id LEFT JOIN wh_locations l ON l.id = po.output_location_id WHERE po.id = ?");
    $po->execute([$prod_id]); $order = $po->fetch();
    $pi = $pdo->prepare("SELECT pi.*, p.name AS product_name, p.sku, b.batch_number, l.label AS location_label FROM wh_production_inputs pi JOIN products p ON p.id = pi.product_id LEFT JOIN wh_batches b ON b.id = pi.batch_id LEFT JOIN wh_locations l ON l.id = pi.location_id WHERE pi.production_id = ?");
    $pi->execute([$prod_id]);
    wh_json(['ok' => true, 'order' => $order, 'inputs' => $pi->fetchAll()]);
}

if ($action === 'production_start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo     = db();
    $prod_id = (int)$_POST['prod_id'];
    $pdo->prepare("UPDATE wh_production_orders SET status='in_progress', actual_start=NOW() WHERE id=?")->execute([$prod_id]);
    wh_json(['ok' => true]);
}

if ($action === 'production_complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo         = db();
    $prod_id     = (int)$_POST['prod_id'];
    $qty_produced = (float)$_POST['qty_produced'];
    $qty_rejected = (float)($_POST['qty_rejected'] ?? 0);
    $inputs       = json_decode($_POST['inputs'] ?? '[]', true);
    $output_batch = 'FG-' . date('Ymd') . '-' . rand(1000, 9999);

    // Fetch order
    $ord = $pdo->prepare("SELECT * FROM wh_production_orders WHERE id = ?");
    $ord->execute([$prod_id]); $order = $ord->fetch();

    $pdo->beginTransaction();
    try {
        // Update input consumption
        foreach ($inputs as $inp) {
            $pdo->prepare("UPDATE wh_production_inputs SET qty_actual=?,qty_wasted=?,batch_id=?,location_id=?,issued_by=?,issued_at=NOW() WHERE id=?")
                ->execute([(float)$inp['qty_actual'], (float)($inp['qty_wasted'] ?? 0), !empty($inp['batch_id']) ? (int)$inp['batch_id'] : null, !empty($inp['location_id']) ? (int)$inp['location_id'] : null, wh_me()['id'], (int)$inp['input_id']]);

            // Deduct from batch and location
            if (!empty($inp['batch_id']) && (float)$inp['qty_actual'] > 0) {
                $pdo->prepare("UPDATE wh_batches SET qty_available = GREATEST(0, qty_available - ?), qty_consumed = qty_consumed + ? WHERE id=?")
                    ->execute([(float)$inp['qty_actual'], (float)$inp['qty_actual'], (int)$inp['batch_id']]);
                if (!empty($inp['location_id'])) {
                    $pdo->prepare("UPDATE wh_locations SET current_qty = GREATEST(0, current_qty - ?) WHERE id=?")
                        ->execute([(float)$inp['qty_actual'], (int)$inp['location_id']]);
                }
                wh_ledger($pdo, [
                    'product_id'     => $inp['product_id'],
                    'batch_id'       => $inp['batch_id'],
                    'location_id'    => $inp['location_id'] ?? null,
                    'movement_type'  => 'production_issue',
                    'qty_out'        => (float)$inp['qty_actual'],
                    'qty_balance'    => 0,
                    'reference_type' => 'production_order',
                    'reference_id'   => $prod_id,
                ]);
            }
        }

        // Create finished goods batch
        if ($qty_produced > 0) {
            $pdo->prepare("
                INSERT INTO wh_batches
                  (client_id, warehouse_id, product_id, batch_number, qty_received, qty_available, status, location_id, created_by)
                VALUES (?,?,?,?,?,?,'available',?,?)
            ")->execute([wh_client(), wh_warehouse(), $order['finished_product_id'], $output_batch, $qty_produced, $qty_produced, $order['output_location_id'], wh_me()['id']]);
            $fg_batch_id = (int)$pdo->lastInsertId();

            if ($order['output_location_id']) {
                $pdo->prepare("UPDATE wh_locations SET current_qty = current_qty + ? WHERE id=?")
                    ->execute([$qty_produced, $order['output_location_id']]);
                $pdo->prepare("INSERT INTO wh_batch_locations (batch_id, location_id, quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity = quantity + ?")
                    ->execute([$fg_batch_id, $order['output_location_id'], $qty_produced, $qty_produced]);
            }

            wh_ledger($pdo, [
                'product_id'     => $order['finished_product_id'],
                'batch_id'       => $fg_batch_id,
                'location_id'    => $order['output_location_id'],
                'movement_type'  => 'production_output',
                'qty_in'         => $qty_produced,
                'qty_balance'    => $qty_produced,
                'reference_type' => 'production_order',
                'reference_id'   => $prod_id,
            ]);

            // NOTE: branch stock sync removed from here — it now happens
            // in outbound_dispatch, when goods actually leave the warehouse.
        }

        $final_status = $qty_produced >= (float)$order['qty_planned'] ? 'completed' : 'partially_completed';
        $pdo->prepare("UPDATE wh_production_orders SET status=?,qty_produced=?,qty_rejected=?,actual_end=NOW(),output_batch=? WHERE id=?")
            ->execute([$final_status, $qty_produced, $qty_rejected, $output_batch, $prod_id]);

        // ── EMAIL: production run complete ──
        $pn = $pdo->prepare("SELECT p.name FROM products p WHERE p.id = ?"); $pn->execute([$order['finished_product_id']]);
        $prodName = $pn->fetchColumn() ?: 'Product';
        $bodyRows = wh_email_table([
            wh_email_row('Product', htmlspecialchars($prodName, ENT_QUOTES, 'UTF-8'), true),
            wh_email_row('Output Batch', $output_batch),
            wh_email_row('Qty Produced', number_format($qty_produced, 2)),
            wh_email_row('Qty Rejected', number_format($qty_rejected, 2)),
        ]);
        wh_notify(db(), wh_warehouse(), "🏭 Production Complete — {$prodName}", $bodyRows, 'success');

        $pdo->commit();
        wh_audit($pdo, 'production_completed', 'production_order', $prod_id, [], ['qty_produced' => $qty_produced, 'batch' => $output_batch]);
        wh_json(['ok' => true, 'output_batch' => $output_batch]);
    } catch (Exception $e) {
        $pdo->rollBack();
        wh_json(['ok' => false, 'msg' => APP_ENV === 'development' ? $e->getMessage() : 'Completion failed.']);
    }
}

// ── DELIVERY SCHEDULE ─────────────────────────────────────────
if ($action === 'delivery_list') {
    $pdo   = db();
    $wh_id = wh_warehouse();
    $from  = $_GET['from'] ?? wh_today();
    $to    = $_GET['to']   ?? date('Y-m-d', strtotime('+7 days'));
    $stmt  = $pdo->prepare("
        SELECT ds.*, s.name AS supplier_name
        FROM wh_delivery_schedule ds
        LEFT JOIN suppliers s ON s.id = ds.supplier_id
        WHERE ds.warehouse_id = ? AND ds.scheduled_date BETWEEN ? AND ?
        ORDER BY ds.scheduled_date ASC, ds.scheduled_time ASC
    ");
    $stmt->execute([$wh_id, $from, $to]);
    wh_json(['ok' => true, 'deliveries' => $stmt->fetchAll()]);
}

if ($action === 'delivery_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR, WH_ROLE_RECEIVING])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo   = db();
    $id    = (int)($_POST['id'] ?? 0);
    $wh_id = wh_warehouse();
    $data  = [
        wh_client(), $wh_id,
        !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
        !empty($_POST['po_id'])       ? (int)$_POST['po_id']       : null,
        $_POST['scheduled_date'] ?? wh_today(),
        !empty($_POST['scheduled_time']) ? $_POST['scheduled_time'] : null,
        $_POST['status'] ?? 'scheduled',
        trim($_POST['vehicle_reg']  ?? '') ?: null,
        trim($_POST['driver_name']  ?? '') ?: null,
        trim($_POST['driver_phone'] ?? '') ?: null,
        trim($_POST['dock_door']    ?? '') ?: null,
        trim($_POST['notes']        ?? '') ?: null,
        wh_me()['id'],
    ];
    if ($id) {
        $pdo->prepare("UPDATE wh_delivery_schedule SET client_id=?,warehouse_id=?,supplier_id=?,po_id=?,scheduled_date=?,scheduled_time=?,status=?,vehicle_reg=?,driver_name=?,driver_phone=?,dock_door=?,notes=? WHERE id=?")
            ->execute([...$data, $id]);
    } else {
        $pdo->prepare("INSERT INTO wh_delivery_schedule (client_id,warehouse_id,supplier_id,po_id,scheduled_date,scheduled_time,status,vehicle_reg,driver_name,driver_phone,dock_door,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute($data);
        $id = (int)$pdo->lastInsertId();
    }
    wh_json(['ok' => true, 'id' => $id]);
}

// ── STOCK COUNT ───────────────────────────────────────────────
if ($action === 'count_list') {
    $pdo   = db();
    $wh_id = wh_warehouse();
    $stmt  = $pdo->prepare("SELECT sc.*, u.full_name AS created_by_name FROM wh_stock_counts sc LEFT JOIN users u ON u.id = sc.created_by WHERE sc.warehouse_id = ? ORDER BY sc.created_at DESC LIMIT 30");
    $stmt->execute([$wh_id]);
    wh_json(['ok' => true, 'counts' => $stmt->fetchAll()]);
}

if ($action === 'count_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo    = db();
    $wh_id  = wh_warehouse();
    $cnt_no = gen_count($pdo, $wh_id);
    $type   = $_POST['count_type'] ?? 'full';
    $pdo->prepare("INSERT INTO wh_stock_counts (client_id, warehouse_id, count_number, count_type, zone_id, count_date, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([wh_client(), $wh_id, $cnt_no, $type, !empty($_POST['zone_id']) ? (int)$_POST['zone_id'] : null, $_POST['count_date'] ?? wh_today(), 'in_progress', trim($_POST['notes'] ?? '') ?: null, wh_me()['id']]);
    $cnt_id = (int)$pdo->lastInsertId();

    // Pre-populate count items from current location stock
    $q_loc = "SELECT l.id AS location_id, bl.product_id, bl.batch_id, bl.quantity AS system_qty FROM wh_batch_locations bl JOIN wh_locations l ON l.id = bl.location_id JOIN wh_zones z ON z.id = l.zone_id WHERE l.warehouse_id = ?";
    $params = [$wh_id];
    if ($type === 'zone' && !empty($_POST['zone_id'])) { $q_loc .= ' AND z.id = ?'; $params[] = (int)$_POST['zone_id']; }
    $locs = $pdo->prepare($q_loc); $locs->execute($params);
    $ci = $pdo->prepare("INSERT INTO wh_stock_count_items (count_id, product_id, batch_id, location_id, system_qty, status) VALUES (?,?,?,?,?,'pending')");
    foreach ($locs->fetchAll() as $loc) {
        $ci->execute([$cnt_id, $loc['product_id'], $loc['batch_id'], $loc['location_id'], $loc['system_qty']]);
    }
    wh_json(['ok' => true, 'count_id' => $cnt_id, 'count_number' => $cnt_no]);
}

if ($action === 'count_items') {
    $pdo      = db();
    $count_id = (int)($_GET['count_id'] ?? 0);
    $stmt     = $pdo->prepare("
        SELECT ci.*, p.name AS product_name, p.sku, l.label AS location_label,
               b.batch_number, b.expiry_date, z.name AS zone_name
        FROM wh_stock_count_items ci
        JOIN products p ON p.id = ci.product_id
        JOIN wh_locations l ON l.id = ci.location_id
        JOIN wh_zones z ON z.id = l.zone_id
        LEFT JOIN wh_batches b ON b.id = ci.batch_id
        WHERE ci.count_id = ?
        ORDER BY z.name, l.label, p.name
    ");
    $stmt->execute([$count_id]);
    wh_json(['ok' => true, 'items' => $stmt->fetchAll()]);
}

if ($action === 'count_submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    $pdo      = db();
    $count_id = (int)$_POST['count_id'];
    $items    = json_decode($_POST['items'] ?? '[]', true);
    foreach ($items as $it) {
        $counted  = (float)$it['counted_qty'];
        $variance = $counted - (float)$it['system_qty'];
        $val      = !empty($it['unit_cost']) ? abs($variance) * (float)$it['unit_cost'] : null;
        $pdo->prepare("UPDATE wh_stock_count_items SET counted_qty=?,variance_value=?,counted_by=?,counted_at=NOW(),status='counted' WHERE id=?")
            ->execute([$counted, $val, wh_me()['id'], (int)$it['item_id']]);
    }
    $pdo->prepare("UPDATE wh_stock_counts SET status='completed' WHERE id=?")->execute([$count_id]);
    wh_json(['ok' => true]);
}

if ($action === 'count_approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo      = db();
    $count_id = (int)$_POST['count_id'];
    $items    = $pdo->prepare("SELECT * FROM wh_stock_count_items WHERE count_id = ? AND counted_qty IS NOT NULL");
    $items->execute([$count_id]);
    $pdo->beginTransaction();
    try {
        foreach ($items->fetchAll() as $it) {
            $variance = (float)$it['variance'];
            if (abs($variance) < 0.001) continue;
            $type = $variance > 0 ? 'adjustment_add' : 'adjustment_remove';
            // Adjust batch
            if ($it['batch_id']) {
                $pdo->prepare("UPDATE wh_batches SET qty_available = GREATEST(0, qty_available + ?) WHERE id=?")
                    ->execute([$variance, $it['batch_id']]);
            }
            if ($it['location_id']) {
                $pdo->prepare("UPDATE wh_locations SET current_qty = GREATEST(0, current_qty + ?) WHERE id=?")
                    ->execute([$variance, $it['location_id']]);
            }
            wh_ledger($pdo, [
                'product_id'     => $it['product_id'],
                'batch_id'       => $it['batch_id'],
                'location_id'    => $it['location_id'],
                'movement_type'  => $type,
                'qty_in'         => $variance > 0 ? $variance : 0,
                'qty_out'        => $variance < 0 ? abs($variance) : 0,
                'qty_balance'    => (float)$it['counted_qty'],
                'reference_type' => 'stock_count',
                'reference_id'   => $count_id,
                'notes'          => 'Stock count variance adjustment',
            ]);
            $pdo->prepare("UPDATE wh_stock_count_items SET status='adjusted' WHERE id=?")->execute([$it['id']]);
        }
        $pdo->prepare("UPDATE wh_stock_counts SET status='approved', approved_by=?, approved_at=NOW() WHERE id=?")->execute([wh_me()['id'], $count_id]);
        $pdo->commit();
        wh_json(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        wh_json(['ok' => false, 'msg' => APP_ENV === 'development' ? $e->getMessage() : 'Approval failed.']);
    }
}

// ── LEDGER / MOVEMENTS ────────────────────────────────────────
if ($action === 'ledger_list') {
    $pdo       = db();
    $wh_id     = wh_warehouse();
    $product_id = (int)($_GET['product_id'] ?? 0);
    $type      = $_GET['type'] ?? '';
    $from      = $_GET['from'] ?? wh_today();
    $to        = $_GET['to']   ?? wh_today();
    $params    = [$wh_id, $from . ' 00:00:00', $to . ' 23:59:59'];
    $extra     = '';
    if ($product_id) { $extra .= ' AND l.product_id = ?'; $params[] = $product_id; }
    if ($type)       { $extra .= ' AND l.movement_type = ?'; $params[] = $type; }
    $stmt = $pdo->prepare("
        SELECT l.*, p.name AS product_name, p.sku,
               loc.label AS location_label, b.batch_number,
               u.full_name AS performed_by_name
        FROM wh_stock_ledger l
        JOIN products p ON p.id = l.product_id
        LEFT JOIN wh_locations loc ON loc.id = l.location_id
        LEFT JOIN wh_batches b ON b.id = l.batch_id
        LEFT JOIN users u ON u.id = l.performed_by
        WHERE l.warehouse_id = ? AND l.created_at BETWEEN ? AND ? $extra
        ORDER BY l.created_at DESC LIMIT 200
    ");
    $stmt->execute($params);
    wh_json(['ok' => true, 'movements' => $stmt->fetchAll()]);
}

// ── INTER-WAREHOUSE TRANSFERS ─────────────────────────────────
if ($action === 'wh_transfer_list') {
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT t.*, wf.name AS from_wh, wt.name AS to_wh, u.full_name AS requested_by_name
        FROM wh_transfers t
        JOIN wh_warehouses wf ON wf.id = t.from_warehouse_id
        JOIN wh_warehouses wt ON wt.id = t.to_warehouse_id
        LEFT JOIN users u ON u.id = t.requested_by
        WHERE t.client_id = ?
        ORDER BY t.created_at DESC LIMIT 50
    ");
    $stmt->execute([wh_client()]);
    wh_json(['ok' => true, 'transfers' => $stmt->fetchAll()]);
}

if ($action === 'wh_transfer_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wh_csrf_check();
    if (!wh_can([WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR])) wh_json(['ok' => false, 'msg' => 'No permission.']);
    $pdo   = db();
    $cid   = wh_client();
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (empty($items)) wh_json(['ok' => false, 'msg' => 'Add at least one item.']);
    $from = (int)$_POST['from_warehouse_id'];
    $to   = (int)$_POST['to_warehouse_id'];
    if ($from === $to) wh_json(['ok' => false, 'msg' => 'From and To warehouse must differ.']);
    $t_no = gen_transfer($pdo, $cid);
    $pdo->prepare("INSERT INTO wh_transfers (client_id,transfer_number,from_warehouse_id,to_warehouse_id,requested_by,notes) VALUES (?,?,?,?,?,?)")
        ->execute([$cid, $t_no, $from, $to, wh_me()['id'], trim($_POST['notes'] ?? '') ?: null]);
    $t_id = (int)$pdo->lastInsertId();
    $ti   = $pdo->prepare("INSERT INTO wh_transfer_items (transfer_id,product_id,batch_id,from_location_id,to_location_id,qty_requested) VALUES (?,?,?,?,?,?)");
    foreach ($items as $it) {
        $ti->execute([$t_id, (int)$it['product_id'], !empty($it['batch_id']) ? (int)$it['batch_id'] : null, !empty($it['from_location_id']) ? (int)$it['from_location_id'] : null, !empty($it['to_location_id']) ? (int)$it['to_location_id'] : null, (float)$it['qty']]);
    }
    wh_json(['ok' => true, 'transfer_id' => $t_id, 'transfer_number' => $t_no]);
}

// ── PRODUCTS (from HMS) ───────────────────────────────────────
if ($action === 'wh_products') {
    $pdo  = db();
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt = $pdo->prepare("SELECT id, name, sku, buying_price, selling_price, unit FROM products WHERE client_id = ? AND is_active = 1 AND (name LIKE ? OR sku LIKE ?) ORDER BY name LIMIT 60");
    $stmt->execute([wh_client(), $q, $q]);
    wh_json(['ok' => true, 'products' => $stmt->fetchAll()]);
}

// ── SUPPLIERS (from HMS) ──────────────────────────────────────
if ($action === 'wh_suppliers') {
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT id, name, phone FROM suppliers WHERE client_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([wh_client()]);
    wh_json(['ok' => true, 'suppliers' => $stmt->fetchAll()]);
}

// ── BRANCHES (from HMS) ───────────────────────────────────────
if ($action === 'wh_branches') {
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT id, branch_name AS name FROM branches WHERE client_id = ? AND is_active = 1 ORDER BY branch_name");
    $stmt->execute([wh_client()]);
    wh_json(['ok' => true, 'branches' => $stmt->fetchAll()]);
}

// ── STAFF USERS (from HMS) ────────────────────────────────────
if ($action === 'wh_users') {
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT u.id, u.full_name, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id LEFT JOIN branches b ON b.id = u.branch_id WHERE b.client_id = ? AND u.is_active = 1 ORDER BY u.full_name");
    $stmt->execute([wh_client()]);
    wh_json(['ok' => true, 'users' => $stmt->fetchAll()]);
}

// ── CRON: EXPIRING BATCH ALERT ─────────────────────────────────
define('WH_CRON_SECRET', 'change-this-secret-2026');
if ($action === 'cron_expiry_alert') {
    if (($_GET['secret'] ?? '') !== WH_CRON_SECRET) wh_json(['ok' => false, 'msg' => 'Forbidden.']);
    $pdo = db();
    $whs = $pdo->query("SELECT id, name FROM wh_warehouses WHERE is_active = 1")->fetchAll();
    foreach ($whs as $w) {
        $exp = $pdo->prepare("
            SELECT b.batch_number, b.expiry_date, b.qty_available, p.name AS product_name
            FROM wh_batches b JOIN products p ON p.id = b.product_id
            WHERE b.warehouse_id = ? AND b.status = 'available' AND b.qty_available > 0
              AND b.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ORDER BY b.expiry_date ASC
        ");
        $exp->execute([$w['id']]);
        $rows = $exp->fetchAll();
        if (!$rows) continue;
        $tableRows = array_map(fn($r) => wh_email_row(
            htmlspecialchars($r['product_name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($r['batch_number'], ENT_QUOTES, 'UTF-8') . ')',
            number_format($r['qty_available'],2) . ' units — exp ' . $r['expiry_date']
        ), $rows);
        wh_notify($pdo, $w['id'], "⏳ " . count($rows) . " Batch(es) Expiring Soon — {$w['name']}", wh_email_table($tableRows), 'warning');
    }
    wh_json(['ok' => true, 'checked' => count($whs)]);
}

// ── UNKNOWN ACTION ────────────────────────────────────────────
if ($action) { wh_json(['ok' => false, 'msg' => 'Unknown action.']); }

// ════════════════════════════════════════════════════════════
//  PAGE RENDER
// ════════════════════════════════════════════════════════════
// ── PAGE-LEVEL AUTH GUARD ─────────────────────────────────────
if (wh_logged_in()) {
    $allowed_wms_roles = [WH_ROLE_OWNER, WH_ROLE_MANAGER, WH_ROLE_WH_MGR,
                          WH_ROLE_RECEIVING, WH_ROLE_PICKER, WH_ROLE_PACKER, WH_ROLE_QC];
    if (!in_array(wh_role(), $allowed_wms_roles)) {
        session_unset(); session_destroy();
        wh_redirect('/nymix_hardwares/warehouse.php');
    }
    $pdo_check = db();
    if (!wh_check_subscription($pdo_check, wh_client())) {
        session_unset(); session_destroy();
        wh_redirect('/nymix_hardwares/staff_portal.php?err=subscription_expired');
    }
}

$logged_in   = wh_logged_in();
$user        = wh_me();
$csrf_token  = wh_csrf();
$wh_id_curr  = wh_warehouse();

// Load warehouse list for switcher
$wh_list = [];
if ($logged_in) {
    try {
        $pdo = db(); 
        $current_role = (int)($_SESSION['wh_staff']['role_id'] ?? 0);
        if (in_array($current_role, [WH_ROLE_OWNER, WH_ROLE_WH_MGR])) {
            $ws = $pdo->prepare("SELECT id, name, code FROM wh_warehouses WHERE client_id = ? AND is_active = 1 ORDER BY name");
            $ws->execute([wh_client()]);
        } else {
            $ws = $pdo->prepare("
                SELECT w.id, w.name, w.code FROM wh_warehouses w
                JOIN wh_user_warehouse uw ON uw.warehouse_id = w.id
                WHERE w.client_id = ? AND w.is_active = 1 AND uw.user_id = ?
                ORDER BY w.name
            ");
            $ws->execute([wh_client(), $_SESSION['wh_staff_id']]);
        }
        $wh_list = $ws->fetchAll();
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NYMIX WMS — Warehouse Management</title>
<link rel="icon" type="image/png" href="logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ── TOKENS ─────────────────────────────────────────────────── */
:root, [data-theme="dark"] {
  --bg:       #0a0d14;
  --surface:  #111520;
  --card:     #161b28;
  --border:   #1e2535;
  --border2:  #252d40;
  --accent:   #00d4aa;   /* teal — warehouse/industrial */
  --accent2:  #0ea5e9;   /* sky blue */
  --warn:     #f59e0b;
  --danger:   #ef4444;
  --success:  #22c55e;
  --purple:   #a78bfa;
  --text:     #e2e8f0;
  --muted:    #64748b;
  --muted2:   #94a3b8;
  --sidebar:  220px;
  --topbar:   56px;
  --mono:     'JetBrains Mono', monospace;
  --head:     'Space Grotesk', sans-serif;
  --body:     'DM Sans', sans-serif;
}
[data-theme="light"] {
  --bg:       #f0f4f8;
  --surface:  #ffffff;
  --card:     #ffffff;
  --border:   #e2e8f0;
  --border2:  #cbd5e1;
  --accent:   #0d9488;
  --accent2:  #0284c7;
  --warn:     #d97706;
  --danger:   #dc2626;
  --success:  #16a34a;
  --purple:   #7c3aed;
  --text:     #0f172a;
  --muted:    #64748b;
  --muted2:   #475569;
}

/* ── BASE ────────────────────────────────────────────────────── */
* { box-sizing: border-box; }
body { font-family: var(--body); background: var(--bg); color: var(--text); min-height: 100vh; margin: 0; }
a { color: var(--accent); text-decoration: none; }
::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 4px; }

/* ── SIDEBAR ─────────────────────────────────────────────────── */
.wh-sidebar {
  position: fixed; top: 0; left: 0; bottom: 0; width: var(--sidebar);
  background: var(--surface); border-right: 1px solid var(--border);
  display: flex; flex-direction: column; z-index: 1040;
  overflow-y: auto; transition: transform .25s ease;
}
.wh-brand {
  font-family: var(--head); font-weight: 700; font-size: .95rem;
  background: linear-gradient(135deg, var(--accent), var(--accent2));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  letter-spacing: .5px;
}
.wh-badge-wms {
  font-size: .55rem; font-weight: 700; letter-spacing: 1.5px;
  background: rgba(0,212,170,.15); color: var(--accent);
  border: 1px solid rgba(0,212,170,.25); border-radius: 4px; padding: 1px 6px;
  font-family: var(--mono);
}
.wh-nav-link {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 12px; border-radius: 7px; margin: 1px 0;
  color: var(--muted); font-size: .82rem; font-weight: 500;
  cursor: pointer; border: none; background: none;
  width: 100%; text-align: left; transition: all .14s ease;
  font-family: var(--body);
}
.wh-nav-link:hover  { background: var(--card); color: var(--text); }
.wh-nav-link.active { background: rgba(0,212,170,.1); color: var(--accent); }
.wh-nav-link i      { width: 15px; text-align: center; font-size: .82rem; flex-shrink: 0; }
.nav-sec { font-size: .6rem; font-weight: 700; letter-spacing: 1.4px;
  text-transform: uppercase; color: var(--muted); padding: 10px 12px 3px; }

/* ── TOPBAR ──────────────────────────────────────────────────── */
.wh-topbar {
  position: fixed; top: 0; left: var(--sidebar); right: 0;
  height: var(--topbar); background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 20px; z-index: 1030; transition: left .25s ease;
}
.wh-page-title { font-family: var(--head); font-size: 1rem; font-weight: 700; }

/* ── MAIN ────────────────────────────────────────────────────── */
#wh-main { margin-left: var(--sidebar); padding-top: var(--topbar); min-height: 100vh; }
#wh-content { padding: 22px; }

/* ── CARDS ───────────────────────────────────────────────────── */
.wh-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 10px;
}
.wh-card-header {
  background: var(--surface); border-bottom: 1px solid var(--border);
  border-radius: 10px 10px 0 0; padding: 10px 16px;
  font-size: .8rem; font-weight: 600; font-family: var(--head);
  display: flex; align-items: center; justify-content: space-between;
}

/* ── KPI CARDS ───────────────────────────────────────────────── */
.kpi-card {
  background: var(--card); border: 1px solid var(--border);
  border-radius: 10px; padding: 18px 16px;
  position: relative; overflow: hidden;
  transition: border-color .2s, transform .2s;
}
.kpi-card:hover { transform: translateY(-2px); border-color: var(--kpi-color, var(--accent)); }
.kpi-card::after {
  content: ''; position: absolute; bottom: 0; left: 0; right: 0;
  height: 2px; background: var(--kpi-color, var(--accent));
}
.kpi-label { font-size: .65rem; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; font-weight: 600; }
.kpi-value { font-family: var(--head); font-size: 1.7rem; font-weight: 700; line-height: 1.1; margin: 6px 0 2px; }
.kpi-sub   { font-size: .7rem; color: var(--muted2); }
.kpi-icon  { position: absolute; top: 14px; right: 14px; font-size: 1.3rem; opacity: .1; }

/* ── STATUS BADGES ───────────────────────────────────────────── */
.st-badge { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: .68rem; font-weight: 600; font-family: var(--mono); }
.st-draft      { background: rgba(100,116,139,.15); color: var(--muted2); }
.st-receiving  { background: rgba(14,165,233,.15);  color: var(--accent2); }
.st-qc_pending { background: rgba(245,158,11,.15);  color: var(--warn); }
.st-qc_passed, .st-available, .st-accepted, .st-completed, .st-delivered, .st-dispatched { background: rgba(34,197,94,.15); color: var(--success); }
.st-qc_failed, .st-rejected, .st-cancelled  { background: rgba(239,68,68,.15); color: var(--danger); }
.st-partially_accepted, .st-partial, .st-partially_completed { background: rgba(167,139,250,.15); color: var(--purple); }
.st-quarantine, .st-qc_pending { background: rgba(245,158,11,.15); color: var(--warn); }
.st-in_progress, .st-picking, .st-packing, .st-ready { background: rgba(0,212,170,.12); color: var(--accent); }
.st-confirmed, .st-scheduled  { background: rgba(14,165,233,.12); color: var(--accent2); }
.st-urgent  { background: rgba(239,68,68,.2);   color: var(--danger); }
.st-high    { background: rgba(245,158,11,.15); color: var(--warn); }
.st-normal  { background: rgba(100,116,139,.12); color: var(--muted2); }
.st-low     { background: rgba(34,197,94,.1);   color: var(--success); }

/* ── TABLES ──────────────────────────────────────────────────── */
.wh-table { color: var(--text); width: 100%; }
.wh-table > :not(caption) > * > * { background: transparent; border-bottom: 1px solid var(--border); padding: 9px 13px; }
.wh-table thead th { background: var(--surface); color: var(--muted); font-size: .68rem; text-transform: uppercase; letter-spacing: .7px; font-weight: 600; border-bottom: 1px solid var(--border); white-space: nowrap; }
.wh-table tbody tr { transition: background .1s; }
.wh-table tbody tr:hover > td { background: rgba(255,255,255,.02); }

/* ── FORMS ───────────────────────────────────────────────────── */
.form-control, .form-select {
  background: var(--surface); border: 1px solid var(--border);
  color: var(--text); font-family: var(--body);
}
.form-control:focus, .form-select:focus {
  background: var(--surface); border-color: var(--accent);
  color: var(--text); box-shadow: 0 0 0 3px rgba(0,212,170,.12);
}
.form-control::placeholder { color: var(--muted); }
.form-select option         { background: var(--surface); }
.form-label { color: var(--muted); font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
[data-theme="light"] .form-control, [data-theme="light"] .form-select { background: #f8fafc; color: #0f172a; border-color: var(--border); }
[data-theme="light"] .form-control:focus, [data-theme="light"] .form-select:focus { background: #fff; color: #0f172a; }

/* ── MODALS ──────────────────────────────────────────────────── */
.modal-content { background: var(--card); border: 1px solid var(--border); border-radius: 12px; color: var(--text); }
.modal-header  { background: var(--surface); border-bottom: 1px solid var(--border); }
.modal-footer  { background: var(--surface); border-top: 1px solid var(--border); }
.btn-close     { filter: invert(1); }
[data-theme="light"] .btn-close { filter: none; }
[data-theme="light"] .modal-content { background: #fff; color: #0f172a; }

/* ── BUTTONS ─────────────────────────────────────────────────── */
.btn-teal   { background: var(--accent);  color: #000; font-weight: 600; }
.btn-teal:hover { background: #00bfa5; color: #000; }
.btn-sky    { background: var(--accent2); color: #fff; font-weight: 600; }
.btn-sky:hover  { background: #0284c7; color: #fff; }

/* ── MOVEMENT TYPES ──────────────────────────────────────────── */
.mv { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: .66rem; font-weight: 600; font-family: var(--mono); white-space: nowrap; }
.mv-grn_receive    { background: rgba(34,197,94,.15);  color: var(--success); }
.mv-pick           { background: rgba(14,165,233,.15);  color: var(--accent2); }
.mv-dispatch       { background: rgba(100,116,139,.15); color: var(--muted2); }
.mv-production_issue  { background: rgba(245,158,11,.15); color: var(--warn); }
.mv-production_output { background: rgba(0,212,170,.15);  color: var(--accent); }
.mv-adjustment_add    { background: rgba(34,197,94,.15);  color: var(--success); }
.mv-adjustment_remove { background: rgba(239,68,68,.15);  color: var(--danger); }
.mv-transfer_out   { background: rgba(239,68,68,.12);   color: var(--danger); }
.mv-transfer_in    { background: rgba(34,197,94,.12);   color: var(--success); }
.mv-qc_quarantine  { background: rgba(245,158,11,.15);  color: var(--warn); }
.mv-qc_release     { background: rgba(34,197,94,.12);   color: var(--success); }
.mv-qc_reject      { background: rgba(239,68,68,.12);   color: var(--danger); }

/* ── TOAST ───────────────────────────────────────────────────── */
#wh-toast-wrap { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 7px; pointer-events: none; }
.wh-toast { background: var(--card); border: 1px solid var(--border); border-radius: 9px; padding: 10px 15px; font-size: .82rem; box-shadow: 0 4px 20px rgba(0,0,0,.5); display: flex; align-items: center; gap: 9px; min-width: 200px; animation: whToastIn .2s ease; pointer-events: all; }
.wh-toast.success { border-left: 3px solid var(--success); }
.wh-toast.error   { border-left: 3px solid var(--danger); }
.wh-toast.info    { border-left: 3px solid var(--accent2); }
.wh-toast.warn    { border-left: 3px solid var(--warn); }
@keyframes whToastIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }

/* ── LOGIN ───────────────────────────────────────────────────── */
#wh-login { min-height: 100vh; position: relative; overflow: hidden; }
.wh-login-split { display: flex; min-height: 100vh; width: 100%; }
.wh-login-visual {
  flex: 1.1; position: relative; display: flex; align-items: center; justify-content: center;
  background: url('https://images.unsplash.com/photo-1553413077-190dd305871c?q=80&w=1400&auto=format&fit=crop') center/cover no-repeat;
  padding: 60px;
}
.wh-login-visual-overlay {
  position: absolute; inset: 0;
  background: linear-gradient(160deg, rgba(10,13,20,.92) 0%, rgba(10,13,20,.75) 45%, rgba(0,212,170,.25) 100%);
}
.wh-login-visual-content { position: relative; z-index: 1; max-width: 440px; color: #fff; }
.wh-login-tagline { font-size: .92rem; color: rgba(226,232,240,.8); line-height: 1.7; margin: 16px 0 32px; }
.wh-login-features { display: flex; flex-direction: column; gap: 18px; }
.wh-login-feature { display: flex; align-items: flex-start; gap: 14px; }
.wh-login-feature i { width: 38px; height: 38px; flex-shrink: 0; border-radius: 10px; background: rgba(0,212,170,.15); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1rem; }
.wh-login-feature strong { display: block; font-size: .88rem; color: #fff; }
.wh-login-feature span { display: block; font-size: .75rem; color: rgba(226,232,240,.65); margin-top: 1px; }
.wh-login-trust { margin-top: 36px; font-size: .72rem; color: rgba(226,232,240,.6); padding-top: 18px; border-top: 1px solid rgba(255,255,255,.1); }
.wh-login-trust i { color: var(--accent); margin-right: 6px; }
.wh-login-form-panel { flex: 0.9; min-width: 420px; display: flex; align-items: center; justify-content: center; background: var(--bg); padding: 40px; }
.wh-login-box { background: transparent; width: 100%; max-width: 360px; }
.wh-logo-ring { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, var(--accent), var(--accent2)); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin: 0 auto 16px; }
@media (max-width: 991.98px) {
  .wh-login-visual { display: none; }
  .wh-login-form-panel { min-width: 0; flex: 1; }
  #wh-login { background: radial-gradient(ellipse at 30% 40%, rgba(0,212,170,.08), transparent 60%), radial-gradient(ellipse at 70% 80%, rgba(14,165,233,.06), transparent 60%), var(--bg); }
  .wh-login-box { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 36px 28px; box-shadow: 0 20px 60px rgba(0,0,0,.6); }
}

/* ── ZONE UTILIZATION BAR ────────────────────────────────────── */
.zone-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.zone-bar-fill { height: 100%; border-radius: 3px; background: var(--accent); transition: width .4s ease; }

/* ── EXPIRY INDICATOR ────────────────────────────────────────── */
.expiry-ok      { color: var(--success); }
.expiry-soon    { color: var(--warn); }
.expiry-expired { color: var(--danger); font-weight: 700; }

/* ── MONO LABELS ─────────────────────────────────────────────── */
.mono { font-family: var(--mono); font-size: .8rem; }

/* ── PRIORITY DOT ────────────────────────────────────────────── */
.prio-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 5px; }
.prio-urgent { background: var(--danger); }
.prio-high   { background: var(--warn); }
.prio-normal { background: var(--accent2); }
.prio-low    { background: var(--muted); }

/* ── RESPONSIVE ──────────────────────────────────────────────── */
@media (max-width: 991.98px) {
  .wh-sidebar { transform: translateX(-100%); }
  .wh-sidebar.show { transform: translateX(0); box-shadow: 8px 0 30px rgba(0,0,0,.7); }
  .wh-topbar { left: 0 !important; }
  #wh-main   { margin-left: 0 !important; }
}
@media (max-width: 575.98px) {
  .wh-login-box { padding: 28px 20px; }
  #wh-content   { padding: 12px; }
  .kpi-value    { font-size: 1.3rem; }
}
#wh-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 1039; backdrop-filter: blur(2px); }
#wh-backdrop.show { display: block; }

/* ── LOCK SCREEN ─────────────────────────────────────────────── */
#wh-lock { display: none; position: fixed; inset: 0; z-index: 99998; background: rgba(10,13,20,.97); backdrop-filter: blur(12px); align-items: center; justify-content: center; }
#wh-lock.show { display: flex; }
.wh-lock-card { background: var(--card); border: 1px solid var(--border); border-radius: 18px; padding: 40px 36px; max-width: 360px; width: 90%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.7); }
</style>
</head>
<body>

<!-- ══════ LOGIN ══════ -->
<div id="wh-login" <?= $logged_in ? 'class="d-none"' : '' ?>>
  <div class="wh-login-split">
    <div class="wh-login-visual">
      <div class="wh-login-visual-overlay"></div>
      <div class="wh-login-visual-content">
        <div class="wh-brand" style="font-size:1.4rem">NYMIX WMS</div>
        <p class="wh-login-tagline">Run receiving, quality control, picking, and production from one secure warehouse dashboard built for Kenyan distributors.</p>
        <div class="wh-login-features">
          <div class="wh-login-feature"><i class="fa fa-truck-ramp-box"></i><div><strong>GRN & QC</strong><span>Receive stock and inspect batches before they hit the shelf</span></div></div>
          <div class="wh-login-feature"><i class="fa fa-clock-rotate-left"></i><div><strong>FEFO Picking</strong><span>Oldest stock picked first, automatically</span></div></div>
          <div class="wh-login-feature"><i class="fa fa-industry"></i><div><strong>Production Orders</strong><span>Track raw materials in, finished goods out</span></div></div>
        </div>
        <div class="wh-login-trust"><i class="fa fa-shield-halved"></i> Secured warehouse access · NYMIX TECH</div>
      </div>
    </div>
    <div class="wh-login-form-panel">
      <div class="wh-login-box">
        <div class="wh-logo-ring d-lg-none">🏭</div>
        <div class="text-center mb-4">
          <div class="wh-brand" style="font-size:1.3rem">NYMIX WMS</div>
          <div style="font-size:.72rem;color:var(--muted);margin-top:3px">Warehouse Management System</div>
        </div>
        <div id="wh-login-err" class="alert alert-danger d-none py-2 small mb-3"></div>
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" id="wl-user" class="form-control" placeholder="Enter username" autocomplete="username">
        </div>
        <div class="mb-4">
          <label class="form-label">Password</label>
          <div class="input-group">
            <input type="password" id="wl-pass" class="form-control" placeholder="••••••••" autocomplete="current-password">
            <button class="btn btn-outline-secondary" type="button" onclick="const i=document.getElementById('wl-pass');i.type=i.type==='password'?'text':'password'">
              <i class="fa fa-eye"></i>
            </button>
          </div>
        </div>
        <button class="btn btn-teal w-100 fw-bold" onclick="whLogin()">
          <i class="fa fa-warehouse me-2"></i>Access Warehouse
        </button>
        <p class="text-center mt-3 mb-0">
          <a href="javascript:void(0)" onclick="openForgotModal()" style="font-size:.75rem;color:var(--accent2)">
            <i class="fa fa-key me-1"></i>Forgot password?
          </a>
        </p>
        <p class="text-center mt-3 mb-0" style="font-size:.68rem;color:var(--muted)">
          <i class="fa fa-shield-halved me-1"></i>NYMIX TECH · Secured WMS
        </p>
      </div>
    </div>
  </div>
</div>

<!-- ══════ APP ══════ -->
<div id="wh-app" <?= $logged_in ? '' : 'class="d-none"' ?>>

  <div id="wh-backdrop" onclick="whCloseSidebar()"></div>

  <!-- SIDEBAR -->
  <aside class="wh-sidebar" id="wh-sidebar">
    <div class="p-3" style="border-bottom:1px solid var(--border)">
      <div class="d-flex align-items-center gap-2 mb-2">
        <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0">🏭</div>
        <div>
          <div class="wh-brand">NYMIX WMS</div>
          <div class="wh-badge-wms">WAREHOUSE</div>
        </div>
      </div>
      <!-- Warehouse switcher -->
      <select id="wh-switcher" class="form-select form-select-sm mt-2" onchange="switchWarehouse()" style="font-size:.75rem">
        <option value="">— No Warehouse —</option>
        <?php foreach ($wh_list as $wh): ?>
        <option value="<?= $wh['id'] ?>" <?= $wh['id'] == $wh_id_curr ? 'selected' : '' ?>>
          <?= wh_esc($wh['name']) ?> (<?= wh_esc($wh['code']) ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- User chip -->
    <div class="p-2" style="border-bottom:1px solid var(--border)">
      <div style="display:flex;align-items:center;gap:9px;background:var(--bg);border-radius:8px;padding:9px 10px;border:1px solid var(--border)">
        <div style="width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:700;color:#000;font-size:.78rem;flex-shrink:0">
          <?= $logged_in ? strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) : 'U' ?>
        </div>
        <div style="min-width:0">
          <div class="fw-bold text-truncate" style="font-size:.8rem" id="wh-sb-name"><?= wh_esc($user['full_name'] ?? '') ?></div>
          <div style="font-size:.65rem;color:var(--muted);text-transform:capitalize"><?= wh_esc($user['role_name'] ?? '') ?></div>
        </div>
      </div>
    </div>

    <nav class="p-2 flex-grow-1">
      <div class="nav-sec">Main</div>
      <button class="wh-nav-link active" data-page="dashboard"><i class="fa fa-chart-line"></i>Dashboard</button>

      <div class="nav-sec">Receiving</div>
      <button class="wh-nav-link" data-page="delivery_schedule"><i class="fa fa-calendar-check"></i>Delivery Schedule</button>
      <button class="wh-nav-link" data-page="grn"><i class="fa fa-truck-ramp-box"></i>GRN — Goods Received</button>
      <button class="wh-nav-link" data-page="batches"><i class="fa fa-boxes-stacked"></i>Batches & Lots</button>

      <div class="nav-sec">Outbound</div>
      <button class="wh-nav-link" data-page="outbound"><i class="fa fa-dolly"></i>Outbound Orders</button>

      <div class="nav-sec">Manufacturing</div>
      <button class="wh-nav-link" data-page="bom"><i class="fa fa-diagram-project"></i>Bill of Materials</button>
      <button class="wh-nav-link" data-page="production"><i class="fa fa-industry"></i>Production Orders</button>

      <div class="nav-sec">Warehouse</div>
      <button class="wh-nav-link" data-page="locations"><i class="fa fa-map-pin"></i>Locations & Bins</button>
      <button class="wh-nav-link" data-page="stock_count"><i class="fa fa-clipboard-check"></i>Stock Count</button>
      <button class="wh-nav-link" data-page="wh_transfers"><i class="fa fa-shuffle"></i>WH Transfers</button>
      <button class="wh-nav-link" data-page="ledger"><i class="fa fa-book-open"></i>Stock Ledger</button>

      <div class="nav-sec">Settings</div>
      <button class="wh-nav-link" data-page="warehouses"><i class="fa fa-building"></i>Warehouses & Zones</button>
    </nav>

    <div class="p-2" style="border-top:1px solid var(--border)">
      <div class="d-flex gap-1">
        <button class="btn btn-sm flex-grow-1" style="background:var(--surface);border:1px solid var(--border);color:var(--muted);font-size:.75rem" onclick="whToggleTheme()">
          <i class="fa fa-moon" id="wh-theme-icon"></i>
        </button>
        <a href="staff_portal.php" class="btn btn-sm flex-grow-1" style="background:var(--surface);border:1px solid var(--border);color:var(--muted);font-size:.75rem" title="Switch to Staff Portal">
          <i class="fa fa-cash-register"></i>
        </a>
        <button class="btn btn-sm flex-grow-1 text-danger" style="background:var(--surface);border:1px solid var(--border);font-size:.75rem" onclick="whLogout()">
          <i class="fa fa-right-from-bracket"></i>
        </button>
      </div>
    </div>
  </aside>

  <!-- TOPBAR -->
  <header class="wh-topbar" id="wh-topbar">
    <div class="d-flex align-items-center gap-3">
      <button class="btn btn-sm d-lg-none" style="background:var(--surface);border:1px solid var(--border);color:var(--text)" onclick="whToggleSidebar()">
        <i class="fa fa-bars"></i>
      </button>
      <div>
        <div class="wh-page-title" id="wh-page-title">Dashboard</div>
        <div style="font-size:.62rem;color:var(--muted);line-height:1" id="wh-wh-label"></div>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="d-none d-md-block" style="font-size:.75rem;color:var(--muted);font-family:var(--mono)" id="wh-clock"></span>
      <a href="staff_portal.php" class="btn btn-sm" style="background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.25);color:var(--accent);font-size:.75rem">
        <i class="fa fa-cash-register me-1"></i><span class="d-none d-sm-inline">POS Portal</span>
      </a>
    </div>
  </header>

  <div id="wh-main">
    <div id="wh-content">
      <div class="d-flex justify-content-center align-items-center py-5">
        <div class="spinner-border" style="color:var(--accent)"></div>
      </div>
    </div>
  </div>
</div>

<!-- ══════ LOCK SCREEN ══════ -->
<div id="wh-lock">
  <div class="wh-lock-card">
    <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;color:#000;margin:0 auto 14px">
      <?= $logged_in ? strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) : 'U' ?>
    </div>
    <div style="font-family:var(--head);font-size:1rem;font-weight:700"><?= wh_esc($user['full_name'] ?? '') ?></div>
    <div style="color:var(--muted);font-size:.75rem;margin-bottom:20px"><i class="fa fa-lock me-1" style="color:var(--accent)"></i>Screen Locked</div>
    <input type="password" id="wh-lock-pin" class="form-control text-center mb-2" placeholder="Enter password" style="letter-spacing:2px">
    <div id="wh-lock-err" class="alert alert-danger py-1 small d-none mb-2"></div>
    <button class="btn btn-teal w-100 mb-3" onclick="whUnlock()"><i class="fa fa-unlock me-2"></i>Unlock</button>
    <button class="btn btn-outline-danger btn-sm w-100" onclick="whLogout()"><i class="fa fa-right-from-bracket me-2"></i>Different user</button>
  </div>
</div>

<!-- ══════ MODALS ══════ -->

<!-- GRN Modal -->
<div class="modal fade" id="modal-grn" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)">New Goods Received Note</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4"><label class="form-label">Supplier</label><select id="grn-supplier" class="form-select"></select></div>
          <div class="col-md-3"><label class="form-label">Delivery Date *</label><input type="date" id="grn-date" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">Vehicle Reg</label><input type="text" id="grn-vehicle" class="form-control" placeholder="KAB 123C"></div>
          <div class="col-md-3"><label class="form-label">Driver Name</label><input type="text" id="grn-driver" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Supplier Invoice No.</label><input type="text" id="grn-invoice" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Supplier DO No.</label><input type="text" id="grn-do" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Notes</label><input type="text" id="grn-notes" class="form-control"></div>
        </div>
        <div class="wh-card p-3">
          <div class="d-flex gap-2 mb-3 flex-wrap">
            <select id="grn-prod-sel" class="form-select" style="min-width:200px;flex:1"></select>
            <input type="number" id="grn-qty" class="form-control" placeholder="Qty received" min="0.001" step="0.001" style="width:110px">
            <input type="number" id="grn-cost" class="form-control" placeholder="Unit cost" min="0" step="0.01" style="width:110px">
            <input type="text" id="grn-batch" class="form-control" placeholder="Batch no." style="width:120px">
            <input type="date" id="grn-expiry" class="form-control" style="width:140px" title="Expiry date">
            <select id="grn-loc" class="form-select" style="width:140px" title="Putaway location"></select>
            <button class="btn btn-teal btn-sm" onclick="grnAddItem()"><i class="fa fa-plus"></i></button>
          </div>
          <div id="grn-items-list"></div>
          <div class="text-end mt-2 small" style="color:var(--muted)">
            Total items: <span id="grn-item-count" class="fw-bold" style="color:var(--accent)">0</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="submitGRN()"><i class="fa fa-truck-ramp-box me-2"></i>Create GRN</button>
      </div>
    </div>
  </div>
</div>

<!-- QC Modal -->
<div class="modal fade" id="modal-qc" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)">QC Inspection</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="qc-body"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="submitQC()"><i class="fa fa-clipboard-check me-2"></i>Submit QC</button>
      </div>
    </div>
  </div>
</div>

<!-- Outbound Order Modal -->
<div class="modal fade" id="modal-outbound" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)">New Outbound Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4"><label class="form-label">Order Type</label>
            <select id="ob-type" class="form-select" onchange="toggleOBDest()">
              <option value="branch_replenishment">Branch Replenishment</option>
              <option value="customer_order">Customer Order</option>
              <option value="production_supply">Production Supply</option>
              <option value="inter_warehouse">Inter-Warehouse</option>
              <option value="return_to_supplier">Return to Supplier</option>
            </select>
          </div>
          <div class="col-md-4" id="ob-dest-branch-col"><label class="form-label">Destination Branch</label><select id="ob-dest-branch" class="form-select"></select></div>
          <div class="col-md-4"><label class="form-label">Priority</label>
            <select id="ob-priority" class="form-select">
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
              <option value="low">Low</option>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label">Required Date</label><input type="date" id="ob-req-date" class="form-control"></div>
        </div>
        <div class="wh-card p-3">
          <div class="d-flex gap-2 mb-3">
            <select id="ob-prod-sel" class="form-select flex-grow-1"></select>
            <input type="number" id="ob-qty" class="form-control" placeholder="Qty" min="0.001" step="0.001" style="width:100px">
            <button class="btn btn-teal btn-sm" onclick="obAddItem()"><i class="fa fa-plus"></i></button>
          </div>
          <div id="ob-items-list"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="submitOutbound()"><i class="fa fa-dolly me-2"></i>Create Order</button>
      </div>
    </div>
  </div>
</div>

<!-- Pick Modal -->
<div class="modal fade" id="modal-pick" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)">Pick List</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="pick-body"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="submitPick()"><i class="fa fa-check me-2"></i>Confirm Picks</button>
      </div>
    </div>
  </div>
</div>

<!-- BOM Modal -->
<div class="modal fade" id="modal-bom" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)" id="bom-modal-title">New BOM</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="bom-id">
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Finished Product *</label><select id="bom-fp" class="form-select"></select></div>
          <div class="col-md-3"><label class="form-label">BOM Name *</label><input type="text" id="bom-name" class="form-control"></div>
          <div class="col-md-1"><label class="form-label">Version</label><input type="text" id="bom-version" class="form-control" value="v1"></div>
          <div class="col-md-2"><label class="form-label">Qty Produced</label><input type="number" id="bom-qty" class="form-control" value="1" min="0.001"></div>
          <div class="col-12"><label class="form-label">Notes</label><input type="text" id="bom-notes" class="form-control"></div>
        </div>
        <div class="wh-card p-3">
          <div class="small fw-bold mb-2" style="color:var(--muted)">INGREDIENTS / INPUTS</div>
          <div class="d-flex gap-2 mb-3 flex-wrap">
            <select id="bom-ing-prod" class="form-select flex-grow-1"></select>
            <input type="number" id="bom-ing-qty" class="form-control" placeholder="Qty required" min="0.0001" step="0.0001" style="width:120px">
            <input type="text"   id="bom-ing-unit" class="form-control" placeholder="Unit" style="width:80px">
            <input type="number" id="bom-ing-waste" class="form-control" placeholder="Waste %" min="0" max="100" step="0.1" style="width:90px">
            <button class="btn btn-teal btn-sm" onclick="bomAddIngredient()"><i class="fa fa-plus"></i></button>
          </div>
          <div id="bom-ingredients-list"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="saveBOM()"><i class="fa fa-save me-2"></i>Save BOM</button>
      </div>
    </div>
  </div>
</div>

<!-- Production Create Modal -->
<div class="modal fade" id="modal-production" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)">New Production Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Bill of Materials *</label><select id="prod-bom" class="form-select" onchange="loadBOMPreview()"></select></div>
          <div class="col-md-3"><label class="form-label">Qty to Produce *</label><input type="number" id="prod-qty" class="form-control" min="0.001" step="0.001" oninput="loadBOMPreview()"></div>
          <div class="col-md-3"><label class="form-label">Output Location</label><select id="prod-out-loc" class="form-select"></select></div>
          <div class="col-md-3"><label class="form-label">Start Date</label><input type="date" id="prod-start" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">End Date</label><input type="date" id="prod-end" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Assign To</label><select id="prod-assign" class="form-select"></select></div>
          <div class="col-12" id="bom-preview"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="submitProduction()"><i class="fa fa-industry me-2"></i>Create Order</button>
      </div>
    </div>
  </div>
</div>

<!-- Complete Production Modal -->
<div class="modal fade" id="modal-prod-complete" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)">Complete Production Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="prod-complete-body"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="submitProductionComplete()"><i class="fa fa-check me-2"></i>Mark Complete</button>
      </div>
    </div>
  </div>
</div>

<!-- Location Modal -->
<div class="modal fade" id="modal-location" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)" id="loc-modal-title">New Location</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="loc-id">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Zone *</label><select id="loc-zone" class="form-select"></select></div>
          <div class="col-md-6"><label class="form-label">Type</label>
            <select id="loc-type" class="form-select">
              <option value="bin">Bin</option><option value="shelf">Shelf</option>
              <option value="rack">Rack</option><option value="floor">Floor</option><option value="bulk">Bulk Area</option>
            </select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label">Aisle</label><input type="text" id="loc-aisle" class="form-control" placeholder="A"></div>
          <div class="col-6 col-md-3"><label class="form-label">Rack</label><input type="text" id="loc-rack" class="form-control" placeholder="01"></div>
          <div class="col-6 col-md-3"><label class="form-label">Shelf</label><input type="text" id="loc-shelf" class="form-control" placeholder="02"></div>
          <div class="col-6 col-md-3"><label class="form-label">Bin</label><input type="text" id="loc-bin" class="form-control" placeholder="03"></div>
          <div class="col-md-6"><label class="form-label">Label (Address) *</label><input type="text" id="loc-label" class="form-control" placeholder="e.g. RM-A-01-03"></div>
          <div class="col-md-6"><label class="form-label">Barcode</label><input type="text" id="loc-barcode" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Capacity (units)</label><input type="number" id="loc-capacity" class="form-control" min="0"></div>
          <div class="col-md-6"><label class="form-label">Status</label><select id="loc-active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="saveLocation()">Save Location</button>
      </div>
    </div>
  </div>
</div>

<!-- Delivery Schedule Modal -->
<div class="modal fade" id="modal-delivery" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)" id="del-modal-title">Schedule Delivery</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="del-id">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Supplier</label><select id="del-supplier" class="form-select"></select></div>
          <div class="col-md-3"><label class="form-label">Date *</label><input type="date" id="del-date" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">Time</label><input type="time" id="del-time" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Vehicle Reg</label><input type="text" id="del-vehicle" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Driver Name</label><input type="text" id="del-driver" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Driver Phone</label><input type="text" id="del-driver-phone" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Dock Door</label><input type="text" id="del-dock" class="form-control" placeholder="e.g. Door 1"></div>
          <div class="col-md-4"><label class="form-label">Status</label>
            <select id="del-status" class="form-select">
              <option value="scheduled">Scheduled</option>
              <option value="confirmed">Confirmed</option>
              <option value="arrived">Arrived</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label">Notes</label><input type="text" id="del-notes" class="form-control"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="saveDelivery()">Save Schedule</button>
      </div>
    </div>
  </div>
</div>

<!-- WH Transfer Modal -->
<div class="modal fade" id="modal-wh-transfer" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" style="font-family:var(--head)">Inter-Warehouse Transfer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-5"><label class="form-label">From Warehouse</label><select id="wht-from" class="form-select"></select></div>
          <div class="col-md-5"><label class="form-label">To Warehouse</label><select id="wht-to" class="form-select"></select></div>
          <div class="col-md-2"><label class="form-label">Notes</label><input type="text" id="wht-notes" class="form-control"></div>
        </div>
        <div class="wh-card p-3">
          <div class="d-flex gap-2 mb-3">
            <select id="wht-prod" class="form-select flex-grow-1"></select>
            <input type="number" id="wht-qty" class="form-control" placeholder="Qty" min="0.001" step="0.001" style="width:100px">
            <button class="btn btn-teal btn-sm" onclick="whtAddItem()"><i class="fa fa-plus"></i></button>
          </div>
          <div id="wht-items-list"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-teal fw-bold" onclick="submitWHTransfer()">Create Transfer</button>
      </div>
    </div>
  </div>
</div>

<!-- Forgot Password Modal -->
<div class="modal fade" id="modal-forgot" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" style="font-family:var(--head)">Reset Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="fg-err" class="alert alert-danger d-none py-2 small mb-3"></div>
        <div id="fg-msg" class="alert alert-success d-none py-2 small mb-3"></div>

        <!-- Step 1: username -->
        <div id="fg-step-1">
          <label class="form-label">Username</label>
          <input type="text" id="fg-username" class="form-control mb-3" placeholder="Enter your username">
          <button class="btn btn-teal w-100 fw-bold" onclick="forgotCheckUsername()">Continue</button>
        </div>

        <!-- Step 2: email + send OTP -->
        <div id="fg-step-2" class="d-none">
          <p class="small" style="color:var(--muted)" id="fg-hint"></p>
          <label class="form-label">Email Address</label>
          <input type="email" id="fg-email" class="form-control mb-3" placeholder="you@example.com">
          <button class="btn btn-teal w-100 fw-bold" onclick="forgotSendOtp()">Send Reset Code</button>
        </div>

        <!-- Step 3: OTP + new password -->
        <div id="fg-step-3" class="d-none">
          <label class="form-label">6-Digit Code</label>
          <input type="text" id="fg-otp" class="form-control mb-3" maxlength="6" placeholder="123456">
          <label class="form-label">New Password</label>
          <input type="password" id="fg-pass1" class="form-control mb-3" placeholder="At least 8 characters">
          <label class="form-label">Confirm Password</label>
          <input type="password" id="fg-pass2" class="form-control mb-3" placeholder="Repeat password">
          <button class="btn btn-teal w-100 fw-bold" onclick="forgotResetPassword()">Reset Password</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="wh-toast-wrap"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ════════════════════════════════════════════════════════════
//  NYMIX WMS — CLIENT JS
// ════════════════════════════════════════════════════════════
const WH_SELF  = window.location.pathname;
const WH_CSRF  = <?= json_encode($csrf_token) ?>;
const WH_CU    = <?= $logged_in ? json_encode([
  'id'           => (int)$user['id'],
  'name'         => $user['full_name'] ?? '',
  'username'     => $user['username']  ?? '',
  'role_id'      => (int)($user['role_id'] ?? 0),
  'role_name'    => $user['role_name']   ?? '',
  'business_name'=> $user['business_name'] ?? '',
]) : 'null' ?>;

const wh_can = (...roles) => WH_CU && roles.includes(WH_CU.role_id);
const wh_fmt = n => 'KSh ' + parseFloat(n||0).toLocaleString('en-KE', {minimumFractionDigits:2, maximumFractionDigits:2});
const wh_fmtN = n => parseFloat(n||0).toLocaleString('en-KE', {minimumFractionDigits:0, maximumFractionDigits:2});
const wh_today = () => new Date().toISOString().slice(0,10);
const wh_fmtDate = d => d ? new Date(d).toLocaleDateString('en-KE') : '—';
const wh_esc = s => { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; };

// ── THEME ─────────────────────────────────────────────────────
const _whTheme = localStorage.getItem('wh-theme') || 'dark';
document.documentElement.setAttribute('data-theme', _whTheme);
function whToggleTheme() {
  const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('wh-theme', next);
  document.getElementById('wh-theme-icon').className = next === 'dark' ? 'fa fa-sun' : 'fa fa-moon';
}
document.addEventListener('DOMContentLoaded', () => {
  const t = localStorage.getItem('wh-theme') || 'dark';
  const icon = document.getElementById('wh-theme-icon');
  if (icon) icon.className = t === 'dark' ? 'fa fa-sun' : 'fa fa-moon';
});

// ── API ────────────────────────────────────────────────────────
async function whApi(action, params = {}, method = 'GET') {
  const url = WH_SELF + '?action=' + encodeURIComponent(action);
  const opts = { method };
  if (method === 'POST') {
    const fd = new FormData();
    fd.append('csrf', WH_CSRF);
    for (const k in params) fd.append(k, typeof params[k] === 'object' ? JSON.stringify(params[k]) : params[k]);
    opts.body = fd;
  }
  try {
    const res = await fetch(method === 'GET' ? url + '&' + new URLSearchParams(params) : url, opts);
    const text = await res.text();
    try { return JSON.parse(text); }
    catch(e) { console.error('Non-JSON [' + action + ']:', text.slice(0, 600)); return { ok: false, msg: 'Server error' }; }
  } catch(e) { return { ok: false, msg: 'Request failed: ' + e.message }; }
}

// ── TOAST ─────────────────────────────────────────────────────
function whToast(msg, type = 'info') {
  const icons = { success:'✓', error:'✕', info:'ℹ', warn:'⚠' };
  const el = document.createElement('div');
  el.className = `wh-toast ${type}`;
  el.innerHTML = `<span>${icons[type]||'•'}</span><span>${msg}</span>`;
  document.getElementById('wh-toast-wrap').appendChild(el);
  setTimeout(() => el.remove(), 3800);
}

// ── MODAL HELPERS ─────────────────────────────────────────────
function whOpenModal(id)  { bootstrap.Modal.getOrCreateInstance(document.getElementById(id)).show(); }
function whCloseModal(id) { bootstrap.Modal.getInstance(document.getElementById(id))?.hide(); }
function whLoading(el)    { el.innerHTML = `<div class="d-flex justify-content-center py-5"><div class="spinner-border" style="color:var(--accent)"></div></div>`; }

// ── SIDEBAR ───────────────────────────────────────────────────
function whToggleSidebar() {
  document.getElementById('wh-sidebar').classList.toggle('show');
  document.getElementById('wh-backdrop').classList.toggle('show');
}
function whCloseSidebar() {
  document.getElementById('wh-sidebar').classList.remove('show');
  document.getElementById('wh-backdrop').classList.remove('show');
}

// ── AUTH ──────────────────────────────────────────────────────
async function whLogin() {
  const u = document.getElementById('wl-user').value.trim();
  const p = document.getElementById('wl-pass').value;
  const err = document.getElementById('wh-login-err');
  err.classList.add('d-none');
  if (!u || !p) { err.textContent = 'Enter username and password.'; err.classList.remove('d-none'); return; }
  const btn = document.querySelector('#wh-login .btn-teal');
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in…';
  const res = await whApi('login', { username: u, password: p }, 'POST');
  btn.disabled = false; btn.innerHTML = '<i class="fa fa-warehouse me-2"></i>Access Warehouse';
  if (res.ok) location.reload();
  else { err.textContent = res.msg || 'Login failed.'; err.classList.remove('d-none'); }
}
document.getElementById('wl-pass')?.addEventListener('keydown', e => { if (e.key === 'Enter') whLogin(); });
function whLogout() { window.location = WH_SELF + '?action=logout'; }

// ── FORGOT PASSWORD ───────────────────────────────────────────
let _fgUsername = '';
function fgReset() {
  document.getElementById('fg-err').classList.add('d-none');
  document.getElementById('fg-msg').classList.add('d-none');
  document.getElementById('fg-step-1').classList.remove('d-none');
  document.getElementById('fg-step-2').classList.add('d-none');
  document.getElementById('fg-step-3').classList.add('d-none');
  document.getElementById('fg-username').value = '';
  document.getElementById('fg-email').value = '';
  document.getElementById('fg-otp').value = '';
  document.getElementById('fg-pass1').value = '';
  document.getElementById('fg-pass2').value = '';
}
function openForgotModal() { fgReset(); whOpenModal('modal-forgot'); }
function fgError(msg) {
  const e = document.getElementById('fg-err');
  e.textContent = msg; e.classList.remove('d-none');
  document.getElementById('fg-msg').classList.add('d-none');
}
function fgSuccess(msg) {
  const m = document.getElementById('fg-msg');
  m.textContent = msg; m.classList.remove('d-none');
  document.getElementById('fg-err').classList.add('d-none');
}

async function forgotCheckUsername() {
  const u = document.getElementById('fg-username').value.trim();
  if (!u) { fgError('Enter your username.'); return; }
  const res = await whApi('wh_forgot_check', { username: u }, 'POST');
  if (!res.ok) { fgError(res.msg || 'Username not found.'); return; }
  _fgUsername = u;
  document.getElementById('fg-hint').textContent = res.has_email
    ? `We have ${res.masked_email} on file. Enter it below to receive your reset code.`
    : `No email on file yet — enter the email you'd like to use for this account.`;
  document.getElementById('fg-step-1').classList.add('d-none');
  document.getElementById('fg-step-2').classList.remove('d-none');
}

async function forgotSendOtp() {
  const email = document.getElementById('fg-email').value.trim();
  if (!email) { fgError('Enter your email address.'); return; }
  const res = await whApi('wh_forgot_send', { username: _fgUsername, email }, 'POST');
  if (!res.ok) { fgError(res.msg || 'Could not send code.'); return; }
  fgSuccess(res.msg || 'Reset code sent — check your inbox.');
  document.getElementById('fg-step-2').classList.add('d-none');
  document.getElementById('fg-step-3').classList.remove('d-none');
}

async function forgotResetPassword() {
  const otp = document.getElementById('fg-otp').value.trim();
  const p1  = document.getElementById('fg-pass1').value;
  const p2  = document.getElementById('fg-pass2').value;
  if (!otp || !p1 || !p2) { fgError('Fill in all fields.'); return; }
  if (p1 !== p2) { fgError('Passwords do not match.'); return; }
  const res = await whApi('wh_forgot_reset', { username: _fgUsername, otp, password: p1, password2: p2 }, 'POST');
  if (!res.ok) { fgError(res.msg || 'Reset failed.'); return; }
  fgSuccess(res.msg || 'Password reset! You can now sign in.');
  setTimeout(() => whCloseModal('modal-forgot'), 1500);
}

// ── WAREHOUSE SWITCHER ────────────────────────────────────────
async function switchWarehouse() {
  const wh_id = document.getElementById('wh-switcher').value;
  if (!wh_id) return;
  const res = await whApi('switch_warehouse', { warehouse_id: wh_id }, 'POST');
  if (res.ok) { whToast('Warehouse switched', 'success'); whNav(document.querySelector('.wh-nav-link.active')?.dataset?.page || 'dashboard'); }
  else whToast(res.msg || 'Failed', 'error');
}

// ── NAVIGATION ────────────────────────────────────────────────
const WH_TITLES = {
  dashboard: 'Dashboard', grn: 'Goods Received Notes', batches: 'Batches & Lots',
  outbound: 'Outbound Orders', bom: 'Bill of Materials', production: 'Production Orders',
  locations: 'Locations & Bins', stock_count: 'Stock Count', wh_transfers: 'WH Transfers',
  ledger: 'Stock Ledger', warehouses: 'Warehouses & Zones', delivery_schedule: 'Delivery Schedule',
};

function whNav(page) {
  document.querySelectorAll('.wh-nav-link').forEach(b => b.classList.toggle('active', b.dataset.page === page));
  document.getElementById('wh-page-title').textContent = WH_TITLES[page] || page;
  whCloseSidebar();
  const el = document.getElementById('wh-content');
  whLoading(el);
  const pages = {
    dashboard, grn: renderGRN, batches: renderBatches,
    outbound: renderOutbound, bom: renderBOM, production: renderProduction,
    locations: renderLocations, stock_count: renderStockCount,
    wh_transfers: renderWHTransfers, ledger: renderLedger,
    warehouses: renderWarehouses, delivery_schedule: renderDeliverySchedule,
  };
  (pages[page] || (() => el.innerHTML = '<p class="text-danger p-4">Page not found.</p>'))(el);
}

// ── STATUS BADGE ──────────────────────────────────────────────
function stBadge(s) {
  return `<span class="st-badge st-${(s||'').replace(/\s/g,'_')}">${wh_esc((s||'').replace(/_/g,' '))}</span>`;
}
function mvBadge(t) {
  return `<span class="mv mv-${t||''}">${(t||'').replace(/_/g,' ')}</span>`;
}
function prioDot(p) {
  return `<span class="prio-dot prio-${p||'normal'}"></span>`;
}
function expiryClass(d) {
  if (!d) return '';
  const days = Math.ceil((new Date(d) - new Date()) / 86400000);
  return days < 0 ? 'expiry-expired' : days <= 30 ? 'expiry-soon' : 'expiry-ok';
}

// ════════════════════════════════════════════════════════════
//  DASHBOARD
// ════════════════════════════════════════════════════════════
async function dashboard(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h5 style="font-family:var(--head);margin-bottom:2px">Warehouse Overview</h5>
        <div style="font-size:.72rem;color:var(--muted)" id="wh-dash-sub">Loading…</div>
      </div>
      <span style="font-family:var(--mono);font-size:.75rem;color:var(--accent);background:rgba(0,212,170,.08);border:1px solid rgba(0,212,170,.2);border-radius:6px;padding:4px 12px" id="wh-dash-time"></span>
    </div>

    <div class="row g-3 mb-4" id="wh-kpis">
      ${[1,2,3,4,5,6].map(()=>`<div class="col-6 col-md-4 col-lg-2"><div class="kpi-card"><div class="d-flex justify-content-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`).join('')}
    </div>

    <div class="row g-4 mb-4">
      <div class="col-lg-8">
        <div class="wh-card h-100">
          <div class="wh-card-header">Zone Utilization</div>
          <div class="p-3" id="wh-zone-stats">
            <div class="d-flex justify-content-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="wh-card h-100">
          <div class="wh-card-header">Upcoming Deliveries <span style="color:var(--muted);font-weight:400">next 7 days</span></div>
          <div id="wh-upcoming" style="max-height:280px;overflow-y:auto">
            <div class="d-flex justify-content-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div>
          </div>
        </div>
      </div>
    </div>

    <div class="wh-card">
      <div class="wh-card-header">Recent Stock Movements</div>
      <div class="table-responsive" id="wh-recent-mvmt">
        <div class="d-flex justify-content-center py-3"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div>
      </div>
    </div>`;

  // Live clock
  const tick = () => {
    const t = document.getElementById('wh-dash-time');
    if (t) t.textContent = new Date().toLocaleString('en-KE');
    else return;
    setTimeout(tick, 1000);
  };
  tick();

  const d = await whApi('wh_dashboard');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed to load dashboard.</p>'; return; }

  const sub = document.getElementById('wh-dash-sub');
  if (sub) sub.textContent = new Date().toLocaleDateString('en-KE', { weekday:'long', year:'numeric', month:'long', day:'numeric' });

  // KPIs
  document.getElementById('wh-kpis').innerHTML = [
    { label:'GRNs Today',       val: d.grn_today?.cnt || 0,        sub: wh_fmtN(d.grn_today?.qty || 0) + ' units received', icon:'fa-truck-ramp-box', color:'var(--accent)' },
    { label:'Open Orders',      val: d.open_orders,                 sub: 'awaiting dispatch',            icon:'fa-dolly',          color:'var(--accent2)' },
    { label:'Active Production',val: d.active_production,           sub: 'production runs',              icon:'fa-industry',       color:'var(--purple)' },
    { label:'Deliveries Today', val: d.deliveries_today,            sub: 'scheduled arrivals',           icon:'fa-calendar-check', color:'var(--warn)' },
    { label:'Expiring Soon',    val: d.expiring_batches,            sub: 'batches within 30 days',       icon:'fa-clock',          color:'var(--warn)' },
    { label:'In Quarantine',    val: d.quarantine_batches,          sub: 'batches pending QC',           icon:'fa-shield-halved',  color:'var(--danger)' },
  ].map(k => `
    <div class="col-6 col-md-4 col-lg-2">
      <div class="kpi-card" style="--kpi-color:${k.color}">
        <div class="kpi-label">${k.label}</div>
        <div class="kpi-value">${k.val}</div>
        <div class="kpi-sub">${k.sub}</div>
        <i class="fa ${k.icon} kpi-icon"></i>
      </div>
    </div>`).join('');

  // Zone stats
  const zoneEl = document.getElementById('wh-zone-stats');
  if (zoneEl && d.zone_stats?.length) {
    zoneEl.innerHTML = d.zone_stats.map(z => {
      const total = parseInt(z.total_bins) || 1;
      const occ   = parseInt(z.occupied_bins) || 0;
      const pct   = Math.round((occ / total) * 100);
      const typeColors = { raw_material:'var(--accent2)', finished_goods:'var(--success)', work_in_progress:'var(--warn)', quarantine:'var(--danger)', dispatch:'var(--purple)', general:'var(--muted2)' };
      const color = typeColors[z.type] || 'var(--accent)';
      return `<div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <div class="d-flex align-items-center gap-2">
            <span style="width:8px;height:8px;border-radius:50%;background:${color};display:inline-block"></span>
            <span class="fw-bold small">${wh_esc(z.name)}</span>
            <span style="font-size:.65rem;color:var(--muted);font-family:var(--mono)">${wh_esc(z.code)}</span>
          </div>
          <div style="font-size:.72rem;color:var(--muted2)">${occ}/${total} bins · ${wh_fmtN(z.total_qty)} units</div>
        </div>
        <div class="zone-bar"><div class="zone-bar-fill" style="width:${pct}%;background:${color}"></div></div>
        <div style="font-size:.62rem;color:var(--muted);margin-top:2px">${pct}% occupied</div>
      </div>`;
    }).join('');
  } else if (zoneEl) {
    zoneEl.innerHTML = '<p class="text-center py-3" style="color:var(--muted);font-size:.8rem">No zones configured. Go to Warehouses & Zones to set up.</p>';
  }

  // Upcoming deliveries
  const upEl = document.getElementById('wh-upcoming');
  if (upEl) {
    upEl.innerHTML = d.upcoming_deliveries?.length
      ? d.upcoming_deliveries.map(del => `
        <div class="p-3 border-bottom" style="border-color:var(--border)!important">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="fw-bold small">${wh_esc(del.supplier_name || 'Unknown Supplier')}</div>
              <div style="font-size:.68rem;color:var(--muted)">${wh_fmtDate(del.scheduled_date)} ${del.scheduled_time ? '@ ' + del.scheduled_time : ''}</div>
              ${del.driver_name ? `<div style="font-size:.65rem;color:var(--muted2)">Driver: ${wh_esc(del.driver_name)}</div>` : ''}
              ${del.dock_door   ? `<div style="font-size:.65rem;color:var(--accent)">Dock: ${wh_esc(del.dock_door)}</div>` : ''}
            </div>
            ${stBadge(del.status)}
          </div>
        </div>`).join('')
      : '<p class="text-center py-4" style="color:var(--muted);font-size:.8rem">No deliveries scheduled this week</p>';
  }

  // Recent movements
  const mvEl = document.getElementById('wh-recent-mvmt');
  if (mvEl) {
    const rows = (d.recent_movements || []).map(m => `
      <tr>
        <td style="font-size:.68rem;color:var(--muted);white-space:nowrap">${new Date(m.created_at).toLocaleString('en-KE', {hour:'2-digit',minute:'2-digit',day:'numeric',month:'short'})}</td>
        <td class="fw-bold small">${wh_esc(m.product_name)}</td>
        <td>${mvBadge(m.movement_type)}</td>
        <td class="small ${m.qty_in > 0 ? 'text-success' : 'text-danger'} fw-bold">
          ${m.qty_in > 0 ? '+' + wh_fmtN(m.qty_in) : '-' + wh_fmtN(m.qty_out)}
        </td>
        <td style="font-size:.72rem;color:var(--muted);font-family:var(--mono)">${wh_esc(m.location_label||'—')}</td>
        <td style="font-size:.68rem;color:var(--muted)">${wh_esc(m.batch_number||'—')}</td>
      </tr>`).join('');
    mvEl.innerHTML = `<table class="table wh-table table-sm mb-0">
      <thead><tr><th>Time</th><th>Product</th><th>Type</th><th>Qty</th><th>Location</th><th>Batch</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="6" class="text-center py-4" style="color:var(--muted)">No movements yet</td></tr>'}</tbody>
    </table>`;
  }
}

// ════════════════════════════════════════════════════════════
//  DELIVERY SCHEDULE
// ════════════════════════════════════════════════════════════
async function renderDeliverySchedule(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 style="font-family:var(--head);margin:0">Delivery Schedule</h5>
      <div class="d-flex gap-2">
        <input type="date" id="del-from" class="form-control form-control-sm" value="${wh_today()}" style="width:140px">
        <input type="date" id="del-to"   class="form-control form-control-sm" value="${new Date(Date.now()+7*864e5).toISOString().slice(0,10)}" style="width:140px">
        <button class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--text)" onclick="loadDeliveries()"><i class="fa fa-search"></i></button>
        <button class="btn btn-teal btn-sm fw-bold" onclick="openDeliveryModal()"><i class="fa fa-plus me-1"></i>Schedule</button>
      </div>
    </div>
    <div class="wh-card"><div class="table-responsive" id="del-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`;
  const supp = await whApi('wh_suppliers');
  if (supp.ok) {
    document.getElementById('del-supplier').innerHTML = '<option value="">— No Supplier —</option>' + supp.suppliers.map(s => `<option value="${s.id}">${wh_esc(s.name)}</option>`).join('');
  }
  loadDeliveries();
}

async function loadDeliveries() {
  const from = document.getElementById('del-from')?.value || wh_today();
  const to   = document.getElementById('del-to')?.value   || wh_today();
  const d    = await whApi('delivery_list', { from, to });
  const el   = document.getElementById('del-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const statusColor = { scheduled:'var(--accent2)', confirmed:'var(--success)', arrived:'var(--warn)', completing:'var(--accent)', completed:'var(--muted)', cancelled:'var(--danger)', missed:'var(--danger)' };
  const rows = d.deliveries.map(del => `
    <tr>
      <td class="small fw-bold">${wh_fmtDate(del.scheduled_date)}</td>
      <td class="small" style="color:var(--muted);font-family:var(--mono)">${del.scheduled_time || '—'}</td>
      <td class="fw-bold small">${wh_esc(del.supplier_name || '—')}</td>
      <td style="font-family:var(--mono);font-size:.75rem">${wh_esc(del.vehicle_reg||'—')}</td>
      <td class="small">${wh_esc(del.driver_name||'—')}</td>
      <td class="small" style="color:var(--accent);font-family:var(--mono)">${wh_esc(del.dock_door||'—')}</td>
      <td>${stBadge(del.status)}</td>
      <td><button class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--muted);font-size:.72rem" onclick='openDeliveryEdit(${JSON.stringify(del)})'><i class="fa fa-pen"></i></button></td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Date</th><th>Time</th><th>Supplier</th><th>Vehicle</th><th>Driver</th><th>Dock</th><th>Status</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="8" class="text-center py-4" style="color:var(--muted)">No deliveries scheduled</td></tr>'}</tbody>
  </table>`;
}

function openDeliveryModal() {
  document.getElementById('del-id').value = '';
  document.getElementById('del-modal-title').textContent = 'Schedule Delivery';
  ['del-vehicle','del-driver','del-driver-phone','del-dock','del-notes'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('del-date').value   = wh_today();
  document.getElementById('del-time').value   = '';
  document.getElementById('del-status').value = 'scheduled';
  whOpenModal('modal-delivery');
}
function openDeliveryEdit(del) {
  document.getElementById('del-id').value           = del.id;
  document.getElementById('del-modal-title').textContent = 'Edit Delivery';
  document.getElementById('del-supplier').value     = del.supplier_id || '';
  document.getElementById('del-date').value         = del.scheduled_date || wh_today();
  document.getElementById('del-time').value         = del.scheduled_time || '';
  document.getElementById('del-vehicle').value      = del.vehicle_reg || '';
  document.getElementById('del-driver').value       = del.driver_name || '';
  document.getElementById('del-driver-phone').value = del.driver_phone || '';
  document.getElementById('del-dock').value         = del.dock_door || '';
  document.getElementById('del-status').value       = del.status || 'scheduled';
  document.getElementById('del-notes').value        = del.notes || '';
  whOpenModal('modal-delivery');
}
async function saveDelivery() {
  const res = await whApi('delivery_save', {
    id: document.getElementById('del-id').value,
    supplier_id:   document.getElementById('del-supplier').value,
    scheduled_date:document.getElementById('del-date').value,
    scheduled_time:document.getElementById('del-time').value,
    vehicle_reg:   document.getElementById('del-vehicle').value,
    driver_name:   document.getElementById('del-driver').value,
    driver_phone:  document.getElementById('del-driver-phone').value,
    dock_door:     document.getElementById('del-dock').value,
    status:        document.getElementById('del-status').value,
    notes:         document.getElementById('del-notes').value,
  }, 'POST');
  if (res.ok) { whToast('Delivery saved', 'success'); whCloseModal('modal-delivery'); loadDeliveries(); }
  else whToast(res.msg || 'Failed', 'error');
}

// ════════════════════════════════════════════════════════════
//  GRN
// ════════════════════════════════════════════════════════════
let _grnItems = [];
async function renderGRN(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 style="font-family:var(--head);margin:0">Goods Received Notes</h5>
      <div class="d-flex gap-2 flex-wrap">
        <input type="date" id="grn-from" class="form-control form-control-sm" value="${wh_today()}" style="width:140px">
        <input type="date" id="grn-to"   class="form-control form-control-sm" value="${wh_today()}" style="width:140px">
        <button class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--text)" onclick="loadGRNs()"><i class="fa fa-search"></i></button>
        <button class="btn btn-teal btn-sm fw-bold" onclick="openGRNModal()"><i class="fa fa-plus me-1"></i>New GRN</button>
      </div>
    </div>
    <div class="wh-card"><div class="table-responsive" id="grn-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`;
  loadGRNs();
}

async function loadGRNs() {
  const from = document.getElementById('grn-from')?.value || wh_today();
  const to   = document.getElementById('grn-to')?.value   || wh_today();
  const d    = await whApi('grn_list', { from, to });
  const el   = document.getElementById('grn-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.grns.map(g => `
    <tr>
      <td><span class="mono" style="color:var(--accent)">${wh_esc(g.grn_number)}</span></td>
      <td class="small">${wh_fmtDate(g.delivery_date)}</td>
      <td class="fw-bold small">${wh_esc(g.supplier_name||'—')}</td>
      <td class="small" style="font-family:var(--mono)">${wh_esc(g.supplier_invoice||'—')}</td>
      <td class="small">${wh_fmtN(g.total_qty_received)}</td>
      <td class="small" style="color:var(--success)">${wh_fmtN(g.total_qty_accepted)}</td>
      <td class="small" style="color:var(--danger)">${wh_fmtN(g.total_qty_rejected)}</td>
      <td>${stBadge(g.status)}</td>
      <td>
        ${g.status === 'qc_pending' ? `<button class="btn btn-sm" style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.25);color:var(--warn);font-size:.72rem" onclick="openQC(${g.id})"><i class="fa fa-clipboard-check me-1"></i>QC</button>` : ''}
        <button class="btn btn-sm ms-1" style="background:var(--surface);border:1px solid var(--border);color:var(--muted);font-size:.72rem" onclick="viewGRN(${g.id})"><i class="fa fa-eye"></i></button>
      </td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>GRN No.</th><th>Date</th><th>Supplier</th><th>Invoice</th><th>Total Rcvd</th><th>Accepted</th><th>Rejected</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="9" class="text-center py-4" style="color:var(--muted)">No GRNs for this period</td></tr>'}</tbody>
  </table>`;
}

async function openGRNModal() {
  _grnItems = [];
  const [supp, prods, locs] = await Promise.all([
    whApi('wh_suppliers'), whApi('wh_products', { q: '' }), whApi('locations_list', { q: '' })
  ]);
  document.getElementById('grn-supplier').innerHTML = '<option value="">— Walk-in / No Supplier —</option>' + (supp.suppliers||[]).map(s => `<option value="${s.id}">${wh_esc(s.name)}</option>`).join('');
  document.getElementById('grn-prod-sel').innerHTML = (prods.products||[]).map(p => `<option value="${p.id}" data-cost="${p.buying_price}">${wh_esc(p.name)} (${wh_esc(p.sku||'—')})</option>`).join('');
  document.getElementById('grn-loc').innerHTML = '<option value="">— No Location —</option>' + (locs.locations||[]).map(l => `<option value="${l.id}">${wh_esc(l.label)} [${wh_esc(l.zone_name)}]</option>`).join('');
  document.getElementById('grn-date').value = wh_today();
  ['grn-qty','grn-cost','grn-batch','grn-expiry','grn-invoice','grn-do','grn-notes','grn-vehicle','grn-driver'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('grn-items-list').innerHTML = '';
  document.getElementById('grn-item-count').textContent = '0';
  whOpenModal('modal-grn');
}

function grnAddItem() {
  const sel  = document.getElementById('grn-prod-sel');
  const opt  = sel.selectedOptions[0];
  const qty  = parseFloat(document.getElementById('grn-qty').value) || 0;
  if (!qty || qty <= 0) { whToast('Enter quantity received', 'error'); return; }
  _grnItems.push({
    product_id:  sel.value,
    product_name:opt.text,
    qty_received: qty,
    unit_cost:   parseFloat(document.getElementById('grn-cost').value) || parseFloat(opt.dataset.cost) || 0,
    batch_number:document.getElementById('grn-batch').value.trim() || ('B-' + Date.now()),
    expiry_date: document.getElementById('grn-expiry').value || '',
    location_id: document.getElementById('grn-loc').value || '',
  });
  renderGRNItems();
  ['grn-qty','grn-batch','grn-expiry'].forEach(i => document.getElementById(i).value = '');
}

function renderGRNItems() {
  document.getElementById('grn-item-count').textContent = _grnItems.length;
  document.getElementById('grn-items-list').innerHTML = _grnItems.map((it, i) => `
    <div class="d-flex align-items-center justify-content-between py-2 border-bottom small" style="border-color:var(--border)!important">
      <div>
        <div class="fw-bold">${wh_esc(it.product_name)}</div>
        <div style="font-size:.68rem;color:var(--muted);font-family:var(--mono)">Batch: ${wh_esc(it.batch_number)} ${it.expiry_date ? '· Exp: '+it.expiry_date : ''}</div>
      </div>
      <div class="d-flex align-items-center gap-3">
        <span class="fw-bold" style="color:var(--accent)">${wh_fmtN(it.qty_received)} units</span>
        <span style="color:var(--muted)">${wh_fmt(it.unit_cost)} each</span>
        <button class="btn btn-sm text-danger border-0 p-0" onclick="_grnItems.splice(${i},1);renderGRNItems()">×</button>
      </div>
    </div>`).join('');
}

async function submitGRN() {
  if (!_grnItems.length) { whToast('Add at least one item', 'error'); return; }
  const res = await whApi('grn_create', {
    supplier_id:     document.getElementById('grn-supplier').value,
    delivery_date:   document.getElementById('grn-date').value,
    vehicle_reg:     document.getElementById('grn-vehicle').value,
    driver_name:     document.getElementById('grn-driver').value,
    supplier_invoice:document.getElementById('grn-invoice').value,
    supplier_do:     document.getElementById('grn-do').value,
    notes:           document.getElementById('grn-notes').value,
    items:           JSON.stringify(_grnItems),
  }, 'POST');
  if (res.ok) { whToast('GRN created: ' + res.grn_number, 'success'); whCloseModal('modal-grn'); loadGRNs(); }
  else whToast(res.msg || 'Failed', 'error');
}

let _qcGrnId = null;
async function openQC(grn_id) {
  _qcGrnId = grn_id;
  document.getElementById('qc-body').innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border" style="color:var(--accent)"></div></div>';
  whOpenModal('modal-qc');
  const d = await whApi('grn_detail', { id: grn_id });
  if (!d.ok) { document.getElementById('qc-body').innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.items.map(it => `
    <tr>
      <td class="fw-bold small">${wh_esc(it.product_name)}</td>
      <td class="small mono">${wh_esc(it.batch_number||'—')}</td>
      <td class="small">${wh_fmtN(it.qty_received)}</td>
      <td><input type="number" class="form-control form-control-sm qc-accepted" data-item-id="${it.id}" value="${it.qty_received}" min="0" step="0.001" style="width:90px" oninput="updateQCRejected(this,${it.qty_received})"></td>
      <td><input type="number" class="form-control form-control-sm qc-rejected" value="0" min="0" step="0.001" style="width:90px" readonly></td>
      <td><input type="text" class="form-control form-control-sm qc-rejection-reason" placeholder="Reason if rejected" style="min-width:130px"></td>
      <td><input type="text" class="form-control form-control-sm qc-notes" placeholder="QC notes"></td>
    </tr>`).join('');
  document.getElementById('qc-body').innerHTML = `
    <p class="small mb-3" style="color:var(--muted)">GRN: <strong style="color:var(--accent)">${wh_esc(d.grn.grn_number)}</strong> · Supplier: ${wh_esc(d.grn.supplier_name||'—')}</p>
    <div class="table-responsive"><table class="table wh-table table-sm">
      <thead><tr><th>Product</th><th>Batch</th><th>Received</th><th>Accepted ✓</th><th>Rejected ✗</th><th>Rejection Reason</th><th>QC Notes</th></tr></thead>
      <tbody>${rows}</tbody>
    </table></div>
    <div class="mt-3"><label class="form-label">Overall QC Notes</label><textarea id="qc-overall-notes" class="form-control" rows="2"></textarea></div>`;
}

function updateQCRejected(inp, total) {
  const acc = parseFloat(inp.value) || 0;
  const rej = Math.max(0, total - acc);
  const tr  = inp.closest('tr');
  if (tr) {
    const rejInp = tr.querySelector('.qc-rejected');
    if (rejInp) rejInp.value = rej.toFixed(3);
  }
}

async function submitQC() {
  const rows   = document.querySelectorAll('#qc-body tbody tr');
  const items  = [];
  rows.forEach(row => {
    const acc_inp = row.querySelector('.qc-accepted');
    if (!acc_inp) return;
    items.push({
      item_id:          acc_inp.dataset.itemId,
      qty_accepted:     parseFloat(acc_inp.value) || 0,
      qty_rejected:     parseFloat(row.querySelector('.qc-rejected')?.value) || 0,
      rejection_reason: row.querySelector('.qc-rejection-reason')?.value || '',
      qc_notes:         row.querySelector('.qc-notes')?.value || '',
    });
  });
  const res = await whApi('grn_qc', {
    grn_id: _qcGrnId,
    items: JSON.stringify(items),
    qc_notes: document.getElementById('qc-overall-notes')?.value || '',
  }, 'POST');
  if (res.ok) { whToast('QC submitted: ' + res.status.replace(/_/g,' '), 'success'); whCloseModal('modal-qc'); loadGRNs(); }
  else whToast(res.msg || 'QC failed', 'error');
}

async function viewGRN(id) {
  const d = await whApi('grn_detail', { id });
  if (!d.ok) { whToast('Failed to load GRN', 'error'); return; }
  alert(`GRN: ${d.grn.grn_number}\nSupplier: ${d.grn.supplier_name||'—'}\nStatus: ${d.grn.status}\nItems: ${d.items.length}\nQty Accepted: ${d.grn.total_qty_accepted}\nQty Rejected: ${d.grn.total_qty_rejected}`);
}

// ════════════════════════════════════════════════════════════
//  BATCHES
// ════════════════════════════════════════════════════════════
async function renderBatches(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 style="font-family:var(--head);margin:0">Batches & Lots</h5>
      <div class="d-flex gap-2">
        <input type="text" id="batch-search" class="form-control form-control-sm" placeholder="Search batch no. or product…" style="width:200px" oninput="loadBatches()">
        <select id="batch-status-filter" class="form-select form-select-sm" style="width:140px" onchange="loadBatches()">
          <option value="">All Statuses</option>
          <option value="quarantine">Quarantine</option>
          <option value="available">Available</option>
          <option value="reserved">Reserved</option>
          <option value="consumed">Consumed</option>
          <option value="expired">Expired</option>
          <option value="rejected">Rejected</option>
        </select>
      </div>
    </div>
    <div class="wh-card"><div class="table-responsive" id="batch-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`;
  loadBatches();
}

async function loadBatches() {
  const q      = document.getElementById('batch-search')?.value || '';
  const status = document.getElementById('batch-status-filter')?.value || '';
  const d      = await whApi('batches_list', { q, status });
  const el     = document.getElementById('batch-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.batches.map(b => {
    const ec = expiryClass(b.expiry_date);
    return `<tr>
      <td><span class="mono" style="color:var(--accent)">${wh_esc(b.batch_number)}</span></td>
      <td class="fw-bold small">${wh_esc(b.product_name)}</td>
      <td class="small mono" style="color:var(--muted)">${wh_esc(b.sku||'—')}</td>
      <td>${stBadge(b.status)}</td>
      <td class="small fw-bold" style="color:var(--success)">${wh_fmtN(b.qty_available)}</td>
      <td class="small" style="color:var(--warn)">${wh_fmtN(b.qty_reserved)}</td>
      <td class="small" style="color:var(--muted)">${wh_fmtN(b.qty_consumed)}</td>
      <td class="small ${ec}">${b.expiry_date || '—'}</td>
      <td class="small mono" style="color:var(--muted)">${wh_esc(b.location_label||'—')}</td>
      <td>
        ${b.status === 'quarantine' ? `
          <button class="btn btn-sm me-1" style="background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.25);color:var(--success);font-size:.7rem" onclick="releaseQC(${b.id},'available')">Release ✓</button>
          <button class="btn btn-sm" style="background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:var(--danger);font-size:.7rem" onclick="releaseQC(${b.id},'rejected')">Reject ✗</button>` : ''}
      </td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Batch No.</th><th>Product</th><th>SKU</th><th>Status</th><th>Available</th><th>Reserved</th><th>Consumed</th><th>Expiry</th><th>Location</th><th>Actions</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="10" class="text-center py-4" style="color:var(--muted)">No batches found</td></tr>'}</tbody>
  </table>`;
}

async function releaseQC(batch_id, decision) {
  const notes = decision === 'rejected' ? (prompt('Rejection reason:') || '') : '';
  const res = await whApi('batch_release', { batch_id, decision, notes }, 'POST');
  if (res.ok) { whToast('Batch ' + decision, decision === 'available' ? 'success' : 'warn'); loadBatches(); }
  else whToast(res.msg || 'Failed', 'error');
}

// ════════════════════════════════════════════════════════════
//  OUTBOUND ORDERS
// ════════════════════════════════════════════════════════════
let _obItems = [];
async function renderOutbound(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 style="font-family:var(--head);margin:0">Outbound Orders</h5>
      <div class="d-flex gap-2">
        <select id="ob-status-filter" class="form-select form-select-sm" style="width:160px" onchange="loadOutbound()">
          <option value="">All Statuses</option>
          <option value="confirmed">Confirmed</option>
          <option value="picking">Picking</option>
          <option value="picked">Picked</option>
          <option value="dispatched">Dispatched</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <button class="btn btn-teal btn-sm fw-bold" onclick="openOutboundModal()"><i class="fa fa-plus me-1"></i>New Order</button>
      </div>
    </div>
    <div class="wh-card"><div class="table-responsive" id="ob-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`;
  loadOutbound();
}

async function loadOutbound() {
  const status = document.getElementById('ob-status-filter')?.value || '';
  const d      = await whApi('outbound_list', { status });
  const el     = document.getElementById('ob-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.orders.map(o => `
    <tr>
      <td><span class="mono" style="color:var(--accent)">${wh_esc(o.order_number)}</span></td>
      <td class="small">${wh_esc(o.order_type.replace(/_/g,' '))}</td>
      <td class="small">${wh_esc(o.dest_branch_name||'—')}</td>
      <td>${prioDot(o.priority)}${stBadge(o.priority)}</td>
      <td>${stBadge(o.status)}</td>
      <td class="small" style="color:var(--muted)">${wh_fmtDate(o.required_date)}</td>
      <td class="small">${wh_esc(o.picker_name||'—')}</td>
      <td class="d-flex gap-1">
        ${o.status === 'confirmed' ? `<button class="btn btn-sm btn-teal" style="font-size:.7rem" onclick="openPickModal(${o.id})"><i class="fa fa-clipboard-list me-1"></i>Pick</button>` : ''}
        ${o.status === 'picked'    ? `<button class="btn btn-sm btn-sky" style="font-size:.7rem" onclick="dispatchOrder(${o.id})"><i class="fa fa-truck me-1"></i>Dispatch</button>` : ''}
      </td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Order No.</th><th>Type</th><th>Destination</th><th>Priority</th><th>Status</th><th>Required</th><th>Picker</th><th>Actions</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="8" class="text-center py-4" style="color:var(--muted)">No orders found</td></tr>'}</tbody>
  </table>`;
}

async function openOutboundModal() {
  _obItems = [];
  const [branches, prods] = await Promise.all([whApi('wh_branches'), whApi('wh_products', { q: '' })]);
  document.getElementById('ob-dest-branch').innerHTML = '<option value="">— Select Branch —</option>' + (branches.branches||[]).map(b => `<option value="${b.id}">${wh_esc(b.name)}</option>`).join('');
  document.getElementById('ob-prod-sel').innerHTML = (prods.products||[]).map(p => `<option value="${p.id}">${wh_esc(p.name)} (${wh_esc(p.sku||'—')})</option>`).join('');
  document.getElementById('ob-items-list').innerHTML = '';
  document.getElementById('ob-qty').value = '';
  document.getElementById('ob-req-date').value = '';
  whOpenModal('modal-outbound');
}

function toggleOBDest() {
  const type = document.getElementById('ob-type').value;
  const col  = document.getElementById('ob-dest-branch-col');
  if (col) col.style.display = type === 'branch_replenishment' ? '' : 'none';
}

function obAddItem() {
  const sel = document.getElementById('ob-prod-sel');
  const qty = parseFloat(document.getElementById('ob-qty').value) || 0;
  if (!qty || qty <= 0) { whToast('Enter quantity', 'error'); return; }
  _obItems.push({ product_id: sel.value, product_name: sel.selectedOptions[0]?.text, qty });
  renderOBItems();
  document.getElementById('ob-qty').value = '';
}

function renderOBItems() {
  document.getElementById('ob-items-list').innerHTML = _obItems.map((it, i) => `
    <div class="d-flex align-items-center justify-content-between py-2 border-bottom small" style="border-color:var(--border)!important">
      <span class="fw-bold">${wh_esc(it.product_name)}</span>
      <div class="d-flex gap-2 align-items-center">
        <span style="color:var(--accent)">${wh_fmtN(it.qty)} units</span>
        <button class="btn btn-sm text-danger border-0 p-0" onclick="_obItems.splice(${i},1);renderOBItems()">×</button>
      </div>
    </div>`).join('');
}

async function submitOutbound() {
  if (!_obItems.length) { whToast('Add at least one item', 'error'); return; }
  const res = await whApi('outbound_create', {
    order_type:    document.getElementById('ob-type').value,
    dest_branch_id:document.getElementById('ob-dest-branch').value,
    priority:      document.getElementById('ob-priority').value,
    required_date: document.getElementById('ob-req-date').value,
    items:         JSON.stringify(_obItems),
  }, 'POST');
  if (res.ok) { whToast('Order created: ' + res.order_number, 'success'); whCloseModal('modal-outbound'); loadOutbound(); }
  else whToast(res.msg || 'Failed', 'error');
}

let _pickOrderId = null;
async function openPickModal(order_id) {
  _pickOrderId = order_id;
  document.getElementById('pick-body').innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border" style="color:var(--accent)"></div></div>';
  whOpenModal('modal-pick');
  const d = await whApi('outbound_detail', { id: order_id });
  if (!d.ok) { document.getElementById('pick-body').innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.items.map(it => `
    <tr>
      <td class="fw-bold small">${wh_esc(it.product_name)}</td>
      <td class="small mono" style="color:var(--muted)">${wh_esc(it.location_label||'—')}</td>
      <td class="small mono" style="color:var(--accent)">${wh_esc(it.batch_number||'—')}</td>
      <td class="${expiryClass(it.expiry_date)} small">${it.expiry_date||'—'}</td>
      <td class="fw-bold small">${wh_fmtN(it.qty_requested)}</td>
      <td><input type="number" class="form-control form-control-sm pick-qty" data-item-id="${it.id}" value="${it.qty_requested}" min="0" step="0.001" style="width:90px"></td>
      <td>${stBadge(it.status)}</td>
    </tr>`).join('');
  document.getElementById('pick-body').innerHTML = `
    <p class="small mb-3" style="color:var(--muted)">Order: <strong style="color:var(--accent)">${wh_esc(d.order.order_number)}</strong> · Dest: ${wh_esc(d.order.dest_branch||'—')}</p>
    <div class="table-responsive"><table class="table wh-table table-sm">
      <thead><tr><th>Product</th><th>Location</th><th>Batch</th><th>Expiry</th><th>Requested</th><th>Pick Qty</th><th>Status</th></tr></thead>
      <tbody>${rows}</tbody>
    </table></div>`;
}

async function submitPick() {
  const inputs = document.querySelectorAll('.pick-qty');
  const picks  = [];
  inputs.forEach(inp => picks.push({ item_id: inp.dataset.itemId, qty_picked: parseFloat(inp.value) || 0 }));
  const res = await whApi('outbound_pick', { order_id: _pickOrderId, picks: JSON.stringify(picks) }, 'POST');
  if (res.ok) { whToast('Picks recorded — ' + res.status, 'success'); whCloseModal('modal-pick'); loadOutbound(); }
  else whToast(res.msg || 'Failed', 'error');
}

async function dispatchOrder(order_id) {
  const notes = prompt('Dispatch notes (optional):') || '';
  const res   = await whApi('outbound_dispatch', { order_id, notes }, 'POST');
  if (res.ok) { whToast('Order dispatched!', 'success'); loadOutbound(); }
  else whToast(res.msg || 'Failed', 'error');
}

// ════════════════════════════════════════════════════════════
//  BOM
// ════════════════════════════════════════════════════════════
let _bomIngredients = [];
async function renderBOM(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 style="font-family:var(--head);margin:0">Bill of Materials</h5>
      <button class="btn btn-teal btn-sm fw-bold" onclick="openBOMModal()"><i class="fa fa-plus me-1"></i>New BOM</button>
    </div>
    <div class="wh-card"><div class="table-responsive" id="bom-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`;
  loadBOMs();
}

async function loadBOMs() {
  const d  = await whApi('bom_list');
  const el = document.getElementById('bom-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.boms.map(b => `
    <tr>
      <td class="fw-bold small">${wh_esc(b.product_name)}</td>
      <td class="small">${wh_esc(b.bom_name)}</td>
      <td class="small mono" style="color:var(--muted)">${wh_esc(b.version)}</td>
      <td class="small">${wh_fmtN(b.qty_produced)} units per run</td>
      <td class="small">${b.ingredient_count} ingredients</td>
      <td>${stBadge(b.is_active ? 'available' : 'cancelled')}</td>
      <td>
        <button class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--muted);font-size:.72rem" onclick="editBOM(${b.id})"><i class="fa fa-pen"></i></button>
        <button class="btn btn-sm ms-1 btn-teal" style="font-size:.72rem" onclick="createProdFromBOM(${b.id},'${wh_esc(b.bom_name)}')"><i class="fa fa-industry me-1"></i>Produce</button>
      </td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Finished Product</th><th>BOM Name</th><th>Version</th><th>Output</th><th>Ingredients</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="7" class="text-center py-4" style="color:var(--muted)">No BOMs defined yet</td></tr>'}</tbody>
  </table>`;
}

async function openBOMModal() {
  _bomIngredients = [];
  document.getElementById('bom-id').value = '';
  document.getElementById('bom-modal-title').textContent = 'New Bill of Materials';
  ['bom-name','bom-notes'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('bom-version').value = 'v1';
  document.getElementById('bom-qty').value = '1';
  document.getElementById('bom-ingredients-list').innerHTML = '';
  const prods = await whApi('wh_products', { q: '' });
  const opts  = (prods.products||[]).map(p => `<option value="${p.id}">${wh_esc(p.name)} (${wh_esc(p.sku||'—')})</option>`).join('');
  document.getElementById('bom-fp').innerHTML     = opts;
  document.getElementById('bom-ing-prod').innerHTML = opts;
  whOpenModal('modal-bom');
}

async function editBOM(id) {
  await openBOMModal();
  const d = await whApi('bom_detail', { id });
  if (!d.ok) { whToast('Failed to load BOM', 'error'); return; }
  document.getElementById('bom-id').value            = d.bom.id;
  document.getElementById('bom-modal-title').textContent = 'Edit BOM';
  document.getElementById('bom-fp').value            = d.bom.finished_product_id;
  document.getElementById('bom-name').value          = d.bom.bom_name;
  document.getElementById('bom-version').value       = d.bom.version;
  document.getElementById('bom-qty').value           = d.bom.qty_produced;
  document.getElementById('bom-notes').value         = d.bom.notes || '';
  _bomIngredients = d.items.map(it => ({ product_id: it.input_product_id, product_name: it.input_name, qty: it.qty_required, unit: it.unit || '', waste_pct: it.waste_pct || 0 }));
  renderBOMIngredients();
}

function bomAddIngredient() {
  const sel = document.getElementById('bom-ing-prod');
  const qty = parseFloat(document.getElementById('bom-ing-qty').value) || 0;
  if (!qty) { whToast('Enter qty required', 'error'); return; }
  _bomIngredients.push({ product_id: sel.value, product_name: sel.selectedOptions[0]?.text, qty, unit: document.getElementById('bom-ing-unit').value, waste_pct: parseFloat(document.getElementById('bom-ing-waste').value) || 0 });
  renderBOMIngredients();
  ['bom-ing-qty','bom-ing-unit','bom-ing-waste'].forEach(i => document.getElementById(i).value = '');
}

function renderBOMIngredients() {
  document.getElementById('bom-ingredients-list').innerHTML = _bomIngredients.map((it, i) => `
    <div class="d-flex align-items-center justify-content-between py-2 border-bottom small" style="border-color:var(--border)!important">
      <span class="fw-bold">${wh_esc(it.product_name)}</span>
      <div class="d-flex gap-2 align-items-center">
        <span style="color:var(--accent)">${wh_fmtN(it.qty)} ${wh_esc(it.unit)}</span>
        ${it.waste_pct > 0 ? `<span style="color:var(--warn);font-size:.68rem">+${it.waste_pct}% waste</span>` : ''}
        <button class="btn btn-sm text-danger border-0 p-0" onclick="_bomIngredients.splice(${i},1);renderBOMIngredients()">×</button>
      </div>
    </div>`).join('');
}

async function saveBOM() {
  if (!_bomIngredients.length) { whToast('Add at least one ingredient', 'error'); return; }
  const res = await whApi('bom_save', {
    id: document.getElementById('bom-id').value,
    finished_product_id: document.getElementById('bom-fp').value,
    bom_name: document.getElementById('bom-name').value,
    version:  document.getElementById('bom-version').value,
    qty_produced: document.getElementById('bom-qty').value,
    notes: document.getElementById('bom-notes').value,
    items: JSON.stringify(_bomIngredients),
  }, 'POST');
  if (res.ok) { whToast('BOM saved', 'success'); whCloseModal('modal-bom'); loadBOMs(); }
  else whToast(res.msg || 'Failed', 'error');
}

// ════════════════════════════════════════════════════════════
//  PRODUCTION
// ════════════════════════════════════════════════════════════
async function renderProduction(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 style="font-family:var(--head);margin:0">Production Orders</h5>
      <button class="btn btn-teal btn-sm fw-bold" onclick="openProductionModal()"><i class="fa fa-plus me-1"></i>New Order</button>
    </div>
    <div class="wh-card"><div class="table-responsive" id="prod-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`;
  loadProduction();
}

async function loadProduction() {
  const d  = await whApi('production_list');
  const el = document.getElementById('prod-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.orders.map(o => `
    <tr>
      <td><span class="mono" style="color:var(--accent)">${wh_esc(o.order_number)}</span></td>
      <td class="fw-bold small">${wh_esc(o.product_name)}</td>
      <td class="small">${wh_esc(o.bom_name)}</td>
      <td class="small">${wh_fmtN(o.qty_planned)}</td>
      <td class="small" style="color:var(--success)">${wh_fmtN(o.qty_produced)}</td>
      <td>${stBadge(o.status)}</td>
      <td class="small">${wh_fmtDate(o.planned_start)} → ${wh_fmtDate(o.planned_end)}</td>
      <td class="small">${wh_esc(o.assigned_name||'—')}</td>
      <td class="d-flex gap-1">
        ${o.status === 'draft' || o.status === 'ready' ? `<button class="btn btn-sm btn-sky" style="font-size:.7rem" onclick="startProduction(${o.id})"><i class="fa fa-play me-1"></i>Start</button>` : ''}
        ${o.status === 'in_progress' ? `<button class="btn btn-sm btn-teal" style="font-size:.7rem" onclick="openCompleteModal(${o.id})"><i class="fa fa-check me-1"></i>Complete</button>` : ''}
      </td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Order No.</th><th>Product</th><th>BOM</th><th>Planned</th><th>Produced</th><th>Status</th><th>Dates</th><th>Assigned</th><th>Actions</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="9" class="text-center py-4" style="color:var(--muted)">No production orders</td></tr>'}</tbody>
  </table>`;
}

async function openProductionModal() {
  const [boms, locs, users] = await Promise.all([
    whApi('bom_list'), whApi('locations_list', { q: '' }), whApi('wh_users')
  ]);
  document.getElementById('prod-bom').innerHTML = (boms.boms||[]).map(b => `<option value="${b.id}" data-qty="${b.qty_produced}">${wh_esc(b.bom_name)} → ${wh_esc(b.product_name)}</option>`).join('');
  document.getElementById('prod-out-loc').innerHTML = '<option value="">— No Location —</option>' + (locs.locations||[]).map(l => `<option value="${l.id}">${wh_esc(l.label)} [${wh_esc(l.zone_name)}]</option>`).join('');
  document.getElementById('prod-assign').innerHTML = '<option value="">— Unassigned —</option>' + (users.users||[]).map(u => `<option value="${u.id}">${wh_esc(u.full_name)}</option>`).join('');
  document.getElementById('prod-qty').value   = '';
  document.getElementById('prod-start').value = wh_today();
  document.getElementById('prod-end').value   = '';
  document.getElementById('bom-preview').innerHTML = '';
  whOpenModal('modal-production');
}

async function createProdFromBOM(bom_id, bom_name) {
  await openProductionModal();
  document.getElementById('prod-bom').value = bom_id;
}

async function loadBOMPreview() {
  const bom_id = document.getElementById('prod-bom').value;
  const qty    = parseFloat(document.getElementById('prod-qty').value) || 0;
  const el     = document.getElementById('bom-preview');
  if (!bom_id || !qty) { el.innerHTML = ''; return; }
  const d = await whApi('bom_detail', { id: bom_id });
  if (!d.ok || !d.items.length) { el.innerHTML = ''; return; }
  const opt = document.querySelector(`#prod-bom option[value="${bom_id}"]`);
  const perRun = parseFloat(opt?.dataset?.qty || 1);
  const mult   = qty / perRun;
  el.innerHTML = `<div class="wh-card p-3 mt-3">
    <div class="small fw-bold mb-2" style="color:var(--muted)">MATERIALS REQUIRED</div>
    ${d.items.map(it => {
      const need = it.qty_required * mult * (1 + (parseFloat(it.waste_pct)||0)/100);
      return `<div class="d-flex justify-content-between py-1 border-bottom small" style="border-color:var(--border)!important">
        <span>${wh_esc(it.input_name)}</span>
        <span style="color:var(--accent);font-weight:600">${wh_fmtN(need)} ${wh_esc(it.unit||'units')}</span>
      </div>`;
    }).join('')}
  </div>`;
}

async function startProduction(prod_id) {
  const res = await whApi('production_start', { prod_id }, 'POST');
  if (res.ok) { whToast('Production started!', 'success'); loadProduction(); }
  else whToast(res.msg || 'Failed', 'error');
}

let _completeProdId = null;
async function openCompleteModal(prod_id) {
  _completeProdId = prod_id;
  document.getElementById('prod-complete-body').innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border" style="color:var(--accent)"></div></div>';
  whOpenModal('modal-prod-complete');
  const d = await whApi('production_detail', { id: prod_id });
  if (!d.ok) { document.getElementById('prod-complete-body').innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.inputs.map(inp => `
    <tr>
      <td class="fw-bold small">${wh_esc(inp.product_name)}</td>
      <td class="small">${wh_fmtN(inp.qty_planned)}</td>
      <td><input type="number" class="form-control form-control-sm pc-actual" data-input-id="${inp.id}" data-product-id="${inp.product_id}" value="${inp.qty_planned}" min="0" step="0.001" style="width:90px"></td>
      <td><input type="number" class="form-control form-control-sm pc-wasted" value="0" min="0" step="0.001" style="width:90px"></td>
      <td><input type="text" class="form-control form-control-sm pc-batch" value="${wh_esc(inp.batch_number||'')}" placeholder="Batch no." style="width:110px"></td>
    </tr>`).join('');
  document.getElementById('prod-complete-body').innerHTML = `
    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label">Qty Produced *</label><input type="number" id="pc-qty-produced" class="form-control" value="${d.order.qty_planned}" min="0" step="0.001"></div>
      <div class="col-md-4"><label class="form-label">Qty Rejected (QC fail)</label><input type="number" id="pc-qty-rejected" class="form-control" value="0" min="0" step="0.001"></div>
    </div>
    <div class="small fw-bold mb-2" style="color:var(--muted)">RAW MATERIAL CONSUMPTION</div>
    <div class="table-responsive"><table class="table wh-table table-sm">
      <thead><tr><th>Input Material</th><th>Planned</th><th>Actual Consumed</th><th>Wasted</th><th>Source Batch</th></tr></thead>
      <tbody>${rows}</tbody>
    </table></div>`;
}

async function submitProductionComplete() {
  const inputs = [];
  document.querySelectorAll('.pc-actual').forEach(inp => {
    const tr = inp.closest('tr');
    inputs.push({
      input_id:   inp.dataset.inputId,
      product_id: inp.dataset.productId,
      qty_actual: parseFloat(inp.value) || 0,
      qty_wasted: parseFloat(tr.querySelector('.pc-wasted')?.value) || 0,
      batch_id:   null,
      location_id:null,
    });
  });
  const res = await whApi('production_complete', {
    prod_id:      _completeProdId,
    qty_produced: document.getElementById('pc-qty-produced').value,
    qty_rejected: document.getElementById('pc-qty-rejected').value,
    inputs: JSON.stringify(inputs),
  }, 'POST');
  if (res.ok) { whToast('Production complete! Batch: ' + res.output_batch, 'success'); whCloseModal('modal-prod-complete'); loadProduction(); }
  else whToast(res.msg || 'Failed', 'error');
}

// ════════════════════════════════════════════════════════════
//  LOCATIONS
// ════════════════════════════════════════════════════════════
async function renderLocations(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 style="font-family:var(--head);margin:0">Locations & Bins</h5>
      <div class="d-flex gap-2">
        <input type="text" id="loc-search" class="form-control form-control-sm" placeholder="Search label…" style="width:180px" oninput="loadLocations()">
        <button class="btn btn-teal btn-sm fw-bold" onclick="openLocModal()"><i class="fa fa-plus me-1"></i>Add Location</button>
      </div>
    </div>
    <div class="wh-card"><div class="table-responsive" id="loc-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`;
  loadLocations();
}

async function loadLocations() {
  const q  = document.getElementById('loc-search')?.value || '';
  const d  = await whApi('locations_list', { q });
  const el = document.getElementById('loc-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.locations.map(l => {
    const pct = l.capacity ? Math.min(100, Math.round((l.current_qty / l.capacity) * 100)) : null;
    return `<tr>
      <td><span class="mono fw-bold" style="color:var(--accent)">${wh_esc(l.label)}</span></td>
      <td class="small">${wh_esc(l.zone_name)}</td>
      <td class="small" style="color:var(--muted)">${wh_esc(l.location_type)}</td>
      <td class="small">${wh_esc(l.aisle||'—')} / ${wh_esc(l.rack||'—')} / ${wh_esc(l.shelf||'—')} / ${wh_esc(l.bin||'—')}</td>
      <td class="small fw-bold" style="color:${pct !== null && pct > 80 ? 'var(--danger)' : 'var(--success)'}">${wh_fmtN(l.current_qty)}</td>
      <td>
        ${pct !== null ? `<div style="display:flex;align-items:center;gap:6px">
          <div class="zone-bar" style="width:70px"><div class="zone-bar-fill" style="width:${pct}%;background:${pct>80?'var(--danger)':pct>50?'var(--warn)':'var(--accent)'}"></div></div>
          <span style="font-size:.68rem;color:var(--muted)">${pct}%</span>
        </div>` : '<span style="color:var(--muted);font-size:.72rem">—</span>'}
      </td>
      <td>${stBadge(l.is_active ? 'available' : 'cancelled')}</td>
      <td><button class="btn btn-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--muted);font-size:.72rem" onclick='openLocEdit(${JSON.stringify(l)})'><i class="fa fa-pen"></i></button></td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Label</th><th>Zone</th><th>Type</th><th>Address</th><th>Current Qty</th><th>Capacity</th><th>Status</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="8" class="text-center py-4" style="color:var(--muted)">No locations found</td></tr>'}</tbody>
  </table>`;
}

async function openLocModal() {
  document.getElementById('loc-id').value = '';
  document.getElementById('loc-modal-title').textContent = 'New Location';
  ['loc-aisle','loc-rack','loc-shelf','loc-bin','loc-label','loc-barcode'].forEach(i => document.getElementById(i).value = '');
  document.getElementById('loc-capacity').value = '';
  document.getElementById('loc-type').value   = 'bin';
  document.getElementById('loc-active').value = '1';
  const zones = await whApi('zones_list');
  document.getElementById('loc-zone').innerHTML = (zones.zones||[]).map(z => `<option value="${z.id}">${wh_esc(z.name)} (${wh_esc(z.code)})</option>`).join('');
  // Auto-build label from inputs
  ['loc-aisle','loc-rack','loc-shelf','loc-bin'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => {
      const z  = document.getElementById('loc-zone').selectedOptions[0]?.text?.split(' ')[0] || '';
      const a  = document.getElementById('loc-aisle').value.toUpperCase();
      const r  = document.getElementById('loc-rack').value;
      const s  = document.getElementById('loc-shelf').value;
      const b  = document.getElementById('loc-bin').value;
      const parts = [z, a, r, s, b].filter(Boolean);
      document.getElementById('loc-label').value = parts.join('-');
    });
  });
  whOpenModal('modal-location');
}

function openLocEdit(l) {
  document.getElementById('loc-id').value       = l.id;
  document.getElementById('loc-modal-title').textContent = 'Edit Location';
  document.getElementById('loc-aisle').value    = l.aisle || '';
  document.getElementById('loc-rack').value     = l.rack || '';
  document.getElementById('loc-shelf').value    = l.shelf || '';
  document.getElementById('loc-bin').value      = l.bin || '';
  document.getElementById('loc-label').value    = l.label;
  document.getElementById('loc-barcode').value  = l.barcode || '';
  document.getElementById('loc-capacity').value = l.capacity || '';
  document.getElementById('loc-type').value     = l.location_type || 'bin';
  document.getElementById('loc-active').value   = l.is_active;
  whOpenModal('modal-location');
}

async function saveLocation() {
  const res = await whApi('location_save', {
    id:            document.getElementById('loc-id').value,
    zone_id:       document.getElementById('loc-zone').value,
    aisle:         document.getElementById('loc-aisle').value,
    rack:          document.getElementById('loc-rack').value,
    shelf:         document.getElementById('loc-shelf').value,
    bin:           document.getElementById('loc-bin').value,
    label:         document.getElementById('loc-label').value,
    barcode:       document.getElementById('loc-barcode').value,
    capacity:      document.getElementById('loc-capacity').value,
    location_type: document.getElementById('loc-type').value,
    is_active:     document.getElementById('loc-active').value,
  }, 'POST');
  if (res.ok) { whToast('Location saved', 'success'); whCloseModal('modal-location'); loadLocations(); }
  else whToast(res.msg || 'Failed', 'error');
}

// ════════════════════════════════════════════════════════════
//  STOCK COUNT
// ════════════════════════════════════════════════════════════
async function renderStockCount(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 style="font-family:var(--head);margin:0">Stock Count</h5>
      <button class="btn btn-teal btn-sm fw-bold" onclick="openStockCountModal()"><i class="fa fa-plus me-1"></i>New Count</button>
    </div>
    <div class="wh-card"><div class="table-responsive" id="cnt-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>
    <div class="mt-4 d-none" id="cnt-items-section">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 style="font-family:var(--head)">Count Items — <span id="cnt-number-label" style="color:var(--accent)"></span></h6>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-teal fw-bold" onclick="submitCount()">Submit Count</button>
          <button class="btn btn-sm btn-sky fw-bold" onclick="approveCount()">Approve & Adjust</button>
        </div>
      </div>
      <div class="wh-card"><div class="table-responsive" id="cnt-items-table"></div></div>
    </div>`;
  loadCounts();
}

async function loadCounts() {
  const d  = await whApi('count_list');
  const el = document.getElementById('cnt-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.counts.map(c => `
    <tr>
      <td><span class="mono" style="color:var(--accent)">${wh_esc(c.count_number)}</span></td>
      <td class="small">${wh_esc(c.count_type)}</td>
      <td class="small">${wh_fmtDate(c.count_date)}</td>
      <td>${stBadge(c.status)}</td>
      <td class="small">${wh_esc(c.created_by_name||'—')}</td>
      <td>
        ${c.status === 'in_progress' ? `<button class="btn btn-sm btn-teal" style="font-size:.72rem" onclick="loadCountItems(${c.id},'${wh_esc(c.count_number)}')"><i class="fa fa-clipboard-list me-1"></i>Count</button>` : ''}
        ${c.status === 'completed'   ? `<button class="btn btn-sm btn-sky"  style="font-size:.72rem" onclick="loadCountItems(${c.id},'${wh_esc(c.count_number)}')"><i class="fa fa-check me-1"></i>Review & Approve</button>` : ''}
      </td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Count No.</th><th>Type</th><th>Date</th><th>Status</th><th>By</th><th>Action</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="6" class="text-center py-4" style="color:var(--muted)">No stock counts</td></tr>'}</tbody>
  </table>`;
}

async function openStockCountModal() {
  const type = prompt('Count type (full / zone / cycle):', 'full')?.toLowerCase() || 'full';
  const date  = prompt('Count date (YYYY-MM-DD):', wh_today()) || wh_today();
  const res   = await whApi('count_create', { count_type: type, count_date: date }, 'POST');
  if (res.ok) { whToast('Stock count created: ' + res.count_number, 'success'); loadCounts(); }
  else whToast(res.msg || 'Failed', 'error');
}

let _currentCountId = null;
async function loadCountItems(count_id, count_no) {
  _currentCountId = count_id;
  document.getElementById('cnt-items-section').classList.remove('d-none');
  document.getElementById('cnt-number-label').textContent = count_no;
  document.getElementById('cnt-items-table').innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div>';
  const d = await whApi('count_items', { count_id });
  if (!d.ok) { document.getElementById('cnt-items-table').innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.items.map(it => `
    <tr>
      <td class="small">${wh_esc(it.zone_name)}</td>
      <td class="mono fw-bold small" style="color:var(--accent)">${wh_esc(it.location_label)}</td>
      <td class="fw-bold small">${wh_esc(it.product_name)}</td>
      <td class="small mono" style="color:var(--muted)">${wh_esc(it.batch_number||'—')}</td>
      <td class="small">${wh_fmtN(it.system_qty)}</td>
      <td><input type="number" class="form-control form-control-sm cnt-counted" data-item-id="${it.id}" data-system="${it.system_qty}" value="${it.counted_qty !== null ? it.counted_qty : ''}" min="0" step="0.001" style="width:90px" oninput="showVariance(this)"></td>
      <td class="small cnt-variance-${it.id}" style="font-weight:700">${it.counted_qty !== null ? showVarianceText(parseFloat(it.system_qty), parseFloat(it.counted_qty)) : '—'}</td>
      <td>${stBadge(it.status)}</td>
    </tr>`).join('');
  document.getElementById('cnt-items-table').innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Zone</th><th>Location</th><th>Product</th><th>Batch</th><th>System Qty</th><th>Counted Qty</th><th>Variance</th><th>Status</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="8" class="text-center py-4" style="color:var(--muted)">No items to count</td></tr>'}</tbody>
  </table>`;
}

function showVarianceText(system, counted) {
  const v = counted - system;
  if (Math.abs(v) < 0.001) return '<span style="color:var(--success)">✓ Match</span>';
  return `<span style="color:${v > 0 ? 'var(--success)' : 'var(--danger)'}">${v > 0 ? '+' : ''}${wh_fmtN(v)}</span>`;
}

function showVariance(inp) {
  const system  = parseFloat(inp.dataset.system) || 0;
  const counted = parseFloat(inp.value) || 0;
  const id      = inp.dataset.itemId;
  const el      = document.querySelector(`.cnt-variance-${id}`);
  if (el) el.innerHTML = showVarianceText(system, counted);
}

async function submitCount() {
  const inputs = document.querySelectorAll('.cnt-counted');
  const items  = [];
  inputs.forEach(inp => { if (inp.value !== '') items.push({ item_id: inp.dataset.itemId, system_qty: inp.dataset.system, counted_qty: inp.value }); });
  if (!items.length) { whToast('Enter at least one count', 'error'); return; }
  const res = await whApi('count_submit', { count_id: _currentCountId, items: JSON.stringify(items) }, 'POST');
  if (res.ok) { whToast('Count submitted', 'success'); loadCounts(); }
  else whToast(res.msg || 'Failed', 'error');
}

async function approveCount() {
  if (!confirm('Approve and apply variances to stock? This cannot be undone.')) return;
  const res = await whApi('count_approve', { count_id: _currentCountId }, 'POST');
  if (res.ok) { whToast('Count approved and stock adjusted!', 'success'); document.getElementById('cnt-items-section').classList.add('d-none'); loadCounts(); }
  else whToast(res.msg || 'Failed', 'error');
}

// ════════════════════════════════════════════════════════════
//  WH TRANSFERS
// ════════════════════════════════════════════════════════════
let _whtItems = [];
async function renderWHTransfers(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 style="font-family:var(--head);margin:0">Inter-Warehouse Transfers</h5>
      <button class="btn btn-teal btn-sm fw-bold" onclick="openWHTransferModal()"><i class="fa fa-plus me-1"></i>New Transfer</button>
    </div>
    <div class="wh-card"><div class="table-responsive" id="wht-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div></div></div>`;
  loadWHTransfers();
}

async function loadWHTransfers() {
  const d  = await whApi('wh_transfer_list');
  const el = document.getElementById('wht-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.transfers.map(t => `
    <tr>
      <td><span class="mono" style="color:var(--accent)">${wh_esc(t.transfer_number)}</span></td>
      <td class="small">${wh_esc(t.from_wh)} → ${wh_esc(t.to_wh)}</td>
      <td>${stBadge(t.status)}</td>
      <td class="small">${wh_esc(t.requested_by_name||'—')}</td>
      <td class="small">${wh_fmtDate(t.created_at)}</td>
      <td class="small" style="color:var(--muted)">${wh_esc(t.notes||'—')}</td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Transfer No.</th><th>Route</th><th>Status</th><th>Requested By</th><th>Date</th><th>Notes</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="6" class="text-center py-4" style="color:var(--muted)">No transfers</td></tr>'}</tbody>
  </table>`;
}

async function openWHTransferModal() {
  _whtItems = [];
  const [whs, prods] = await Promise.all([whApi('warehouses_list'), whApi('wh_products', { q: '' })]);
  const whOpts = (whs.warehouses||[]).map(w => `<option value="${w.id}">${wh_esc(w.name)}</option>`).join('');
  document.getElementById('wht-from').innerHTML = whOpts;
  document.getElementById('wht-to').innerHTML   = whOpts;
  document.getElementById('wht-prod').innerHTML = (prods.products||[]).map(p => `<option value="${p.id}">${wh_esc(p.name)}</option>`).join('');
  document.getElementById('wht-items-list').innerHTML = '';
  document.getElementById('wht-qty').value = '';
  document.getElementById('wht-notes').value = '';
  whOpenModal('modal-wh-transfer');
}

function whtAddItem() {
  const sel = document.getElementById('wht-prod');
  const qty = parseFloat(document.getElementById('wht-qty').value) || 0;
  if (!qty) { whToast('Enter quantity', 'error'); return; }
  _whtItems.push({ product_id: sel.value, product_name: sel.selectedOptions[0]?.text, qty });
  renderWHTItems();
  document.getElementById('wht-qty').value = '';
}

function whtAddItem() {
  const sel = document.getElementById('wht-prod');
  const qty = parseFloat(document.getElementById('wht-qty').value) || 0;
  if (!qty) { whToast('Enter quantity', 'error'); return; }
  _whtItems.push({ product_id: sel.value, product_name: sel.selectedOptions[0]?.text, qty });
  renderWHTItems();
  document.getElementById('wht-qty').value = '';
}

function renderWHTItems() {
  document.getElementById('wht-items-list').innerHTML = _whtItems.map((it, i) => `
    <div class="d-flex align-items-center justify-content-between py-2 border-bottom small" style="border-color:var(--border)!important">
      <span class="fw-bold">${wh_esc(it.product_name)}</span>
      <div class="d-flex gap-2 align-items-center">
        <span style="color:var(--accent)">${wh_fmtN(it.qty)} units</span>
        <button class="btn btn-sm text-danger border-0 p-0" onclick="_whtItems.splice(${i},1);renderWHTItems()">×</button>
      </div>
    </div>`).join('');
}

async function submitWHTransfer() {
  if (!_whtItems.length) { whToast('Add at least one item', 'error'); return; }
  const from = document.getElementById('wht-from').value;
  const to   = document.getElementById('wht-to').value;
  if (from === to) { whToast('From and To warehouse must differ', 'error'); return; }
  const res = await whApi('wh_transfer_create', {
    from_warehouse_id: from,
    to_warehouse_id:   to,
    notes:             document.getElementById('wht-notes').value,
    items:             JSON.stringify(_whtItems),
  }, 'POST');
  if (res.ok) { whToast('Transfer created: ' + res.transfer_number, 'success'); whCloseModal('modal-wh-transfer'); loadWHTransfers(); }
  else whToast(res.msg || 'Failed', 'error');
}

// ════════════════════════════════════════════════════════════
//  STOCK LEDGER
// ════════════════════════════════════════════════════════════
async function renderLedger(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 style="font-family:var(--head);margin:0">Stock Ledger</h5>
    </div>
    <div class="wh-card p-3 mb-3">
      <div class="row g-2">
        <div class="col-md-3"><label class="form-label">From</label><input type="date" id="led-from" class="form-control form-control-sm" value="${wh_today()}"></div>
        <div class="col-md-3"><label class="form-label">To</label><input type="date" id="led-to" class="form-control form-control-sm" value="${wh_today()}"></div>
        <div class="col-md-3"><label class="form-label">Movement Type</label>
          <select id="led-type" class="form-select form-select-sm">
            <option value="">All Types</option>
            <option value="grn_receive">GRN Receive</option>
            <option value="pick">Pick</option>
            <option value="dispatch">Dispatch</option>
            <option value="production_issue">Production Issue</option>
            <option value="production_output">Production Output</option>
            <option value="adjustment_add">Adjustment Add</option>
            <option value="adjustment_remove">Adjustment Remove</option>
            <option value="transfer_in">Transfer In</option>
            <option value="transfer_out">Transfer Out</option>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-teal btn-sm w-100" onclick="loadLedger()"><i class="fa fa-search me-1"></i>Search</button>
        </div>
      </div>
    </div>
    <div class="wh-card"><div class="table-responsive" id="led-table">
      <div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div>
    </div></div>`;
  loadLedger();
}

async function loadLedger() {
  const d  = await whApi('ledger_list', {
    from: document.getElementById('led-from')?.value || wh_today(),
    to:   document.getElementById('led-to')?.value   || wh_today(),
    type: document.getElementById('led-type')?.value || '',
  });
  const el = document.getElementById('led-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.movements.map(m => `
    <tr>
      <td style="font-size:.68rem;color:var(--muted);white-space:nowrap;font-family:var(--mono)">${new Date(m.created_at).toLocaleString('en-KE')}</td>
      <td class="fw-bold small">${wh_esc(m.product_name)}</td>
      <td class="small mono" style="color:var(--muted)">${wh_esc(m.sku||'—')}</td>
      <td>${mvBadge(m.movement_type)}</td>
      <td class="small fw-bold" style="color:var(--success)">${m.qty_in > 0 ? '+'+wh_fmtN(m.qty_in) : '—'}</td>
      <td class="small fw-bold" style="color:var(--danger)">${m.qty_out > 0 ? '-'+wh_fmtN(m.qty_out) : '—'}</td>
      <td class="small fw-bold">${wh_fmtN(m.qty_balance)}</td>
      <td class="small mono" style="color:var(--accent)">${wh_esc(m.location_label||'—')}</td>
      <td class="small mono" style="color:var(--muted)">${wh_esc(m.batch_number||'—')}</td>
      <td class="small">${wh_esc(m.performed_by_name||'—')}</td>
      <td class="small" style="color:var(--muted);font-size:.65rem">${wh_esc(m.notes||'—')}</td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Time</th><th>Product</th><th>SKU</th><th>Type</th><th>In</th><th>Out</th><th>Balance</th><th>Location</th><th>Batch</th><th>By</th><th>Notes</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="11" class="text-center py-4" style="color:var(--muted)">No movements for this period</td></tr>'}</tbody>
  </table>`;
}

// ════════════════════════════════════════════════════════════
//  WAREHOUSES & ZONES
// ════════════════════════════════════════════════════════════
async function renderWarehouses(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h5 style="font-family:var(--head);margin:0">Warehouses & Zones</h5>
    </div>
    <div class="wh-card"><div class="table-responsive" id="wh-list-table">
      <div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div>
    </div></div>
    <div class="mt-4 d-none" id="zones-section">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 style="font-family:var(--head)">Zones — <span id="zones-wh-label" style="color:var(--accent)"></span></h6>
      </div>
      <div class="wh-card"><div class="table-responsive" id="zones-table"></div></div>
    </div>`;
  loadWarehouses();
}

async function loadWarehouses() {
  const d  = await whApi('warehouses_list');
  const el = document.getElementById('wh-list-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.warehouses.map(w => `
    <tr>
      <td class="fw-bold small">${wh_esc(w.name)}</td>
      <td class="mono small" style="color:var(--accent)">${wh_esc(w.code)}</td>
      <td class="small">${wh_esc(w.branch_name||'—')}</td>
      <td class="small">${wh_esc(w.manager_name||'—')}</td>
      <td class="small" style="color:var(--muted)">${wh_esc(w.address||'—')}</td>
      <td>${stBadge(w.is_active ? 'available' : 'cancelled')}</td>
      <td><button class="btn btn-sm btn-sky" style="font-size:.72rem" onclick="loadZones(${w.id},'${wh_esc(w.name)}')">
        <i class="fa fa-layer-group me-1"></i>Zones
      </button></td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Name</th><th>Code</th><th>Branch</th><th>Manager</th><th>Address</th><th>Status</th><th>Zones</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="7" class="text-center py-4" style="color:var(--muted)">No warehouses</td></tr>'}</tbody>
  </table>`;
}

async function loadZones(wh_id, wh_name) {
  document.getElementById('zones-section').classList.remove('d-none');
  document.getElementById('zones-wh-label').textContent = wh_name;
  document.getElementById('zones-table').innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--accent)"></div></div>';
  const d  = await whApi('zones_list', { warehouse_id: wh_id });
  const el = document.getElementById('zones-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.zones.map(z => `
    <tr>
      <td class="fw-bold small">${wh_esc(z.name)}</td>
      <td class="mono small" style="color:var(--accent)">${wh_esc(z.code)}</td>
      <td class="small">${wh_esc(z.type.replace(/_/g,' '))}</td>
      <td class="small">${z.total_locations} locations</td>
      <td class="small" style="color:var(--warn)">${z.occupied} occupied</td>
      <td>${stBadge(z.is_active ? 'available' : 'cancelled')}</td>
      <td class="small" style="color:var(--muted)">${wh_esc(z.description||'—')}</td>
    </tr>`).join('');
  el.innerHTML = `<table class="table wh-table table-sm mb-0">
    <thead><tr><th>Name</th><th>Code</th><th>Type</th><th>Locations</th><th>Occupied</th><th>Status</th><th>Description</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="7" class="text-center py-4" style="color:var(--muted)">No zones</td></tr>'}</tbody>
  </table>`;
}

// ════════════════════════════════════════════════════════════
//  CLOCK + IDLE LOCK
// ════════════════════════════════════════════════════════════
(function clock() {
  const el = document.getElementById('wh-clock');
  if (el) el.textContent = new Date().toLocaleTimeString('en-KE');
  setTimeout(clock, 1000);
})();

let _idleTimer;
function resetIdleTimer() {
  clearTimeout(_idleTimer);
  _idleTimer = setTimeout(() => {
    document.getElementById('wh-lock').classList.add('show');
  }, 15 * 60 * 1000);
}
['mousemove','keydown','click','touchstart'].forEach(e => document.addEventListener(e, resetIdleTimer));
resetIdleTimer();

async function whUnlock() {
  const pass = document.getElementById('wh-lock-pin').value;
  const err  = document.getElementById('wh-lock-err');
  err.classList.add('d-none');
  if (!pass) { err.textContent = 'Enter your password.'; err.classList.remove('d-none'); return; }
  const res = await whApi('login', { username: WH_CU?.username || '', password: pass }, 'POST');
  if (res.ok) {
    document.getElementById('wh-lock').classList.remove('show');
    document.getElementById('wh-lock-pin').value = '';
    resetIdleTimer();
  } else {
    err.textContent = 'Incorrect password.';
    err.classList.remove('d-none');
  }
}
document.getElementById('wh-lock-pin')?.addEventListener('keydown', e => { if (e.key === 'Enter') whUnlock(); });

// ════════════════════════════════════════════════════════════
//  INIT
// ════════════════════════════════════════════════════════════
document.querySelectorAll('.wh-nav-link[data-page]').forEach(btn => {
  btn.addEventListener('click', () => whNav(btn.dataset.page));
});

if (<?= $logged_in ? 'true' : 'false' ?>) {
  const whLabelEl = document.getElementById('wh-wh-label');
  const selEl     = document.getElementById('wh-switcher');
  if (whLabelEl && selEl && selEl.selectedOptions[0]?.text) {
    whLabelEl.textContent = selEl.selectedOptions[0].text;
  }
  whNav('dashboard');
}
</script>
</body>
</html>
</php>