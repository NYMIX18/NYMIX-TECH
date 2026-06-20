<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
// ============================================================
//  NYMIX TECH — Super Admin Dashboard (FULL CORRECTED v2)
//  File: dashboard.php
//  Requires: ../includes/db.php ($conn as mysqli)
// ============================================================
// ── Secure session configuration ─────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0);       // set to 1 once you have HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.use_strict_mode', 1);

// Safe session start — destroys corrupted sessions silently
if (isset($_COOKIE[session_name()])) {
    @session_start();
    if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION)) {
        session_destroy();
        session_unset();
        session_start();
    }
} else {
    session_start();
}

// Session timeout — force re-login after 1 hour idle
if (isset($_SESSION['sa_id'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
        session_destroy();
        session_unset();
        session_start();
        $_SESSION = [];
    }
    $_SESSION['last_activity'] = time();
}

require_once '../includes/db.php';


// ── HELPERS ───────────────────────────────────────────────
function esc($v)      { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function money($v)    { return 'KES ' . number_format((float)$v, 2); }
function today()      { return date('Y-m-d'); }

// ── CSRF HELPERS ──────────────────────────────────────────
function csrf_generate(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_token_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_generate() . '">';
}
function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Request verification failed. Please go back and try again.');
    }
}
function slugify($s)  {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
    $s = preg_replace('/[\s-]+/', '-', $s);
    return substr($s, 0, 80);
}
function redirect($tab = 'dashboard') {
    header('Location: dashboard.php?tab=' . $tab);
    exit;
}

// ── LOGO UPLOAD HELPER ────────────────────────────────────
function handle_logo_upload($field = 'logo') {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;

    // Check real file contents — not the spoofable browser MIME header
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($_FILES[$field]['tmp_name']);
    $allowed  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!array_key_exists($realMime, $allowed)) return null;

    // 2MB max
    if ($_FILES[$field]['size'] > 2 * 1024 * 1024) return null;

    $dir = __DIR__ . '/uploads/logos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    // Extension from real MIME — never trust uploaded filename
    $ext  = $allowed[$realMime];
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $dir . $name;

    if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
        // Block PHP execution inside uploads folder
        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -ExecCGI\nAddHandler cgi-script .php .pl .py .rb\nphp_flag engine off\n");
        }
        return 'uploads/logos/' . $name;
    }
    return null;
}



// ── LOGOUT ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: dashboard.php');
    exit;
}

// ── LOGIN ─────────────────────────────────────────────────
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sa_login'])) {
    // CSRF check temporarily disabled for debugging
    if (false) {
        $login_error = 'Invalid request. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password']   ?? ''; // never trim passwords
        $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $attempt_key = 'login_attempts_' . md5($ip);

        // Rate limit: max 5 attempts per 15 minutes per IP
        if (!isset($_SESSION[$attempt_key])) {
            $_SESSION[$attempt_key] = ['count' => 0, 'first' => time()];
        }
        $attempts = &$_SESSION[$attempt_key];
        if ((time() - $attempts['first']) > 900) {
            $attempts = ['count' => 0, 'first' => time()];
        }

        if ($attempts['count'] >= 5) {
            $wait = max(1, 15 - round((time() - $attempts['first']) / 60));
            $login_error = "Too many failed attempts. Please wait {$wait} minute(s).";
        } elseif ($email && $password) {
            $stmt = $conn->prepare(
                "SELECT id, name, role, password_hash FROM super_admins WHERE email = ? AND is_active = 1 LIMIT 1"
            );
            if ($stmt) {
$stmt->bind_param('s', $email);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($admin && password_verify($password, $admin['password_hash'])) {
                    unset($_SESSION[$attempt_key]);
                    session_regenerate_id(true);
                    $_SESSION['sa_id']         = $admin['id'];
                    $_SESSION['sa_name']       = $admin['name'];
                    $_SESSION['sa_role']       = $admin['role'];
                    $_SESSION['last_activity'] = time();
                    $lid = (int)$admin['id'];
                    $upd = $conn->prepare("UPDATE super_admins SET last_login = NOW() WHERE id = ?");
                    $upd->bind_param('i', $lid);
                    $upd->execute();
                    $upd->close();
                    header('Location: dashboard.php?tab=dashboard');
                    exit;
                } else {
                    $attempts['count']++;
                    $login_error = 'Invalid credentials.';
                }
            } else {
                error_log('Login query error: ' . $conn->error);
                $login_error = 'A system error occurred. Please try again.';
            }
        } else {
            $login_error = 'Please enter email and password.';
        }
    }
}

$is_logged_in = isset($_SESSION['sa_id']);
$sa_role      = $_SESSION['sa_role'] ?? '';

function require_role(string ...$roles): void {
    global $sa_role;
    if (!in_array($sa_role, $roles, true)) {
        http_response_code(403);
        die('Access denied: insufficient permissions.');
    }
}

// ══════════════════════════════════════════════════════════
//  LOGIN PAGE
// ══════════════════════════════════════════════════════════
if (!$is_logged_in):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NYMIX TECH — Admin Login</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#05080E;--ink2:#0A0F1A;--ink3:#101828;--ink4:#182036;
  --surface:#1E2840;--line:rgba(255,255,255,0.06);--line2:rgba(255,255,255,0.11);
  --tx:#E4EDF8;--tx2:#7B92B5;--tx3:#3E546F;
  --gold:#EAAD19;--blue:#4080F0;--blue2:#2563EB;--cyan:#0DBCDA;
  --green:#0EC87A;--red:#F04060;
}
html,body{height:100%;min-height:100vh;background:var(--ink);color:var(--tx);
  font-family:'DM Sans',sans-serif;font-size:14px;
  display:flex;align-items:center;justify-content:center;overflow:hidden;}
body::before{content:'';position:fixed;inset:0;pointer-events:none;
  background-image:linear-gradient(rgba(64,128,240,0.03) 1px,transparent 1px),
    linear-gradient(90deg,rgba(64,128,240,0.03) 1px,transparent 1px);
  background-size:52px 52px;animation:gs 24s linear infinite;}
@keyframes gs{0%{background-position:0 0}100%{background-position:52px 52px}}
.orb{position:fixed;border-radius:50%;filter:blur(90px);pointer-events:none;animation:of 9s ease-in-out infinite;}
.o1{width:420px;height:420px;background:rgba(64,128,240,0.07);top:-120px;left:-120px;animation-delay:0s;}
.o2{width:320px;height:320px;background:rgba(234,173,25,0.05);bottom:-120px;right:-80px;animation-delay:4s;}
@keyframes of{0%,100%{transform:translateY(0) scale(1);}50%{transform:translateY(-28px) scale(1.04);}}
.wrap{position:relative;z-index:1;width:100%;max-width:420px;padding:0 20px;}
.eyebrow{display:flex;align-items:center;gap:10px;margin-bottom:28px;}
.ey-line{height:1px;flex:1;background:linear-gradient(to right,var(--gold),transparent);}
.ey-line.r{background:linear-gradient(to left,var(--blue),transparent);}
.ey-txt{font-family:'DM Mono',monospace;font-size:9px;letter-spacing:3px;color:var(--gold);text-transform:uppercase;white-space:nowrap;}
.card{background:var(--ink2);border:1px solid var(--line2);border-radius:22px;padding:44px 40px;position:relative;overflow:hidden;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,var(--gold),var(--blue),var(--cyan));border-radius:22px 22px 0 0;}
.logo-row{display:flex;align-items:center;gap:14px;margin-bottom:36px;}
.logo-mark{width:46px;height:46px;border-radius:14px;background:var(--ink3);border:1px solid var(--line2);
  display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;flex-shrink:0;}
.logo-mark::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(234,173,25,0.12),rgba(64,128,240,0.12));}
.logo-mark span{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;letter-spacing:1px;
  background:linear-gradient(135deg,var(--gold),var(--blue));-webkit-background-clip:text;-webkit-text-fill-color:transparent;position:relative;z-index:1;}
.logo-name{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;letter-spacing:2px;color:var(--tx);line-height:1;}
.logo-sub{font-family:'DM Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--tx3);margin-top:2px;}
h1{font-family:'Syne',sans-serif;font-size:26px;font-weight:700;margin-bottom:4px;}
.sub{font-size:13px;color:var(--tx3);margin-bottom:28px;}
.err{background:rgba(240,64,96,0.08);border:1px solid rgba(240,64,96,0.2);border-left:3px solid var(--red);
  color:#FF8098;font-size:13px;padding:11px 15px;border-radius:10px;margin-bottom:20px;}
.fg{margin-bottom:18px;}
.fg label{display:block;font-family:'DM Mono',monospace;font-size:9px;font-weight:500;
  color:var(--tx3);letter-spacing:2px;text-transform:uppercase;margin-bottom:7px;}
.iw{position:relative;}
.ii{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--tx3);pointer-events:none;width:15px;height:15px;}
input[type=email],input[type=password]{width:100%;background:var(--ink3);border:1px solid var(--line2);
  color:var(--tx);font-family:'DM Sans',sans-serif;font-size:14px;padding:12px 14px 12px 40px;
  border-radius:11px;outline:none;transition:all .2s;}
input:focus{border-color:var(--blue);background:var(--ink4);box-shadow:0 0 0 3px rgba(64,128,240,0.1);}
input::placeholder{color:var(--tx3);}
.btn-login{width:100%;padding:13px;background:var(--blue2);border:none;color:#fff;
  font-family:'Syne',sans-serif;font-size:15px;font-weight:700;letter-spacing:.5px;
  border-radius:11px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:9px;}
.btn-login:hover{background:#1D4ED8;transform:translateY(-1px);box-shadow:0 8px 28px rgba(64,128,240,0.32);}
.foot{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:26px;
  font-family:'DM Mono',monospace;font-size:9px;color:var(--tx3);letter-spacing:1px;}
.fdot{width:5px;height:5px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green);}
svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
</style>
</head>
<body>
<div class="orb o1"></div><div class="orb o2"></div>
<div class="wrap">
  <div class="eyebrow">
    <div class="ey-line"></div>
    <div class="ey-txt">Secure Admin Access</div>
    <div class="ey-line r"></div>
  </div>
  <div class="card">
    <div class="logo-row">
      <div class="logo-mark"><span>NT</span></div>
      <div>
        <div class="logo-name">NYMIX TECH</div>
        <div class="logo-sub">Super Admin Portal</div>
      </div>
    </div>
    <h1>Welcome back 👋</h1>
    <div class="sub">Sign in to access your dashboard</div>
    <?php if ($login_error): ?>
    <div class="err">⚠ <?= esc($login_error) ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="on">
      <input type="hidden" name="sa_login" value="1">
      <?= csrf_token_field() ?>
      <div class="fg">
        <label>Email Address</label>
        <div class="iw">
          <svg class="ii" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          <input type="email" name="email" placeholder="admin@nymixtech.com" required value="<?= esc($_POST['email'] ?? '') ?>">
        </div>
      </div>
      <div class="fg">
        <label>Password</label>
        <div class="iw">
          <svg class="ii" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <input type="password" name="password" placeholder="••••••••••" required>
        </div>
      </div>
      <button type="submit" class="btn-login">
        Sign In
        <svg viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
      </button>
    </form>
    <div class="foot">
      <div class="fdot"></div>
      NYMIX TECH © <?= date('Y') ?> · System Secure
      <div class="fdot"></div>
    </div>
  </div>
</div>
</body>
</html>
<?php exit; endif; // end login page

// ══════════════════════════════════════════════════════════
//  AUTO MONTHLY SUBSCRIPTION RENEWAL
// ══════════════════════════════════════════════════════════
function run_auto_renewals($conn) {
    $res = $conn->query("
        SELECT s.*, c.id AS cid, c.business_name, c.email
        FROM subscriptions s
        JOIN clients c ON c.id = s.client_id
        WHERE s.auto_renew = 1
          AND s.status IN ('active','grace')
          AND s.end_date < CURDATE()
        ORDER BY s.id ASC
        LIMIT 20
    ");
    if (!$res) return;
    while ($sub = $res->fetch_assoc()) {
        $conn->begin_transaction();
        try {
            $old_end  = $sub['end_date'];
            switch ($sub['billing_cycle']) {
                case 'quarterly': $interval = 'INTERVAL 3 MONTH'; break;
                case 'annual':    $interval = 'INTERVAL 1 YEAR';  break;
                default:          $interval = 'INTERVAL 1 MONTH'; break;
            }
            $new_start = date('Y-m-d', strtotime($old_end . ' +1 day'));
            $new_end_r = $conn->query("SELECT DATE_ADD('$new_start', $interval) AS ne");
            $new_end   = $new_end_r ? $new_end_r->fetch_assoc()['ne'] : date('Y-m-d', strtotime($new_start . ' +1 month'));

            $exp_s = $conn->prepare("UPDATE subscriptions SET status='expired' WHERE id=?");
            $exp_s->bind_param('i', $sub['id']);
            $exp_s->execute(); $exp_s->close();

            $stmt = $conn->prepare("
                INSERT INTO subscriptions
                  (client_id, plan_id, branch_count, base_price, addon_price, total_monthly,
                   billing_cycle, start_date, end_date, status, auto_renew, created_at, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,'active',1,NOW(),NOW())
            ");
            $stmt->bind_param('iisdddsss',
                $sub['client_id'], $sub['plan_id'], $sub['branch_count'],
                $sub['base_price'], $sub['addon_price'], $sub['total_monthly'],
                $sub['billing_cycle'], $new_start, $new_end
            );
            $stmt->execute();
            $new_sub_id = $conn->insert_id;
            $stmt->close();

            $inv_no    = 'INV-' . strtoupper(date('Ym')) . '-' . str_pad($sub['client_id'], 4, '0', STR_PAD_LEFT);
            $issue     = today();
            $due       = date('Y-m-d', strtotime($issue . ' +7 days'));
            $amount    = $sub['total_monthly'];

            $chk = $conn->prepare("SELECT id FROM invoices WHERE client_id=? AND period_start=? LIMIT 1");
            $chk->bind_param('is', $sub['client_id'], $new_start);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();

            if (!$exists) {
                $istmt = $conn->prepare("
                    INSERT INTO invoices
                      (client_id, subscription_id, invoice_no, issue_date, due_date,
                       period_start, period_end, amount, tax, total, status, created_at)
                    VALUES (?,?,?,?,?,?,?,?,0.00,?,'unpaid',NOW())
                ");
                $istmt->bind_param('iisssssdd',
                    $sub['client_id'], $new_sub_id, $inv_no, $issue, $due,
                    $new_start, $new_end, $amount, $amount
                );
                $istmt->execute();
                $istmt->close();
            }

            $act_c = $conn->prepare("UPDATE clients SET status='active' WHERE id=?");
            $act_c->bind_param('i', $sub['client_id']);
            $act_c->execute(); $act_c->close();
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
        }
    }

    // Suspend clients past grace period with no auto-renew
    $conn->query("
        UPDATE clients c
        JOIN subscriptions s ON s.client_id = c.id
        JOIN (SELECT client_id, MAX(id) AS max_id FROM subscriptions GROUP BY client_id) AS latest
          ON latest.client_id = c.id AND latest.max_id = s.id
        SET c.status = 'suspended', s.status = 'expired'
        WHERE s.auto_renew = 0
          AND CURDATE() > s.grace_end_date
          AND c.status NOT IN ('suspended','cancelled')
    ");

    // Activate clients whose latest subscription is active and paid
    $conn->query("
        UPDATE clients c
        JOIN subscriptions s ON s.client_id = c.id
        JOIN invoices i ON i.subscription_id = s.id AND i.status = 'paid'
        JOIN (SELECT client_id, MAX(id) AS max_id FROM subscriptions GROUP BY client_id) AS latest
          ON latest.client_id = c.id AND latest.max_id = s.id
        SET c.status = 'active', s.status = 'active'
        WHERE CURDATE() <= s.end_date
          AND c.status = 'suspended'
    ");
}
run_auto_renewals($conn);

// ── SUSPEND: unpaid invoice past due_date = suspended ─────
$conn->query("
    UPDATE clients c
    INNER JOIN invoices i ON i.client_id = c.id
    SET c.status = 'suspended'
    WHERE i.status IN ('unpaid','overdue','draft','sent')
      AND i.due_date < CURDATE()
      AND c.status NOT IN ('suspended','cancelled')
");

// ── SYNC subscription status ───────────────────────────────
$conn->query("
    UPDATE subscriptions s
    JOIN clients c ON c.id = s.client_id
    SET s.status = 'suspended'
    WHERE c.status = 'suspended'
      AND s.status NOT IN ('expired','cancelled')
");

// ══════════════════════════════════════════════════════════
//  ALL CRUD ACTIONS
// ══════════════════════════════════════════════════════════
$action   = $_POST['_action'] ?? $_GET['action'] ?? '';
$tab      = $_GET['tab'] ?? 'dashboard';

// ── CLIENTS ──────────────────────────────────────────────
if ($action === 'client_add') {
    $name    = trim($_POST['business_name'] ?? '');
    $owner   = trim($_POST['owner_name']    ?? '');
    $phone   = trim($_POST['phone']         ?? '');
    $email   = trim($_POST['email']         ?? '');
    $address = trim($_POST['address']       ?? '');
    $status  = $_POST['status']             ?? 'active';
    $notes   = trim($_POST['notes']         ?? '');
    $logo    = handle_logo_upload('logo') ?? '';

    // Auto-generate client code — replaces KRA PIN requirement
    // Format: NYM-YYYYMM-XXXXX (e.g. NYM-202506-A3F7K)
    $kra = trim($_POST['kra_pin'] ?? '');
    if (empty($kra)) {
        $kra = 'NYM-' . date('Ym') . '-' . strtoupper(substr(uniqid(), -5));
    }

    if (!$name) {
        $_SESSION['client_error'] = 'Business name is required.';
        redirect('clients');
    }

    // Check duplicate phone
    if ($phone) {
        $dp = $conn->prepare("SELECT id, business_name FROM clients WHERE phone=? LIMIT 1");
        $dp->bind_param('s', $phone);
        $dp->execute();
        $dp_row = $dp->get_result()->fetch_assoc();
        $dp->close();
        if ($dp_row) {
            $_SESSION['client_error'] = "Phone {$phone} is already registered to \"{$dp_row['business_name']}\".";
            redirect('clients');
        }
    }

    // Check duplicate email
    if ($email) {
        $de = $conn->prepare("SELECT id, business_name FROM clients WHERE email=? LIMIT 1");
        $de->bind_param('s', $email);
        $de->execute();
        $de_row = $de->get_result()->fetch_assoc();
        $de->close();
        if ($de_row) {
            $_SESSION['client_error'] = "Email {$email} is already registered to \"{$de_row['business_name']}\".";
            redirect('clients');
        }
    }

    // Build unique slug
    $base_slug = slugify($name);
    $slug = $base_slug; $i = 1;
    while (true) {
        $chk = $conn->prepare("SELECT id FROM clients WHERE slug=? LIMIT 1");
        $chk->bind_param('s', $slug);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
        if (!$exists) break;
        $slug = $base_slug . '-' . $i++;
    }

    $name_e    = $conn->real_escape_string($name);
    $owner_e   = $conn->real_escape_string($owner);
    $phone_e   = $conn->real_escape_string($phone);
    $email_e   = $conn->real_escape_string($email);
    $address_e = $conn->real_escape_string($address);
    $kra_e     = $conn->real_escape_string($kra);
    $logo_e    = $conn->real_escape_string($logo);
    $slug_e    = $conn->real_escape_string($slug);
    $status_e  = $conn->real_escape_string($status);
    $notes_e   = $conn->real_escape_string($notes);

    $result = $conn->query("
        INSERT INTO clients
          (business_name, owner_name, phone, email, address, kra_pin, logo, slug, status, notes, created_at, updated_at)
        VALUES ('$name_e','$owner_e','$phone_e','$email_e','$address_e','$kra_e','$logo_e','$slug_e','$status_e','$notes_e',NOW(),NOW())
    ");

    if ($result) {
        $_SESSION['client_success'] = "Client \"{$name}\" added. Client Code: {$kra}";
    } else {
        $_SESSION['client_error'] = 'Failed to add client: ' . $conn->error;
    }
    redirect('clients');
}

if ($action === 'client_edit') {
    $id      = (int)($_POST['id']            ?? 0);
    $name    = trim($_POST['business_name']  ?? '');
    $owner   = trim($_POST['owner_name']     ?? '');
    $phone   = trim($_POST['phone']          ?? '');
    $email   = trim($_POST['email']          ?? '');
    $address = trim($_POST['address']        ?? '');
    $kra     = trim($_POST['kra_pin']        ?? '');
    $status  = $_POST['status']              ?? 'active';
    $notes   = trim($_POST['notes']          ?? '');
    $logo    = handle_logo_upload('logo');

    if ($id && $name) {
        $name_e    = $conn->real_escape_string($name);
        $owner_e   = $conn->real_escape_string($owner);
        $phone_e   = $conn->real_escape_string($phone);
        $email_e   = $conn->real_escape_string($email);
        $address_e = $conn->real_escape_string($address);
        $kra_e     = $conn->real_escape_string($kra);
        $status_e  = $conn->real_escape_string($status);
        $notes_e   = $conn->real_escape_string($notes);

        if ($logo) {
            $logo_e = $conn->real_escape_string($logo);
            $conn->query("UPDATE clients SET business_name='$name_e', owner_name='$owner_e', phone='$phone_e', email='$email_e', address='$address_e', kra_pin='$kra_e', logo='$logo_e', status='$status_e', notes='$notes_e', updated_at=NOW() WHERE id=$id");
        } else {
            $conn->query("UPDATE clients SET business_name='$name_e', owner_name='$owner_e', phone='$phone_e', email='$email_e', address='$address_e', kra_pin='$kra_e', status='$status_e', notes='$notes_e', updated_at=NOW() WHERE id=$id");
        }
    }
    redirect('clients');
}

if ($action === 'client_delete') {
    require_role('superadmin');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { redirect('clients'); }

    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    $conn->query("DELETE FROM ticket_replies WHERE ticket_id IN (SELECT id FROM support_tickets WHERE client_id=$id)");
    $conn->query("DELETE FROM support_tickets WHERE client_id=$id");
    $conn->query("DELETE FROM subscription_payments WHERE client_id=$id");
    $conn->query("DELETE FROM invoices WHERE client_id=$id");
    $conn->query("DELETE FROM subscriptions WHERE client_id=$id");
    $conn->query("DELETE FROM client_portal_users WHERE client_id=$id");
    $conn->query("DELETE FROM users WHERE branch_id IN (SELECT id FROM branches WHERE client_id=$id)");
    $conn->query("DELETE FROM branches WHERE client_id=$id");
    $conn->query("DELETE FROM clients WHERE id=$id");
    $conn->query("SET FOREIGN_KEY_CHECKS=1");

    $_SESSION['message'] = 'Client deleted successfully.';
    redirect('clients');
}

// ── SUBSCRIPTIONS ─────────────────────────────────────────
if ($action === 'sub_add') {
    $cid    = (int)($_POST['client_id']    ?? 0);
    $pid    = (int)($_POST['plan_id']      ?? 0);
    $bcount = (int)($_POST['branch_count'] ?? 1);
    $cycle  = $_POST['billing_cycle']      ?? 'monthly';
    $start  = $_POST['start_date']         ?? today();
    $end    = $_POST['end_date']           ?? '';
    $auto   = isset($_POST['auto_renew']) ? 1 : 0;
    $status = $_POST['status']             ?? 'active';

    if ($cid && $pid && $end) {
        // ── HARD BLOCK: check for existing active/grace subscription ──
        $chk_sub = $conn->prepare("
            SELECT id, end_date, status FROM subscriptions
            WHERE client_id = ? AND status IN ('active','grace')
            ORDER BY id DESC LIMIT 1
        ");
        $chk_sub->bind_param('i', $cid);
        $chk_sub->execute();
        $existing_sub = $chk_sub->get_result()->fetch_assoc();
        $chk_sub->close();

        if ($existing_sub) {
            // Get client name for error message
            $cn_s = $conn->prepare("SELECT business_name FROM clients WHERE id=?");
            $cn_s->bind_param('i', $cid);
            $cn_s->execute();
            $cn_r = $cn_s->get_result();
            $cn_row = $cn_r ? ($cn_r->fetch_assoc() ?? ['business_name'=>'This client']) : ['business_name'=>'This client'];
            $cn_s->close();
            $_SESSION['sub_add_error'] = '⛔ Cannot add subscription: ' . $cn_row['business_name'] . ' already has an active subscription (ID #' . $existing_sub['id'] . ') expiring on ' . $existing_sub['end_date'] . '. Wait for it to expire first.';
            redirect('subscriptions');
        }
        $pr_s = $conn->prepare("SELECT base_price, branch_addon_price FROM subscription_plans WHERE id=?");
        $pr_s->bind_param('i', $pid);
        $pr_s->execute();
        $pr = $pr_s->get_result();
        $pl = $pr ? $pr->fetch_assoc() : ['base_price'=>0,'branch_addon_price'=>0];
        $pr_s->close();

        $base_price  = (float)$pl['base_price'];
        $addon_price = ($bcount > 1) ? ($bcount - 1) * (float)$pl['branch_addon_price'] : 0.00;
        $total       = $base_price + $addon_price;

        // Adjust total for billing cycle (yearly = 12x monthly)
        $display_total = $total;
        if ($cycle === 'annual') $display_total = $total; // stored as monthly, invoiced as yearly
$stmt = $conn->prepare("
    INSERT INTO subscriptions
      (client_id, plan_id, branch_count, base_price, addon_price, total_monthly,
       billing_cycle, start_date, end_date, status, auto_renew, user_id, plan_name, amount, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
");
$pn_s = $conn->prepare("SELECT name FROM subscription_plans WHERE id=?");
$pn_s->bind_param('i', $pid);
$pn_s->execute();
$pn_r = $pn_s->get_result();
$plan_name_v = $pn_r ? ($pn_r->fetch_assoc()['name'] ?? '') : '';
$pn_s->close();
$stmt->bind_param('iiidddssssissd',
    $cid, $pid, $bcount, $base_price, $addon_price, $total,
    $cycle, $start, $end, $status, $auto, $cid, $plan_name_v, $total
);
if (!$stmt->execute()) { die("Sub insert failed: " . $stmt->error); }
$new_sub_id = $conn->insert_id;
$stmt->close();

        // ONE invoice always — full total regardless of branch count
        $issue      = today();
        $due        = date('Y-m-d', strtotime($issue . ' +7 days'));
        $multiplier = match($cycle) {
            'annual'    => 12,
            'quarterly' => 3,
            default     => 1,
        };
        $inv_no     = 'INV-' . strtoupper(date('Ym')) . '-' . strtoupper(substr(uniqid(), -5));
        $inv_amount = $total * $multiplier;
        $inv_note_e = $conn->real_escape_string($bcount > 1 ? "$bcount branches included" : '');

        $conn->query("
            INSERT INTO invoices
              (client_id, subscription_id, invoice_no, issue_date, due_date,
               period_start, period_end, amount, tax, total, status, note, created_at)
            VALUES ($cid, $new_sub_id, '$inv_no', '$issue', '$due',
                    '$start', '$end', $inv_amount, 0.00, $inv_amount, 'unpaid', '$inv_note_e', NOW())
        ");

        // Client suspended until invoice is paid — auto_confirm_payment activates them
        $conn->query("UPDATE clients SET status='suspended' WHERE id=$cid");
    }
    redirect('subscriptions');
}

if ($action === 'sub_edit') {
    $id     = (int)($_POST['id']           ?? 0);
    $end    = $_POST['end_date']           ?? '';
    $bcount = (int)($_POST['branch_count'] ?? 1);
    $stat   = $_POST['status']             ?? 'active';
    $auto   = isset($_POST['auto_renew']) ? 1 : 0;

    if ($id) {
        $sub_rs = $conn->prepare("SELECT s.plan_id, sp.base_price, sp.branch_addon_price
            FROM subscriptions s JOIN subscription_plans sp ON sp.id=s.plan_id WHERE s.id=?");
        $sub_rs->bind_param('i', $id);
        $sub_rs->execute();
        $sub_r = $sub_rs->get_result();
        $sub_rs->close();
        if ($sub_r && ($sr = $sub_r->fetch_assoc())) {
            $base_price  = (float)$sr['base_price'];
            $addon_price = ($bcount > 1) ? ($bcount - 1) * (float)$sr['branch_addon_price'] : 0.00;
            $total       = $base_price + $addon_price;
        } else {
            $base_price = $addon_price = $total = 0;
        }
        $stmt = $conn->prepare("UPDATE subscriptions SET end_date=?, branch_count=?, base_price=?, addon_price=?, total_monthly=?, status=?, auto_renew=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param('sidddsii', $end, $bcount, $base_price, $addon_price, $total, $stat, $auto, $id);
        $stmt->execute(); $stmt->close();
    }
    redirect('subscriptions');
}

if ($action === 'sub_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) { $s=$conn->prepare("DELETE FROM subscriptions WHERE id=?"); $s->bind_param('i',$id); $s->execute(); $s->close(); }
    redirect('subscriptions');
}

// ── PLANS ─────────────────────────────────────────────────
if ($action === 'plan_add') {
    $name      = trim($_POST['name']               ?? '');
    $base_price= (float)($_POST['base_price']      ?? 0);
    $addon     = (float)($_POST['branch_addon_price'] ?? 0);
    $max_users = ($_POST['max_users'] ?? '') !== '' ? (int)$_POST['max_users'] : null;
    $max_prod  = ($_POST['max_products'] ?? '') !== '' ? (int)$_POST['max_products'] : null;
    $features  = trim($_POST['features'] ?? '[]');
    if ($name && $base_price > 0) {
        $stmt = $conn->prepare("
            INSERT INTO subscription_plans
              (name, base_price, branch_addon_price, max_users, max_products, features, is_active, created_at)
            VALUES (?,?,?,?,?,?,1,NOW())
        ");
        $mu_sql       = ($max_users !== null) ? (int)$max_users : 'NULL';
        $mp_sql       = ($max_prod  !== null) ? (int)$max_prod  : 'NULL';
        $name_esc     = $conn->real_escape_string($name);
        $features_esc = $conn->real_escape_string($features);

        $conn->query("
            INSERT INTO subscription_plans
              (name, base_price, branch_addon_price, max_users, max_products, features, is_active, created_at)
            VALUES ('$name_esc', $base_price, $addon, $mu_sql, $mp_sql, '$features_esc', 1, NOW())
        ");
    }
    redirect('plans');
}

if ($action === 'plan_edit') {
    $id        = (int)($_POST['id']               ?? 0);
    $name      = trim($_POST['name']              ?? '');
    $base_price= (float)($_POST['base_price']     ?? 0);
    $addon     = (float)($_POST['branch_addon_price'] ?? 0);
    $max_users = ($_POST['max_users'] ?? '') !== '' ? (int)$_POST['max_users'] : null;
    $max_prod  = ($_POST['max_products'] ?? '') !== '' ? (int)$_POST['max_products'] : null;
    $features  = trim($_POST['features'] ?? '[]');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    if ($id && $name) {
        $stmt = $conn->prepare("
            UPDATE subscription_plans SET
              name=?, base_price=?, branch_addon_price=?,
              max_users=?, max_products=?, features=?, is_active=?
            WHERE id=?
        ");
        $mu_sql       = ($max_users !== null) ? (int)$max_users : 'NULL';
        $mp_sql       = ($max_prod  !== null) ? (int)$max_prod  : 'NULL';
        $name_esc     = $conn->real_escape_string($name);
        $features_esc = $conn->real_escape_string($features);

        $conn->query("
            UPDATE subscription_plans SET
              name        = '$name_esc',
              base_price  = $base_price,
              branch_addon_price = $addon,
              max_users   = $mu_sql,
              max_products= $mp_sql,
              features    = '$features_esc',
              is_active   = $is_active
            WHERE id = $id
        ");
    }
    redirect('plans');
}

if ($action === 'plan_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) { $s=$conn->prepare("DELETE FROM subscription_plans WHERE id=?"); $s->bind_param('i',$id); $s->execute(); $s->close(); }
    redirect('plans');
}

// ── INVOICES ──────────────────────────────────────────────
if ($action === 'invoice_add') {
    $cid     = (int)($_POST['client_id']       ?? 0);
    $sub_id  = (int)($_POST['subscription_id'] ?? 0) ?: null;
    $amount  = (float)($_POST['amount']        ?? 0);
    $tax     = (float)($_POST['tax']           ?? 0);
    $total   = $amount + $tax;
    $issue   = $_POST['issue_date']            ?? today();
    $due     = $_POST['due_date']              ?? '';
    $p_start = $_POST['period_start']          ?? today();
    $p_end   = $_POST['period_end']            ?? '';
    $status  = $_POST['status']               ?? 'unpaid';
    $note    = trim($_POST['note']             ?? '');
    $inv_no  = 'INV-' . strtoupper(date('Ym')) . '-' . strtoupper(substr(uniqid(), -5));

    if ($cid && $amount > 0 && $due) {
        $stmt = $conn->prepare("
            INSERT INTO invoices
              (client_id, subscription_id, invoice_no, issue_date, due_date, period_start, period_end,
               amount, tax, total, status, note, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        $stmt->bind_param('iisssssdddss',
            $cid, $sub_id, $inv_no, $issue, $due, $p_start, $p_end,
            $amount, $tax, $total, $status, $note
        );
        $stmt->execute(); $stmt->close();
    }
    redirect('invoices');
}

if ($action === 'invoice_edit') {
    $id     = (int)($_POST['id']       ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $tax    = (float)($_POST['tax']    ?? 0);
    $total  = $amount + $tax;
    $due    = $_POST['due_date']       ?? '';
    $status = $_POST['status']         ?? 'unpaid';
    $note   = trim($_POST['note']      ?? '');
    if ($id) {
        $stmt = $conn->prepare("UPDATE invoices SET amount=?,tax=?,total=?,due_date=?,status=?,note=? WHERE id=?");
        $stmt->bind_param('dddsssi', $amount, $tax, $total, $due, $status, $note, $id);
        $stmt->execute(); $stmt->close();
    }
    redirect('invoices');
}

if ($action === 'invoice_mark_paid') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        // Mark invoice paid
        $s = $conn->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?");
        $s->bind_param('i', $id); $s->execute(); $s->close();

        // Check if all invoices for this subscription are now paid, then activate client
        $chk = $conn->prepare("
            SELECT sp.client_id, sp.id AS sub_id, sp.status AS sub_status, sp.billing_cycle
            FROM invoices i
            JOIN subscriptions sp ON sp.id = i.subscription_id
            WHERE i.id = ? LIMIT 1
        ");
        $chk->bind_param('i', $id);
        $chk->execute();
        $sub_row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($sub_row) {
            $unpaid_chk = $conn->prepare("
                SELECT COUNT(*) AS cnt FROM invoices
                WHERE subscription_id = ? AND status NOT IN ('paid','void')
            ");
            $unpaid_chk->bind_param('i', $sub_row['sub_id']);
            $unpaid_chk->execute();
            $unpaid_cnt = (int)$unpaid_chk->get_result()->fetch_assoc()['cnt'];
            $unpaid_chk->close();

            if ($unpaid_cnt === 0) {
                $conn->prepare("UPDATE clients SET status='active' WHERE id=?")
                    ->bind_param('i', $sub_row['client_id']);
                $conn->prepare("UPDATE subscriptions SET status='active' WHERE id=?")
                    ->bind_param('i', $sub_row['sub_id']);
                // Use execute separately
                $ac = $conn->prepare("UPDATE clients SET status='active' WHERE id=?");
                $ac->bind_param('i', $sub_row['client_id']); $ac->execute(); $ac->close();
                $as = $conn->prepare("UPDATE subscriptions SET status='active' WHERE id=?");
                $as->bind_param('i', $sub_row['sub_id']); $as->execute(); $as->close();
            }
        }
    }
    redirect('invoices');
}

if ($action === 'invoice_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $s = $conn->prepare("DELETE FROM invoices WHERE id=?");
        $s->bind_param('i', $id); $s->execute(); $s->close();
    }
    redirect('invoices');
}

// ── PESAPAL IPN AUTO-CONFIRM ──────────────────────────────
// Add pesapal_ipn.php as a separate file and call it from your webserver
// This block handles when PesaPal pushes a payment notification
if ($action === 'pesapal_ipn') {
    $order_ref    = $_GET['OrderMerchantReference'] ?? '';
    $order_track  = $_GET['OrderTrackingId']        ?? '';
    $status_code  = $_GET['OrderNotificationType']  ?? '';

    if ($order_ref && $order_track) {
        // Find the pending payment by reference
        $pay_r = $conn->prepare("SELECT * FROM subscription_payments WHERE reference=? LIMIT 1");
        $pay_r->bind_param('s', $order_ref);
        $pay_r->execute();
        $pay = $pay_r->get_result()->fetch_assoc();
        $pay_r->close();

        if ($pay && !$pay['confirmed']) {
            $pid   = (int)$pay['id'];
            $cid   = (int)$pay['client_id'];
            $inv_id = (int)($pay['invoice_id'] ?? 0);

            // Mark payment confirmed automatically
            $pp1 = $conn->prepare("UPDATE subscription_payments SET confirmed=1, confirmed_at=NOW(), reference=? WHERE id=?");
            $pp1->bind_param('si', $order_track, $pid);
            $pp1->execute(); $pp1->close();

            if ($inv_id) {
                $pp2 = $conn->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?");
                $pp2->bind_param('i', $inv_id);
                $pp2->execute(); $pp2->close();
            }

            // Check if all invoices for this subscription are paid
            $pp3 = $conn->prepare("
                SELECT COUNT(*) AS unpaid FROM invoices
                WHERE subscription_id = (SELECT subscription_id FROM invoices WHERE id=? LIMIT 1)
                  AND status NOT IN ('paid','void')
            ");
            $pp3->bind_param('i', $inv_id);
            $pp3->execute();
            $all_paid = (int)$pp3->get_result()->fetch_assoc()['unpaid'];
            $pp3->close();

            if ($all_paid === 0) {
                $pp4 = $conn->prepare("UPDATE clients SET status='active' WHERE id=?");
                $pp4->bind_param('i', $cid);
                $pp4->execute(); $pp4->close();

                $pp5 = $conn->prepare("SELECT * FROM subscriptions WHERE client_id=? ORDER BY id DESC LIMIT 1");
                $pp5->bind_param('i', $cid);
                $pp5->execute();
                $sub = $pp5->get_result()->fetch_assoc();
                $pp5->close();

                if ($sub) {
                    if (in_array($sub['status'], ['expired','grace','suspended'])) {
                        $ns  = date('Y-m-d');
                        $int = match($sub['billing_cycle']){'annual'=>'+1 year','quarterly'=>'+3 months',default=>'+1 month'};
                        $ne  = date('Y-m-d', strtotime($ns . ' ' . $int));
                        $pp6 = $conn->prepare("UPDATE subscriptions SET status='active', start_date=?, end_date=? WHERE id=?");
                        $pp6->bind_param('ssi', $ns, $ne, $sub['id']);
                        $pp6->execute(); $pp6->close();
                    } else {
                        $pp7 = $conn->prepare("UPDATE subscriptions SET status='active' WHERE id=?");
                        $pp7->bind_param('i', $sub['id']);
                        $pp7->execute(); $pp7->close();
                    }
                }
            }
        }
    }
    echo 'OK'; exit;
}

// ── PAYMENTS (pay against invoice only — no partial) ──────
if ($action === 'payment_add') {
    $inv_id   = (int)($_POST['invoice_id'] ?? 0);
    $recv_by  = (int)$_SESSION['sa_id'];
    $method   = $_POST['payment_method']   ?? 'mpesa';
    $ref      = trim($_POST['reference']   ?? '');
    $pay_date = $_POST['payment_date']     ?? today();
    $note     = trim($_POST['note']        ?? '');

    if ($inv_id) {
        // Fetch invoice to get client_id and total
        $inv_r = $conn->query("SELECT client_id, total FROM invoices WHERE id=$inv_id LIMIT 1");
        if ($inv_r && ($inv_row = $inv_r->fetch_assoc())) {
            $cid    = (int)$inv_row['client_id'];
            $amount = (float)$inv_row['total'];

            $stmt = $conn->prepare("
                INSERT INTO subscription_payments
                  (client_id, invoice_id, received_by, amount, payment_method, reference, payment_date, note, created_at)
                VALUES (?,?,?,?,?,?,?,?,NOW())
            ");
            $stmt->bind_param('iiidssss', $cid, $inv_id, $recv_by, $amount, $method, $ref, $pay_date, $note);
            $stmt->execute(); $stmt->close();
            // Payment recorded — admin must click Confirm button after verifying in M-PESA/bank
            // Invoice and client remain pending until manual confirmation
        }
    }
    redirect('payments');
}

if ($action === 'payment_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) { $s=$conn->prepare("DELETE FROM subscription_payments WHERE id=?"); $s->bind_param('i',$id); $s->execute(); $s->close(); }
    redirect('payments');
}

// ── AUTO-CONFIRM helper (reused for both bulk and single) ──
function auto_confirm_payment($conn, int $pid, int $inv_id, int $cid, int $admin_id): void {
    $s1 = $conn->prepare("UPDATE subscription_payments SET confirmed=1, confirmed_by=?, confirmed_at=NOW() WHERE id=? AND confirmed=0");
    $s1->bind_param('ii', $admin_id, $pid);
    $s1->execute(); $s1->close();

    if ($inv_id) {
        $s2 = $conn->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=? AND status NOT IN ('paid','void')");
        $s2->bind_param('i', $inv_id);
        $s2->execute(); $s2->close();
    }

    if ($cid) {
        $s3 = $conn->prepare("
            SELECT COUNT(*) AS unpaid FROM invoices
            WHERE subscription_id = (SELECT subscription_id FROM invoices WHERE id=? LIMIT 1)
              AND status NOT IN ('paid','void')
        ");
        $s3->bind_param('i', $inv_id);
        $s3->execute();
        $all_paid = (int)$s3->get_result()->fetch_assoc()['unpaid'];
        $s3->close();

        if ($all_paid === 0) {
            $s4 = $conn->prepare("UPDATE clients SET status='active' WHERE id=?");
            $s4->bind_param('i', $cid);
            $s4->execute(); $s4->close();

            $s5 = $conn->prepare("SELECT * FROM subscriptions WHERE client_id=? ORDER BY id DESC LIMIT 1");
            $s5->bind_param('i', $cid);
            $s5->execute();
            $sub = $s5->get_result()->fetch_assoc();
            $s5->close();

            if ($sub) {
                if (in_array($sub['status'], ['expired','grace','suspended'])) {
                    $new_start = date('Y-m-d');
                    $interval  = match($sub['billing_cycle']) {
                        'annual'    => '+1 year',
                        'quarterly' => '+3 months',
                        default     => '+1 month',
                    };
                    $new_end = date('Y-m-d', strtotime($new_start . ' ' . $interval));
                    $s6 = $conn->prepare("UPDATE subscriptions SET status='active', start_date=?, end_date=? WHERE id=?");
                    $s6->bind_param('ssi', $new_start, $new_end, $sub['id']);
                    $s6->execute(); $s6->close();
                } else {
                    $s7 = $conn->prepare("UPDATE subscriptions SET status='active' WHERE id=?");
                    $s7->bind_param('i', $sub['id']);
                    $s7->execute(); $s7->close();
                }
            }
        }
    }
}

// Auto-confirm on page load DISABLED — admin must manually confirm each payment

if ($action === 'payment_confirm') {
    $id     = (int)($_POST['id'] ?? 0);
    $inv_id = (int)($_POST['invoice_id'] ?? 0);
    $cid    = (int)($_POST['client_id']  ?? 0);
    $admin  = (int)$_SESSION['sa_id'];
    if ($id) {
        auto_confirm_payment($conn, $id, $inv_id, $cid, $admin);
    }
    redirect('payments');
}

// ── TICKETS ───────────────────────────────────────────────
if ($action === 'ticket_add') {
    $cid      = (int)($_POST['client_id']   ?? 0);
    $assigned = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $subject  = trim($_POST['subject']      ?? '');
    $priority = $_POST['priority']          ?? 'medium';
    if ($cid && $subject) {
        $stmt = $conn->prepare("INSERT INTO support_tickets (client_id, assigned_to, subject, priority, status, created_at) VALUES (?,?,?,?,'open',NOW())");
        $stmt->bind_param('iiss', $cid, $assigned, $subject, $priority);
        $stmt->execute(); $stmt->close();
    }
    redirect('tickets');
}

if ($action === 'ticket_reply') {
    $tid      = (int)($_POST['ticket_id'] ?? 0);
    $status   = $_POST['status']          ?? 'open';
    $priority = $_POST['priority']        ?? 'medium';
    $message  = trim($_POST['message']    ?? '');
    $admin_id = (int)$_SESSION['sa_id'];
    if ($tid) {
        // Whitelist status and priority — never inject raw strings
        $allowed_statuses  = ['open','in_progress','resolved','closed'];
        $allowed_priorities = ['low','medium','high','critical'];
        $status   = in_array($status,   $allowed_statuses,   true) ? $status   : 'open';
        $priority = in_array($priority, $allowed_priorities, true) ? $priority : 'medium';

        if ($status === 'resolved' || $status === 'closed') {
            $tstmt = $conn->prepare("UPDATE support_tickets SET status=?, priority=?, resolved_at=NOW() WHERE id=?");
            $tstmt->bind_param('ssi', $status, $priority, $tid);
        } else {
            $tstmt = $conn->prepare("UPDATE support_tickets SET status=?, priority=? WHERE id=?");
            $tstmt->bind_param('ssi', $status, $priority, $tid);
        }
        $tstmt->execute(); $tstmt->close();

        if ($message) {
            $stmt = $conn->prepare("INSERT INTO ticket_replies (ticket_id, sender_type, sender_id, message, created_at) VALUES (?, 'admin', ?, ?, NOW())");
            $stmt->bind_param('iis', $tid, $admin_id, $message);
            $stmt->execute(); $stmt->close();
        }
    }
    redirect('tickets');
}

if ($action === 'ticket_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) { $s=$conn->prepare("DELETE FROM support_tickets WHERE id=?"); $s->bind_param('i',$id); $s->execute(); $s->close(); }
    redirect('tickets');
}

// ── ADMIN USERS ───────────────────────────────────────────
if ($action === 'admin_add') {
    require_role('superadmin');
    $name  = trim($_POST['name']     ?? '');
    $uname = trim($_POST['username'] ?? '');
    $email = trim($_POST['email']    ?? '');
    $pass  = trim($_POST['password'] ?? '');
    $role  = $_POST['role']          ?? 'support';
    $phone = trim($_POST['phone']    ?? '');
    if ($name && $uname && $email && $pass) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO super_admins (name, username, email, phone, password_hash, role, is_active, created_at) VALUES (?,?,?,?,?,?,1,NOW())");
        $stmt->bind_param('ssssss', $name, $uname, $email, $phone, $hash, $role);
        $stmt->execute(); $stmt->close();
    }
    redirect('admins');
}

if ($action === 'admin_edit') {
    $id    = (int)($_POST['id']        ?? 0);
    $name  = trim($_POST['name']       ?? '');
    $role  = $_POST['role']            ?? 'support';
    $act   = (int)($_POST['is_active'] ?? 1);
    $phone = trim($_POST['phone']      ?? '');
    if ($id && $name) {
        $stmt = $conn->prepare("UPDATE super_admins SET name=?, role=?, is_active=?, phone=? WHERE id=?");
        $stmt->bind_param('ssisi', $name, $role, $act, $phone, $id);
        $stmt->execute(); $stmt->close();
        if (!empty($_POST['new_password'])) {
            $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            $stmt2 = $conn->prepare("UPDATE super_admins SET password_hash=? WHERE id=?");
            $stmt2->bind_param('si', $hash, $id);
            $stmt2->execute(); $stmt2->close();
        }
    }
    redirect('admins');
}

if ($action === 'admin_delete') {
    require_role('superadmin');
    $id = (int)($_POST['id'] ?? 0);
    $my = (int)$_SESSION['sa_id'];
    if ($id && $id !== $my) { $s=$conn->prepare("DELETE FROM super_admins WHERE id=?"); $s->bind_param('i',$id); $s->execute(); $s->close(); }
    redirect('admins');
}

if ($action === 'error_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) { $s=$conn->prepare("DELETE FROM error_log WHERE id=?"); $s->bind_param('i',$id); $s->execute(); $s->close(); }
    redirect('errors');
}
if ($action === 'errors_clear') {
    require_role('superadmin');
    $conn->query("DELETE FROM error_log WHERE level IN('error','critical')");
    redirect('errors');
}

// ══════════════════════════════════════════════════════════
//  DATA FETCHING
// ══════════════════════════════════════════════════════════

// Overview
$ov_defaults = ['active_clients'=>0,'readonly_clients'=>0,'suspended_clients'=>0,
    'total_branches'=>0,'total_users'=>0,'total_products'=>0,
    'sales_today'=>0,'revenue_today'=>0,'sub_revenue_this_month'=>0,
    'open_tickets'=>0,'errors_24h'=>0];
$ov_res = $conn->query("SELECT * FROM v_system_overview LIMIT 1");
$ov     = $ov_res ? array_merge($ov_defaults, $ov_res->fetch_assoc() ?? []) : $ov_defaults;

$expiring = [];
$er = $conn->query("SELECT * FROM v_expiring_soon LIMIT 10");
if ($er) while ($row = $er->fetch_assoc()) $expiring[] = $row;

$monthly_rev = [];
$mr = $conn->query("SELECT * FROM v_monthly_revenue LIMIT 12");
if ($mr) while ($row = $mr->fetch_assoc()) $monthly_rev[] = $row;

$health_latest = [];
$hr = $conn->query("SELECT * FROM system_health ORDER BY recorded_at DESC LIMIT 1");
if ($hr) $health_latest = $hr->fetch_assoc() ?: [];

// Clients — NO filter that drops null subscriptions
$clients = [];
$cr = $conn->query("
    SELECT c.*,
           sp.name AS plan_name,
           s.id AS sub_id,
           s.end_date,
           s.total_monthly,
           s.billing_cycle,
           s.status AS sub_status,
           s.auto_renew,
           DATEDIFF(s.end_date, CURDATE()) AS days_left
    FROM clients c
    LEFT JOIN subscriptions s ON s.id = (
        SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.client_id = c.id
    )
    LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
    ORDER BY c.created_at DESC
");
if ($cr) while ($row = $cr->fetch_assoc()) $clients[] = $row;

// Plans
$plans = [];
$pr = $conn->query("SELECT * FROM subscription_plans ORDER BY base_price ASC");
if ($pr) while ($row = $pr->fetch_assoc()) $plans[] = $row;

// Subscriptions
$subs = [];
$sr = $conn->query("
    SELECT s.*, c.business_name, sp.name AS plan_name
    FROM subscriptions s
    JOIN clients c ON c.id = s.client_id
    LEFT JOIN subscription_plans sp ON sp.id = s.plan_id
    ORDER BY s.created_at DESC LIMIT 200
");
if ($sr) while ($row = $sr->fetch_assoc()) $subs[] = $row;

// Invoices — only UNPAID shown in payment dropdown
$invoices = [];
$ir = $conn->query("
    SELECT i.*, c.business_name
    FROM invoices i
    JOIN clients c ON c.id = i.client_id
    ORDER BY i.created_at DESC LIMIT 200
");
if ($ir) while ($row = $ir->fetch_assoc()) $invoices[] = $row;

// Unpaid invoices (for payment modal)
$unpaid_invoices = array_filter($invoices, fn($i) => in_array($i['status'], ['unpaid','overdue','draft','sent']));

// Payments
$payments = [];
$payr = $conn->query("
    SELECT sp.*, c.business_name, sa.name AS received_by_name,
           i.invoice_no
    FROM subscription_payments sp
    JOIN clients c ON c.id = sp.client_id
    LEFT JOIN super_admins sa ON sa.id = sp.received_by
    LEFT JOIN invoices i ON i.id = sp.invoice_id
    ORDER BY sp.payment_date DESC, sp.created_at DESC LIMIT 200
");
if ($payr) while ($row = $payr->fetch_assoc()) $payments[] = $row;

// Tickets
$tickets = [];
$tr = $conn->query("
    SELECT t.*, c.business_name, sa.name AS assigned_name,
           (SELECT COUNT(*) FROM ticket_replies tr2 WHERE tr2.ticket_id = t.id) AS reply_count
    FROM support_tickets t
    JOIN clients c ON c.id = t.client_id
    LEFT JOIN super_admins sa ON sa.id = t.assigned_to
    ORDER BY FIELD(t.priority,'critical','high','medium','low'), t.created_at ASC LIMIT 200
");
if ($tr) while ($row = $tr->fetch_assoc()) $tickets[] = $row;

// Errors
$errors = [];
$errr = $conn->query("SELECT * FROM error_log ORDER BY created_at DESC LIMIT 200");
if ($errr) while ($row = $errr->fetch_assoc()) $errors[] = $row;

// Admins
$admins = [];
$ar = $conn->query("SELECT id, name, username, email, phone, role, is_active, last_login, created_at FROM super_admins ORDER BY created_at DESC");
if ($ar) while ($row = $ar->fetch_assoc()) $admins[] = $row;

// Stats
$total_clients   = count($clients);
$admin_name      = esc($_SESSION['sa_name'] ?? 'Admin');
$admin_role      = esc($_SESSION['sa_role'] ?? 'superadmin');
$greeting_h      = (int)date('H');
$greeting        = $greeting_h < 12 ? 'Good morning' : ($greeting_h < 17 ? 'Good afternoon' : 'Good evening');
$active_tab      = $tab;

// SOC summary
$soc_defaults=['logins_24h'=>0,'failures_24h'=>0,'successes_24h'=>0,'active_sessions'=>0,'open_alerts'=>0,'critical_alerts'=>0,'audit_events_24h'=>0,'suspicious_ips'=>0];
$soc_r=$conn->query("SELECT * FROM v_soc_summary LIMIT 1");
$soc_sum=$soc_r?array_merge($soc_defaults,$soc_r->fetch_assoc()??[]):$soc_defaults;
$login_log=[];$ll_r=$conn->query("SELECT * FROM login_attempts ORDER BY created_at DESC LIMIT 200");if($ll_r)while($row=$ll_r->fetch_assoc())$login_log[]=$row;
$active_sessions=[];$as_r=$conn->query("SELECT * FROM admin_sessions WHERE ended_at IS NULL AND last_active >= NOW() - INTERVAL 1 HOUR ORDER BY last_active DESC");if($as_r)while($row=$as_r->fetch_assoc())$active_sessions[]=$row;
$sec_alerts=[];$sa_r=$conn->query("SELECT a.*,sa.name AS resolved_by_name FROM security_alerts a LEFT JOIN super_admins sa ON sa.id=a.resolved_by ORDER BY a.is_resolved ASC,a.created_at DESC LIMIT 100");if($sa_r)while($row=$sa_r->fetch_assoc())$sec_alerts[]=$row;
$audit_entries=[];$ae_r=$conn->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200");if($ae_r)while($row=$ae_r->fetch_assoc())$audit_entries[]=$row;
$bf_ips=[];$bf_r=$conn->query("SELECT * FROM v_failed_logins_24h LIMIT 30");if($bf_r)while($row=$bf_r->fetch_assoc())$bf_ips[]=$row;
$geo_countries=[];$gc_r=$conn->query("SELECT country,COUNT(*) AS cnt,result FROM login_attempts WHERE created_at>=NOW()-INTERVAL 48 HOUR AND country IS NOT NULL GROUP BY country,result ORDER BY cnt DESC LIMIT 20");if($gc_r)while($row=$gc_r->fetch_assoc())$geo_countries[]=$row;

// SOC summary
$soc_defaults=['logins_24h'=>0,'failures_24h'=>0,'successes_24h'=>0,'active_sessions'=>0,'open_alerts'=>0,'critical_alerts'=>0,'audit_events_24h'=>0,'suspicious_ips'=>0];
$soc_r=$conn->query("SELECT * FROM v_soc_summary LIMIT 1");
$soc_sum=$soc_r?array_merge($soc_defaults,$soc_r->fetch_assoc()??[]):$soc_defaults;
$login_log=[];$ll_r=$conn->query("SELECT * FROM login_attempts ORDER BY created_at DESC LIMIT 200");if($ll_r)while($row=$ll_r->fetch_assoc())$login_log[]=$row;
$active_sessions=[];$as_r=$conn->query("SELECT * FROM admin_sessions WHERE ended_at IS NULL AND last_active >= NOW() - INTERVAL 1 HOUR ORDER BY last_active DESC");if($as_r)while($row=$as_r->fetch_assoc())$active_sessions[]=$row;
$sec_alerts=[];$sa_r=$conn->query("SELECT a.*,sa.name AS resolved_by_name FROM security_alerts a LEFT JOIN super_admins sa ON sa.id=a.resolved_by ORDER BY a.is_resolved ASC,a.created_at DESC LIMIT 100");if($sa_r)while($row=$sa_r->fetch_assoc())$sec_alerts[]=$row;
$audit_entries=[];$ae_r=$conn->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200");if($ae_r)while($row=$ae_r->fetch_assoc())$audit_entries[]=$row;
$bf_ips=[];$bf_r=$conn->query("SELECT * FROM v_failed_logins_24h LIMIT 30");if($bf_r)while($row=$bf_r->fetch_assoc())$bf_ips[]=$row;
$geo_countries=[];$gc_r=$conn->query("SELECT country,COUNT(*) AS cnt,result FROM login_attempts WHERE created_at>=NOW()-INTERVAL 48 HOUR AND country IS NOT NULL GROUP BY country,result ORDER BY cnt DESC LIMIT 20");if($gc_r)while($row=$gc_r->fetch_assoc())$geo_countries[]=$row;

$total_revenue_all = 0;
$rev_res = $conn->query("SELECT COALESCE(SUM(amount),0) AS t FROM subscription_payments");
if ($rev_res) $total_revenue_all = (float)$rev_res->fetch_assoc()['t'];

$total_outstanding = 0;
$out_res = $conn->query("SELECT COALESCE(SUM(total),0) AS t FROM invoices WHERE status IN ('unpaid','overdue','draft','sent')");
if ($out_res) $total_outstanding = (float)$out_res->fetch_assoc()['t'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Super Admin — NYMIX TECH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#05080E;--ink2:#090E1B;--ink3:#0F1726;--ink4:#172035;
  --ink5:#1D2840;--surface:#243050;--surface2:#2C3A5C;
  --line:rgba(255,255,255,0.05);--line2:rgba(255,255,255,0.09);
  --line3:rgba(255,255,255,0.16);--line4:rgba(255,255,255,0.24);
  --tx:#E2EBF8;--tx2:#7A93B8;--tx3:#455B78;--tx4:#283A52;
  --gold:#EAAD19;--gold-d:rgba(234,173,25,0.14);--gold-g:rgba(234,173,25,0.06);
  --blue:#4080F0;--blue2:#2563EB;--blue-d:rgba(64,128,240,0.14);--blue-g:rgba(64,128,240,0.06);
  --cyan:#0DBCDA;--cyan-d:rgba(13,188,218,0.14);
  --purple:#9B72F0;--purple-d:rgba(155,114,240,0.14);
  --green:#0EC87A;--green-d:rgba(14,200,122,0.1);
  --red:#F04060;--red-d:rgba(240,64,96,0.1);
  --amber:#F5A623;--amber-d:rgba(245,166,35,0.12);
  --sidebar-w:248px;--topbar-h:62px;--ease:.18s ease;
}
html{scroll-behavior:smooth;}
body{background:var(--ink);color:var(--tx);font-family:'DM Sans',sans-serif;font-size:14px;line-height:1.6;min-height:100vh;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(64,128,240,0.02) 1px,transparent 1px),linear-gradient(90deg,rgba(64,128,240,0.02) 1px,transparent 1px);
  background-size:64px 64px;}
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:var(--ink2);}
::-webkit-scrollbar-thumb{background:var(--ink5);border-radius:3px;}

/* ── SIDEBAR ── */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);background:var(--ink2);border-right:1px solid var(--line2);display:flex;flex-direction:column;z-index:100;overflow:hidden;}
.sidebar::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--blue),var(--cyan));}
.sb-logo{padding:18px 18px 14px;display:flex;align-items:center;gap:11px;border-bottom:1px solid var(--line);flex-shrink:0;}
.sb-mark{width:38px;height:38px;border-radius:11px;background:var(--ink3);border:1px solid var(--line2);display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;overflow:hidden;}
.sb-mark::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,var(--gold-d),var(--blue-d));}
.sb-mark span{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;letter-spacing:1px;background:linear-gradient(135deg,var(--gold),var(--blue));-webkit-background-clip:text;-webkit-text-fill-color:transparent;position:relative;z-index:1;}
.sb-name{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;letter-spacing:2px;color:var(--tx);line-height:1;}
.sb-role{font-family:'DM Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--tx3);margin-top:2px;}
.sb-nav{flex:1;overflow-y:auto;padding:14px 10px;}
.nav-lbl{font-family:'DM Mono',monospace;font-size:9px;letter-spacing:2.5px;color:var(--tx4);text-transform:uppercase;padding:8px 8px 4px;margin-top:10px;}
.nav-lbl:first-child{margin-top:0;}
.nav-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:9px;color:var(--tx2);text-decoration:none;font-size:13.5px;font-weight:500;transition:background var(--ease),color var(--ease);position:relative;cursor:pointer;border:none;background:transparent;width:100%;text-align:left;}
.nav-item:hover{background:var(--ink3);color:var(--tx);}
.nav-item.active{background:var(--blue-d);color:var(--blue);}
.nav-item.active::before{content:'';position:absolute;left:0;top:25%;bottom:25%;width:2px;border-radius:2px;background:var(--blue);}
.nav-item svg{width:16px;height:16px;flex-shrink:0;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}
.nav-badge{margin-left:auto;background:var(--red-d);color:var(--red);font-family:'DM Mono',monospace;font-size:10px;padding:1px 7px;border-radius:20px;}
.sb-footer{padding:10px;border-top:1px solid var(--line);flex-shrink:0;}
.sb-user{display:flex;align-items:center;gap:9px;padding:9px;border-radius:11px;background:var(--ink3);border:1px solid var(--line);}
.sb-avatar{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,var(--blue-d),var(--purple-d));border:1px solid var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--blue);flex-shrink:0;}
.sb-uname{font-size:13px;font-weight:600;color:var(--tx);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sb-urole{font-family:'DM Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--gold);text-transform:uppercase;}
.logout-btn{display:flex;align-items:center;justify-content:center;padding:6px;border-radius:7px;color:var(--tx3);background:transparent;border:none;cursor:pointer;transition:all var(--ease);text-decoration:none;flex-shrink:0;}
.logout-btn:hover{color:var(--red);background:var(--red-d);}
.logout-btn svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}

/* ── TOPBAR ── */
.topbar{position:fixed;top:0;left:var(--sidebar-w);right:0;height:var(--topbar-h);background:rgba(5,8,14,0.88);backdrop-filter:blur(14px);border-bottom:1px solid var(--line2);display:flex;align-items:center;padding:0 26px;gap:14px;z-index:90;}
.tb-title{flex:1;font-family:'Syne',sans-serif;font-size:18px;font-weight:800;letter-spacing:2px;color:var(--tx);}
.tb-greet{font-size:13px;color:var(--tx3);display:flex;align-items:center;gap:6px;}
.status-dot{width:6px;height:6px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green);flex-shrink:0;}
.tb-date{font-family:'DM Mono',monospace;font-size:11px;color:var(--tx3);padding:5px 12px;background:var(--ink3);border:1px solid var(--line2);border-radius:8px;}

/* ── MAIN ── */
.main{margin-left:var(--sidebar-w);padding-top:var(--topbar-h);min-height:100vh;position:relative;z-index:1;}
.main-inner{padding:26px;max-width:1440px;}

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px;margin-bottom:26px;}
.page-eyebrow{font-family:'DM Mono',monospace;font-size:9px;letter-spacing:3px;color:var(--gold);text-transform:uppercase;margin-bottom:4px;}
.page-title{font-family:'Syne',sans-serif;font-size:30px;font-weight:800;letter-spacing:1.5px;color:var(--tx);line-height:1;}
.page-sub{font-size:13px;color:var(--tx3);margin-top:3px;}
.page-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 17px;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:13.5px;font-weight:600;border:none;cursor:pointer;transition:all var(--ease);text-decoration:none;white-space:nowrap;}
.btn svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;}
.btn-primary{background:var(--blue2);color:#fff;box-shadow:0 4px 14px rgba(64,128,240,0.22);}
.btn-primary:hover{background:#1D4ED8;transform:translateY(-1px);}
.btn-gold{background:var(--gold);color:#05080E;}
.btn-gold:hover{background:#D4991A;transform:translateY(-1px);}
.btn-ghost{background:transparent;color:var(--tx2);border:1px solid var(--line2);}
.btn-ghost:hover{background:var(--ink3);color:var(--tx);border-color:var(--line3);}
.btn-danger{background:var(--red-d);color:var(--red);border:1px solid rgba(240,64,96,0.18);}
.btn-danger:hover{background:var(--red);color:#fff;}
.btn-success{background:var(--green-d);color:var(--green);border:1px solid rgba(14,200,122,0.2);}
.btn-success:hover{background:var(--green);color:#fff;}
.btn-sm{padding:6px 11px;font-size:12px;border-radius:7px;}
.btn-icon{width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;}
.btn-icon svg{width:14px;height:14px;}

/* ── STAT CARDS ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:14px;margin-bottom:22px;}
.stat-card{background:var(--ink2);border:1px solid var(--line2);border-radius:15px;padding:19px 20px;position:relative;overflow:hidden;transition:border-color var(--ease),transform var(--ease);}
.stat-card:hover{border-color:var(--line3);transform:translateY(-2px);}
.sc-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;margin-bottom:13px;}
.sc-icon svg{width:17px;height:17px;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}
.sc-label{font-family:'DM Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--tx3);text-transform:uppercase;margin-bottom:4px;}
.sc-value{font-family:'Syne',sans-serif;font-size:30px;font-weight:700;color:var(--tx);line-height:1;margin-bottom:4px;}
.sc-value.money{font-size:18px;}
.sc-change{font-size:12px;display:flex;align-items:center;gap:4px;color:var(--tx3);}
.sc-change.up{color:var(--green);}
.sc-change.down{color:var(--red);}

/* ── CARDS ── */
.card{background:var(--ink2);border:1px solid var(--line2);border-radius:15px;overflow:hidden;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:9px;}
.card-title{font-size:14px;font-weight:600;color:var(--tx);display:flex;align-items:center;gap:8px;}
.card-title svg{width:15px;height:15px;stroke:var(--blue);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
.card-body{padding:20px;}
.card-body-flush{padding:0;}

/* ── GRID ── */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;}
.grid-main-side{display:grid;grid-template-columns:1fr 320px;gap:18px;}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:13.5px;}
thead th{background:var(--ink3);font-family:'DM Mono',monospace;font-size:9px;letter-spacing:1.5px;color:var(--tx3);text-transform:uppercase;padding:11px 15px;text-align:left;font-weight:500;white-space:nowrap;position:sticky;top:0;z-index:1;}
tbody tr{border-bottom:1px solid var(--line);transition:background var(--ease);}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:rgba(255,255,255,0.02);}
tbody td{padding:11px 15px;color:var(--tx);vertical-align:middle;}
.td-muted{color:var(--tx3);}
.td-mono{font-family:'DM Mono',monospace;font-size:12px;color:var(--tx2);}
.td-name{font-weight:600;color:var(--tx);}
.td-actions{display:flex;align-items:center;gap:5px;flex-wrap:nowrap;}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;letter-spacing:.3px;white-space:nowrap;}
.badge::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;flex-shrink:0;}
.badge-active,.badge-paid,.badge-resolved,.badge-success{background:var(--green-d);color:var(--green);}
.badge-suspended,.badge-overdue,.badge-critical,.badge-error,.badge-expired{background:var(--red-d);color:var(--red);}
.badge-read_only,.badge-pending,.badge-open,.badge-draft,.badge-grace,.badge-unpaid{background:var(--amber-d);color:var(--amber);}
.badge-cancelled,.badge-info,.badge-closed,.badge-sent,.badge-void{background:var(--blue-d);color:var(--blue);}
.badge-in_progress,.badge-medium,.badge-warning{background:var(--cyan-d);color:var(--cyan);}
.badge-low{background:var(--ink4);color:var(--tx3);}
.badge-high{background:var(--amber-d);color:var(--amber);}
.badge-superadmin{background:var(--gold-d);color:var(--gold);}
.badge-support{background:var(--cyan-d);color:var(--cyan);}
.badge-billing{background:var(--purple-d);color:var(--purple);}

/* ── FORMS ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{margin-bottom:0;}
.form-group.full{grid-column:1/-1;}
label.field-label{display:block;font-family:'DM Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--tx3);text-transform:uppercase;margin-bottom:7px;font-weight:500;}
input[type=text],input[type=email],input[type=password],input[type=number],
input[type=date],input[type=tel],select,textarea,input[type=file]{
  width:100%;background:var(--ink3);border:1px solid var(--line2);color:var(--tx);
  font-family:'DM Sans',sans-serif;font-size:14px;padding:10px 13px;
  border-radius:9px;outline:none;transition:border-color var(--ease),background var(--ease),box-shadow var(--ease);appearance:none;
}
input[type=file]{padding:8px 13px;cursor:pointer;color:var(--tx2);}
input[type=file]::file-selector-button{background:var(--ink4);color:var(--tx2);border:1px solid var(--line2);border-radius:6px;padding:4px 10px;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:12px;margin-right:10px;}
input:focus,select:focus,textarea:focus{border-color:var(--blue);background:var(--ink4);box-shadow:0 0 0 3px rgba(64,128,240,0.1);}
input::placeholder,textarea::placeholder{color:var(--tx4);}
select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23455B78' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;padding-right:32px;}
textarea{resize:vertical;min-height:86px;}
.checkbox-row{display:flex;align-items:center;gap:9px;cursor:pointer;}
.checkbox-row input[type=checkbox]{width:auto;cursor:pointer;}

/* ── Logo preview ── */
.logo-preview{width:60px;height:60px;border-radius:10px;object-fit:cover;border:1px solid var(--line2);background:var(--ink3);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
.logo-preview img{width:100%;height:100%;object-fit:cover;}
.client-logo{width:34px;height:34px;border-radius:9px;object-fit:cover;border:1px solid var(--line2);}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.72);backdrop-filter:blur(5px);z-index:200;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--ink2);border:1px solid var(--line2);border-radius:19px;width:100%;max-width:580px;max-height:90vh;overflow-y:auto;position:relative;animation:min .2s ease;}
@keyframes min{from{opacity:0;transform:translateY(14px) scale(0.97);}to{opacity:1;transform:none;}}
.modal::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold),var(--blue));border-radius:19px 19px 0 0;}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--line);}
.modal-title{font-size:15px;font-weight:700;color:var(--tx);display:flex;align-items:center;gap:8px;}
.modal-title svg{width:17px;height:17px;stroke:var(--blue);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}
.modal-close{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:var(--ink3);border:1px solid var(--line2);color:var(--tx3);cursor:pointer;transition:all var(--ease);}
.modal-close:hover{background:var(--red-d);color:var(--red);}
.modal-close svg{width:14px;height:14px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;}
.modal-body{padding:22px;}
.modal-footer{display:flex;align-items:center;justify-content:flex-end;gap:9px;padding:14px 22px;border-top:1px solid var(--line);background:var(--ink3);}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:50px 20px;color:var(--tx3);}
.empty-state svg{width:44px;height:44px;stroke:var(--tx4);fill:none;stroke-width:1.2;stroke-linecap:round;stroke-linejoin:round;margin:0 auto 14px;display:block;}
.empty-state h3{font-size:14px;font-weight:600;color:var(--tx2);margin-bottom:5px;}
.empty-state p{font-size:13px;}

/* ── CHART ── */
.chart-wrap{position:relative;height:230px;}

/* ── EXPIRING ── */
.expiring-list{display:flex;flex-direction:column;gap:8px;}
.exp-item{display:flex;align-items:center;gap:11px;padding:11px 13px;background:var(--ink3);border:1px solid var(--line);border-radius:11px;}
.exp-av{width:34px;height:34px;border-radius:9px;background:var(--amber-d);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--amber);flex-shrink:0;}
.exp-info{flex:1;min-width:0;}
.exp-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.exp-plan{font-size:11px;color:var(--tx3);}
.exp-days{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;padding:3px 8px;border-radius:6px;background:var(--amber-d);color:var(--amber);white-space:nowrap;flex-shrink:0;}
.exp-days.urgent{background:var(--red-d);color:var(--red);}

/* ── PROGRESS BAR ── */
.prog-bar{height:5px;background:var(--ink4);border-radius:3px;overflow:hidden;margin-top:4px;}
.prog-fill{height:100%;border-radius:3px;transition:width .5s ease;}

/* ── INFO BOX ── */
.info-box{background:var(--blue-g);border:1px solid rgba(64,128,240,0.15);border-radius:10px;padding:12px 14px;font-size:13px;color:var(--tx2);margin-bottom:16px;}
.info-box svg{width:14px;height:14px;stroke:var(--blue);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;display:inline;margin-right:5px;vertical-align:middle;}

/* ── RENEWAL BADGE ── */
.auto-renew-on{background:var(--green-d);color:var(--green);font-family:'DM Mono',monospace;font-size:10px;padding:2px 8px;border-radius:20px;display:inline-flex;align-items:center;gap:4px;}
.auto-renew-off{background:var(--ink4);color:var(--tx3);font-family:'DM Mono',monospace;font-size:10px;padding:2px 8px;border-radius:20px;}

/* ── INVOICE AMOUNT SUMMARY ── */
.pay-summary{background:var(--ink3);border:1px solid var(--line2);border-radius:11px;padding:14px 16px;margin-top:14px;}
.pay-summary-row{display:flex;justify-content:space-between;font-size:13px;padding:4px 0;}
.pay-summary-row.total{border-top:1px solid var(--line2);margin-top:8px;padding-top:10px;font-weight:700;font-family:'Syne',sans-serif;font-size:16px;color:var(--gold);}

/* ── HEALTH ── */
.health-metric{background:var(--ink3);border:1px solid var(--line);border-radius:11px;padding:12px 14px;margin-bottom:8px;display:flex;align-items:center;gap:12px;}
.health-ring{position:relative;width:56px;height:56px;flex-shrink:0;}
.health-ring svg{width:100%;height:100%;transform:rotate(-90deg);}
.hr-track{fill:none;stroke:var(--ink4);stroke-width:5;}
.hr-fill{fill:none;stroke-width:5;stroke-linecap:round;}
.health-val{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:14px;font-weight:700;}

/* ── PRIO DOT ── */
.prio-dot{width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0;}
.prio-critical{background:var(--red);}
.prio-high{background:var(--amber);}
.prio-medium{background:var(--cyan);}
.prio-low{background:var(--tx3);}

/* ── RESPONSIVE ── */
@media(max-width:1100px){
  .grid-main-side{grid-template-columns:1fr;}
  .grid-2,.grid-3{grid-template-columns:1fr 1fr;}
}
@media(max-width:900px){
  .grid-2,.grid-3{grid-template-columns:1fr;}
}
@media(max-width:768px){
  :root{--sidebar-w:0px;}
  .sidebar{
    position:fixed;top:0;left:0;bottom:0;width:248px;
    transform:translateX(-100%);transition:transform .25s cubic-bezier(.4,0,.2,1);
    z-index:300;
  }
  .sidebar.open{transform:translateX(0);box-shadow:8px 0 32px rgba(0,0,0,0.6);}
  .sidebar-backdrop{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);
    backdrop-filter:blur(2px);z-index:299;
  }
  .sidebar-backdrop.open{display:block;}
  .main{margin-left:0;}
  .topbar{left:0;padding:0 14px;}
  .tb-title{font-size:14px;letter-spacing:1px;}
  .tb-date{display:none;}
  .main-inner{padding:14px;}
  .stats-grid{grid-template-columns:1fr 1fr;}
  .form-grid{grid-template-columns:1fr;}
  .page-header{flex-direction:column;gap:10px;}
  .page-actions{width:100%;}
  .page-actions .btn{flex:1;justify-content:center;}
  .modal{max-width:100%;margin:0;border-radius:16px 16px 0 0;position:fixed;bottom:0;left:0;right:0;max-height:92vh;}
  .modal-overlay{align-items:flex-end;padding:0;}
  table{font-size:12px;}
  thead th,tbody td{padding:9px 10px;}
  .tb-greet{display:none;}
  .hamburger{display:flex !important;}
  .sc-value{font-size:24px;}
  .sc-value.money{font-size:16px;}
}
@media(max-width:420px){
  .stats-grid{grid-template-columns:1fr;}
}
/* Hamburger button — hidden on desktop */
.hamburger{
  display:none;align-items:center;justify-content:center;
  width:38px;height:38px;border-radius:10px;
  background:var(--ink3);border:1px solid var(--line2);
  color:var(--tx2);cursor:pointer;flex-shrink:0;
  margin-right:8px;
}
.hamburger svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
</style>
</head>
<body>

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar" id="sidebar">
  <div class="sb-logo">
    <div class="sb-mark"><span>NT</span></div>
    <div>
      <div class="sb-name">NYMIX TECH</div>
      <div class="sb-role">Super Admin</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="nav-lbl">Overview</div>
    <a href="?tab=dashboard" class="nav-item <?= $active_tab==='dashboard'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
      Dashboard
    </a>
    <div class="nav-lbl">Business</div>
    <a href="?tab=clients" class="nav-item <?= $active_tab==='clients'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      Clients
      <?php if (count($clients)): ?><span class="nav-badge"><?= count($clients) ?></span><?php endif; ?>
    </a>
    <a href="?tab=plans" class="nav-item <?= $active_tab==='plans'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
      Plans
    </a>
    <a href="?tab=subscriptions" class="nav-item <?= $active_tab==='subscriptions'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
      Subscriptions
    </a>
    <div class="nav-lbl">Finance</div>
    <a href="?tab=invoices" class="nav-item <?= $active_tab==='invoices'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Invoices
      <?php $ui=count($unpaid_invoices); if($ui): ?><span class="nav-badge"><?= $ui ?></span><?php endif; ?>
    </a>
    <?php
    $pending_pay_r = $conn->query("SELECT COUNT(*) as cnt FROM subscription_payments WHERE confirmed=0");
    $pending_pay_cnt = $pending_pay_r ? (int)$pending_pay_r->fetch_assoc()['cnt'] : 0;
    ?>
    <a href="?tab=payments" class="nav-item <?= $active_tab==='payments'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Payments
      <?php if($pending_pay_cnt): ?><span class="nav-badge"><?= $pending_pay_cnt ?></span><?php endif; ?>
    </a>
    <div class="nav-lbl">Support</div>
    <a href="?tab=tickets" class="nav-item <?= $active_tab==='tickets'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Tickets
      <?php $ot=(int)$ov['open_tickets']; if($ot): ?><span class="nav-badge"><?= $ot ?></span><?php endif; ?>
    </a>
    <div class="nav-lbl">System</div>
    <a href="?tab=errors" class="nav-item <?= $active_tab==='errors'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Error Log
      <?php $eh=(int)$ov['errors_24h']; if($eh): ?><span class="nav-badge"><?= $eh ?></span><?php endif; ?>
    </a>
    <a href="?tab=admins" class="nav-item <?= $active_tab==='admins'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Admins
    </a>
    <a href="?tab=security" class="nav-item <?= $active_tab==='security'?'active':'' ?>">
      <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
      Security (SOC)
      <?php $open_alert_cnt=(int)($soc_sum['critical_alerts']??0); if($open_alert_cnt): ?><span class="nav-badge"><?= $open_alert_cnt ?></span><?php endif; ?>
    </a>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-avatar"><?= strtoupper(substr($admin_name,0,2)) ?></div>
      <div style="flex:1;min-width:0;">
        <div class="sb-uname"><?= $admin_name ?></div>
        <div class="sb-urole"><?= $admin_role ?></div>
      </div>
      <a href="?action=logout" class="logout-btn" title="Logout">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>
  </div>
</aside>
<div class="sidebar-backdrop" id="sidebar-backdrop" onclick="closeSidebar()"></div>

<!-- ═══ TOPBAR ═══ -->
<header class="topbar">
  <button class="hamburger" id="hamburger-btn" onclick="toggleSidebar()" aria-label="Open menu">
    <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <div class="tb-title"><?= strtoupper($active_tab) ?></div>
  <div class="tb-greet"><div class="status-dot"></div><?= $greeting ?>, <?= $admin_name ?></div>
  <div class="tb-date"><?= date('D, d M Y') ?></div>
</header>

<!-- ═══ MAIN ═══ -->
<main class="main"><div class="main-inner">

<?php // ════════════ DASHBOARD ════════════
if ($active_tab === 'dashboard'): ?>

<div class="page-header">
  <div>
    <div class="page-eyebrow">System Overview</div>
    <div class="page-title">Dashboard</div>
    <div class="page-sub">Real-time platform overview · Auto-renewal active</div>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--blue-d);"><svg viewBox="0 0 24 24" stroke="var(--blue)"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
    <div class="sc-label">Total Clients</div>
    <div class="sc-value"><?= number_format($total_clients) ?></div>
    <div class="sc-change"><?= number_format((int)$ov['active_clients']) ?> active</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--gold-d);"><svg viewBox="0 0 24 24" stroke="var(--gold)"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
    <div class="sc-label">Sub Revenue / Mo</div>
    <div class="sc-value money"><?= money($ov['sub_revenue_this_month']) ?></div>
    <div class="sc-change up">MRR this month</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--green-d);"><svg viewBox="0 0 24 24" stroke="var(--green)"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><path d="M12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg></div>
    <div class="sc-label">Total Revenue</div>
    <div class="sc-value money"><?= money($total_revenue_all) ?></div>
    <div class="sc-change">All time payments</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--red-d);"><svg viewBox="0 0 24 24" stroke="var(--red)"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg></div>
    <div class="sc-label">Outstanding</div>
    <div class="sc-value money"><?= money($total_outstanding) ?></div>
    <div class="sc-change down"><?= count($unpaid_invoices) ?> unpaid invoices</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--amber-d);"><svg viewBox="0 0 24 24" stroke="var(--amber)"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
    <div class="sc-label">Open Tickets</div>
    <div class="sc-value"><?= number_format($ov['open_tickets']) ?></div>
    <div class="sc-change <?= $ov['open_tickets']>0?'down':'' ?>"><?= $ov['open_tickets']>0?'⚠ Needs attention':'✓ All clear' ?></div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--red-d);"><svg viewBox="0 0 24 24" stroke="var(--red)"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
    <div class="sc-label">Errors (24h)</div>
    <div class="sc-value"><?= number_format($ov['errors_24h']) ?></div>
    <div class="sc-change <?= $ov['errors_24h']>0?'down':'' ?>"><?= $ov['errors_24h']>0?'⚠ Review log':'✓ System healthy' ?></div>
  </div>
</div>

<div class="grid-main-side" style="margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><div class="card-title"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Monthly Subscription Revenue</div></div>
    <div class="card-body"><div class="chart-wrap"><canvas id="revenueChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Expiring Soon</div></div>
    <div class="card-body">
      <?php if (empty($expiring)): ?>
      <div class="empty-state" style="padding:24px 0;"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><h3>All subscriptions current</h3><p>Nothing expiring in 7 days</p></div>
      <?php else: ?>
      <div class="expiring-list">
        <?php foreach ($expiring as $exp): $d=(int)($exp['days_left']??0); ?>
        <div class="exp-item">
          <div class="exp-av"><?= strtoupper(substr($exp['business_name'],0,1)) ?></div>
          <div class="exp-info">
            <div class="exp-name"><?= esc($exp['business_name']) ?></div>
            <div class="exp-plan"><?= esc($exp['plan']??'—') ?> · <?= money($exp['total_monthly']??0) ?>/mo</div>
          </div>
          <div class="exp-days <?= $d<=2?'urgent':'' ?>"><?= $d ?>d</div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── RECENT PAYMENTS ── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <div class="card-title">
      <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      Recent Payments
    </div>
    <a href="?tab=payments" class="btn btn-ghost btn-sm">View All</a>
  </div>
  <div class="card-body-flush">
    <?php
    $recent_pays = [];
    $rp = $conn->query("
        SELECT sp.*, c.business_name, i.invoice_no, i.total AS invoice_total
        FROM subscription_payments sp
        JOIN clients c ON c.id = sp.client_id
        LEFT JOIN invoices i ON i.id = sp.invoice_id
        ORDER BY sp.created_at DESC
        LIMIT 8
    ");
    if ($rp) while ($row = $rp->fetch_assoc()) $recent_pays[] = $row;
    ?>
    <?php if (empty($recent_pays)): ?>
    <div class="empty-state" style="padding:30px 20px;">
      <svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
      <h3>No payments yet</h3>
      <p>Payments will appear here once recorded.</p>
    </div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Client</th>
          <th>Invoice #</th>
          <th>Amount</th>
          <th>Method</th>
          <th>Reference</th>
          <th>Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent_pays as $rp_row):
          $method_colors = [
            'mpesa'  => ['background:rgba(14,200,122,0.1);color:var(--green)',  'MPESA'],
            'bank'   => ['background:rgba(64,128,240,0.1);color:var(--blue)',   'BANK'],
            'cash'   => ['background:rgba(245,166,35,0.1);color:var(--amber)',  'CASH'],
            'cheque' => ['background:rgba(155,114,240,0.1);color:var(--purple)','CHEQUE'],
          ];
          $mkey   = strtolower($rp_row['payment_method'] ?? 'cash');
          $mstyle = $method_colors[$mkey] ?? $method_colors['cash'];
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px;">
              <div style="width:30px;height:30px;border-radius:8px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--blue);flex-shrink:0;">
                <?= strtoupper(substr($rp_row['business_name'],0,1)) ?>
              </div>
              <span style="font-weight:600;font-size:13px;"><?= esc($rp_row['business_name']) ?></span>
            </div>
          </td>
          <td class="td-mono"><?= esc($rp_row['invoice_no'] ?? '—') ?></td>
          <td>
            <span style="font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:var(--green);">
              <?= money($rp_row['amount']) ?>
            </span>
          </td>
          <td>
            <span style="font-family:'DM Mono',monospace;font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px;<?= $mstyle[0] ?>;">
              <?= $mstyle[1] ?>
            </span>
          </td>
          <td class="td-mono" style="font-size:11px;color:var(--tx3);">
            <?= esc($rp_row['reference'] ?? '—') ?>
          </td>
          <td class="td-mono" style="font-size:12px;color:var(--tx3);">
            <?= $rp_row['payment_date'] ? date('d M Y', strtotime($rp_row['payment_date'])) : '—' ?>
          </td>
          <td>
            <?php if ($rp_row['confirmed']): ?>
              <span class="badge badge-active">✓ Confirmed</span>
            <?php else: ?>
              <span class="badge badge-read_only">⏳ Pending</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="grid-3">
  <div class="card">
    <div class="card-header"><div class="card-title"><svg viewBox="0 0 24 24"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>Client Status</div></div>
    <div class="card-body">
      <?php
      $statuses=[['Active',$ov['active_clients'],'var(--green)'],['Read-only',$ov['readonly_clients'],'var(--amber)'],['Suspended',$ov['suspended_clients'],'var(--red)']];
      $tc=max(1,$total_clients);
      foreach($statuses as [$lbl,$val,$clr]):
        $pct=round($val/$tc*100); ?>
      <div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;"><span style="color:var(--tx2);"><?= $lbl ?></span><span style="font-family:'DM Mono',monospace;color:var(--tx3);"><?= $val ?> · <?= $pct ?>%</span></div>
        <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $clr ?>;"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Recent Clients</div></div>
    <div class="card-body" style="padding:0;">
      <?php foreach (array_slice($clients,0,5) as $rc): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--line);">
        <?php if (!empty($rc['logo'])): ?>
        <img src="<?= esc($rc['logo']) ?>" class="client-logo" alt="">
        <?php else: ?>
        <div style="width:34px;height:34px;border-radius:9px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--blue);flex-shrink:0;"><?= strtoupper(substr($rc['business_name'],0,1)) ?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= esc($rc['business_name']) ?></div>
          <div style="font-size:11px;color:var(--tx3);"><?= esc($rc['plan_name']??'No plan') ?></div>
        </div>
        <span class="badge badge-<?= strtolower($rc['status']??'active') ?>"><?= ucfirst($rc['status']??'active') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
  // Fetch last 24 history points for sparklines
  $health_history = [];
  $hh = $conn->query("SELECT recorded_at, server_load, memory_pct, disk_pct FROM system_health ORDER BY recorded_at DESC LIMIT 24");
  if ($hh) while ($row = $hh->fetch_assoc()) $health_history[] = $row;
  $health_history = array_reverse($health_history);
  ?>
  <?php if (!empty($health_latest)): ?>
  <?php
  $cpu_pct  = min(100, (float)($health_latest['server_load'] ?? 0));
  $mem_pct  = (float)($health_latest['memory_pct'] ?? 0);
  $disk_pct = (float)($health_latest['disk_pct'] ?? 0);
  $disk_free = (float)($health_latest['disk_free_gb'] ?? 0);
  $disk_total = (float)($health_latest['disk_total_gb'] ?? 0);
  $mem_used  = (float)($health_latest['memory_used_mb'] ?? 0);
  $mem_total = (float)($health_latest['memory_total_mb'] ?? 0);
  $db_mb     = (float)($health_latest['db_size_mb'] ?? 0);
  $db_conns  = (int)($health_latest['db_connections'] ?? 0);
  $db_slow   = (int)($health_latest['db_slow_queries'] ?? 0);
  $db_uptime = (int)($health_latest['db_uptime_secs'] ?? 0);
  $php_ver   = $health_latest['php_version'] ?? '—';
  $rec_at    = $health_latest['recorded_at'] ?? '';

  function ring_svg($pct, $color, $size=56) {
      $r = ($size/2) - 5;
      $c = 2 * M_PI * $r;
      $off = $c * (1 - min(100, $pct) / 100);
      $col = $pct > 85 ? 'var(--red)' : ($pct > 65 ? 'var(--amber)' : $color);
      return "
      <div class='health-ring' style='width:{$size}px;height:{$size}px;'>
        <svg viewBox='0 0 {$size} {$size}'>
          <circle class='hr-track' cx='".($size/2)."' cy='".($size/2)."' r='$r'/>
          <circle class='hr-fill' cx='".($size/2)."' cy='".($size/2)."' r='$r'
            stroke='$col' stroke-dasharray='$c' stroke-dashoffset='$off'/>
        </svg>
        <div class='health-val' style='color:$col;font-size:13px;'>".round($pct)."<span style='font-size:9px;'>%</span></div>
      </div>";
  }

  function db_uptime_fmt($s) {
      if ($s <= 0) return '—';
      $d = floor($s/86400); $h = floor(($s%86400)/3600); $m = floor(($s%3600)/60);
      return $d>0 ? "{$d}d {$h}h" : ($h>0 ? "{$h}h {$m}m" : "{$m}m");
  }

  // Build sparkline SVG points from history
  function sparkline($history, $key, $color) {
      if (empty($history)) return '';
      $vals = array_map(fn($r) => (float)($r[$key] ?? 0), $history);
      $max  = max($vals) ?: 1;
      $w = 80; $h = 24; $n = count($vals);
      $pts = '';
      foreach ($vals as $i => $v) {
          $x = $n > 1 ? round($i / ($n-1) * $w, 1) : $w/2;
          $y = round($h - ($v / $max * $h * 0.9) - 1, 1);
          $pts .= ($i===0 ? "M$x,$y" : " L$x,$y");
      }
      return "<svg width='$w' height='$h' viewBox='0 0 $w $h' style='overflow:visible'>
        <path d='$pts' fill='none' stroke='$color' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/>
      </svg>";
  }
  ?>
  <div class="card" style="grid-column:1/-1;">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        System Health
      </div>
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:6px;height:6px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green);"></div>
        <span style="font-family:'DM Mono',monospace;font-size:10px;color:var(--tx3);">
          Last: <?= $rec_at ? date('d M H:i', strtotime($rec_at)) : '—' ?>
        </span>
      </div>
    </div>
    <div class="card-body">

      <!-- ── TOP ROW: 3 rings + DB info ── -->
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;margin-bottom:20px;">

        <!-- CPU -->
        <div style="background:var(--ink3);border:1px solid var(--line);border-radius:12px;padding:14px;display:flex;align-items:center;gap:12px;">
          <?= ring_svg($cpu_pct, '#4080F0') ?>
          <div>
            <div style="font-family:'DM Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--tx3);text-transform:uppercase;margin-bottom:3px;">CPU Load</div>
            <div style="font-size:13px;color:var(--tx2);"><?= $cpu_pct ?>%</div>
            <div style="margin-top:6px;"><?= sparkline($health_history,'server_load','#4080F0') ?></div>
          </div>
        </div>

        <!-- Memory -->
        <div style="background:var(--ink3);border:1px solid var(--line);border-radius:12px;padding:14px;display:flex;align-items:center;gap:12px;">
          <?= ring_svg($mem_pct, '#9B72F0') ?>
          <div>
            <div style="font-family:'DM Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--tx3);text-transform:uppercase;margin-bottom:3px;">Memory</div>
            <div style="font-size:13px;color:var(--tx2);"><?= round($mem_used) ?>MB / <?= round($mem_total) ?>MB</div>
            <div style="margin-top:6px;"><?= sparkline($health_history,'memory_pct','#9B72F0') ?></div>
          </div>
        </div>

        <!-- Disk -->
        <div style="background:var(--ink3);border:1px solid var(--line);border-radius:12px;padding:14px;display:flex;align-items:center;gap:12px;">
          <?= ring_svg($disk_pct, '#EAAD19') ?>
          <div>
            <div style="font-family:'DM Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--tx3);text-transform:uppercase;margin-bottom:3px;">Disk</div>
            <div style="font-size:13px;color:var(--tx2);"><?= $disk_free ?>GB free</div>
            <div style="font-size:11px;color:var(--tx3);"><?= $disk_total ?>GB total</div>
            <div style="margin-top:4px;"><?= sparkline($health_history,'disk_pct','#EAAD19') ?></div>
          </div>
        </div>

        <!-- Database -->
        <div style="background:var(--ink3);border:1px solid var(--line);border-radius:12px;padding:14px;">
          <div style="font-family:'DM Mono',monospace;font-size:9px;letter-spacing:2px;color:var(--tx3);text-transform:uppercase;margin-bottom:10px;">Database</div>
          <div style="display:flex;flex-direction:column;gap:7px;">
            <div style="display:flex;justify-content:space-between;font-size:12px;">
              <span style="color:var(--tx3);">Size</span>
              <span style="font-family:'DM Mono',monospace;color:var(--cyan);"><?= $db_mb ?>MB</span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
              <span style="color:var(--tx3);">Connections</span>
              <span style="font-family:'DM Mono',monospace;color:var(--tx2);"><?= $db_conns ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
              <span style="color:var(--tx3);">Slow queries</span>
              <span style="font-family:'DM Mono',monospace;color:<?= $db_slow>0?'var(--amber)':'var(--green)' ?>;"><?= $db_slow ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
              <span style="color:var(--tx3);">Uptime</span>
              <span style="font-family:'DM Mono',monospace;color:var(--tx2);"><?= db_uptime_fmt($db_uptime) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;">
              <span style="color:var(--tx3);">PHP</span>
              <span style="font-family:'DM Mono',monospace;color:var(--tx2);"><?= esc($php_ver) ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- ── BOTTOM ROW: App metrics ── -->
      <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;">
        <?php
        $app_metrics = [
            ['Active Clients',    $health_latest['active_clients']    ?? 0, 'var(--green)',  false],
            ['Suspended',         $health_latest['suspended_clients']  ?? 0, 'var(--red)',    false],
            ['Active Subs',       $health_latest['active_subs']        ?? 0, 'var(--blue)',   false],
            ['Unpaid Invoices',   $health_latest['unpaid_invoices']    ?? 0, 'var(--amber)',  true],
            ['Open Tickets',      $health_latest['open_tickets']       ?? 0, 'var(--cyan)',   true],
            ['Errors (24h)',      $health_latest['errors_24h']         ?? 0, 'var(--red)',    true],
        ];
        foreach ($app_metrics as [$lbl, $val, $clr, $warn]):
            $val = (int)$val;
            $highlight = $warn && $val > 0;
        ?>
        <div style="background:var(--ink3);border:1px solid <?= $highlight ? 'rgba(240,64,96,0.2)' : 'var(--line)' ?>;border-radius:10px;padding:12px;text-align:center;">
          <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:700;color:<?= $highlight ? 'var(--red)' : $clr ?>;"><?= $val ?></div>
          <div style="font-family:'DM Mono',monospace;font-size:9px;letter-spacing:1px;color:var(--tx3);text-transform:uppercase;margin-top:4px;"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
  <?php else: ?>
  <div class="card" style="grid-column:1/-1;">
    <div class="card-header"><div class="card-title"><svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>System Health</div></div>
    <div class="card-body">
      <div class="empty-state" style="padding:30px 0;">
        <svg viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        <h3>No health data yet</h3>
        <p style="margin-bottom:14px;">Run the cron script to start collecting metrics.</p>
        <code style="background:var(--ink3);padding:8px 14px;border-radius:8px;font-family:'DM Mono',monospace;font-size:12px;color:var(--cyan);">
          require_once dirname(__DIR__, 2) . '/includes/db.php';
        </code>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php endif; // end dashboard ?>


<?php // ════════════ CLIENTS ════════════
if ($active_tab === 'clients'): ?>

<?php if (!empty($_SESSION['client_success'])): ?>
<div style="background:rgba(14,200,122,.08);border:1px solid rgba(14,200,122,.25);color:var(--green);padding:13px 18px;border-radius:11px;margin-bottom:18px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;">
    <span style="font-size:16px;">✓</span>
    <?= esc($_SESSION['client_success']) ?>
</div>
<?php unset($_SESSION['client_success']); endif; ?>

<?php if (!empty($_SESSION['client_error'])): ?>
<div style="background:var(--red-d);border:1px solid rgba(240,64,96,.25);color:var(--red);padding:13px 18px;border-radius:11px;margin-bottom:18px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;">
    <span style="font-size:16px;">⚠</span>
    <?= esc($_SESSION['client_error']) ?>
</div>
<?php unset($_SESSION['client_error']); endif; ?>

<div class="page-header">
  <div>
    <div class="page-eyebrow">Business</div>
    <div class="page-title">Clients</div>
    <div class="page-sub"><?= count($clients) ?> clients registered</div>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" onclick="openModal('m-client-add')">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Client
    </button>
  </div>
</div>

<div class="card"><div class="card-body-flush"><div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Logo</th><th>Business Name</th><th>Owner</th><th>Phone</th><th>Client Code</th><th>Plan</th><th>MRR</th><th>Expires</th><th>Days Left</th><th>Auto-Renew</th><th>Sub Status</th><th>Client Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if (empty($clients)): ?>
      <tr><td colspan="13"><div class="empty-state"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><h3>No clients yet</h3><p>Add your first client.</p></div></td></tr>
      <?php else: ?>
      <?php foreach ($clients as $c):
        $days = isset($c['days_left']) ? (int)$c['days_left'] : null;
        $days_color = is_null($days)?'':(($days<0)?'var(--red)':($days<=7?'var(--amber)':'var(--green)'));
      ?>
      <tr>
        <td class="td-mono">#<?= $c['id'] ?></td>
        <td>
          <?php if (!empty($c['logo'])): ?>
          <img src="<?= esc($c['logo']) ?>" class="client-logo" alt="">
          <?php else: ?>
          <div style="width:34px;height:34px;border-radius:9px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--blue);"><?= strtoupper(substr($c['business_name'],0,1)) ?></div>
          <?php endif; ?>
        </td>
        <td class="td-name"><?= esc($c['business_name']) ?></td>
        <td class="td-muted"><?= esc($c['owner_name']??'—') ?></td>
        <td class="td-muted"><?= esc($c['phone']) ?></td>
        <td class="td-mono" style="font-size:11px;color:var(--tx3);"><?= esc($c['kra_pin']??'—') ?></td>
        <td><?= $c['plan_name']?esc($c['plan_name']):'<span class="td-muted">No plan</span>' ?></td>
        <td class="td-mono"><?= $c['total_monthly']?money($c['total_monthly']):'—' ?></td>
        <td class="td-mono"><?= $c['end_date']?esc($c['end_date']):'—' ?></td>
        <td><?php if(!is_null($days)): ?><span style="font-family:'DM Mono',monospace;font-size:12px;color:<?= $days_color ?>;"><?= $days ?>d</span><?php else: ?>—<?php endif; ?></td>
        <td><?= ($c['auto_renew']??0)?'<span class="auto-renew-on">⟳ ON</span>':'<span class="auto-renew-off">OFF</span>' ?></td>
        <td><?php if($c['sub_status']): ?><span class="badge badge-<?= strtolower($c['sub_status']) ?>"><?= ucfirst($c['sub_status']) ?></span><?php else: ?><span class="td-muted">—</span><?php endif; ?></td>
        <td><span class="badge badge-<?= strtolower($c['status']??'active') ?>"><?= ucfirst($c['status']??'active') ?></span></td>
        <td>
          <div class="td-actions">
            <button class="btn btn-ghost btn-sm btn-icon" onclick="openEditClient(<?= htmlspecialchars(json_encode($c)) ?>)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <form method="POST" onsubmit="return confirm('Delete client and all data?');" style="display:inline;">
              <input type="hidden" name="_action" value="client_delete">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <?= csrf_token_field() ?>
              <button type="submit" class="btn btn-danger btn-sm btn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div></div></div>

<!-- Add Client Modal -->
<div class="modal-overlay" id="m-client-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>Add New Client</div>
      <button class="modal-close" onclick="closeModal('m-client-add')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="client_add">
      <?= csrf_token_field() ?>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full"><label class="field-label">Business Name *</label><input type="text" name="business_name" required placeholder="Acme Hardware Ltd"></div>
          <div class="form-group"><label class="field-label">Owner Name</label><input type="text" name="owner_name" placeholder="John Doe"></div>
          <div class="form-group"><label class="field-label">Phone *</label><input type="tel" name="phone" required placeholder="+254 700 000 000"></div>
          <div class="form-group"><label class="field-label">Email</label><input type="email" name="email" placeholder="owner@company.co.ke"></div>
          <div class="form-group"><label class="field-label">KRA PIN <span style="color:var(--tx3);font-size:8px;letter-spacing:1px;">(optional — auto-generated if blank)</span></label><input type="text" name="kra_pin" placeholder="Leave blank to auto-generate"></div>
          <div class="form-group"><label class="field-label">Address</label><input type="text" name="address" placeholder="Nairobi, Kenya"></div>
          <div class="form-group"><label class="field-label">Status</label>
            <select name="status"><option value="active">Active</option><option value="read_only">Read-only</option><option value="suspended">Suspended</option></select>
          </div>
          <div class="form-group"><label class="field-label">Business Logo</label><input type="file" name="logo" accept="image/*"></div>
          <div class="form-group full"><label class="field-label">Notes (internal)</label><input type="text" name="notes" placeholder="Optional admin notes"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-client-add')">Cancel</button>
        <button type="submit" class="btn btn-primary"><svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Save Client</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Client Modal -->
<div class="modal-overlay" id="m-client-edit">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Client</div>
      <button class="modal-close" onclick="closeModal('m-client-edit')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="_action" value="client_edit">
      <?= csrf_token_field() ?>
      <input type="hidden" name="id" id="ec-id">
      <div class="modal-body">
        <div id="ec-logo-preview" style="margin-bottom:14px;display:none;">
          <label class="field-label">Current Logo</label>
          <div class="logo-preview"><img id="ec-logo-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px;"></div>
        </div>
        <div class="form-grid">
          <div class="form-group full"><label class="field-label">Business Name *</label><input type="text" name="business_name" id="ec-name" required></div>
          <div class="form-group"><label class="field-label">Owner Name</label><input type="text" name="owner_name" id="ec-owner"></div>
          <div class="form-group"><label class="field-label">Phone *</label><input type="tel" name="phone" id="ec-phone" required></div>
          <div class="form-group"><label class="field-label">Email</label><input type="email" name="email" id="ec-email"></div>
          <div class="form-group"><label class="field-label">KRA PIN</label><input type="text" name="kra_pin" id="ec-kra"></div>
          <div class="form-group"><label class="field-label">Address</label><input type="text" name="address" id="ec-address"></div>
          <div class="form-group"><label class="field-label">Status</label>
            <select name="status" id="ec-status"><option value="active">Active</option><option value="read_only">Read-only</option><option value="suspended">Suspended</option><option value="cancelled">Cancelled</option></select>
          </div>
          <div class="form-group"><label class="field-label">Replace Logo</label><input type="file" name="logo" accept="image/*"></div>
          <div class="form-group full"><label class="field-label">Notes</label><input type="text" name="notes" id="ec-notes"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-client-edit')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Client</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>


<?php // ════════════ PLANS ════════════
if ($active_tab === 'plans'): ?>
<div class="page-header">
  <div>
    <div class="page-eyebrow">Business</div>
    <div class="page-title">Subscription Plans</div>
    <div class="page-sub"><?= count($plans) ?> plans · base_price + branch_addon_price × extra branches</div>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" onclick="openModal('m-plan-add')">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Plan
    </button>
  </div>
</div>

<div class="grid-3">
  <?php foreach ($plans as $p):
    $features = json_decode($p['features']??'[]',true) ?: [];
  ?>
  <div class="card">
    <div class="card-body">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:12px;">
        <div>
          <div style="font-family:'Syne',sans-serif;font-size:17px;font-weight:800;"><?= esc($p['name']) ?></div>
          <div style="font-family:'DM Mono',monospace;font-size:9px;color:var(--tx3);letter-spacing:1.5px;">ID #<?= $p['id'] ?></div>
        </div>
        <span class="badge badge-<?= $p['is_active']?'active':'suspended' ?>"><?= $p['is_active']?'Active':'Off' ?></span>
      </div>
      <div style="font-family:'Syne',sans-serif;font-size:28px;font-weight:700;color:var(--gold);margin-bottom:4px;"><?= money($p['base_price']) ?><span style="font-size:13px;color:var(--tx3);font-family:'DM Sans',sans-serif;font-weight:400;">/mo</span></div>
      <div style="font-size:12px;color:var(--tx3);margin-bottom:6px;">+<?= money($p['branch_addon_price']) ?>/extra branch</div>
      <div style="display:flex;gap:16px;font-family:'DM Mono',monospace;font-size:11px;color:var(--tx2);margin-bottom:14px;background:var(--ink3);border-radius:8px;padding:8px 12px;">
        <div>Users: <strong><?= $p['max_users']??'∞' ?></strong></div>
        <div>Products: <strong><?= $p['max_products']??'∞' ?></strong></div>
      </div>
      <?php if(!empty($features)): ?>
      <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:14px;">
        <?php foreach($features as $f): ?><span style="background:var(--blue-d);color:var(--blue);font-family:'DM Mono',monospace;font-size:9px;padding:2px 7px;border-radius:4px;"><?= esc($f) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;padding:12px 20px;border-top:1px solid var(--line);background:var(--ink3);">
      <button class="btn btn-ghost btn-sm" style="flex:1;" onclick="openEditPlan(<?= htmlspecialchars(json_encode($p)) ?>)">Edit</button>
      <form method="POST" onsubmit="return confirm('Delete this plan?');"><input type="hidden" name="_action" value="plan_delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><?= csrf_token_field() ?><button type="submit" class="btn btn-danger btn-sm">Delete</button></form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(empty($plans)): ?>
  <div class="card" style="grid-column:1/-1;"><div class="empty-state"><svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg><h3>No plans configured</h3><p>Create your first subscription plan.</p></div></div>
  <?php endif; ?>
</div>

<!-- Add Plan Modal -->
<div class="modal-overlay" id="m-plan-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/></svg>New Plan</div>
      <button class="modal-close" onclick="closeModal('m-plan-add')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="plan_add">
      <?= csrf_token_field() ?>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full"><label class="field-label">Plan Name *</label><input type="text" name="name" required placeholder="Starter / Business / Enterprise"></div>
          <div class="form-group"><label class="field-label">Base Price (KES/mo) *</label><input type="number" name="base_price" step="0.01" min="0" required placeholder="1500.00"></div>
          <div class="form-group"><label class="field-label">Branch Add-on (KES/mo)</label><input type="number" name="branch_addon_price" step="0.01" min="0" placeholder="500.00" value="0"></div>
          <div class="form-group"><label class="field-label">Max Users (blank=unlimited)</label><input type="number" name="max_users" min="1" placeholder="e.g. 5"></div>
          <div class="form-group"><label class="field-label">Max Products (blank=unlimited)</label><input type="number" name="max_products" min="1" placeholder="e.g. 500"></div>
          <div class="form-group full"><label class="field-label">Features (JSON array)</label><textarea name="features" placeholder='["inventory","sales","reports"]'></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-plan-add')">Cancel</button>
        <button type="submit" class="btn btn-gold">Create Plan</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Plan Modal -->
<div class="modal-overlay" id="m-plan-edit">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Plan</div>
      <button class="modal-close" onclick="closeModal('m-plan-edit')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="plan_edit">
      <?= csrf_token_field() ?>
      <input type="hidden" name="id" id="ep-id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group full"><label class="field-label">Plan Name *</label><input type="text" name="name" id="ep-name" required></div>
          <div class="form-group"><label class="field-label">Base Price (KES/mo) *</label><input type="number" name="base_price" id="ep-price" step="0.01" min="0" required></div>
          <div class="form-group"><label class="field-label">Branch Add-on (KES/mo)</label><input type="number" name="branch_addon_price" id="ep-addon" step="0.01" min="0"></div>
          <div class="form-group"><label class="field-label">Max Users</label><input type="number" name="max_users" id="ep-maxu" min="1" placeholder="blank=unlimited"></div>
          <div class="form-group"><label class="field-label">Max Products</label><input type="number" name="max_products" id="ep-maxp" min="1" placeholder="blank=unlimited"></div>
          <div class="form-group full"><label class="field-label">Features (JSON)</label><textarea name="features" id="ep-features"></textarea></div>
          <div class="form-group"><label class="checkbox-row"><input type="checkbox" name="is_active" id="ep-active" value="1"> Plan is Active</label></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-plan-edit')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Plan</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>


<?php // ════════════ SUBSCRIPTIONS ════════════
if ($active_tab === 'subscriptions'): ?>
<div class="page-header">
  <div>
    <div class="page-eyebrow">Business</div>
    <div class="page-title">Subscriptions</div>
    <div class="page-sub"><?= count($subs) ?> records · Invoice auto-generated on subscription creation</div>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" onclick="openModal('m-sub-add')">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Subscription
    </button>
  </div>
</div>

<div class="info-box">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  When you add a subscription an invoice is <strong>automatically generated</strong>. Yearly = 12× monthly, Quarterly = 3× monthly. Auto-renewal creates new periods and invoices when subscriptions expire.
</div>

<div class="card"><div class="card-body-flush"><div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Client</th><th>Plan</th><th>Branches</th><th>Base/Mo</th><th>Total/Mo</th><th>Cycle</th><th>Start</th><th>End</th><th>Auto-Renew</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if(empty($subs)): ?>
      <tr><td colspan="12"><div class="empty-state"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg><h3>No subscriptions</h3><p>Create a subscription for a client.</p></div></td></tr>
      <?php endif; ?>
      <?php foreach($subs as $s): ?>
      <tr>
        <td class="td-mono">#<?= $s['id'] ?></td>
        <td class="td-name"><?= esc($s['business_name']) ?></td>
        <td><?= esc($s['plan_name']??'—') ?></td>
        <td class="td-mono" style="text-align:center;"><?= (int)$s['branch_count'] ?></td>
        <td class="td-mono"><?= money($s['base_price']) ?></td>
        <td class="td-mono" style="color:var(--gold);"><?= money($s['total_monthly']) ?></td>
        <td><span class="badge badge-info"><?= ucfirst($s['billing_cycle']) ?></span></td>
        <td class="td-mono"><?= esc($s['start_date']) ?></td>
        <td class="td-mono"><?= esc($s['end_date']??'—') ?></td>
        <td><?= $s['auto_renew']?'<span class="auto-renew-on">⟳ ON</span>':'<span class="auto-renew-off">OFF</span>' ?></td>
        <td><span class="badge badge-<?= strtolower($s['status']??'active') ?>"><?= ucfirst($s['status']??'active') ?></span></td>
        <td>
          <div class="td-actions">
            <button class="btn btn-ghost btn-sm btn-icon" onclick="openEditSub(<?= htmlspecialchars(json_encode($s)) ?>)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <form method="POST" onsubmit="return confirm('Delete subscription?');" style="display:inline;">
              <input type="hidden" name="_action" value="sub_delete">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <?= csrf_token_field() ?>
              <button type="submit" class="btn btn-danger btn-sm btn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<!-- Add Sub Modal -->
<div class="modal-overlay" id="m-sub-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>New Subscription</div>
      <button class="modal-close" onclick="closeModal('m-sub-add')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="sub_add">
      <?= csrf_token_field() ?>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label class="field-label">Client *</label>
            <select name="client_id" required><option value="">Select client…</option>
              <?php foreach($clients as $c): ?><option value="<?= $c['id'] ?>"><?= esc($c['business_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="field-label">Plan *</label>
            <select name="plan_id" required id="sub-plan-sel"><option value="">Select plan…</option>
              <?php foreach($plans as $p): ?><option value="<?= $p['id'] ?>" data-base="<?= $p['base_price'] ?>" data-addon="<?= $p['branch_addon_price'] ?>"><?= esc($p['name']) ?> — <?= money($p['base_price']) ?>/mo</option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="field-label">Branch Count</label><input type="number" name="branch_count" id="sub-bc" min="1" value="1" oninput="calcSubTotal()"></div>
          <div class="form-group"><label class="field-label">Billing Cycle</label>
            <select name="billing_cycle" id="sub-cycle" onchange="calcSubTotal()">
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly (3×)</option>
              <option value="annual">Annual (12×)</option>
            </select>
          </div>
          <div class="form-group"><label class="field-label">Start Date</label><input type="date" name="start_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label class="field-label">End Date *</label><input type="date" name="end_date" required></div>
          <div class="form-group"><label class="field-label">Status</label>
            <select name="status"><option value="active">Active</option><option value="grace">Grace</option><option value="read_only">Read-only</option><option value="expired">Expired</option></select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;padding-top:28px;">
            <label class="checkbox-row"><input type="checkbox" name="auto_renew" value="1" checked> Auto-renew</label>
          </div>
        </div>
        <div class="pay-summary" id="sub-summary" style="display:none;">
          <div class="pay-summary-row"><span style="color:var(--tx3);">Base/mo</span><span id="ss-base" class="td-mono">—</span></div>
          <div class="pay-summary-row"><span style="color:var(--tx3);">Branches add-on</span><span id="ss-addon" class="td-mono">—</span></div>
          <div class="pay-summary-row"><span style="color:var(--tx3);">Monthly total</span><span id="ss-mo" class="td-mono">—</span></div>
          <div class="pay-summary-row total"><span>Invoice Total</span><span id="ss-inv">—</span></div>
        </div>
        <div class="info-box" style="margin-top:14px;margin-bottom:0;">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          An invoice is automatically generated when saving. Pay it from the Payments tab.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-sub-add')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save & Generate Invoice</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Sub Modal -->
<div class="modal-overlay" id="m-sub-edit">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Subscription</div>
      <button class="modal-close" onclick="closeModal('m-sub-edit')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="sub_edit">
      <?= csrf_token_field() ?>
      <input type="hidden" name="id" id="es-id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label class="field-label">End Date</label><input type="date" name="end_date" id="es-end"></div>
          <div class="form-group"><label class="field-label">Branch Count</label><input type="number" name="branch_count" id="es-bc" min="1" value="1"></div>
          <div class="form-group"><label class="field-label">Status</label>
            <select name="status" id="es-status"><option value="active">Active</option><option value="grace">Grace</option><option value="read_only">Read-only</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option></select>
          </div>
          <div class="form-group" style="display:flex;align-items:center;padding-top:28px;">
            <label class="checkbox-row"><input type="checkbox" name="auto_renew" id="es-ar" value="1"> Auto-renew</label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-sub-edit')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>


<?php // ════════════ INVOICES ════════════
if ($active_tab === 'invoices'): ?>
<div class="page-header">
  <div>
    <div class="page-eyebrow">Finance</div>
    <div class="page-title">Invoices</div>
    <div class="page-sub"><?= count($invoices) ?> records · <?= count($unpaid_invoices) ?> awaiting payment · Outstanding: <?= money($total_outstanding) ?></div>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" onclick="openModal('m-inv-add')">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Invoice
    </button>
  </div>
</div>

<div class="card"><div class="card-body-flush"><div class="table-wrap">
  <table>
    <thead><tr><th>Invoice #</th><th>Client</th><th>Amount</th><th>Tax</th><th>Total</th><th>Issue Date</th><th>Due Date</th><th>Period</th><th>Status</th><th>Paid At</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if(empty($invoices)): ?><tr><td colspan="11"><div class="empty-state"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg><h3>No invoices</h3><p>Invoices auto-generate when subscriptions are created.</p></div></td></tr><?php endif; ?>
      <?php foreach($invoices as $inv):
        $ist=strtolower($inv['status']??'unpaid'); ?>
      <tr>
        <td class="td-mono"><?= esc($inv['invoice_no']) ?></td>
        <td class="td-name"><?= esc($inv['business_name']) ?></td>
        <td class="td-mono"><?= money($inv['amount']) ?></td>
        <td class="td-mono"><?= money($inv['tax']) ?></td>
        <td class="td-mono" style="color:var(--gold);"><?= money($inv['total']) ?></td>
        <td class="td-mono"><?= esc($inv['issue_date']??'—') ?></td>
        <td class="td-mono"><?= esc($inv['due_date']??'—') ?></td>
        <td class="td-mono" style="font-size:11px;"><?= esc($inv['period_start']??'') ?> → <?= esc($inv['period_end']??'') ?></td>
        <td><span class="badge badge-<?= $ist ?>"><?= ucfirst($ist) ?></span></td>
        <td class="td-mono"><?= $inv['paid_at']?date('d M Y',strtotime($inv['paid_at'])):'—' ?></td>
        <td>
          <div class="td-actions">
            <?php if($ist!=='paid'): ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="_action" value="invoice_mark_paid">
              <input type="hidden" name="id" value="<?= $inv['id'] ?>">
              <?= csrf_token_field() ?>
              <button type="submit" class="btn btn-success btn-sm">✓ Paid</button>
            </form>
            <?php endif; ?>
            <button class="btn btn-ghost btn-sm btn-icon" onclick="openEditInvoice(<?= htmlspecialchars(json_encode($inv)) ?>)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <form method="POST" onsubmit="return confirm('Delete invoice?');" style="display:inline;">
              <input type="hidden" name="_action" value="invoice_delete">
              <input type="hidden" name="id" value="<?= $inv['id'] ?>">
              <?= csrf_token_field() ?>
              <button type="submit" class="btn btn-danger btn-sm btn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<!-- Add Invoice Modal -->
<div class="modal-overlay" id="m-inv-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>New Invoice</div>
      <button class="modal-close" onclick="closeModal('m-inv-add')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="invoice_add">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label class="field-label">Client *</label>
            <select name="client_id" required><option value="">Select client…</option>
              <?php foreach($clients as $c): ?><option value="<?= $c['id'] ?>"><?= esc($c['business_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="field-label">Subscription (optional)</label>
            <select name="subscription_id"><option value="">None</option>
              <?php foreach($subs as $s): ?><option value="<?= $s['id'] ?>">#<?= $s['id'] ?> <?= esc($s['business_name']) ?> · <?= esc($s['plan_name']??'') ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="field-label">Amount (KES) *</label><input type="number" name="amount" step="0.01" min="0" required placeholder="3500.00"></div>
          <div class="form-group"><label class="field-label">Tax (KES)</label><input type="number" name="tax" step="0.01" min="0" placeholder="0.00" value="0"></div>
          <div class="form-group"><label class="field-label">Issue Date</label><input type="date" name="issue_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label class="field-label">Due Date *</label><input type="date" name="due_date" required></div>
          <div class="form-group"><label class="field-label">Period Start</label><input type="date" name="period_start" value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label class="field-label">Period End</label><input type="date" name="period_end"></div>
          <div class="form-group"><label class="field-label">Status</label>
            <select name="status"><option value="unpaid">Unpaid</option><option value="sent">Sent</option><option value="paid">Paid</option><option value="overdue">Overdue</option><option value="void">Void</option></select>
          </div>
          <div class="form-group"><label class="field-label">Note</label><input type="text" name="note" placeholder="Optional note"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-inv-add')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Invoice</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Invoice Modal -->
<div class="modal-overlay" id="m-inv-edit">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Invoice</div>
      <button class="modal-close" onclick="closeModal('m-inv-edit')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="invoice_edit">
      <?= csrf_token_field() ?>
      <input type="hidden" name="id" id="ei-id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label class="field-label">Amount (KES)</label><input type="number" name="amount" id="ei-amount" step="0.01" min="0"></div>
          <div class="form-group"><label class="field-label">Tax (KES)</label><input type="number" name="tax" id="ei-tax" step="0.01" min="0" value="0"></div>
          <div class="form-group"><label class="field-label">Due Date</label><input type="date" name="due_date" id="ei-due"></div>
          <div class="form-group"><label class="field-label">Status</label>
            <select name="status" id="ei-status"><option value="unpaid">Unpaid</option><option value="sent">Sent</option><option value="paid">Paid</option><option value="overdue">Overdue</option><option value="void">Void</option></select>
          </div>
          <div class="form-group full"><label class="field-label">Note</label><input type="text" name="note" id="ei-note"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-inv-edit')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Invoice</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>


<?php // ════════════ PAYMENTS ════════════
if ($active_tab === 'payments'): ?>
<div class="page-header">
  <div>
    <div class="page-eyebrow">Finance</div>
    <div class="page-title">Payments</div>
    <div class="page-sub"><?= count($payments) ?> payments · Payments are tied to invoices · Full invoice amount only</div>
  </div>
  <div class="page-actions">
    <?php if (!empty($unpaid_invoices)): ?>
    <button class="btn btn-primary" onclick="openModal('m-pay-add')">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Record Payment
    </button>
    <?php else: ?>
    <div style="font-size:13px;color:var(--green);display:flex;align-items:center;gap:6px;"><div class="status-dot"></div>All invoices paid</div>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($unpaid_invoices)): ?>
<div class="info-box">
  <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
  <strong><?= count($unpaid_invoices) ?> invoice(s) awaiting payment</strong> totalling <strong><?= money($total_outstanding) ?></strong>. Select an invoice below to record full payment — the invoice and client status will be updated automatically.
</div>
<?php endif; ?>

<div class="card"><div class="card-body-flush"><div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Client</th><th>Invoice #</th><th>Amount Paid</th><th>Method</th><th>Reference</th><th>Date</th><th>Note</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if(empty($payments)): ?><tr><td colspan="10"><div class="empty-state"><svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg><h3>No payments recorded</h3><p>Record payment against an invoice.</p></div></td></tr><?php endif; ?>
      <?php foreach($payments as $pay): ?>
      <tr>
        <td class="td-mono">#<?= $pay['id'] ?></td>
        <td class="td-name"><?= esc($pay['business_name']) ?></td>
        <td class="td-mono"><?= esc($pay['invoice_no']??'—') ?></td>
        <td class="td-mono" style="color:var(--green);"><?= money($pay['amount']) ?></td>
        <td><span class="badge badge-info"><?= strtoupper(esc($pay['payment_method']??'mpesa')) ?></span></td>
        <td class="td-mono"><?= esc($pay['reference']??'—') ?></td>
        <td class="td-muted"><?= esc($pay['received_by_name']??'—') ?></td>
        <td class="td-mono"><?= esc($pay['payment_date']??'—') ?></td>
        <td class="td-muted" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= esc($pay['note']??'—') ?></td>
        <td>
          <?php if($pay['confirmed']): ?>
            <span class="badge badge-active">✓ Confirmed</span>
          <?php else: ?>
            <span class="badge badge-read_only">⏳ Pending</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="td-actions">
            <?php if(!$pay['confirmed']): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Confirm this payment? Only do this after verifying in M-PESA/bank.');">
              <input type="hidden" name="_action"    value="payment_confirm">
              <input type="hidden" name="id"         value="<?= $pay['id'] ?>">
              <input type="hidden" name="invoice_id" value="<?= (int)($pay['invoice_id'] ?? 0) ?>">
              <input type="hidden" name="client_id"  value="<?= (int)$pay['client_id'] ?>">
              <?= csrf_token_field() ?>
              <button type="submit" class="btn btn-success btn-sm">✓ Confirm</button>
            </form>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Delete this payment record?');" style="display:inline;">
              <input type="hidden" name="_action" value="payment_delete">
              <input type="hidden" name="id"      value="<?= $pay['id'] ?>">
              <?= csrf_token_field() ?>
              <button type="submit" class="btn btn-danger btn-sm btn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<!-- Record Payment Modal — invoice-driven, full amount only -->
<div class="modal-overlay" id="m-pay-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Record Payment</div>
      <button class="modal-close" onclick="closeModal('m-pay-add')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="payment_add">
      <?= csrf_token_field() ?>
      <div class="modal-body">
        <div class="info-box" style="margin-bottom:18px;">
          <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Select an unpaid invoice — the amount is set automatically and the full invoice total will be marked as paid.
        </div>
        <div class="form-grid">
          <div class="form-group full">
            <label class="field-label">Select Invoice (Unpaid) *</label>
            <select name="invoice_id" required id="pay-inv-sel" onchange="onInvoiceSelect(this)">
              <option value="">Choose invoice…</option>
              <?php foreach($unpaid_invoices as $inv): ?>
              <option value="<?= $inv['id'] ?>" data-total="<?= $inv['total'] ?>" data-client="<?= esc($inv['business_name']) ?>" data-invno="<?= esc($inv['invoice_no']) ?>">
                <?= esc($inv['invoice_no']) ?> — <?= esc($inv['business_name']) ?> — <?= money($inv['total']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="pay-summary" id="pay-inv-summary" style="display:none;">
          <div class="pay-summary-row"><span style="color:var(--tx3);">Client</span><span id="ps-client" style="font-weight:600;"></span></div>
          <div class="pay-summary-row"><span style="color:var(--tx3);">Invoice #</span><span id="ps-invno" class="td-mono"></span></div>
          <div class="pay-summary-row total"><span>Amount Due</span><span id="ps-total"></span></div>
        </div>

        <div class="form-grid" style="margin-top:16px;">
          <div class="form-group"><label class="field-label">Payment Method</label>
            <select name="payment_method">
              <option value="mpesa">M-Pesa</option>
              <option value="bank">Bank Transfer</option>
              <option value="cash">Cash</option>
              <option value="cheque">Cheque</option>
            </select>
          </div>
          <div class="form-group"><label class="field-label">Reference / M-Pesa Code</label><input type="text" name="reference" placeholder="QHX7K2A3…"></div>
          <div class="form-group"><label class="field-label">Payment Date</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label class="field-label">Note (optional)</label><input type="text" name="note" placeholder="e.g. Renewal June 2025"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-pay-add')">Cancel</button>
        <button type="submit" class="btn btn-primary">
          <svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
          Confirm Full Payment
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>


<?php // ════════════ TICKETS ════════════
if ($active_tab === 'tickets'): ?>
<div class="page-header">
  <div>
    <div class="page-eyebrow">Support</div>
    <div class="page-title">Support Tickets</div>
    <div class="page-sub"><?= count($tickets) ?> tickets · <?= $ov['open_tickets'] ?> open</div>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" onclick="openModal('m-ticket-add')">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New Ticket
    </button>
  </div>
</div>

<div class="card"><div class="card-body-flush"><div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Client</th><th>Subject</th><th>Priority</th><th>Assigned</th><th>Replies</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
      <?php if(empty($tickets)): ?><tr><td colspan="9"><div class="empty-state"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><h3>No tickets</h3><p>Support inbox is clear.</p></div></td></tr><?php endif; ?>
      <?php foreach($tickets as $t): ?>
      <tr>
        <td class="td-mono">#<?= $t['id'] ?></td>
        <td class="td-name"><?= esc($t['business_name']) ?></td>
        <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= esc($t['subject']) ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;">
            <span class="prio-dot prio-<?= strtolower($t['priority']??'medium') ?>"></span>
            <span style="font-size:12px;color:var(--tx2);"><?= ucfirst($t['priority']??'medium') ?></span>
          </div>
        </td>
        <td class="td-muted"><?= esc($t['assigned_name']??'Unassigned') ?></td>
        <td class="td-mono" style="text-align:center;"><?= (int)$t['reply_count'] ?></td>
        <td><span class="badge badge-<?= strtolower($t['status']??'open') ?>"><?= ucfirst($t['status']??'open') ?></span></td>
        <td class="td-mono"><?= date('d M Y',strtotime($t['created_at'])) ?></td>
        <td>
          <div class="td-actions">
            <button class="btn btn-ghost btn-sm" onclick="openReplyTicket(<?= htmlspecialchars(json_encode($t)) ?>)">Reply</button>
            <form method="POST" onsubmit="return confirm('Delete ticket?');" style="display:inline;">
              <input type="hidden" name="_action" value="ticket_delete">
              <input type="hidden" name="id" value="<?= $t['id'] ?>">
              <?= csrf_token_field() ?>
              <button type="submit" class="btn btn-danger btn-sm btn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<!-- Add Ticket Modal -->
<div class="modal-overlay" id="m-ticket-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>New Ticket</div>
      <button class="modal-close" onclick="closeModal('m-ticket-add')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="ticket_add">
      <?= csrf_token_field() ?>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label class="field-label">Client *</label>
            <select name="client_id" required><option value="">Select client…</option>
              <?php foreach($clients as $c): ?><option value="<?= $c['id'] ?>"><?= esc($c['business_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="field-label">Assign To</label>
            <select name="assigned_to"><option value="">Unassigned</option>
              <?php foreach($admins as $adm): ?><option value="<?= $adm['id'] ?>"><?= esc($adm['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group full"><label class="field-label">Subject *</label><input type="text" name="subject" required placeholder="Brief description…"></div>
          <div class="form-group"><label class="field-label">Priority</label>
            <select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-ticket-add')">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Ticket</button>
      </div>
    </form>
  </div>
</div>

<!-- Reply Ticket Modal -->
<div class="modal-overlay" id="m-ticket-reply">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Reply to Ticket</div>
      <button class="modal-close" onclick="closeModal('m-ticket-reply')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="ticket_reply">
      <?= csrf_token_field() ?>
      <input type="hidden" name="ticket_id" id="et-id">
      <div class="modal-body">
        <div id="ticket-subject-box" style="background:var(--ink3);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:var(--tx2);"></div>
        <div class="form-grid">
          <div class="form-group"><label class="field-label">Update Status</label>
            <select name="status" id="et-status"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select>
          </div>
          <div class="form-group"><label class="field-label">Priority</label>
            <select name="priority" id="et-priority"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select>
          </div>
          <div class="form-group full"><label class="field-label">Reply Message</label><textarea name="message" id="et-message" placeholder="Your response to the client…" style="min-height:110px;"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-ticket-reply')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Response</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>


<?php // ════════════ ERRORS ════════════
if ($active_tab === 'errors'): ?>
<div class="page-header">
  <div>
    <div class="page-eyebrow">System</div>
    <div class="page-title">Error Log</div>
    <div class="page-sub"><?= count($errors) ?> entries · <?= $ov['errors_24h'] ?> critical/error in last 24h</div>
  </div>
  <div class="page-actions">
    <form method="POST" onsubmit="return confirm('Clear all critical/error entries?');">
      <input type="hidden" name="_action" value="errors_clear">
      <?= csrf_token_field() ?>
      <button type="submit" class="btn btn-danger"><svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg>Clear Critical</button>
    </form>
  </div>
</div>

<div class="card"><div class="card-body-flush"><div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Level</th><th>Message</th><th>File</th><th>Line</th><th>Method</th><th>IP</th><th>Timestamp</th><th>Del</th></tr></thead>
    <tbody>
      <?php if(empty($errors)): ?><tr><td colspan="9"><div class="empty-state"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg><h3>No errors logged</h3><p>System is clean.</p></div></td></tr><?php endif; ?>
      <?php foreach($errors as $e):
        $elv=strtolower($e['level']??'info');
        $ecolor=match($elv){'critical','error'=>'var(--red)','warning'=>'var(--amber)',default=>'var(--blue)'}; ?>
      <tr>
        <td class="td-mono"><?= $e['id'] ?></td>
        <td><span style="font-family:'DM Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;color:<?= $ecolor ?>;"><?= esc($e['level']??'info') ?></span></td>
        <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--tx2);font-size:13px;" title="<?= esc($e['message']??'') ?>"><?= esc($e['message']??'—') ?></td>
        <td class="td-mono" style="max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= esc($e['file']??'—') ?></td>
        <td class="td-mono"><?= esc($e['line']??'—') ?></td>
        <td class="td-mono"><?= esc($e['method']??'—') ?></td>
        <td class="td-mono"><?= esc($e['ip_address']??'—') ?></td>
        <td class="td-mono" style="font-size:11px;"><?= esc($e['created_at']??'—') ?></td>
        <td>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="_action" value="error_delete">
            <input type="hidden" name="id" value="<?= $e['id'] ?>">
            <?= csrf_token_field() ?>
            <button type="submit" class="btn btn-danger btn-sm btn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>
<?php endif; ?>


<?php // ════════════ ADMINS ════════════
if ($active_tab === 'admins'): ?>
<div class="page-header">
  <div>
    <div class="page-eyebrow">System</div>
    <div class="page-title">Admin Users</div>
    <div class="page-sub"><?= count($admins) ?> admin accounts</div>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" onclick="openModal('m-admin-add')">
      <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>Add Admin
    </button>
  </div>
</div>

<div class="card"><div class="card-body-flush"><div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Last Login</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
      <?php foreach($admins as $adm): ?>
      <tr>
        <td class="td-mono">#<?= $adm['id'] ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:9px;">
            <div style="width:28px;height:28px;border-radius:7px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--blue);flex-shrink:0;"><?= strtoupper(substr($adm['name'],0,2)) ?></div>
            <span class="td-name"><?= esc($adm['name']) ?></span>
          </div>
        </td>
        <td class="td-mono"><?= esc($adm['username']??'—') ?></td>
        <td class="td-muted"><?= esc($adm['email']) ?></td>
        <td class="td-muted"><?= esc($adm['phone']??'—') ?></td>
        <td><span class="badge badge-<?= strtolower($adm['role']??'support') ?>"><?= ucfirst(esc($adm['role']??'support')) ?></span></td>
        <td><span class="badge badge-<?= $adm['is_active']?'active':'suspended' ?>"><?= $adm['is_active']?'Active':'Inactive' ?></span></td>
        <td class="td-mono" style="font-size:11px;"><?= $adm['last_login']?date('d M H:i',strtotime($adm['last_login'])):'—' ?></td>
        <td class="td-mono"><?= date('d M Y',strtotime($adm['created_at'])) ?></td>
        <td>
          <div class="td-actions">
            <button class="btn btn-ghost btn-sm btn-icon" onclick="openEditAdmin(<?= htmlspecialchars(json_encode($adm)) ?>)">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </button>
            <?php if($adm['id']!=$_SESSION['sa_id']): ?>
            <form method="POST" onsubmit="return confirm('Delete this admin?');" style="display:inline;">
              <input type="hidden" name="_action" value="admin_delete">
              <input type="hidden" name="id" value="<?= $adm['id'] ?>">
              <?= csrf_token_field() ?>
              <button type="submit" class="btn btn-danger btn-sm btn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M9 6V4h6v2"/></svg></button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<!-- Add Admin Modal -->
<div class="modal-overlay" id="m-admin-add">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>New Admin User</div>
      <button class="modal-close" onclick="closeModal('m-admin-add')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="admin_add">
      <?= csrf_token_field() ?>
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label class="field-label">Full Name *</label><input type="text" name="name" required placeholder="Jane Doe"></div>
          <div class="form-group"><label class="field-label">Username *</label><input type="text" name="username" required placeholder="janedoe"></div>
          <div class="form-group"><label class="field-label">Email *</label><input type="email" name="email" required placeholder="jane@nymixtech.com"></div>
          <div class="form-group"><label class="field-label">Phone</label><input type="tel" name="phone" placeholder="+254 700 000 000"></div>
          <div class="form-group"><label class="field-label">Password *</label><input type="password" name="password" required placeholder="Min 8 characters"></div>
          <div class="form-group"><label class="field-label">Role</label>
            <select name="role"><option value="support">Support</option><option value="billing">Billing</option><option value="superadmin">Super Admin</option></select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-admin-add')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create Admin</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Admin Modal -->
<div class="modal-overlay" id="m-admin-edit">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit Admin</div>
      <button class="modal-close" onclick="closeModal('m-admin-edit')"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <form method="POST">
      <input type="hidden" name="_action" value="admin_edit">
      <?= csrf_token_field() ?>
      <input type="hidden" name="id" id="ea-id">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label class="field-label">Full Name</label><input type="text" name="name" id="ea-name" required></div>
          <div class="form-group"><label class="field-label">Phone</label><input type="tel" name="phone" id="ea-phone"></div>
          <div class="form-group"><label class="field-label">Role</label>
            <select name="role" id="ea-role"><option value="support">Support</option><option value="billing">Billing</option><option value="superadmin">Super Admin</option></select>
          </div>
          <div class="form-group"><label class="field-label">Active</label>
            <select name="is_active" id="ea-active"><option value="1">Active</option><option value="0">Inactive</option></select>
          </div>
          <div class="form-group full"><label class="field-label">New Password (leave blank to keep)</label><input type="password" name="new_password" placeholder="••••••••"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('m-admin-edit')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Admin</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($active_tab === 'security'): ?>

<div class="page-header">
  <div>
    <div class="page-eyebrow">Security Operations Centre</div>
    <div class="page-title">Security</div>
    <div class="page-sub">Login attempts · Active sessions · Brute-force alerts · Geo-location · Audit trail</div>
  </div>
  <div class="page-actions">
    <?php if (!empty($sec_alerts) && array_filter($sec_alerts, fn($a)=>!$a['is_resolved'])): ?>
    <form method="POST">
      <input type="hidden" name="_action" value="alert_resolve_all">
      <?= csrf_token_field() ?>
      <button type="submit" class="btn btn-ghost">✓ Resolve All Alerts</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:22px;">
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--blue-d);"><svg viewBox="0 0 24 24" stroke="var(--blue)" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg></div>
    <div class="sc-label">Logins (24h)</div>
    <div class="sc-value"><?= number_format($soc_sum['logins_24h']) ?></div>
    <div class="sc-change up"><?= $soc_sum['successes_24h'] ?> success</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--red-d);"><svg viewBox="0 0 24 24" stroke="var(--red)" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
    <div class="sc-label">Failed Attempts (24h)</div>
    <div class="sc-value" style="color:<?= $soc_sum['failures_24h']>0?'var(--red)':'var(--tx)' ?>;"><?= number_format($soc_sum['failures_24h']) ?></div>
    <div class="sc-change <?= $soc_sum['failures_24h']>0?'down':'' ?>"><?= $soc_sum['suspicious_ips'] ?> suspicious IPs</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--green-d);"><svg viewBox="0 0 24 24" stroke="var(--green)" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="23 11 17 11"/></svg></div>
    <div class="sc-label">Active Sessions</div>
    <div class="sc-value"><?= number_format($soc_sum['active_sessions']) ?></div>
    <div class="sc-change">Right now</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:<?= $soc_sum['critical_alerts']>0?'var(--red-d)':'var(--amber-d)' ?>;"><svg viewBox="0 0 24 24" stroke="<?= $soc_sum['critical_alerts']>0?'var(--red)':'var(--amber)' ?>" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
    <div class="sc-label">Open Alerts</div>
    <div class="sc-value" style="color:<?= $soc_sum['open_alerts']>0?'var(--red)':'var(--green)' ?>;"><?= number_format($soc_sum['open_alerts']) ?></div>
    <div class="sc-change <?= $soc_sum['critical_alerts']>0?'down':'' ?>"><?= $soc_sum['critical_alerts'] ?> critical</div>
  </div>
  <div class="stat-card">
    <div class="sc-icon" style="background:var(--purple-d);"><svg viewBox="0 0 24 24" stroke="var(--purple)" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
    <div class="sc-label">Audit Events (24h)</div>
    <div class="sc-value"><?= number_format($soc_sum['audit_events_24h']) ?></div>
    <div class="sc-change">Admin actions logged</div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:20px;">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>Active Sessions
        <?php if (!empty($active_sessions)): ?><span style="background:var(--green-d);color:var(--green);font-family:'DM Mono',monospace;font-size:10px;padding:2px 8px;border-radius:10px;"><?= count($active_sessions) ?> online</span><?php endif; ?>
      </div>
    </div>
    <div class="card-body-flush">
      <?php if (empty($active_sessions)): ?>
      <div class="empty-state" style="padding:30px 20px;"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><h3>No active sessions</h3><p>Sessions appear here once admins log in.</p></div>
      <?php else: ?>
      <?php foreach ($active_sessions as $sess): $idle=round((time()-strtotime($sess['last_active']))/60); ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line);">
        <div style="position:relative;flex-shrink:0;">
          <div style="width:36px;height:36px;border-radius:10px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--blue);"><?= strtoupper(substr($sess['admin_name'],0,2)) ?></div>
          <div style="position:absolute;bottom:-2px;right:-2px;width:9px;height:9px;border-radius:50%;background:var(--green);border:2px solid var(--ink2);"></div>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:600;"><?= esc($sess['admin_name']) ?></div>
          <div style="font-size:11px;color:var(--tx3);"><?= esc($sess['ip_address']) ?><?php if($sess['country']): ?> · 📍 <?= esc($sess['city']?$sess['city'].', '.$sess['country']:$sess['country']) ?><?php endif; ?></div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <span class="badge badge-<?= strtolower($sess['admin_role']) ?>"><?= ucfirst($sess['admin_role']) ?></span>
          <div style="font-family:'DM Mono',monospace;font-size:10px;color:var(--tx3);margin-top:3px;"><?= $idle ?>m ago</div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <div class="card-title"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Suspicious IPs (24h)
        <?php if (!empty($bf_ips)): ?><span style="background:var(--red-d);color:var(--red);font-family:'DM Mono',monospace;font-size:10px;padding:2px 8px;border-radius:10px;"><?= count($bf_ips) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="card-body-flush">
      <?php if (empty($bf_ips)): ?>
      <div class="empty-state" style="padding:30px 20px;"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg><h3>No suspicious IPs</h3><p>All login traffic looks normal.</p></div>
      <?php else: ?>
      <?php foreach (array_slice($bf_ips,0,8) as $bfip): $risk=$bfip['attempt_count']>=10?['critical','var(--red)']:['high','var(--amber)']; ?>
      <div style="display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid var(--line);">
        <div style="width:34px;height:34px;border-radius:9px;background:var(--red-d);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:700;"><?= esc($bfip['ip_address']) ?></div>
          <div style="font-size:11px;color:var(--tx3);"><?= (int)$bfip['distinct_emails'] ?> emails · <?= esc($bfip['countries']??'—') ?></div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
          <div style="font-family:'DM Mono',monospace;font-size:16px;font-weight:700;color:<?= $risk[1] ?>;"><?= $bfip['attempt_count'] ?></div>
          <div style="font-size:10px;color:<?= $risk[1] ?>;text-transform:uppercase;letter-spacing:1px;"><?= $risk[0] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <div class="card-title"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>Security Alerts
      <?php $ua_cnt=count(array_filter($sec_alerts,fn($a)=>!$a['is_resolved'])); ?>
      <?php if($ua_cnt): ?><span style="background:var(--red-d);color:var(--red);font-family:'DM Mono',monospace;font-size:10px;padding:2px 8px;border-radius:10px;"><?= $ua_cnt ?> open</span><?php endif; ?>
    </div>
  </div>
  <div class="card-body-flush"><div class="table-wrap">
    <?php if(empty($sec_alerts)): ?>
    <div class="empty-state" style="padding:40px 20px;"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg><h3>No security alerts</h3><p>System is clean.</p></div>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Type</th><th>Severity</th><th>IP</th><th>Admin / Email</th><th>Description</th><th>Time</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach($sec_alerts as $al):
        $sev_color=match($al['severity']){'critical'=>'var(--red)','high'=>'var(--amber)','medium'=>'var(--cyan)',default=>'var(--tx3)'};
        $type_labels=['brute_force'=>'🔨 Brute Force','credential_spray'=>'💧 Cred Spray','csrf_violation'=>'🛡 CSRF','rate_limit'=>'⚡ Rate Limit','session_hijack'=>'👤 Session Hijack','impossible_travel'=>'✈️ Impossible Travel','new_country'=>'🌍 New Country','suspicious_ua'=>'🤖 Suspicious UA'];
        $type_lbl=$type_labels[$al['alert_type']]??ucfirst($al['alert_type']);
      ?>
      <tr style="<?= !$al['is_resolved']?'background:rgba(240,64,96,0.02);':'opacity:0.6;' ?>">
        <td class="td-mono">#<?= $al['id'] ?></td>
        <td><span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:600;white-space:nowrap;"><?= $type_lbl ?></span></td>
        <td><span style="font-family:'DM Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;color:<?= $sev_color ?>;"><?= $al['severity'] ?></span></td>
        <td class="td-mono"><?= esc($al['ip_address']??'—') ?></td>
        <td class="td-muted" style="font-size:12px;"><?= esc($al['admin_name']??$al['email']??'—') ?></td>
        <td style="max-width:260px;font-size:12px;color:var(--tx2);"><?= esc($al['description']) ?></td>
        <td class="td-mono" style="font-size:11px;white-space:nowrap;"><?= date('d M H:i',strtotime($al['created_at'])) ?></td>
        <td><?php if($al['is_resolved']): ?><span class="badge badge-active">✓ Resolved</span><?php else: ?><span class="badge badge-suspended">⚠ Open</span><?php endif; ?></td>
        <td>
          <?php if(!$al['is_resolved']): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="_action" value="alert_resolve">
            <input type="hidden" name="id" value="<?= $al['id'] ?>">
            <?= csrf_token_field() ?>
            <button type="submit" class="btn btn-success btn-sm">Resolve</button>
          </form>
          <?php else: ?><span style="font-size:11px;color:var(--tx3);"><?= esc($al['resolved_by_name']??'—') ?></span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div></div>
</div>

<div class="grid-2" style="margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><div class="card-title"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>Login Geo-location (48h)</div></div>
    <div class="card-body">
      <?php if(empty($geo_countries)): ?>
      <div class="empty-state" style="padding:24px 0;"><h3>No geo data yet</h3><p>Appears after first login attempt.</p></div>
      <?php else:
        $by_country=[];
        foreach($geo_countries as $gc){$c=$gc['country']??'Unknown';if(!isset($by_country[$c]))$by_country[$c]=['success'=>0,'failure'=>0];$by_country[$c][$gc['result']]=(int)$gc['cnt'];}
        arsort($by_country);
        $max_total=max(array_map(fn($v)=>$v['success']+$v['failure'],$by_country))?:1;
      ?>
      <?php foreach($by_country as $country=>$counts): $total=$counts['success']+$counts['failure'];$pct=round($total/$max_total*100);$is_ke=($country==='Kenya'); ?>
      <div style="margin-bottom:13px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
          <span style="color:<?= $is_ke?'var(--tx)':'var(--tx2)' ?>;font-weight:<?= $is_ke?'600':'400' ?>;"><?= $is_ke?'🇰🇪 ':'🌍 ' ?><?= esc($country) ?></span>
          <span style="font-family:'DM Mono',monospace;color:var(--tx3);"><span style="color:var(--green);"><?= $counts['success'] ?>✓</span><?php if($counts['failure']): ?> <span style="color:var(--red);"><?= $counts['failure'] ?>✗</span><?php endif; ?></span>
        </div>
        <div class="prog-bar"><div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $counts['failure']>0?'var(--amber)':'var(--green)' ?>;"></div></div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Login Activity (24h)</div></div>
    <div class="card-body"><div class="chart-wrap"><canvas id="loginActivityChart"></canvas></div></div>
  </div>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <div class="card-title"><svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>Login Attempts Log</div>
    <span style="font-size:12px;color:var(--tx3);">Last 200 entries</span>
  </div>
  <div class="card-body-flush"><div class="table-wrap">
    <?php if(empty($login_log)): ?>
    <div class="empty-state" style="padding:30px 20px;"><h3>No login attempts recorded yet</h3><p>Attempts appear once logging code is integrated.</p></div>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Result</th><th>Email</th><th>Admin</th><th>IP Address</th><th>Country / City</th><th>ISP</th><th>Reason</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach($login_log as $ll): ?>
      <tr>
        <td class="td-mono"><?= $ll['id'] ?></td>
        <td><?php if($ll['result']==='success'): ?><span class="badge badge-active">✓ Success</span><?php elseif($ll['result']==='blocked'): ?><span class="badge badge-suspended">⛔ Blocked</span><?php else: ?><span class="badge badge-suspended">✗ Failed</span><?php endif; ?></td>
        <td class="td-mono" style="font-size:12px;"><?= esc($ll['email']) ?></td>
        <td class="td-muted"><?= esc($ll['admin_name']??'—') ?></td>
        <td><span style="font-family:'DM Mono',monospace;font-size:12px;color:<?= $ll['result']==='failure'?'var(--red)':'var(--tx2)' ?>;"><?= esc($ll['ip_address']) ?></span></td>
        <td style="font-size:12px;"><?php if($ll['country']): ?><?= $ll['country']==='Kenya'?'🇰🇪 ':'🌍 ' ?><?= esc($ll['city']?$ll['city'].', '.$ll['country']:$ll['country']) ?><?php else: ?><span class="td-muted">—</span><?php endif; ?></td>
        <td class="td-muted" style="font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= esc($ll['isp']??'—') ?></td>
        <td class="td-muted" style="font-size:12px;"><?= esc($ll['failure_reason']??'—') ?></td>
        <td class="td-mono" style="font-size:11px;white-space:nowrap;"><?= date('d M H:i:s',strtotime($ll['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div></div>
</div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <div class="card-title"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>Audit Log — Who Did What</div>
    <span style="font-size:12px;color:var(--tx3);"><?= $soc_sum['audit_events_24h'] ?> events in last 24h</span>
  </div>
  <div class="card-body-flush"><div class="table-wrap">
    <?php if(empty($audit_entries)): ?>
    <div class="empty-state" style="padding:30px 20px;"><h3>No audit entries yet</h3><p>Add audit() calls to CRUD actions to start logging.</p></div>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Admin</th><th>Role</th><th>Action</th><th>Entity</th><th>Label</th><th>IP</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach($audit_entries as $ae):
        $action_colors=['client_add'=>'var(--green)','client_edit'=>'var(--blue)','client_delete'=>'var(--red)','sub_add'=>'var(--cyan)','invoice_mark_paid'=>'var(--green)','payment_confirm'=>'var(--green)','admin_add'=>'var(--gold)','admin_delete'=>'var(--red)','logout'=>'var(--tx3)','alert_resolve'=>'var(--purple)'];
        $ac=$action_colors[$ae['action']]??'var(--tx2)';
      ?>
      <tr>
        <td class="td-mono"><?= $ae['id'] ?></td>
        <td><div style="display:flex;align-items:center;gap:7px;"><div style="width:26px;height:26px;border-radius:7px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--blue);flex-shrink:0;"><?= strtoupper(substr($ae['admin_name']??'?',0,2)) ?></div><span style="font-size:13px;font-weight:600;"><?= esc($ae['admin_name']??'System') ?></span></div></td>
        <td><span class="badge badge-<?= strtolower($ae['admin_role']??'support') ?>"><?= ucfirst($ae['admin_role']??'—') ?></span></td>
        <td><span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:<?= $ac ?>;"><?= esc($ae['action']) ?></span></td>
        <td class="td-mono" style="font-size:11px;"><?= esc($ae['entity_type']??'—') ?></td>
        <td style="max-width:160px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= esc($ae['entity_label']??'—') ?></td>
        <td class="td-mono" style="font-size:11px;"><?= esc($ae['ip_address']??'—') ?></td>
        <td class="td-mono" style="font-size:11px;white-space:nowrap;"><?= date('d M H:i',strtotime($ae['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div></div>
</div>

<script>
(function(){
  var el=document.getElementById('loginActivityChart');
  if(!el)return;
  var rawLog=<?= json_encode(array_map(fn($l)=>['hour'=>(int)date('H',strtotime($l['created_at'])),'result'=>$l['result']],$login_log)) ?>;
  var success=new Array(24).fill(0),failure=new Array(24).fill(0);
  rawLog.forEach(function(r){if(r.result==='success')success[r.hour]++;else failure[r.hour]++;});
  var labels=Array.from({length:24},(_,i)=>i.toString().padStart(2,'0')+':00');
  new Chart(el,{
    type:'bar',
    data:{labels:labels,datasets:[{label:'Success',data:success,backgroundColor:'rgba(14,200,122,0.55)',borderRadius:4},{label:'Failed',data:failure,backgroundColor:'rgba(240,64,96,0.55)',borderRadius:4}]},
    options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{display:true,labels:{color:'#7A93B8',font:{family:"'DM Mono',monospace",size:10}}},tooltip:{backgroundColor:'#090E1B',borderColor:'rgba(255,255,255,0.1)',borderWidth:1,padding:10,titleColor:'#7A93B8',bodyColor:'#E2EBF8'}},scales:{x:{grid:{color:'rgba(255,255,255,0.03)'},ticks:{color:'#3E546F',font:{family:"'DM Mono',monospace",size:9},maxTicksLimit:12}},y:{grid:{color:'rgba(255,255,255,0.03)'},ticks:{color:'#3E546F',font:{family:"'DM Mono',monospace",size:10},stepSize:1}}}}
  });
})();
</script>

<?php endif; // end security tab ?>

</div></main>

<!-- ═══════════════════ JAVASCRIPT ═══════════════════ -->
<script>
// ── SIDEBAR MOBILE ────────────────────────────────────────
function toggleSidebar() {
  var sb = document.getElementById('sidebar');
  var bd = document.getElementById('sidebar-backdrop');
  var open = sb.classList.contains('open');
  if (open) { sb.classList.remove('open'); bd.classList.remove('open'); document.body.style.overflow=''; }
  else       { sb.classList.add('open');    bd.classList.add('open');    document.body.style.overflow='hidden'; }
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebar-backdrop').classList.remove('open');
  document.body.style.overflow='';
}
// Close sidebar when a nav link is tapped on mobile
document.querySelectorAll('.nav-item').forEach(function(el){
  el.addEventListener('click', function(){ if(window.innerWidth<=768) closeSidebar(); });
});

// ── MODAL HELPERS ─────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
document.querySelectorAll('.modal-overlay').forEach(function(o) { o.addEventListener('click',function(e){if(e.target===o)closeModal(o.id);}); });
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.querySelectorAll('.modal-overlay.open').forEach(function(m){closeModal(m.id);});});

// ── FIELD HELPERS ─────────────────────────────────────────
function sv(id,val){var el=document.getElementById(id);if(el)el.value=val||'';}
function sc(id,val){var el=document.getElementById(id);if(el)el.checked=!!parseInt(val);}
function sh(id,html){var el=document.getElementById(id);if(el)el.innerHTML=html||'';}
function escH(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s||''));return d.innerHTML;}
function fmtKES(n){return 'KES '+parseFloat(n||0).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});}

// ── CLIENT ────────────────────────────────────────────────
function openEditClient(c) {
  sv('ec-id', c.id); sv('ec-name', c.business_name); sv('ec-owner', c.owner_name);
  sv('ec-phone', c.phone); sv('ec-email', c.email); sv('ec-kra', c.kra_pin);
  sv('ec-address', c.address); sv('ec-status', c.status); sv('ec-notes', c.notes);
  var prev = document.getElementById('ec-logo-preview');
  var img  = document.getElementById('ec-logo-img');
  if (c.logo && prev && img) { img.src = c.logo; prev.style.display = 'block'; }
  else if (prev) prev.style.display = 'none';
  openModal('m-client-edit');
}

// ── PLAN ──────────────────────────────────────────────────
function openEditPlan(p) {
  sv('ep-id',p.id); sv('ep-name',p.name); sv('ep-price',p.base_price);
  sv('ep-addon',p.branch_addon_price); sv('ep-maxu',p.max_users||'');
  sv('ep-maxp',p.max_products||''); sv('ep-features',p.features||'[]');
  var a=document.getElementById('ep-active'); if(a)a.checked=!!parseInt(p.is_active);
  openModal('m-plan-edit');
}

// ── SUBSCRIPTION TOTAL CALC ───────────────────────────────
document.getElementById('sub-plan-sel') && document.getElementById('sub-plan-sel').addEventListener('change', calcSubTotal);
function calcSubTotal() {
  var sel = document.getElementById('sub-plan-sel');
  if (!sel || !sel.value) { document.getElementById('sub-summary').style.display='none'; return; }
  var opt   = sel.options[sel.selectedIndex];
  var base  = parseFloat(opt.dataset.base||0);
  var addon = parseFloat(opt.dataset.addon||0);
  var bc    = parseInt(document.getElementById('sub-bc').value||1);
  var cycle = document.getElementById('sub-cycle').value;
  var addonAmt = (bc>1)?(bc-1)*addon:0;
  var mo    = base + addonAmt;
  var mult  = cycle==='annual'?12:(cycle==='quarterly'?3:1);
  var inv   = mo * mult;
  document.getElementById('ss-base').textContent  = fmtKES(base);
  document.getElementById('ss-addon').textContent = fmtKES(addonAmt);
  document.getElementById('ss-mo').textContent    = fmtKES(mo);
  document.getElementById('ss-inv').textContent   = fmtKES(inv);
  document.getElementById('sub-summary').style.display = 'block';
}

// ── SUBSCRIPTION EDIT ─────────────────────────────────────
function openEditSub(s) {
  sv('es-id',s.id); sv('es-end',s.end_date); sv('es-bc',s.branch_count);
  sv('es-status',s.status); sc('es-ar',s.auto_renew);
  openModal('m-sub-edit');
}

// ── INVOICE ───────────────────────────────────────────────
function openEditInvoice(inv) {
  sv('ei-id',inv.id); sv('ei-amount',inv.amount); sv('ei-tax',inv.tax);
  sv('ei-due',inv.due_date); sv('ei-status',inv.status); sv('ei-note',inv.note);
  openModal('m-inv-edit');
}

// ── PAYMENT — invoice select shows summary ─────────────────
function onInvoiceSelect(sel) {
  var s = document.getElementById('pay-inv-summary');
  if (!sel.value) { s.style.display='none'; return; }
  var opt = sel.options[sel.selectedIndex];
  document.getElementById('ps-client').textContent = opt.dataset.client || '';
  document.getElementById('ps-invno').textContent  = opt.dataset.invno  || '';
  document.getElementById('ps-total').textContent  = fmtKES(opt.dataset.total || 0);
  s.style.display = 'block';
}

// ── TICKET ────────────────────────────────────────────────
function openReplyTicket(t) {
  sv('et-id',t.id); sv('et-status',t.status); sv('et-priority',t.priority); sv('et-message','');
  sh('ticket-subject-box','<strong style="color:var(--tx);display:block;margin-bottom:4px;">[#'+t.id+'] '+escH(t.subject)+'</strong><span style="font-size:12px;color:var(--tx3);">'+escH(t.business_name)+' · '+t.reply_count+' replies</span>');
  openModal('m-ticket-reply');
}

// ── ADMIN ─────────────────────────────────────────────────
function openEditAdmin(adm) {
  sv('ea-id',adm.id); sv('ea-name',adm.name); sv('ea-phone',adm.phone);
  sv('ea-role',adm.role); sv('ea-active',adm.is_active);
  openModal('m-admin-edit');
}

// ── REVENUE CHART ─────────────────────────────────────────
var revenueEl = document.getElementById('revenueChart');
if (revenueEl) {
  var revData = <?= json_encode($monthly_rev) ?>;
  var labels  = revData.map(function(r){return r.month||'—';});
  var vals    = revData.map(function(r){return parseFloat(r.total_revenue||0);});
  Chart.defaults.color = '#455B78';
  Chart.defaults.borderColor = 'rgba(255,255,255,0.04)';
  new Chart(revenueEl, {
    type:'line',
    data:{labels:labels,datasets:[{label:'Revenue (KES)',data:vals,borderColor:'#4080F0',backgroundColor:'rgba(64,128,240,0.07)',borderWidth:2,pointBackgroundColor:'#4080F0',pointBorderColor:'#05080E',pointBorderWidth:2,pointRadius:4,pointHoverRadius:6,fill:true,tension:0.4}]},
    options:{
      responsive:true,maintainAspectRatio:false,
      interaction:{mode:'index',intersect:false},
      plugins:{legend:{display:false},tooltip:{backgroundColor:'#090E1B',borderColor:'rgba(255,255,255,0.1)',borderWidth:1,padding:12,titleColor:'#7A93B8',bodyColor:'#E2EBF8',callbacks:{label:function(ctx){return ' KES '+ctx.parsed.y.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}}}},
      scales:{x:{grid:{color:'rgba(255,255,255,0.03)'},ticks:{color:'#3E546F',font:{family:"'DM Mono', monospace",size:10}}},y:{grid:{color:'rgba(255,255,255,0.03)'},ticks:{color:'#3E546F',font:{family:"'DM Mono', monospace",size:10},callback:function(v){return 'KES '+Number(v).toLocaleString();}}}}
    }
  });
}
</script>
</body>
</html>