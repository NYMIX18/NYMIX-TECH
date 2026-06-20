<?php
require_once __DIR__ . '/includes/db.php';
define('APP_NAME',     'NYMIX Hardware Portal');
define('DEBUG_MODE',   false);  // set false in production
define('MPESA_PAYBILL', '220222');  // your actual paybill

function nx_check_schema(): void {
    $required = [
        'clients','branches','users','categories','suppliers',
        'products','stock','customers','sales','sale_items',
        'subscription_plans','subscriptions','invoices',
        'subscription_payments','client_portal_users',
        'super_admins','support_tickets','ticket_replies',
        'system_health','error_log','admin_activity_log',
    ];
    try {
        $existing = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $missing  = array_diff($required, $existing);
        if ($missing) {
            nx_fatal_error(
                'Missing DB tables',
                'Run <code>nymix_hardware_complete.sql</code> first.',
                $missing
            );
        }
    } catch (Throwable $e) {
        nx_fatal_error('DB Connection Failed', $e->getMessage());
    }
}

function nx_fatal_error(string $title, string $msg, array $list = []): never {
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>NYMIX – Setup Error</title>
    <style>
      body{background:#0d0f14;color:#e8eaf0;font-family:system-ui;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
      .box{background:#13161e;border:1px solid #f75555;border-radius:12px;padding:32px 36px;max-width:560px;width:90%}
      h2{color:#f75555;margin:0 0 10px;font-size:1.1rem}
      p{color:#8b91a8;font-size:.875rem;margin:0 0 14px;line-height:1.6}
      code{background:rgba(247,85,85,.12);color:#f75555;padding:2px 7px;border-radius:4px;font-size:.82em}
      ul{color:#5a6075;font-size:.8rem;margin:10px 0 0 16px;line-height:1.9}
    </style></head><body>
    <div class="box">
      <h2>⚠ ' . htmlspecialchars($title) . '</h2>
      <p>' . $msg . '</p>';
    if ($list) {
        echo '<ul>';
        foreach ($list as $item) echo '<li>' . htmlspecialchars($item) . '</li>';
        echo '</ul>';
    }
    echo '</div></body></html>';
    exit;
}

// Debug helper — renders a styled block (only when DEBUG_MODE on)
function nx_debug(Throwable $e, string $ctx = ''): void {
    if (!DEBUG_MODE) return;
    echo '<details style="background:#1a0010;border:1px solid #ff3333;border-radius:8px;padding:12px 16px;margin:8px 0;font-size:12px;font-family:monospace;color:#ff9999">';
    echo '<summary style="cursor:pointer;color:#ff6b6b;font-weight:700">⚠ DB Error' . ($ctx ? " — {$ctx}" : '') . '</summary>';
    echo '<pre style="margin:8px 0 0;white-space:pre-wrap">' . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</details>';
}

nx_check_schema();


// ============================================================
// SESSION
// ============================================================
session_start();
define('SESS_KEY', 'nx_portal_user');

function current_user(): ?array  { return $_SESSION[SESS_KEY] ?? null; }
function is_logged_in(): bool    { return isset($_SESSION[SESS_KEY]); }
function require_login(): void   { if (!is_logged_in()) { header('Location: ?page=login'); exit; } }
function is_owner(): bool        { $u = current_user(); return $u && $u['role'] === 'owner'; }
function is_manager(): bool      { $u = current_user(); return $u && in_array($u['role'], ['owner','manager']); }

function can(string $perm): bool {
    $u = current_user();
    if (!$u) return false;
    $map = [
        'owner'       => ['dashboard','subscription','invoices','payments','branches','users','tickets','sales','reports'],
        'manager'     => ['dashboard','tickets','sales','reports','users_branch'],
        'cashier'     => ['dashboard','sales'],
        'stock_clerk' => ['dashboard','sales_view'],
    ];
    return in_array($perm, $map[$u['role'] ?? 'cashier'] ?? []);
}

function logout(): void { session_destroy(); header('Location: ?page=login'); exit; }

// Returns active branch business_type — reads from DB if needed
function biz(?int $branch_id = null): string {
    static $cache = [];
    $bid = $branch_id ?? (current_user()['branch_id'] ?? 0);
    if (!$bid) return 'hardware';
    if (isset($cache[$bid])) return $cache[$bid];
    try {
        $st = db()->prepare("SELECT business_type FROM branches WHERE id = ? LIMIT 1");
        $st->execute([$bid]);
        $cache[$bid] = $st->fetchColumn() ?: 'hardware';
        return $cache[$bid];
    } catch (Throwable $e) { return 'hardware'; }
}

// Check if current branch supports a feature
function biz_has(string $feature, ?int $branch_id = null): bool {
    $map = [
        'quotations'       => ['hardware','wholesale'],
        'returns'          => ['hardware','wholesale','supermarket','agrovet','pharmacy','electronics'],
        'expiry_tracking'  => ['supermarket','agrovet','pharmacy'],
        'serial_numbers'   => ['electronics'],
        'bulk_pricing'     => ['wholesale'],
        'repair_jobs'      => ['electronics'],
        'prescriptions'    => ['pharmacy'],
        'controlled_drugs' => ['agrovet','pharmacy'],
    ];
    return in_array(biz($branch_id), $map[$feature] ?? []);
}


// ============================================================
// CSRF
// ============================================================
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function verify_csrf(): bool {
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '');
}


// ============================================================
// FLASH
// ============================================================
function flash(string $msg, string $type = 'success'): void { $_SESSION['flash'] = compact('msg','type'); }
function get_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }


// ============================================================
// SUBSCRIPTION STATUS — fixed: no read_only_until column
// ============================================================
function sub_status_sql(string $alias = 'c'): string {
    return "COALESCE((
        SELECT CASE
          WHEN {$alias}.status = 'suspended'    THEN 'suspended'
          WHEN {$alias}.status = 'cancelled'    THEN 'expired'
          WHEN CURDATE() <= s.end_date          THEN 'active'
          WHEN CURDATE() <= s.grace_end_date    THEN 'grace'
          ELSE 'read_only'
        END
        FROM subscriptions s
        WHERE s.client_id = {$alias}.id
        ORDER BY s.id DESC LIMIT 1
    ), 'no_sub') AS sub_status";
}


// ============================================================
// AUTH — LOGIN
// ============================================================
function do_login(): void {
    if (!verify_csrf()) { flash('Invalid request.', 'error'); return; }
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';
    if (!$email || !$password) { flash('Email and password are required.', 'error'); return; }

    try { $db = db(); } catch (Throwable $e) {
        nx_debug($e, 'do_login/connect');
        flash('Database connection failed. Contact support.', 'error'); return;
    }

    // --- owner lookup ---
    $client = null;
    try {
        $st = $db->prepare("
            SELECT c.id AS client_id, c.business_name, c.status AS account_status,
                   c.email, c.phone, c.logo,
                   " . sub_status_sql('c') . "
            FROM clients c WHERE c.email = ? LIMIT 1
        ");
        $st->execute([$email]);
        $client = $st->fetch() ?: null;
    } catch (Throwable $e) { nx_debug($e, 'do_login/owner-lookup'); }

    // --- staff lookup ---
    $staff = null;
    try {
        $st2 = $db->prepare("
            SELECT u.id, u.full_name, u.email, u.role, u.password_hash,
                   u.branch_id, b.branch_name, b.client_id, 
                   c.business_name, c.status AS account_status, c.logo,
                   " . sub_status_sql('c') . "
            FROM users u
            JOIN branches b ON b.id = u.branch_id
            JOIN clients  c ON c.id = b.client_id
            WHERE u.email = ? AND u.is_active = 1 LIMIT 1
        ");
        $st2->execute([$email]);
        $staff = $st2->fetch() ?: null;
    } catch (Throwable $e) { nx_debug($e, 'do_login/staff-lookup'); }

    // --- try owner password ---
    if ($client) {
        try {
            $st3 = $db->prepare("SELECT password_hash FROM client_portal_users WHERE client_id = ? LIMIT 1");
            $st3->execute([$client['client_id']]);
            $pu = $st3->fetch();
            if ($pu && password_verify($password, $pu['password_hash'])) {
                if ($client['account_status'] === 'suspended') {
                    flash('Account suspended. Contact NYMIX TECH.', 'error'); return;
                }

                // ── REAL-TIME subscription + invoice check on login ──
                $real_sub_status = compute_real_sub_status($db, $client['client_id']);

                $_SESSION[SESS_KEY] = [
                    'client_id'      => $client['client_id'],
                    'name'           => $client['business_name'],
                    'email'          => $client['email'],
                    'role'           => 'owner',
                    'branch_id'      => null,
                    'account_status' => $client['account_status'],
                    'sub_status'     => $real_sub_status,
                    'logo'           => $client['logo'],
                    'business_name'  => $client['business_name'],
                ];
                if ($real_sub_status !== 'active') {
                    $_SESSION['sub_warning'] = $real_sub_status;
                    flash('Your subscription is <strong>' . ucfirst($real_sub_status) . '</strong>. Pay your invoice to restore access.', 'sub_warn');
                    header('Location: ?page=payments'); exit;
                }
                header('Location: ?page=dashboard'); exit;
            }
        } catch (Throwable $e) { nx_debug($e, 'do_login/owner-verify'); }
    }

    // --- try staff password ---
    if ($staff && password_verify($password, $staff['password_hash'])) {
        if ($staff['account_status'] === 'suspended') {
            flash('Account suspended. Contact support.', 'error'); return;
        }

        // ── REAL-TIME subscription + invoice check on login ──
        $real_sub_status = compute_real_sub_status($db, $staff['client_id']);

        $_SESSION[SESS_KEY] = [
            'client_id'      => $staff['client_id'],
            'user_id'        => $staff['id'],
            'name'           => $staff['full_name'],
            'email'          => $staff['email'],
            'role'           => $staff['role'],
            'branch_id'      => $staff['branch_id'],
            'branch_name'    => $staff['branch_name'],
            'account_status' => $staff['account_status'],
            'sub_status'     => $real_sub_status,
            'logo'           => $staff['logo'],
            'business_name'  => $staff['business_name'],
        ];
        if ($real_sub_status !== 'active') {
            $_SESSION['sub_warning'] = $real_sub_status;
            header('Location: ?page=payments'); exit;
        }
        header('Location: ?page=dashboard'); exit;
    }

    flash('Invalid email or password.', 'error');
}
// ============================================================
// REAL-TIME SUBSCRIPTION STATUS — checks invoice payment too
// ============================================================
function compute_real_sub_status(object $db, int $client_id): string {
    try {
        // 1. Get client account status
        $cs = $db->prepare("SELECT status FROM clients WHERE id = ? LIMIT 1");
        $cs->execute([$client_id]);
        $client_row = $cs->fetch();
        if (!$client_row) return 'no_sub';
        if ($client_row['status'] === 'suspended') return 'suspended';
        if ($client_row['status'] === 'cancelled') return 'expired';

        // 2. Get latest subscription
        $ss = $db->prepare("
            SELECT s.*, sp.name AS plan_name
            FROM subscriptions s
            JOIN subscription_plans sp ON sp.id = s.plan_id
            WHERE s.client_id = ? ORDER BY s.id DESC LIMIT 1
        ");
        $ss->execute([$client_id]);
        $sub = $ss->fetch();
        if (!$sub) return 'no_sub';

        // 3. Check if ALL invoices for this subscription are paid
        $ip = $db->prepare("
            SELECT COUNT(*) FROM invoices
            WHERE subscription_id = ?
              AND status NOT IN ('paid','void')
        ");
        $ip->execute([$sub['id']]);
        $unpaid_count = (int)$ip->fetchColumn();

        // If there are unpaid invoices, subscription is NOT active regardless of dates
        if ($unpaid_count > 0) {
            // Check grace period
            $today = date('Y-m-d');
            if (!empty($sub['grace_end_date']) && $today <= $sub['grace_end_date']) {
                return 'grace';
            }
            return 'suspended';
        }

        // 4. All invoices paid — check date-based status
        $today = date('Y-m-d');
        if ($today <= $sub['end_date']) return 'active';
        if (!empty($sub['grace_end_date']) && $today <= $sub['grace_end_date']) return 'grace';
        return 'expired';

    } catch (Throwable $e) {
        nx_debug($e, 'compute_real_sub_status');
        return 'no_sub';
    }
}
// ============================================================
// AUTH — REGISTER
// ============================================================
function do_register(): void {
    if (!verify_csrf()) { flash('Invalid request.', 'error'); return; }

    $kra_pin   = strtoupper(trim($_POST['kra_pin']   ?? ''));
    $phone     = trim($_POST['phone']     ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (!$kra_pin || !$phone || !$email || !$password) {
        flash('All fields are required.', 'error'); return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Enter a valid email address.', 'error'); return;
    }
    if (strlen($password) < 8) {
        flash('Password must be at least 8 characters.', 'error'); return;
    }
    if ($password !== $password2) {
        flash('Passwords do not match.', 'error'); return;
    }

    try { $db = db(); } catch (Throwable $e) {
        nx_debug($e, 'do_register/connect');
        flash('Database connection failed.', 'error'); return;
    }

    // 1. Find client by KRA PIN
    $client = null;
    try {
        $st = $db->prepare("
            SELECT c.id, c.business_name, c.owner_name, c.phone, c.email, c.status,
                   " . sub_status_sql('c') . "
            FROM clients c WHERE c.kra_pin = ? LIMIT 1
        ");
        $st->execute([$kra_pin]);
        $client = $st->fetch() ?: null;
    } catch (Throwable $e) {
        nx_debug($e, 'do_register/kra-lookup');
        flash('System error looking up KRA PIN: ' . $e->getMessage(), 'error'); return;
    }

    if (!$client) {
        flash('Business_id  not found. Contact NYMIX TECH to register your business first.', 'error'); return;
    }

    // 2. Check subscription is active
    if (!in_array($client['sub_status'], ['active','grace'])) {
        flash('No active subscription for this BUSINESS ID (status: ' . $client['sub_status'] . '). Contact NYMIX TECH.', 'error'); return;
    }

    // 3. Check if already registered
    try {
        $st2 = $db->prepare("SELECT id FROM client_portal_users WHERE client_id = ? LIMIT 1");
        $st2->execute([$client['id']]);
        if ($st2->fetch()) {
            flash('This business already has a portal account. Please sign in instead.', 'error'); return;
        }
    } catch (Throwable $e) {
        nx_debug($e, 'do_register/already-registered');
        flash('System error: ' . $e->getMessage(), 'error'); return;
    }

    // 4. Phone or email must match
    $phone_in     = preg_replace('/\D/', '', $phone);
    $phone_stored = preg_replace('/\D/', '', $client['phone'] ?? '');
    $email_match  = strtolower($email) === strtolower($client['email'] ?? '');
    $phone_match  = $phone_in && $phone_in === $phone_stored;

    if (!$phone_match && !$email_match) {
        flash('Phone or email does not match our records for this BUSINESS ID. Contact NYMIX TECH.', 'error'); return;
    }

    // 5. Create portal account
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO client_portal_users (client_id, password_hash) VALUES (?,?)")
           ->execute([$client['id'], $hash]);

        if ($email && !$client['email']) {
            $db->prepare("UPDATE clients SET email=? WHERE id=?")->execute([$email, $client['id']]);
        }

        flash('Account created! You can now sign in.', 'success');
        header('Location: ?page=login'); exit;
    } catch (Throwable $e) {
        nx_debug($e, 'do_register/insert');
        flash('Registration failed: ' . $e->getMessage(), 'error');
    }
}


// ============================================================
// DATA HELPERS
// ============================================================
function get_pending_invoice(int $cid): ?array {
    try {
        $st = db()->prepare("
            SELECT i.* FROM invoices i
            WHERE i.client_id = ?
              AND i.status IN ('unpaid','overdue','sent','draft')
            ORDER BY i.created_at DESC LIMIT 1
        ");
        $st->execute([$cid]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { nx_debug($e,'get_pending_invoice'); return null; }
}

function get_subscription(int $cid): ?array {
    try {
        $st = db()->prepare("
            SELECT s.*, sp.name AS plan_name, sp.features,
                   DATEDIFF(s.end_date, CURDATE()) AS days_remaining
            FROM subscriptions s
            JOIN subscription_plans sp ON sp.id = s.plan_id
            WHERE s.client_id = ? ORDER BY s.id DESC LIMIT 1
        ");
        $st->execute([$cid]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { nx_debug($e,'get_subscription'); return null; }
}

function get_invoices(int $cid, int $limit = 20): array {
    try {
        $st = db()->prepare("SELECT * FROM invoices WHERE client_id=? ORDER BY created_at DESC LIMIT $limit");
        $st->execute([$cid]); return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_invoices'); return []; }
}

function get_payments(int $cid, int $limit = 20): array {
    try {
        $st = db()->prepare("
            SELECT sp.*, i.invoice_no
            FROM subscription_payments sp
            LEFT JOIN invoices i ON i.id = sp.invoice_id
            WHERE sp.client_id = ? ORDER BY sp.created_at DESC LIMIT $limit
        ");
        $st->execute([$cid]); return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_payments'); return []; }
}

function get_branches(int $cid): array {
    try {
        $st = db()->prepare("
            SELECT b.*, COUNT(u.id) AS user_count
            FROM branches b
            LEFT JOIN users u ON u.branch_id = b.id AND u.is_active = 1
            WHERE b.client_id = ? GROUP BY b.id ORDER BY b.id ASC
        ");
        $st->execute([$cid]); return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_branches'); return []; }
}

function get_branch_users(int $cid, ?int $bid = null): array {
    try {
        if ($bid) {
            $st = db()->prepare("
                SELECT u.* FROM users u
                JOIN branches b ON b.id = u.branch_id
                WHERE b.client_id=? AND u.branch_id=? ORDER BY u.full_name
            ");
            $st->execute([$cid, $bid]);
        } else {
            $st = db()->prepare("
                SELECT u.*, b.branch_name FROM users u
                JOIN branches b ON b.id = u.branch_id
                WHERE b.client_id=? ORDER BY b.branch_name, u.full_name
            ");
            $st->execute([$cid]);
        }
        return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_branch_users'); return []; }
}

function get_tickets(int $cid): array {
    try {
        $st = db()->prepare("
            SELECT t.*, sa.name AS assigned_name,
            (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id) AS reply_count
            FROM support_tickets t
            LEFT JOIN super_admins sa ON sa.id = t.assigned_to
            WHERE t.client_id=? ORDER BY t.created_at DESC
        ");
        $st->execute([$cid]); return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_tickets'); return []; }
}

function get_ticket(int $id, int $cid): ?array {
    try {
        $st = db()->prepare("
            SELECT t.*, sa.name AS assigned_name
            FROM support_tickets t
            LEFT JOIN super_admins sa ON sa.id = t.assigned_to
            WHERE t.id=? AND t.client_id=? LIMIT 1
        ");
        $st->execute([$id, $cid]); return $st->fetch() ?: null;
    } catch (Throwable $e) { nx_debug($e,'get_ticket'); return null; }
}

function get_ticket_replies(int $tid): array {
    try {
        $st = db()->prepare("
            SELECT tr.*,
            CASE tr.sender_type
              WHEN 'admin' THEN (SELECT name FROM super_admins WHERE id = tr.sender_id)
              ELSE (SELECT full_name FROM users WHERE id = tr.sender_id)
            END AS sender_name
            FROM ticket_replies tr WHERE tr.ticket_id=? ORDER BY tr.created_at ASC
        ");
        $st->execute([$tid]); return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_ticket_replies'); return []; }
}

function get_sales_summary(int $cid, ?int $bid = null, string $period = 'today'): array {
    $empty = ['total_sales'=>0,'total_revenue'=>0,'voided_count'=>0,'avg_sale'=>0];
    try {
        $where = $bid
            ? "AND s.branch_id = " . intval($bid)
            : "AND s.branch_id IN (SELECT id FROM branches WHERE client_id = " . intval($cid) . ")";
        $df = match($period) {
            'week'  => "AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
            'month' => "AND MONTH(s.sale_date)=MONTH(CURDATE()) AND YEAR(s.sale_date)=YEAR(CURDATE())",
            default => "AND DATE(s.sale_date) = CURDATE()",
        };
        $st = db()->prepare("
            SELECT COUNT(*) AS total_sales,
              COALESCE(SUM(grand_total),0) AS total_revenue,
              COALESCE(SUM(CASE WHEN voided=1 THEN 1 ELSE 0 END),0) AS voided_count,
              COALESCE(AVG(CASE WHEN voided=0 THEN grand_total END),0) AS avg_sale
            FROM sales s WHERE s.voided=0 $where $df
        ");
        $st->execute([]); return $st->fetch() ?: $empty;
    } catch (Throwable $e) { nx_debug($e,'get_sales_summary'); return $empty; }
}

function get_recent_sales(int $cid, ?int $bid = null, int $limit = 15): array {
    try {
        $where = $bid
            ? "AND s.branch_id = " . intval($bid)
            : "AND s.branch_id IN (SELECT id FROM branches WHERE client_id = " . intval($cid) . ")";
        $st = db()->prepare("
            SELECT s.*, b.branch_name, u.full_name AS served_by, c2.name AS customer_name
            FROM sales s
            LEFT JOIN branches  b  ON b.id  = s.branch_id
            LEFT JOIN users     u  ON u.id  = s.user_id
            LEFT JOIN customers c2 ON c2.id = s.customer_id
            WHERE s.voided=0 $where ORDER BY s.sale_date DESC LIMIT $limit
        ");
        $st->execute([]); return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_recent_sales'); return []; }
}

function get_stock_notifications(int $cid, int $limit = 50): array {
    try {
        $st = db()->prepare("
            SELECT * FROM stock_notifications
            WHERE client_id = ?
            ORDER BY created_at DESC LIMIT $limit
        ");
        $st->execute([$cid]);
        return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_stock_notifications'); return []; }
}

function count_unread_notifications(int $cid): int {
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM stock_notifications WHERE client_id=? AND is_read=0");
        $st->execute([$cid]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

function mark_notifications_read(int $cid): void {
    try {
        db()->prepare("UPDATE stock_notifications SET is_read=1 WHERE client_id=?")->execute([$cid]);
    } catch (Throwable $e) { nx_debug($e,'mark_notifications_read'); }
}

function get_top_products(int $cid, ?int $bid = null): array {
    try {
        if ($bid) {
            $st = db()->prepare("
                SELECT p.name, SUM(si.quantity) AS qty_sold, SUM(si.line_total) AS revenue
                FROM sale_items si
                JOIN sales    s ON s.id  = si.sale_id
                JOIN products p ON p.id  = si.product_id
                WHERE s.voided=0
                  AND MONTH(s.sale_date)=MONTH(CURDATE())
                  AND YEAR(s.sale_date)=YEAR(CURDATE())
                  AND s.branch_id = ?
                GROUP BY p.id ORDER BY qty_sold DESC LIMIT 8
            ");
            $st->execute([$bid]);
        } else {
            $st = db()->prepare("
                SELECT p.name, SUM(si.quantity) AS qty_sold, SUM(si.line_total) AS revenue
                FROM sale_items si
                JOIN sales    s ON s.id  = si.sale_id
                JOIN products p ON p.id  = si.product_id
                JOIN branches b ON b.id  = s.branch_id
                WHERE s.voided=0
                  AND MONTH(s.sale_date)=MONTH(CURDATE())
                  AND YEAR(s.sale_date)=YEAR(CURDATE())
                  AND b.client_id = ?
                GROUP BY p.id ORDER BY qty_sold DESC LIMIT 8
            ");
            $st->execute([$cid]);
        }
        return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_top_products'); return []; }
}


// ============================================================
// eTIMS HELPERS
// ============================================================
function get_etims_branches(int $cid): array {
    try {
        $st = db()->prepare("
            SELECT b.id, b.branch_name, b.is_active,
                   b.etims_enabled, b.etims_pin, b.etims_branch_code,
                   b.etims_device_serial, b.etims_env
            FROM branches b
            WHERE b.client_id = ? ORDER BY b.branch_name ASC
        ");
        $st->execute([$cid]);
        return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_etims_branches'); return []; }
}

function get_etims_stats(int $cid): array {
    $empty = ['submitted'=>0,'failed'=>0,'pending'=>0,'skipped'=>0,'total'=>0];
    try {
        $st = db()->prepare("
            SELECT etims_status, COUNT(*) AS cnt
            FROM sales s
            JOIN branches b ON b.id = s.branch_id
            WHERE b.client_id = ? AND DATE(s.sale_date) = CURDATE() AND s.voided = 0
            GROUP BY etims_status
        ");
        $st->execute([$cid]);
        $rows = $st->fetchAll();
        $out  = $empty;
        foreach ($rows as $r) {
            $key = $r['etims_status'] ?? 'skipped';
            if (isset($out[$key])) $out[$key] = (int)$r['cnt'];
            $out['total'] += (int)$r['cnt'];
        }
        return $out;
    } catch (Throwable $e) { nx_debug($e,'get_etims_stats'); return $empty; }
}

function get_etims_recent_failures(int $cid, int $limit = 10): array {
    try {
        $st = db()->prepare("
            SELECT s.id, s.receipt_no, s.sale_date, s.grand_total,
                   s.etims_status, s.etims_error, s.etims_invoice_no,
                   s.etims_retry_count, s.etims_submitted_at,
                   b.branch_name
            FROM sales s
            JOIN branches b ON b.id = s.branch_id
            WHERE b.client_id = ?
              AND s.etims_status IN ('failed','pending')
              AND s.voided = 0
            ORDER BY s.sale_date DESC
            LIMIT ?
        ");
        $st->execute([$cid, $limit]);
        return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_etims_failures'); return []; }
}

function get_etims_audit(int $cid, int $limit = 30): array {
    try {
        $st = db()->prepare("
            SELECT s.id, s.receipt_no, s.sale_date, s.grand_total,
                   s.etims_status, s.etims_invoice_no, s.etims_submitted_at,
                   s.etims_error, s.etims_retry_count,
                   b.branch_name
            FROM sales s
            JOIN branches b ON b.id = s.branch_id
            WHERE b.client_id = ?
              AND s.etims_status IN ('submitted','failed')
              AND s.voided = 0
            ORDER BY s.sale_date DESC
            LIMIT ?
        ");
        $st->execute([$cid, $limit]);
        return $st->fetchAll();
    } catch (Throwable $e) { nx_debug($e,'get_etims_audit'); return []; }
}

// ============================================================
// ACTIONS (POST handlers)
// ============================================================
function handle_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $action = $_POST['action'] ?? '';

    if ($action === 'login')    { do_login();    return; }
    if ($action === 'register') { do_register(); return; }
    if ($action === 'logout')   { logout(); }

    require_login();
    $u = current_user();

    match($action) {
        'create_ticket'     => action_create_ticket($u),
        'mark_notifs_read'  => is_owner() ? mark_notifications_read($u['client_id']) : null,
        'reply_ticket'   => action_reply_ticket($u),
        'close_ticket'   => action_close_ticket($u),
        'add_branch'     => is_owner()   ? action_add_branch($u)     : flash('No permission.','error'),
        'toggle_branch'  => is_owner()   ? action_toggle_branch($u)  : flash('No permission.','error'),
        'remove_branch'  => is_owner()   ? action_remove_branch($u)  : flash('No permission.','error'),
        'remove_branch'  => is_owner()   ? action_remove_branch($u)  : flash('No permission.','error'),
        'add_user'       => is_manager() ? action_add_user($u)       : flash('No permission.','error'),
        'toggle_user'    => is_manager() ? action_toggle_user($u)    : flash('No permission.','error'),
        'record_payment' => is_owner()   ? action_record_payment($u) : flash('No permission.','error'),
        'verify_lock'    => action_verify_lock($u),
        'save_etims'     => is_owner()   ? action_save_etims($u)     : flash('No permission.','error'),
        default          => null,
    };
}

function action_create_ticket(array $u): void {
    if (!verify_csrf()) { flash('Invalid request.','error'); return; }
    $subject  = trim($_POST['subject']  ?? '');
    $message  = trim($_POST['message']  ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    if (!$subject || !$message) { flash('Subject and message required.','error'); return; }
    try {
        $db = db();
        $db->prepare("INSERT INTO support_tickets (client_id,subject,priority,status) VALUES (?,?,?,'open')")
           ->execute([$u['client_id'], $subject, $priority]);
        $tid = $db->lastInsertId();
        $db->prepare("INSERT INTO ticket_replies (ticket_id,sender_type,sender_id,message) VALUES (?,'client',?,?)")
           ->execute([$tid, $u['user_id'] ?? 0, $message]);
        flash("Ticket #{$tid} created.");
        header("Location: ?page=tickets&id={$tid}"); exit;
    } catch (Throwable $e) { nx_debug($e,'create_ticket'); flash('Could not create ticket: '.$e->getMessage(),'error'); }
}

function action_reply_ticket(array $u): void {
    if (!verify_csrf()) { flash('Invalid request.','error'); return; }
    $tid     = (int)($_POST['ticket_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if (!$message) { flash('Reply cannot be empty.','error'); return; }
    if (!get_ticket($tid, $u['client_id'])) { flash('Ticket not found.','error'); return; }
    try {
        db()->prepare("INSERT INTO ticket_replies (ticket_id,sender_type,sender_id,message) VALUES (?,'client',?,?)")
           ->execute([$tid, $u['user_id'] ?? 0, $message]);
        flash('Reply sent.');
        header("Location: ?page=tickets&id={$tid}"); exit;
    } catch (Throwable $e) { nx_debug($e,'reply_ticket'); flash('Could not send reply.','error'); }
}

function action_close_ticket(array $u): void {
    if (!verify_csrf()) return;
    $tid = (int)($_POST['ticket_id'] ?? 0);
    if (!get_ticket($tid, $u['client_id'])) { flash('Ticket not found.','error'); return; }
    try {
        db()->prepare("UPDATE support_tickets SET status='closed',resolved_at=NOW() WHERE id=?")->execute([$tid]);
        flash('Ticket closed.');
        header('Location: ?page=tickets'); exit;
    } catch (Throwable $e) { nx_debug($e,'close_ticket'); flash('Could not close ticket.','error'); }
}

function action_add_branch(array $u): void {
    if (!verify_csrf()) { flash('Invalid request.','error'); return; }
    $name    = trim($_POST['branch_name'] ?? '');
    $phone   = trim($_POST['phone']       ?? '');
    $address = trim($_POST['address']     ?? '');
    if (!$name) { flash('Branch name required.','error'); return; }

    $sub = get_subscription($u['client_id']);
    if ($sub) {
        try {
            $cnt = db()->prepare("SELECT COUNT(*) FROM branches WHERE client_id=? AND is_active=1");
            $cnt->execute([$u['client_id']]);
            if ((int)$cnt->fetchColumn() >= $sub['branch_count']) {
                flash("Branch limit reached ({$sub['branch_count']}). Upgrade to add more.",'error'); return;
            }
        } catch (Throwable $e) { nx_debug($e,'add_branch/count'); }
    }

    try {
        db()->prepare("INSERT INTO branches (client_id,branch_name,phone,address,is_active) VALUES (?,?,?,?,1)")
           ->execute([$u['client_id'], $name, $phone, $address]);
flash("Branch \"{$name}\" added.");
        header('Location: ?page=branches'); exit;
    } catch (Throwable $e) { nx_debug($e,'add_branch/insert'); flash('Could not add branch: '.$e->getMessage(),'error'); }
}

function action_remove_branch(array $u): void {
    if (!verify_csrf()) { flash('Invalid request.','error'); return; }
    $bid = (int)($_POST['branch_id'] ?? 0);
    try {
        $st = db()->prepare("SELECT id,branch_name FROM branches WHERE id=? AND client_id=?");
        $st->execute([$bid, $u['client_id']]);
        $b = $st->fetch();
        if (!$b) { flash('Branch not found.','error'); return; }
        // Soft delete: deactivate branch + all its users
        db()->prepare("UPDATE branches SET is_active=0 WHERE id=?")->execute([$bid]);
        db()->prepare("UPDATE users SET is_active=0 WHERE branch_id=?")->execute([$bid]);
        flash("Branch \"{$b['branch_name']}\" removed. All staff accounts deactivated.");
        header('Location: ?page=branches'); exit;
    } catch (Throwable $e) {
        nx_debug($e,'remove_branch');
        flash('Could not remove branch: '.$e->getMessage(),'error');
    }
}

function action_toggle_branch(array $u): void {
    if (!verify_csrf()) return;
    $bid = (int)($_POST['branch_id'] ?? 0);
    try {
        $st = db()->prepare("SELECT id,is_active FROM branches WHERE id=? AND client_id=?");
        $st->execute([$bid, $u['client_id']]);
        $b = $st->fetch();
        if (!$b) { flash('Branch not found.','error'); return; }
        $new = $b['is_active'] ? 0 : 1;
        db()->prepare("UPDATE branches SET is_active=? WHERE id=?")->execute([$new, $bid]);
        flash('Branch '.($new ? 'activated' : 'deactivated').'.');
        header('Location: ?page=branches'); exit;
    } catch (Throwable $e) { nx_debug($e,'toggle_branch'); flash('Could not update branch.','error'); }
}

function action_add_user(array $u): void {
    if (!verify_csrf()) { flash('Invalid request.','error'); return; }
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $phone     = trim($_POST['phone']     ?? '');
    $username  = trim($_POST['username']  ?? '');
    $role      = $_POST['role']      ?? 'cashier';
    $role_map  = ['manager'=>2, 'cashier'=>3, 'stock_clerk'=>4];
    $role_id   = $role_map[$role] ?? 3;
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $password  = $_POST['password']  ?? '';

    if (!$full_name || !$email || !$password || !$phone) { flash('Name, email, phone, and password required.','error'); return; }
    if (!$username) $username = strtolower(str_replace(' ','.', $full_name)) . rand(10,99);

    if (!is_owner()) {
        $branch_id = $u['branch_id'];
        if (in_array($role, ['owner','manager'])) { flash('Cannot assign this role.','error'); return; }
    }

    try {
        $bst = db()->prepare("SELECT id FROM branches WHERE id=? AND client_id=?");
        $bst->execute([$branch_id, $u['client_id']]);
        if (!$bst->fetch()) { flash('Invalid branch selected.','error'); return; }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare("INSERT INTO users (branch_id,full_name,username,email,phone,role_id,role,password_hash,is_active) VALUES (?,?,?,?,?,?,?,?,1)")
           ->execute([$branch_id, $full_name, $username, $email, $phone, $role_id, $role, $hash]);
        flash("User \"{$full_name}\" added.");
        header('Location: ?page=users'); exit;
    } catch (Throwable $e) {
        nx_debug($e,'add_user');
        flash('Could not add user: '.$e->getMessage(), 'error');
    }
}

function action_toggle_user(array $u): void {
    if (!verify_csrf()) return;
    $uid = (int)($_POST['user_id'] ?? 0);
    try {
        $st = db()->prepare("SELECT u.id,u.is_active FROM users u JOIN branches b ON b.id=u.branch_id WHERE u.id=? AND b.client_id=?");
        $st->execute([$uid, $u['client_id']]);
        $usr = $st->fetch();
        if (!$usr) { flash('User not found.','error'); return; }
        $new = $usr['is_active'] ? 0 : 1;
        db()->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$new, $uid]);
        flash('User '.($new ? 'activated' : 'deactivated').'.');
        header('Location: ?page=users'); exit;
    } catch (Throwable $e) { nx_debug($e,'toggle_user'); flash('Could not update user.','error'); }
}

function action_save_etims(array $u): void {
    if (!verify_csrf()) { flash('Invalid request.','error'); return; }
    $bid = (int)($_POST['branch_id'] ?? 0);
    if (!$bid) { flash('Invalid branch.','error'); return; }

    // Verify branch belongs to this client
    try {
        $chk = db()->prepare("SELECT id FROM branches WHERE id = ? AND client_id = ?");
        $chk->execute([$bid, $u['client_id']]);
        if (!$chk->fetch()) { flash('Branch not found.','error'); return; }
    } catch (Throwable $e) { nx_debug($e,'save_etims/check'); flash('Error.','error'); return; }

    $enabled = isset($_POST['etims_enabled']) ? 1 : 0;
    $pin     = strtoupper(trim($_POST['etims_pin'] ?? ''));
    $code    = trim($_POST['etims_branch_code'] ?? '');
    $serial  = trim($_POST['etims_device_serial'] ?? '');
    $env     = in_array($_POST['etims_env'] ?? '', ['sandbox','live']) ? $_POST['etims_env'] : 'sandbox';

    if ($enabled && (!$pin || !$code || !$serial)) {
        flash('PIN, Branch Code and Device Serial are required to enable eTIMS.','error');
        header('Location: ?page=etims&bid='.$bid); exit;
    }

    try {
        db()->prepare("
            UPDATE branches SET
                etims_enabled       = ?,
                etims_pin           = ?,
                etims_branch_code   = ?,
                etims_device_serial = ?,
                etims_env           = ?
            WHERE id = ? AND client_id = ?
        ")->execute([$enabled, $pin, $code, $serial, $env, $bid, $u['client_id']]);

        flash('eTIMS settings saved for branch.', 'success');
        header('Location: ?page=etims&bid='.$bid); exit;
    } catch (Throwable $e) {
        nx_debug($e,'save_etims/update');
        flash('Could not save: '.$e->getMessage(),'error');
    }
}

function action_verify_lock(array $u): void {
    header('Content-Type: application/json');
    if (!verify_csrf()) { echo json_encode(['ok'=>false]); exit; }
    $password = $_POST['password'] ?? '';
    try {
        $db = db();
        // Try owner password
        $st = $db->prepare("SELECT password_hash FROM client_portal_users WHERE client_id = ? LIMIT 1");
        $st->execute([$u['client_id']]);
        $pu = $st->fetch();
        if ($pu && password_verify($password, $pu['password_hash'])) {
            echo json_encode(['ok'=>true]); exit;
        }
        // Try staff password
        if (!empty($u['user_id'])) {
            $st2 = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $st2->execute([$u['user_id']]);
            $usr = $st2->fetch();
            if ($usr && password_verify($password, $usr['password_hash'])) {
                echo json_encode(['ok'=>true]); exit;
            }
        }
        echo json_encode(['ok'=>false]);
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false]);
    }
    exit;
}

function action_record_payment(array $u): void {
    if (!verify_csrf()) { flash('Invalid request.','error'); return; }
    $inv_id = (int)($_POST['invoice_id'] ?? 0) ?: null;

    if (!$inv_id) { flash('Invalid invoice.','error'); return; }

    // Always fetch amount from DB — never trust client-submitted amount
    try {
        $inv_check = db()->prepare("
            SELECT total, client_id, status FROM invoices
            WHERE id = ? AND client_id = ?
            LIMIT 1
        ");
        $inv_check->execute([$inv_id, $u['client_id']]);
        $inv_row = $inv_check->fetch();
    } catch (Throwable $e) {
        nx_debug($e, 'record_payment/inv_check');
        flash('Could not load invoice.', 'error'); return;
    }

    if (!$inv_row) { flash('Invoice not found.', 'error'); return; }
    if (!in_array($inv_row['status'], ['unpaid','sent','overdue','draft'])) {
        flash('This invoice has already been paid or is not payable.', 'error'); return;
    }
    if ((int)$inv_row['client_id'] !== (int)$u['client_id']) {
        flash('Unauthorized.', 'error'); return;
    }

    $amount = (float)$inv_row['total']; // exact amount from DB, not from form

    if ($amount <= 0) { flash('Invalid invoice amount.','error'); return; }

    require_once __DIR__ . '/pesapal_helper.php';

    // Build unique merchant ref
    $merchant_ref = 'NYMIX-' . $u['client_id'] . '-' . time();

// No invoice splitting — one invoice pays in full

    // Get client details for billing address
    try {
        $st = db()->prepare("SELECT business_name, owner_name, email, phone FROM clients WHERE id=? LIMIT 1");
        $st->execute([$u['client_id']]);
        $client = $st->fetch();
    } catch (Throwable $e) {
        nx_debug($e,'record_payment/client');
        flash('Could not load client details.','error'); return;
    }

    $name_parts = explode(' ', $client['owner_name'] ?? $client['business_name'] ?? 'Client', 2);

    // Get token
    $token = pesapal_get_token();
    if (!$token) { flash('Could not connect to payment gateway. Try again.','error'); return; }

    // Register IPN (cached after first time)
    $ipn_id = pesapal_register_ipn($token);
    if (!$ipn_id) { flash('Payment gateway setup failed. Contact support.','error'); return; }

    // Save a pending payment record first so IPN can find it
    try {
        db()->prepare("
            INSERT INTO subscription_payments
              (client_id, invoice_id, amount, payment_method, reference, payment_date, note, confirmed)
            VALUES (?, ?, ?, 'mpesa', ?, CURDATE(), 'Pesapal payment initiated', 0)
        ")->execute([$u['client_id'], $inv_id, $amount, $merchant_ref]);
    } catch (Throwable $e) {
        nx_debug($e,'record_payment/insert');
        flash('Could not initiate payment: '.$e->getMessage(),'error'); return;
    }

    // Submit to Pesapal
    $result = pesapal_submit_order($token, $ipn_id, [
        'merchant_ref'   => $merchant_ref,
        'amount'         => $amount,
        'description'    => 'NYMIX Subscription - ' . ($client['business_name'] ?? ''),
        'email'          => $client['email']  ?? '',
        'phone'          => preg_replace('/\D/', '', $client['phone'] ?? ''),
        'first_name'     => $name_parts[0]    ?? 'Client',
        'last_name'      => $name_parts[1]    ?? '',
        'payment_method' => 'MPESA',          // force mobile money, not card
        'currency'       => 'KES',
    ]);

    if (!empty($result['redirect_url'])) {
        // Store merchant_ref in session so callback can match it
        $_SESSION['pending_payment_ref'] = $merchant_ref;
        $_SESSION['pending_invoice_id']  = $inv_id;
        $_SESSION['pending_client_id']   = $u['client_id'];
        header('Location: ' . $result['redirect_url']);
        exit;
    }

    flash('Payment initiation failed. Please try again or contact support.', 'error');
}


// ============================================================
// ROUTE
// ============================================================
// Handle PesaPal callback return — auto-confirm if OrderTrackingId present
if (isset($_GET['OrderTrackingId']) && isset($_GET['OrderMerchantReference'])) {
    $order_ref   = $_GET['OrderMerchantReference'];
    $order_track = $_GET['OrderTrackingId'];
    try {
        $db = db();
        // Find the pending payment by reference
        $pay_st = $db->prepare("SELECT * FROM subscription_payments WHERE reference = ? AND confirmed = 0 LIMIT 1");
        $pay_st->execute([$order_ref]);
        $pay_row = $pay_st->fetch();
        if ($pay_row) {
            $pay_id  = (int)$pay_row['id'];
            $cid     = (int)$pay_row['client_id'];
            $inv_id  = (int)($pay_row['invoice_id'] ?? 0);
            // Mark payment confirmed
            $db->prepare("UPDATE subscription_payments SET confirmed = 1, confirmed_at = NOW(), reference = ? WHERE id = ?")
               ->execute([$order_track, $pay_id]);
            // Mark invoice paid
            if ($inv_id) {
                $db->prepare("UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$inv_id]);
            }
            // Check if all invoices for this subscription are paid
            $all_st = $db->prepare("
                SELECT COUNT(*) FROM invoices
                WHERE subscription_id = (SELECT subscription_id FROM invoices WHERE id = ? LIMIT 1)
                  AND status NOT IN ('paid','void')
            ");
            $all_st->execute([$inv_id]);
            $unpaid_count = (int)$all_st->fetchColumn();
            if ($unpaid_count === 0) {
                // Activate client
                $db->prepare("UPDATE clients SET status = 'active' WHERE id = ?")->execute([$cid]);
                // Get latest subscription
                $sub_st = $db->prepare("SELECT * FROM subscriptions WHERE client_id = ? ORDER BY id DESC LIMIT 1");
                $sub_st->execute([$cid]);
                $sub_row = $sub_st->fetch();
                if ($sub_row) {
                    if (in_array($sub_row['status'], ['expired','grace','suspended'])) {
                        $ns  = date('Y-m-d');
                        $int = match($sub_row['billing_cycle'] ?? 'monthly') {
                            'annual'    => '+1 year',
                            'quarterly' => '+3 months',
                            default     => '+1 month',
                        };
                        $ne = date('Y-m-d', strtotime($ns . ' ' . $int));
                        $db->prepare("UPDATE subscriptions SET status = 'active', start_date = ?, end_date = ? WHERE id = ?")
                           ->execute([$ns, $ne, $sub_row['id']]);
                    } else {
                        $db->prepare("UPDATE subscriptions SET status = 'active' WHERE id = ?")->execute([$sub_row['id']]);
                    }
                }
                // Refresh session sub_status
                $_SESSION[SESS_KEY]['sub_status']     = 'active';
                $_SESSION[SESS_KEY]['account_status'] = 'active';
            }
            flash('Payment confirmed! Your subscription is now active.', 'success');
        }
    } catch (Throwable $e) {
        nx_debug($e, 'pesapal_callback');
    }
    // Redirect back to dashboard after payment confirmed
    if (is_logged_in()) {
        header('Location: ?page=dashboard');
    } else {
        flash('Payment received! Please log in to confirm your account is active.', 'success');
        header('Location: ?page=login');
    }
    exit;
}

handle_post();
$page  = $_GET['page'] ?? 'login';
$flash = get_flash();

// ── SESSION TIMEOUT — 5 minutes idle ─────────────────────────
define('SESSION_TIMEOUT', 300); // 5 minutes in seconds

if (is_logged_in()) {
    $last_active = $_SESSION['last_active'] ?? time();
    if ((time() - $last_active) > SESSION_TIMEOUT) {
        // Session expired — destroy and redirect
        $expired_name = $_SESSION[SESS_KEY]['name'] ?? '';
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        flash('Your session expired after 5 minutes of inactivity. Please sign in again.', 'error');
        header('Location: ?page=login');
        exit;
    }
    // Update last active timestamp on every request
    $_SESSION['last_active'] = time();
}

if ($page !== 'login' && !is_logged_in()) { header('Location: ?page=login'); exit; }
if ($page === 'login' &&  is_logged_in()) { header('Location: ?page=dashboard'); exit; }

// ── VALID PAGES WHITELIST — block URL manipulation ────────────
$valid_pages = ['login','dashboard','subscription','invoices','payments','branches','users','sales','reports','tickets','etims'];
if (!in_array($page, $valid_pages)) {
    $page = is_logged_in() ? 'dashboard' : 'login';
}

// ── LOCK SUSPENDED/INACTIVE USERS TO PAYMENTS PAGE ONLY ──────
if (is_logged_in()) {
    $locked_statuses = ['suspended', 'expired', 'grace', 'read_only', 'no_sub'];
    $current_sub_status = $_SESSION[SESS_KEY]['sub_status'] ?? 'no_sub';
    if (in_array($current_sub_status, $locked_statuses) && $page !== 'payments') {
        header('Location: ?page=payments');
        exit;
    }
}

// Always refresh sub_status from DB on each page load using invoice-aware check
$u = current_user();
if ($u) {
    try {
        $real_status = compute_real_sub_status(db(), $u['client_id']);
        // Also refresh account_status from clients table
        $ac_st = db()->prepare("SELECT status FROM clients WHERE id = ? LIMIT 1");
        $ac_st->execute([$u['client_id']]);
        $ac_row = $ac_st->fetch();
        $_SESSION[SESS_KEY]['sub_status']     = $real_status;
        $_SESSION[SESS_KEY]['account_status'] = $ac_row['status'] ?? $u['account_status'];
        $u = current_user();
    } catch (Throwable $e) { /* silent */ }
}

$sub = $u ? get_subscription($u['client_id']) : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0d0f14">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="NYMIX">
<meta name="mobile-web-app-capable" content="yes">
<title><?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.co">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<style>
:root {
  --bg:        #0d0f14; --surface:  #13161e; --surface2: #1a1e28;
  --surface3:  #222636; --border:   #2a2f3f; --accent:   #4f8ef7;
  --accent2:   #7c6af7; --green:    #3ecf8e; --yellow:   #f5c542;
  --red:       #f75555; --orange:   #f7924f; --text:     #e8eaf0;
  --text2:     #8b91a8; --text3:    #5a6075; --radius:   10px;
  --radius-lg: 16px;    --shadow:   0 4px 24px rgba(0,0,0,.4);
  --tr:        .18s ease; --sw: 240px;
  --font-head: 'Syne', sans-serif; --font-body: 'DM Sans', sans-serif;
}
[data-theme="light"] {
  --bg:      #f0f2f5; --surface:  #ffffff; --surface2: #f5f6fa;
  --surface3:#eaecf0; --border:   #d1d5e0; --text:     #1a1d26;
  --text2:   #4a4f63; --text3:    #8b91a8;
}
[data-theme="light"] .form-control { background:#f5f6fa; border-color:#d1d5e0; color:#1a1d26; }
[data-theme="light"] .form-control:focus { background:#fff; color:#1a1d26; }
[data-theme="light"] .card { background:#fff; border-color:#d1d5e0; }
[data-theme="light"] .modal-backdrop .modal { background:#fff; border-color:#d1d5e0; color:#1a1d26; }
[data-theme="light"] thead th { background:#f0f2f5; color:#4a4f63; border-bottom-color:#d1d5e0; }
[data-theme="light"] tbody tr { border-bottom-color:#e2e5ed; }
[data-theme="light"] tbody tr:hover { background:#f0f2f5; }
[data-theme="light"] td { color:#1a1d26; }
[data-theme="light"] .stat-card { background:#fff; border-color:#d1d5e0; }
[data-theme="light"] .sub-detail { background:linear-gradient(135deg,rgba(79,142,247,.06),rgba(124,106,247,.05)); border-color:rgba(79,142,247,.15); }
[data-theme="light"] .sidebar { background:#fff; border-right-color:#d1d5e0; }
[data-theme="light"] .sidebar-bottom { border-top-color:#d1d5e0; }
[data-theme="light"] .sidebar-logo { border-bottom-color:#d1d5e0; }
[data-theme="light"] .topbar { background:#fff; border-bottom-color:#d1d5e0; }
[data-theme="light"] .nav-item { color:#4a4f63; }
[data-theme="light"] .nav-item:hover { background:#f0f2f5; color:#1a1d26; }
[data-theme="light"] .nav-item.active { background:#edf2ff; color:var(--accent); }
[data-theme="light"] .user-chip { background:#f0f2f5; }
[data-theme="light"] .btn-ghost { border-color:#d1d5e0; color:#4a4f63; }
[data-theme="light"] .btn-ghost:hover { background:#f0f2f5; color:#1a1d26; }
[data-theme="light"] .btn-logout { background:#f0f2f5; border-color:#d1d5e0; color:#4a4f63; }
[data-theme="light"] .bubble.client { background:#eaecf0; color:#1a1d26; }
[data-theme="light"] .bubble.admin { background:rgba(79,142,247,.12); }
[data-theme="light"] .flash-success { background:rgba(22,163,74,.1); border-color:rgba(22,163,74,.3); color:#15803d; }
[data-theme="light"] .flash-error { background:rgba(220,38,38,.08); border-color:rgba(220,38,38,.25); color:#b91c1c; }
[data-theme="light"] .review-box { background:#f5f6fa; border-color:#d1d5e0; }
[data-theme="light"] .table-wrap::after { background:linear-gradient(to left,#fff,transparent); }
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:16px;overflow-x:hidden}
body{background:var(--bg);color:var(--text);font-family:var(--font-body);min-height:100vh;overflow-x:hidden;-webkit-text-size-adjust:100%}
a{color:var(--accent);text-decoration:none}
a:hover{color:#7fb3ff}
h1,h2,h3,h4{font-family:var(--font-head)}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--surface)}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px}

/* layout */
.layout{display:flex;min-height:100vh}
.sidebar{width:var(--sw);background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;height:100dvh;z-index:200;transition:transform .25s ease;overflow-y:auto;padding-top:env(safe-area-inset-top);padding-left:env(safe-area-inset-left)}
.main{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-height:100vh;min-height:100dvh}
.topbar{height:60px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 24px;padding-left:max(24px,env(safe-area-inset-left));padding-right:max(24px,env(safe-area-inset-right));position:sticky;top:0;z-index:50;min-width:0;overflow:hidden}
.content{padding:28px;padding-bottom:max(28px,env(safe-area-inset-bottom));flex:1;-webkit-overflow-scrolling:touch;overscroll-behavior-y:contain}

/* sidebar */
.sidebar-logo{padding:20px 20px 12px;border-bottom:1px solid var(--border)}
.sidebar-logo .logo-text{font-family:var(--font-head);font-size:1.15rem;font-weight:800;color:var(--text);letter-spacing:-.3px}
.sidebar-logo .logo-sub{font-size:.72rem;color:var(--text3);margin-top:1px;text-transform:uppercase;letter-spacing:1px}
.nav-section{padding:10px 0 4px}
.nav-label{font-size:.65rem;font-weight:600;letter-spacing:1.5px;text-transform:uppercase;color:var(--text3);padding:0 16px 6px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 16px;cursor:pointer;color:var(--text2);font-size:.875rem;transition:all var(--tr);border-left:3px solid transparent;text-decoration:none}
.nav-item:hover{background:var(--surface2);color:var(--text)}
.nav-item.active{background:var(--surface2);color:var(--accent);border-left-color:var(--accent)}
.nav-item .icon{width:18px;text-align:center;flex-shrink:0}
.sidebar-bottom{margin-top:auto;padding:16px;border-top:1px solid var(--border)}
.user-chip{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--surface2);border-radius:var(--radius);margin-bottom:10px}
.user-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0}
.user-info{min-width:0}
.user-name{font-size:.8rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;max-width:120px}
.user-role{font-size:.7rem;color:var(--text3);text-transform:capitalize}

/* sub banner */
.sub-banner{margin:12px 12px 0;padding:10px 12px;border-radius:var(--radius);font-size:.72rem;line-height:1.4}
.sub-banner.active   {background:rgba(62,207,142,.12);border:1px solid rgba(62,207,142,.25);color:var(--green)}
.sub-banner.grace    {background:rgba(245,197,66,.12);border:1px solid rgba(245,197,66,.25);color:var(--yellow)}
.sub-banner.read_only{background:rgba(247,85,85,.1); border:1px solid rgba(247,85,85,.25); color:var(--red)}
.sub-banner.expired  {background:rgba(247,85,85,.1); border:1px solid rgba(247,85,85,.25); color:var(--red)}
.sub-banner.suspended{background:rgba(247,146,79,.1);border:1px solid rgba(247,146,79,.25);color:var(--orange)}
.sub-banner.no_sub   {background:rgba(247,85,85,.1); border:1px solid rgba(247,85,85,.25); color:var(--red)}
.sub-banner strong{display:block;font-weight:700;margin-bottom:2px}

/* topbar */
.page-title{font-family:var(--font-head);font-size:1.1rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:55vw}
.topbar-right{display:flex;align-items:center;gap:12px}
.btn-logout{background:var(--surface2);border:1px solid var(--border);color:var(--text2);padding:6px 14px;border-radius:var(--radius);cursor:pointer;font-size:.8rem;transition:all var(--tr);font-family:var(--font-body)}
.btn-logout:hover{background:var(--surface3);color:var(--text)}

/* cards */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px;min-width:0;overflow:hidden;box-sizing:border-box;width:100%}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:8px;min-width:0;flex-wrap:wrap}
.card-title{font-family:var(--font-head);font-size:.95rem;font-weight:700;color:var(--text);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}

/* stat cards */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:24px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;position:relative;overflow:hidden;transition:transform var(--tr),border-color var(--tr);min-width:0;box-sizing:border-box;width:100%}
.stat-card:hover{transform:translateY(-2px);border-color:var(--surface3)}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--card-accent,var(--border))}
.stat-card.blue::before  {background:var(--accent)}
.stat-card.green::before {background:var(--green)}
.stat-card.yellow::before{background:var(--yellow)}
.stat-card.purple::before{background:var(--accent2)}
.stat-card.orange::before{background:var(--orange)}
.stat-card.red::before   {background:var(--red)}
.stat-label{font-size:.72rem;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;font-weight:600;margin-bottom:8px}
.stat-value{font-family:var(--font-head);font-size:clamp(.95rem,3.5vw,1.75rem);font-weight:800;color:var(--text);line-height:1.1;margin-bottom:4px;word-break:break-all;overflow-wrap:anywhere;max-width:100%}
.stat-sub{font-size:.72rem;color:var(--text3)}
.stat-icon{position:absolute;top:18px;right:18px;font-size:1.6rem;opacity:.12}

/* tables */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;border-radius:var(--radius);scroll-snap-type:x proximity;position:relative}
.table-wrap::after{content:'';position:absolute;top:0;right:0;width:24px;height:100%;background:linear-gradient(to left,var(--surface),transparent);pointer-events:none;border-radius:0 var(--radius) var(--radius) 0;opacity:.7}
@media print{.table-wrap::after{display:none}.sidebar{display:none}.topbar{display:none}.main{margin-left:0}}
table{width:100%;border-collapse:collapse;font-size:.9rem}
thead th{background:var(--surface2);color:var(--text3);font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;font-weight:600;padding:10px 14px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background var(--tr)}
tbody tr:hover{background:var(--surface2)}
tbody tr:last-child{border-bottom:none}
td{padding:11px 14px;color:var(--text);vertical-align:middle;word-break:break-word;overflow-wrap:anywhere;max-width:280px}
.td-muted{color:var(--text3);font-size:.82rem}

/* badges */
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:99px;font-size:.7rem;font-weight:600;letter-spacing:.3px}
.badge-green {background:rgba(62,207,142,.15); color:var(--green)}
.badge-yellow{background:rgba(245,197,66,.15); color:var(--yellow)}
.badge-red   {background:rgba(247,85,85,.12);  color:var(--red)}
.badge-blue  {background:rgba(79,142,247,.15); color:var(--accent)}
.badge-purple{background:rgba(124,106,247,.15);color:var(--accent2)}
.badge-gray  {background:rgba(139,145,168,.1); color:var(--text2)}
.badge-orange{background:rgba(247,146,79,.12); color:var(--orange)}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:var(--radius);font-size:.855rem;font-weight:500;cursor:pointer;transition:all var(--tr);border:none;font-family:var(--font-body);white-space:nowrap}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:#3d7de8}
.btn-success{background:var(--green);color:#0a2a1f}
.btn-success:hover{background:#34b87a}
.btn-danger{background:var(--red);color:#fff}
.btn-danger:hover{background:#e04444}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text2)}
.btn-ghost:hover{background:var(--surface2);color:var(--text)}
.btn-sm{padding:5px 12px;font-size:.78rem}
.btn-xs{padding:3px 9px;font-size:.72rem}

/* forms */
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group label{font-size:.78rem;color:var(--text2);font-weight:500}
.form-control{background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:var(--radius);padding:9px 13px;font-size:.875rem;font-family:var(--font-body);transition:border-color var(--tr);width:100%}
.form-control:focus{outline:none;border-color:var(--accent)}
.form-control::placeholder{color:var(--text3)}
select.form-control{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%238b91a8'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:30px}
textarea.form-control{resize:vertical;min-height:90px}
.form-actions{margin-top:20px;display:flex;gap:10px;align-items:center}

/* flash */
.flash{padding:13px 18px;border-radius:var(--radius);margin-bottom:20px;font-size:.875rem;display:flex;align-items:center;gap:10px;animation:slideDown .25s ease}
.flash-success{background:rgba(62,207,142,.12);border:1px solid rgba(62,207,142,.3);color:var(--green)}
.flash-error  {background:rgba(247,85,85,.1);  border:1px solid rgba(247,85,85,.3); color:var(--red)}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
@keyframes pulse{0%,100%{box-shadow:0 0 0 3px rgba(247,85,85,.4)}50%{box-shadow:0 0 0 6px rgba(247,85,85,.1)}}

/* modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.65);display:none;align-items:center;justify-content:center;z-index:400;backdrop-filter:blur(3px)}
.modal-backdrop.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:26px;width:90%;max-width:500px;max-height:90vh;overflow-y:auto;animation:fadeUp .2s ease}
.modal-lg{max-width:680px}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.modal-title{font-family:var(--font-head);font-size:1rem;font-weight:700}
.modal-close{background:none;border:none;color:var(--text3);cursor:pointer;font-size:1.2rem;line-height:1;padding:4px}
.modal-close:hover{color:var(--text)}

/* ticket thread */
.thread{display:flex;flex-direction:column;gap:12px;margin-bottom:16px}
.bubble{max-width:80%;padding:12px 16px;border-radius:var(--radius-lg);font-size:.875rem;line-height:1.55}
.bubble.client{background:var(--surface3);border-bottom-left-radius:4px;align-self:flex-start}
.bubble.admin {background:rgba(79,142,247,.15);border:1px solid rgba(79,142,247,.2);border-bottom-right-radius:4px;align-self:flex-end}
.bubble-meta{font-size:.7rem;color:var(--text3);margin-top:5px}

/* sub detail */
.sub-detail{background:linear-gradient(135deg,rgba(79,142,247,.08),rgba(124,106,247,.08));border:1px solid rgba(79,142,247,.2);border-radius:var(--radius-lg);padding:22px;margin-bottom:24px}
.sub-plan-name{font-family:var(--font-head);font-size:clamp(1.1rem,4vw,1.5rem);font-weight:800;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
.sub-meta-row{display:flex;flex-wrap:wrap;gap:24px;margin-top:14px}
.sub-meta-item{display:flex;flex-direction:column;gap:3px;min-width:0;overflow:hidden}
.sub-meta-label{font-size:.7rem;color:var(--text3);text-transform:uppercase;letter-spacing:.7px}
.sub-meta-val{font-size:.95rem;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
.progress-bar{height:6px;background:var(--surface3);border-radius:99px;overflow:hidden;margin-top:16px}
.progress-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--accent),var(--accent2));transition:width .5s ease}

/* empty state */
.empty-state{text-align:center;padding:48px 24px;color:var(--text3)}
.empty-state .empty-icon{font-size:2.5rem;margin-bottom:12px;opacity:.4}
.empty-state p{font-size:.9rem}

/* login */
.login-page{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--bg);background-image:radial-gradient(ellipse at 20% 50%,rgba(79,142,247,.07) 0%,transparent 60%),radial-gradient(ellipse at 80% 20%,rgba(124,106,247,.06) 0%,transparent 50%)}
.login-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:36px 40px;width:100%;max-width:420px;box-shadow:var(--shadow)}
.login-logo{text-align:center;margin-bottom:24px}
.login-logo .brand{font-family:var(--font-head);font-size:1.6rem;font-weight:800}
.login-logo .brand span{color:var(--accent)}
.login-logo p{font-size:.8rem;color:var(--text3);margin-top:4px}

/* auth tabs */
.auth-tabs{display:flex;margin-bottom:24px;background:var(--surface2);border-radius:var(--radius);padding:4px}
.auth-tab{flex:1;padding:8px;border:none;background:transparent;color:var(--text3);font-family:var(--font-head);font-size:.85rem;font-weight:600;cursor:pointer;border-radius:calc(var(--radius) - 2px);transition:all var(--tr)}
.auth-tab.active{background:var(--surface3);color:var(--text)}
.tab-panel{animation:fadeIn .2s ease}
.tab-panel.hidden{display:none}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.btn-full{width:100%;justify-content:center;padding:11px}
.auth-switch{text-align:center;margin-top:18px;font-size:.78rem;color:var(--text3)}

/* register */
.reg-info{display:flex;gap:10px;align-items:flex-start;background:rgba(79,142,247,.08);border:1px solid rgba(79,142,247,.2);border-radius:var(--radius);padding:11px 13px;font-size:.78rem;color:var(--text2);margin-bottom:20px;line-height:1.5}
.reg-info-icon{font-size:1rem;flex-shrink:0;margin-top:1px}
.steps{display:flex;align-items:center;margin-bottom:22px}
.step{width:28px;height:28px;border-radius:50%;background:var(--surface2);border:2px solid var(--border);color:var(--text3);font-size:.75rem;font-weight:700;display:flex;align-items:center;justify-content:center;transition:all .25s ease;flex-shrink:0}
.step.active{background:var(--accent);border-color:var(--accent);color:#fff}
.step.done  {background:var(--green); border-color:var(--green); color:#fff}
.step-line{flex:1;height:2px;background:var(--border);margin:0 6px;transition:background .25s}
.step-line.done{background:var(--green)}
.step-title{font-family:var(--font-head);font-size:.95rem;font-weight:700;margin-bottom:18px;color:var(--text)}
.field-hint{font-size:.7rem;color:var(--text3);margin-top:4px}
.req{color:var(--red)}

/* password */
.pw-wrap{position:relative}
.pw-wrap .form-control{padding-right:40px}
.pw-eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:.9rem;color:var(--text3);padding:4px;transition:color var(--tr)}
.pw-eye:hover{color:var(--text)}
.pw-strength-bar{height:4px;background:var(--surface3);border-radius:99px;overflow:hidden;margin-top:8px}
.pw-strength-fill{height:100%;width:0;border-radius:99px;transition:width .3s ease,background .3s ease}
.pw-strength-label{font-size:.68rem;margin-top:4px;color:var(--text3)}

/* review */
.review-box{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:18px}
.review-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border);font-size:.84rem}
.review-row:last-child{border-bottom:none}
.review-label{color:var(--text3);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px}
.review-val{font-weight:600;color:var(--text)}

/* helpers */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;min-width:0;overflow:hidden}
.grid-2>*{min-width:0;overflow:hidden}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;min-width:0;overflow:hidden}
.grid-3>*{min-width:0;overflow:hidden}
.mb-4{margin-bottom:16px} .mb-6{margin-bottom:24px} .mb-8{margin-bottom:32px}
.mt-4{margin-top:16px}    .gap-4{gap:16px}
.flex{display:flex}        .items-center{align-items:center}
.justify-between{justify-content:space-between} .flex-wrap{flex-wrap:wrap}
.text-sm{font-size:.8rem} .text-muted{color:var(--text3)}

/* ═══ MOBILE CARD TABLE ═══ */
@media(max-width:600px){
  .mobile-card-table thead { display:none }
  .mobile-card-table tbody tr {
    display:block;
    background:var(--surface2);
    border:1px solid var(--border);
    border-radius:var(--radius);
    margin-bottom:10px;
    padding:12px 14px;
    min-width:0 !important;
  }
  .mobile-card-table tbody tr:hover { background:var(--surface3) }
  .mobile-card-table td {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    padding:6px 0;
    border:none;
    font-size:.82rem;
    min-width:0 !important;
    width:100%;
    gap:8px;
    overflow:hidden;
  }
  .mobile-card-table td > * {
    min-width:0;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .mobile-card-table td > *:last-child {
    flex-shrink:0;
    text-align:right;
    overflow:visible;
  }
  .mobile-card-table td::before {
    content:attr(data-label);
    font-size:.68rem;
    color:var(--text3);
    text-transform:uppercase;
    letter-spacing:.6px;
    font-weight:600;
    flex-shrink:0;
    margin-right:12px;
    white-space:nowrap;
  }
  .mobile-card-table td[data-label=""]::before { display:none }
  .mobile-card-table td[data-label=""] { justify-content:flex-end }
  .stats-grid { grid-template-columns:1fr }
  .steps { gap:0 }
  .step { width:24px;height:24px;font-size:.7rem }
  .sub-meta-row { flex-direction:column;gap:10px;width:100% }
}


/* ── BURGER ── */
.burger{display:none;flex-direction:column;justify-content:center;gap:5px;background:none;border:none;cursor:pointer;padding:6px;border-radius:var(--radius);transition:background var(--tr)}
.burger:hover{background:var(--surface2)}
.burger span{display:block;width:20px;height:2px;background:var(--text2);border-radius:99px;transition:all .25s ease;transform-origin:center}
.burger[aria-expanded="true"] span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.burger[aria-expanded="true"] span:nth-child(2){opacity:0;transform:scaleX(0)}
.burger[aria-expanded="true"] span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}

/* ═══ RESPONSIVE ═══ */
@media(max-width:768px){
  /* prevent ALL horizontal scroll on mobile */
  html,body{overflow-x:hidden;max-width:100vw}
  .layout{overflow-x:hidden;max-width:100vw}

  /* sidebar */
  .sidebar{
    transform:translateX(-100%);
    width:100%;
    max-width:280px;
    box-shadow:none;
    transition:transform .25s ease, box-shadow .25s ease;
  }
  .sidebar.open{
    transform:translateX(0);
    box-shadow:8px 0 40px rgba(0,0,0,.7);
  }

  /* main */
  .main{margin-left:0}
  .content{padding:14px}

  /* topbar */
  .topbar{padding:0 14px;height:54px;left:0!important;}
  .page-title{font-size:.95rem}
  .topbar-right .text-sm{display:none}

  /* burger */
  .burger{display:flex !important}
  /* fix sidebar z-index on mobile */
  .sidebar{z-index:300}
  .main{margin-left:0!important}

  /* grids */
  .stats-grid{grid-template-columns:1fr;gap:10px}
  .grid-2,.grid-3{grid-template-columns:1fr;gap:14px}
  .form-grid{grid-template-columns:1fr}

  /* cards */
  .card{padding:16px}
  .card-header{margin-bottom:14px}
  .sub-detail{padding:16px}
  .sub-meta-row{gap:14px}

  /* tables — horizontal scroll for regular tables */
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:var(--radius);max-width:calc(100vw - 28px)}
  table:not(.mobile-card-table){min-width:0;font-size:.88rem}
  thead th{padding:8px 10px;font-size:.72rem}
  td{padding:10px 10px;font-size:.88rem}

  /* bigger base fonts on mobile */
  .nav-item{font-size:.95rem}
  .stat-value{font-size:1.5rem}
  .stat-label{font-size:.78rem}
  .card-title{font-size:1rem}
  .badge{font-size:.72rem;padding:3px 9px}
  .btn{font-size:.88rem}
  .form-control{font-size:1rem}
  label{font-size:.85rem}
  td,.td-muted{font-size:.88rem}
  .bubble{font-size:.92rem}
  .sub-plan-name{font-size:1.3rem}
  p{font-size:.95rem}

  /* login */
  .login-card{margin:16px;padding:24px 20px;width:calc(100% - 32px)}
  .login-logo .brand{font-size:1.4rem}
  .auth-tabs{margin-bottom:18px}

  /* buttons */
  .btn{padding:8px 14px;font-size:.82rem}
  .btn-full{padding:12px}

  /* modals */
  .modal{width:calc(100% - 20px);padding:16px 14px;max-height:88vh}
  .modal-lg{max-width:100%}

  /* stat cards */
  .stat-card{padding:14px}
  .stat-value{font-size:1.4rem}
  .stat-icon{font-size:1.2rem;top:12px;right:12px}

  /* subscription detail */
  .sub-plan-name{font-size:1.25rem}
  .sub-meta-item{min-width:120px}

  /* badges */
  .badge{font-size:.65rem;padding:2px 7px}

  /* form actions */
  .form-actions{flex-wrap:wrap;width:100%}
  .form-actions .btn{flex:1;min-width:120px;justify-content:center}

  /* ticket thread */
  .bubble{max-width:95%}

  /* nav items */
  .nav-item{padding:10px 14px;font-size:.875rem}

  /* branch grid */
  .grid-3{grid-template-columns:1fr}
  /* branch stats inner grid */
  [style*="repeat(3,minmax"]{grid-template-columns:1fr!important}

  /* topbar badge hide on mobile */
  /* .topbar-right .badge { display:none } */

  /* lock screen mobile */
  #nx-lock-card { padding:32px 20px; margin:16px; width:calc(100% - 32px) }
}

/* ── small phones ── */
@media(max-width:400px){
  .stats-grid { grid-template-columns:1fr }
  .content { padding:10px }
  .card { padding:12px }
  .stat-value { font-size:1.2rem }
  .login-card { margin:10px; padding:18px 14px }
  .topbar { padding:0 10px }
  table { min-width:0 }
}

/* ── tablet ── */
@media(min-width:769px) and (max-width:1024px){
  :root { --sw:200px }
  .stats-grid { grid-template-columns:repeat(2,1fr) }
  .grid-3 { grid-template-columns:1fr 1fr }
  .content { padding:20px }
}
</style>
</head>
<body>



<!-- APP LOADING SPLASH -->
<div id="nx-splash" style="position:fixed;inset:0;z-index:999999;background:#0d0f14;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:opacity .35s ease,visibility .35s ease">
  <div style="font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;color:#e8eaf0;letter-spacing:-.5px;margin-bottom:6px">NY<span style="color:#4f8ef7">MIX</span></div>
  <div style="font-size:.72rem;color:#5a6075;text-transform:uppercase;letter-spacing:2px;margin-bottom:36px">Hardware Portal</div>
  <div style="width:44px;height:44px;position:relative">
    <svg viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:44px;height:44px;animation:splashSpin 1s linear infinite">
      <circle cx="22" cy="22" r="18" stroke="#2a2f3f" stroke-width="3"/>
      <path d="M22 4a18 18 0 0 1 18 18" stroke="#4f8ef7" stroke-width="3" stroke-linecap="round"/>
    </svg>
  </div>
</div>
<style>
@keyframes splashSpin{to{transform:rotate(360deg)}}
</style>
<script>
window.addEventListener('load',function(){
  var s=document.getElementById('nx-splash');
  if(!s)return;
  setTimeout(function(){
    s.style.opacity='0';
    s.style.visibility='hidden';
    setTimeout(function(){s.remove()},350);
  },600);
});
document.addEventListener('click',function(e){
  var a=e.target.closest('a[href]');
  if(!a||a.target==="_blank"||a.href.startsWith('#')||a.href.startsWith('javascript'))return;
  var s=document.getElementById('nx-splash');
  if(!s){
    s=document.createElement('div');
    s.id='nx-splash';
    s.style.cssText='position:fixed;inset:0;z-index:999999;background:#0d0f14;display:flex;flex-direction:column;align-items:center;justify-content:center;opacity:0;transition:opacity .2s ease';
    s.innerHTML='<div style="font-family:Syne,sans-serif;font-size:2rem;font-weight:800;color:#e8eaf0;letter-spacing:-.5px;margin-bottom:6px">NY<span style="color:#4f8ef7">MIX</span></div><svg viewBox="0 0 44 44" fill="none" style="width:44px;height:44px;animation:splashSpin 1s linear infinite"><circle cx="22" cy="22" r="18" stroke="#2a2f3f" stroke-width="3"/><path d="M22 4a18 18 0 0 1 18 18" stroke="#4f8ef7" stroke-width="3" stroke-linecap="round"/></svg>';
    document.body.appendChild(s);
  }
  requestAnimationFrame(function(){s.style.opacity='1'});
});
</script>

<?php $auth_tab = $_GET['tab'] ?? 'login'; ?>

<?php if ($page === 'login'): ?>
<!-- ════════════════ LOGIN / REGISTER ════════════════ -->
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="brand">NY<span>MIX</span></div>
      <p>Business Management Portal</p>
    </div>

    <?php if ($flash): ?>
    <?php if ($flash['type'] === 'sub_warn'): ?>
    <div style="background:rgba(247,85,85,.1);border:2px solid rgba(247,85,85,.35);border-radius:var(--radius);padding:16px 18px;margin-bottom:20px;animation:slideDown .25s ease;">
      <div style="font-family:var(--font-head);font-size:.95rem;font-weight:700;color:var(--red);margin-bottom:6px;">⛔ <?= strip_tags($flash['msg']) ?></div>
      <div style="font-size:.8rem;color:var(--text3);margin-bottom:12px;">Log in to access your payment page and pay your outstanding invoice.</div>
      <a href="?page=payments" class="btn btn-primary btn-sm" style="display:inline-flex;">💳 Go to Payments</a>
    </div>
    <?php else: ?>
    <div class="flash flash-<?= $flash['type'] ?>">
      <?= $flash['type']==='success' ? '✓' : '✕' ?>
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="auth-tabs">
      <button class="auth-tab <?= $auth_tab==='login'    ? 'active' : '' ?>" onclick="switchTab('login')">Sign In</button>
      <button class="auth-tab <?= $auth_tab==='register' ? 'active' : '' ?>" onclick="switchTab('register')">Register</button>
    </div>

    <!-- SIGN IN -->
    <div id="tab-login" class="tab-panel <?= $auth_tab!=='login' ? 'hidden' : '' ?>">
      <form method="POST" action="?page=login">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <div class="form-group mb-4">
          <label>Email address</label>
          <input type="email" name="email" class="form-control" placeholder="you@business.com" required autofocus>
        </div>
        <div class="form-group mb-4">
          <label>Password</label>
          <div class="pw-wrap">
            <input type="password" name="password" id="pw-login" class="form-control" placeholder="••••••••" required>
            <button type="button" class="pw-eye" onclick="togglePw('pw-login',this)">👁</button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Sign In →</button>
      </form>
      <p class="auth-switch">New business? <a href="#" onclick="switchTab('register')">Create your account</a></p>
    </div>

    <!-- REGISTER -->
    <div id="tab-register" class="tab-panel <?= $auth_tab!=='register' ? 'hidden' : '' ?>">
      <div class="reg-info">
        <span class="reg-info-icon">ℹ</span>
        <span>Your business must be pre-registered by <strong>NYMIX TECH</strong> before you can create a portal account.</span>
      </div>
      <form method="POST" action="?page=login&tab=register" id="regForm">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">

        <div class="steps">
          <div class="step active" id="step-dot-1">1</div>
          <div class="step-line" id="step-line-1"></div>
          <div class="step" id="step-dot-2">2</div>
          <div class="step-line" id="step-line-2"></div>
          <div class="step" id="step-dot-3">3</div>
        </div>

        <!-- Step 1 -->
        <div class="reg-step" id="reg-step-1">
          <div class="step-title">Verify Your Business</div>
          <div class="form-group mb-4">
            <label>KRA PIN <span class="req">*</span></label>
            <input type="text" name="kra_pin" id="kra_pin" class="form-control" placeholder="e.g. P051234567X" oninput="this.value=this.value.toUpperCase()" maxlength="30" required>
            <div class="field-hint">The KRA PIN registered with NYMIX TECH</div>
          </div>
          <div class="form-group mb-4">
            <label>Registered Phone <span class="req">*</span></label>
            <input type="tel" name="phone" id="reg_phone" class="form-control" placeholder="07XXXXXXXX" required>
            <div class="field-hint">Must match what NYMIX TECH has on file</div>
          </div>
          <button type="button" class="btn btn-primary btn-full" onclick="goStep(2)">Continue →</button>
        </div>

        <!-- Step 2 -->
        <div class="reg-step hidden" id="reg-step-2">
          <div class="step-title">Set Your Credentials</div>
          <div class="form-group mb-4">
            <label>Email Address <span class="req">*</span></label>
            <input type="email" name="email" class="form-control" placeholder="owner@yourbusiness.com" required>
            <div class="field-hint">Must match what NYMIX TECH has on file</div>
          </div>
          <div class="form-group mb-4">
            <label>Password <span class="req">*</span></label>
            <div class="pw-wrap">
              <input type="password" name="password" id="pw-reg" class="form-control" placeholder="Min 8 characters" minlength="8" oninput="checkPwStrength(this.value)" required>
              <button type="button" class="pw-eye" onclick="togglePw('pw-reg',this)">👁</button>
            </div>
            <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwFill"></div></div>
            <div class="pw-strength-label" id="pwLabel"></div>
          </div>
          <div class="form-group mb-4">
            <label>Confirm Password <span class="req">*</span></label>
            <div class="pw-wrap">
              <input type="password" name="password2" id="pw-reg2" class="form-control" placeholder="Repeat password" minlength="8" required>
              <button type="button" class="pw-eye" onclick="togglePw('pw-reg2',this)">👁</button>
            </div>
          </div>
          <div style="display:flex;gap:10px">
            <button type="button" class="btn btn-ghost" onclick="goStep(1)" style="flex:1">← Back</button>
            <button type="button" class="btn btn-primary" onclick="goStep(3)" style="flex:2">Review →</button>
          </div>
        </div>

        <!-- Step 3 -->
        <div class="reg-step hidden" id="reg-step-3">
          <div class="step-title">Confirm & Create Account</div>
          <div class="review-box">
            <div class="review-row"><span class="review-label">KRA PIN</span><span class="review-val" id="rv-kra">—</span></div>
            <div class="review-row"><span class="review-label">Phone</span><span class="review-val" id="rv-phone">—</span></div>
            <div class="review-row"><span class="review-label">Email</span><span class="review-val" id="rv-email">—</span></div>
            <div class="review-row"><span class="review-label">Password</span><span class="review-val">••••••••</span></div>
          </div>
          <div class="reg-info" style="margin-bottom:16px">
            <span class="reg-info-icon">🔒</span>
            <span>By registering you confirm this is your business account.</span>
          </div>
          <div style="display:flex;gap:10px">
            <button type="button" class="btn btn-ghost" onclick="goStep(2)" style="flex:1">← Back</button>
            <button type="submit" class="btn btn-success" style="flex:2">✓ Create Account</button>
          </div>
        </div>
      </form>
      <p class="auth-switch">Already have an account? <a href="#" onclick="switchTab('login')">Sign in</a></p>
    </div>
  </div>
  <p style="margin-top:18px;text-align:center;font-size:.75rem;color:var(--text3)">
    Powered by <strong style="color:var(--text2)">NYMIX TECH</strong>
  </p>
</div>

<?php else: /* ════ AUTHENTICATED LAYOUT ════ */ ?>

<?php
$nav = [
  'main'     => [['page'=>'dashboard',    'icon'=>'⊞','label'=>'Dashboard',   'perm'=>'dashboard']],
  'business' => [
    ['page'=>'sales',       'icon'=>'⚡','label'=>'Sales',       'perm'=>'sales'],
    ['page'=>'reports',     'icon'=>'📊','label'=>'Reports',     'perm'=>'reports'],
    ['page'=>'branches',    'icon'=>'🏪','label'=>'Branches',    'perm'=>'branches'],
    ['page'=>'users',       'icon'=>'👥','label'=>'Users',       'perm'=>'users'],
    ['page'=>'etims',       'icon'=>'🧾','label'=>'eTIMS / KRA', 'perm'=>'branches'],
  ],
  'account'  => [
    ['page'=>'subscription','icon'=>'💎','label'=>'Subscription','perm'=>'subscription'],
    ['page'=>'invoices',    'icon'=>'🧾','label'=>'Invoices',    'perm'=>'invoices'],
    ['page'=>'payments',    'icon'=>'💳','label'=>'Payments',    'perm'=>'payments'],
    ['page'=>'tickets',     'icon'=>'🎫','label'=>'Support',     'perm'=>'tickets'],
  ],
];
$sub_status = $sub['status'] ?? ($u['sub_status'] ?? 'no_sub');
$sub_labels = ['active'=>'Active','grace'=>'Grace Period','read_only'=>'Read Only','expired'=>'Expired','suspended'=>'Suspended','no_sub'=>'No Subscription'];
?>

<div class="layout">
<div id="nx-overlay" onclick="nxCloseSidebar()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:199;backdrop-filter:blur(2px)"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-text">NYMIX</div>
    <div class="logo-sub"><?= htmlspecialchars($u['business_name'] ?? $u['name']) ?></div>
  </div>

  <?php if ($sub): ?>
  <div class="sub-banner <?= htmlspecialchars($sub_status) ?>">
    <strong><?= htmlspecialchars($sub['plan_name'] ?? '') ?> — <?= $sub_labels[$sub_status] ?? $sub_status ?></strong>
    <?php if (isset($sub['days_remaining'])): ?>
      <?= $sub['days_remaining'] > 0 ? $sub['days_remaining'].' days left' : 'Expires today' ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php foreach ($nav as $section => $items): ?>
  <div class="nav-section">
    <div class="nav-label"><?= ucfirst($section) ?></div>
    <?php foreach ($items as $item):
      if (!can($item['perm'])) continue; ?>
    <a href="?page=<?= $item['page'] ?>" class="nav-item <?= $page===$item['page']?'active':'' ?>">
      <span class="icon"><?= $item['icon'] ?></span><?= $item['label'] ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <div class="sidebar-bottom">
    <div class="user-chip">
      <div class="user-avatar"><?= strtoupper(substr($u['name'],0,1)) ?></div>
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
        <div class="user-role"><?= htmlspecialchars($u['role']??'') ?><?= !empty($u['branch_name']) ? ' · '.htmlspecialchars($u['branch_name']) : '' ?></div>
      </div>
    </div>
    <form method="POST" style="width:100%">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
      <button type="submit" class="btn-logout" style="width:100%">Sign out</button>
    </form>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:12px;min-width:0;overflow:hidden">
      <button class="burger" id="nx-burger" aria-label="Toggle menu">
        <span></span><span></span><span></span>
      </button>
      <div class="page-title">
        <?= ucfirst($page) ?>
        <?php if (!empty($u['branch_name']) && !is_owner()): ?>
          <span style="font-size:.75rem;font-weight:400;color:var(--text3);margin-left:6px">@ <?= htmlspecialchars($u['branch_name']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="topbar-right">
      <?php if ($sub_status==='grace'):     ?><span class="badge badge-yellow">⚠ Grace period</span><?php endif; ?>
      <?php if ($sub_status==='read_only'): ?><span class="badge badge-red">🔒 Read only</span><?php endif; ?>
      <?php if ($sub_status==='suspended'): ?><span class="badge badge-orange">⛔ Suspended</span><?php endif; ?>
      <?php if(is_owner()):
        $unread_notifs = count_unread_notifications($u['client_id']);
        $notifs = get_stock_notifications($u['client_id'], 30);
        $reason_icons = ['damage'=>'💥','theft'=>'🚨','recount'=>'📋','return'=>'↩️','found'=>'✅','other'=>'📝','adjustment'=>'⚙️'];
      ?>
      <div style="position:relative" id="nx-notif-wrap">
        <button onclick="nxToggleNotif()" style="background:none;border:none;cursor:pointer;font-size:1.1rem;padding:4px 8px;color:var(--text2);position:relative" title="Stock Alerts">
          🔔
          <span id="nx-notif-badge" style="position:absolute;top:0;right:0;background:var(--red);color:#fff;border-radius:99px;font-size:.55rem;font-weight:700;padding:1px 5px;font-family:var(--font-body);min-width:16px;text-align:center;line-height:1.6;<?= $unread_notifs > 0 ? '' : 'display:none' ?>"><?= $unread_notifs ?></span>
        </button>

        <!-- Dropdown Panel -->
        <div id="nx-notif-panel" style="display:none;position:absolute;top:48px;right:-8px;width:320px;max-height:420px;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:0 8px 32px rgba(0,0,0,.5);z-index:500">
          <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:var(--surface);z-index:1">
            <span style="font-family:var(--font-head);font-weight:700;font-size:.9rem">🔔 Stock Alerts</span>
            <?php if($notifs): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="mark_notifs_read">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <button type="submit" style="background:none;border:none;cursor:pointer;font-size:.72rem;color:var(--accent);font-family:var(--font-body)">Mark all read</button>
            </form>
            <?php endif; ?>
          </div>
          <div style="padding:8px">
            <?php if($notifs): foreach($notifs as $n):
              $icon = $reason_icons[$n['reason']] ?? '⚠️';
              $is_new = !$n['is_read'];
            ?>
            <div style="padding:10px 12px;border-radius:8px;margin-bottom:5px;background:<?=$is_new?'rgba(247,85,85,.07)':'var(--surface2)'?>;border:1px solid <?=$is_new?'rgba(247,85,85,.2)':'var(--border)'?>">
              <div style="display:flex;align-items:flex-start;gap:8px">
                <span style="font-size:1.1rem;flex-shrink:0;line-height:1.4"><?=$icon?></span>
                <div style="min-width:0;flex:1">
                  <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;flex-wrap:wrap">
                    <strong style="font-size:.82rem"><?=htmlspecialchars($n['product_name'])?></strong>
                    <?php if($is_new): ?><span style="width:7px;height:7px;background:var(--red);border-radius:50%;flex-shrink:0;display:inline-block"></span><?php endif; ?>
                  </div>
                  <div style="font-size:.72rem;color:var(--text3)">
                    <span style="text-transform:capitalize"><?=htmlspecialchars($n['reason'])?></span>
                    · <strong style="color:var(--red)">−<?=number_format((float)$n['quantity'],2)?> units</strong>
                    · <?=htmlspecialchars($n['branch_name'])?>
                  </div>
                  <div style="font-size:.68rem;color:var(--text3);margin-top:2px">
                    By <?=htmlspecialchars($n['done_by'])?> · <?=date('d M Y, H:i',strtotime($n['created_at']))?>
                  </div>
                  <?php if($n['note']): ?>
                  <div style="font-size:.68rem;color:var(--text3);margin-top:2px;font-style:italic">"<?=htmlspecialchars($n['note'])?>"</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php endforeach;
            else: ?>
            <div style="text-align:center;padding:24px;color:var(--text3);font-size:.85rem">
              <div style="font-size:1.5rem;margin-bottom:8px;opacity:.4">🔔</div>
              No stock alerts yet.
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <button id="theme-btn" onclick="toggleClientTheme()" style="background:none;border:none;cursor:pointer;font-size:1.1rem;padding:4px 8px;color:var(--text2)">🌙</button>
      </div>
    </div><!-- .topbar -->

  <div class="content">
    <?php if ($flash): ?>
    <div class="flash flash-<?= $flash['type'] ?>">
      <?= $flash['type']==='success'?'✓':'✕' ?>
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <?php
    // ── DASHBOARD ────────────────────────────────────────────
    if ($page==='dashboard' && can('dashboard')):
      $period  = $_GET['period'] ?? 'today';
      $bfilter = is_owner() ? null : ($u['branch_id'] ?? null);
      $summary = get_sales_summary($u['client_id'], $bfilter, $period);
      $recents = get_recent_sales($u['client_id'], $bfilter, 10);
      $tprods  = is_manager() ? get_top_products($u['client_id'], $bfilter) : [];
      $brs     = is_owner() ? get_branches($u['client_id']) : [];
      $open_tk = 0;
      try { $st=db()->prepare("SELECT COUNT(*) FROM support_tickets WHERE client_id=? AND status IN('open','in_progress')"); $st->execute([$u['client_id']]); $open_tk=(int)$st->fetchColumn(); } catch(Throwable $e){ nx_debug($e,'dashboard/tickets'); }
    ?>
    <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;align-items:center;justify-content:space-between">
      <div style="display:flex;gap:6px">
        <?php foreach(['today'=>'Today','week'=>'7 Days','month'=>'This Month'] as $k=>$v): ?>
        <a href="?page=dashboard&period=<?=$k?>" class="btn btn-sm btn-ghost"
           style="<?=$period===$k?'background:var(--surface3);color:var(--text);border-color:var(--surface3)':''?>">
          <?=$v?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php if(can('tickets')): ?>
      <a href="?page=tickets" class="btn btn-sm btn-ghost">🎫 <?=$open_tk?> open ticket<?=$open_tk!=1?'s':''?></a>
      <?php endif; ?>
    </div>

    <div class="stats-grid">
      <div class="stat-card blue"><div class="stat-icon">💰</div><div class="stat-label">Revenue</div><div class="stat-value">KES <?=number_format($summary['total_revenue']??0)?></div><div class="stat-sub"><?=$period?></div></div>
      <div class="stat-card green"><div class="stat-icon">🧾</div><div class="stat-label">Sales</div><div class="stat-value"><?=number_format($summary['total_sales']??0)?></div><div class="stat-sub">transactions</div></div>
      <div class="stat-card yellow"><div class="stat-icon">📈</div><div class="stat-label">Avg Sale</div><div class="stat-value">KES <?=number_format($summary['avg_sale']??0)?></div><div class="stat-sub">per transaction</div></div>
      <?php if(is_owner()): ?>
      <div class="stat-card purple"><div class="stat-icon">🏪</div><div class="stat-label">Branches</div><div class="stat-value"><?=count($brs)?></div><div class="stat-sub">/ <?=$sub['branch_count']??'?'?> on plan</div></div>
      <?php endif; ?>
    </div>

    <div class="grid-2 mb-6">
      <?php if($sub && is_owner()): ?>
      <div class="card">
        <div class="card-header"><div class="card-title">Subscription</div><a href="?page=subscription" class="btn btn-xs btn-ghost">Details →</a></div>
        <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:800;margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($sub['plan_name'])?></div>
        <div style="font-size:.78rem;color:var(--text3);margin-bottom:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">KES <?=number_format($sub['total_monthly'],2)?> / month · <?=$sub['billing_cycle']?></div>
        <?php
          $tot = max(1,(strtotime($sub['end_date'])-strtotime($sub['start_date']))/86400);
          $rem = max(0,$sub['days_remaining']??0);
          $pct = round($rem/$tot*100);
        ?>
        <div style="font-size:.75rem;color:var(--text3);margin-bottom:6px"><?=$rem?> days remaining</div>
        <div class="progress-bar"><div class="progress-fill" style="width:<?=$pct?>%"></div></div>
        <div style="font-size:.72rem;color:var(--text3);margin-top:6px">Expires: <?=$sub['end_date']?></div>
      </div>
      <?php endif; ?>
      <?php if($tprods): ?>
      <div class="card">
        <div class="card-header"><div class="card-title">Top Products (Month)</div></div>
        <?php foreach(array_slice($tprods,0,5) as $tp): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);gap:10px;min-width:0;width:100%">
          <div style="font-size:.83rem;min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500"><?=htmlspecialchars($tp['name'])?></div>
          <div style="font-size:.76rem;color:var(--text3);flex-shrink:0;text-align:right;line-height:1.5"><span style="display:block;white-space:nowrap"><?=number_format($tp['qty_sold'])?> units</span><span style="color:var(--green);display:block;white-space:nowrap;font-weight:600">KES <?=number_format($tp['revenue'])?></span></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Recent Sales</div><a href="?page=sales" class="btn btn-xs btn-ghost">View all</a></div>
      <?php if($recents): ?>
      <div class="table-wrap"><table class="mobile-card-table">
        <thead><tr><th>Sale #</th><th>Date</th><?php if(is_owner()):?><th>Branch</th><?php endif;?><th>Customer</th><th>Payment</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach($recents as $s): ?>
        <tr>
          <td data-label="Sale #"><span style="font-family:var(--font-head);font-weight:700"><?=htmlspecialchars($s['sale_no']??$s['id'])?></span></td>
          <td data-label="Date" class="td-muted"><?=date('d M, H:i',strtotime($s['sale_date']))?></td>
          <?php if(is_owner()):?><td data-label="Branch" class="td-muted"><?=htmlspecialchars($s['branch_name']??'—')?></td><?php endif;?>
          <td data-label="Customer"><?=htmlspecialchars($s['customer_name']??'Walk-in')?></td>
          <td data-label="Payment"><span class="badge badge-<?=$s['payment_method']==='cash'?'green':($s['payment_method']==='mpesa'?'blue':'gray')?>"><?=strtoupper($s['payment_method']??'')?></span></td>
          <td data-label="Total" style="font-weight:600">KES <?=number_format($s['grand_total'],2)?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table></div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">⚡</div><p>No sales yet for this period.</p></div><?php endif; ?>
    </div>

    <?php
    // ── SUBSCRIPTION ─────────────────────────────────────────
    elseif ($page==='subscription' && can('subscription')):
      $pmts = get_payments($u['client_id'],5);
      $invs = get_invoices($u['client_id'],5);
    ?>
    <?php if($sub): ?>
    <div class="sub-detail">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
        <div>
          <div style="font-size:.72rem;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">Current Plan</div>
          <div class="sub-plan-name"><?=htmlspecialchars($sub['plan_name'])?></div>
        </div>
        <span class="badge badge-<?=$sub['status']==='active'?'green':($sub['status']==='grace'?'yellow':'red')?>" style="font-size:.85rem;padding:6px 14px"><?=ucfirst($sub['status'])?></span>
      </div>
      <div class="sub-meta-row">
        <div class="sub-meta-item"><span class="sub-meta-label">Monthly Fee</span><span class="sub-meta-val">KES <?=number_format($sub['total_monthly'],2)?></span></div>
        <div class="sub-meta-item"><span class="sub-meta-label">Branches</span><span class="sub-meta-val"><?=$sub['branch_count']?></span></div>
        <div class="sub-meta-item"><span class="sub-meta-label">Billing</span><span class="sub-meta-val"><?=ucfirst($sub['billing_cycle'])?></span></div>
        <div class="sub-meta-item"><span class="sub-meta-label">Start Date</span><span class="sub-meta-val"><?=$sub['start_date']?></span></div>
        <div class="sub-meta-item"><span class="sub-meta-label">Expiry</span><span class="sub-meta-val"><?=$sub['end_date']?></span></div>
        <div class="sub-meta-item"><span class="sub-meta-label">Grace Ends</span><span class="sub-meta-val"><?=$sub['grace_end_date']??'—'?></span></div>
        <div class="sub-meta-item"><span class="sub-meta-label">Days Left</span><span class="sub-meta-val" style="color:<?=($sub['days_remaining']??0)>7?'var(--green)':'var(--red)'?>"><?=max(0,$sub['days_remaining']??0)?> days</span></div>
      </div>
      <?php if(!empty($sub['features'])): ?>
      <div style="margin-top:16px">
        <div style="font-size:.72rem;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px">Included Features</div>
        <div style="display:flex;flex-wrap:wrap;gap:7px">
          <?php foreach(json_decode($sub['features'],true)??[] as $f): ?><span class="badge badge-blue"><?=htmlspecialchars(str_replace('_',' ',$f))?></span><?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">💎</div><p>No active subscription. Contact NYMIX TECH.</p></div><?php endif; ?>

    <div class="grid-2">
      <div class="card">
        <div class="card-header"><div class="card-title">Recent Invoices</div><a href="?page=invoices" class="btn btn-xs btn-ghost">All invoices</a></div>
        <?php foreach($invs as $inv): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--border);font-size:.84rem;gap:8px;min-width:0">
          <div style="min-width:0;flex:1;overflow:hidden"><div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($inv['invoice_no'])?></div><div class="td-muted"><?=$inv['issue_date']?></div></div>
          <div style="text-align:right;flex-shrink:0"><div style="white-space:nowrap">KES <?=number_format($inv['total'],2)?></div><span class="badge badge-<?=$inv['status']==='paid'?'green':($inv['status']==='overdue'?'red':'yellow')?>"><?=$inv['status']?></span></div>
        </div>
        <?php endforeach; ?>
        <?php if(!$invs): ?><div class="empty-state" style="padding:20px"><p>No invoices yet.</p></div><?php endif; ?>
      </div>
      <div class="card">
        <div class="card-header"><div class="card-title">Recent Payments</div><a href="?page=payments" class="btn btn-xs btn-ghost">All payments</a></div>
        <?php foreach($pmts as $p): ?>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:9px 0;border-bottom:1px solid var(--border);font-size:.84rem;gap:8px;min-width:0">
          <div style="min-width:0;flex:1;overflow:hidden"><span class="badge badge-blue" style="margin-right:6px"><?=strtoupper($p['payment_method'])?></span><span style="font-size:.78rem;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;max-width:120px;vertical-align:bottom"><?=htmlspecialchars($p['reference']??'—')?></span><div class="td-muted"><?=$p['payment_date']?></div></div>
          <div style="font-weight:600;color:var(--green);flex-shrink:0;white-space:nowrap">KES <?=number_format($p['amount'],2)?></div>
        </div>
        <?php endforeach; ?>
        <?php if(!$pmts): ?><div class="empty-state" style="padding:20px"><p>No payments yet.</p></div><?php endif; ?>
      </div>
    </div>
    <div style="margin-top:16px;padding:16px 20px;background:var(--surface2);border-radius:var(--radius);border:1px solid var(--border);font-size:.84rem;color:var(--text3)">
      💡 To upgrade, renew, or adjust branches, contact <strong style="color:var(--text)">NYMIX TECH support</strong> or open a support ticket.
    </div>

    <?php
    // ── INVOICES ─────────────────────────────────────────────
    elseif ($page==='invoices' && can('invoices')):
      $invoices = get_invoices($u['client_id'],50);
    ?>
    <div class="card">
      <div class="card-header"><div class="card-title">All Invoices</div></div>
      <?php if($invoices): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Invoice #</th><th>Issue Date</th><th>Due Date</th><th>Period</th><th>Amount</th><th>Tax</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($invoices as $inv): ?>
        <tr>
          <td><strong><?=htmlspecialchars($inv['invoice_no'])?></strong></td>
          <td class="td-muted"><?=$inv['issue_date']?></td>
          <td class="td-muted"><?=$inv['due_date']?></td>
          <td class="td-muted"><?=$inv['period_start']?> → <?=$inv['period_end']?></td>
          <td>KES <?=number_format($inv['amount'],2)?></td>
          <td class="td-muted">KES <?=number_format($inv['tax'],2)?></td>
          <td><strong>KES <?=number_format($inv['total'],2)?></strong></td>
          <td><span class="badge badge-<?=match($inv['status']){'paid'=>'green','overdue'=>'red','sent'=>'blue',default=>'gray'}?>"><?=$inv['status']?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">🧾</div><p>No invoices found.</p></div><?php endif; ?>
    </div>

    <?php
    // ── PAYMENTS ─────────────────────────────────────────────
    elseif ($page==='payments' && can('payments')):
      $payments    = get_payments($u['client_id'],50);
      $invoices    = get_invoices($u['client_id'],50);
      $unpaid_invs = array_filter($invoices, fn($i)=>in_array($i['status'],['unpaid','sent','overdue','draft']));
    ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
      <div>
        <div class="card-title" style="font-size:1rem">Your Invoices</div>
        <div style="font-size:.78rem;color:var(--text3);margin-top:2px">Click <strong style="color:var(--text)">Pay Now</strong> on any unpaid invoice to record your payment</div>
      </div>
    </div>

    <?php
    $sub_warn = $_SESSION['sub_warning'] ?? null;
    unset($_SESSION['sub_warning']);
    if (!$sub_warn && !in_array($u['sub_status'] ?? '', ['active'])) {
        $sub_warn = $u['sub_status'] ?? 'suspended';
    }
    $warn_messages = [
        'grace'     => ['color'=>'yellow', 'icon'=>'⏳', 'title'=>'Grace Period Active',     'msg'=>'Your subscription has expired but you\'re in the grace period. Pay now to avoid losing access.'],
        'suspended' => ['color'=>'red',    'icon'=>'🔒', 'title'=>'Account Suspended',        'msg'=>'Your account is suspended due to non-payment. Pay your outstanding invoice to restore full access immediately.'],
        'expired'   => ['color'=>'red',    'icon'=>'⛔', 'title'=>'Subscription Expired',     'msg'=>'Your subscription has expired. Pay now to reactivate your account and restore access for all users.'],
        'read_only' => ['color'=>'orange', 'icon'=>'👁',  'title'=>'Account in Read-Only Mode','msg'=>'Your account is in read-only mode. Pay your invoice to restore full functionality.'],
        'no_sub'    => ['color'=>'red',    'icon'=>'❌', 'title'=>'No Active Subscription',   'msg'=>'No active subscription found. Contact NYMIX TECH or pay your invoice below to get started.'],
    ];
    ?>

    <?php if ($sub_warn && isset($warn_messages[$sub_warn])): $w = $warn_messages[$sub_warn]; ?>
    <div style="
        background:<?=$w['color']==='red'?'rgba(247,85,85,.08)':($w['color']==='yellow'?'rgba(245,197,66,.08)':'rgba(247,146,79,.08)')?>;
        border:2px solid <?=$w['color']==='red'?'rgba(247,85,85,.4)':($w['color']==='yellow'?'rgba(245,197,66,.4)':'rgba(247,146,79,.4)')?>;
        border-radius:var(--radius-lg);
        padding:20px 22px;
        margin-bottom:22px;
        animation:slideDown .3s ease;
    ">
        <div style="display:flex;align-items:flex-start;gap:14px">
            <div style="font-size:2rem;flex-shrink:0;line-height:1"><?=$w['icon']?></div>
            <div style="flex:1">
                <div style="font-family:var(--font-head);font-size:1.05rem;font-weight:800;color:var(--text);margin-bottom:5px"><?=$w['title']?></div>
                <div style="font-size:.875rem;color:var(--text2);line-height:1.6;margin-bottom:12px"><?=$w['msg']?></div>
                <div style="background:rgba(0,0,0,.2);border-radius:var(--radius);padding:10px 12px;font-size:.82rem;word-break:break-word">
                    <div style="font-weight:700;color:var(--text);margin-bottom:4px">📱 How to pay:</div>
                    <div style="color:var(--text2);line-height:1.7">
                      1. Open M-PESA → Lipa na M-PESA → Pay Bill<br>
                      2. Business No: <strong style="color:var(--text)"><?=MPESA_PAYBILL?></strong><br>
                      3. Account No: <strong style="color:var(--text)">your invoice number</strong><br>
                      4. Come back and click <strong style="color:var(--text)">📱 Pay Now</strong> below
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($unpaid_invs)): ?>
    <div style="background:rgba(245,197,66,.08);border:1px solid rgba(245,197,66,.25);border-radius:var(--radius);padding:13px 16px;margin-bottom:18px;font-size:.84rem;color:var(--yellow);display:flex;align-items:center;gap:10px">
      <span style="font-size:1.1rem">⚠</span>
      <div>You have <strong><?=count($unpaid_invs)?> unpaid invoice(s)</strong>. Pay via M-PESA Paybill <strong style="color:var(--text)"><?=MPESA_PAYBILL?></strong>, then click <strong>Pay Now</strong> and enter your M-PESA code.</div>
    </div>
    <?php endif; ?>

    <?php if (empty($invoices) && $sub_warn): ?>
    <div style="text-align:center;padding:56px 24px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);margin-bottom:20px">
        <div style="font-size:3rem;margin-bottom:14px">📋</div>
        <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:700;margin-bottom:8px">No invoices found</div>
        <div style="font-size:.875rem;color:var(--text3);margin-bottom:20px">Contact NYMIX TECH to get your invoice issued so you can pay and restore access.</div>
        <a href="?page=tickets" class="btn btn-primary">Open Support Ticket</a>
    </div>
    <?php endif; ?>

    <div class="card mb-6">
      <div class="card-header"><div class="card-title">All Invoices</div></div>
      <?php if($invoices): ?>
      <div class="table-wrap">
      <table class="mobile-card-table">
        <thead><tr>
          <th>Invoice #</th><th>Period</th><th>Due Date</th>
          <th>Amount</th><th>Status</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php foreach($invoices as $inv):
          $ist = strtolower($inv['status']??'unpaid');
          $is_payable = in_array($ist, ['unpaid','sent','overdue','draft']);
        ?>
        <tr>
          <td data-label="Invoice">
            <strong><?=htmlspecialchars($inv['invoice_no'])?></strong>
            <div style="font-size:.7rem;color:var(--text3)">Issued: <?=$inv['issue_date']??'—'?></div>
          </td>
          <td data-label="Period" class="td-muted" style="font-size:.78rem"><?=htmlspecialchars($inv['period_start']??'—')?> → <?=htmlspecialchars($inv['period_end']??'—')?></td>
          <td data-label="Due" class="td-muted">
            <?=$inv['due_date']??'—'?>
            <?php if($ist==='overdue'):?><div style="font-size:.7rem;color:var(--red)">⚠ Overdue</div><?php endif;?>
          </td>
          <td data-label="Amount" style="text-align:right;font-weight:700;font-size:1rem">KES <?=number_format($inv['total']??$inv['amount'],2)?></td>
          <td data-label="Status">
            <span class="badge badge-<?=match($ist){'paid'=>'green','overdue'=>'red','sent'=>'blue','void'=>'gray',default=>'yellow'}?>">
              <?=ucfirst($ist)?>
            </span>
            <?php if(!empty($inv['paid_at'])): ?>
            <div style="font-size:.68rem;color:var(--text3);margin-top:2px">Paid <?=date('d M Y',strtotime($inv['paid_at']))?></div>
            <?php endif; ?>
          </td>
          <td data-label="">
            <?php if($is_payable): ?>
            <button type="button" class="btn btn-sm btn-primary" style="white-space:nowrap;"
              onclick="openPayModal(
                '<?= $inv['id'] ?>',
                '<?= htmlspecialchars($inv['invoice_no'], ENT_QUOTES) ?>',
                '<?= number_format($inv['total']??$inv['amount'],2,'.','') ?>',
                '<?= htmlspecialchars($inv['due_date']??'', ENT_QUOTES) ?>'
              )">
              📱 Pay Now
            </button>
            <?php else: ?>
            <span style="font-size:.75rem;color:var(--text3)">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">🧾</div><p>No invoices yet.</p></div><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title">Payment History</div></div>
      <?php if($payments): ?>
      <div class="table-wrap"><table class="mobile-card-table">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Invoice</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($payments as $p): ?>
        <tr>
          <td data-label="Date" class="td-muted"><?=$p['payment_date']?></td>
          <td data-label="Method"><span class="badge badge-<?=$p['payment_method']==='pesapal'?'purple':($p['payment_method']==='mpesa'?'blue':($p['payment_method']==='cash'?'green':'gray'))?>"><?=strtoupper($p['payment_method'])?></span></td>
          <td data-label="Ref" class="td-muted" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=htmlspecialchars($p['reference']??'—')?></td>
          <td data-label="Invoice" class="td-muted"><?=htmlspecialchars($p['invoice_no']??'—')?></td>
          <td data-label="Amount" style="font-weight:600;color:var(--green)">KES <?=number_format($p['amount'],2)?></td>
          <td data-label="Status">
            <?php if($p['confirmed']): ?>
            <span class="badge badge-green">✓ Confirmed</span>
            <?php else: ?>
            <span class="badge badge-yellow">⏳ Pending</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">💳</div><p>No payments recorded yet.</p></div><?php endif; ?>
    </div>

    <!-- Pay Now Modal -->
    <div class="modal-backdrop" id="payModal">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">💳 Confirm Payment</div>
          <button class="modal-close" onclick="closeModal('payModal')">✕</button>
        </div>

        <div style="background:linear-gradient(135deg,rgba(79,142,247,.1),rgba(124,106,247,.08));border:1px solid rgba(79,142,247,.2);border-radius:var(--radius);padding:16px 18px;margin-bottom:18px">
          <div style="font-size:.72rem;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px">Paying Invoice</div>
          <div id="pm-invno" style="font-family:var(--font-head);font-size:1rem;font-weight:700;color:var(--text)">—</div>
          <div style="margin-top:10px;display:flex;justify-content:space-between;align-items:center">
            <div>
              <div style="font-size:.72rem;color:var(--text3)">Amount Due</div>
              <div id="pm-amount-display" style="font-family:var(--font-head);font-size:1.6rem;font-weight:800;color:var(--accent)">KES 0.00</div>
            </div>
            <div style="text-align:right">
              <div style="font-size:.72rem;color:var(--text3)">Due Date</div>
              <div id="pm-due" style="font-weight:600;color:var(--text)">—</div>
            </div>
          </div>
        </div>

        <div style="background:rgba(62,207,142,.07);border:1px solid rgba(62,207,142,.2);border-radius:var(--radius);padding:11px 14px;margin-bottom:18px;font-size:.8rem;color:var(--green)">
          📱 Pay via <strong>M-PESA Paybill <?=MPESA_PAYBILL?></strong> first, then enter your confirmation code below.
        </div>

        <form method="POST" id="payForm">
          <input type="hidden" name="action"     value="record_payment">
          <input type="hidden" name="_csrf"       value="<?=csrf_token()?>">
          <input type="hidden" name="invoice_id" id="pm-invoice-id" value="">
          <input type="hidden" name="amount"     id="pm-amount"     value="">

          <div style="background:rgba(62,207,142,.07);border:1px solid rgba(62,207,142,.2);border-radius:var(--radius);padding:13px 16px;margin-bottom:18px;font-size:.84rem;color:var(--green)">
            ✅ Clicking <strong>Proceed to Pay</strong> will redirect you to the secure M-PESA payment page. Your account will activate automatically once payment is confirmed.
          </div>

          <button type="submit"
            class="btn btn-primary btn-full"
            style="padding:13px;font-size:1rem;font-family:var(--font-head);justify-content:center">
            📱 Proceed to Pay via M-PESA
          </button>
          <button type="button" class="btn btn-ghost btn-full" onclick="closeModal('payModal')" style="margin-top:8px;justify-content:center">Cancel</button>
        </form>
      </div>
    </div>

    <script>
    function nxConfirmRemoveBranch(id, name) {
  document.getElementById('remove-branch-id').value = id;
  document.getElementById('remove-branch-name').textContent = name;
  openModal('removeBranchModal');
}

function openPayModal(invId, invNo, amount, dueDate) {
      document.getElementById('pm-invoice-id').value = invId;
      document.getElementById('pm-amount').value     = amount;
      document.getElementById('pm-invno').textContent = invNo;
      document.getElementById('pm-amount-display').textContent = 'KES ' + parseFloat(amount).toLocaleString('en-KE',{minimumFractionDigits:2});
      document.getElementById('pm-due').textContent  = dueDate || '—';
      
      openModal('payModal');
    }
    function confirmPayment() {
      // No longer needed — form submits directly to Pesapal
    }
    </script>

    <?php
    // ── BRANCHES ─────────────────────────────────────────────
    elseif ($page==='branches' && can('branches')):
      $branches     = get_branches($u['client_id']);
      $branch_limit = $sub['branch_count'] ?? 0;
    ?>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px;width:100%;min-width:0">
      <div style="min-width:0">
        <div class="card-title" style="font-size:1rem">Branch Management</div>
        <div style="font-size:.78rem;color:var(--text3);margin-top:2px"><?=count($branches)?> of <?=$branch_limit?> branches used</div>
      </div>
      <?php if(count($branches) < $branch_limit): ?>
      <button class="btn btn-primary" onclick="openModal('branchModal')">+ Add Branch</button>
      <?php else: ?>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span class="badge badge-yellow">⚠ Branch limit reached</span>
        <a href="?page=tickets" class="btn btn-sm btn-ghost">Upgrade Plan →</a>
      </div>
      <?php endif; ?>
    </div>
    <div class="grid-3" style="margin-bottom:24px">
      <?php foreach($branches as $b): ?>
      <div class="card" style="position:relative;padding:18px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
          <div style="font-family:var(--font-head);font-weight:700"><?=htmlspecialchars($b['branch_name'])?></div>
          <span class="badge badge-<?=$b['is_active']?'green':'gray'?>"><?=$b['is_active']?'Active':'Inactive'?></span>
        </div>
        <div style="font-size:.8rem;color:var(--text3);margin-bottom:4px">📞 <?=htmlspecialchars($b['phone']??'—')?></div>
        <div style="font-size:.8rem;color:var(--text3);margin-bottom:10px">📍 <?=htmlspecialchars($b['address']??'—')?></div>
        <?php $biz_labels=['hardware'=>'🔧 Hardware','wholesale'=>'📦 Wholesale','supermarket'=>'🛒 Supermarket','agrovet'=>'🌱 Agrovet','pharmacy'=>'💊 Pharmacy','electronics'=>'📱 Electronics']; ?>
        <div style="font-size:.75rem;color:var(--accent);margin-bottom:6px"><?=$biz_labels[$b['business_type']??'hardware']??'🔧 Hardware'?></div>
        <div style="font-size:.8rem;color:var(--text2)">👥 <?=$b['user_count']?> active users</div>
        <div style="display:flex;gap:8px;margin-top:12px">
          <form method="POST">
            <input type="hidden" name="action" value="toggle_branch">
            <input type="hidden" name="_csrf"  value="<?=csrf_token()?>">
            <input type="hidden" name="branch_id" value="<?=$b['id']?>">
            <button type="submit" class="btn btn-xs btn-ghost"><?=$b['is_active']?'Deactivate':'Activate'?></button>
          </form>
          <?php if(!$b['is_active']): ?>
          <button type="button" class="btn btn-xs btn-danger"
            onclick="nxConfirmRemoveBranch(<?=$b['id']?>, '<?=htmlspecialchars($b['branch_name'],ENT_QUOTES)?>')">
            Remove
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(!$branches): ?><div class="empty-state" style="grid-column:1/-1"><div class="empty-icon">🏪</div><p>No branches yet.</p></div><?php endif; ?>
    </div>

    <!-- Remove Branch Modal -->
    <div class="modal-backdrop" id="removeBranchModal">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title" style="color:var(--red)">⚠ Remove Branch</div>
          <button class="modal-close" onclick="closeModal('removeBranchModal')">✕</button>
        </div>
        <div style="background:rgba(247,85,85,.08);border:1px solid rgba(247,85,85,.25);border-radius:var(--radius);padding:14px 16px;margin-bottom:18px;font-size:.85rem;color:var(--text2);line-height:1.7">
          You are about to remove <strong id="remove-branch-name" style="color:var(--text)"></strong>.<br>
          This will hide the branch and disable all its staff accounts.<br>
          <span style="color:var(--text3);font-size:.78rem">Sales history and data are preserved. You cannot undo this from the portal.</span>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="remove_branch">
          <input type="hidden" name="_csrf"  value="<?=csrf_token()?>">
          <input type="hidden" name="branch_id" id="remove-branch-id" value="">
          <div style="display:flex;gap:10px;margin-top:4px">
            <button type="button" class="btn btn-ghost" onclick="closeModal('removeBranchModal')" style="flex:1">Cancel</button>
            <button type="submit" class="btn btn-danger" style="flex:2">Yes, Remove Branch</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal-backdrop" id="branchModal">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">🏪 Add New Branch</div>
          <button class="modal-close" onclick="closeModal('branchModal')">✕</button>
        </div>
        <form method="POST">
          <input type="hidden" name="action" value="add_branch">
          <input type="hidden" name="_csrf"  value="<?=csrf_token()?>">
          <div class="form-group mb-4">
            <label>Branch Name <span style="color:var(--red)">*</span></label>
            <input type="text" name="branch_name" class="form-control" placeholder="e.g. Westlands Branch" required autofocus>
          </div>
          <div class="form-group mb-4">
            <label>Business Type <span style="color:var(--red)">*</span></label>
            <select name="business_type" class="form-control">
              <option value="hardware">🔧 Hardware</option>
              <option value="wholesale">📦 Wholesale</option>
              <option value="supermarket">🛒 Supermarket</option>
              <option value="agrovet">🌱 Agrovet</option>
              <option value="pharmacy">💊 Pharmacy</option>
              <option value="electronics">📱 Electronics</option>
            </select>
          </div>
          <div class="form-group mb-4">
            <label>Phone Number</label>
            <input type="tel" name="phone" class="form-control" placeholder="07XXXXXXXX">
          </div>
          <div class="form-group mb-4">
            <label>Address / Location</label>
            <input type="text" name="address" class="form-control" placeholder="e.g. Westlands, Nairobi">
          </div>
          <div style="display:flex;gap:10px;margin-top:20px">
            <button type="button" class="btn btn-ghost" onclick="closeModal('branchModal')" style="flex:1">Cancel</button>
            <button type="submit" class="btn btn-primary" style="flex:2">✓ Create Branch</button>
          </div>
        </form>
      </div>
    </div>

    <?php
    // ── USERS ────────────────────────────────────────────────
    elseif ($page==='users' && (can('users')||can('users_branch'))):
      $bfu  = is_owner() ? null : ($u['branch_id'] ?? null);
      $ausers = get_branch_users($u['client_id'], $bfu);
      $abrs   = is_owner() ? get_branches($u['client_id']) : [];
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
      <div class="card-title" style="font-size:1rem">User Management</div>
      <button class="btn btn-primary" onclick="openModal('userModal')">+ Add User</button>
    </div>
    <div class="card">
      <?php if($ausers): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><?php if(is_owner()):?><th>Branch</th><?php endif;?><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($ausers as $usr): ?>
        <tr>
          <td><strong><?=htmlspecialchars($usr['full_name'])?></strong></td>
          <td class="td-muted"><?=htmlspecialchars($usr['email'])?></td>
          <td class="td-muted"><?=htmlspecialchars($usr['phone']??'—')?></td>
          <?php if(is_owner()):?><td class="td-muted"><?=htmlspecialchars($usr['branch_name']??'—')?></td><?php endif;?>
          <td><span class="badge badge-<?=match($usr['role']){'manager'=>'blue','cashier'=>'green','stock_clerk'=>'yellow',default=>'gray'}?>"><?=$usr['role']?></span></td>
          <td><span class="badge badge-<?=$usr['is_active']?'green':'gray'?>"><?=$usr['is_active']?'Active':'Inactive'?></span></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="toggle_user">
              <input type="hidden" name="_csrf"   value="<?=csrf_token()?>">
              <input type="hidden" name="user_id" value="<?=$usr['id']?>">
              <button type="submit" class="btn btn-xs btn-ghost"><?=$usr['is_active']?'Disable':'Enable'?></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">👥</div><p>No users found.</p></div><?php endif; ?>
    </div>

    <div class="modal-backdrop" id="userModal">
      <div class="modal modal-lg">
        <div class="modal-header"><div class="modal-title">Add New User</div><button class="modal-close" onclick="closeModal('userModal')">✕</button></div>
        <form method="POST">
          <input type="hidden" name="action" value="add_user">
          <input type="hidden" name="_csrf"  value="<?=csrf_token()?>">
          <div class="form-grid">
            <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
            <div class="form-group"><label>Username *</label><input type="text" name="username" class="form-control" placeholder="e.g. john.doe (leave blank to auto-generate)"></div>
            <div class="form-group"><label>Email *</label><input type="email" name="email" class="form-control" required></div>
            <div class="form-group"><label>Phone *</label><input type="text" name="phone" class="form-control" placeholder="07XXXXXXXX" required></div>
            <div class="form-group"><label>Password *</label><input type="password" name="password" class="form-control" minlength="6" required></div>
            <div class="form-group"><label>Role</label>
              <select name="role" class="form-control">
                <?php if(is_owner()):?><option value="manager">Manager</option><?php endif;?>
                <option value="cashier" selected>Cashier</option>
                <option value="stock_clerk">Stock Clerk</option>
              </select>
            </div>
            <?php if(is_owner() && $abrs): ?>
            <div class="form-group"><label>Branch *</label>
              <select name="branch_id" class="form-control" required>
                <option value="">Select branch...</option>
                <?php foreach($abrs as $b):?><option value="<?=$b['id']?>"><?=htmlspecialchars($b['branch_name'])?></option><?php endforeach;?>
              </select>
            </div>
            <?php else: ?><input type="hidden" name="branch_id" value="<?=$u['branch_id']??0?>">
            <?php endif; ?>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create User</button>
            <button type="button" class="btn btn-ghost" onclick="closeModal('userModal')">Cancel</button>
          </div>
        </form>
      </div>
    </div>

    <?php
    // ── SALES ────────────────────────────────────────────────
    elseif ($page==='sales' && (can('sales')||can('sales_view'))):
      $period  = $_GET['period'] ?? 'today';
      $bfilter = is_owner() ? null : ($u['branch_id'] ?? null);
      $summary = get_sales_summary($u['client_id'], $bfilter, $period);
      $recents = get_recent_sales($u['client_id'], $bfilter, 50);
    ?>
    <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
      <?php foreach(['today'=>'Today','week'=>'7 Days','month'=>'This Month'] as $k=>$v): ?>
      <a href="?page=sales&period=<?=$k?>" class="btn btn-sm btn-ghost" style="<?=$period===$k?'background:var(--surface3);color:var(--text);border-color:var(--surface3)':''?>"><?=$v?></a>
      <?php endforeach; ?>
    </div>
    <div class="stats-grid mb-6">
      <div class="stat-card blue"><div class="stat-icon">💰</div><div class="stat-label">Revenue</div><div class="stat-value">KES <?=number_format($summary['total_revenue']??0)?></div></div>
      <div class="stat-card green"><div class="stat-icon">🧾</div><div class="stat-label">Transactions</div><div class="stat-value"><?=number_format($summary['total_sales']??0)?></div></div>
      <div class="stat-card yellow"><div class="stat-icon">📈</div><div class="stat-label">Avg Sale</div><div class="stat-value">KES <?=number_format($summary['avg_sale']??0)?></div></div>
      <div class="stat-card orange"><div class="stat-icon">🚫</div><div class="stat-label">Voided</div><div class="stat-value"><?=$summary['voided_count']??0?></div></div>
    </div>
    <div class="card">
      <div class="card-header"><div class="card-title">All Sales</div></div>
      <?php if($recents): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Sale #</th><th>Date/Time</th><?php if(is_owner()):?><th>Branch</th><?php endif;?><th>Customer</th><th>Served By</th><th>Payment</th><th style="text-align:right">Total</th></tr></thead>
        <tbody>
        <?php foreach($recents as $s): ?>
        <tr>
          <td><strong><?=htmlspecialchars($s['sale_no']??'#'.$s['id'])?></strong></td>
          <td class="td-muted"><?=date('d M Y, H:i',strtotime($s['sale_date']))?></td>
          <?php if(is_owner()):?><td class="td-muted"><?=htmlspecialchars($s['branch_name']??'—')?></td><?php endif;?>
          <td><?=htmlspecialchars($s['customer_name']??'Walk-in')?></td>
          <td class="td-muted"><?=htmlspecialchars($s['served_by']??'—')?></td>
          <td><span class="badge badge-<?=$s['payment_method']==='cash'?'green':($s['payment_method']==='mpesa'?'blue':'gray')?>"><?=strtoupper($s['payment_method']??'')?></span></td>
          <td style="text-align:right;font-weight:600">KES <?=number_format($s['grand_total'],2)?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">⚡</div><p>No sales for this period.</p></div><?php endif; ?>
    </div>

    <?php
    // ── REPORTS ──────────────────────────────────────────────
    elseif ($page==='reports' && can('reports')):
      $bfilter  = is_owner() ? null : ($u['branch_id'] ?? null);
      $r_today  = get_sales_summary($u['client_id'], $bfilter, 'today');
      $r_week   = get_sales_summary($u['client_id'], $bfilter, 'week');
      $r_month  = get_sales_summary($u['client_id'], $bfilter, 'month');
      $top      = get_top_products($u['client_id'], $bfilter);
      $brs_rep  = is_owner() ? get_branches($u['client_id']) : [];

      // ── EXTENDED STATS ────────────────────────────────────
      // Last month comparison
      $last_month_rev = 0; $last_month_txn = 0;
      try {
          $lm = db()->prepare("
              SELECT COALESCE(SUM(grand_total),0) AS rev, COUNT(*) AS txn
              FROM sales s WHERE s.voided=0
              AND MONTH(s.sale_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
              AND YEAR(s.sale_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
              " . ($bfilter ? "AND s.branch_id = ?" : "AND s.branch_id IN (SELECT id FROM branches WHERE client_id = ?)"));
          $lm->execute([$bfilter ?? $u['client_id']]);
          $lm_row = $lm->fetch();
          $last_month_rev = (float)($lm_row['rev'] ?? 0);
          $last_month_txn = (int)($lm_row['txn'] ?? 0);
      } catch(Throwable $e){ nx_debug($e,'reports/lastmonth'); }

      // Month-over-month change
      $mom_rev_pct = $last_month_rev > 0
          ? round((($r_month['total_revenue'] - $last_month_rev) / $last_month_rev) * 100, 1)
          : 0;
      $mom_txn_pct = $last_month_txn > 0
          ? round((($r_month['total_sales'] - $last_month_txn) / $last_month_txn) * 100, 1)
          : 0;

      // Daily revenue for last 14 days (sparkline)
      $daily_14 = [];
      try {
          $bf_sql = $bfilter
              ? "AND s.branch_id = ?"
              : "AND s.branch_id IN (SELECT id FROM branches WHERE client_id = ?)";
          $d14 = db()->prepare("
              SELECT DATE(sale_date) AS d, COALESCE(SUM(grand_total),0) AS rev, COUNT(*) AS txn
              FROM sales s WHERE s.voided=0 $bf_sql
              AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              GROUP BY DATE(sale_date) ORDER BY d ASC
          ");
          $d14->execute([]);
          $daily_14 = $d14->fetchAll();
      } catch(Throwable $e){ nx_debug($e,'reports/daily14'); }

      // Payment method breakdown this month
      $pay_methods = [];
      try {
          $bf_sql2 = $bfilter
              ? "AND s.branch_id = ?"
              : "AND s.branch_id IN (SELECT id FROM branches WHERE client_id = ?)";
          $pm = db()->prepare("
              SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS rev
              FROM sales s WHERE s.voided=0 $bf_sql2
              AND MONTH(s.sale_date)=MONTH(CURDATE())
              AND YEAR(s.sale_date)=YEAR(CURDATE())
              GROUP BY payment_method ORDER BY rev DESC
          ");
          $pm->execute([$bfilter ?? $u['client_id']]);
          $pay_methods = $pm->fetchAll();
      } catch(Throwable $e){ nx_debug($e,'reports/paymethods'); }

      // Voided sales this month
      $voided_rev = 0;
      try {
          $bf_sql3 = $bfilter
              ? "AND s.branch_id = ?"
              : "AND s.branch_id IN (SELECT id FROM branches WHERE client_id = ?)";
          $vs = db()->prepare("
              SELECT COALESCE(SUM(grand_total),0) AS rev, COUNT(*) AS cnt
              FROM sales s WHERE s.voided=1 $bf_sql3
              AND MONTH(s.sale_date)=MONTH(CURDATE())
              AND YEAR(s.sale_date)=YEAR(CURDATE())
          ");
          $vs->execute([$bfilter ?? $u['client_id']]);
          $vrow = $vs->fetch();
          $voided_rev = (float)($vrow['rev'] ?? 0);
          $voided_cnt = (int)($vrow['cnt'] ?? 0);
      } catch(Throwable $e){ nx_debug($e,'reports/voided'); }

      // Credit outstanding total
      $credit_outstanding = 0;
      try {
          $bf_sql4 = $bfilter
              ? "AND branch_id = ?"
              : "AND branch_id IN (SELECT id FROM branches WHERE client_id = ?)";
          $co = db()->prepare("SELECT COALESCE(SUM(balance),0) AS total FROM customers WHERE balance > 0 $bf_sql4");
          $co->execute([$bfilter ?? $u['client_id']]);
          $credit_outstanding = (float)($co->fetchColumn() ?? 0);
      } catch(Throwable $e){ nx_debug($e,'reports/credit'); }

      // Top customers this month
      $top_customers = [];
      try {
          $bf_sql5 = $bfilter
              ? "AND s.branch_id = ?"
              : "AND s.branch_id IN (SELECT id FROM branches WHERE client_id = ?)";
          $tc = db()->prepare("
              SELECT c.name, COUNT(s.id) AS orders, COALESCE(SUM(s.grand_total),0) AS spend
              FROM sales s JOIN customers c ON c.id=s.customer_id
              WHERE s.voided=0 $bf_sql5
              AND MONTH(s.sale_date)=MONTH(CURDATE())
              AND YEAR(s.sale_date)=YEAR(CURDATE())
              GROUP BY c.id ORDER BY spend DESC LIMIT 5
          ");
          $tc->execute([$bfilter ?? $u['client_id']]);
          $top_customers = $tc->fetchAll();
      } catch(Throwable $e){ nx_debug($e,'reports/topcustomers'); }

      // Hourly heatmap today
      $hourly = [];
      try {
          $bf_sql6 = $bfilter
              ? "AND s.branch_id = ?"
              : "AND s.branch_id IN (SELECT id FROM branches WHERE client_id = ?)";
          $hh = db()->prepare("
              SELECT HOUR(sale_date) AS hr, COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS rev
              FROM sales s WHERE s.voided=0 $bf_sql6
              AND DATE(sale_date)=CURDATE()
              GROUP BY HOUR(sale_date) ORDER BY hr ASC
          ");
          $hh->execute([$bfilter ?? $u['client_id']]);
          $hourly = $hh->fetchAll();
      } catch(Throwable $e){ nx_debug($e,'reports/hourly'); }

      // Build hourly map keyed by hour
      $hourly_map = [];
      foreach($hourly as $h) $hourly_map[(int)$h['hr']] = $h;
      $max_hourly_rev = $hourly ? max(array_column($hourly,'rev')) : 1;

      // Daily 14-day max for bar scaling
      $max_daily_rev = $daily_14 ? max(array_column($daily_14,'rev')) : 1;
      $avg_daily_rev = count($daily_14) > 0
          ? array_sum(array_column($daily_14,'rev')) / count($daily_14)
          : 0;

      // Payment method total for percentages
      $pm_total_rev = array_sum(array_column($pay_methods,'rev'));
    ?>

    <!-- ── REPORT TABS ── -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:20px">
      <?php foreach(['overview'=>['📊','Overview'],'products'=>['📦','Products'],'branches'=>['🏪','Branches'],'payments'=>['💳','Payments']] as $rtab=>[$icon,$label]): ?>
      <a href="?page=reports&rtab=<?=$rtab?>" class="btn btn-sm btn-ghost"
         style="flex-direction:column;gap:2px;padding:8px 4px;text-align:center;font-size:.7rem;<?=(($_GET['rtab']??'overview')===$rtab)?'background:var(--surface3);color:var(--text);border-color:var(--accent)':''?>">
        <span style="font-size:1.1rem;line-height:1"><?=$icon?></span>
        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%"><?=$label?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php $rtab = $_GET['rtab'] ?? 'overview'; ?>

    <?php if($rtab === 'overview'): ?>
    <!-- ══ OVERVIEW TAB ══ -->

    <!-- KPI Row -->
    <div class="stats-grid mb-6" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr))">
      <?php
        $mom_color_rev = $mom_rev_pct >= 0 ? 'var(--green)' : 'var(--red)';
        $mom_color_txn = $mom_txn_pct >= 0 ? 'var(--green)' : 'var(--red)';
        $mom_arrow_rev = $mom_rev_pct >= 0 ? '▲' : '▼';
        $mom_arrow_txn = $mom_txn_pct >= 0 ? '▲' : '▼';
      ?>
      <div class="stat-card blue">
        <div class="stat-icon">💰</div>
        <div class="stat-label">Today Revenue</div>
        <div class="stat-value" style="font-size:1.3rem">KES <?=number_format($r_today['total_revenue']??0)?></div>
        <div class="stat-sub"><?=number_format($r_today['total_sales']??0)?> transactions</div>
      </div>
      <div class="stat-card green">
        <div class="stat-icon">📅</div>
        <div class="stat-label">This Month</div>
        <div class="stat-value" style="font-size:1.3rem">KES <?=number_format($r_month['total_revenue']??0)?></div>
        <div class="stat-sub" style="color:<?=$mom_color_rev?>"><?=$mom_arrow_rev?> <?=abs($mom_rev_pct)?>% vs last month</div>
      </div>
      <div class="stat-card yellow">
        <div class="stat-icon">🧾</div>
        <div class="stat-label">Month Transactions</div>
        <div class="stat-value"><?=number_format($r_month['total_sales']??0)?></div>
        <div class="stat-sub" style="color:<?=$mom_color_txn?>"><?=$mom_arrow_txn?> <?=abs($mom_txn_pct)?>% vs last month</div>
      </div>
      <div class="stat-card purple">
        <div class="stat-icon">📈</div>
        <div class="stat-label">Avg Ticket (Month)</div>
        <div class="stat-value" style="font-size:1.3rem">KES <?=number_format($r_month['avg_sale']??0)?></div>
        <div class="stat-sub">per transaction</div>
      </div>
      <div class="stat-card orange">
        <div class="stat-icon">💳</div>
        <div class="stat-label">Credit Outstanding</div>
        <div class="stat-value" style="font-size:1.2rem">KES <?=number_format($credit_outstanding)?></div>
        <div class="stat-sub">customer balances due</div>
      </div>
      <div class="stat-card" style="--card-color:var(--red)">
        <div class="stat-icon" style="opacity:.12">🚫</div>
        <div class="stat-label">Voided This Month</div>
        <div class="stat-value" style="color:var(--red)"><?=$voided_cnt?></div>
        <div class="stat-sub">KES <?=number_format($voided_rev)?> lost</div>
      </div>
    </div>

    <!-- 14-Day Revenue Trend -->
    <div class="card mb-6">
      <div class="card-header">
        <div class="card-title">📈 Revenue Trend — Last 14 Days</div>
        <div style="font-size:.72rem;color:var(--text3)">Green = above avg · Orange = below avg</div>
      </div>
      <?php if($daily_14): ?>
      <div style="display:flex;align-items:flex-end;gap:3px;height:120px;padding:4px 0;overflow-x:auto;max-width:100%">
        <?php foreach($daily_14 as $dd):
          $pct = $max_daily_rev > 0 ? round(($dd['rev']/$max_daily_rev)*100) : 0;
          $color = (float)$dd['rev'] >= $avg_daily_rev ? 'var(--green)' : 'var(--orange)';
          $lbl = date('d M', strtotime($dd['d']));
        ?>
        <div style="flex:1;min-width:28px;display:flex;flex-direction:column;align-items:center;gap:3px">
          <div style="font-size:.58rem;color:var(--accent);font-weight:700"><?=number_format($dd['rev']/1000,0)?>k</div>
          <div style="width:100%;background:<?=$color?>;border-radius:4px 4px 0 0;height:<?=$pct?>%;min-height:4px;opacity:.85;transition:opacity .2s" title="<?=$dd['d']?>: KES <?=number_format($dd['rev'])?>"></div>
          <div style="font-size:.58rem;color:var(--text3);white-space:nowrap"><?=$lbl?></div>
          <div style="font-size:.55rem;color:var(--text3)"><?=$dd['txn']?>tx</div>
        </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:16px;margin-top:10px;font-size:.72rem;color:var(--text3)">
        <span>Avg/day: <strong style="color:var(--text)">KES <?=number_format($avg_daily_rev)?></strong></span>
        <span>Best: <strong style="color:var(--green)">KES <?=number_format($max_daily_rev)?></strong></span>
        <span>7-day: <strong style="color:var(--accent)">KES <?=number_format($r_week['total_revenue']??0)?></strong></span>
      </div>
      <?php else: ?><div class="empty-state" style="padding:20px"><p>No sales data in the last 14 days.</p></div><?php endif; ?>
    </div>

    <!-- Today Hourly Heatmap + Payment Methods -->
    <div class="grid-2 mb-6">
      <div class="card">
        <div class="card-header"><div class="card-title">⏱ Today by Hour</div></div>
        <?php if($hourly): ?>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px">
          <?php for($hr=7; $hr<=22; $hr++):
            $h = $hourly_map[$hr] ?? null;
            $rev = $h ? (float)$h['rev'] : 0;
            $cnt = $h ? (int)$h['cnt'] : 0;
            $intensity = $max_hourly_rev > 0 ? $rev/$max_hourly_rev : 0;
            $bg = $rev > 0
                ? 'rgba(79,142,247,'.round(0.1 + $intensity * 0.85, 2).')'
                : 'var(--surface2)';
            $tc = $intensity > 0.5 ? '#fff' : 'var(--text3)';
          ?>
          <div style="background:<?=$bg?>;border-radius:6px;padding:6px 4px;text-align:center;cursor:default" title="<?=$hr?>:00 — <?=$cnt?> sales · KES <?=number_format($rev)?>">
            <div style="font-size:.6rem;color:<?=$tc?>;font-weight:600"><?=$hr?>h</div>
            <div style="font-size:.62rem;color:<?=$tc?>"><?=$cnt>0?$cnt.'tx':''?></div>
          </div>
          <?php endfor; ?>
        </div>
        <div style="font-size:.7rem;color:var(--text3);margin-top:8px">Darker = more revenue · Hover for details</div>
        <?php else: ?><div class="empty-state" style="padding:16px"><p>No sales yet today.</p></div><?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">💳 Payment Methods (Month)</div></div>
        <?php if($pay_methods):
          foreach($pay_methods as $pm):
            $pct = $pm_total_rev > 0 ? round(($pm['rev']/$pm_total_rev)*100) : 0;
            $colors = ['cash'=>'var(--green)','mpesa'=>'var(--accent)','credit'=>'var(--orange)','mixed'=>'var(--accent2)'];
            $bc = $colors[$pm['payment_method']] ?? 'var(--text3)';
        ?>
        <div style="margin-bottom:12px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:.83rem">
            <span style="text-transform:uppercase;font-weight:600;color:<?=$bc?>"><?=htmlspecialchars($pm['payment_method'])?></span>
            <span><strong>KES <?=number_format($pm['rev'])?></strong> <span style="color:var(--text3)">(<?=$pct?>% · <?=$pm['cnt']?> sales)</span></span>
          </div>
          <div style="height:7px;background:var(--surface3);border-radius:99px;overflow:hidden">
            <div style="height:100%;width:<?=$pct?>%;background:<?=$bc?>;border-radius:99px"></div>
          </div>
        </div>
        <?php endforeach;
        else: ?><div class="empty-state" style="padding:16px"><p>No sales this month.</p></div><?php endif; ?>
      </div>
    </div>

    <!-- Top Customers + Top Products -->
    <div class="grid-2 mb-6">
      <div class="card">
        <div class="card-header"><div class="card-title">👑 Top Customers (Month)</div></div>
        <?php if($top_customers):
          $max_spend = max(array_column($top_customers,'spend'));
          foreach($top_customers as $i=>$tc):
            $pct = $max_spend > 0 ? round(($tc['spend']/$max_spend)*100) : 0;
        ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;margin-bottom:3px;font-size:.83rem;gap:8px;min-width:0">
            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1"><?=$i+1?>. <strong><?=htmlspecialchars($tc['name'])?></strong></span>
            <span style="color:var(--green);flex-shrink:0;white-space:nowrap;font-size:.76rem">KES <?=number_format($tc['spend'])?> <span style="color:var(--text3)">(<?=$tc['orders']?>)</span></span>
          </div>
          <div style="height:5px;background:var(--surface3);border-radius:99px;overflow:hidden">
            <div style="height:100%;width:<?=$pct?>%;background:linear-gradient(90deg,var(--green),var(--accent));border-radius:99px"></div>
          </div>
        </div>
        <?php endforeach;
        else: ?><div class="empty-state" style="padding:16px"><p>No customer sales this month.</p></div><?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">📦 Top Products (Month)</div></div>
        <?php if($top):
          $mx = max(array_column($top,'qty_sold'));
          foreach($top as $i=>$tp):
            $pct = $mx > 0 ? round(($tp['qty_sold']/$mx)*100) : 0;
        ?>
        <div style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;margin-bottom:3px;font-size:.83rem;gap:8px;min-width:0">
            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1"><?=$i+1?>. <?=htmlspecialchars($tp['name'])?></span>
            <span style="color:var(--accent);flex-shrink:0;white-space:nowrap;font-size:.76rem">KES <?=number_format($tp['revenue'])?></span>
          </div>
          <div style="height:5px;background:var(--surface3);border-radius:99px;overflow:hidden">
            <div style="height:100%;width:<?=$pct?>%;background:linear-gradient(90deg,var(--accent),var(--accent2));border-radius:99px"></div>
          </div>
          <div style="font-size:.68rem;color:var(--text3);margin-top:2px"><?=number_format($tp['qty_sold'])?> units</div>
        </div>
        <?php endforeach;
        else: ?><div class="empty-state" style="padding:16px"><p>No product data this month.</p></div><?php endif; ?>
      </div>
    </div>

    <?php elseif($rtab === 'products'): ?>
    <!-- ══ PRODUCTS TAB ══ -->
    <?php
      // Product margin analysis
      $prod_margins = [];
      try {
          $pm2 = db()->prepare("
              SELECT p.name, p.sku, c.name AS category,
                     p.buying_price, p.selling_price,
                     ROUND((p.selling_price-p.buying_price)/NULLIF(p.buying_price,0)*100,1) AS margin_pct,
                     (p.selling_price-p.buying_price) AS profit_unit,
                     COALESCE(SUM(st.quantity),0) AS stock_qty,
                     COALESCE(SUM(st.quantity)*p.buying_price,0) AS stock_value,
                     p.reorder_level,
                     IF(COALESCE(SUM(st.quantity),0)<=p.reorder_level,1,0) AS low_stock
              FROM products p
              LEFT JOIN categories c ON c.id=p.category_id
              LEFT JOIN stock st ON st.product_id=p.id
              WHERE p.is_active=1
              GROUP BY p.id ORDER BY margin_pct DESC
          ");
          $pm2->execute([]);
          $prod_margins = $pm2->fetchAll();
      } catch(Throwable $e){ nx_debug($e,'reports/prodmargins'); }

      $total_stock_val = array_sum(array_column($prod_margins,'stock_value'));
      $low_count       = count(array_filter($prod_margins, fn($p)=>$p['low_stock']));
      $out_count       = count(array_filter($prod_margins, fn($p)=>(float)$p['stock_qty']<=0));
    ?>
    <div class="stats-grid mb-6" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
      <div class="stat-card blue"><div class="stat-icon">📦</div><div class="stat-label">Total Products</div><div class="stat-value"><?=count($prod_margins)?></div></div>
      <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-label">Stock Value</div><div class="stat-value" style="font-size:1.2rem">KES <?=number_format($total_stock_val)?></div><div class="stat-sub">at buying price</div></div>
      <div class="stat-card yellow"><div class="stat-icon">⚠</div><div class="stat-label">Low Stock</div><div class="stat-value"><?=$low_count?></div><div class="stat-sub">need reorder</div></div>
      <div class="stat-card" style=""><div class="stat-icon" style="opacity:.12">🚫</div><div class="stat-label">Out of Stock</div><div class="stat-value" style="color:var(--red)"><?=$out_count?></div></div>
    </div>

    <!-- Margin bar chart top 10 -->
    <div class="card mb-6">
      <div class="card-header"><div class="card-title">💹 Top 10 Margin Products</div><div style="font-size:.72rem;color:var(--text3)">% profit on buying price</div></div>
      <?php foreach(array_slice($prod_margins,0,10) as $pm3):
        $mc = (float)$pm3['margin_pct'] >= 50 ? 'var(--green)' : ((float)$pm3['margin_pct'] >= 25 ? 'var(--accent)' : 'var(--orange)');
        $bw = min(100, max(2, (float)$pm3['margin_pct']));
      ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;margin-bottom:3px;font-size:.82rem">
          <span><strong><?=htmlspecialchars($pm3['name'])?></strong> <span style="color:var(--text3);font-size:.7rem"><?=htmlspecialchars($pm3['category']??'')?></span></span>
          <span style="color:<?=$mc?>;font-weight:700"><?=$pm3['margin_pct']?>% <span style="color:var(--text3);font-weight:400">· KES <?=number_format($pm3['profit_unit'])?>/unit</span></span>
        </div>
        <div style="height:7px;background:var(--surface3);border-radius:99px;overflow:hidden">
          <div style="height:100%;width:<?=$bw?>%;background:<?=$mc?>;border-radius:99px"></div>
        </div>
        <div style="display:flex;gap:12px;font-size:.68rem;color:var(--text3);margin-top:2px">
          <span>Buy: KES <?=number_format($pm3['buying_price'],2)?></span>
          <span>Sell: KES <?=number_format($pm3['selling_price'],2)?></span>
          <span>Stock: <?=number_format($pm3['stock_qty'],2)?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Full product table -->
    <div class="card">
      <div class="card-header"><div class="card-title">📋 Full Product Margin Table</div></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Product</th><th>Category</th><th>Buy Price</th><th>Sell Price</th><th>Margin %</th><th>Profit/Unit</th><th>Stock Qty</th><th>Stock Value</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach($prod_margins as $pm4):
            $mc4 = (float)$pm4['margin_pct'] >= 50 ? 'var(--green)' : ((float)$pm4['margin_pct'] >= 25 ? 'var(--accent)' : 'var(--orange)');
            $sc  = (float)$pm4['stock_qty'] <= 0 ? 'badge-red' : ($pm4['low_stock'] ? 'badge-yellow' : 'badge-green');
            $sl  = (float)$pm4['stock_qty'] <= 0 ? 'Out' : ($pm4['low_stock'] ? 'Low' : 'OK');
          ?>
          <tr>
            <td><strong><?=htmlspecialchars($pm4['name'])?></strong><div style="font-size:.68rem;color:var(--text3)"><?=htmlspecialchars($pm4['sku']??'')?></div></td>
            <td class="td-muted"><?=htmlspecialchars($pm4['category']??'—')?></td>
            <td>KES <?=number_format($pm4['buying_price'],2)?></td>
            <td style="color:var(--accent);font-weight:600">KES <?=number_format($pm4['selling_price'],2)?></td>
            <td style="color:<?=$mc4?>;font-weight:700"><?=$pm4['margin_pct']?>%</td>
            <td style="color:<?=$mc4?>">KES <?=number_format($pm4['profit_unit'],2)?></td>
            <td><?=number_format($pm4['stock_qty'],2)?></td>
            <td>KES <?=number_format($pm4['stock_value'],2)?></td>
            <td><span class="badge <?=$sc?>"><?=$sl?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif($rtab === 'branches' && is_owner() && $brs_rep): ?>
    <!-- ══ BRANCHES TAB ══ -->
    <?php
      $branch_stats = [];
      foreach($brs_rep as $b) {
          $bm = get_sales_summary($u['client_id'], (int)$b['id'], 'month');
          $bw2= get_sales_summary($u['client_id'], (int)$b['id'], 'week');
          $bt = get_sales_summary($u['client_id'], (int)$b['id'], 'today');
          $branch_stats[] = array_merge($b, ['month'=>$bm,'week'=>$bw2,'today'=>$bt]);
      }
      usort($branch_stats, fn($a,$b2) => $b2['month']['total_revenue'] <=> $a['month']['total_revenue']);
      $total_branch_rev = array_sum(array_column(array_column($branch_stats,'month'),'total_revenue'));
    ?>
    <div class="stats-grid mb-6">
      <div class="stat-card blue"><div class="stat-icon">🏪</div><div class="stat-label">Total Branches</div><div class="stat-value"><?=count($brs_rep)?></div><div class="stat-sub">/ <?=$sub['branch_count']??'?'?> on plan</div></div>
      <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-label">Combined Month Rev</div><div class="stat-value" style="font-size:1.2rem">KES <?=number_format($total_branch_rev)?></div></div>
      <div class="stat-card yellow"><div class="stat-icon">📈</div><div class="stat-label">Avg Per Branch</div><div class="stat-value" style="font-size:1.2rem">KES <?=count($brs_rep)>0?number_format($total_branch_rev/count($brs_rep)):0?></div></div>
    </div>

    <?php foreach($branch_stats as $bi=>$bs):
      $share = $total_branch_rev > 0 ? round(($bs['month']['total_revenue']/$total_branch_rev)*100) : 0;
      $rank_color = $bi===0 ? 'var(--green)' : ($bi===1 ? 'var(--accent)' : ($bi===2 ? 'var(--orange)' : 'var(--text3)'));
    ?>
    <div class="card mb-4">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:14px">
        <div>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-family:var(--font-head);font-size:1.1rem;font-weight:800;color:<?=$rank_color?>">#<?=$bi+1?></span>
            <strong style="font-size:1rem"><?=htmlspecialchars($bs['branch_name'])?></strong>
            <span class="badge badge-<?=$bs['is_active']?'green':'gray'?>"><?=$bs['is_active']?'Active':'Inactive'?></span>
          </div>
          <div style="font-size:.75rem;color:var(--text3);margin-top:3px">👥 <?=$bs['user_count']?> active users · <?=$share?>% of total revenue</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:1.3rem;font-weight:800;font-family:var(--font-head);color:var(--green)">KES <?=number_format($bs['month']['total_revenue']??0)?></div>
          <div style="font-size:.72rem;color:var(--text3)">this month</div>
        </div>
      </div>
      <!-- Revenue share bar -->
      <div style="height:6px;background:var(--surface3);border-radius:99px;overflow:hidden;margin-bottom:14px">
        <div style="height:100%;width:<?=$share?>%;background:<?=$rank_color?>;border-radius:99px"></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px">
        <?php foreach([['Today',($bs['today']['total_revenue']??0),($bs['today']['total_sales']??0)],['This Week',($bs['week']['total_revenue']??0),($bs['week']['total_sales']??0)],['This Month',($bs['month']['total_revenue']??0),($bs['month']['total_sales']??0)]] as [$pl,$pv,$pt]): ?>
        <div style="background:var(--surface2);border-radius:var(--radius);padding:8px 6px;text-align:center;min-width:0">
          <div style="font-size:.6rem;color:var(--text3);text-transform:uppercase;letter-spacing:.3px;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=$pl?></div>
          <div style="font-weight:700;font-size:.78rem;word-break:break-all">KES <?=number_format($pv)?></div>
          <div style="font-size:.62rem;color:var(--text3)"><?=$pt?> sales</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php elseif($rtab === 'payments'): ?>
    <!-- ══ PAYMENTS TAB ══ -->
    <?php
      $pm_total = array_sum(array_column($pay_methods,'rev'));
      $pm_total_txn = array_sum(array_column($pay_methods,'cnt'));
      $collect_rate = ($r_month['total_revenue']??0) > 0
          ? round(($pm_total / ($r_month['total_revenue']??1)) * 100, 1) : 0;

      // Customer credit health
      $credit_health = [];
      try {
          $ch = db()->prepare("
              SELECT
                COUNT(*) AS total_customers,
                SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS with_debt,
                SUM(CASE WHEN balance > credit_limit AND credit_limit > 0 THEN 1 ELSE 0 END) AS overlimit,
                COALESCE(SUM(balance),0) AS total_debt,
                COALESCE(AVG(CASE WHEN balance > 0 THEN balance END),0) AS avg_debt
              FROM customers
              WHERE branch_id IN (SELECT id FROM branches WHERE client_id=?)
          ");
          $ch->execute([$u['client_id']]);
          $credit_health = $ch->fetch() ?: [];
      } catch(Throwable $e){ nx_debug($e,'reports/credithealth'); }
    ?>
    <div class="stats-grid mb-6" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr))">
      <div class="stat-card green"><div class="stat-icon">✅</div><div class="stat-label">Collection Rate</div><div class="stat-value"><?=$collect_rate?>%</div><div class="stat-sub">of gross revenue</div></div>
      <div class="stat-card orange"><div class="stat-icon">💳</div><div class="stat-label">Credit Outstanding</div><div class="stat-value" style="font-size:1.2rem">KES <?=number_format($credit_outstanding)?></div></div>
      <div class="stat-card yellow"><div class="stat-icon">👤</div><div class="stat-label">Customers w/ Debt</div><div class="stat-value"><?=$credit_health['with_debt']??0?></div><div class="stat-sub">of <?=$credit_health['total_customers']??0?> total</div></div>
      <div class="stat-card" style=""><div class="stat-icon" style="opacity:.12">⚠</div><div class="stat-label">Over Credit Limit</div><div class="stat-value" style="color:var(--red)"><?=$credit_health['overlimit']??0?></div><div class="stat-sub">customers</div></div>
    </div>

    <div class="grid-2 mb-6">
      <div class="card">
        <div class="card-header"><div class="card-title">💳 Payment Method Breakdown (Month)</div></div>
        <?php if($pay_methods): foreach($pay_methods as $pmr):
          $pct2 = $pm_total > 0 ? round(($pmr['rev']/$pm_total)*100) : 0;
          $colors2 = ['cash'=>'var(--green)','mpesa'=>'var(--accent)','credit'=>'var(--orange)','mixed'=>'var(--accent2)'];
          $bc2 = $colors2[$pmr['payment_method']] ?? 'var(--text3)';
        ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:.84rem;align-items:center">
            <span style="text-transform:uppercase;font-weight:700;color:<?=$bc2?>"><?=htmlspecialchars($pmr['payment_method'])?></span>
            <div style="text-align:right">
              <div style="font-weight:700">KES <?=number_format($pmr['rev'])?></div>
              <div style="font-size:.68rem;color:var(--text3)"><?=$pct2?>% · <?=$pmr['cnt']?> transactions</div>
            </div>
          </div>
          <div style="height:8px;background:var(--surface3);border-radius:99px;overflow:hidden">
            <div style="height:100%;width:<?=$pct2?>%;background:<?=$bc2?>;border-radius:99px"></div>
          </div>
        </div>
        <?php endforeach;
        else: ?><div class="empty-state" style="padding:16px"><p>No sales this month.</p></div><?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">🏥 Credit Health Indicators</div></div>
        <?php
          $debt_ratio = ($credit_health['total_customers']??0) > 0
              ? round(($credit_health['with_debt']??0)/($credit_health['total_customers']??1)*100,1) : 0;
          $overlimit_ratio = ($credit_health['with_debt']??0) > 0
              ? round(($credit_health['overlimit']??0)/($credit_health['with_debt']??1)*100,1) : 0;
          $indicators = [
              ['Customers with Debt', $debt_ratio.'%', $debt_ratio<=25?'good':($debt_ratio<=50?'fair':'poor'), 'KES '.number_format($credit_health['total_debt']??0).' total'],
              ['Avg Debt per Customer', 'KES '.number_format($credit_health['avg_debt']??0), 'info', ''],
              ['Over-limit Customers', $overlimit_ratio.'% of debtors', $overlimit_ratio<=10?'good':($overlimit_ratio<=25?'fair':'poor'), ($credit_health['overlimit']??0).' customers'],
              ['Collection Rate', $collect_rate.'%', $collect_rate>=80?'good':($collect_rate>=60?'fair':'poor'), 'vs gross this month'],
          ];
          $health_colors = ['good'=>'var(--green)','fair'=>'var(--yellow)','poor'=>'var(--red)','info'=>'var(--accent)'];
        ?>
        <?php foreach($indicators as [$label,$val,$health,$sub2]): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border)">
          <div>
            <div style="font-size:.83rem;font-weight:500"><?=$label?></div>
            <?php if($sub2): ?><div style="font-size:.68rem;color:var(--text3)"><?=$sub2?></div><?php endif; ?>
          </div>
          <div style="font-family:var(--font-head);font-weight:700;color:<?=$health_colors[$health]??'var(--text)'?>"><?=$val?></div>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:12px;padding:10px 12px;border-radius:var(--radius);font-size:.78rem;
          background:<?=$collect_rate>=80?'rgba(62,207,142,.08)':'rgba(247,85,85,.08)'?>;
          border:1px solid <?=$collect_rate>=80?'rgba(62,207,142,.25)':'rgba(247,85,85,.25)'?>;
          color:<?=$collect_rate>=80?'var(--green)':'var(--red)'?>">
          <?=$collect_rate>=80
            ? '✅ Collection rate is healthy. Credit exposure is well-managed.'
            : '⚠ High credit exposure detected. Consider tightening credit terms or following up on balances.'?>
        </div>
      </div>
    </div>

    <?php endif; // rtab ?>

    <?php
    // ── TICKETS ──────────────────────────────────────────────
    elseif ($page==='tickets' && can('tickets')):
      $ticket_id = (int)($_GET['id'] ?? 0);
      if ($ticket_id) {
          $ticket  = get_ticket($ticket_id, $u['client_id']);
          $replies = get_ticket_replies($ticket_id);
      } else {
          $tickets = get_tickets($u['client_id']);
      }

      if ($ticket_id && !empty($ticket)):
    ?>
    <div style="margin-bottom:16px"><a href="?page=tickets" class="btn btn-sm btn-ghost">← All Tickets</a></div>
    <div class="card mb-6">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
        <div>
          <div style="font-size:.72rem;color:var(--text3);margin-bottom:4px">Ticket #<?=$ticket['id']?></div>
          <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:700"><?=htmlspecialchars($ticket['subject'])?></div>
          <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
            <span class="badge badge-<?=match($ticket['priority']){'critical'=>'red','high'=>'orange','medium'=>'yellow','low'=>'gray',default=>'gray'}?>"><?=$ticket['priority']?></span>
            <span class="badge badge-<?=match($ticket['status']){'open'=>'blue','in_progress'=>'yellow','resolved'=>'green','closed'=>'gray',default=>'gray'}?>"><?=str_replace('_',' ',$ticket['status'])?></span>
            <?php if(!empty($ticket['assigned_name'])): ?><span class="badge badge-purple">Assigned: <?=htmlspecialchars($ticket['assigned_name'])?></span><?php endif; ?>
          </div>
        </div>
        <?php if(!in_array($ticket['status'],['closed','resolved'])): ?>
        <form method="POST">
          <input type="hidden" name="action" value="close_ticket">
          <input type="hidden" name="_csrf"     value="<?=csrf_token()?>">
          <input type="hidden" name="ticket_id" value="<?=$ticket['id']?>">
          <button type="submit" class="btn btn-ghost btn-sm">Close Ticket</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="card mb-4">
      <div class="card-header"><div class="card-title">Conversation</div></div>
      <div class="thread">
        <?php foreach($replies??[] as $r): ?>
        <div><div class="bubble <?=$r['sender_type']?>">
          <?=nl2br(htmlspecialchars($r['message']))?>
          <div class="bubble-meta"><?=htmlspecialchars($r['sender_name']??$r['sender_type'])?> · <?=date('d M Y, H:i',strtotime($r['created_at']))?></div>
        </div></div>
        <?php endforeach; ?>
        <?php if(empty($replies)): ?><div class="empty-state" style="padding:16px"><p>No messages yet.</p></div><?php endif; ?>
      </div>
      <?php if(!in_array($ticket['status'],['closed','resolved'])): ?>
      <form method="POST">
        <input type="hidden" name="action" value="reply_ticket">
        <input type="hidden" name="_csrf"     value="<?=csrf_token()?>">
        <input type="hidden" name="ticket_id" value="<?=$ticket['id']?>">
        <div class="form-group mb-4"><label>Reply</label><textarea name="message" class="form-control" rows="3" placeholder="Type your message..." required></textarea></div>
        <button type="submit" class="btn btn-primary">Send Reply</button>
      </form>
      <?php endif; ?>
    </div>

    <?php else: /* ticket list */ ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
      <div class="card-title" style="font-size:1rem">Support Tickets</div>
      <button class="btn btn-primary" onclick="openModal('ticketModal')">+ New Ticket</button>
    </div>
    <div class="card">
      <?php if(!empty($tickets)): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Subject</th><th>Priority</th><th>Status</th><th>Assigned To</th><th>Replies</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach($tickets as $t): ?>
        <tr>
          <td class="td-muted">#<?=$t['id']?></td>
          <td><strong><?=htmlspecialchars($t['subject'])?></strong></td>
          <td><span class="badge badge-<?=match($t['priority']){'critical'=>'red','high'=>'orange','medium'=>'yellow','low'=>'gray',default=>'gray'}?>"><?=$t['priority']?></span></td>
          <td><span class="badge badge-<?=match($t['status']){'open'=>'blue','in_progress'=>'yellow','resolved'=>'green','closed'=>'gray',default=>'gray'}?>"><?=str_replace('_',' ',$t['status'])?></span></td>
          <td class="td-muted"><?=htmlspecialchars($t['assigned_name']??'Unassigned')?></td>
          <td class="td-muted"><?=$t['reply_count']?></td>
          <td class="td-muted"><?=date('d M Y',strtotime($t['created_at']))?></td>
          <td><a href="?page=tickets&id=<?=$t['id']?>" class="btn btn-xs btn-ghost">View</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">🎫</div><p>No tickets yet. Click "New Ticket" to get help.</p></div><?php endif; ?>
    </div>

    <div class="modal-backdrop" id="ticketModal">
      <div class="modal modal-lg">
        <div class="modal-header"><div class="modal-title">Create Support Ticket</div><button class="modal-close" onclick="closeModal('ticketModal')">✕</button></div>
        <form method="POST">
          <input type="hidden" name="action" value="create_ticket">
          <input type="hidden" name="_csrf"  value="<?=csrf_token()?>">
          <div class="form-group mb-4"><label>Subject *</label><input type="text" name="subject" class="form-control" placeholder="Brief description of your issue" required></div>
          <div class="form-group mb-4"><label>Priority</label>
            <select name="priority" class="form-control">
              <option value="low">Low</option><option value="medium" selected>Medium</option>
              <option value="high">High</option><option value="critical">Critical</option>
            </select>
          </div>
          <div class="form-group mb-4"><label>Message *</label><textarea name="message" class="form-control" rows="5" placeholder="Describe your issue in detail..." required></textarea></div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Submit Ticket</button>
            <button type="button" class="btn btn-ghost" onclick="closeModal('ticketModal')">Cancel</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // ── eTIMS / KRA ──────────────────────────────────────────
    elseif ($page === 'etims' && can('branches')):
        $etims_branches = get_etims_branches($u['client_id']);
        $etims_stats    = get_etims_stats($u['client_id']);
        $etims_failures = get_etims_recent_failures($u['client_id']);
        $etims_audit    = get_etims_audit($u['client_id']);
        $selected_bid   = (int)($_GET['bid'] ?? ($etims_branches[0]['id'] ?? 0));
        $selected_branch = null;
        foreach ($etims_branches as $eb) {
            if ($eb['id'] == $selected_bid) { $selected_branch = $eb; break; }
        }
    ?>

    <!-- Page header -->
    <div style="margin-bottom:24px">
        <div style="font-family:var(--font-head);font-size:1.2rem;font-weight:800;margin-bottom:4px">🧾 eTIMS / KRA Integration</div>
        <div style="font-size:.8rem;color:var(--text3)">Configure KRA eTIMS credentials per branch. Each branch needs its own credentials from the KRA eTIMS portal.</div>
    </div>

    <!-- Today's submission stats -->
    <div class="stats-grid mb-6" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr))">
        <div class="stat-card green">
            <div class="stat-icon" style="opacity:.15">✅</div>
            <div class="stat-label">Submitted Today</div>
            <div class="stat-value"><?= $etims_stats['submitted'] ?></div>
            <div class="stat-sub">sent to KRA</div>
        </div>
        <div class="stat-card" style="">
            <div class="stat-icon" style="opacity:.15">❌</div>
            <div class="stat-label">Failed</div>
            <div class="stat-value" style="color:var(--red)"><?= $etims_stats['failed'] ?></div>
            <div class="stat-sub">need retry</div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-icon" style="opacity:.15">⏳</div>
            <div class="stat-label">Pending</div>
            <div class="stat-value"><?= $etims_stats['pending'] ?></div>
            <div class="stat-sub">queued</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="opacity:.15">⏭</div>
            <div class="stat-label">Skipped</div>
            <div class="stat-value" style="color:var(--text3)"><?= $etims_stats['skipped'] ?></div>
            <div class="stat-sub">no eTIMS on branch</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-icon" style="opacity:.15">🏪</div>
            <div class="stat-label">Branches Configured</div>
            <div class="stat-value"><?= count(array_filter($etims_branches, fn($b) => $b['etims_enabled'])) ?></div>
            <div class="stat-sub">of <?= count($etims_branches) ?> total</div>
        </div>
    </div>

    <div class="grid-2" style="align-items:start">

        <!-- LEFT: Branch selector + credentials form -->
        <div>
            <!-- Branch tabs -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
                <?php foreach ($etims_branches as $eb): ?>
                <a href="?page=etims&bid=<?= $eb['id'] ?>"
                   style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--radius);font-size:.8rem;font-weight:600;border:1px solid var(--border);text-decoration:none;transition:all var(--tr);
                   <?= $eb['id'] == $selected_bid
                       ? 'background:var(--accent);color:#fff;border-color:var(--accent)'
                       : 'background:var(--surface2);color:var(--text2)' ?>">
                    <?= $eb['etims_enabled'] ? '✅' : '⭕' ?>
                    <?= htmlspecialchars($eb['branch_name']) ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($selected_branch): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <?= htmlspecialchars($selected_branch['branch_name']) ?>
                        <?php if ($selected_branch['etims_enabled']): ?>
                            <span class="badge badge-green" style="margin-left:8px">eTIMS Active</span>
                        <?php else: ?>
                            <span class="badge badge-gray" style="margin-left:8px">Not Configured</span>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action"    value="save_etims">
                    <input type="hidden" name="_csrf"     value="<?= csrf_token() ?>">
                    <input type="hidden" name="branch_id" value="<?= $selected_branch['id'] ?>">

                    <!-- Enable toggle -->
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--border);margin-bottom:18px">
                        <div>
                            <div style="font-weight:600;font-size:.9rem">Enable eTIMS for this branch</div>
                            <div style="font-size:.72rem;color:var(--text3);margin-top:2px">Sales will be automatically submitted to KRA after enabling</div>
                        </div>
                        <label style="position:relative;display:inline-block;width:46px;height:26px;flex-shrink:0">
                            <input type="checkbox" name="etims_enabled" value="1"
                                   <?= $selected_branch['etims_enabled'] ? 'checked' : '' ?>
                                   style="opacity:0;width:0;height:0" id="etims-toggle"
                                   onchange="document.getElementById('etims-fields').style.opacity=this.checked?'1':'.5'">
                            <span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;border-radius:26px;transition:.3s;background:var(--surface3)" id="etims-slider"></span>
                        </label>
                    </div>

                    <div id="etims-fields" style="opacity:<?= $selected_branch['etims_enabled'] ? '1' : '.6' ?>;transition:opacity .2s">
                        <div class="form-grid">
                            <div class="form-group" style="grid-column:1/-1">
                                <label>KRA PIN (Taxpayer PIN) <span style="color:var(--red)">*</span></label>
                                <input type="text" name="etims_pin" class="form-control"
                                       value="<?= htmlspecialchars($selected_branch['etims_pin'] ?? '') ?>"
                                       placeholder="e.g. P051234567A"
                                       oninput="this.value=this.value.toUpperCase()"
                                       maxlength="20">
                                <div style="font-size:.7rem;color:var(--text3);margin-top:4px">Your business KRA PIN registered with NYMIX TECH</div>
                            </div>
                            <div class="form-group">
                                <label>Branch Code (bhfId) <span style="color:var(--red)">*</span></label>
                                <input type="text" name="etims_branch_code" class="form-control"
                                       value="<?= htmlspecialchars($selected_branch['etims_branch_code'] ?? '') ?>"
                                       placeholder="e.g. 00 or 01">
                                <div style="font-size:.7rem;color:var(--text3);margin-top:4px">From KRA eTIMS portal → Branch Management</div>
                            </div>
                            <div class="form-group">
                                <label>Device Serial (dvcSrlNo) <span style="color:var(--red)">*</span></label>
                                <input type="text" name="etims_device_serial" class="form-control"
                                       value="<?= htmlspecialchars($selected_branch['etims_device_serial'] ?? '') ?>"
                                       placeholder="From KRA eTIMS device registration">
                                <div style="font-size:.7rem;color:var(--text3);margin-top:4px">Issued when you register the virtual ETR device</div>
                            </div>
                            <div class="form-group" style="grid-column:1/-1">
                                <label>Environment</label>
                                <select name="etims_env" class="form-control">
                                    <option value="sandbox" <?= ($selected_branch['etims_env'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>
                                        🧪 Sandbox — Testing (use this first)
                                    </option>
                                    <option value="live" <?= ($selected_branch['etims_env'] ?? '') === 'live' ? 'selected' : '' ?>>
                                        🟢 Live — Production (real KRA submissions)
                                    </option>
                                </select>
                                <div style="font-size:.7rem;color:var(--text3);margin-top:4px">Always test in Sandbox before switching to Live</div>
                            </div>
                        </div>

                        <?php if ($selected_branch['etims_enabled'] && $selected_branch['etims_env'] === 'live'): ?>
                        <div style="background:rgba(62,207,142,.08);border:1px solid rgba(62,207,142,.25);border-radius:var(--radius);padding:10px 13px;margin-top:14px;font-size:.78rem;color:var(--green)">
                            🟢 <strong>Live mode active.</strong> All sales from this branch are being submitted to KRA in real time.
                        </div>
                        <?php elseif ($selected_branch['etims_enabled'] && $selected_branch['etims_env'] === 'sandbox'): ?>
                        <div style="background:rgba(245,197,66,.08);border:1px solid rgba(245,197,66,.25);border-radius:var(--radius);padding:10px 13px;margin-top:14px;font-size:.78rem;color:var(--yellow)">
                            🧪 <strong>Sandbox mode.</strong> Submissions go to KRA's test environment. Switch to Live when ready.
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:20px;display:flex;gap:10px">
                        <button type="submit" class="btn btn-primary">💾 Save Settings</button>
                        <?php if ($selected_branch['etims_enabled']): ?>
                        <a href="?page=etims&bid=<?= $selected_branch['id'] ?>&test=1" class="btn btn-ghost">🔌 Test Connection</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <?php else: ?>
            <div class="card">
                <div class="empty-state"><div class="empty-icon">🏪</div><p>Select a branch above to configure its eTIMS credentials.</p></div>
            </div>
            <?php endif; ?>

            <!-- How to get credentials -->
            <div class="card" style="margin-top:16px">
                <div class="card-header"><div class="card-title">📋 How to Get eTIMS Credentials</div></div>
                <div style="font-size:.82rem;color:var(--text2);line-height:1.8">
                    <div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
                        <span style="width:22px;height:22px;background:var(--accent);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0">1</span>
                        <span>Log in to <a href="https://itax.kra.go.ke" target="_blank" style="color:var(--accent)">itax.kra.go.ke</a> with your KRA credentials</span>
                    </div>
                    <div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
                        <span style="width:22px;height:22px;background:var(--accent);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0">2</span>
                        <span>Navigate to <strong>eTIMS</strong> → <strong>Device Management</strong> → <strong>Register Device</strong></span>
                    </div>
                    <div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
                        <span style="width:22px;height:22px;background:var(--accent);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0">3</span>
                        <span>Register each branch as a separate device — you'll get a <strong>Branch Code</strong> and <strong>Device Serial</strong> per branch</span>
                    </div>
                    <div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
                        <span style="width:22px;height:22px;background:var(--accent);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0">4</span>
                        <span>Enter the credentials here in <strong>Sandbox mode</strong> first and make a test sale</span>
                    </div>
                    <div style="display:flex;gap:10px;padding:8px 0">
                        <span style="width:22px;height:22px;background:var(--green);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;flex-shrink:0">5</span>
                        <span>Once confirmed working, switch to <strong>Live mode</strong> — all future sales will submit to KRA automatically</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Status + failures + audit log -->
        <div>
            <!-- Failed submissions -->
            <?php if ($etims_failures): ?>
            <div class="card mb-4" style="border-color:rgba(247,85,85,.3)">
                <div class="card-header">
                    <div class="card-title" style="color:var(--red)">⚠ Failed Submissions</div>
                    <span class="badge badge-red"><?= count($etims_failures) ?> pending</span>
                </div>
                <div style="font-size:.75rem;color:var(--text3);margin-bottom:12px">
                    These sales failed to reach KRA. Your staff portal has a Retry button on each sale, or they can bulk retry from the eTIMS admin page.
                </div>
                <?php foreach ($etims_failures as $fail): ?>
                <div style="background:rgba(247,85,85,.06);border:1px solid rgba(247,85,85,.15);border-radius:var(--radius);padding:10px 13px;margin-bottom:8px">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
                        <div style="min-width:0">
                            <div style="font-weight:700;font-size:.85rem"><?= htmlspecialchars($fail['receipt_no']) ?></div>
                            <div style="font-size:.72rem;color:var(--text3);margin-top:2px">
                                <?= htmlspecialchars($fail['branch_name']) ?> · <?= date('d M Y H:i', strtotime($fail['sale_date'])) ?>
                            </div>
                            <?php if ($fail['etims_error']): ?>
                            <div style="font-size:.7rem;color:var(--red);margin-top:3px;word-break:break-word">
                                Error: <?= htmlspecialchars(substr($fail['etims_error'], 0, 80)) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:right;flex-shrink:0">
                            <div style="font-weight:700;font-size:.9rem">KES <?= number_format($fail['grand_total'], 2) ?></div>
                            <div style="font-size:.68rem;color:var(--text3)"><?= $fail['etims_retry_count'] ?> retries</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div style="font-size:.72rem;color:var(--text3);margin-top:8px;padding:10px 12px;background:var(--surface2);border-radius:var(--radius)">
                    💡 Log in to the Staff Portal → eTIMS / KRA → Retry Failed Queue to resubmit these in bulk.
                </div>
            </div>
            <?php else: ?>
            <div class="card mb-4" style="border-color:rgba(62,207,142,.2)">
                <div style="text-align:center;padding:20px">
                    <div style="font-size:2rem;margin-bottom:8px">✅</div>
                    <div style="font-weight:700;color:var(--green);margin-bottom:4px">All Submissions OK</div>
                    <div style="font-size:.78rem;color:var(--text3)">No failed eTIMS submissions found</div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Branch status overview -->
            <div class="card mb-4">
                <div class="card-header"><div class="card-title">🏪 Branch eTIMS Status</div></div>
                <?php foreach ($etims_branches as $eb): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border)">
                    <div style="min-width:0;flex:1;overflow:hidden">
                        <div style="font-weight:600;font-size:.85rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= htmlspecialchars($eb['branch_name']) ?>
                        </div>
                        <?php if ($eb['etims_enabled']): ?>
                        <div style="font-size:.7rem;color:var(--text3);margin-top:1px">
                            PIN: <?= htmlspecialchars($eb['etims_pin'] ?? '—') ?> ·
                            Code: <?= htmlspecialchars($eb['etims_branch_code'] ?? '—') ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
                        <?php if ($eb['etims_enabled']): ?>
                            <span class="badge badge-<?= $eb['etims_env'] === 'live' ? 'green' : 'yellow' ?>">
                                <?= $eb['etims_env'] === 'live' ? '🟢 Live' : '🧪 Sandbox' ?>
                            </span>
                        <?php else: ?>
                            <span class="badge badge-gray">⭕ Not set up</span>
                        <?php endif; ?>
                        <a href="?page=etims&bid=<?= $eb['id'] ?>" class="btn btn-xs btn-ghost">Edit</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Audit log -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">📋 Recent Submission Log</div>
                    <span style="font-size:.72rem;color:var(--text3)">Last 30 submissions</span>
                </div>
                <?php if ($etims_audit): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Receipt</th>
                                <th>Branch</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>CU Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($etims_audit as $a):
                            $sc = match($a['etims_status']) {
                                'submitted' => 'badge-green',
                                'failed'    => 'badge-red',
                                default     => 'badge-gray',
                            };
                        ?>
                        <tr>
                            <td><strong style="font-size:.8rem"><?= htmlspecialchars($a['receipt_no']) ?></strong></td>
                            <td class="td-muted" style="font-size:.75rem"><?= htmlspecialchars($a['branch_name']) ?></td>
                            <td class="td-muted" style="font-size:.75rem"><?= date('d M, H:i', strtotime($a['sale_date'])) ?></td>
                            <td style="font-size:.82rem;font-weight:600">KES <?= number_format($a['grand_total'], 2) ?></td>
                            <td>
                                <span class="badge <?= $sc ?>" style="font-size:.65rem">
                                    <?= ucfirst($a['etims_status']) ?>
                                </span>
                                <?php if ($a['etims_status'] === 'failed' && $a['etims_error']): ?>
                                <div style="font-size:.65rem;color:var(--red);margin-top:2px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($a['etims_error']) ?>">
                                    <?= htmlspecialchars(substr($a['etims_error'], 0, 30)) ?>…
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="td-muted" style="font-size:.7rem;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?= htmlspecialchars($a['etims_invoice_no'] ?? '—') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state" style="padding:24px">
                    <div class="empty-icon">📋</div>
                    <p>No submissions yet. Enable eTIMS on a branch and make a sale to see logs here.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Toggle slider JS -->
    <script>
    (function() {
        const toggle  = document.getElementById('etims-toggle');
        const slider  = document.getElementById('etims-slider');
        const fields  = document.getElementById('etims-fields');
        function updateSlider(checked) {
            slider.style.background = checked ? 'var(--green)' : 'var(--surface3)';
            if (!slider.querySelector('span')) {
                const dot = document.createElement('span');
                dot.style.cssText = 'position:absolute;width:20px;height:20px;background:#fff;border-radius:50%;top:3px;transition:.3s;box-shadow:0 1px 4px rgba(0,0,0,.3)';
                slider.appendChild(dot);
            }
            slider.querySelector('span').style.left = checked ? '23px' : '3px';
            if (fields) fields.style.opacity = checked ? '1' : '.6';
        }
        if (toggle) {
            updateSlider(toggle.checked);
            toggle.addEventListener('change', () => updateSlider(toggle.checked));
        }
    })();
    </script>

    <?php else: ?>
    <div class="empty-state" style="padding:80px 24px">
      <div class="empty-icon">🔒</div>
      <p>You don't have permission to access this page.</p>
      <a href="?page=dashboard" class="btn btn-ghost mt-4" style="display:inline-flex">← Go to Dashboard</a>
    </div>
    <?php endif; ?>

  </div><!-- .content -->
</div><!-- .main -->
</div><!-- .layout -->
<?php endif; ?>

<!-- ══════════ SESSION LOCK SCREEN ══════════ -->
<div id="nx-lock-screen" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(13,15,20,0.98);backdrop-filter:blur(16px);">
  <div id="nx-lock-card" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:36px 24px;width:calc(100% - 32px);max-width:380px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.7)">
    <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:700;color:#fff;margin:0 auto 16px" id="nx-lock-avatar">
      <?php if(is_logged_in()): ?><?= strtoupper(substr($u['name'] ?? 'U', 0, 1)) ?><?php else: ?>?<?php endif; ?>
    </div>
    <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:700;margin-bottom:4px" id="nx-lock-name">
      <?php if(is_logged_in()): ?><?= htmlspecialchars($u['name'] ?? '') ?><?php endif; ?>
    </div>
    <div style="color:var(--text3);font-size:.8rem;margin-bottom:6px">
      <?php if(is_logged_in()): ?><?= htmlspecialchars($u['role'] ?? '') ?><?php endif; ?>
    </div>
    <div style="color:var(--text3);font-size:.75rem;margin-bottom:28px">
      <span style="color:var(--accent)">🔒</span> Screen Locked
    </div>
    <div style="position:relative;margin-bottom:12px">
      <input type="password" id="nx-lock-pw"
             style="width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:var(--radius);padding:12px 42px 12px 14px;font-size:1rem;font-family:var(--font-body);text-align:center;letter-spacing:2px"
             placeholder="Enter your password"
             autocomplete="current-password"
             onkeydown="if(event.key==='Enter')nxUnlock()">
      <button type="button"
              style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text3);cursor:pointer;font-size:.9rem"
              onclick="const i=document.getElementById('nx-lock-pw');i.type=i.type==='password'?'text':'password'">👁</button>
    </div>
    <div id="nx-lock-error" style="display:none;color:var(--red);font-size:.78rem;margin-bottom:12px;padding:8px;background:rgba(247,85,85,.1);border-radius:var(--radius)"></div>
    <button onclick="nxUnlock()" id="nx-lock-btn"
            style="width:100%;background:var(--accent);color:#fff;border:none;padding:12px;border-radius:var(--radius);font-family:var(--font-head);font-size:.95rem;font-weight:700;cursor:pointer;margin-bottom:12px;transition:all .2s">
      🔓 Unlock
    </button>
    <div style="border-top:1px solid var(--border);padding-top:16px">
      <a href="?page=login&action=force_logout" onclick="nxForceLogout()"
         style="font-size:.78rem;color:var(--text3);text-decoration:none;display:block">
        Sign in as different user →
      </a>
    </div>
    <div style="color:var(--text3);font-size:.68rem;margin-top:16px">
      🛡 NYMIX TECH · Session Secured
    </div>
  </div>
</div>

<!-- ══════════ IDLE WARNING OVERLAY ══════════ -->
<div id="nx-idle-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:99998;align-items:center;justify-content:center;backdrop-filter:blur(4px)">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:40px 36px;max-width:360px;width:90%;text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.6)">
    <div style="font-size:3rem;margin-bottom:12px">⏱️</div>
    <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:700;margin-bottom:8px">Still there?</div>
    <p style="color:var(--text3);font-size:.875rem;margin-bottom:16px">Your session will lock in</p>
    <div id="nx-idle-seconds" style="font-family:var(--font-head);font-size:3.5rem;font-weight:800;color:var(--accent);margin-bottom:8px">30</div>
    <div style="height:4px;background:var(--surface3);border-radius:99px;overflow:hidden;margin-bottom:24px">
      <div id="nx-idle-bar" style="height:100%;width:100%;background:var(--accent);border-radius:99px;transition:width 1s linear"></div>
    </div>
    <button onclick="nxResetIdle()" style="width:100%;background:var(--accent);color:#fff;border:none;padding:12px;border-radius:var(--radius);font-family:var(--font-head);font-size:.95rem;font-weight:700;cursor:pointer;margin-bottom:10px">
      Keep me signed in
    </button>
    <button onclick="nxForceLogout()" style="width:100%;background:transparent;border:1px solid var(--border);color:var(--text3);padding:10px;border-radius:var(--radius);font-family:var(--font-body);font-size:.85rem;cursor:pointer">
      Sign out now
    </button>
  </div>
</div>

<script>
function openModal(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden'}
function closeModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow=''}
document.querySelectorAll('.modal-backdrop').forEach(b=>b.addEventListener('click',e=>{if(e.target===b)closeModal(b.id)}));

// ── SIDEBAR (mobile) ──────────────────────────────────────
const nxSidebar = document.getElementById('sidebar');
const nxOverlay = document.getElementById('nx-overlay');
const nxBurger  = document.getElementById('nx-burger');

function nxOpenSidebar() {
  nxSidebar?.classList.add('open');
  if (nxOverlay) nxOverlay.style.display = 'block';
  if (nxBurger)  nxBurger.setAttribute('aria-expanded','true');
  document.body.style.overflow = 'hidden';
}
function nxCloseSidebar() {
  nxSidebar?.classList.remove('open');
  if (nxOverlay) nxOverlay.style.display = 'none';
  if (nxBurger)  nxBurger.setAttribute('aria-expanded','false');
  document.body.style.overflow = '';
}
nxBurger?.addEventListener('click', () => {
  nxSidebar?.classList.contains('open') ? nxCloseSidebar() : nxOpenSidebar();
});
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', () => { if (window.innerWidth <= 768) nxCloseSidebar(); });
});
window.addEventListener('resize', () => { if (window.innerWidth > 768) nxCloseSidebar(); });

// ── THEME TOGGLE ───────────────────────────────────────────
function toggleClientTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
  html.setAttribute('data-theme', next);
  localStorage.setItem('nymix-theme', next);
  const btn = document.getElementById('theme-btn');
  if (btn) btn.textContent = next === 'dark' ? '🌙' : '☀️';
}
(function() {
  const saved = localStorage.getItem('nymix-theme');
  if (saved) {
    document.documentElement.setAttribute('data-theme', saved);
    const btn = document.getElementById('theme-btn');
    if (btn) btn.textContent = saved === 'dark' ? '🌙' : '☀️';
  }
})();

setTimeout(()=>{const f=document.querySelector('.flash');if(f){f.style.opacity='0';f.style.transition='.5s';setTimeout(()=>f.remove(),500)}},4000);

function switchTab(tab){
  document.getElementById('tab-login').classList.toggle('hidden',tab!=='login');
  document.getElementById('tab-register').classList.toggle('hidden',tab!=='register');
  document.querySelectorAll('.auth-tab').forEach((b,i)=>b.classList.toggle('active',(i===0&&tab==='login')||(i===1&&tab==='register')));
  if(tab==='register') goStep(1,false);
}

function goStep(n,validate=true){
  if(validate&&n===2){
    const kra=document.getElementById('kra_pin')?.value.trim();
    const ph=document.getElementById('reg_phone')?.value.trim();
    if(!kra||!ph){alert('Please fill in KRA PIN and Phone.');return}
  }
  if(validate&&n===3){
    const email=document.querySelector('#tab-register [name="email"]')?.value.trim();
    const pw=document.getElementById('pw-reg')?.value;
    const pw2=document.getElementById('pw-reg2')?.value;
    if(!email){alert('Email is required.');return}
    if(pw.length<8){alert('Password must be at least 8 characters.');return}
    if(pw!==pw2){alert('Passwords do not match.');return}
    document.getElementById('rv-kra').textContent=document.getElementById('kra_pin').value;
    document.getElementById('rv-phone').textContent=document.getElementById('reg_phone').value;
    document.getElementById('rv-email').textContent=email;
  }
  for(let i=1;i<=3;i++){
    document.getElementById('reg-step-'+i)?.classList.toggle('hidden',i!==n);
    const dot=document.getElementById('step-dot-'+i);
    if(dot){dot.classList.toggle('active',i===n);dot.classList.toggle('done',i<n);dot.textContent=i<n?'✓':i}
  }
  document.querySelectorAll('.step-line').forEach((l,i)=>l.classList.toggle('done',i<n-1));
}

// ── NOTIFICATION BELL ─────────────────────────────────────
function nxToggleNotif() {
  const panel = document.getElementById('nx-notif-panel');
  if (!panel) return;
  panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
  const wrap = document.getElementById('nx-notif-wrap');
  if (wrap && !wrap.contains(e.target)) {
    const panel = document.getElementById('nx-notif-panel');
    if (panel) panel.style.display = 'none';
  }
});

function togglePw(id,btn){const el=document.getElementById(id);const s=el.type==='password';el.type=s?'text':'password';btn.textContent=s?'🙈':'👁'}

function checkPwStrength(val){
  const fill=document.getElementById('pwFill');const lbl=document.getElementById('pwLabel');
  if(!fill||!lbl)return;
  let s=0;
  if(val.length>=8)s++;if(val.length>=12)s++;if(/[A-Z]/.test(val))s++;if(/[0-9]/.test(val))s++;if(/[^A-Za-z0-9]/.test(val))s++;
  const lvls=[{p:'20%',c:'var(--red)',t:'Very weak'},{p:'40%',c:'var(--orange)',t:'Weak'},{p:'60%',c:'var(--yellow)',t:'Fair'},{p:'80%',c:'var(--accent)',t:'Strong'},{p:'100%',c:'var(--green)',t:'Very strong'}];
  const l=lvls[Math.max(0,s-1)]||lvls[0];
  fill.style.width=val?l.p:'0';fill.style.background=val?l.c:'';lbl.textContent=val?l.t:'';lbl.style.color=val?l.c:'';
}

(function(){const r=document.getElementById('tab-register');if(r&&!r.classList.contains('hidden'))goStep(1,false)})();

// ── LOCK SCREEN & IDLE FUNCTIONS ─────────────────────────
const NX_IDLE_MS = 4 * 60 * 1000;
const NX_COUNTDOWN = 30;
let nxIdleTimer = null, nxCountdownTimer = null, nxIsLocked = false;

function nxShowLock(){
  nxIsLocked = true;
  const el = document.getElementById('nx-lock-screen');
  if(!el) return;
  el.style.display = 'flex';
  el.style.alignItems = 'center';
  el.style.justifyContent = 'center';
  setTimeout(()=>document.getElementById('nx-lock-pw')?.focus(), 80);
}

function nxHideLock(){
  nxIsLocked = false;
  const el = document.getElementById('nx-lock-screen');
  if(el) el.style.display = 'none';
  nxResetIdle();
}

async function nxUnlock(){
  const pw = document.getElementById('nx-lock-pw')?.value;
  const errEl = document.getElementById('nx-lock-error');
  const btn = document.getElementById('nx-lock-btn');
  if(!pw){ if(errEl){errEl.textContent='Enter your password.';errEl.style.display='block';} return; }
  if(btn){ btn.disabled=true; btn.textContent='Verifying…'; }
  try{
    const fd = new FormData();
    fd.append('action','verify_lock');
    fd.append('password', pw);
    fd.append('_csrf', document.querySelector('input[name="_csrf"]')?.value || '');
    const res = await fetch('', { method:'POST', body:fd });
    const json = await res.json();
    if(json.ok){
      nxHideLock();
      if(errEl) errEl.style.display = 'none';
      document.getElementById('nx-lock-pw').value = '';
    } else {
      if(errEl){ errEl.textContent = 'Incorrect password. Try again.'; errEl.style.display='block'; }
    }
  } catch(e){
    if(errEl){ errEl.textContent = 'Error verifying. Try again.'; errEl.style.display='block'; }
  }
  if(btn){ btn.disabled=false; btn.textContent='🔓 Unlock'; }
}

function nxForceLogout(){
  const f = document.createElement('form');
  f.method = 'POST'; f.style.display = 'none';
  const a = document.createElement('input'); a.name = 'action'; a.value = 'logout';
  const c = document.createElement('input'); c.name = '_csrf';
  const csrfEl = document.querySelector('input[name="_csrf"]');
  c.value = csrfEl ? csrfEl.value : '';
  f.append(a); f.append(c);
  document.body.append(f); f.submit();
}

function nxShowIdleWarning(){
  if(nxIsLocked) return;
  const el = document.getElementById('nx-idle-overlay');
  if(!el) return;
  el.style.display = 'flex';
  el.style.alignItems = 'center';
  el.style.justifyContent = 'center';
  let s = NX_COUNTDOWN;
  const secEl = document.getElementById('nx-idle-seconds');
  const barEl = document.getElementById('nx-idle-bar');
  if(secEl) secEl.textContent = s;
  if(barEl) barEl.style.width = '100%';
  clearInterval(nxCountdownTimer);
  nxCountdownTimer = setInterval(()=>{
    s--;
    if(secEl) secEl.textContent = s;
    if(barEl) barEl.style.width = Math.max(0,(s/NX_COUNTDOWN)*100)+'%';
    if(s<=0){ clearInterval(nxCountdownTimer); nxHideIdleWarning(); nxShowLock(); }
  }, 1000);
}

function nxHideIdleWarning(){
  const el = document.getElementById('nx-idle-overlay');
  if(el) el.style.display = 'none';
  clearInterval(nxCountdownTimer);
}

function nxResetIdle(){
  if(nxIsLocked) return;
  nxHideIdleWarning();
  clearTimeout(nxIdleTimer);
  nxIdleTimer = setTimeout(nxShowIdleWarning, NX_IDLE_MS);
}

if(document.querySelector('.layout')){
  nxResetIdle();
  ['mousemove','mousedown','keydown','touchstart','scroll'].forEach(ev=>
    document.addEventListener(ev, nxResetIdle, {passive:true})
  );
}

// ── BACK BUTTON → always go to dashboard ─────────────────
(function(){
  if(document.querySelector('.layout')){
    history.pushState({nymix:true},'','');
    window.addEventListener('popstate',function(e){
      window.location.href='?page=dashboard';
    });
  }
})();
</script>
</script>
</body>
</html>

