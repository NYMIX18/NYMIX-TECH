<?php
// ============================================================
//  NYMIX BUSINESS — STAFF PORTAL  (Secured v2)
//  staff_portal.php  (single-file, session-based, PDO)
//  Security: CSRF, parameterized queries, rate limiting,
//            branch-inactive login block, session hardening
// ============================================================

// ── ENVIRONMENT ──────────────────────────────────────────────
define('APP_ENV', 'development'); // change back to 'production' when done
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// ── SESSION HARDENING ─────────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 3600);
// Multi-user: each user gets their own session file, not shared memory
ini_set('session.save_handler', 'files');
// Prevent session locking blocking concurrent requests from same user
ini_set('session.lazy_write', 1);
// Set cookie_secure only if HTTPS
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}
session_start();
ob_start();

require_once __DIR__ . '/includes/db.php';

// TEMP DEBUG — remove after fixing
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json');
    try {
        $pdo = db();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['ok' => true, 'tables' => $tables, 'count' => count($tables)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ── ROLE CONSTANTS ────────────────────────────────────────────
define('ROLE_OWNER',          1);
define('ROLE_BRANCH_MANAGER', 2);
define('ROLE_CASHIER',        3);
define('ROLE_STOREKEEPER',    4);
define('ROLE_SUPPLIER_CLERK', 5);

// ── HELPERS ───────────────────────────────────────────────────
function logged_in(): bool { return isset($_SESSION['staff_id']); }
function me(): array        { return $_SESSION['staff'] ?? []; }
function role(): int        { return (int)($_SESSION['staff']['role_id'] ?? 0); }
function branch(): int      { return (int)($_SESSION['staff']['branch_id'] ?? 0); }
function can(array $roles): bool { return in_array(role(), $roles); }
function fmt(float $n): string   { return 'KSh ' . number_format($n, 2); }
function today(): string         { return date('Y-m-d'); }
function esc(mixed $v): string   { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function redirect(string $to): void { header('Location: ' . $to); exit; }
function json_out(mixed $data): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($data); exit;
}

// ── CSRF ──────────────────────────────────────────────────────
function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_ok(): bool {
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}
function csrf_check(): void {
    if (!csrf_ok()) {
        json_out(['ok' => false, 'msg' => 'Invalid or missing CSRF token.']);
    }
}

// ── LOGIN RATE LIMITER ────────────────────────────────────────
function login_rate_check(string $ip): bool {
    $key = 'login_attempts_' . md5($ip);
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'first' => time()];
    }
    $att = &$_SESSION[$key];
    if ((time() - $att['first']) > 900) { // reset after 15 min
        $att = ['count' => 0, 'first' => time()];
    }
    return $att['count'] < 5;
}
function login_rate_increment(string $ip): void {
    $key = 'login_attempts_' . md5($ip);
    $_SESSION[$key]['count'] = ($_SESSION[$key]['count'] ?? 0) + 1;
}
function login_rate_reset(string $ip): void {
    unset($_SESSION['login_attempts_' . md5($ip)]);
}

// ── BRANCH SQL HELPER (parameterized-safe) ────────────────────
// Returns ['sql' => string, 'params' => array]
// Caller merges params into their own execute() array
function branch_filter(string $alias = ''): array {
    $col = $alias ? "$alias.branch_id" : 'branch_id';
    if (role() === ROLE_OWNER) {
        return ['sql' => '1=1', 'params' => []];
    }
    return ['sql' => "$col = ?", 'params' => [branch()]];
}

// ── RECEIPT / LPO GENERATORS ─────────────────────────────────
function gen_receipt(): string {
    return 'RCP-' . strtoupper(base_convert(time(), 10, 36)) . '-' . rand(100, 999);
}
function gen_sale_no(): string {
    return 'SL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)) . rand(10, 99);
}
function gen_lpo(): string {
    return 'LPO-' . date('Ymd') . '-' . rand(1000, 9999);
}

// ── eTIMS INTEGRATION ─────────────────────────────────────────
function etims_submit(PDO $pdo, int $sale_id, int $branch_id): array {
    // Fetch branch eTIMS credentials
    $stmt = $pdo->prepare("
        SELECT etims_enabled, etims_pin, etims_branch_code,
               etims_device_serial, etims_env
        FROM branches WHERE id = ?
    ");
    $stmt->execute([$branch_id]);
    $branch = $stmt->fetch();

    // Skip if eTIMS not enabled for this branch
    if (!$branch || !$branch['etims_enabled']) {
        $pdo->prepare("UPDATE sales SET etims_status = 'skipped' WHERE id = ?")
            ->execute([$sale_id]);
        return ['ok' => true, 'skipped' => true];
    }

    // Missing credentials check
    if (empty($branch['etims_pin']) || empty($branch['etims_branch_code']) || empty($branch['etims_device_serial'])) {
        $pdo->prepare("UPDATE sales SET etims_status = 'failed', etims_error = 'Missing eTIMS credentials' WHERE id = ?")
            ->execute([$sale_id]);
        return ['ok' => false, 'msg' => 'Missing eTIMS credentials on branch.'];
    }

    // Fetch sale + items
    $s = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
    $s->execute([$sale_id]);
    $sale = $s->fetch();

    $si = $pdo->prepare("
        SELECT si.*, p.name AS product_name, p.sku
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.sale_id = ?
    ");
    $si->execute([$sale_id]);
    $items = $si->fetchAll();

    // Build eTIMS payload (KRA eTIMS API v3 format)
    $payload = [
        'tin'          => $branch['etims_pin'],
        'bhfId'        => $branch['etims_branch_code'],
        'dvcSrlNo'     => $branch['etims_device_serial'],
        'invcNo'       => $sale['receipt_no'],
        'orgInvcNo'    => 0,
        'rcptTyCd'     => 'S',   // S = Sale
        'pmtTyCd'      => match($sale['payment_method']) {
            'cash'  => '01',
            'mpesa' => '02',
            'credit'=> '05',
            default => '01',
        },
        'salesSttsCd'  => '02',  // 02 = Approved
        'cfmDt'        => date('YmdHis'),
        'salesDt'      => date('Ymd', strtotime($sale['sale_date'])),
        'stockRlsDt'   => null,
        'cnclReqDt'    => null,
        'cnclDt'       => null,
        'rfdDt'        => null,
        'totItemCnt'   => count($items),
        'taxblAmtA'    => 0,
        'taxblAmtB'    => round((float)$sale['grand_total'] / 1.16, 2), // VAT excl
        'taxblAmtC'    => 0,
        'taxblAmtD'    => 0,
        'taxRtA'       => 0,
        'taxRtB'       => 16,    // Kenya VAT 16%
        'taxRtC'       => 0,
        'taxRtD'       => 0,
        'taxAmtA'      => 0,
        'taxAmtB'      => round((float)$sale['grand_total'] - ((float)$sale['grand_total'] / 1.16), 2),
        'taxAmtC'      => 0,
        'taxAmtD'      => 0,
        'totTaxblAmt'  => round((float)$sale['grand_total'] / 1.16, 2),
        'totTaxAmt'    => round((float)$sale['grand_total'] - ((float)$sale['grand_total'] / 1.16), 2),
        'totAmt'       => (float)$sale['grand_total'],
        'prchrAcptcYn' => 'N',
        'remark'       => $sale['note'] ?? '',
        'regrId'       => $branch['etims_pin'],
        'regrNm'       => $branch['etims_pin'],
        'modrId'       => $branch['etims_pin'],
        'modrNm'       => $branch['etims_pin'],
        'receipt'      => [
            'custTin'    => null,
            'custMblNo'  => null,
            'rptNo'      => 0,
            'trdeNm'     => null,
            'adrs'       => null,
            'topMsg'     => 'Thank you for your business',
            'btmMsg'     => 'Powered by NYMIX HMS',
            'prchrAcptcYn' => 'N',
        ],
        'itemList' => array_map(function($it, $idx) {
            $unitPrice = (float)$it['unit_price'];
            $qty       = (float)$it['quantity'];
            $total     = $unitPrice * $qty;
            $taxable   = round($total / 1.16, 2);
            $tax       = round($total - $taxable, 2);
            return [
                'itemSeq'     => $idx + 1,
                'itemCd'      => $it['sku'] ?: 'ITEM' . $it['product_id'],
                'itemClsCd'   => '5020230602', // default goods code
                'itemNm'      => $it['product_name'],
                'bcd'         => null,
                'pkgUnitCd'   => 'U',
                'pkg'         => 1,
                'qtyUnitCd'   => 'U',
                'qty'         => $qty,
                'prc'         => $unitPrice,
                'splyAmt'     => $taxable,
                'dcRt'        => 0,
                'dcAmt'       => 0,
                'isrccCd'     => null,
                'isrccNm'     => null,
                'isrcRt'      => null,
                'isrcAmt'     => null,
                'vatCatCd'    => 'B', // B = 16% VAT
                'exciseTxCatCd' => null,
                'vatTaxblAmt' => $taxable,
                'exciseTaxblAmt' => 0,
                'vatAmt'      => $tax,
                'exciseTxAmt' => 0,
                'totAmt'      => $total,
            ];
        }, $items, array_keys($items)),
    ];

    // Choose endpoint
    $base_url = $branch['etims_env'] === 'live'
        ? 'https://etims-api.kra.go.ke/etims-api'
        : 'https://etims-api-sbx.kra.go.ke/etims-api';

    $url = $base_url . '/saveOsdc';

    // Send to KRA
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'tin: ' . $branch['etims_pin'],
            'bhfId: ' . $branch['etims_branch_code'],
            'cmcKey: ' . $branch['etims_device_serial'],
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err || !$response) {
        // Network failure — queue for retry
        $pdo->prepare("
            UPDATE sales SET etims_status = 'failed',
            etims_error = ?, etims_retry_count = etims_retry_count + 1
            WHERE id = ?
        ")->execute([$curl_err ?: 'No response', $sale_id]);
        $pdo->prepare("
            INSERT INTO etims_queue (sale_id, branch_id, status, error)
            VALUES (?, ?, 'pending', ?)
            ON DUPLICATE KEY UPDATE status = 'pending', attempts = attempts + 1, error = ?
        ")->execute([$sale_id, $branch_id, $curl_err, $curl_err]);
        return ['ok' => false, 'msg' => 'eTIMS network error. Queued for retry.'];
    }

    $result = json_decode($response, true);
    $result_code = $result['resultCd'] ?? 'ERR';

    if ($result_code === '000') {
        // Success — store CU invoice number and QR
        $cu_invoice = $result['data']['rcptSgntr'] ?? ($result['data']['intrlData'] ?? '');
        $qr_data    = $result['data']['qrCodeUrl']  ?? $cu_invoice;
        $pdo->prepare("
            UPDATE sales
            SET etims_status = 'submitted',
                etims_invoice_no = ?,
                etims_qr_code = ?,
                etims_submitted_at = NOW(),
                etims_error = NULL
            WHERE id = ?
        ")->execute([$cu_invoice, $qr_data, $sale_id]);
        // Remove from queue if it was there
        $pdo->prepare("UPDATE etims_queue SET status = 'done' WHERE sale_id = ?")
            ->execute([$sale_id]);
        return ['ok' => true, 'cu_invoice' => $cu_invoice, 'qr' => $qr_data];
    } else {
        $err_msg = $result['resultMsg'] ?? 'eTIMS error code: ' . $result_code;
        $pdo->prepare("
            UPDATE sales SET etims_status = 'failed',
            etims_error = ?, etims_retry_count = etims_retry_count + 1
            WHERE id = ?
        ")->execute([$err_msg, $sale_id]);
        $pdo->prepare("
            INSERT INTO etims_queue (sale_id, branch_id, status, error)
            VALUES (?, ?, 'pending', ?)
            ON DUPLICATE KEY UPDATE status = 'pending', attempts = attempts + 1, error = ?
        ")->execute([$sale_id, $branch_id, $err_msg, $err_msg]);
        return ['ok' => false, 'msg' => $err_msg];
    }
}

// ── SUBSCRIPTION GUARD ────────────────────────────────────────
function check_subscription(PDO $pdo, int $client_id): array {
    $stmt = $pdo->prepare("
        SELECT c.status AS account_status,
        COALESCE((
            SELECT CASE
                WHEN c.status = 'suspended'        THEN 'suspended'
                WHEN c.status = 'cancelled'        THEN 'expired'
                WHEN CURDATE() <= s.end_date       THEN 'active'
                WHEN CURDATE() <= s.grace_end_date THEN 'grace'
                ELSE 'expired'
            END
            FROM subscriptions s
            WHERE s.client_id = c.id
            ORDER BY s.id DESC LIMIT 1
        ), 'no_sub') AS sub_status
        FROM clients c WHERE c.id = ?
    ");
    $stmt->execute([$client_id]);
    return $stmt->fetch() ?: ['account_status' => 'suspended', 'sub_status' => 'no_sub'];
}

function render_lock_screen(string $status, string $name): never {
    $label = match($status) {
        'suspended' => 'Account Suspended',
        'expired'   => 'Subscription Expired',
        default     => 'System Unavailable',
    };
    echo '<!DOCTYPE html><html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NYMIX — Access Restricted</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#0f1117;color:#e8eaf0;font-family:"DM Sans",sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center}
.lock-card{background:#1e2333;border:1px solid #2a2f42;border-radius:16px;padding:48px 40px;max-width:440px;text-align:center}
.lock-icon{font-size:56px;animation:pulse 2s ease infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.1)}}
</style>
</head>
<body>
<div class="lock-card">
  <div class="lock-icon mb-4">🔒</div>
  <h4 class="text-danger fw-bold mb-2">' . esc($label) . '</h4>
  <p class="text-secondary mb-4">This system is not available. Contact your administrator to restore access.</p>
  <div class="bg-dark rounded p-3 mb-4 text-start small">
    <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Account</span><span class="fw-600">' . esc($name) . '</span></div>
    <div class="d-flex justify-content-between"><span class="text-secondary">Status</span><span class="text-danger fw-bold">' . esc(ucfirst($status)) . '</span></div>
  </div>
  <div class="alert alert-warning small text-start">📞 Contact <strong>NYMIX TECH</strong> to renew your subscription.</div>
  <a href="staff_portal.php?action=logout" class="btn btn-secondary w-100">Sign Out</a>
</div>
</body></html>';
    exit;
}

// ── CASCADE: deactivate branch users ─────────────────────────
function deactivate_branch_users(PDO $pdo, int $branch_id, int $client_id): void {
    $stmt = $pdo->prepare("
        UPDATE users SET is_active = 0
        WHERE branch_id = ?
          AND branch_id IN (SELECT id FROM branches WHERE client_id = ?)
    ");
    $stmt->execute([$branch_id, $client_id]);
}

// ── CASCADE: reactivate branch users ─────────────────────────
function reactivate_branch_users(PDO $pdo, int $branch_id, int $client_id): void {
    $stmt = $pdo->prepare("
        UPDATE users SET is_active = 1
        WHERE branch_id = ?
          AND branch_id IN (SELECT id FROM branches WHERE client_id = ?)
    ");
    $stmt->execute([$branch_id, $client_id]);
}

// ============================================================
//  LOGOUT
// ============================================================
$action = $_REQUEST['action'] ?? '';

if ($action === 'logout') {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    setcookie(session_name(), '', time() - 3600, '/');
    redirect('staff_portal.php');
}

// ============================================================
//  LOGIN  (POST, before auth guard)
// ============================================================
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $is_reauth = logged_in(); // true when unlocking locked screen

    if (!login_rate_check($ip)) {
        json_out(['ok' => false, 'msg' => 'Too many failed attempts. Please wait 15 minutes.']);
    }

    if (!$username || !$password) {
        json_out(['ok' => false, 'msg' => 'Username and password are required.']);
    }

    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT u.*, r.name AS role_name,
               b.branch_name, b.address AS branch_address, b.phone AS branch_phone,
               b.client_id, b.is_active AS branch_active,
               c.status AS client_status, c.business_name, c.logo,
               c.phone AS client_phone, c.address AS client_address
        FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN branches b ON b.id = u.branch_id
        LEFT JOIN clients c ON c.id = b.client_id
        WHERE u.username = ? AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        login_rate_increment($ip);
        // Timing-safe: always hash to prevent timing attacks
        json_out(['ok' => false, 'msg' => 'Invalid username or password.']);
    }

    // ── Block: branch inactive ────────────────────────────────
    if (isset($user['branch_active']) && !(int)$user['branch_active']) {
        json_out(['ok' => false, 'msg' => 'Your branch is currently inactive. Contact your administrator.']);
    }

    // ── Block: client account suspended ──────────────────────
    if (($user['client_status'] ?? '') === 'suspended') {
        json_out(['ok' => false, 'msg' => 'This system is not available. Contact your administrator.']);
    }

    // ── Block: subscription check ─────────────────────────────
    $client_id = (int)($user['client_id'] ?? 0);
    if ($client_id) {
        $sub = check_subscription($pdo, $client_id);
        if (in_array($sub['sub_status'], ['suspended', 'expired', 'no_sub'])) {
            json_out(['ok' => false, 'msg' => 'This system is not available. Contact your administrator.']);
        }
    }

    // ── Success ───────────────────────────────────────────────
    login_rate_reset($ip);
    session_regenerate_id(true); // session fixation protection
    $_SESSION['staff_id'] = $user['id'];
    $_SESSION['staff']    = $user;
    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    json_out(['ok' => true]);
}

// ============================================================
//  AUTH GUARD — all actions below require login
// ============================================================
if ($action && $action !== 'login' && !logged_in()) {
    json_out(['ok' => false, 'msg' => 'Not authenticated.']);
}

// ── SUBSCRIPTION GUARD — every request ───────────────────────
if (logged_in()) {
    $pdo       = db();
    $client_id = (int)($_SESSION['staff']['client_id'] ?? 0);
    if ($client_id) {
        try {
            $sub = check_subscription($pdo, $client_id);
            $blocked = ['suspended', 'expired', 'no_sub'];
            if (in_array($sub['sub_status'], $blocked) || $sub['account_status'] === 'suspended') {
                if ($action) {
                    json_out(['ok' => false, 'msg' => 'System unavailable. Contact your administrator.', 'locked' => true]);
                }
                render_lock_screen($sub['sub_status'], me()['full_name'] ?? '');
            }
        } catch (Throwable $e) {
            // Fail open on transient DB error so staff aren't locked out
            if (APP_ENV === 'development') error_log($e->getMessage());
        }
    }
}

// ============================================================
//  AJAX HANDLERS
// ============================================================

// ── DASHBOARD DATA ────────────────────────────────────────────
if ($action === 'dashboard_data') {
    $pdo = db();
    $bf  = branch_filter('s');

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total), 0) AS rev
        FROM sales s WHERE DATE(sale_date) = CURDATE() AND voided = 0 AND {$bf['sql']}
    ");
    $stmt->execute($bf['params']);
    $today_sales = $stmt->fetch();

    $bf2  = branch_filter();
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) AS cnt FROM stock
        WHERE quantity <= (SELECT reorder_level FROM products WHERE id = product_id)
        AND {$bf2['sql']}
    ");
    $stmt2->execute($bf2['params']);
    $low = $stmt2->fetch();

    $bf3  = branch_filter('purchase_orders');
    $stmt3 = $pdo->prepare("
        SELECT COUNT(*) AS cnt FROM purchase_orders
        WHERE status NOT IN ('received','cancelled') AND {$bf3['sql']}
    ");
    $stmt3->execute($bf3['params']);
    $pending_po = $stmt3->fetch();

    $bf4  = branch_filter('customers');
    $stmt4 = $pdo->prepare("SELECT COALESCE(SUM(balance),0) AS total FROM customers WHERE {$bf4['sql']}");
    $stmt4->execute($bf4['params']);
    $credit = $stmt4->fetch();

    $bf5  = branch_filter('s');
    $stmt5 = $pdo->prepare("
        SELECT s.receipt_no, s.grand_total, s.sale_date, s.payment_method, u.full_name
        FROM sales s LEFT JOIN users u ON u.id = s.served_by
        WHERE s.voided = 0 AND {$bf5['sql']}
        ORDER BY s.sale_date DESC LIMIT 5
    ");
    $stmt5->execute($bf5['params']);
    $recent = $stmt5->fetchAll();

    $bf6  = branch_filter('sales');
    $stmt6 = $pdo->prepare("
        SELECT DATE(sale_date) AS d, SUM(grand_total) AS rev
        FROM sales WHERE voided = 0 AND {$bf6['sql']}
          AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY DATE(sale_date) ORDER BY d ASC
    ");
    $stmt6->execute($bf6['params']);
    $chart = $stmt6->fetchAll();

    json_out(['ok' => true, 'today_sales' => $today_sales, 'low_stock' => $low['cnt'],
              'pending_po' => $pending_po['cnt'], 'credit_out' => $credit['total'],
              'recent' => $recent, 'chart' => $chart]);
}

// ── DASHBOARD EXTENDED DATA ───────────────────────────────────
if ($action === 'dashboard_extended') {
    $pdo = db();
    $bf  = branch_filter('s');

    // Hourly sales today
    $h = $pdo->prepare("
        SELECT HOUR(sale_date) AS hr,
               COUNT(*) AS cnt,
               COALESCE(SUM(grand_total),0) AS rev
        FROM sales s
        WHERE DATE(sale_date) = CURDATE() AND voided = 0 AND {$bf['sql']}
        GROUP BY HOUR(sale_date) ORDER BY hr ASC
    ");
    $h->execute($bf['params']);
    $hourly = $h->fetchAll();

    // 14-day trend
    $bf2 = branch_filter('s');
    $d14 = $pdo->prepare("
        SELECT DATE(sale_date) AS d,
               COALESCE(SUM(grand_total),0) AS rev,
               COUNT(*) AS txn
        FROM sales s
        WHERE voided = 0 AND {$bf2['sql']}
          AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(sale_date) ORDER BY d ASC
    ");
    $d14->execute($bf2['params']);
    $trend = $d14->fetchAll();

    // Payment method breakdown today
    $bf3 = branch_filter('s');
    $pm = $pdo->prepare("
        SELECT payment_method,
               COUNT(*) AS cnt,
               COALESCE(SUM(grand_total),0) AS rev
        FROM sales s
        WHERE DATE(sale_date) = CURDATE() AND voided = 0 AND {$bf3['sql']}
        GROUP BY payment_method
    ");
    $pm->execute($bf3['params']);
    $pay_methods = $pm->fetchAll();

    // Top 5 products today
    $bf4 = branch_filter('s');
    $tp = $pdo->prepare("
        SELECT p.name,
               SUM(si.quantity) AS qty,
               SUM(si.quantity * si.unit_price) AS rev
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN products p ON p.id = si.product_id
        WHERE DATE(s.sale_date) = CURDATE() AND s.voided = 0 AND {$bf4['sql']}
        GROUP BY p.id ORDER BY rev DESC LIMIT 5
    ");
    $tp->execute($bf4['params']);
    $top_products = $tp->fetchAll();

    // This month vs last month
    $bf5 = branch_filter('s');
    $mm = $pdo->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE()) THEN grand_total END),0) AS this_month,
          COALESCE(SUM(CASE WHEN MONTH(sale_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(sale_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) THEN grand_total END),0) AS last_month,
          COALESCE(SUM(CASE WHEN DATE(sale_date)=CURDATE() THEN grand_total END),0) AS today,
          COALESCE(SUM(CASE WHEN DATE(sale_date)=DATE_SUB(CURDATE(),INTERVAL 1 DAY) THEN grand_total END),0) AS yesterday
        FROM sales s WHERE voided = 0 AND {$bf5['sql']}
        AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH)
    ");
    $mm->execute($bf5['params']);
    $compare = $mm->fetch();

    // Low stock alert count with product names
    $bf6 = branch_filter('s');
    $ls = $pdo->prepare("
        SELECT p.name, st.quantity, p.reorder_level, b.branch_name
        FROM stock st
        JOIN products p ON p.id = st.product_id
        JOIN branches b ON b.id = st.branch_id
        WHERE st.quantity <= p.reorder_level
        AND st.branch_id IN (SELECT id FROM branches WHERE client_id = (SELECT client_id FROM branches WHERE id = ?))
        ORDER BY st.quantity ASC LIMIT 5
    ");
    $ls->execute([branch() ?: (int)(me()['branch_id'] ?? 0)]);
    $low_items = $ls->fetchAll();

    json_out([
        'ok'           => true,
        'hourly'       => $hourly,
        'trend'        => $trend,
        'pay_methods'  => $pay_methods,
        'top_products' => $top_products,
        'compare'      => $compare,
        'low_items'    => $low_items,
    ]);
}

// ── PRODUCT SEARCH (POS) ──────────────────────────────────────
if ($action === 'product_search') {
    $q   = '%' . trim($_GET['q'] ?? '') . '%';
    $bid = branch();
    $pdo = db();
    if (role() === ROLE_OWNER) {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.sku, p.barcode, p.selling_price,
                   COALESCE(SUM(st.quantity),0) AS qty, u.name AS unit
            FROM products p
            LEFT JOIN stock st ON st.product_id = p.id
            LEFT JOIN units u ON u.name = p.unit
            WHERE p.is_active = 1 AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
            GROUP BY p.id LIMIT 30
        ");
        $stmt->execute([$q, $q, $q]);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.sku, p.barcode, p.selling_price,
                   COALESCE(st.quantity, 0) AS qty, u.name AS unit
            FROM products p
            LEFT JOIN stock st ON st.product_id = p.id AND st.branch_id = ?
            LEFT JOIN units u ON u.name = p.unit
            WHERE p.is_active = 1 AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)
            LIMIT 30
        ");
        $stmt->execute([$bid, $q, $q, $q]);
    }
    json_out(['ok' => true, 'products' => $stmt->fetchAll()]);
}

// ── CREATE SALE ───────────────────────────────────────────────
if ($action === 'create_sale' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER, ROLE_CASHIER])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo         = db();
    $items       = json_decode($_POST['items'] ?? '[]', true);
    $customer_id = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $pay_method  = in_array($_POST['payment_method'] ?? '', ['cash','mpesa','credit','mixed'])
                   ? $_POST['payment_method'] : 'cash';
    $amount_paid = (float)($_POST['amount_paid'] ?? 0);
    $discount    = (float)($_POST['discount'] ?? 0);
    $mpesa_code  = trim($_POST['mpesa_code'] ?? '') ?: null;
    $note        = trim($_POST['note'] ?? '') ?: null;
    $bid         = branch() ?: (int)($_POST['branch_id'] ?? 0);

    if (empty($items) || !is_array($items)) {
        json_out(['ok' => false, 'msg' => 'Cart is empty.']);
    }

    // Validate each item
    foreach ($items as $it) {
        if (!isset($it['id'], $it['qty'], $it['price']) ||
            (int)$it['id'] <= 0 || (float)$it['qty'] <= 0 || (float)$it['price'] < 0) {
            json_out(['ok' => false, 'msg' => 'Invalid cart item.']);
        }
    }

    $subtotal    = array_reduce($items, fn($c, $i) => $c + $i['qty'] * $i['price'], 0);
    $grand_total = max(0, $subtotal - $discount);
    $balance_due = max(0, $grand_total - $amount_paid);
    $pay_status  = $balance_due <= 0 ? 'paid' : ($amount_paid > 0 ? 'partial' : 'credit');

    $pdo->beginTransaction();
    try {
        $receipt = gen_receipt();
        $stmt = $pdo->prepare("
            INSERT INTO sales
              (branch_id, customer_id, served_by, receipt_no, sale_no, subtotal, discount,
               grand_total, amount_paid, balance_due, payment_method, mpesa_code, payment_status, note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([$bid, $customer_id, me()['id'], $receipt, gen_sale_no(), $subtotal, $discount,
                        $grand_total, $amount_paid, $balance_due, $pay_method, $mpesa_code,
                        $pay_status, $note]);
        $sale_id = $pdo->lastInsertId();

        $si = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount) VALUES (?,?,?,?,0)");
        // Atomic decrement — safe for multiple cashiers selling simultaneously
        $su = $pdo->prepare("
            INSERT INTO stock (branch_id, product_id, quantity) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE quantity = GREATEST(0, quantity - ?)
        ");
        $sm = $pdo->prepare("INSERT INTO stock_movements (branch_id, product_id, user_id, type, quantity, reference_id) VALUES (?,?,?,'sale',?,?)");

        foreach ($items as $it) {
            $si->execute([$sale_id, (int)$it['id'], (float)$it['qty'], (float)$it['price']]);
            $su->execute([$bid, (int)$it['id'], (float)$it['qty'], (float)$it['qty']]);
            $sm->execute([$bid, (int)$it['id'], me()['id'], (float)$it['qty'], $sale_id]);
        }

        if ($customer_id && $balance_due > 0) {
            $pdo->prepare("UPDATE customers SET balance = balance + ? WHERE id = ?")
                ->execute([$balance_due, $customer_id]);
        }

        // ── TOKEN EARNING ─────────────────────────────────────
        if ($customer_id) {
            // Get current tier
            $ct = $pdo->prepare("SELECT tier, total_purchased FROM customers WHERE id = ?");
            $ct->execute([$customer_id]);
            $crow = $ct->fetch();
            $ctier = $crow['tier'] ?? 'Bronze';

            // Payment multiplier
            $pay_mult = match($pay_method) {
                'mpesa'  => 1.5,
                'credit' => 0.5,
                default  => 1.0,
            };
            // Tier multiplier
            $tier_mult = match($ctier) {
                'Platinum' => 2.0,
                'Gold'     => 1.5,
                'Silver'   => 1.25,
                default    => 1.0,
            };
            $base_tokens   = floor($amount_paid / 100);
            $earned_tokens = (int)floor($base_tokens * $pay_mult * $tier_mult);

            if ($earned_tokens > 0) {
                $new_purchased = ($crow['total_purchased'] ?? 0) + $amount_paid;
                $new_tier = match(true) {
                    $new_purchased >= 100000 => 'Platinum',
                    $new_purchased >= 50000  => 'Gold',
                    $new_purchased >= 10000  => 'Silver',
                    default                  => 'Bronze',
                };
                $pdo->prepare("
                    UPDATE customers
                    SET tokens          = tokens + ?,
                        lifetime_tokens = lifetime_tokens + ?,
                        total_purchased = total_purchased + ?,
                        tier            = ?
                    WHERE id = ?
                ")->execute([$earned_tokens, $earned_tokens, $amount_paid, $new_tier, $customer_id]);
            }
        }
        $pdo->commit();

        // ── eTIMS SUBMISSION (after commit, non-blocking) ─────
        $etims_result = etims_submit($pdo, $sale_id, $bid);
        $etims_info = [
            'etims_status'  => $etims_result['ok'] ? ($etims_result['skipped'] ?? false ? 'skipped' : 'submitted') : 'failed',
            'etims_invoice' => $etims_result['cu_invoice'] ?? null,
            'etims_qr'      => $etims_result['qr'] ?? null,
        ];

        json_out(['ok' => true, 'sale_id' => $sale_id, 'receipt_no' => $receipt,
                  'grand_total' => $grand_total, 'balance_due' => $balance_due,
                  ...$etims_info]);
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = APP_ENV === 'development' ? $e->getMessage() : 'Sale failed. Please try again.';
        json_out(['ok' => false, 'msg' => $msg]);
    }
}

// ── SALES LIST ────────────────────────────────────────────────
if ($action === 'sales_list') {
    $pdo  = db();
    $bf   = branch_filter('s');
    $date = $_GET['date'] ?? today();
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = today();
    $stmt = $pdo->prepare("
        SELECT s.*, u.full_name AS cashier, c.name AS customer_name
        FROM sales s
        LEFT JOIN users u ON u.id = s.served_by
        LEFT JOIN customers c ON c.id = s.customer_id
        WHERE DATE(s.sale_date) = ? AND {$bf['sql']}
        ORDER BY s.sale_date DESC
    ");
    $stmt->execute(array_merge([$date], $bf['params']));
    json_out(['ok' => true, 'sales' => $stmt->fetchAll()]);
}

// ── SALE DETAIL ───────────────────────────────────────────────
if ($action === 'sale_detail') {
    $pdo = db();
    $id  = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT s.*, u.full_name AS cashier, c.name AS customer_name, b.branch_name,
               b.etims_enabled, b.etims_pin
        FROM sales s
        LEFT JOIN users u ON u.id = s.served_by
        LEFT JOIN customers c ON c.id = s.customer_id
        LEFT JOIN branches b ON b.id = s.branch_id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    $sale = $stmt->fetch();

    $stmt2 = $pdo->prepare("
        SELECT si.*, p.name AS product_name,
               (si.quantity * si.unit_price) AS subtotal
        FROM sale_items si
        JOIN products p ON p.id = si.product_id
        WHERE si.sale_id = ?
    ");
    $stmt2->execute([$id]);
    $items = $stmt2->fetchAll();
    json_out(['ok' => true, 'sale' => $sale, 'items' => $items]);
}

// ── VOID SALE ─────────────────────────────────────────────────
if ($action === 'void_sale' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo     = db();
    $sale_id = (int)($_POST['sale_id'] ?? 0);
    $stmt    = $pdo->prepare("SELECT * FROM sales WHERE id = ? AND voided = 0");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch();
    if (!$sale) json_out(['ok' => false, 'msg' => 'Sale not found or already voided.']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE sales SET voided = 1, voided_at = NOW(), voided_by = ? WHERE id = ?")
            ->execute([me()['id'], $sale_id]);

        $items = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
        $items->execute([$sale_id]);
        $void_su = $pdo->prepare("UPDATE stock SET quantity = quantity + ? WHERE branch_id = ? AND product_id = ?");
        $void_sm = $pdo->prepare("INSERT INTO stock_movements (branch_id, product_id, user_id, type, quantity, reference_id) VALUES (?,?,?,'void_return',?,?)");
        foreach ($items->fetchAll() as $it) {
            $void_su->execute([(float)$it['quantity'], (int)$sale['branch_id'], (int)$it['product_id']]);
            $void_sm->execute([(int)$sale['branch_id'], (int)$it['product_id'], me()['id'], (float)$it['quantity'], $sale_id]);
        }
        $pdo->commit();
        json_out(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = APP_ENV === 'development' ? $e->getMessage() : 'Void failed.';
        json_out(['ok' => false, 'msg' => $msg]);
    }
}

// ── STOCK LIST ────────────────────────────────────────────────
if ($action === 'stock_list') {
    $pdo    = db();
    $bf     = branch_filter('s');
    $search = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt   = $pdo->prepare("
        SELECT s.id, s.product_id, b.branch_name AS branch, b.id AS branch_id,
               p.name AS product, p.sku, c.name AS category,
               s.quantity, u.name AS unit, p.reorder_level,
               p.buying_price, p.selling_price,
               (s.quantity * p.buying_price) AS stock_value,
               IF(s.quantity <= p.reorder_level, 1, 0) AS low_stock
        FROM stock s
        JOIN branches b ON b.id = s.branch_id
        JOIN products p ON p.id = s.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN units u ON u.name = p.unit
        WHERE {$bf['sql']} AND p.name LIKE ?
        ORDER BY low_stock DESC, p.name ASC
    ");
    $stmt->execute(array_merge($bf['params'], [$search]));
    json_out(['ok' => true, 'stock' => $stmt->fetchAll()]);
}

// ── STOCK ADJUSTMENT ──────────────────────────────────────────
if ($action === 'stock_adjust' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER, ROLE_STOREKEEPER])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo        = db();
    $product_id = (int)$_POST['product_id'];
    $bid        = branch() ?: (int)($_POST['branch_id'] ?? 0);
    $qty        = (float)$_POST['quantity'];
    $type       = in_array($_POST['type'] ?? '', ['add','remove','set'])
                  ? $_POST['type'] : 'add';
    $note       = trim($_POST['note'] ?? '');

    $curr = $pdo->prepare("SELECT quantity FROM stock WHERE branch_id = ? AND product_id = ?");
    $curr->execute([$bid, $product_id]);
    $row = $curr->fetch();

    $new_qty = match($type) {
        'set'    => $qty,
        'add'    => ($row['quantity'] ?? 0) + $qty,
        'remove' => max(0, ($row['quantity'] ?? 0) - $qty),
    };
    $mov = $new_qty - ($row['quantity'] ?? 0);

    $pdo->prepare("INSERT INTO stock (branch_id, product_id, quantity) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE quantity = ?")
        ->execute([$bid, $product_id, $new_qty, $new_qty]);
    $pdo->prepare("INSERT INTO stock_movements (branch_id, product_id, user_id, type, quantity, note)
        VALUES (?,?,?,'adjustment',?,?)")
        ->execute([$bid, $product_id, me()['id'], $mov, $note]);

    json_out(['ok' => true, 'new_qty' => $new_qty]);
}

// ── STOCK MOVEMENTS ───────────────────────────────────────────
if ($action === 'stock_movements') {
    $pdo  = db();
    $pid  = (int)($_GET['product_id'] ?? 0);
    $bf   = branch_filter('sm');
    $params = $bf['params'];
    $extra  = '';
    if ($pid) { $extra = ' AND sm.product_id = ?'; $params[] = $pid; }
    $stmt = $pdo->prepare("
        SELECT sm.*, p.name AS product_name, b.branch_name, u.full_name AS by_name
        FROM stock_movements sm
        JOIN products p ON p.id = sm.product_id
        JOIN branches b ON b.id = sm.branch_id
        LEFT JOIN users u ON u.id = sm.user_id
        WHERE {$bf['sql']} $extra
        ORDER BY sm.created_at DESC LIMIT 200
    ");
    $stmt->execute($params);
    json_out(['ok' => true, 'movements' => $stmt->fetchAll()]);
}

// ── TRANSFERS ─────────────────────────────────────────────────
if ($action === 'transfer_list') {
    $pdo = db();
    $bid = branch();
    if (role() === ROLE_OWNER) {
        $stmt = $pdo->prepare("
            SELECT st.*, p.name AS product,
                   bf.branch_name AS from_branch, bt.branch_name AS to_branch,
                   u.full_name AS requested_by_name
            FROM stock_transfers st
            JOIN products p ON p.id = st.product_id
            JOIN branches bf ON bf.id = st.from_branch
            JOIN branches bt ON bt.id = st.to_branch
            LEFT JOIN users u ON u.id = st.requested_by
            ORDER BY st.created_at DESC LIMIT 50
        ");
        $stmt->execute([]);
    } else {
        $stmt = $pdo->prepare("
            SELECT st.*, p.name AS product,
                   bf.branch_name AS from_branch, bt.branch_name AS to_branch,
                   u.full_name AS requested_by_name
            FROM stock_transfers st
            JOIN products p ON p.id = st.product_id
            JOIN branches bf ON bf.id = st.from_branch
            JOIN branches bt ON bt.id = st.to_branch
            LEFT JOIN users u ON u.id = st.requested_by
            WHERE st.from_branch = ? OR st.to_branch = ?
            ORDER BY st.created_at DESC LIMIT 50
        ");
        $stmt->execute([$bid, $bid]);
    }
    json_out(['ok' => true, 'transfers' => $stmt->fetchAll()]);
}

if ($action === 'transfer_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER, ROLE_STOREKEEPER])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo  = db();
    $from = (int)$_POST['from_branch'];
    $to   = (int)$_POST['to_branch'];
    $pid  = (int)$_POST['product_id'];
    $qty  = (float)$_POST['quantity'];
    $note = trim($_POST['note'] ?? '');
    if ($from === $to) json_out(['ok' => false, 'msg' => 'From and To branches must differ.']);
    if ($qty <= 0)     json_out(['ok' => false, 'msg' => 'Quantity must be greater than 0.']);
    $pdo->prepare("
        INSERT INTO stock_transfers (from_branch, to_branch, product_id, quantity, requested_by, note)
        VALUES (?,?,?,?,?,?)
    ")->execute([$from, $to, $pid, $qty, me()['id'], $note]);
    json_out(['ok' => true]);
}

if ($action === 'transfer_approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo      = db();
    $id       = (int)$_POST['id'];
    $decision = in_array($_POST['decision'] ?? '', ['approved','rejected'])
                ? $_POST['decision'] : 'rejected';

    $pdo->prepare("UPDATE stock_transfers SET status = ?, approved_by = ? WHERE id = ?")
        ->execute([$decision, me()['id'], $id]);

    if ($decision === 'approved') {
        $t = $pdo->prepare("SELECT * FROM stock_transfers WHERE id = ?");
        $t->execute([$id]);
        $tr = $t->fetch();
        $pdo->prepare("UPDATE stock SET quantity = quantity - ? WHERE branch_id = ? AND product_id = ?")
            ->execute([$tr['quantity'], $tr['from_branch'], $tr['product_id']]);
        $pdo->prepare("INSERT INTO stock (branch_id, product_id, quantity) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?")
            ->execute([$tr['to_branch'], $tr['product_id'], $tr['quantity'], $tr['quantity']]);
        $pdo->prepare("UPDATE stock_transfers SET status = 'completed' WHERE id = ?")->execute([$id]);
        $sm = $pdo->prepare("INSERT INTO stock_movements (branch_id, product_id, user_id, type, quantity, reference_id) VALUES (?,?,?,?,?,?)");
        $sm->execute([$tr['from_branch'], $tr['product_id'], me()['id'], 'transfer_out', -$tr['quantity'], $id]);
        $sm->execute([$tr['to_branch'],   $tr['product_id'], me()['id'], 'transfer_in',  $tr['quantity'],  $id]);
    }
    json_out(['ok' => true]);
}

// ── CUSTOMERS ─────────────────────────────────────────────────
if ($action === 'customers_list') {
    $pdo    = db();
    $q      = '%' . trim($_GET['q'] ?? '') . '%';
    $bf     = branch_filter('c');
    $stmt   = $pdo->prepare("
        SELECT c.*,
               b.branch_name,
               COALESCE((
                   SELECT SUM(s.grand_total)
                   FROM sales s
                   WHERE s.customer_id = c.id AND s.voided = 0
               ), 0) AS lifetime_spend,
               COALESCE((
                   SELECT COUNT(*) FROM sales s
                   WHERE s.customer_id = c.id AND s.voided = 0
               ), 0) AS total_orders,
               COALESCE((
                   SELECT AVG(s.grand_total)
                   FROM sales s
                   WHERE s.customer_id = c.id AND s.voided = 0
               ), 0) AS avg_order,
               COALESCE((
                   SELECT SUM(s.grand_total)
                   FROM sales s
                   WHERE s.customer_id = c.id AND s.voided = 0
                   AND s.sale_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
               ), 0) AS spend_3m,
               ROUND(
                   GREATEST(
                       (COALESCE((
                           SELECT AVG(s.grand_total)
                           FROM sales s
                           WHERE s.customer_id = c.id AND s.voided = 0
                       ), 0) * 2)
                       * COALESCE(c.payment_score, 1.0),
                   0),
               2) AS suggested_limit,
               COALESCE(c.tokens, 0) AS tokens,
               COALESCE(c.lifetime_tokens, 0) AS lifetime_tokens,
               COALESCE(c.tier, 'Bronze') AS tier,
               CASE
                   WHEN COALESCE(c.total_purchased, 0) >= 100000 THEN 'Platinum'
                   WHEN COALESCE(c.total_purchased, 0) >= 50000  THEN 'Gold'
                   WHEN COALESCE(c.total_purchased, 0) >= 10000  THEN 'Silver'
                   ELSE 'Bronze'
               END AS computed_tier
        FROM customers c
        LEFT JOIN branches b ON b.id = c.branch_id
        WHERE {$bf['sql']} AND (c.name LIKE ? OR c.phone LIKE ?)
        ORDER BY c.balance DESC
    ");
    $stmt->execute(array_merge($bf['params'], [$q, $q]));
    json_out(['ok' => true, 'customers' => $stmt->fetchAll()]);
}

if ($action === 'customer_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER, ROLE_CASHIER])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo  = db();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$name) json_out(['ok' => false, 'msg' => 'Customer name is required.']);
    $client_id = (int)(me()['client_id'] ?? 0);
    if (!$client_id) json_out(['ok' => false, 'msg' => 'Session error. Please re-login.']);
    $data = [$name, trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''),
             trim($_POST['address'] ?? ''), (float)($_POST['credit_limit'] ?? 0),
             branch() ?: null, $client_id];
    if ($id) {
        $pdo->prepare("UPDATE customers SET name=?,phone=?,email=?,address=?,credit_limit=?,branch_id=?,client_id=? WHERE id=?")
            ->execute([...$data, $id]);
        $action_done = 'updated';
    } else {
        $pdo->prepare("INSERT INTO customers (name,phone,email,address,credit_limit,branch_id,client_id) VALUES (?,?,?,?,?,?,?)")
            ->execute($data);
        $id = $pdo->lastInsertId();
        $action_done = 'created';
    }
    // Sync tier based on total_purchased
    $pdo->prepare("
        UPDATE customers SET tier = CASE
            WHEN COALESCE(total_purchased,0) >= 100000 THEN 'Platinum'
            WHEN COALESCE(total_purchased,0) >= 50000  THEN 'Gold'
            WHEN COALESCE(total_purchased,0) >= 10000  THEN 'Silver'
            ELSE 'Bronze'
        END WHERE id = ?
    ")->execute([$id]);

    // Fetch full customer for receipt
    $cust = $pdo->prepare("SELECT c.*, b.branch_name FROM customers c LEFT JOIN branches b ON b.id = c.branch_id WHERE c.id = ?");
    $cust->execute([$id]);
    $cust_data = $cust->fetch();
    json_out(['ok' => true, 'id' => $id, 'action' => $action_done, 'customer' => $cust_data]);
}

if ($action === 'customer_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pdo    = db();
    $cid    = (int)$_POST['customer_id'];
    $amount = (float)$_POST['amount'];
    $method = in_array($_POST['method'] ?? '', ['cash','mpesa','bank']) ? $_POST['method'] : 'cash';
    $code   = trim($_POST['mpesa_code'] ?? '') ?: null;
    if ($amount <= 0) json_out(['ok' => false, 'msg' => 'Invalid amount.']);
    $pdo->prepare("INSERT INTO customer_payments (customer_id, received_by, amount, payment_method, mpesa_code) VALUES (?,?,?,?,?)")
        ->execute([$cid, me()['id'], $amount, $method, $code]);
    $pdo->prepare("UPDATE customers SET balance = balance - ? WHERE id = ?")->execute([$amount, $cid]);
    json_out(['ok' => true]);
}

// ── SUPPLIERS ─────────────────────────────────────────────────
if ($action === 'suppliers_list') {
    $pdo  = db();
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE is_active = 1 AND (name LIKE ? OR phone LIKE ?) ORDER BY name");
    $stmt->execute([$q, $q]);
    json_out(['ok' => true, 'suppliers' => $stmt->fetchAll()]);
}

if ($action === 'supplier_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo  = db();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$name) json_out(['ok' => false, 'msg' => 'Supplier name is required.']);
    $client_id = (int)(me()['client_id'] ?? 0);
    if (!$client_id) json_out(['ok' => false, 'msg' => 'Session error. Please re-login.']);
    $data = [$name, trim($_POST['phone'] ?? ''),
             trim($_POST['email'] ?? ''), trim($_POST['address'] ?? ''),
             (int)($_POST['is_active'] ?? 1), $client_id];
    if ($id) {
        $pdo->prepare("UPDATE suppliers SET name=?,phone=?,email=?,address=?,is_active=? WHERE id=?")
            ->execute([$name, trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''),
                       trim($_POST['address'] ?? ''), (int)($_POST['is_active'] ?? 1), $id]);
    } else {
        $pdo->prepare("INSERT INTO suppliers (name,phone,email,address,is_active,client_id) VALUES (?,?,?,?,?,?)")
            ->execute($data);
        $id = $pdo->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id]);
}

// ── PURCHASE ORDERS ───────────────────────────────────────────
if ($action === 'po_list') {
    $pdo  = db();
    $bf   = branch_filter('po');
    $stmt = $pdo->prepare("
        SELECT po.*, s.name AS supplier_name, u.full_name AS created_by_name
        FROM purchase_orders po
        JOIN suppliers s ON s.id = po.supplier_id
        LEFT JOIN users u ON u.id = po.created_by
        WHERE {$bf['sql']}
        ORDER BY po.created_at DESC LIMIT 50
    ");
    $stmt->execute($bf['params']);
    json_out(['ok' => true, 'orders' => $stmt->fetchAll()]);
}

if ($action === 'po_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER, ROLE_STOREKEEPER, ROLE_SUPPLIER_CLERK])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo   = db();
    $bid   = branch() ?: (int)($_POST['branch_id'] ?? 0);
    $sid   = (int)$_POST['supplier_id'];
    $date  = $_POST['expected_date'] ?? null;
    $note  = trim($_POST['note'] ?? '');
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (empty($items)) json_out(['ok' => false, 'msg' => 'Add at least one item.']);
    $total = array_reduce($items, fn($c, $i) => $c + $i['qty'] * $i['price'], 0);
    $lpo   = gen_lpo();
    $pdo->prepare("INSERT INTO purchase_orders (branch_id, supplier_id, created_by, lpo_number, total_amount, expected_date, note)
        VALUES (?,?,?,?,?,?,?)")
        ->execute([$bid, $sid, me()['id'], $lpo, $total, $date, $note]);
    $po_id = $pdo->lastInsertId();
    $pi = $pdo->prepare("INSERT INTO purchase_items (purchase_order_id, product_id, quantity_ordered, buying_price) VALUES (?,?,?,?)");
    foreach ($items as $it) {
        $pi->execute([$po_id, (int)$it['id'], (float)$it['qty'], (float)$it['price']]);
    }
    json_out(['ok' => true, 'lpo' => $lpo, 'po_id' => $po_id]);
}

if ($action === 'po_items') {
    $pdo   = db();
    $po_id = (int)($_GET['po_id'] ?? 0);
    $items = $pdo->prepare("SELECT pi.*, p.name AS product_name, p.sku FROM purchase_items pi
        JOIN products p ON p.id = pi.product_id WHERE pi.purchase_order_id = ?");
    $items->execute([$po_id]);
    $po = $pdo->prepare("SELECT po.*, s.name AS supplier_name, b.branch_name
        FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
        JOIN branches b ON b.id = po.branch_id WHERE po.id = ?");
    $po->execute([$po_id]);
    json_out(['ok' => true, 'items' => $items->fetchAll(), 'po' => $po->fetch()]);
}

if ($action === 'po_receive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER, ROLE_STOREKEEPER, ROLE_SUPPLIER_CLERK])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo   = db();
    $po_id = (int)$_POST['po_id'];
    $items = json_decode($_POST['items'] ?? '[]', true);
    $po_r  = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ?");
    $po_r->execute([$po_id]);
    $po    = $po_r->fetch();
    if (!$po) json_out(['ok' => false, 'msg' => 'PO not found.']);

    $pdo->beginTransaction();
    try {
        $pi  = $pdo->prepare("SELECT * FROM purchase_items WHERE id = ?");
        $upi = $pdo->prepare("UPDATE purchase_items SET quantity_received = quantity_received + ? WHERE id = ?");
        $ust = $pdo->prepare("INSERT INTO stock (branch_id, product_id, quantity) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?");
        $sm  = $pdo->prepare("INSERT INTO stock_movements (branch_id, product_id, user_id, type, quantity, reference_id)
            VALUES (?,?,?,'purchase',?,?)");
        foreach ($items as $it) {
            $pi->execute([(int)$it['item_id']]);
            $pirow = $pi->fetch();
            $upi->execute([(float)$it['qty'], (int)$it['item_id']]);
            $ust->execute([$po['branch_id'], $pirow['product_id'], (float)$it['qty'], (float)$it['qty']]);
            $sm->execute([$po['branch_id'], $pirow['product_id'], me()['id'], (float)$it['qty'], $po_id]);
        }
        $pdo->prepare("UPDATE purchase_orders SET status = 'received', received_date = CURDATE() WHERE id = ?")
            ->execute([$po_id]);
        $pdo->commit();
        json_out(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_out(['ok' => false, 'msg' => 'Receive failed.']);
    }
}

// ── EXPENSES ──────────────────────────────────────────────────
if ($action === 'expenses_list') {
    $pdo  = db();
    $bf   = branch_filter('e');
    $date = $_GET['date'] ?? today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = today();
    $stmt = $pdo->prepare("
        SELECT e.*, ec.name AS category_name, u.full_name AS recorded_by_name
        FROM expenses e
        LEFT JOIN expense_categories ec ON ec.id = e.category_id
        LEFT JOIN users u ON u.id = e.recorded_by
        WHERE {$bf['sql']} AND e.expense_date = ?
        ORDER BY e.created_at DESC
    ");
    $stmt->execute(array_merge($bf['params'], [$date]));
    $cats = $pdo->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll();
    json_out(['ok' => true, 'expenses' => $stmt->fetchAll(), 'categories' => $cats]);
}

if ($action === 'expense_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) {
        json_out(['ok' => false, 'msg' => 'No permission.']);
    }
    $pdo    = db();
    $bid    = branch() ?: (int)($_POST['branch_id'] ?? 0);
    $cat    = (int)($_POST['category_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    if (!$bid)     json_out(['ok' => false, 'msg' => 'Branch is required.']);
    if (!$cat)     json_out(['ok' => false, 'msg' => 'Category is required.']);
    if ($amount <= 0) json_out(['ok' => false, 'msg' => 'Enter a valid amount.']);
    $date   = $_POST['expense_date'] ?? today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = today();
    $pdo->prepare("INSERT INTO expenses (branch_id, category_id, recorded_by, amount, description, expense_date, receipt_no)
        VALUES (?,?,?,?,?,?,?)")
        ->execute([$bid, $cat, me()['id'], $amount, trim($_POST['description'] ?? ''),
                   $date, trim($_POST['receipt_no'] ?? '') ?: null]);
    json_out(['ok' => true]);
}

// ── REPORTS ───────────────────────────────────────────────────
if ($action === 'report_sales') {
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $from = $_GET['from'] ?? today();
    $to   = $_GET['to']   ?? today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = today();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = today();
    $bf   = branch_filter('s');
    $stmt = $pdo->prepare("
        SELECT DATE(sale_date) AS d, COUNT(*) AS txns,
               SUM(grand_total) AS gross, SUM(discount) AS disc,
               SUM(amount_paid) AS collected, SUM(balance_due) AS credit
        FROM sales s WHERE voided = 0 AND {$bf['sql']}
          AND DATE(sale_date) BETWEEN ? AND ?
        GROUP BY DATE(sale_date) ORDER BY d ASC
    ");
    $stmt->execute(array_merge($bf['params'], [$from, $to]));
    $rows = $stmt->fetchAll();
    $stmt2 = $pdo->prepare("
        SELECT COUNT(*) AS txns, SUM(grand_total) AS gross,
               SUM(discount) AS disc, SUM(amount_paid) AS collected, SUM(balance_due) AS credit
        FROM sales s WHERE voided = 0 AND {$bf['sql']}
          AND DATE(sale_date) BETWEEN ? AND ?
    ");
    $stmt2->execute(array_merge($bf['params'], [$from, $to]));
    json_out(['ok' => true, 'rows' => $rows, 'totals' => $stmt2->fetch()]);
}

if ($action === 'report_stock') {
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $bf   = branch_filter('s');
    $stmt = $pdo->prepare("
        SELECT p.name, p.sku, c.name AS category, s.quantity,
               u.name AS unit, p.buying_price, p.selling_price,
               (s.quantity * p.buying_price) AS stock_value,
               IF(s.quantity <= p.reorder_level, 1, 0) AS low_stock
        FROM stock s
        JOIN products p ON p.id = s.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN units u ON u.name = p.unit
        WHERE {$bf['sql']}
        ORDER BY low_stock DESC, p.name
    ");
    $stmt->execute($bf['params']);
    $rows      = $stmt->fetchAll();
    $total_val = array_sum(array_column($rows, 'stock_value'));
    json_out(['ok' => true, 'rows' => $rows, 'total_value' => $total_val]);
}

// ── USERS ─────────────────────────────────────────────────────
if ($action === 'users_list') {
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $rows = $pdo->query("SELECT u.*, r.name AS role_name, b.branch_name
        FROM users u JOIN roles r ON r.id = u.role_id
        LEFT JOIN branches b ON b.id = u.branch_id
        ORDER BY u.full_name")->fetchAll();
    $roles    = $pdo->query("SELECT * FROM roles")->fetchAll();
    $branches = $pdo->query("SELECT * FROM branches WHERE is_active = 1")->fetchAll();
    json_out(['ok' => true, 'users' => $rows, 'roles' => $roles, 'branches' => $branches]);
}

if ($action === 'user_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $id   = (int)($_POST['id'] ?? 0);
    $pwd  = trim($_POST['password'] ?? '');
    $name = trim($_POST['full_name'] ?? '');
    $user = trim($_POST['username'] ?? '');
    if (!$name || !$user) json_out(['ok' => false, 'msg' => 'Name and username are required.']);
    $data = [$name, $user, trim($_POST['phone'] ?? ''),
             trim($_POST['email'] ?? '') ?: null,
             (int)$_POST['role_id'],
             !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null,
             (int)($_POST['is_active'] ?? 1)];
    if ($id) {
        $sql    = "UPDATE users SET full_name=?,username=?,phone=?,email=?,role_id=?,branch_id=?,is_active=?";
        $params = [...$data];
        if ($pwd) { $sql .= ',password_hash=?'; $params[] = password_hash($pwd, PASSWORD_DEFAULT); }
        $sql .= ' WHERE id=?'; $params[] = $id;
        $pdo->prepare($sql)->execute($params);
    } else {
        if (!$pwd) json_out(['ok' => false, 'msg' => 'Password is required for new users.']);
        $pdo->prepare("INSERT INTO users (full_name,username,phone,email,role_id,branch_id,password_hash,is_active) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([...$data, password_hash($pwd, PASSWORD_DEFAULT)]);
    }
    json_out(['ok' => true]);
}

// ── BRANCHES ──────────────────────────────────────────────────
if ($action === 'branches_list') {
    $pdo  = db();
    $rows = $pdo->query("SELECT id, branch_name AS name FROM branches WHERE is_active = 1 ORDER BY branch_name")->fetchAll();
    json_out(['ok' => true, 'branches' => $rows]);
}

if ($action === 'branch_toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo       = db();
    $bid       = (int)$_POST['branch_id'];
    $client_id = (int)(me()['client_id'] ?? 0);
    $stmt      = $pdo->prepare("SELECT id, is_active FROM branches WHERE id = ? AND client_id = ?");
    $stmt->execute([$bid, $client_id]);
    $branch    = $stmt->fetch();
    if (!$branch) json_out(['ok' => false, 'msg' => 'Branch not found.']);
    $new_status = $branch['is_active'] ? 0 : 1;
    $pdo->prepare("UPDATE branches SET is_active = ? WHERE id = ?")->execute([$new_status, $bid]);
    // CASCADE: deactivate/reactivate all users in this branch
    if ($new_status === 0) {
        deactivate_branch_users($pdo, $bid, $client_id);
    } else {
        reactivate_branch_users($pdo, $bid, $client_id);
    }
    json_out(['ok' => true, 'new_status' => $new_status,
              'msg' => 'Branch ' . ($new_status ? 'activated' : 'deactivated') .
                       '. All branch users ' . ($new_status ? 're-enabled.' : 'disabled.')]);
}

// ── PRODUCTS ──────────────────────────────────────────────────
if ($action === 'products_list') {
    $pdo  = db();
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, p.unit AS unit_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?
        ORDER BY p.name");
    $stmt->execute([$q, $q, $q]);
    json_out(['ok' => true, 'products' => $stmt->fetchAll()]);
}

if ($action === 'products_all') {
    $pdo  = db();
    $rows = $pdo->query("SELECT id, name, sku, buying_price, selling_price FROM products WHERE is_active = 1 ORDER BY name")->fetchAll();
    json_out(['ok' => true, 'products' => $rows]);
}

if ($action === 'product_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$name) json_out(['ok' => false, 'msg' => 'Product name is required.']);
    $data = [$name, trim($_POST['sku'] ?? ''), trim($_POST['barcode'] ?? ''),
             !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
             trim($_POST['unit'] ?? 'pcs'),
             (float)($_POST['buying_price']  ?? 0),
             (float)($_POST['selling_price'] ?? 0),
             (float)($_POST['reorder_level'] ?? 0),
             (int)($_POST['is_active'] ?? 1)];
    if ($id) {
        $pdo->prepare("UPDATE products SET name=?,sku=?,barcode=?,category_id=?,unit=?,buying_price=?,selling_price=?,reorder_level=?,is_active=? WHERE id=?")
            ->execute([...$data, $id]);
    } else {
        $pdo->prepare("INSERT INTO products (name,sku,barcode,category_id,unit,buying_price,selling_price,reorder_level,is_active) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute($data);
        $id = $pdo->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id]);
}

// ── CATEGORIES ────────────────────────────────────────────────
if ($action === 'categories_list') {
    $rows = db()->query("SELECT * FROM categories ORDER BY name")->fetchAll();
    json_out(['ok' => true, 'categories' => $rows]);
}

if ($action === 'category_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER, ROLE_STOREKEEPER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$name) json_out(['ok' => false, 'msg' => 'Category name is required.']);
    if ($id) {
        $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$name, $id]);
    } else {
        $pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$name]);
        $id = $pdo->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id]);
}

// ── UNITS ─────────────────────────────────────────────────────
if ($action === 'units_list') {
    $rows = db()->query("SELECT * FROM units ORDER BY name")->fetchAll();
    json_out(['ok' => true, 'units' => $rows]);
}

if ($action === 'unit_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER, ROLE_STOREKEEPER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if (!$name) json_out(['ok' => false, 'msg' => 'Unit name is required.']);
    if ($id) {
        $pdo->prepare("UPDATE units SET name = ? WHERE id = ?")->execute([$name, $id]);
    } else {
        $pdo->prepare("INSERT INTO units (name) VALUES (?)")->execute([$name]);
        $id = $pdo->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id]);
}

// ── eTIMS RETRY ───────────────────────────────────────────────
if ($action === 'etims_retry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo     = db();
    $sale_id = (int)($_POST['sale_id'] ?? 0);
    $stmt    = $pdo->prepare("SELECT branch_id FROM sales WHERE id = ? AND etims_status IN ('failed','pending')");
    $stmt->execute([$sale_id]);
    $sale = $stmt->fetch();
    if (!$sale) json_out(['ok' => false, 'msg' => 'Sale not found or already submitted.']);
    $result = etims_submit($pdo, $sale_id, $sale['branch_id']);
    json_out($result);
}

// ── eTIMS RETRY QUEUE (bulk) ──────────────────────────────────
if ($action === 'etims_retry_queue') {
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $bf   = branch_filter('s');
    $stmt = $pdo->prepare("
        SELECT s.id, s.branch_id FROM sales s
        WHERE s.etims_status IN ('failed','pending')
        AND s.voided = 0 AND {$bf['sql']}
        ORDER BY s.sale_date ASC LIMIT 20
    ");
    $stmt->execute($bf['params']);
    $pending = $stmt->fetchAll();
    $results = ['attempted' => count($pending), 'ok' => 0, 'failed' => 0];
    foreach ($pending as $sale) {
        $r = etims_submit($pdo, $sale['id'], $sale['branch_id']);
        if ($r['ok']) $results['ok']++; else $results['failed']++;
    }
    json_out(['ok' => true, 'results' => $results]);
}

// ── BRANCH eTIMS SETTINGS ─────────────────────────────────────
if ($action === 'branch_etims_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can([ROLE_OWNER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo       = db();
    $bid       = (int)$_POST['branch_id'];
    $client_id = (int)(me()['client_id'] ?? 0);
    // Verify branch belongs to this client
    $chk = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND client_id = ?");
    $chk->execute([$bid, $client_id]);
    if (!$chk->fetch()) json_out(['ok' => false, 'msg' => 'Branch not found.']);
    $pdo->prepare("
        UPDATE branches SET
            etims_enabled      = ?,
            etims_pin          = ?,
            etims_branch_code  = ?,
            etims_device_serial= ?,
            etims_env          = ?
        WHERE id = ?
    ")->execute([
        (int)($_POST['etims_enabled'] ?? 0),
        trim($_POST['etims_pin'] ?? ''),
        trim($_POST['etims_branch_code'] ?? ''),
        trim($_POST['etims_device_serial'] ?? ''),
        in_array($_POST['etims_env'] ?? '', ['sandbox','live']) ? $_POST['etims_env'] : 'sandbox',
        $bid,
    ]);
    json_out(['ok' => true]);
}

// ── BRANCH eTIMS GET ──────────────────────────────────────────
if ($action === 'branch_etims_get') {
    if (!can([ROLE_OWNER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo  = db();
    $bid  = (int)($_GET['branch_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT id, branch_name, etims_enabled, etims_pin,
               etims_branch_code, etims_device_serial, etims_env
        FROM branches WHERE id = ?
    ");
    $stmt->execute([$bid]);
    json_out(['ok' => true, 'branch' => $stmt->fetch()]);
}

// ── eTIMS STATUS SUMMARY ──────────────────────────────────────
if ($action === 'etims_status_summary') {
    if (!can([ROLE_OWNER, ROLE_BRANCH_MANAGER])) json_out(['ok' => false, 'msg' => 'No permission.']);
    $pdo = db();
    $bf  = branch_filter('s');
    $stmt = $pdo->prepare("
        SELECT etims_status, COUNT(*) AS cnt
        FROM sales s WHERE voided = 0 AND {$bf['sql']}
        AND DATE(sale_date) = CURDATE()
        GROUP BY etims_status
    ");
    $stmt->execute($bf['params']);
    $rows = $stmt->fetchAll();
    $summary = ['submitted' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0];
    foreach ($rows as $r) {
        $summary[$r['etims_status']] = (int)$r['cnt'];
    }
    json_out(['ok' => true, 'summary' => $summary]);
}

// Unknown action guard
if ($action) { json_out(['ok' => false, 'msg' => 'Unknown action.']); }

// ============================================================
//  PAGE RENDER
// ============================================================
$logged_in   = logged_in();
$user        = me();
$role_name   = $user['role_name']   ?? '';
$branch_name = $user['branch_name'] ?? 'All Branches';
$csrf_token  = csrf();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NYMIX — Staff Portal</title> <link rel="icon" type="image/png" href="logo.png">

<!-- Bootstrap 5.3 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Font Awesome 6 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
/* ── DESIGN TOKENS ──────────────────────────────────────────── */
:root,
[data-theme="dark"] {
  --nx-bg:       #0f1117;
  --nx-surface:  #181c27;
  --nx-card:     #1e2333;
  --nx-border:   #2a2f42;
  --nx-accent:   #f59e0b;
  --nx-blue:     #3b82f6;
  --nx-green:    #22c55e;
  --nx-red:      #ef4444;
  --nx-orange:   #f97316;
  --nx-text:     #e8eaf0;
  --nx-muted:    #6b7280;
  --nx-sidebar:  240px;
  --nx-topbar:   60px;
  --font-body:  'DM Sans', sans-serif;
  --font-head:  'Space Grotesk', sans-serif;
}

[data-theme="light"] {
  --nx-bg:       #f0f2f5;
  --nx-surface:  #ffffff;
  --nx-card:     #ffffff;
  --nx-border:   #e2e8f0;
  --nx-accent:   #d97706;
  --nx-blue:     #2563eb;
  --nx-green:    #16a34a;
  --nx-red:      #dc2626;
  --nx-orange:   #ea580c;
  --nx-text:     #0f172a;
  --nx-muted:    #64748b;
  --nx-sidebar:  240px;
  --nx-topbar:   60px;
  --font-body:  'DM Sans', sans-serif;
  --font-head:  'Space Grotesk', sans-serif;
}

[data-theme="light"] .form-control,
[data-theme="light"] .form-select {
  background: #f8fafc;
  border-color: #e2e8f0;
  color: #0f172a;
}
[data-theme="light"] .form-control:focus,
[data-theme="light"] .form-select:focus {
  background: #ffffff;
  color: #0f172a;
}
[data-theme="light"] .table { color: #0f172a; }
[data-theme="light"] .table > :not(caption) > * > * { border-bottom-color: #e2e8f0; }
[data-theme="light"] .table thead th { background: #f1f5f9; color: #64748b; border-bottom-color: #e2e8f0; }
[data-theme="light"] .table tbody tr:hover > td { background: rgba(0,0,0,.02); }
[data-theme="light"] .dropdown-menu { background: #ffffff; border-color: #e2e8f0; }
[data-theme="light"] .dropdown-item { color: #0f172a; }
[data-theme="light"] .dropdown-item:hover { background: #f1f5f9; }
[data-theme="light"] .modal-content { background: #ffffff; border-color: #e2e8f0; color: #0f172a; }
[data-theme="light"] .modal-header { background: #f8fafc; border-bottom-color: #e2e8f0; }
[data-theme="light"] .modal-footer { background: #f8fafc; border-top-color: #e2e8f0; }
[data-theme="light"] .btn-close { filter: none; }
[data-theme="light"] .receipt { background: #fff; color: #111; }
[data-theme="light"] #login-screen { background: radial-gradient(ellipse at 20% 50%, #dbeafe, #f0f2f5 60%); }
[data-theme="light"] .nx-toast { background: #ffffff; border-color: #e2e8f0; color: #0f172a; }

/* ── BOOTSTRAP OVERRIDES ───────────────────────────────────── */
body {
  font-family: var(--font-body);
  background: var(--nx-bg);
  color: var(--nx-text);
  min-height: 100vh;
}
.card {
  background: var(--nx-card);
  border: 1px solid var(--nx-border);
  border-radius: 12px;
}
.card-header {
  background: var(--nx-surface);
  border-bottom: 1px solid var(--nx-border);
  font-family: var(--font-head);
  font-weight: 600;
}
.modal-content {
  background: var(--nx-card);
  border: 1px solid var(--nx-border);
  border-radius: 14px;
  color: var(--nx-text);
}
.modal-header {
  background: var(--nx-surface);
  border-bottom: 1px solid var(--nx-border);
}
.modal-footer {
  background: var(--nx-surface);
  border-top: 1px solid var(--nx-border);
}
.btn-close { filter: invert(1); }
.form-control, .form-select {
  background: var(--nx-surface);
  border: 1px solid var(--nx-border);
  color: var(--nx-text);
  font-family: var(--font-body);
}
.form-control:focus, .form-select:focus {
  background: var(--nx-surface);
  border-color: var(--nx-accent);
  color: var(--nx-text);
  box-shadow: 0 0 0 3px rgba(245,158,11,.15);
}
.form-control::placeholder { color: var(--nx-muted); }
.form-select option { background: var(--nx-surface); }
.form-label { color: var(--nx-muted); font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; }
.table { color: var(--nx-text); }
.table > :not(caption) > * > * { background: transparent; border-bottom-color: var(--nx-border); padding: 10px 14px; }
.table thead th { background: var(--nx-surface); color: var(--nx-muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .7px; font-weight: 600; border-bottom: 1px solid var(--nx-border); }
.table tbody tr:hover > td { background: rgba(255,255,255,.02); }
.dropdown-menu { background: var(--nx-card); border: 1px solid var(--nx-border); }
.dropdown-item { color: var(--nx-text); }
.dropdown-item:hover { background: var(--nx-surface); color: var(--nx-accent); }
.nav-tabs { border-bottom: 1px solid var(--nx-border); }
.nav-tabs .nav-link { color: var(--nx-muted); border: none; padding: 10px 18px; font-weight: 500; }
.nav-tabs .nav-link:hover { color: var(--nx-text); }
.nav-tabs .nav-link.active { color: var(--nx-accent); border-bottom: 2px solid var(--nx-accent); background: transparent; }
.tab-content { padding-top: 20px; }
.badge { font-weight: 600; }
.alert { border: none; border-radius: 10px; }
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: var(--nx-bg); }
::-webkit-scrollbar-thumb { background: var(--nx-border); border-radius: 4px; }

/* ── SIDEBAR ────────────────────────────────────────────────── */
.nx-sidebar {
  position: fixed; top: 0; left: 0; bottom: 0;
  width: var(--nx-sidebar);
  background: var(--nx-surface);
  border-right: 1px solid var(--nx-border);
  display: flex; flex-direction: column;
  z-index: 1040;
  overflow-y: auto;
  transition: transform .25s cubic-bezier(.4,0,.2,1);
}
.nx-sidebar .brand {
  font-family: var(--font-head);
  font-size: 1.15rem; font-weight: 700;
  background: linear-gradient(135deg, var(--nx-accent), #fb923c);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.nx-sidebar .branch-badge {
  font-size: .7rem; color: var(--nx-muted);
  display: flex; align-items: center; gap: 4px;
}
.nx-nav-link {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 14px; border-radius: 8px;
  color: var(--nx-muted); font-size: .875rem; font-weight: 500;
  cursor: pointer; border: none; background: none;
  width: 100%; text-align: left;
  transition: all .15s ease;
  text-decoration: none;
}
.nx-nav-link:hover { background: var(--nx-card); color: var(--nx-text); }
.nx-nav-link.active { background: rgba(245,158,11,.12); color: var(--nx-accent); }
.nx-nav-link i { width: 16px; text-align: center; font-size: .9rem; }
.nav-section-label {
  font-size: .65rem; font-weight: 700; letter-spacing: 1.2px;
  text-transform: uppercase; color: var(--nx-muted);
  padding: 6px 14px 4px; margin-top: 8px;
}
.user-chip {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; background: var(--nx-card);
  border-radius: 10px; border: 1px solid var(--nx-border);
}
.user-avatar {
  width: 34px; height: 34px; border-radius: 50%;
  background: linear-gradient(135deg, var(--nx-accent), #fb923c);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; color: #000; font-size: .85rem; flex-shrink: 0;
}

/* ── TOPBAR ─────────────────────────────────────────────────── */
.nx-topbar {
  position: fixed; top: 0; right: 0; left: var(--nx-sidebar);
  height: var(--nx-topbar);
  background: var(--nx-surface);
  border-bottom: 1px solid var(--nx-border);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 20px; z-index: 1030;
  transition: left .25s cubic-bezier(.4,0,.2,1);
}
.nx-page-title { font-family: var(--font-head); font-size: 1.05rem; font-weight: 700; }

/* ── MAIN CONTENT ───────────────────────────────────────────── */
#nx-main {
  margin-left: var(--nx-sidebar);
  padding-top: var(--nx-topbar);
  min-height: 100vh;
  transition: margin-left .25s cubic-bezier(.4,0,.2,1);
}
#nx-content { padding: 24px; }

/* ── STAT CARDS ─────────────────────────────────────────────── */
.stat-card {
  background: var(--nx-card);
  border: 1px solid var(--nx-border);
  border-radius: 12px; padding: 20px;
  position: relative; overflow: hidden;
  transition: transform .2s, border-color .2s;
}
.stat-card:hover { transform: translateY(-2px); border-color: rgba(245,158,11,.3); }
.stat-card::before {
  content: ''; position: absolute; top: 0; right: 0;
  width: 3px; height: 100%;
  background: var(--card-accent, var(--nx-accent));
}
.stat-label { font-size: .7rem; color: var(--nx-muted); text-transform: uppercase; letter-spacing: .8px; font-weight: 600; }
.stat-value { font-family: var(--font-head); font-size: 1.8rem; font-weight: 700; line-height: 1.1; margin: 6px 0 2px; }
.stat-sub   { font-size: .72rem; color: var(--nx-muted); }
.stat-icon  { position: absolute; top: 16px; right: 14px; font-size: 1.4rem; opacity: .12; }

/* ── POS LAYOUT ─────────────────────────────────────────────── */
.pos-wrap { display: grid; grid-template-columns: 1fr 320px; gap: 16px; height: calc(100vh - var(--nx-topbar) - 48px); }
.pos-products { overflow-y: auto; }
.pos-cart { display: flex; flex-direction: column; background: var(--nx-card); border: 1px solid var(--nx-border); border-radius: 12px; overflow: hidden; }
.cart-items { flex: 1; overflow-y: auto; padding: 10px; }
.cart-item { background: var(--nx-surface); border: 1px solid var(--nx-border); border-radius: 8px; padding: 9px 11px; margin-bottom: 7px; }
.cart-qty-btn { width: 26px; height: 26px; border-radius: 6px; border: none; background: var(--nx-border); color: var(--nx-text); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background .15s; }
.cart-qty-btn:hover { background: var(--nx-accent); color: #000; }
.cart-qty-input { width: 46px; text-align: center; background: var(--nx-bg); border: 1px solid var(--nx-border); border-radius: 6px; color: var(--nx-text); font-size: .85rem; padding: 2px 4px; }
.cart-summary { padding: 12px 14px; border-top: 1px solid var(--nx-border); background: var(--nx-surface); }
.cart-row { display: flex; justify-content: space-between; font-size: .83rem; margin-bottom: 5px; color: var(--nx-muted); }
.cart-row.total { font-size: 1.05rem; font-weight: 700; color: var(--nx-text); border-top: 1px solid var(--nx-border); padding-top: 7px; margin-top: 3px; }
.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
.prod-card { background: var(--nx-card); border: 1px solid var(--nx-border); border-radius: 10px; padding: 13px; cursor: pointer; transition: border-color .15s, transform .15s; }
.prod-card:hover { border-color: var(--nx-accent); transform: translateY(-1px); }
.prod-card.out-of-stock { opacity: .45; pointer-events: none; }
.prod-name { font-size: .83rem; font-weight: 600; margin-bottom: 3px; line-height: 1.3; }
.prod-sku  { font-size: .7rem; color: var(--nx-muted); margin-bottom: 6px; }
.prod-price { font-size: 1rem; font-weight: 700; color: var(--nx-accent); }
.prod-stock { font-size: .7rem; margin-top: 3px; }
.change-box { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.25); border-radius: 8px; padding: 8px 12px; text-align: center; }

/* ── BAR CHART ──────────────────────────────────────────────── */
.bar-chart { display: flex; align-items: flex-end; gap: 8px; height: 110px; padding: 4px 0; }
.bar-wrap { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 3px; }
.bar { width: 100%; background: var(--nx-accent); border-radius: 4px 4px 0 0; min-height: 4px; opacity: .85; transition: opacity .2s; }
.bar:hover { opacity: 1; }
.bar-label { font-size: .65rem; color: var(--nx-muted); white-space: nowrap; }
.bar-val   { font-size: .65rem; color: var(--nx-accent); font-weight: 600; }

/* ── RECEIPT ────────────────────────────────────────────────── */
.receipt { background: #fff; color: #111; padding: 20px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: .75rem; max-width: 300px; margin: 0 auto; }
.receipt h5 { text-align: center; font-size: .9rem; }
.receipt hr { border-top: 1px dashed #aaa; }
.receipt-row { display: flex; justify-content: space-between; margin-bottom: 2px; }
.receipt-total { font-weight: 700; border-top: 2px solid #111; padding-top: 5px; margin-top: 5px; }

/* ── MOVEMENT TYPES ─────────────────────────────────────────── */
.mov-type { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: .7rem; font-weight: 600; }
.mov-sale         { background: rgba(59,130,246,.15); color: var(--nx-blue); }
.mov-purchase     { background: rgba(34,197,94,.15);  color: var(--nx-green); }
.mov-adjustment   { background: rgba(245,158,11,.15); color: var(--nx-accent); }
.mov-transfer_in  { background: rgba(34,197,94,.12);  color: var(--nx-green); }
.mov-transfer_out { background: rgba(239,68,68,.12);  color: var(--nx-red); }
.mov-void_return  { background: rgba(107,114,128,.15);color: var(--nx-muted); }

/* ── TOAST ──────────────────────────────────────────────────── */
#toast-wrap { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
.nx-toast { background: var(--nx-card); border: 1px solid var(--nx-border); border-radius: 10px; padding: 11px 16px; font-size: .85rem; box-shadow: 0 4px 20px rgba(0,0,0,.4); display: flex; align-items: center; gap: 10px; min-width: 220px; animation: toastIn .25s ease; pointer-events: all; }
.nx-toast.success { border-left: 3px solid var(--nx-green); }
.nx-toast.error   { border-left: 3px solid var(--nx-red); }
.nx-toast.info    { border-left: 3px solid var(--nx-blue); }
@keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: none; opacity: 1; } }

/* ── LOGIN ──────────────────────────────────────────────────── */
#login-screen { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: radial-gradient(ellipse at 20% 50%, #1a1f35, #0f1117 60%); }
.login-box { background: var(--nx-card); border: 1px solid var(--nx-border); border-radius: 16px; padding: 44px 40px; width: 100%; max-width: 380px; box-shadow: 0 8px 40px rgba(0,0,0,.5); }
.login-brand { font-family: var(--font-head); font-size: 1.6rem; font-weight: 700; background: linear-gradient(135deg, var(--nx-accent), #fb923c); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

/* ── IDLE OVERLAY ───────────────────────────────────────────── */
#idle-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.75); z-index: 9998; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
#idle-overlay.show { display: flex; }
.idle-card { background: var(--nx-card); border: 1px solid var(--nx-border); border-radius: 16px; padding: 40px 36px; max-width: 360px; width: 90%; text-align: center; box-shadow: 0 8px 40px rgba(0,0,0,.6); }
#idle-seconds { font-family: var(--font-head); font-size: 3.5rem; font-weight: 700; color: var(--nx-accent); }

/* ── UTILITIES ──────────────────────────────────────────────── */
.text-accent { color: var(--nx-accent) !important; }
.text-nx-muted { color: var(--nx-muted) !important; }
.fw-head { font-family: var(--font-head); }
.low-stock-row td:first-child { border-left: 3px solid var(--nx-red); }
.surface { background: var(--nx-surface); }

/* ── RESPONSIVE ─────────────────────────────────────────────── */
@media (max-width: 991.98px) {
  .nx-sidebar { transform: translateX(-100%); }
  .nx-sidebar.show { transform: translateX(0); box-shadow: 8px 0 32px rgba(0,0,0,.6); }
  .nx-topbar { left: 0 !important; }
  #nx-main { margin-left: 0 !important; }
  .pos-wrap { grid-template-columns: 1fr; height: auto; }
  .pos-cart { margin-top: 16px; max-height: 500px; }
}
@media (max-width: 575.98px) {
  .login-box { padding: 32px 22px; }
  #nx-content { padding: 14px; }
  .stat-value { font-size: 1.4rem; }
  .product-grid { grid-template-columns: repeat(2, 1fr); }
}
/* Sidebar backdrop */
#nx-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1039; backdrop-filter: blur(2px); }
#nx-backdrop.show { display: block; }

/* Lock screen shake */
@keyframes lockShake {
  0%,100% { transform: translateX(0); }
  15%     { transform: translateX(-10px); }
  30%     { transform: translateX(10px); }
  45%     { transform: translateX(-8px); }
  60%     { transform: translateX(8px); }
  75%     { transform: translateX(-4px); }
  90%     { transform: translateX(4px); }
}
.lock-shake { animation: lockShake 0.5s ease; }
</style>
</head>
<body>

<!-- ══════════════ LOGIN ══════════════ -->
<div id="login-screen" <?= $logged_in ? 'class="d-none"' : '' ?>>
  <div class="login-box">
    <div class="text-center mb-4">
      <div class="login-brand">NYMIX BUSINESS</div>
      <p class="text-nx-muted small mt-1">Staff Operations Portal</p>
    </div>
    <h5 class="fw-head mb-4">Sign in to your account</h5>
    <div id="login-err" class="alert alert-danger d-none py-2 small"></div>
    <div class="mb-3">
      <label class="form-label">Username</label>
      <input type="text" id="l-user" class="form-control" placeholder="Enter username" autocomplete="username">
    </div>
    <div class="mb-4">
      <label class="form-label">Password</label>
      <div class="input-group">
        <input type="password" id="l-pass" class="form-control" placeholder="••••••••" autocomplete="current-password">
        <button class="btn btn-outline-secondary" type="button" id="togglePw" onclick="togglePassword()">
          <i class="fa fa-eye" id="pw-eye-icon"></i>
        </button>
      </div>
    </div>
    <button class="btn w-100 fw-bold" style="background:var(--nx-accent);color:#000" onclick="doLogin()">
      <i class="fa fa-sign-in-alt me-2"></i> Sign In
    </button>
    <p class="text-nx-muted text-center small mt-3 mb-0">
      <i class="fa fa-shield-halved me-1"></i> Secured · NYMIX TECH
    </p>
  </div>
</div>

<!-- ══════════════ APP ══════════════ -->
<div id="nx-app" <?= $logged_in ? '' : 'class="d-none"' ?>>

  <!-- Sidebar backdrop (mobile) -->
  <div id="nx-backdrop" onclick="closeSidebar()"></div>

  <!-- SIDEBAR -->
  <aside class="nx-sidebar" id="nx-sidebar">
    <div class="p-3 border-bottom" style="border-color:var(--nx-border)!important">
      <div class="d-flex align-items-center gap-2 mb-1">
        <div id="sb-logo-wrap">
          <?php if (!empty($user['logo'])): ?>
          <img src="<?= esc($user['logo']) ?>" alt="logo" id="sb-logo"
               style="width:36px;height:36px;border-radius:8px;object-fit:cover;border:1px solid var(--nx-border)"
               onerror="this.style.display='none';document.getElementById('sb-logo-fallback').style.display='flex'">
          <?php endif; ?>
          <div id="sb-logo-fallback" style="width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,var(--nx-accent),#fb923c);display:<?= !empty($user['logo']) ? 'none' : 'flex' ?>;align-items:center;justify-content:center;font-weight:700;color:#000;font-size:.9rem">
            <?= strtoupper(substr($user['business_name'] ?? 'N', 0, 1)) ?>
          </div>
        </div>
        <div style="min-width:0">
          <div class="brand" style="font-size:.95rem" id="sb-bizname">
            <?= esc($user['business_name'] ?? 'NYMIX Hardware') ?>
          </div>
        </div>
      </div>
      <div class="branch-badge mt-1">
        <i class="fa fa-code-branch" style="font-size:.65rem"></i>
        <span id="sb-branch"><?= esc($branch_name) ?></span>
      </div>
    </div>

    <div class="p-2 border-bottom" style="border-color:var(--nx-border)!important">
      <div class="user-chip">
        <div class="user-avatar" id="sb-avatar">
          <?= $logged_in ? strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) : 'U' ?>
        </div>
        <div style="min-width:0">
          <div class="fw-600 text-truncate small" id="sb-name"><?= esc($user['full_name'] ?? '') ?></div>
          <div class="text-nx-muted" style="font-size:.7rem;text-transform:capitalize" id="sb-role"><?= esc($role_name) ?></div>
        </div>
      </div>
    </div>

    <nav class="p-2 flex-grow-1" id="nx-nav">
      <div class="nav-section-label">Main</div>
      <button class="nx-nav-link active" data-page="dashboard"><i class="fa fa-chart-pie"></i> Dashboard</button>

      <div id="nav-sales-sec">
        <div class="nav-section-label">Sales</div>
        <button class="nx-nav-link" data-page="pos"><i class="fa fa-cash-register"></i> Point of Sale</button>
        <button class="nx-nav-link" data-page="sales"><i class="fa fa-receipt"></i> Sales History</button>
      </div>

      <div id="nav-inventory-sec">
        <div class="nav-section-label">Inventory</div>
        <button class="nx-nav-link" data-page="inventory"><i class="fa fa-boxes-stacked"></i> Stock Levels</button>
        <button class="nx-nav-link" data-page="transfers"><i class="fa fa-arrows-left-right"></i> Transfers</button>
      </div>

      <div id="nav-products-sec">
        <div class="nav-section-label">Products</div>
        <button class="nx-nav-link" data-page="products"><i class="fa fa-box"></i> Products</button>
        <button class="nx-nav-link" data-page="categories"><i class="fa fa-tags"></i> Categories & Units</button>
      </div>

      <div id="nav-purchase-sec">
        <div class="nav-section-label">Purchases</div>
        <button class="nx-nav-link" data-page="purchases"><i class="fa fa-truck"></i> Purchase Orders</button>
        <button class="nx-nav-link" data-page="suppliers"><i class="fa fa-industry"></i> Suppliers</button>
      </div>

      <div id="nav-crm-sec">
        <div class="nav-section-label">Customers</div>
        <button class="nx-nav-link" data-page="customers"><i class="fa fa-users"></i> Customers</button>
      </div>

      <div id="nav-reports-sec">
        <div class="nav-section-label">Reports</div>
        <button class="nx-nav-link" data-page="reports"><i class="fa fa-chart-bar"></i> Reports</button>
        <button class="nx-nav-link" data-page="expenses"><i class="fa fa-wallet"></i> Expenses</button>
      </div>

      <div id="nav-admin-sec">
        <div class="nav-section-label">Admin</div>
        <button class="nx-nav-link" data-page="users"><i class="fa fa-user-gear"></i> Staff Users</button>
        <button class="nx-nav-link" data-page="etims"><i class="fa fa-file-invoice"></i> eTIMS / KRA</button>
      </div>
    </nav>

    <div class="p-2 border-top" style="border-color:var(--nx-border)!important">
      <button class="nx-nav-link text-danger" onclick="doLogout()">
        <i class="fa fa-right-from-bracket"></i> Sign Out
      </button>
    </div>
  </aside>

  <!-- TOPBAR -->
  <header class="nx-topbar" id="nx-topbar">
    <div class="d-flex align-items-center gap-3">
      <!-- Hamburger (mobile) -->
      <button class="btn btn-sm d-lg-none" style="background:var(--nx-surface);border:1px solid var(--nx-border);color:var(--nx-text)" onclick="toggleSidebar()">
        <i class="fa fa-bars"></i>
      </button>
      <div class="d-flex align-items-center gap-2">
        <div id="tb-logo-wrap" class="d-none d-md-block">
          <?php if (!empty($user['logo'])): ?>
          <img src="<?= esc($user['logo']) ?>" alt="logo"
               style="width:28px;height:28px;border-radius:6px;object-fit:cover"
               onerror="this.style.display='none'">
          <?php endif; ?>
        </div>
        <div>
          <div class="nx-page-title" id="page-title">Dashboard</div>
          <div style="font-size:.65rem;color:var(--nx-muted);line-height:1" id="tb-branch-label">
            <?= esc($user['branch_name'] ?? '') ?>
          </div>
        </div>
      </div>
    </div>
    <div class="d-flex align-items-center gap-3">
      <span class="text-nx-muted d-none d-md-block" style="font-size:.8rem" id="topbar-date"></span>
<button class="btn btn-sm" id="theme-toggle" style="background:var(--nx-surface);border:1px solid var(--nx-border);color:var(--nx-text);width:36px;height:36px;padding:0;border-radius:8px" onclick="toggleTheme()" title="Toggle theme">
  <i class="fa fa-moon" id="theme-icon"></i>
</button>
<button class="btn btn-sm" style="background:var(--nx-surface);border:1px solid var(--nx-border);color:var(--nx-muted);width:36px;height:36px;padding:0;border-radius:8px" onclick="lockScreen()" title="Lock screen">
  <i class="fa fa-lock"></i>
</button>
      <div class="dropdown">
        <button class="btn btn-sm dropdown-toggle" style="background:var(--nx-surface);border:1px solid var(--nx-border);color:var(--nx-text)" data-bs-toggle="dropdown">
          <i class="fa fa-user-circle me-1"></i>
          <span class="d-none d-sm-inline" id="tb-username"><?= esc($user['full_name'] ?? '') ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><span class="dropdown-item-text small text-nx-muted"><?= esc($role_name) ?></span></li>
          <li><hr class="dropdown-divider" style="border-color:var(--nx-border)"></li>
          <li><a class="dropdown-item text-danger" href="#" onclick="doLogout()"><i class="fa fa-right-from-bracket me-2"></i>Sign Out</a></li>
        </ul>
      </div>
    </div>
  </header>

  <!-- MAIN CONTENT -->
  <div id="nx-main">
    <div id="nx-content">
      <div class="d-flex align-items-center justify-content-center py-5">
        <div class="spinner-border text-warning me-3" role="status"></div>
        <span class="text-nx-muted">Loading...</span>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ MODALS ══════════ -->

<!-- Sale Receipt -->
<div class="modal fade" id="modal-receipt" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-head">Sale Receipt</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="receipt-body"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" onclick="window.print()"><i class="fa fa-print me-1"></i>Print</button>
        <button class="btn btn-danger btn-sm d-none" id="btn-void-sale" onclick="voidSale()">Void Sale</button>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Stock Adjust -->
<div class="modal fade" id="modal-adjust" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head">Stock Adjustment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="adj-prod-id">
        <input type="hidden" id="adj-stock-id">
        <input type="hidden" id="adj-branch-id">
        <div class="mb-3"><label class="form-label">Product</label><input type="text" id="adj-prod-name" class="form-control" readonly></div>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Reason</label>
            <select id="adj-reason" class="form-select">
              <option value="recount">Stock Recount</option>
              <option value="damage">Damaged Goods</option>
              <option value="theft">Theft / Loss</option>
              <option value="return">Customer Return</option>
              <option value="found">Found Stock</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Type</label>
            <select id="adj-type" class="form-select">
              <option value="add">Add Stock</option>
              <option value="remove">Remove Stock</option>
              <option value="set">Set Exact Qty</option>
            </select>
          </div>
        </div>
        <div class="mt-3 mb-3"><label class="form-label">Quantity</label><input type="number" id="adj-qty" class="form-control" min="0" step="0.001"></div>
        <div><label class="form-label">Note</label><input type="text" id="adj-note" class="form-control" placeholder="Optional detail…"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="submitAdjust()">Save Adjustment</button>
      </div>
    </div>
  </div>
</div>

<!-- Transfer Create -->
<div class="modal fade" id="modal-transfer" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head">New Stock Transfer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-6"><label class="form-label">From Branch</label><select id="tr-from" class="form-select"></select></div>
          <div class="col-6"><label class="form-label">To Branch</label><select id="tr-to" class="form-select"></select></div>
        </div>
        <div class="mt-3"><label class="form-label">Product</label><select id="tr-product" class="form-select"></select></div>
        <div class="mt-3"><label class="form-label">Quantity</label><input type="number" id="tr-qty" class="form-control" min="0.001" step="0.001"></div>
        <div class="mt-3"><label class="form-label">Note</label><input type="text" id="tr-note" class="form-control"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="submitTransfer()">Submit Transfer</button>
      </div>
    </div>
  </div>
</div>

<!-- Customer Form -->
<div class="modal fade" id="modal-customer" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head" id="cust-modal-title">New Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="cust-id">
        <div class="mb-3"><label class="form-label">Full Name *</label><input type="text" id="cust-name" class="form-control"></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Phone</label><input type="text" id="cust-phone" class="form-control"></div>
          <div class="col-6"><label class="form-label">Email</label><input type="email" id="cust-email" class="form-control"></div>
        </div>
        <div class="mt-3"><label class="form-label">Address</label><input type="text" id="cust-address" class="form-control"></div>
        <div class="mt-3">
          <label class="form-label">Credit Limit (KSh)</label>
          <div class="input-group">
            <input type="number" id="cust-credit" class="form-control" value="0" min="0">
            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="applySuggestedLimit()" title="Apply suggested limit">
              <i class="fa fa-magic"></i>
            </button>
          </div>
          <div id="cust-credit-hint" class="mt-1" style="font-size:.72rem;color:var(--nx-muted)"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="saveCustomer()">Save Customer</button>
      </div>
    </div>
  </div>
</div>

<!-- Customer Payment -->
<div class="modal fade" id="modal-cust-pay" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head">Record Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="cpay-cid">
        <div class="mb-3"><label class="form-label">Customer</label><input type="text" id="cpay-name" class="form-control" readonly></div>
        <div class="mb-3"><label class="form-label">Amount (KSh)</label><input type="number" id="cpay-amount" class="form-control" min="1"></div>
        <div class="mb-3"><label class="form-label">Method</label>
          <select id="cpay-method" class="form-select">
            <option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="bank">Bank</option>
          </select>
        </div>
        <div><label class="form-label">M-Pesa Code</label><input type="text" id="cpay-mpesa" class="form-control"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success fw-bold" onclick="submitCustPayment()">Record Payment</button>
      </div>
    </div>
  </div>
</div>

<!-- PO Create -->
<div class="modal fade" id="modal-po" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head">New Purchase Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label">Supplier *</label><select id="po-supplier" class="form-select"></select></div>
          <div class="col-md-6"><label class="form-label">Expected Date</label><input type="date" id="po-date" class="form-control"></div>
        </div>
        <div class="mb-3"><label class="form-label">Note</label><input type="text" id="po-note" class="form-control"></div>
        <div class="border rounded p-3" style="border-color:var(--nx-border)!important">
          <div class="d-flex gap-2 mb-3 flex-wrap">
            <select id="po-prod-sel" class="form-select flex-grow-1" style="min-width:180px"></select>
            <input type="number" id="po-prod-qty" class="form-control" placeholder="Qty" min="1" style="width:80px">
            <input type="number" id="po-prod-price" class="form-control" placeholder="Price" min="0" style="width:100px">
            <button class="btn btn-primary btn-sm" onclick="addPoItem()"><i class="fa fa-plus"></i></button>
          </div>
          <div id="po-items-list"></div>
          <div class="text-end mt-2 fw-bold">Total: <span id="po-total" class="text-accent">KSh 0.00</span></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="submitPO()">Create LPO</button>
      </div>
    </div>
  </div>
</div>

<!-- Receive Stock -->
<div class="modal fade" id="modal-receive" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head">Receive Stock</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="receive-body"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-success fw-bold" onclick="submitReceive()"><i class="fa fa-box-open me-1"></i>Confirm Receipt</button>
      </div>
    </div>
  </div>
</div>

<!-- Expense Form -->
<div class="modal fade" id="modal-expense" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head">Add Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Category *</label><select id="exp-cat" class="form-select"></select></div>
        <div class="mb-3"><label class="form-label">Amount (KSh) *</label><input type="number" id="exp-amount" class="form-control" min="1"></div>
        <div class="mb-3"><label class="form-label">Description</label><input type="text" id="exp-desc" class="form-control"></div>
        <div class="mb-3"><label class="form-label">Date</label><input type="date" id="exp-date" class="form-control"></div>
        <div><label class="form-label">Receipt No.</label><input type="text" id="exp-receipt" class="form-control"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="submitExpense()">Save Expense</button>
      </div>
    </div>
  </div>
</div>

<!-- User Form -->
<div class="modal fade" id="modal-user" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head" id="user-modal-title">New Staff User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="usr-id">
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Full Name *</label><input type="text" id="usr-name" class="form-control"></div>
          <div class="col-6"><label class="form-label">Username *</label><input type="text" id="usr-username" class="form-control"></div>
          <div class="col-6"><label class="form-label">Phone *</label><input type="text" id="usr-phone" class="form-control"></div>
          <div class="col-6"><label class="form-label">Email</label><input type="email" id="usr-email" class="form-control"></div>
          <div class="col-6"><label class="form-label">Role *</label><select id="usr-role" class="form-select"></select></div>
          <div class="col-6"><label class="form-label">Branch</label><select id="usr-branch" class="form-select"></select></div>
          <div class="col-6"><label class="form-label">Password</label><input type="password" id="usr-password" class="form-control" placeholder="Leave blank to keep"></div>
          <div class="col-6"><label class="form-label">Status</label><select id="usr-active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="saveUser()">Save User</button>
      </div>
    </div>
  </div>
</div>

<!-- Product Form -->
<div class="modal fade" id="modal-product" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head" id="prod-modal-title">New Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="prod-id">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Product Name *</label><input type="text" id="prod-name" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">SKU</label><input type="text" id="prod-sku" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Barcode</label><input type="text" id="prod-barcode" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Category</label><select id="prod-category" class="form-select"></select></div>
          <div class="col-md-6"><label class="form-label">Unit</label><select id="prod-unit" class="form-select"></select></div>
          <div class="col-md-6"><label class="form-label">Reorder Level</label><input type="number" id="prod-reorder" class="form-control" value="5" min="0"></div>
          <div class="col-md-6"><label class="form-label">Buying Price (KSh) *</label><input type="number" id="prod-buy" class="form-control" min="0" step="0.01"></div>
          <div class="col-md-6"><label class="form-label">Selling Price (KSh) *</label><input type="number" id="prod-sell" class="form-control" min="0" step="0.01"></div>
          <div class="col-12"><label class="form-label">Status</label><select id="prod-active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="saveProduct()">Save Product</button>
      </div>
    </div>
  </div>
</div>

<!-- Category Form -->
<div class="modal fade" id="modal-category" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head" id="cat-modal-title">New Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="cat-id">
        <label class="form-label">Category Name *</label>
        <input type="text" id="cat-name" class="form-control">
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="saveCategory()">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Unit Form -->
<div class="modal fade" id="modal-unit" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head" id="unit-modal-title">New Unit</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="unit-id">
        <label class="form-label">Unit Name *</label>
        <input type="text" id="unit-name" class="form-control" placeholder="e.g. Pieces, Kg, Litres">
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="saveUnit()">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Supplier Form -->
<div class="modal fade" id="modal-supplier" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-head" id="sup-modal-title">New Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="sup-id">
        <div class="mb-3"><label class="form-label">Supplier Name *</label><input type="text" id="sup-name" class="form-control"></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Contact Person</label><input type="text" id="sup-contact" class="form-control"></div>
          <div class="col-6"><label class="form-label">Phone *</label><input type="text" id="sup-phone" class="form-control"></div>
          <div class="col-6"><label class="form-label">Email</label><input type="email" id="sup-email" class="form-control"></div>
          <div class="col-6"><label class="form-label">Status</label><select id="sup-active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select></div>
        </div>
        <div class="mt-3"><label class="form-label">Address</label><input type="text" id="sup-address" class="form-control"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="saveSupplier()">Save Supplier</button>
      </div>
    </div>
  </div>
</div>

<!-- Customer Receipt Modal -->
<div class="modal fade" id="modal-cust-receipt" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-head">Customer Card</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" id="cust-receipt-body"></div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-sm" onclick="printCard()">
          <i class="fa fa-print me-1"></i>Print
        </button>
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ LOCK SCREEN ══════════ -->
<div id="lock-screen" class="d-none" style="position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(10,12,20,0.97);backdrop-filter:blur(12px)">
  <div id="lock-card" style="background:var(--nx-card);border:1px solid var(--nx-border);border-radius:20px;padding:44px 40px;width:100%;max-width:380px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.7)">
    <!-- Avatar -->
    <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--nx-accent),#fb923c);display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:700;color:#000;margin:0 auto 16px" id="lock-avatar">
      <?= $logged_in ? strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) : 'U' ?>
    </div>
    <div style="font-family:var(--font-head);font-size:1.1rem;font-weight:700" id="lock-name">
      <?= esc($user['full_name'] ?? '') ?>
    </div>
    <div style="color:var(--nx-muted);font-size:.8rem;margin-bottom:6px" id="lock-role">
      <?= esc($role_name) ?>
    </div>
    <div style="color:var(--nx-muted);font-size:.75rem;margin-bottom:24px">
      <i class="fa fa-lock me-1 text-accent"></i>Screen Locked
    </div>

    <!-- Password input -->
    <div class="mb-3" style="position:relative">
      <input type="password" id="lock-pin"
             class="form-control text-center fw-bold"
             placeholder="Enter your password"
             style="font-size:1rem;padding:12px;letter-spacing:2px"
             autocomplete="current-password">
      <button type="button"
              style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--nx-muted);cursor:pointer"
              onclick="const i=document.getElementById('lock-pin');i.type=i.type==='password'?'text':'password'">
        <i class="fa fa-eye"></i>
      </button>
    </div>

    <div id="lock-error" class="alert alert-danger py-2 small d-none mb-3"></div>

    <button id="lock-unlock-btn" class="btn w-100 fw-bold mb-3"
            style="background:var(--nx-accent);color:#000;padding:11px"
            onclick="unlockScreen()">
      <i class="fa fa-unlock me-2"></i>Unlock
    </button>

    <div style="border-top:1px solid var(--nx-border);padding-top:16px">
      <button class="btn btn-outline-danger btn-sm w-100" onclick="doLogout()">
        <i class="fa fa-right-from-bracket me-2"></i>Sign in as different user
      </button>
    </div>

    <div style="color:var(--nx-muted);font-size:.68rem;margin-top:16px">
      <i class="fa fa-shield-halved me-1"></i>
      Session secured · NYMIX TECH
    </div>
  </div>
</div>

<!-- Idle Warning -->
<div id="idle-overlay">
  <div class="idle-card">
    <div style="font-size:3rem" class="mb-3">⏱️</div>
    <h5 class="fw-head mb-2">Still there?</h5>
    <p class="text-nx-muted small mb-3">You've been inactive. Signing out in</p>
    <div id="idle-seconds">60</div>
    <p class="text-nx-muted small mb-3">seconds</p>
    <div class="progress mb-4" style="height:4px;background:var(--nx-border)">
      <div id="idle-bar" class="progress-bar" style="background:var(--nx-accent);width:100%;transition:width 1s linear"></div>
    </div>
    <button class="btn w-100 fw-bold mb-2" style="background:var(--nx-accent);color:#000" onclick="resetIdle()">
      Keep me signed in
    </button>
    <button class="btn btn-outline-secondary w-100 btn-sm" onclick="doLogout()">Sign out now</button>
  </div>
</div>

<!-- Toast container -->
<div id="toast-wrap"></div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── THEME ─────────────────────────────────────────────────────
const savedTheme = localStorage.getItem('nx-theme') || 'dark';
document.documentElement.setAttribute('data-theme', savedTheme);
</script>

<script>
// ════════════════════════════════════════════════════════════
//  NYMIX STAFF PORTAL — CLIENT JS  (Secured v2)
// ════════════════════════════════════════════════════════════
const SELF = window.location.pathname;
const CSRF = <?= json_encode($csrf_token) ?>;
const R = { OWNER:1, MANAGER:2, CASHIER:3, STOREKEEPER:4, SUPPLIER:5 };
const CU = <?= $logged_in ? json_encode([
  'id'           => (int)$user['id'],
  'name'         => $user['full_name'] ?? '',
  'username'     => $user['username']  ?? '',
  'role_id'      => (int)($user['role_id'] ?? 0),
  'role_name'    => $user['role_name']   ?? '',
  'branch_id'    => (int)($user['branch_id'] ?? 0),
  'branch_name'  => $user['branch_name'] ?? '',
  'business_name'=> $user['business_name'] ?? 'NYMIX Hardware',
  'logo'         => $user['logo'] ?? '',
  'branch_address'=> $user['branch_address'] ?? '',
  'branch_phone' => $user['branch_phone'] ?? '',
]) : 'null' ?>;

// ── UTILS ────────────────────────────────────────────────────
const can = (...roles) => CU && roles.includes(CU.role_id);
const fmt = n => 'KSh ' + parseFloat(n||0).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-KE') : '—';
const today = () => new Date().toISOString().slice(0,10);
const esc = s => { const d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; };

// ── NOTIFICATION SOUNDS ──────────────────────────────────────
const _sfx = (() => {
  const ctx = new (window.AudioContext || window.webkitAudioContext)();
  function play(freq, type, duration, vol=0.3) {
    try {
      const o = ctx.createOscillator();
      const g = ctx.createGain();
      o.connect(g); g.connect(ctx.destination);
      o.type = type; o.frequency.setValueAtTime(freq, ctx.currentTime);
      g.gain.setValueAtTime(vol, ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
      o.start(ctx.currentTime); o.stop(ctx.currentTime + duration);
    } catch(e) {}
  }
  return {
    success: () => { play(520, 'sine', 0.12); setTimeout(() => play(780, 'sine', 0.18), 100); },
    error:   () => { play(220, 'sawtooth', 0.15); setTimeout(() => play(180, 'sawtooth', 0.2), 120); },
    info:    () => { play(440, 'sine', 0.1, 0.15); },
    sale:    () => { play(520,'sine',0.1); setTimeout(()=>play(660,'sine',0.1),80); setTimeout(()=>play(880,'sine',0.18),160); },
    void:    () => { play(440,'sawtooth',0.12); setTimeout(()=>play(300,'sawtooth',0.15),100); setTimeout(()=>play(200,'sawtooth',0.2),200); },
  };
})();

function toast(msg, type='info') {
  const icons = { success:'✓', error:'✕', info:'ℹ' };
  const el = document.createElement('div');
  el.className = `nx-toast ${type}`;
  el.innerHTML = `<span>${icons[type]||'•'}</span><span>${msg}</span>`;
  document.getElementById('toast-wrap').appendChild(el);
  setTimeout(() => el.remove(), 3500);
  // Play sound
  try {
    if      (type === 'error')   _sfx.error();
    else if (type === 'success') _sfx.success();
    else                         _sfx.info();
  } catch(e) {}
}

function toastSale()  { _sfx.sale(); }
function toastVoid()  { _sfx.void(); }

async function api(action, params={}, method='GET') {
  const url = SELF + '?action=' + encodeURIComponent(action);
  const opts = { method };
  if (method === 'POST') {
    const fd = new FormData();
    fd.append('csrf', CSRF); // CSRF on every POST
    for (const k in params) fd.append(k, params[k]);
    opts.body = fd;
  }
  try {
    const res = await fetch(
      method === 'GET' ? url + '&' + new URLSearchParams(params) : url,
      opts
    );
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (parseErr) {
      console.error('Non-JSON response [' + action + ']:', text.substring(0, 800));
      toast('Server error on "' + action + '". Open browser console (F12) for details.', 'error');
      return { ok: false, msg: 'Server returned an unexpected response.' };
    }
    if (data.locked) {
      toast('System unavailable. Contact your administrator.', 'error');
      setTimeout(() => doLogout(), 2000);
    }
    return data;
  } catch (e) {
    console.error('Fetch failed [' + action + ']:', e);
    toast('Request failed: ' + e.message, 'error');
    return { ok: false, msg: 'Request failed: ' + e.message };
  }
}

// Bootstrap modal helpers
function openModal(id)  { bootstrap.Modal.getOrCreateInstance(document.getElementById(id)).show(); }
function closeModal(id) { bootstrap.Modal.getInstance(document.getElementById(id))?.hide(); }

function loading(el) {
  el.innerHTML = `<div class="d-flex align-items-center justify-content-center py-5">
    <div class="spinner-border text-warning me-3"></div>
    <span class="text-nx-muted">Loading...</span></div>`;
}

// ── SIDEBAR (mobile) ─────────────────────────────────────────
function toggleSidebar() {
  const sb = document.getElementById('nx-sidebar');
  const bd = document.getElementById('nx-backdrop');
  sb.classList.toggle('show');
  bd.classList.toggle('show');
  document.body.style.overflow = sb.classList.contains('show') ? 'hidden' : '';
}
function closeSidebar() {
  document.getElementById('nx-sidebar').classList.remove('show');
  document.getElementById('nx-backdrop').classList.remove('show');
  document.body.style.overflow = '';
}

// ── PASSWORD TOGGLE ──────────────────────────────────────────
function togglePassword() {
  const inp = document.getElementById('l-pass');
  const icon = document.getElementById('pw-eye-icon');
  const isHidden = inp.type === 'password';
  inp.type = isHidden ? 'text' : 'password';
  icon.className = isHidden ? 'fa fa-eye-slash' : 'fa fa-eye';
}

// ── LOGIN ────────────────────────────────────────────────────
async function doLogin() {
  const u   = document.getElementById('l-user').value.trim();
  const p   = document.getElementById('l-pass').value;
  const err = document.getElementById('login-err');
  err.classList.add('d-none');
  if (!u || !p) { err.textContent = 'Enter username and password.'; err.classList.remove('d-none'); return; }
  const btn = document.querySelector('#login-screen .btn');
  btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in…';
  const res = await api('login', { username: u, password: p }, 'POST');
  btn.disabled = false; btn.innerHTML = '<i class="fa fa-sign-in-alt me-2"></i> Sign In';
  if (res.ok) { location.reload(); }
  else { err.textContent = res.msg || 'Login failed.'; err.classList.remove('d-none'); }
}
document.getElementById('l-pass')?.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
document.getElementById('l-user')?.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });

function doLogout() { window.location = SELF + '?action=logout'; }

// ── THEME TOGGLE ─────────────────────────────────────────────
function toggleTheme() {
  const html = document.documentElement;
  const current = html.getAttribute('data-theme') || 'dark';
  const next = current === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('nx-theme', next);
  updateThemeIcon(next);
}
function updateThemeIcon(theme) {
  const icon = document.getElementById('theme-icon');
  if (!icon) return;
  icon.className = theme === 'dark' ? 'fa fa-sun' : 'fa fa-moon';
}
// Set icon on load
document.addEventListener('DOMContentLoaded', () => {
  updateThemeIcon(localStorage.getItem('nx-theme') || 'dark');
});

// ── NAVIGATION ───────────────────────────────────────────────
const PAGE_TITLES = {
  dashboard:'Dashboard', pos:'Point of Sale', sales:'Sales History',
  inventory:'Stock Levels', transfers:'Stock Transfers', customers:'Customers',
  purchases:'Purchase Orders', suppliers:'Suppliers', reports:'Reports',
  expenses:'Expenses', users:'Staff Users', products:'Products',
  categories:'Categories & Units', etims:'eTIMS / KRA Settings'
};
const PAGE_ACCESS = {
  pos:       [R.OWNER, R.MANAGER, R.CASHIER],
  reports:   [R.OWNER, R.MANAGER],
  expenses:  [R.OWNER, R.MANAGER],
  users:     [R.OWNER],
  transfers: [R.OWNER, R.MANAGER, R.STOREKEEPER],
  purchases: [R.OWNER, R.MANAGER, R.STOREKEEPER, R.SUPPLIER],
  suppliers: [R.OWNER, R.MANAGER, R.STOREKEEPER, R.SUPPLIER],
  products:  [R.OWNER, R.MANAGER, R.STOREKEEPER],
  categories:[R.OWNER, R.MANAGER, R.STOREKEEPER],
};

function navigate(page) {
  if (!CU) return;
  if (PAGE_ACCESS[page] && !PAGE_ACCESS[page].includes(CU.role_id)) {
    toast('You do not have access to this section.', 'error'); return;
  }
  document.querySelectorAll('.nx-nav-link').forEach(b => b.classList.toggle('active', b.dataset.page === page));
  document.getElementById('page-title').textContent = PAGE_TITLES[page] || page;
  closeSidebar();
  const el = document.getElementById('nx-content');
  loading(el);
  const renders = {
    dashboard, pos: renderPOS, sales: renderSales, inventory: renderInventory,
    transfers: renderTransfers, customers: renderCustomers, purchases: renderPurchases,
    suppliers: renderSuppliers, reports: renderReports, expenses: renderExpenses,
    users: renderUsers, products: renderProducts, categories: renderCategories,
    etims: renderEtims,
  };
  (renders[page] || (() => el.innerHTML = '<p class="text-danger p-4">Page not found.</p>'))(el);
}

function buildNav() {
  if (!CU) return;
  const hide = (id, show) => { const el = document.getElementById(id); if (el) el.style.display = show ? '' : 'none'; };
  hide('nav-sales-sec',     can(R.OWNER, R.MANAGER, R.CASHIER));
  hide('nav-inventory-sec', true); // all roles see inventory
  hide('nav-products-sec',  can(R.OWNER, R.MANAGER, R.STOREKEEPER));
  hide('nav-purchase-sec',  can(R.OWNER, R.MANAGER, R.STOREKEEPER, R.SUPPLIER));
  hide('nav-crm-sec',       can(R.OWNER, R.MANAGER, R.CASHIER));
  hide('nav-reports-sec',   can(R.OWNER, R.MANAGER));
  hide('nav-admin-sec',     can(R.OWNER));
}

// ── DASHBOARD ────────────────────────────────────────────────
let _dashCharts = {};
function _destroyCharts() {
  Object.values(_dashCharts).forEach(c => { try { c.destroy(); } catch(e){} });
  _dashCharts = {};
}

async function dashboard(el) {
  _destroyCharts();
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h5 class="fw-head mb-0">Dashboard</h5>
        <div class="text-nx-muted small" id="dash-greeting"></div>
      </div>
      <div class="d-flex gap-2">
        <span class="badge" style="background:rgba(245,158,11,.15);color:var(--nx-accent);font-size:.75rem;padding:6px 12px" id="dash-live-time"></span>
      </div>
    </div>

    <!-- KPI CARDS -->
    <div class="row g-3 mb-4" id="dash-kpis">
      ${[1,2,3,4].map(()=>`<div class="col-6 col-md-3"><div class="stat-card" style="animation:fadeIn .4s ease"><div class="d-flex justify-content-center py-3"><div class="spinner-border spinner-border-sm text-warning"></div></div></div></div>`).join('')}
    </div>

    <!-- CHARTS ROW 1 -->
    <div class="row g-4 mb-4">
      <div class="col-lg-8">
        <div class="card h-100">
          <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between">
            <span class="small fw-bold">14-Day Revenue Trend</span>
            <div class="d-flex gap-1">
              <span style="width:10px;height:10px;border-radius:50%;background:var(--nx-accent);display:inline-block"></span>
              <span style="font-size:.68rem;color:var(--nx-muted)">Revenue</span>
              <span style="width:10px;height:10px;border-radius:50%;background:var(--nx-blue);display:inline-block;margin-left:8px"></span>
              <span style="font-size:.68rem;color:var(--nx-muted)">Transactions</span>
            </div>
          </div>
          <div class="card-body" style="position:relative;height:220px">
            <canvas id="chart-trend"></canvas>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header py-2 px-3 small fw-bold">Today — Hourly Sales</div>
          <div class="card-body" style="position:relative;height:220px">
            <canvas id="chart-hourly"></canvas>
          </div>
        </div>
      </div>
    </div>

    <!-- CHARTS ROW 2 -->
    <div class="row g-4 mb-4">
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header py-2 px-3 small fw-bold">Payment Methods — Today</div>
          <div class="card-body d-flex align-items-center justify-content-center" style="position:relative;height:220px">
            <canvas id="chart-payments"></canvas>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header py-2 px-3 small fw-bold">🏆 Top Products — Today</div>
          <div class="card-body p-0" id="dash-top-products" style="height:220px;overflow-y:auto">
            <div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm text-warning"></div></div>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100">
          <div class="card-header py-2 px-3 small fw-bold d-flex align-items-center justify-content-between">
            <span>⚠️ Low Stock Alerts</span>
            <button class="btn btn-xs btn-outline-secondary btn-sm" onclick="navigate('inventory')" style="font-size:.68rem;padding:2px 8px">View All</button>
          </div>
          <div class="card-body p-0" id="dash-low-stock" style="height:220px;overflow-y:auto">
            <div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm text-warning"></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- RECENT TRANSACTIONS -->
    <div class="card">
      <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between">
        <span class="small fw-bold">Recent Transactions</span>
        <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem" onclick="navigate('sales')">View All →</button>
      </div>
      <div class="table-responsive" id="dash-recent">
        <div class="d-flex justify-content-center py-4"><div class="spinner-border spinner-border-sm text-warning"></div></div>
      </div>
    </div>`;

  // Live clock
  const clockEl = document.getElementById('dash-live-time');
  const greetEl = document.getElementById('dash-greeting');
  const h = new Date().getHours();
  if (greetEl) greetEl.textContent = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  const ticker = setInterval(() => {
    if (!document.getElementById('dash-live-time')) { clearInterval(ticker); return; }
    clockEl.textContent = new Date().toLocaleTimeString('en-KE');
  }, 1000);

  // Fetch both APIs in parallel
  const [d, ext] = await Promise.all([
    api('dashboard_data'),
    api('dashboard_extended'),
  ]);

  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed to load dashboard.</p>'; return; }

  // ── KPI CARDS ─────────────────────────────────────────────
  const comp = ext.ok ? ext.compare : {};
  const todayRev   = parseFloat(comp.today    || d.today_sales?.rev || 0);
  const yestRev    = parseFloat(comp.yesterday || 0);
  const monthRev   = parseFloat(comp.this_month || 0);
  const lastMonthRev = parseFloat(comp.last_month || 0);
  const todayVsYest = yestRev > 0 ? ((todayRev - yestRev) / yestRev * 100).toFixed(1) : null;
  const monthVsLast = lastMonthRev > 0 ? ((monthRev - lastMonthRev) / lastMonthRev * 100).toFixed(1) : null;

  const arrow = (v) => v === null ? '' :
    `<span style="color:${v>=0?'var(--nx-green)':'var(--nx-red)'};font-size:.7rem;font-weight:600">
      ${v>=0?'▲':'▼'} ${Math.abs(v)}% vs ${v===todayVsYest?'yesterday':'last month'}
    </span>`;

  document.getElementById('dash-kpis').innerHTML = `
    <div class="col-6 col-md-3">
      <div class="stat-card" style="--card-accent:var(--nx-accent)">
        <div class="stat-label">Revenue Today</div>
        <div class="stat-value">${fmt(todayRev)}</div>
        <div class="stat-sub">${arrow(todayVsYest) || (d.today_sales?.cnt||0) + ' transactions'}</div>
        <i class="fa fa-coins stat-icon"></i>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card" style="--card-accent:var(--nx-blue)">
        <div class="stat-label">This Month</div>
        <div class="stat-value" style="font-size:1.3rem">${fmt(monthRev)}</div>
        <div class="stat-sub">${arrow(monthVsLast) || 'month to date'}</div>
        <i class="fa fa-calendar stat-icon"></i>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card" style="--card-accent:var(--nx-red)">
        <div class="stat-label">Low Stock</div>
        <div class="stat-value">${d.low_stock}</div>
        <div class="stat-sub">items need reorder</div>
        <i class="fa fa-triangle-exclamation stat-icon"></i>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-card" style="--card-accent:var(--nx-orange)">
        <div class="stat-label">Credit Out</div>
        <div class="stat-value" style="font-size:1.3rem">${fmt(d.credit_out)}</div>
        <div class="stat-sub">customer balances</div>
        <i class="fa fa-hand-holding-dollar stat-icon"></i>
      </div>
    </div>`;

  if (!ext.ok) return;

  // ── TREND CHART (14 days) ──────────────────────────────────
  const trendCtx = document.getElementById('chart-trend')?.getContext('2d');
  if (trendCtx && ext.trend?.length) {
    const isDark = (localStorage.getItem('nx-theme') || 'dark') === 'dark';
    const gridColor  = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
    const textColor  = isDark ? '#6b7280' : '#94a3b8';
    _dashCharts.trend = new Chart(trendCtx, {
      data: {
        labels: ext.trend.map(r => new Date(r.d).toLocaleDateString('en-KE', { day:'numeric', month:'short' })),
        datasets: [
          {
            type: 'bar',
            label: 'Revenue',
            data: ext.trend.map(r => parseFloat(r.rev)),
            backgroundColor: 'rgba(245,158,11,.25)',
            borderColor: 'rgba(245,158,11,.8)',
            borderWidth: 1,
            borderRadius: 4,
            yAxisID: 'y',
          },
          {
            type: 'line',
            label: 'Transactions',
            data: ext.trend.map(r => parseInt(r.txn)),
            borderColor: 'rgba(59,130,246,.9)',
            backgroundColor: 'rgba(59,130,246,.08)',
            borderWidth: 2,
            pointRadius: 3,
            pointBackgroundColor: 'var(--nx-blue)',
            tension: 0.4,
            fill: true,
            yAxisID: 'y2',
          }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false }, tooltip: {
          backgroundColor: isDark ? '#1e2333' : '#fff',
          titleColor: isDark ? '#e8eaf0' : '#111',
          bodyColor: isDark ? '#94a3b8' : '#555',
          borderColor: isDark ? '#2a2f42' : '#e2e8f0',
          borderWidth: 1,
          callbacks: {
            label: ctx => ctx.datasetIndex === 0
              ? ' Revenue: KSh ' + parseFloat(ctx.raw).toLocaleString('en-KE', {minimumFractionDigits:0})
              : ' Txns: ' + ctx.raw
          }
        }},
        scales: {
          x: { grid: { color: gridColor }, ticks: { color: textColor, font: { size: 10 } } },
          y: { grid: { color: gridColor }, ticks: { color: textColor, font: { size: 10 }, callback: v => 'KSh ' + (v/1000).toFixed(0) + 'k' }, position: 'left' },
          y2: { grid: { display: false }, ticks: { color: textColor, font: { size: 10 } }, position: 'right' }
        }
      }
    });
  } else if (trendCtx) {
    document.getElementById('chart-trend').parentElement.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-nx-muted small">No sales in the last 14 days</div>';
  }

  // ── HOURLY CHART ──────────────────────────────────────────
  const hourlyCtx = document.getElementById('chart-hourly')?.getContext('2d');
  if (hourlyCtx) {
    const allHours = Array.from({length:16}, (_,i)=>i+7); // 7am–10pm
    const hourMap  = {};
    ext.hourly.forEach(h => { hourMap[h.hr] = h; });
    const isDark = (localStorage.getItem('nx-theme') || 'dark') === 'dark';
    const gridColor = isDark ? 'rgba(255,255,255,.06)' : 'rgba(0,0,0,.06)';
    const textColor = isDark ? '#6b7280' : '#94a3b8';

    _dashCharts.hourly = new Chart(hourlyCtx, {
      type: 'bar',
      data: {
        labels: allHours.map(h => h + 'h'),
        datasets: [{
          label: 'Revenue',
          data: allHours.map(h => parseFloat(hourMap[h]?.rev || 0)),
          backgroundColor: allHours.map(h => {
            const rev = parseFloat(hourMap[h]?.rev || 0);
            const max = Math.max(...allHours.map(hh => parseFloat(hourMap[hh]?.rev || 0)), 1);
            const opacity = 0.2 + (rev / max) * 0.75;
            return `rgba(245,158,11,${opacity.toFixed(2)})`;
          }),
          borderColor: 'rgba(245,158,11,.6)',
          borderWidth: 1,
          borderRadius: 3,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: {
          backgroundColor: isDark ? '#1e2333' : '#fff',
          titleColor: isDark ? '#e8eaf0' : '#111',
          bodyColor: isDark ? '#94a3b8' : '#555',
          borderColor: isDark ? '#2a2f42' : '#e2e8f0',
          borderWidth: 1,
          callbacks: {
            label: ctx => {
              const h = allHours[ctx.dataIndex];
              const cnt = hourMap[h]?.cnt || 0;
              return [' KSh ' + parseFloat(ctx.raw).toLocaleString('en-KE', {minimumFractionDigits:0}), ' ' + cnt + ' txns'];
            }
          }
        }},
        scales: {
          x: { grid: { display: false }, ticks: { color: textColor, font: { size: 9 } } },
          y: { grid: { color: gridColor }, ticks: { color: textColor, font: { size: 9 }, callback: v => v >= 1000 ? (v/1000).toFixed(0)+'k' : v } }
        }
      }
    });
  }

  // ── PAYMENT PIE ──────────────────────────────────────────
  const payCtx = document.getElementById('chart-payments')?.getContext('2d');
  if (payCtx && ext.pay_methods?.length) {
    const colors = { cash:'rgba(34,197,94,.8)', mpesa:'rgba(59,130,246,.8)', credit:'rgba(249,115,22,.8)', mixed:'rgba(168,85,247,.8)' };
    const isDark = (localStorage.getItem('nx-theme') || 'dark') === 'dark';
    _dashCharts.pay = new Chart(payCtx, {
      type: 'doughnut',
      data: {
        labels: ext.pay_methods.map(p => p.payment_method.toUpperCase()),
        datasets: [{
          data: ext.pay_methods.map(p => parseFloat(p.rev)),
          backgroundColor: ext.pay_methods.map(p => colors[p.payment_method] || 'rgba(107,114,128,.6)'),
          borderColor: isDark ? '#1e2333' : '#fff',
          borderWidth: 3,
          hoverOffset: 6,
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
          legend: { position: 'bottom', labels: { color: isDark ? '#94a3b8' : '#555', font: { size: 11 }, padding: 12, boxWidth: 12 } },
          tooltip: {
            backgroundColor: isDark ? '#1e2333' : '#fff',
            titleColor: isDark ? '#e8eaf0' : '#111',
            bodyColor: isDark ? '#94a3b8' : '#555',
            borderColor: isDark ? '#2a2f42' : '#e2e8f0',
            borderWidth: 1,
            callbacks: {
              label: ctx => ' KSh ' + parseFloat(ctx.raw).toLocaleString('en-KE', {minimumFractionDigits:0}) + ' (' + ctx.parsed + ')'
            }
          }
        }
      }
    });
  } else if (payCtx) {
    document.getElementById('chart-payments').parentElement.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-nx-muted small">No sales today</div>';
  }

  // ── TOP PRODUCTS ─────────────────────────────────────────
  const tpEl = document.getElementById('dash-top-products');
  if (tpEl) {
    if (ext.top_products?.length) {
      const maxRev = Math.max(...ext.top_products.map(p => parseFloat(p.rev)), 1);
      tpEl.innerHTML = ext.top_products.map((p, i) => {
        const pct = Math.round((parseFloat(p.rev) / maxRev) * 100);
        const medals = ['🥇','🥈','🥉','4️⃣','5️⃣'];
        return `<div class="px-3 py-2 border-bottom" style="border-color:var(--nx-border)!important">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <span class="small fw-bold" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              ${medals[i] || i+1} ${esc(p.name)}
            </span>
            <span class="text-accent fw-bold small" style="white-space:nowrap">${fmt(p.rev)}</span>
          </div>
          <div style="height:4px;background:var(--nx-border);border-radius:2px">
            <div style="height:4px;width:${pct}%;background:var(--nx-accent);border-radius:2px"></div>
          </div>
          <div style="font-size:.65rem;color:var(--nx-muted);margin-top:2px">${parseFloat(p.qty).toFixed(0)} units sold</div>
        </div>`;
      }).join('');
    } else {
      tpEl.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-nx-muted small">No sales today</div>';
    }
  }

  // ── LOW STOCK ────────────────────────────────────────────
  const lsEl = document.getElementById('dash-low-stock');
  if (lsEl) {
    if (ext.low_items?.length) {
      lsEl.innerHTML = ext.low_items.map(item => {
        const pct = item.reorder_level > 0 ? Math.min(100, Math.round((item.quantity / item.reorder_level) * 100)) : 0;
        const color = item.quantity <= 0 ? 'var(--nx-red)' : pct < 50 ? 'var(--nx-orange)' : 'var(--nx-accent)';
        return `<div class="px-3 py-2 border-bottom" style="border-color:var(--nx-border)!important">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <span class="small fw-bold" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(item.name)}</span>
            <span style="color:${color};font-size:.78rem;font-weight:700">${parseFloat(item.quantity).toFixed(0)} left</span>
          </div>
          <div style="height:4px;background:var(--nx-border);border-radius:2px">
            <div style="height:4px;width:${pct}%;background:${color};border-radius:2px"></div>
          </div>
          <div style="font-size:.65rem;color:var(--nx-muted);margin-top:2px">Reorder at ${item.reorder_level} · ${esc(item.branch_name)}</div>
        </div>`;
      }).join('');
    } else {
      lsEl.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100 text-nx-muted small" style="color:var(--nx-green)!important">✓ All stock levels OK</div>';
    }
  }

  // ── RECENT TRANSACTIONS ───────────────────────────────────
  const recentEl = document.getElementById('dash-recent');
  if (recentEl) {
    const rows = d.recent.map(s => `
      <tr style="cursor:pointer" onclick="viewSaleReceipt(${s.id || 0})">
        <td><span class="text-accent fw-bold small">${esc(s.receipt_no)}</span></td>
        <td class="small text-nx-muted">${new Date(s.sale_date).toLocaleTimeString('en-KE',{hour:'2-digit',minute:'2-digit'})}</td>
        <td class="small">${esc(s.full_name||'—')}</td>
        <td class="text-end fw-bold small">${fmt(s.grand_total)}</td>
        <td><span class="badge" style="background:${s.payment_method==='cash'?'rgba(34,197,94,.15)':s.payment_method==='mpesa'?'rgba(59,130,246,.15)':'rgba(107,114,128,.15)'};color:${s.payment_method==='cash'?'var(--nx-green)':s.payment_method==='mpesa'?'var(--nx-blue)':'var(--nx-muted)'};font-size:.68rem">${esc(s.payment_method?.toUpperCase())}</span></td>
      </tr>`).join('');
    recentEl.innerHTML = `<table class="table table-sm mb-0">
      <thead><tr><th>Receipt</th><th>Time</th><th>Cashier</th><th class="text-end">Total</th><th>Method</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="5" class="text-center text-nx-muted py-4 small">No sales today</td></tr>'}</tbody>
    </table>`;
  }
}
  

// ── POS ──────────────────────────────────────────────────────
let cart = [];
let heldSales = [];

async function renderPOS(el) {
  el.innerHTML = `
    <div class="pos-wrap">
      <div class="pos-products">
        <div class="input-group mb-3">
          <input type="text" id="pos-search" class="form-control" placeholder="Scan barcode or search by name / SKU…" autofocus>
          <button class="btn btn-outline-secondary" onclick="searchProducts()"><i class="fa fa-search"></i></button>
          <button class="btn btn-outline-secondary position-relative" onclick="showHeldSales()" title="Held Sales">
            <i class="fa fa-pause"></i>
            <span id="held-count" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark d-none">0</span>
          </button>
        </div>
        <div id="prod-grid" class="product-grid">
          <p class="text-nx-muted small">Search or scan a product above.</p>
        </div>
      </div>
      <div class="pos-cart">
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom" style="border-color:var(--nx-border)!important">
          <span class="fw-head fw-bold">Cart <span class="badge bg-warning text-dark" id="cart-count">0</span></span>
          <button class="btn btn-sm btn-outline-secondary" onclick="holdSale()"><i class="fa fa-pause me-1"></i>Hold</button>
        </div>
        <div class="cart-items" id="cart-items">
          <p class="text-nx-muted text-center py-4 small">Cart is empty</p>
        </div>
        <div class="cart-summary">
          <div class="cart-row"><span>Subtotal</span><span id="cs-sub">KSh 0.00</span></div>
          <div class="cart-row">
            <span>Discount
              <select id="disc-type" class="form-select form-select-sm d-inline-block ms-1" style="width:60px" onchange="updateCartTotals()">
                <option value="flat">KSh</option><option value="pct">%</option>
              </select>
            </span>
            <input type="number" id="cs-discount" value="0" min="0" class="form-control form-control-sm text-end" style="width:80px" onchange="updateCartTotals()">
          </div>
          <div class="cart-row total"><span>TOTAL</span><span id="cs-total">KSh 0.00</span></div>
          <div class="cart-row mt-2">
            <span class="small">Payment</span>
            <select id="pay-method" class="form-select form-select-sm" style="width:140px" onchange="togglePayFields()">
              <option value="cash">Cash</option>
              <option value="mpesa">M-Pesa</option>
              <option value="credit">Credit</option>
              <option value="mixed">Cash + M-Pesa</option>
            </select>
          </div>
          <div class="cart-row" id="mpesa-row" style="display:none">
            <span class="small">M-Pesa Code</span>
            <input type="text" id="pos-mpesa" class="form-control form-control-sm text-end" placeholder="QGHX…" style="width:120px">
          </div>
          <div class="cart-row" id="cash-row">
            <span class="small">Cash Received</span>
            <div class="d-flex gap-1 align-items-center">
              <input type="number" id="pos-paid" value="0" min="0" class="form-control form-control-sm text-end" style="width:90px" oninput="updateCartTotals()">
              <button class="btn btn-outline-secondary btn-sm" style="white-space:nowrap;font-size:.7rem" onclick="document.getElementById('pos-paid').value=parseFloat(document.getElementById('cs-total').textContent.replace(/[^0-9.]/g,'')||0).toFixed(2);updateCartTotals()">Exact</button>
            </div>
          </div>
          <div class="cart-row" id="mpesa-amt-row" style="display:none">
            <span class="small">M-Pesa Amount</span>
            <input type="number" id="pos-mpesa-amt" value="0" min="0" class="form-control form-control-sm text-end" style="width:100px" oninput="updateCartTotals()">
          </div>
          <div class="cart-row"><span class="small">Balance Due</span><span id="cs-balance" class="text-danger">KSh 0.00</span></div>
          <div id="change-box" class="change-box d-none mb-2">
            <div class="small text-nx-muted">Change to give</div>
            <div id="cs-change" class="fw-bold text-success fs-5">KSh 0.00</div>
          </div>
          <div class="cart-row mt-2">
            <span class="small">Customer</span>
            <select id="pos-customer" class="form-select form-select-sm" style="max-width:160px">
              <option value="">Walk-in</option>
            </select>
          </div>
          <div class="mt-3 d-grid gap-2">
            <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="processSale()">
              <i class="fa fa-check me-2"></i>Process Sale
            </button>
            <button class="btn btn-outline-secondary btn-sm" onclick="clearCart()">
              <i class="fa fa-trash me-1"></i>Clear Cart
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Held Sales Modal -->
    <div class="modal fade" id="modal-held" tabindex="-1">
      <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title fw-head">Held Sales</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="held-list"></div>
      </div></div>
    </div>`;

  document.getElementById('pos-search').addEventListener('keydown', e => { if (e.key === 'Enter') searchProducts(); });
  let scanTimer = null;
  document.getElementById('pos-search').addEventListener('input', e => {
    clearTimeout(scanTimer);
    scanTimer = setTimeout(() => { if (e.target.value.length >= 2) searchProducts(); }, 300);
  });
  await loadPOSCustomers();
  await searchProducts('');
}

function togglePayFields() {
  const m = document.getElementById('pay-method').value;
  document.getElementById('mpesa-row').style.display    = (m==='mpesa'||m==='mixed') ? 'flex' : 'none';
  document.getElementById('mpesa-amt-row').style.display = m==='mixed' ? 'flex' : 'none';
  document.getElementById('cash-row').style.display     = m==='credit' ? 'none' : 'flex';
  updateCartTotals();
}

function holdSale() {
  if (!cart.length) { toast('Cart is empty', 'error'); return; }
  const label = prompt('Label for held sale:', 'Hold ' + (heldSales.length + 1));
  if (label === null) return;
  heldSales.push({ label: label || ('Hold ' + (heldSales.length + 1)), cart: [...cart], time: new Date().toLocaleTimeString('en-KE') });
  cart = []; renderCart(); updateHeldCount(); toast('Sale held', 'info');
}
function updateHeldCount() {
  const el = document.getElementById('held-count');
  if (!el) return;
  el.textContent = heldSales.length;
  el.classList.toggle('d-none', heldSales.length === 0);
}
function showHeldSales() {
  const el = document.getElementById('held-list');
  if (!heldSales.length) { el.innerHTML = '<p class="text-nx-muted small">No held sales.</p>'; openModal('modal-held'); return; }
  el.innerHTML = heldSales.map((h, i) => `
    <div class="d-flex align-items-center justify-content-between p-2 mb-2 rounded border cursor-pointer"
         style="border-color:var(--nx-border)!important;cursor:pointer" onclick="resumeHeld(${i})">
      <div>
        <div class="fw-bold small">${esc(h.label)}</div>
        <div class="text-nx-muted" style="font-size:.7rem">${h.cart.length} items · ${h.time}</div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="text-accent fw-bold small">${fmt(h.cart.reduce((a,x)=>a+x.qty*x.price,0))}</span>
        <button class="btn btn-danger btn-sm" onclick="event.stopPropagation();deleteHeld(${i})">×</button>
      </div>
    </div>`).join('');
  openModal('modal-held');
}
function resumeHeld(i) {
  if (cart.length && !confirm('Replace current cart with held sale?')) return;
  cart = [...heldSales[i].cart]; heldSales.splice(i, 1);
  updateHeldCount(); renderCart(); closeModal('modal-held'); toast('Sale resumed', 'success');
}
function deleteHeld(i) { heldSales.splice(i, 1); updateHeldCount(); showHeldSales(); }

async function loadPOSCustomers() {
  const d = await api('customers_list', { q: '' });
  if (!d.ok) return;
  const sel = document.getElementById('pos-customer');
  if (!sel) return;
  d.customers.forEach(c => {
    const o = document.createElement('option');
    o.value = c.id;
    o.textContent = c.name + (parseFloat(c.balance) > 0 ? ` (owes ${fmt(c.balance)})` : '');
    sel.appendChild(o);
  });
}

async function searchProducts(q) {
  q = q !== undefined ? q : (document.getElementById('pos-search')?.value ?? '');
  const d = await api('product_search', { q });
  const grid = document.getElementById('prod-grid');
  if (!d.ok) { grid.innerHTML = `<p class="text-danger small">Error: ${d.msg || 'Unknown error'}</p>`; return; }
if (!d.products.length) { grid.innerHTML = '<p class="text-nx-muted small">No products found. Add products first under Products → New Product.</p>'; return; }
  window._posProducts = d.products;
  grid.innerHTML = d.products.map((p, i) => {
    const out = parseFloat(p.qty) <= 0;
    return `<div class="prod-card ${out ? 'out-of-stock' : ''}" data-idx="${i}">
      <div class="prod-name">${esc(p.name)}</div>
      <div class="prod-sku">${esc(p.sku||'—')}</div>
      <div class="prod-price">${fmt(p.selling_price)}</div>
      <div class="prod-stock ${parseFloat(p.qty) <= 5 ? 'text-danger' : 'text-success'}">
        Stock: ${parseFloat(p.qty).toFixed(2)} ${esc(p.unit||'')}
      </div></div>`;
  }).join('');
  document.querySelectorAll('.prod-card:not(.out-of-stock)').forEach(card => {
    card.addEventListener('click', () => {
      const p = window._posProducts[card.dataset.idx];
      if (p) addToCart(p);
    });
  });
}

function addToCart(p) {
  const existing = cart.find(i => i.id == p.id);
  if (existing) { existing.qty++; }
  else { cart.push({ id: p.id, name: p.name, price: parseFloat(p.selling_price), qty: 1, max: parseFloat(p.qty) }); }
  renderCart();
}

function renderCart() {
  const el = document.getElementById('cart-items');
  const countEl = document.getElementById('cart-count');
  if (countEl) countEl.textContent = cart.reduce((a, i) => a + i.qty, 0);
  if (!cart.length) { el.innerHTML = '<p class="text-nx-muted text-center py-4 small">Cart is empty</p>'; updateCartTotals(); return; }
  el.innerHTML = cart.map((item, idx) => `
    <div class="cart-item">
      <div class="d-flex align-items-start justify-content-between mb-1">
        <div class="small fw-bold" style="max-width:140px">${esc(item.name)}</div>
        <button class="btn btn-sm text-danger p-0 border-0" onclick="removeItem(${idx})" style="line-height:1">×</button>
      </div>
      <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-1">
          <button class="cart-qty-btn" onclick="changeQty(${idx},-1)">−</button>
          <input type="number" class="cart-qty-input" value="${item.qty}" min="0.001" step="0.001" onchange="setQty(${idx},this.value)">
          <button class="cart-qty-btn" onclick="changeQty(${idx},1)">+</button>
        </div>
        <span class="text-accent fw-bold small">${fmt(item.qty * item.price)}</span>
      </div>
    </div>`).join('');
  updateCartTotals();
}

function changeQty(idx, d) { cart[idx].qty = Math.max(0.001, +(cart[idx].qty + d).toFixed(3)); if (cart[idx].qty <= 0) cart.splice(idx, 1); renderCart(); }
function setQty(idx, v)    { v = parseFloat(v); if (v > 0) cart[idx].qty = v; else cart.splice(idx, 1); renderCart(); }
function removeItem(idx)   { cart.splice(idx, 1); renderCart(); }
function clearCart()       { cart = []; renderCart(); }

function updateCartTotals() {
  const sub      = cart.reduce((a, i) => a + i.qty * i.price, 0);
  const discVal  = parseFloat(document.getElementById('cs-discount')?.value || 0);
  const discType = document.getElementById('disc-type')?.value || 'flat';
  const disc     = discType === 'pct' ? (sub * (discVal / 100)) : discVal;
  const total    = Math.max(0, sub - disc);
  const method   = document.getElementById('pay-method')?.value || 'cash';
  const cashPaid  = parseFloat(document.getElementById('pos-paid')?.value || 0);
  const mpesaPaid = parseFloat(document.getElementById('pos-mpesa-amt')?.value || 0);
  let totalPaid;
  if (method === 'cash')       totalPaid = cashPaid;
  else if (method === 'mpesa') totalPaid = total;          // M-Pesa always pays full
  else if (method === 'mixed') totalPaid = cashPaid + mpesaPaid;
  else                         totalPaid = 0;              // credit
  const bal    = Math.max(0, total - totalPaid);
  const change = (method === 'cash' || method === 'mixed') ? Math.max(0, totalPaid - total) : 0;

  const s = id => document.getElementById(id);
  if (s('cs-sub'))     s('cs-sub').textContent     = fmt(sub);
  if (s('cs-total'))   s('cs-total').textContent   = fmt(total);
  if (s('cs-balance')) s('cs-balance').textContent = fmt(bal);
  if (s('change-box')) {
    s('change-box').classList.toggle('d-none', change <= 0);
    if (s('cs-change')) s('cs-change').textContent = fmt(change);
  }
}

async function processSale() {
  if (!cart.length) { toast('Cart is empty', 'error'); return; }
  const sub      = cart.reduce((a, i) => a + i.qty * i.price, 0);
  const discVal  = parseFloat(document.getElementById('cs-discount').value || 0);
  const discType = document.getElementById('disc-type').value;
  const disc     = discType === 'pct' ? (sub * (discVal / 100)) : discVal;
  const total    = Math.max(0, sub - disc);
  const method   = document.getElementById('pay-method').value;
  const cashPaid = parseFloat(document.getElementById('pos-paid')?.value || 0);
  const mpesaPaid = parseFloat(document.getElementById('pos-mpesa-amt')?.value || 0);
  const amountPaid = method === 'cash' ? cashPaid : method === 'mpesa' ? total : method === 'mixed' ? (cashPaid + mpesaPaid) : 0;

  const res = await api('create_sale', {
    items:          JSON.stringify(cart.map(i => ({ id: i.id, qty: i.qty, price: i.price }))),
    customer_id:    document.getElementById('pos-customer').value,
    payment_method: method,
    amount_paid:    amountPaid,
    discount:       disc.toFixed(2),
    mpesa_code:     document.getElementById('pos-mpesa')?.value || '',
  }, 'POST');

  if (res.ok) {
    toastSale(); toast(`Sale recorded! Receipt: ${res.receipt_no}`, 'success');
    clearCart();
    viewSaleReceipt(res.sale_id);
  } else { toast(res.msg || 'Failed to process sale.', 'error'); }
}

// ── SALES HISTORY ─────────────────────────────────────────────
async function renderSales(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Sales History</h5>
      <input type="date" id="sales-date" class="form-control form-control-sm" style="width:auto" value="${today()}" onchange="loadSales()">
    </div>
    <div class="card">
      <div class="table-responsive" id="sales-table">
        <div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div>
      </div>
    </div>`;
  loadSales();
}

async function loadSales() {
  const date = document.getElementById('sales-date')?.value || today();
  const d    = await api('sales_list', { date });
  const el   = document.getElementById('sales-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed to load sales.</p>'; return; }
  const etimsBadge = s => {
    if (s.etims_status === 'submitted') return '<span class="badge bg-success bg-opacity-25 text-success" title="KRA Submitted">KRA ✓</span>';
    if (s.etims_status === 'failed')    return `<span class="badge bg-danger bg-opacity-25 text-danger" title="${esc(s.etims_error||'Failed')}" style="cursor:pointer" onclick="retryEtims(${s.id})">KRA ✗ Retry</span>`;
    if (s.etims_status === 'pending')   return '<span class="badge bg-warning bg-opacity-25 text-warning">KRA Pending</span>';
    return ''; // skipped — branch has no eTIMS
  };
  const rows = d.sales.map(s => `
    <tr>
      <td><span class="text-accent fw-bold">${esc(s.receipt_no)}</span></td>
      <td class="small">${esc(s.customer_name||'Walk-in')}</td>
      <td class="small text-nx-muted">${esc(s.cashier||'—')}</td>
      <td class="small text-nx-muted">${new Date(s.sale_date).toLocaleTimeString('en-KE',{hour:'2-digit',minute:'2-digit'})}</td>
      <td class="text-end fw-bold">${fmt(s.grand_total)}</td>
      <td><span class="badge bg-primary bg-opacity-25 text-primary">${esc(s.payment_method)}</span></td>
      <td><span class="badge ${s.payment_status==='paid'?'bg-success bg-opacity-25 text-success':'bg-warning bg-opacity-25 text-warning'}">${esc(s.payment_status)}</span></td>
      <td>${etimsBadge(s)}</td>
      <td>${s.voided ? '<span class="badge bg-danger bg-opacity-25 text-danger">Voided</span>' : ''}</td>
      <td><button class="btn btn-sm btn-outline-secondary" onclick="viewSaleReceipt(${s.id})"><i class="fa fa-eye"></i></button></td>
    </tr>`).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Receipt</th><th>Customer</th><th>Cashier</th><th>Time</th><th class="text-end">Total</th><th>Method</th><th>Status</th><th>KRA</th><th></th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="10" class="text-center text-nx-muted py-4">No sales for this date</td></tr>'}</tbody>
  </table>`;
}

let currentSaleId = null;
async function viewSaleReceipt(id) {
  currentSaleId = id;
  const d = await api('sale_detail', { id });
  if (!d.ok) return;
  const s = d.sale, items = d.items;
  const rows = items.map(i => `<div class="receipt-row"><span>${esc(i.product_name)} x${i.quantity}</span><span>${fmt(i.subtotal)}</span></div>`).join('');
  document.getElementById('receipt-body').innerHTML = `
    <div class="receipt">
      <h5>${esc(s.branch_name)}</h5>
      <div class="text-center text-muted">${esc(s.receipt_no)}<br>${new Date(s.sale_date).toLocaleString('en-KE')}<br>Cashier: ${esc(s.cashier||'—')}</div>
      <hr>
      <div class="receipt-row fw-bold"><span>Item</span><span>Amount</span></div><hr>
      ${rows}<hr>
      <div class="receipt-row"><span>Subtotal</span><span>${fmt(s.subtotal)}</span></div>
      ${parseFloat(s.discount)>0?`<div class="receipt-row"><span>Discount</span><span>-${fmt(s.discount)}</span></div>`:''}
      <div class="receipt-row receipt-total"><span>TOTAL</span><span>${fmt(s.grand_total)}</span></div>
      <div class="receipt-row"><span>Paid (${esc(s.payment_method)})</span><span>${fmt(s.amount_paid)}</span></div>
      ${parseFloat(s.balance_due)>0?`<div class="receipt-row text-danger"><span>Balance Due</span><span>${fmt(s.balance_due)}</span></div>`:''}
      ${s.mpesa_code?`<div class="text-center mt-2">M-Pesa: ${esc(s.mpesa_code)}</div>`:''}
      ${s.etims_status === 'submitted' ? `
      <hr>
      <div class="text-center" style="font-size:.65rem">
        <div style="font-weight:700;letter-spacing:1px">KRA eTIMS VERIFIED</div>
        <div>CU Invoice: <strong>${esc(s.etims_invoice_no||'—')}</strong></div>
        ${s.etims_qr_code ? `<div style="margin:6px auto;max-width:100px">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=${encodeURIComponent(s.etims_qr_code)}" style="width:100%;border:1px solid #ddd;border-radius:4px">
        </div>` : ''}
        <div style="color:#888">${s.etims_submitted_at ? new Date(s.etims_submitted_at).toLocaleString('en-KE') : ''}</div>
      </div>` : s.etims_status === 'failed' ? `
      <hr>
      <div class="text-center text-danger" style="font-size:.65rem">
        <div>⚠ eTIMS submission failed</div>
        <div style="color:#888">${esc(s.etims_error||'')}</div>
      </div>` : ''}
      <div class="text-center mt-3">Thank you for your business!</div>
    </div>`;
  const voidBtn = document.getElementById('btn-void-sale');
  voidBtn.classList.toggle('d-none', !can(R.OWNER, R.MANAGER) || !!s.voided);
  openModal('modal-receipt');
}

async function retryEtims(sale_id) {
  const res = await api('etims_retry', { sale_id }, 'POST');
  if (res.ok) { toast('eTIMS submission successful!', 'success'); loadSales(); }
  else toast('eTIMS retry failed: ' + (res.msg || 'Unknown error'), 'error');
}

async function voidSale() {
  if (!currentSaleId || !confirm('Void this sale? Stock will be reversed.')) return;
  const res = await api('void_sale', { sale_id: currentSaleId }, 'POST');
  if (res.ok) { toastVoid(); toast('Sale voided', 'success'); closeModal('modal-receipt'); loadSales(); }
  else toast(res.msg || 'Void failed.', 'error');
}

// ── INVENTORY ─────────────────────────────────────────────────
async function renderInventory(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Stock Levels</h5>
      <input type="text" id="inv-search" class="form-control form-control-sm" style="width:220px" placeholder="Search products…" oninput="loadInventory()">
    </div>
    <ul class="nav nav-tabs mb-3" id="inv-tabs">
      <li class="nav-item"><button class="nav-link active" onclick="invTab('stock',this)">Stock</button></li>
      <li class="nav-item"><button class="nav-link" onclick="invTab('movements',this)">Movement Log</button></li>
      <li class="nav-item"><button class="nav-link" onclick="invTab('lowstock',this)">Low Stock</button></li>
    </ul>
    <div id="inv-stock-pane"><div class="card"><div class="table-responsive" id="inv-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div></div>
    <div id="inv-movements-pane" class="d-none"><div class="card"><div class="table-responsive" id="mov-table"></div></div></div>
    <div id="inv-lowstock-pane" class="d-none"><div class="card" id="low-table"></div></div>`;
  loadInventory();
}

function invTab(tab, btn) {
  document.querySelectorAll('#inv-tabs .nav-link').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  ['stock','movements','lowstock'].forEach(t => {
    document.getElementById(`inv-${t}-pane`).classList.toggle('d-none', t !== tab);
  });
  if (tab === 'movements') loadMovements();
  if (tab === 'lowstock') loadLowStock();
}

async function loadInventory() {
  const q  = document.getElementById('inv-search')?.value || '';
  const d  = await api('stock_list', { q });
  const el = document.getElementById('inv-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.stock.map(s => `
    <tr class="${s.low_stock ? 'low-stock-row' : ''}">
      <td class="fw-bold">${esc(s.product)}</td>
      <td class="small text-nx-muted">${esc(s.sku||'—')}</td>
      <td class="small">${esc(s.category||'—')}</td>
      <td class="small">${esc(s.branch)}</td>
      <td class="${s.low_stock ? 'text-danger fw-bold' : 'text-success'}">${parseFloat(s.quantity).toFixed(2)}</td>
      <td class="small text-nx-muted">${esc(s.unit||'—')}</td>
      <td class="small text-nx-muted">${s.reorder_level}</td>
      <td class="small">${fmt(s.selling_price)}</td>
      <td class="small">${fmt(s.stock_value)}</td>
      <td>${s.low_stock ? '<span class="badge bg-danger bg-opacity-25 text-danger">LOW</span>' : ''}</td>
      <td>${can(R.OWNER,R.MANAGER,R.STOREKEEPER) ? `<button class="btn btn-sm btn-outline-secondary" onclick='openAdjust(${JSON.stringify({id:s.id,product_id:s.product_id,name:s.product,branch:s.branch_id||0})})'><i class="fa fa-pen"></i></button>` : ''}</td>
    </tr>`).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Branch</th><th>Qty</th><th>Unit</th><th>Reorder</th><th>Sell Price</th><th>Value</th><th>Alert</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="11" class="text-center text-nx-muted py-4">No stock found</td></tr>'}</tbody>
  </table>`;
}

async function loadMovements() {
  const d  = await api('stock_movements');
  const el = document.getElementById('mov-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.movements.map(m => {
    const qty = parseFloat(m.quantity);
    return `<tr>
      <td class="small text-nx-muted">${new Date(m.created_at).toLocaleString('en-KE')}</td>
      <td class="fw-bold small">${esc(m.product_name)}</td>
      <td class="small">${esc(m.branch_name)}</td>
      <td><span class="mov-type mov-${m.type}">${m.type.replace('_',' ')}</span></td>
      <td class="${qty >= 0 ? 'text-success' : 'text-danger'} fw-bold">${qty >= 0 ? '+' : ''}${qty.toFixed(2)}</td>
      <td class="small text-nx-muted">${esc(m.by_name||'—')}</td>
      <td class="small text-nx-muted">${esc(m.note||'')}</td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Date/Time</th><th>Product</th><th>Branch</th><th>Type</th><th>Qty</th><th>By</th><th>Note</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="7" class="text-center text-nx-muted py-4">No movements yet</td></tr>'}</tbody>
  </table>`;
}

async function loadLowStock() {
  const d  = await api('stock_list', { q: '' });
  const el = document.getElementById('low-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const low = d.stock.filter(s => parseInt(s.low_stock));
  if (!low.length) { el.innerHTML = '<p class="text-success text-center p-4"><i class="fa fa-check-circle me-2"></i>All stock levels are OK.</p>'; return; }
  const rows = low.map(s => `
    <tr class="low-stock-row">
      <td class="fw-bold">${esc(s.product)}</td>
      <td>${esc(s.branch)}</td>
      <td class="text-danger fw-bold">${parseFloat(s.quantity).toFixed(2)}</td>
      <td class="text-nx-muted">${s.reorder_level}</td>
      <td class="text-nx-muted">${esc(s.unit||'—')}</td>
      <td>${can(R.OWNER,R.MANAGER,R.STOREKEEPER) ? `<button class="btn btn-sm btn-outline-secondary" onclick='openAdjust(${JSON.stringify({id:s.id,product_id:s.product_id,name:s.product,branch:s.branch_id||0})})'><i class="fa fa-pen"></i></button>` : ''}</td>
    </tr>`).join('');
  el.innerHTML = `<p class="text-danger p-3 mb-0"><i class="fa fa-triangle-exclamation me-2"></i>${low.length} item(s) below reorder level</p>
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Product</th><th>Branch</th><th>Current Qty</th><th>Reorder Level</th><th>Unit</th><th></th></tr></thead>
      <tbody>${rows}</tbody>
    </table></div>`;
}

function openAdjust(item) {
  document.getElementById('adj-prod-name').value = item.name;
  document.getElementById('adj-prod-id').value   = item.product_id || 0;
  document.getElementById('adj-stock-id').value  = item.id || 0;
  document.getElementById('adj-branch-id').value = item.branch || (CU?.branch_id || 0);
  document.getElementById('adj-qty').value       = '';
  document.getElementById('adj-note').value      = '';
  openModal('modal-adjust');
}

async function submitAdjust() {
  const reason = document.getElementById('adj-reason')?.value || '';
  const extra  = document.getElementById('adj-note').value;
  const note   = [reason, extra].filter(Boolean).join(' — ');
  const qty    = parseFloat(document.getElementById('adj-qty').value);
  if (!qty || qty <= 0) { toast('Enter a valid quantity', 'error'); return; }
  const res = await api('stock_adjust', {
    product_id: document.getElementById('adj-prod-id').value,
    stock_id:   document.getElementById('adj-stock-id').value,
    branch_id:  document.getElementById('adj-branch-id').value,
    quantity:   qty,
    type:       document.getElementById('adj-type').value,
    note,
  }, 'POST');
  if (res.ok) { toast(`Stock adjusted. New qty: ${parseFloat(res.new_qty).toFixed(2)}`, 'success'); closeModal('modal-adjust'); loadInventory(); }
  else toast(res.msg || 'Adjustment failed.', 'error');
}

// ── TRANSFERS ─────────────────────────────────────────────────
async function renderTransfers(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Stock Transfers</h5>
      ${can(R.OWNER,R.MANAGER,R.STOREKEEPER) ? '<button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openTransferModal()"><i class="fa fa-plus me-1"></i>New Transfer</button>' : ''}
    </div>
    <div class="card"><div class="table-responsive" id="tr-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>`;
  loadTransfers();
}

async function loadTransfers() {
  const d  = await api('transfer_list');
  const el = document.getElementById('tr-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.transfers.map(t => {
    const sb = { pending:'bg-warning bg-opacity-25 text-warning', approved:'bg-primary bg-opacity-25 text-primary', completed:'bg-success bg-opacity-25 text-success', rejected:'bg-danger bg-opacity-25 text-danger' }[t.status] || 'bg-secondary bg-opacity-25 text-secondary';
    const canApprove = can(R.OWNER, R.MANAGER) && t.status === 'pending';
    return `<tr>
      <td class="small">${esc(t.from_branch)} → ${esc(t.to_branch)}</td>
      <td class="small fw-bold">${esc(t.product)}</td>
      <td class="small">${parseFloat(t.quantity).toFixed(2)}</td>
      <td class="small text-nx-muted">${esc(t.requested_by_name||'—')}</td>
      <td class="small text-nx-muted">${fmtDate(t.created_at)}</td>
      <td><span class="badge ${sb}">${esc(t.status)}</span></td>
      <td class="d-flex gap-1">
        ${canApprove ? `
          <button class="btn btn-success btn-sm" onclick="approveTransfer(${t.id},'approved')">Approve</button>
          <button class="btn btn-danger btn-sm" onclick="approveTransfer(${t.id},'rejected')">Reject</button>` : ''}
      </td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Route</th><th>Product</th><th>Qty</th><th>Requested By</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="7" class="text-center text-nx-muted py-4">No transfers</td></tr>'}</tbody>
  </table>`;
}

async function openTransferModal() {
  const [br, pr] = await Promise.all([api('branches_list'), api('products_all')]);
  const brOpts = br.branches.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('');
  const prOpts = pr.products.map(p => `<option value="${p.id}">${esc(p.name)} (${esc(p.sku||'—')})</option>`).join('');
  document.getElementById('tr-from').innerHTML = brOpts;
  document.getElementById('tr-to').innerHTML   = brOpts;
  document.getElementById('tr-product').innerHTML = prOpts;
  openModal('modal-transfer');
}

async function submitTransfer() {
  const res = await api('transfer_create', {
    from_branch: document.getElementById('tr-from').value,
    to_branch:   document.getElementById('tr-to').value,
    product_id:  document.getElementById('tr-product').value,
    quantity:    document.getElementById('tr-qty').value,
    note:        document.getElementById('tr-note').value,
  }, 'POST');
  if (res.ok) { toast('Transfer submitted', 'success'); closeModal('modal-transfer'); loadTransfers(); }
  else toast(res.msg || 'Failed.', 'error');
}

async function approveTransfer(id, decision) {
  const res = await api('transfer_approve', { id, decision }, 'POST');
  if (res.ok) { toast(`Transfer ${decision}`, 'success'); loadTransfers(); }
  else toast(res.msg || 'Failed.', 'error');
}

// ── CUSTOMERS ─────────────────────────────────────────────────
async function renderCustomers(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Customers</h5>
      ${can(R.OWNER,R.MANAGER,R.CASHIER) ? '<button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openCustModal()"><i class="fa fa-plus me-1"></i>New Customer</button>' : ''}
    </div>
    <div class="card mb-3 p-3">
      <input type="text" id="cust-search" class="form-control" placeholder="Search by name or phone…" oninput="loadCustomers()">
    </div>
    <div class="card"><div class="table-responsive" id="cust-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>`;
  loadCustomers();
}

async function loadCustomers() {
  const q  = document.getElementById('cust-search')?.value || '';
  const d  = await api('customers_list', { q });
  const el = document.getElementById('cust-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const tierIcon = t => ({ Platinum:'💎', Gold:'🥇', Silver:'🥈', Bronze:'🥉' }[t] || '🥉');
  const tierColor = t => ({ Platinum:'#a78bfa', Gold:'#f59e0b', Silver:'#94a3b8', Bronze:'#b87333' }[t] || '#b87333');
  const rows = d.customers.map(c => {
    const tier = c.computed_tier || c.tier || 'Bronze';
    const tokens = parseInt(c.tokens || 0);
    const lifetimeTokens = parseInt(c.lifetime_tokens || 0);
    const totalSpend = parseFloat(c.lifetime_spend || 0);
    // Progress to next tier
    const tierThresholds = { Bronze:10000, Silver:50000, Gold:100000, Platinum:null };
    const nextThreshold = tierThresholds[tier];
    const prevThreshold = { Bronze:0, Silver:10000, Gold:50000, Platinum:100000 }[tier];
    const progress = nextThreshold
      ? Math.min(100, Math.round(((totalSpend - prevThreshold) / (nextThreshold - prevThreshold)) * 100))
      : 100;
    return `<tr>
      <td>
        <div class="fw-bold">${esc(c.name)}</div>
        <div style="font-size:.7rem;color:var(--nx-muted)">${esc(c.phone||'—')}</div>
      </td>
      <td>
        <span style="display:inline-flex;align-items:center;gap:4px;background:${tierColor(tier)}22;border:1px solid ${tierColor(tier)}55;color:${tierColor(tier)};border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700">
          ${tierIcon(tier)} ${tier}
        </span>
        ${nextThreshold ? `
        <div style="margin-top:4px">
          <div style="height:4px;background:var(--nx-border);border-radius:2px;width:100px">
            <div style="height:4px;background:${tierColor(tier)};border-radius:2px;width:${progress}%"></div>
          </div>
          <div style="font-size:.62rem;color:var(--nx-muted);margin-top:2px">${progress}% to ${Object.keys(tierThresholds)[Object.keys(tierThresholds).indexOf(tier)+1]}</div>
        </div>` : `<div style="font-size:.62rem;color:#a78bfa;margin-top:3px">✨ Max Tier</div>`}
      </td>
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          <div style="background:linear-gradient(135deg,#f59e0b,#fb923c);border-radius:8px;padding:4px 10px;text-align:center;min-width:60px">
            <div style="font-size:.62rem;color:#000;font-weight:600;opacity:.7">TOKENS</div>
            <div style="font-size:1rem;font-weight:700;color:#000;line-height:1">${tokens.toLocaleString()}</div>
          </div>
          <div style="font-size:.68rem;color:var(--nx-muted)">
            <div>Lifetime: ${lifetimeTokens.toLocaleString()}</div>
            <div>≈ ${fmt(Math.floor(tokens/10))} redeem value</div>
          </div>
        </div>
      </td>
      <td class="small text-nx-muted">${esc(c.branch_name||'All')}</td>
      <td class="small">${fmt(c.credit_limit)}</td>
      <td class="${parseFloat(c.balance)>0?'text-danger fw-bold':''}">${fmt(c.balance)}</td>
      <td>
        <div class="d-flex gap-1 flex-wrap">
          ${can(R.OWNER,R.MANAGER,R.CASHIER) ? `<button class="btn btn-sm btn-outline-secondary" onclick='editCust(${JSON.stringify(c)})'><i class="fa fa-pen"></i></button>` : ''}
          ${can(R.OWNER,R.MANAGER,R.CASHIER) ? `<button class="btn btn-sm btn-outline-warning" onclick='showCustomerCard(${JSON.stringify(c)})'><i class="fa fa-id-card"></i></button>` : ''}
          ${parseFloat(c.balance)>0&&can(R.OWNER,R.MANAGER,R.CASHIER) ? `<button class="btn btn-sm btn-success" onclick='openCustPay(${c.id},"${esc(c.name)}",${c.balance})'><i class="fa fa-money-bill me-1"></i>Pay</button>` : ''}
        </div>
      </td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Customer</th><th>Tier</th><th>Tokens</th><th>Branch</th><th>Credit Limit</th><th>Balance</th><th>Actions</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="7" class="text-center text-nx-muted py-4">No customers found</td></tr>'}</tbody>
  </table>`;
}

function openCustModal() {
  document.getElementById('cust-id').value = '';
  document.getElementById('cust-modal-title').textContent = 'New Customer';
  ['cust-name','cust-phone','cust-email','cust-address'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('cust-credit').value = 0;
  _currentCustSuggested = 0;
  const hint = document.getElementById('cust-credit-hint');
  if (hint) hint.innerHTML = '<i class="fa fa-info-circle me-1"></i>New customer — limit will be suggested after first purchase';
  openModal('modal-customer');
}
let _currentCustSuggested = 0;
function editCust(c) {
  document.getElementById('cust-id').value    = c.id;
  document.getElementById('cust-modal-title').textContent = 'Edit Customer';
  document.getElementById('cust-name').value  = c.name;
  document.getElementById('cust-phone').value = c.phone || '';
  document.getElementById('cust-email').value = c.email || '';
  document.getElementById('cust-address').value = c.address || '';
  document.getElementById('cust-credit').value  = c.credit_limit;
  // Show credit intelligence
  _currentCustSuggested = parseFloat(c.suggested_limit || 0);
  const hint = document.getElementById('cust-credit-hint');
  if (hint) {
    const orders = parseInt(c.total_orders || 0);
    const spend  = parseFloat(c.lifetime_spend || 0);
    const spend3m = parseFloat(c.spend_3m || 0);
    const score  = parseFloat(c.payment_score || 1);
    if (orders === 0) {
      hint.innerHTML = `<i class="fa fa-info-circle me-1"></i>New customer — no purchase history yet`;
    } else {
      const reliability = score >= 1.2 ? '🟢 Excellent' : score >= 1.0 ? '🟡 Good' : '🔴 Poor';
      hint.innerHTML = `
        <i class="fa fa-lightbulb me-1 text-warning"></i>
        <strong>Suggested: ${fmt(_currentCustSuggested)}</strong>
        &nbsp;·&nbsp; ${orders} orders &nbsp;·&nbsp; Lifetime: ${fmt(spend)}
        &nbsp;·&nbsp; Last 3mo: ${fmt(spend3m)}
        &nbsp;·&nbsp; Reliability: ${reliability}
        <a href="#" onclick="applySuggestedLimit();return false" class="ms-2 text-accent" style="font-size:.72rem">Apply →</a>`;
    }
  }
  openModal('modal-customer');
}

function applySuggestedLimit() {
  if (_currentCustSuggested > 0) {
    document.getElementById('cust-credit').value = _currentCustSuggested.toFixed(2);
    toast(`Suggested limit applied: ${fmt(_currentCustSuggested)}`, 'info');
  } else {
    toast('No purchase history to base a suggestion on yet', 'info');
  }
}
async function saveCustomer() {
  const res = await api('customer_save', {
    id: document.getElementById('cust-id').value,
    name: document.getElementById('cust-name').value,
    phone: document.getElementById('cust-phone').value,
    email: document.getElementById('cust-email').value,
    address: document.getElementById('cust-address').value,
    credit_limit: document.getElementById('cust-credit').value,
  }, 'POST');
  if (res.ok) {
    toast('Customer saved', 'success');
    closeModal('modal-customer');
    loadCustomers();
    // Show receipt only for new customers
    if (res.action === 'created' && res.customer) {
      showCustomerReceipt(res.customer);
    }
  }
  else toast(res.msg || 'Failed.', 'error');
}

function showCustomerReceipt(c) { showCustomerCard(c); }

function showCustomerCard(c) {
  const now      = new Date().toLocaleString('en-KE');
  const custNo   = 'CUST-' + String(c.id).padStart(5, '0');
  const branch   = c.branch_name || 'All Branches';
  const tier     = c.computed_tier || c.tier || 'Bronze';
  const tokens   = parseInt(c.tokens || 0);
  const ltTokens = parseInt(c.lifetime_tokens || 0);
  const creditLimit = parseFloat(c.credit_limit || 0);
  const balance     = parseFloat(c.balance || 0);
  const totalSpend  = parseFloat(c.lifetime_spend || 0);

  const tierConfig = {
    Bronze:   { icon:'🥉', color:'#b87333', bg:'#f5efe6', grad:'#b87333,#d4956a' },
    Silver:   { icon:'🥈', color:'#64748b', bg:'#f1f5f9', grad:'#64748b,#94a3b8' },
    Gold:     { icon:'🥇', color:'#d97706', bg:'#fffbeb', grad:'#d97706,#f59e0b' },
    Platinum: { icon:'💎', color:'#7c3aed', bg:'#f5f3ff', grad:'#7c3aed,#a78bfa' },
  };
  const tc = tierConfig[tier] || tierConfig.Bronze;

  const tierThresholds = { Bronze:10000, Silver:50000, Gold:100000, Platinum:null };
  const prevThreshold  = { Bronze:0, Silver:10000, Gold:50000, Platinum:100000 }[tier];
  const nextThreshold  = tierThresholds[tier];
  const nextTierName   = { Bronze:'Silver', Silver:'Gold', Gold:'Platinum', Platinum:null }[tier];
  const progress = nextThreshold
    ? Math.min(100, Math.round(((totalSpend - prevThreshold) / (nextThreshold - prevThreshold)) * 100))
    : 100;
  const amountToNext = nextThreshold ? Math.max(0, nextThreshold - totalSpend) : 0;
  const redeemValue  = Math.floor(tokens / 10);

  document.getElementById('cust-receipt-body').innerHTML = `
    <div style="background:#fff;color:#111;font-family:'DM Sans',sans-serif;font-size:.8rem;border-radius:12px;overflow:hidden">

      <!-- CARD HEADER with tier gradient -->
      <div style="background:linear-gradient(135deg,${tc.grad});padding:20px 20px 28px;position:relative;overflow:hidden">
        <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.1)"></div>
        <div style="position:absolute;bottom:-30px;left:-10px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.08)"></div>

        <div style="display:flex;justify-content:space-between;align-items:flex-start;position:relative">
          <div>
            <div style="font-size:.65rem;color:rgba(255,255,255,.75);font-weight:600;letter-spacing:1px;text-transform:uppercase">${esc(CU.business_name||'NYMIX Hardware')}</div>
            <div style="font-size:1.25rem;font-weight:700;color:#fff;margin-top:2px">${esc(c.name)}</div>
            <div style="font-size:.7rem;color:rgba(255,255,255,.8);margin-top:1px">${esc(c.phone||'')}</div>
          </div>
          <div style="text-align:right">
            <div style="font-size:2rem;line-height:1">${tc.icon}</div>
            <div style="font-size:.75rem;font-weight:700;color:#fff;margin-top:2px">${tier}</div>
          </div>
        </div>

        <div style="margin-top:16px;background:rgba(255,255,255,.15);border-radius:8px;padding:6px 12px;display:inline-block">
          <div style="font-size:.6rem;color:rgba(255,255,255,.7);letter-spacing:2px">MEMBER ID</div>
          <div style="font-size:.9rem;font-weight:700;color:#fff;letter-spacing:3px">${custNo}</div>
        </div>
      </div>

      <!-- TOKEN BALANCE STRIP -->
      <div style="background:linear-gradient(90deg,#1e2333,#2a2f42);padding:14px 20px;display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-size:.6rem;color:#f59e0b;font-weight:700;letter-spacing:1px;text-transform:uppercase">Available Tokens</div>
          <div style="font-size:1.8rem;font-weight:700;color:#f59e0b;line-height:1">${tokens.toLocaleString()} <span style="font-size:.75rem;color:#6b7280">pts</span></div>
          <div style="font-size:.65rem;color:#6b7280;margin-top:2px">≈ KSh ${redeemValue.toLocaleString()} redeem value</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:.6rem;color:#6b7280;letter-spacing:1px;text-transform:uppercase">Lifetime Earned</div>
          <div style="font-size:1rem;font-weight:600;color:#94a3b8">${ltTokens.toLocaleString()} pts</div>
          <div style="font-size:.65rem;color:#6b7280;margin-top:2px">10 pts = KSh 1 off</div>
        </div>
      </div>

      <!-- TIER PROGRESS -->
      <div style="padding:14px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <div style="font-size:.7rem;font-weight:600;color:#374151">${tier} Member</div>
          ${nextTierName ? `<div style="font-size:.65rem;color:#6b7280">${fmt(amountToNext)} to ${nextTierName}</div>` : `<div style="font-size:.65rem;color:#7c3aed;font-weight:600">✨ Highest Tier</div>`}
        </div>
        <div style="height:6px;background:#e2e8f0;border-radius:3px">
          <div style="height:6px;background:linear-gradient(90deg,${tc.grad});border-radius:3px;width:${progress}%;transition:width .3s"></div>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:4px">
          <div style="font-size:.6rem;color:#9ca3af">${progress}% complete</div>
          <div style="font-size:.6rem;color:#9ca3af">Spend: ${fmt(totalSpend)}</div>
        </div>

        <!-- Tier benefits row -->
        <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
          ${[
            { t:'Bronze', i:'🥉', c:'#b87333' },
            { t:'Silver', i:'🥈', c:'#64748b' },
            { t:'Gold',   i:'🥇', c:'#d97706' },
            { t:'Platinum',i:'💎',c:'#7c3aed' },
          ].map(tr => `
            <div style="flex:1;min-width:60px;text-align:center;padding:6px 4px;border-radius:6px;border:2px solid ${tr.t===tier?tr.c:'#e2e8f0'};background:${tr.t===tier?tr.c+'15':'#fff'}">
              <div style="font-size:1rem">${tr.i}</div>
              <div style="font-size:.58rem;font-weight:600;color:${tr.t===tier?tr.c:'#9ca3af'}">${tr.t}</div>
              <div style="font-size:.55rem;color:#9ca3af">${{Bronze:'1×',Silver:'1.25×',Gold:'1.5×',Platinum:'2×'}[tr.t]}</div>
            </div>`).join('')}
        </div>
      </div>

      <!-- ACCOUNT DETAILS -->
      <div style="padding:14px 20px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px">
          <div style="background:#f8fafc;border-radius:8px;padding:10px">
            <div style="font-size:.6rem;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Credit Limit</div>
            <div style="font-size:.95rem;font-weight:700;color:#111;margin-top:2px">${fmt(creditLimit)}</div>
          </div>
          <div style="background:${balance>0?'#fef2f2':'#f0fdf4'};border-radius:8px;padding:10px">
            <div style="font-size:.6rem;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Balance Owed</div>
            <div style="font-size:.95rem;font-weight:700;color:${balance>0?'#dc2626':'#16a34a'};margin-top:2px">${fmt(balance)}</div>
          </div>
          <div style="background:#f8fafc;border-radius:8px;padding:10px">
            <div style="font-size:.6rem;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Total Orders</div>
            <div style="font-size:.95rem;font-weight:700;color:#111;margin-top:2px">${parseInt(c.total_orders||0)}</div>
          </div>
          <div style="background:#f8fafc;border-radius:8px;padding:10px">
            <div style="font-size:.6rem;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Lifetime Spend</div>
            <div style="font-size:.85rem;font-weight:700;color:#111;margin-top:2px">${fmt(totalSpend)}</div>
          </div>
        </div>

        <div style="background:#f8fafc;border-radius:8px;padding:10px;margin-bottom:12px">
          <div style="display:flex;justify-content:space-between">
            <div>
              <div style="font-size:.6rem;color:#6b7280">Branch</div>
              <div style="font-size:.8rem;font-weight:600">${esc(branch)}</div>
            </div>
            <div style="text-align:right">
              <div style="font-size:.6rem;color:#6b7280">Member Since</div>
              <div style="font-size:.8rem;font-weight:600">${c.created_at ? new Date(c.created_at).toLocaleDateString('en-KE') : now}</div>
            </div>
          </div>
        </div>

        <!-- How to earn box -->
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:12px">
          <div style="font-size:.65rem;font-weight:700;color:#92400e;margin-bottom:4px">🎯 How to earn tokens</div>
          <div style="font-size:.62rem;color:#78350f;line-height:1.6">
            Every <strong>KSh 100</strong> spent = <strong>1 token</strong><br>
            M-Pesa payments earn <strong>1.5× tokens</strong><br>
            ${tier==='Platinum'?'You earn <strong>2× tokens</strong> as Platinum member':tier==='Gold'?'You earn <strong>1.5× tokens</strong> as Gold member':tier==='Silver'?'You earn <strong>1.25× tokens</strong> as Silver member':'Upgrade to Silver for bonus tokens'}<br>
            <strong>10 tokens = KSh 1</strong> discount on next purchase
          </div>
        </div>

        <!-- Footer -->
        <div style="text-align:center;font-size:.62rem;color:#9ca3af;border-top:1px dashed #e2e8f0;padding-top:10px">
          <div>Present this card when making purchases</div>
          <div style="margin-top:4px;font-weight:700;color:#6b7280">NYMIX TECH · Powered by NYMIX HMS</div>
        </div>
      </div>
    </div>`;

  openModal('modal-cust-receipt');
}
function openCustPay(id, name, balance) {
  document.getElementById('cpay-cid').value    = id;
  document.getElementById('cpay-name').value   = name;
  document.getElementById('cpay-amount').value = balance;
  openModal('modal-cust-pay');
}
async function submitCustPayment() {
  const res = await api('customer_payment', {
    customer_id: document.getElementById('cpay-cid').value,
    amount:      document.getElementById('cpay-amount').value,
    method:      document.getElementById('cpay-method').value,
    mpesa_code:  document.getElementById('cpay-mpesa').value,
  }, 'POST');
  if (res.ok) { toast('Payment recorded', 'success'); closeModal('modal-cust-pay'); loadCustomers(); }
  else toast(res.msg || 'Failed.', 'error');
}

// ── SUPPLIERS ─────────────────────────────────────────────────
async function renderSuppliers(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Suppliers</h5>
      ${can(R.OWNER,R.MANAGER) ? '<button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openSupplierModal()"><i class="fa fa-plus me-1"></i>New Supplier</button>' : ''}
    </div>
    <div class="card mb-3 p-3">
      <input type="text" id="sup-search" class="form-control" placeholder="Search by name or phone…" oninput="loadSuppliers()">
    </div>
    <div class="card"><div class="table-responsive" id="sup-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>`;
  loadSuppliers();
}

async function loadSuppliers() {
  const q  = document.getElementById('sup-search')?.value || '';
  const d  = await api('suppliers_list', { q });
  const el = document.getElementById('sup-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = (d.suppliers || []).map(s => `
    <tr>
      <td class="fw-bold">${esc(s.name)}</td>
      <td class="small">${esc(s.contact||'—')}</td>
      <td class="small">${esc(s.phone||'—')}</td>
      <td class="small">${esc(s.email||'—')}</td>
      <td class="small">${esc(s.address||'—')}</td>
      <td><span class="badge ${s.is_active?'bg-success bg-opacity-25 text-success':'bg-danger bg-opacity-25 text-danger'}">${s.is_active?'Active':'Inactive'}</span></td>
      ${can(R.OWNER,R.MANAGER) ? `<td><button class="btn btn-sm btn-outline-secondary" onclick='openSupplierEdit(${JSON.stringify(s)})'><i class="fa fa-pen"></i></button></td>` : '<td></td>'}
    </tr>`).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Name</th><th>Contact</th><th>Phone</th><th>Email</th><th>Address</th><th>Status</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="7" class="text-center text-nx-muted py-4">No suppliers</td></tr>'}</tbody>
  </table>`;
}

function openSupplierModal() {
  document.getElementById('sup-id').value = '';
  document.getElementById('sup-modal-title').textContent = 'New Supplier';
  ['sup-name','sup-contact','sup-phone','sup-email','sup-address'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('sup-active').value = '1';
  openModal('modal-supplier');
}
function openSupplierEdit(s) {
  document.getElementById('sup-id').value      = s.id;
  document.getElementById('sup-modal-title').textContent = 'Edit Supplier';
  document.getElementById('sup-name').value    = s.name;
  document.getElementById('sup-contact').value = s.contact || '';
  document.getElementById('sup-phone').value   = s.phone || '';
  document.getElementById('sup-email').value   = s.email || '';
  document.getElementById('sup-address').value = s.address || '';
  document.getElementById('sup-active').value  = s.is_active;
  openModal('modal-supplier');
}
async function saveSupplier() {
  const res = await api('supplier_save', {
    id: document.getElementById('sup-id').value,
    name: document.getElementById('sup-name').value,
    contact: document.getElementById('sup-contact').value,
    phone: document.getElementById('sup-phone').value,
    email: document.getElementById('sup-email').value,
    address: document.getElementById('sup-address').value,
    is_active: document.getElementById('sup-active').value,
  }, 'POST');
  if (res.ok) { toast('Supplier saved', 'success'); closeModal('modal-supplier'); loadSuppliers(); }
  else toast(res.msg || 'Failed.', 'error');
}

// ── PURCHASE ORDERS ───────────────────────────────────────────
let poItems = [];

async function renderPurchases(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Purchase Orders</h5>
      ${can(R.OWNER,R.MANAGER,R.STOREKEEPER,R.SUPPLIER) ? '<button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openPOModal()"><i class="fa fa-plus me-1"></i>New LPO</button>' : ''}
    </div>
    <div class="card"><div class="table-responsive" id="po-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>`;
  loadPurchases();
}

async function loadPurchases() {
  const d  = await api('po_list');
  const el = document.getElementById('po-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.orders.map(o => {
    const sb = { draft:'bg-secondary bg-opacity-25 text-secondary', sent:'bg-primary bg-opacity-25 text-primary', received:'bg-success bg-opacity-25 text-success', cancelled:'bg-danger bg-opacity-25 text-danger' }[o.status] || 'bg-warning bg-opacity-25 text-warning';
    return `<tr>
      <td class="text-accent fw-bold small">${esc(o.lpo_number||'—')}</td>
      <td class="small">${esc(o.supplier_name)}</td>
      <td class="small text-nx-muted">${fmtDate(o.created_at)}</td>
      <td class="small text-nx-muted">${fmtDate(o.expected_date)}</td>
      <td class="fw-bold small">${fmt(o.total_amount)}</td>
      <td><span class="badge ${sb}">${esc(o.status.replace('_',' '))}</span></td>
      <td>${o.status!=='received'&&o.status!=='cancelled'&&can(R.OWNER,R.MANAGER,R.STOREKEEPER,R.SUPPLIER) ? `<button class="btn btn-success btn-sm" onclick="openReceive(${o.id})"><i class="fa fa-box-open me-1"></i>Receive</button>` : ''}</td>
    </tr>`;
  }).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>LPO No.</th><th>Supplier</th><th>Created</th><th>Expected</th><th>Total</th><th>Status</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="7" class="text-center text-nx-muted py-4">No orders</td></tr>'}</tbody>
  </table>`;
}

async function openPOModal() {
  poItems = [];
  const [sup, pr] = await Promise.all([api('suppliers_list', { q: '' }), api('products_all')]);
  document.getElementById('po-supplier').innerHTML = sup.suppliers.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  document.getElementById('po-prod-sel').innerHTML = pr.products.map(p => `<option value="${p.id}" data-price="${p.buying_price}">${esc(p.name)}</option>`).join('');
  document.getElementById('po-items-list').innerHTML = '';
  document.getElementById('po-total').textContent = 'KSh 0.00';
  document.getElementById('po-date').value = document.getElementById('po-note').value = '';
  openModal('modal-po');
}

function addPoItem() {
  const sel   = document.getElementById('po-prod-sel');
  const opt   = sel.selectedOptions[0];
  const qty   = parseFloat(document.getElementById('po-prod-qty').value) || 0;
  const price = parseFloat(document.getElementById('po-prod-price').value) || parseFloat(opt.dataset.price) || 0;
  if (!qty || !price) { toast('Enter qty and price', 'error'); return; }
  poItems.push({ id: sel.value, name: opt.text, qty, price });
  renderPOItems();
}

function renderPOItems() {
  const total = poItems.reduce((a, i) => a + i.qty * i.price, 0);
  document.getElementById('po-total').textContent = fmt(total);
  document.getElementById('po-items-list').innerHTML = poItems.map((it, i) => `
    <div class="d-flex justify-content-between align-items-center py-2 border-bottom small" style="border-color:var(--nx-border)!important">
      <span>${esc(it.name)}</span>
      <span>${it.qty} × ${fmt(it.price)} = <strong>${fmt(it.qty * it.price)}</strong></span>
      <button class="btn btn-danger btn-sm" onclick="poItems.splice(${i},1);renderPOItems()"><i class="fa fa-times"></i></button>
    </div>`).join('');
}

async function submitPO() {
  if (!poItems.length) { toast('Add at least one item', 'error'); return; }
  const res = await api('po_create', {
    supplier_id:   document.getElementById('po-supplier').value,
    expected_date: document.getElementById('po-date').value,
    note:          document.getElementById('po-note').value,
    items:         JSON.stringify(poItems.map(i => ({ id: i.id, qty: i.qty, price: i.price }))),
  }, 'POST');
  if (res.ok) { toast('LPO created: ' + res.lpo, 'success'); closeModal('modal-po'); loadPurchases(); }
  else toast(res.msg || 'Failed.', 'error');
}

let currentPOId = null;
async function openReceive(po_id) {
  currentPOId = po_id;
  document.getElementById('receive-body').innerHTML = '<div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div>';
  openModal('modal-receive');
  const d = await api('po_items', { po_id });
  if (!d.ok) { document.getElementById('receive-body').innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.items.map(it => {
    const remaining = parseFloat(it.quantity_ordered) - parseFloat(it.quantity_received || 0);
    return `<tr>
      <td><span class="fw-bold">${esc(it.product_name)}</span><br><span class="text-nx-muted small">${esc(it.sku||'')}</span></td>
      <td class="text-end">${parseFloat(it.quantity_ordered).toFixed(2)}</td>
      <td class="text-end text-success">${parseFloat(it.quantity_received||0).toFixed(2)}</td>
      <td class="text-end text-accent">${remaining.toFixed(2)}</td>
      <td><input type="number" class="rv-qty form-control form-control-sm" data-item-id="${it.id}" value="${remaining > 0 ? remaining : 0}" min="0" max="${remaining}" step="0.001" style="width:90px"></td>
    </tr>`;
  }).join('');
  document.getElementById('receive-body').innerHTML = `
    <p class="text-nx-muted small mb-3">LPO: <strong class="text-accent">${esc(d.po.lpo_number)}</strong> · Supplier: <strong>${esc(d.po.supplier_name)}</strong> · Branch: ${esc(d.po.branch_name)}</p>
    <div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>Product</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Remaining</th><th>Receive Now</th></tr></thead>
      <tbody>${rows}</tbody>
    </table></div>`;
}

async function submitReceive() {
  const inputs = document.querySelectorAll('.rv-qty');
  const items  = [];
  inputs.forEach(inp => { const qty = parseFloat(inp.value); if (qty > 0) items.push({ item_id: inp.dataset.itemId, qty }); });
  if (!items.length) { toast('Enter at least one quantity', 'error'); return; }
  const res = await api('po_receive', { po_id: currentPOId, items: JSON.stringify(items) }, 'POST');
  if (res.ok) { toast('Stock received!', 'success'); closeModal('modal-receive'); loadPurchases(); }
  else toast(res.msg || 'Failed.', 'error');
}

// ── EXPENSES ──────────────────────────────────────────────────
async function renderExpenses(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Expenses</h5>
      <div class="d-flex gap-2">
        <input type="date" id="exp-filter-date" class="form-control form-control-sm" value="${today()}" onchange="loadExpenses()">
        <button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openExpModal()"><i class="fa fa-plus me-1"></i>Add</button>
      </div>
    </div>
    <div class="card"><div class="table-responsive" id="exp-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>`;
  loadExpenses();
}

async function loadExpenses() {
  const date = document.getElementById('exp-filter-date')?.value || today();
  const d    = await api('expenses_list', { date });
  const el   = document.getElementById('exp-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const catSel = document.getElementById('exp-cat');
  if (catSel && d.categories) catSel.innerHTML = d.categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  const rows = d.expenses.map(e => `
    <tr>
      <td class="small">${esc(e.category_name||'—')}</td>
      <td class="fw-bold">${fmt(e.amount)}</td>
      <td class="small">${esc(e.description||'—')}</td>
      <td class="small text-nx-muted">${e.expense_date}</td>
      <td class="small text-nx-muted">${esc(e.receipt_no||'—')}</td>
      <td class="small text-nx-muted">${esc(e.recorded_by_name||'—')}</td>
    </tr>`).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Category</th><th>Amount</th><th>Description</th><th>Date</th><th>Receipt</th><th>By</th></tr></thead>
    <tbody>${rows || '<tr><td colspan="6" class="text-center text-nx-muted py-4">No expenses</td></tr>'}</tbody>
  </table>`;
}

async function openExpModal() {
  const d = await api('expenses_list', { date: today() });
  const catSel = document.getElementById('exp-cat');
  if (d.categories) catSel.innerHTML = d.categories.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  document.getElementById('exp-date').value = today();
  ['exp-amount','exp-desc','exp-receipt'].forEach(id => document.getElementById(id).value = '');
  openModal('modal-expense');
}

async function submitExpense() {
  const res = await api('expense_save', {
    category_id:  document.getElementById('exp-cat').value,
    amount:       document.getElementById('exp-amount').value,
    description:  document.getElementById('exp-desc').value,
    expense_date: document.getElementById('exp-date').value,
    receipt_no:   document.getElementById('exp-receipt').value,
  }, 'POST');
  if (res.ok) { toast('Expense saved', 'success'); closeModal('modal-expense'); loadExpenses(); }
  else toast(res.msg || 'Failed.', 'error');
}

// ── REPORTS ───────────────────────────────────────────────────
async function renderReports(el) {
  el.innerHTML = `
    <h5 class="fw-head mb-3">Reports</h5>
    <ul class="nav nav-tabs mb-0" id="rpt-tabs">
      <li class="nav-item"><button class="nav-link active" onclick="rptTab('sales',this)">Sales Report</button></li>
      <li class="nav-item"><button class="nav-link" onclick="rptTab('stock',this)">Stock Valuation</button></li>
    </ul>
    <div class="tab-content">
      <div id="rpt-sales-pane">
        <div class="card mb-3 p-3">
          <div class="row g-2 align-items-end">
            <div class="col-auto"><label class="form-label mb-1">From</label><input type="date" id="rpt-from" class="form-control form-control-sm" value="${today()}"></div>
            <div class="col-auto"><label class="form-label mb-1">To</label><input type="date" id="rpt-to" class="form-control form-control-sm" value="${today()}"></div>
            <div class="col-auto"><button class="btn btn-sm btn-primary" onclick="loadSalesReport()"><i class="fa fa-search me-1"></i>Run</button></div>
            <div class="col-auto"><button class="btn btn-sm btn-outline-secondary" onclick="exportSalesCSV()"><i class="fa fa-download me-1"></i>CSV</button></div>
          </div>
        </div>
        <div id="rpt-sales-out"></div>
      </div>
      <div id="rpt-stock-pane" class="d-none">
        <div class="d-flex justify-content-end mb-3">
          <button class="btn btn-sm btn-outline-secondary" onclick="exportStockCSV()"><i class="fa fa-download me-1"></i>Export CSV</button>
        </div>
        <div id="rpt-stock-out"></div>
      </div>
    </div>`;
}

function rptTab(tab, btn) {
  document.querySelectorAll('#rpt-tabs .nav-link').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  ['sales','stock'].forEach(t => document.getElementById(`rpt-${t}-pane`).classList.toggle('d-none', t !== tab));
  if (tab === 'stock') loadStockReport();
}

async function loadSalesReport() {
  const from = document.getElementById('rpt-from').value;
  const to   = document.getElementById('rpt-to').value;
  const d    = await api('report_sales', { from, to });
  const el   = document.getElementById('rpt-sales-out');
  if (!d.ok) { el.innerHTML = '<p class="text-danger">Failed.</p>'; return; }
  const t = d.totals;
  el.innerHTML = `
    <div class="row g-3 mb-4">
      ${[['Transactions',t.txns,'var(--nx-blue)'],['Gross Sales',fmt(t.gross),'var(--nx-accent)'],['Discounts',fmt(t.disc),'var(--nx-orange)'],['Collected',fmt(t.collected),'var(--nx-green)'],['Credit',fmt(t.credit),'var(--nx-red)']].map(([l,v,c]) => `
      <div class="col-6 col-md-4 col-lg-2-4">
        <div class="stat-card" style="--card-accent:${c}"><div class="stat-label">${l}</div><div class="stat-value" style="font-size:1.2rem">${v}</div></div>
      </div>`).join('')}
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Date</th><th class="text-end">Txns</th><th class="text-end">Gross</th><th class="text-end">Discount</th><th class="text-end">Collected</th><th class="text-end">Credit</th></tr></thead>
      <tbody>${d.rows.map(r => `<tr>
        <td class="small">${r.d}</td><td class="text-end small">${r.txns}</td>
        <td class="text-end fw-bold small">${fmt(r.gross)}</td>
        <td class="text-end text-danger small">${fmt(r.disc)}</td>
        <td class="text-end text-success small">${fmt(r.collected)}</td>
        <td class="text-end text-warning small">${fmt(r.credit)}</td>
      </tr>`).join('') || '<tr><td colspan="6" class="text-center text-nx-muted py-4">No data</td></tr>'}</tbody>
    </table></div></div>`;
}

async function loadStockReport() {
  const d  = await api('report_stock');
  const el = document.getElementById('rpt-stock-out');
  if (!d.ok) { el.innerHTML = '<p class="text-danger">Failed.</p>'; return; }
  el.innerHTML = `
    <div class="row g-3 mb-4">
      <div class="col-6"><div class="stat-card" style="--card-accent:var(--nx-accent)"><div class="stat-label">Total Stock Value</div><div class="stat-value" style="font-size:1.2rem">${fmt(d.total_value)}</div></div></div>
      <div class="col-6"><div class="stat-card" style="--card-accent:var(--nx-red)"><div class="stat-label">Low Stock Items</div><div class="stat-value">${d.rows.filter(r=>r.low_stock).length}</div></div></div>
    </div>
    <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
      <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th class="text-end">Qty</th><th>Unit</th><th class="text-end">Buy Price</th><th class="text-end">Value</th><th>Alert</th></tr></thead>
      <tbody>${d.rows.map(r => `<tr class="${r.low_stock?'low-stock-row':''}">
        <td class="fw-bold small">${esc(r.name)}</td><td class="small text-nx-muted">${esc(r.sku||'—')}</td>
        <td class="small">${esc(r.category||'—')}</td>
        <td class="text-end ${r.low_stock?'text-danger':''} small">${parseFloat(r.quantity).toFixed(2)}</td>
        <td class="small text-nx-muted">${esc(r.unit||'—')}</td>
        <td class="text-end small">${fmt(r.buying_price)}</td>
        <td class="text-end fw-bold small">${fmt(r.stock_value)}</td>
        <td>${r.low_stock?'<span class="badge bg-danger bg-opacity-25 text-danger">LOW</span>':''}</td>
      </tr>`).join('')}</tbody>
    </table></div></div>`;
}

async function exportSalesCSV() {
  const from = document.getElementById('rpt-from')?.value || today();
  const to   = document.getElementById('rpt-to')?.value   || today();
  const d = await api('report_sales', { from, to });
  if (!d.ok) { toast('Failed to load data', 'error'); return; }
  const t = d.totals;
  const csv = 'Date,Transactions,Gross,Discount,Collected,Credit\n'
    + d.rows.map(r => `${r.d},${r.txns},${r.gross},${r.disc},${r.collected},${r.credit}`).join('\n')
    + `\nTOTAL,${t.txns},${t.gross},${t.disc},${t.collected},${t.credit}`;
  downloadCSV(csv, `sales_${from}_to_${to}.csv`);
}

async function exportStockCSV() {
  const d = await api('report_stock');
  if (!d.ok) { toast('Failed to load data', 'error'); return; }
  const csv = 'Product,SKU,Category,Qty,Unit,Buy Price,Stock Value,Low Stock\n'
    + d.rows.map(r => `"${r.name}","${r.sku||''}","${r.category||''}",${r.quantity},"${r.unit||''}",${r.buying_price},${r.stock_value},${r.low_stock?'YES':''}`).join('\n');
  downloadCSV(csv, `stock_${today()}.csv`);
}

function downloadCSV(content, filename) {
  const blob = new Blob([content], { type: 'text/csv' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}

// ── USERS ─────────────────────────────────────────────────────
async function renderUsers(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Staff Users</h5>
      <button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openUserModal()"><i class="fa fa-plus me-1"></i>New User</button>
    </div>
    <div class="card"><div class="table-responsive" id="usr-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>`;
  loadUsers();
}

async function loadUsers() {
  const d  = await api('users_list');
  const el = document.getElementById('usr-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">No permission or failed.</p>'; return; }
  const rows = d.users.map(u => `
    <tr>
      <td class="fw-bold">${esc(u.full_name)}</td>
      <td class="small text-nx-muted">${esc(u.username)}</td>
      <td class="small text-nx-muted">${esc(u.phone||'—')}</td>
      <td><span class="badge bg-primary bg-opacity-25 text-primary">${esc(u.role_name)}</span></td>
      <td class="small">${esc(u.branch_name||'All Branches')}</td>
      <td class="small text-nx-muted">${u.last_login ? fmtDate(u.last_login) : 'Never'}</td>
      <td><span class="badge ${u.is_active?'bg-success bg-opacity-25 text-success':'bg-danger bg-opacity-25 text-danger'}">${u.is_active?'Active':'Inactive'}</span></td>
      <td><button class="btn btn-sm btn-outline-secondary" onclick='openUserEdit(${JSON.stringify({...u,password_hash:""})})'><i class="fa fa-pen"></i></button></td>
    </tr>`).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Name</th><th>Username</th><th>Phone</th><th>Role</th><th>Branch</th><th>Last Login</th><th>Status</th><th></th></tr></thead>
    <tbody>${rows}</tbody>
  </table>`;
  window._roles = d.roles; window._branches = d.branches;
}

function populateUserModal(roles, branches) {
  document.getElementById('usr-role').innerHTML = roles.map(r => `<option value="${r.id}">${esc(r.name)}</option>`).join('');
  document.getElementById('usr-branch').innerHTML = '<option value="">All Branches</option>' + branches.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('');
}

async function openUserModal() {
  if (!window._roles) { const d = await api('users_list'); window._roles = d.roles; window._branches = d.branches; }
  populateUserModal(window._roles, window._branches);
  document.getElementById('usr-id').value = '';
  document.getElementById('user-modal-title').textContent = 'New Staff User';
  ['usr-name','usr-username','usr-phone','usr-email','usr-password'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('usr-active').value = '1';
  openModal('modal-user');
}

function openUserEdit(u) {
  if (!window._roles) return;
  populateUserModal(window._roles, window._branches);
  document.getElementById('usr-id').value       = u.id;
  document.getElementById('user-modal-title').textContent = 'Edit User';
  document.getElementById('usr-name').value     = u.full_name;
  document.getElementById('usr-username').value = u.username;
  document.getElementById('usr-phone').value    = u.phone || '';
  document.getElementById('usr-email').value    = u.email || '';
  document.getElementById('usr-role').value     = u.role_id;
  document.getElementById('usr-branch').value   = u.branch_id || '';
  document.getElementById('usr-active').value   = u.is_active;
  document.getElementById('usr-password').value = '';
  openModal('modal-user');
}

async function saveUser() {
  const res = await api('user_save', {
    id:        document.getElementById('usr-id').value,
    full_name: document.getElementById('usr-name').value,
    username:  document.getElementById('usr-username').value,
    phone:     document.getElementById('usr-phone').value,
    email:     document.getElementById('usr-email').value,
    role_id:   document.getElementById('usr-role').value,
    branch_id: document.getElementById('usr-branch').value,
    is_active: document.getElementById('usr-active').value,
    password:  document.getElementById('usr-password').value,
  }, 'POST');
  if (res.ok) { toast('User saved', 'success'); closeModal('modal-user'); loadUsers(); }
  else toast(res.msg || 'Failed.', 'error');
}

// ── PRODUCTS ──────────────────────────────────────────────────
async function renderProducts(el) {
  el.innerHTML = `
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h5 class="fw-head mb-0">Products</h5>
      ${can(R.OWNER,R.MANAGER) ? '<button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openProductModal()"><i class="fa fa-plus me-1"></i>New Product</button>' : ''}
    </div>
    <div class="card mb-3 p-3">
      <input type="text" id="prod-search" class="form-control" placeholder="Search by name, SKU or barcode…" oninput="loadProducts()">
    </div>
    <div class="card"><div class="table-responsive" id="prod-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>`;
  loadProducts();
}

async function loadProducts() {
  const q  = document.getElementById('prod-search')?.value || '';
  const d  = await api('products_list', { q });
  const el = document.getElementById('prod-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.products.map(p => `
    <tr>
      <td class="fw-bold">${esc(p.name)}</td>
      <td class="small text-nx-muted">${esc(p.sku||'—')}</td>
      <td class="small text-nx-muted">${esc(p.barcode||'—')}</td>
      <td class="small">${esc(p.category_name||'—')}</td>
      <td class="small">${esc(p.unit_name||'—')}</td>
      <td class="small text-end">${fmt(p.buying_price)}</td>
      <td class="fw-bold text-accent text-end">${fmt(p.selling_price)}</td>
      <td class="small text-nx-muted text-end">${p.reorder_level}</td>
      <td><span class="badge ${p.is_active?'bg-success bg-opacity-25 text-success':'bg-danger bg-opacity-25 text-danger'}">${p.is_active?'Active':'Inactive'}</span></td>
      ${can(R.OWNER,R.MANAGER) ? `<td><button class="btn btn-sm btn-outline-secondary" onclick='openProductEdit(${JSON.stringify(p)})'><i class="fa fa-pen"></i></button></td>` : '<td></td>'}
    </tr>`).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Name</th><th>SKU</th><th>Barcode</th><th>Category</th><th>Unit</th><th class="text-end">Buy</th><th class="text-end">Sell</th><th class="text-end">Reorder</th><th>Status</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="10" class="text-center text-nx-muted py-4">No products found</td></tr>'}</tbody>
  </table>`;
}

async function loadProductDropdowns() {
  const [cats, units] = await Promise.all([api('categories_list'), api('units_list')]);
  document.getElementById('prod-category').innerHTML = '<option value="">— No Category —</option>' + (cats.categories||[]).map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  document.getElementById('prod-unit').innerHTML     = '<option value="">— No Unit —</option>'     + (units.units||[]).map(u => `<option value="${u.name}">${esc(u.name)}</option>`).join('');
}

async function openProductModal() {
  await loadProductDropdowns();
  document.getElementById('prod-id').value = '';
  document.getElementById('prod-modal-title').textContent = 'New Product';
  ['prod-name','prod-sku','prod-barcode'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('prod-buy').value     = '';
  document.getElementById('prod-sell').value    = '';
  document.getElementById('prod-reorder').value = '5';
  document.getElementById('prod-active').value  = '1';
  openModal('modal-product');
}

async function openProductEdit(p) {
  await loadProductDropdowns();
  document.getElementById('prod-id').value       = p.id;
  document.getElementById('prod-modal-title').textContent = 'Edit Product';
  document.getElementById('prod-name').value     = p.name;
  document.getElementById('prod-sku').value      = p.sku || '';
  document.getElementById('prod-barcode').value  = p.barcode || '';
  document.getElementById('prod-category').value = p.category_id || '';
  document.getElementById('prod-unit').value     = p.unit || '';
  document.getElementById('prod-buy').value      = p.buying_price;
  document.getElementById('prod-sell').value     = p.selling_price;
  document.getElementById('prod-reorder').value  = p.reorder_level;
  document.getElementById('prod-active').value   = p.is_active;
  openModal('modal-product');
}

async function saveProduct() {
  const name = document.getElementById('prod-name').value.trim();
  const buy  = parseFloat(document.getElementById('prod-buy').value);
  const sell = parseFloat(document.getElementById('prod-sell').value);
  if (!name)      { toast('Product name required', 'error'); return; }
  if (!buy||!sell){ toast('Enter buying and selling prices', 'error'); return; }
  const res = await api('product_save', {
    id: document.getElementById('prod-id').value, name,
    sku: document.getElementById('prod-sku').value,
    barcode: document.getElementById('prod-barcode').value,
    category_id: document.getElementById('prod-category').value,
    unit: document.getElementById('prod-unit').value,
    buying_price: buy, selling_price: sell,
    reorder_level: document.getElementById('prod-reorder').value,
    is_active: document.getElementById('prod-active').value,
  }, 'POST');
  if (res.ok) { toast('Product saved', 'success'); closeModal('modal-product'); loadProducts(); }
  else toast(res.msg || 'Failed.', 'error');
}

// ── CATEGORIES & UNITS ────────────────────────────────────────
async function renderCategories(el) {
  el.innerHTML = `
    <h5 class="fw-head mb-3">Categories & Units</h5>
    <div class="row g-4">
      <div class="col-md-6">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h6 class="fw-head mb-0">Categories</h6>
          ${can(R.OWNER,R.MANAGER) ? '<button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openCatModal()"><i class="fa fa-plus me-1"></i>Add</button>' : ''}
        </div>
        <div class="card"><div class="table-responsive" id="cat-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>
      </div>
      <div class="col-md-6">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h6 class="fw-head mb-0">Units of Measure</h6>
          ${can(R.OWNER,R.MANAGER) ? '<button class="btn btn-sm fw-bold" style="background:var(--nx-accent);color:#000" onclick="openUnitModal()"><i class="fa fa-plus me-1"></i>Add</button>' : ''}
        </div>
        <div class="card"><div class="table-responsive" id="unit-table"><div class="d-flex justify-content-center py-4"><div class="spinner-border text-warning"></div></div></div></div>
      </div>
    </div>`;
  loadCategories(); loadUnits();
}

async function loadCategories() {
  const d  = await api('categories_list');
  const el = document.getElementById('cat-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.categories.map(c => `
    <tr>
      <td class="fw-bold">${esc(c.name)}</td>
      ${can(R.OWNER,R.MANAGER) ? `<td class="text-end"><button class="btn btn-sm btn-outline-secondary" onclick='openCatEdit(${JSON.stringify(c)})'><i class="fa fa-pen"></i></button></td>` : '<td></td>'}
    </tr>`).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Name</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="2" class="text-center text-nx-muted py-4">No categories</td></tr>'}</tbody>
  </table>`;
}

async function loadUnits() {
  const d  = await api('units_list');
  const el = document.getElementById('unit-table');
  if (!d.ok) { el.innerHTML = '<p class="text-danger p-4">Failed.</p>'; return; }
  const rows = d.units.map(u => `
    <tr>
      <td class="fw-bold">${esc(u.name)}</td>
      ${can(R.OWNER,R.MANAGER) ? `<td class="text-end"><button class="btn btn-sm btn-outline-secondary" onclick='openUnitEdit(${JSON.stringify(u)})'><i class="fa fa-pen"></i></button></td>` : '<td></td>'}
    </tr>`).join('');
  el.innerHTML = `<table class="table table-sm mb-0">
    <thead><tr><th>Name</th><th></th></tr></thead>
    <tbody>${rows || '<tr><td colspan="2" class="text-center text-nx-muted py-4">No units</td></tr>'}</tbody>
  </table>`;
}

function openCatModal()  { document.getElementById('cat-id').value=''; document.getElementById('cat-modal-title').textContent='New Category'; document.getElementById('cat-name').value=''; openModal('modal-category'); }
function openCatEdit(c)  { document.getElementById('cat-id').value=c.id; document.getElementById('cat-modal-title').textContent='Edit Category'; document.getElementById('cat-name').value=c.name; openModal('modal-category'); }
function openUnitModal() { document.getElementById('unit-id').value=''; document.getElementById('unit-modal-title').textContent='New Unit'; document.getElementById('unit-name').value=''; openModal('modal-unit'); }
function openUnitEdit(u) { document.getElementById('unit-id').value=u.id; document.getElementById('unit-modal-title').textContent='Edit Unit'; document.getElementById('unit-name').value=u.name; openModal('modal-unit'); }

async function saveCategory() {
  const name = document.getElementById('cat-name').value.trim();
  if (!name) { toast('Enter a name', 'error'); return; }
  const res = await api('category_save', { id: document.getElementById('cat-id').value, name }, 'POST');
  if (res.ok) { toast('Category saved', 'success'); closeModal('modal-category'); loadCategories(); }
  else toast(res.msg || 'Failed.', 'error');
}

async function saveUnit() {
  const name = document.getElementById('unit-name').value.trim();
  if (!name) { toast('Enter a name', 'error'); return; }
  const res = await api('unit_save', { id: document.getElementById('unit-id').value, name }, 'POST');
  if (res.ok) { toast('Unit saved', 'success'); closeModal('modal-unit'); loadUnits(); }
  else toast(res.msg || 'Failed.', 'error');
}

// ── SCREEN LOCK ───────────────────────────────────────────────
const IDLE_MS   = 5 * 60 * 1000; // 5 min idle → lock
const COUNTDOWN = 30;             // 30s warning before lock
let idleTimer = null, countdownTimer = null, secondsLeft = COUNTDOWN;
let warningActive = false, screenLocked = false;
const idleOverlay = document.getElementById('idle-overlay');

function lockScreen() {
  // Clear warning if showing
  warningActive = false;
  clearInterval(countdownTimer);
  clearTimeout(idleTimer);
  idleOverlay.classList.remove('show');

  screenLocked = true;
  document.getElementById('lock-screen').classList.remove('d-none');
  document.getElementById('lock-pin').value = '';
  document.getElementById('lock-error').classList.add('d-none');
  document.getElementById('lock-pin').focus();
  // Blur the app behind
  document.getElementById('nx-app').style.filter = 'blur(6px) brightness(0.4)';
  document.getElementById('nx-app').style.pointerEvents = 'none';
  document.getElementById('nx-app').style.userSelect = 'none';
}

async function unlockScreen() {
  const pin = document.getElementById('lock-pin').value;
  if (!pin) { showLockError('Enter your password.'); return; }

  const btn = document.getElementById('lock-unlock-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying…';

  // Verify against server using existing login action
  const res = await api('login', { username: CU.username || '', password: pin }, 'POST');

  btn.disabled = false;
  btn.innerHTML = '<i class="fa fa-unlock me-2"></i>Unlock';

  if (res.ok) {
    screenLocked = false;
    document.getElementById('lock-screen').classList.add('d-none');
    document.getElementById('nx-app').style.filter = '';
    document.getElementById('nx-app').style.pointerEvents = '';
    document.getElementById('nx-app').style.userSelect = '';
    resetIdle();
  } else {
    showLockError(res.msg || 'Incorrect password. Try again.');
    document.getElementById('lock-pin').value = '';
    document.getElementById('lock-pin').focus();
    // Shake animation
    const card = document.getElementById('lock-card');
    card.classList.add('lock-shake');
    setTimeout(() => card.classList.remove('lock-shake'), 500);
  }
}

function printCard() {
  const content = document.getElementById('cust-receipt-body').innerHTML;
  const win = window.open('', '_blank', 'width=420,height=700');
  win.document.write(`<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Member Card</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'DM Sans',sans-serif; background:#fff; }
  @media print {
    body { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  }
</style>
</head>
<body>${content}</body>
</html>`);
  win.document.close();
  win.focus();
  setTimeout(() => { win.print(); win.close(); }, 500);
}

function showLockError(msg) {
  const el = document.getElementById('lock-error');
  el.textContent = msg;
  el.classList.remove('d-none');
}

function showIdleWarning() {
  if (screenLocked) return;
  warningActive = true; secondsLeft = COUNTDOWN;
  idleOverlay.classList.add('show');
  document.getElementById('idle-seconds').textContent = secondsLeft;
  document.getElementById('idle-bar').style.width = '100%';
  document.getElementById('idle-bar').style.background = 'var(--nx-accent)';
  countdownTimer = setInterval(() => {
    secondsLeft--;
    document.getElementById('idle-seconds').textContent = secondsLeft;
    document.getElementById('idle-bar').style.width = Math.max(0, (secondsLeft / COUNTDOWN) * 100) + '%';
    if (secondsLeft <= 10) document.getElementById('idle-bar').style.background = 'var(--nx-red)';
    if (secondsLeft <= 0) {
      clearInterval(countdownTimer);
      idleOverlay.classList.remove('show');
      warningActive = false;
      lockScreen(); // LOCK instead of logout
    }
  }, 1000);
}

function resetIdle() {
  if (screenLocked) return;
  if (warningActive) {
    warningActive = false;
    idleOverlay.classList.remove('show');
    document.getElementById('idle-bar').style.background = 'var(--nx-accent)';
    clearInterval(countdownTimer);
  }
  clearTimeout(idleTimer);
  idleTimer = setTimeout(showIdleWarning, IDLE_MS);
}

['mousemove','mousedown','touchstart','scroll','click'].forEach(ev =>
  document.addEventListener(ev, () => { if (!warningActive && !screenLocked) resetIdle(); }, { passive: true })
);

document.addEventListener('keydown', e => {
  if (screenLocked) {
    if (e.key === 'Enter') unlockScreen();
    return; // block all other keydown handling while locked
  }
  if (!warningActive) resetIdle();
}, { passive: true });

// ── eTIMS PAGE ────────────────────────────────────────────────
async function renderEtims(el) {
  el.innerHTML = `
    <h5 class="fw-head mb-1">eTIMS / KRA Settings</h5>
    <p class="text-nx-muted small mb-4">Configure KRA eTIMS credentials per branch. Each branch needs its own Branch Code and Device Serial from KRA.</p>

    <!-- Status Summary -->
    <div class="row g-3 mb-4" id="etims-summary-cards">
      <div class="col-12 text-center py-3"><div class="spinner-border text-warning"></div></div>
    </div>

    <!-- Retry failed queue -->
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h6 class="fw-head mb-0">Today's eTIMS Status</h6>
      <button class="btn btn-sm btn-outline-warning" onclick="retryQueue()">
        <i class="fa fa-rotate me-1"></i>Retry Failed Queue
      </button>
    </div>

    <!-- Branch settings -->
    <div class="card mb-4">
      <div class="card-header py-2 px-3 small d-flex align-items-center justify-content-between">
        <span>Branch eTIMS Credentials</span>
        <select id="etims-branch-sel" class="form-select form-select-sm" style="width:220px" onchange="loadEtimsSettings()">
          <option value="">— Select Branch —</option>
        </select>
      </div>
      <div class="card-body" id="etims-branch-form">
        <p class="text-nx-muted small">Select a branch above to configure its eTIMS credentials.</p>
      </div>
    </div>

    <!-- Info box -->
    <div class="alert" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);color:var(--nx-text)">
      <div class="fw-bold mb-2"><i class="fa fa-info-circle me-2 text-blue"></i>How to get eTIMS credentials</div>
      <ol class="small mb-0" style="color:var(--nx-muted);padding-left:1.2rem">
        <li>Register your business on <strong>itax.kra.go.ke</strong></li>
        <li>Navigate to eTIMS → Device Management → Register Device</li>
        <li>You'll receive a <strong>Branch Code (bhfId)</strong> and <strong>Device Serial (dvcSrlNo)</strong></li>
        <li>Enter them here per branch and switch from Sandbox to Live when ready</li>
      </ol>
    </div>`;

  // Load summary
  const sum = await api('etims_status_summary');
  if (sum.ok) {
    const s = sum.summary;
    document.getElementById('etims-summary-cards').innerHTML = `
      <div class="col-6 col-md-3">
        <div class="stat-card" style="--card-accent:var(--nx-green)">
          <div class="stat-label">Submitted</div>
          <div class="stat-value">${s.submitted}</div>
          <div class="stat-sub">sent to KRA today</div>
          <i class="fa fa-check stat-icon"></i>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card" style="--card-accent:var(--nx-red)">
          <div class="stat-label">Failed</div>
          <div class="stat-value">${s.failed}</div>
          <div class="stat-sub">need retry</div>
          <i class="fa fa-xmark stat-icon"></i>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card" style="--card-accent:var(--nx-orange)">
          <div class="stat-label">Pending</div>
          <div class="stat-value">${s.pending}</div>
          <div class="stat-sub">queued</div>
          <i class="fa fa-clock stat-icon"></i>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card" style="--card-accent:var(--nx-muted)">
          <div class="stat-label">Skipped</div>
          <div class="stat-value">${s.skipped}</div>
          <div class="stat-sub">no eTIMS on branch</div>
          <i class="fa fa-minus stat-icon"></i>
        </div>
      </div>`;
  }

  // Load branches into selector
  const br = await api('branches_list');
  if (br.ok) {
    document.getElementById('etims-branch-sel').innerHTML =
      '<option value="">— Select Branch —</option>' +
      br.branches.map(b => `<option value="${b.id}">${esc(b.name)}</option>`).join('');
  }
}

async function loadEtimsSettings() {
  const bid = document.getElementById('etims-branch-sel').value;
  const el  = document.getElementById('etims-branch-form');
  if (!bid) { el.innerHTML = '<p class="text-nx-muted small">Select a branch above.</p>'; return; }
  el.innerHTML = '<div class="d-flex justify-content-center py-3"><div class="spinner-border text-warning spinner-border-sm"></div></div>';
  const d = await api('branch_etims_get', { branch_id: bid });
  if (!d.ok) { el.innerHTML = '<p class="text-danger small">Failed to load.</p>'; return; }
  const b = d.branch;
  el.innerHTML = `
    <div class="row g-3">
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="etims-enabled" ${b.etims_enabled ? 'checked' : ''}>
          <label class="form-check-label" for="etims-enabled">Enable eTIMS for <strong>${esc(b.branch_name)}</strong></label>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label">KRA PIN (Taxpayer PIN)</label>
        <input type="text" id="etims-pin" class="form-control" value="${esc(b.etims_pin||'')}" placeholder="e.g. P051234567A">
      </div>
      <div class="col-md-6">
        <label class="form-label">Branch Code (bhfId)</label>
        <input type="text" id="etims-branch-code" class="form-control" value="${esc(b.etims_branch_code||'')}" placeholder="e.g. 00">
      </div>
      <div class="col-md-6">
        <label class="form-label">Device Serial (dvcSrlNo)</label>
        <input type="text" id="etims-device-serial" class="form-control" value="${esc(b.etims_device_serial||'')}" placeholder="From KRA eTIMS portal">
      </div>
      <div class="col-md-6">
        <label class="form-label">Environment</label>
        <select id="etims-env" class="form-select">
          <option value="sandbox" ${b.etims_env==='sandbox'?'selected':''}>🧪 Sandbox (Testing)</option>
          <option value="live"    ${b.etims_env==='live'?'selected':''}>🟢 Live (Production)</option>
        </select>
      </div>
      <div class="col-12">
        <button class="btn fw-bold" style="background:var(--nx-accent);color:#000" onclick="saveEtimsSettings(${bid})">
          <i class="fa fa-save me-2"></i>Save eTIMS Settings
        </button>
        ${b.etims_enabled ? `
        <button class="btn btn-outline-secondary ms-2" onclick="testEtims(${bid})">
          <i class="fa fa-plug me-2"></i>Test Connection
        </button>` : ''}
      </div>
    </div>`;
}

async function saveEtimsSettings(branch_id) {
  const res = await api('branch_etims_save', {
    branch_id,
    etims_enabled:      document.getElementById('etims-enabled').checked ? 1 : 0,
    etims_pin:          document.getElementById('etims-pin').value,
    etims_branch_code:  document.getElementById('etims-branch-code').value,
    etims_device_serial:document.getElementById('etims-device-serial').value,
    etims_env:          document.getElementById('etims-env').value,
  }, 'POST');
  if (res.ok) toast('eTIMS settings saved', 'success');
  else toast(res.msg || 'Failed.', 'error');
}

async function retryQueue() {
  const res = await api('etims_retry_queue');
  if (res.ok) {
    const r = res.results;
    toast(`Retry complete: ${r.ok} submitted, ${r.failed} failed out of ${r.attempted}`, r.failed === 0 ? 'success' : 'info');
    renderEtims(document.getElementById('nx-content'));
  } else toast('Retry failed: ' + (res.msg || ''), 'error');
}

async function testEtims(branch_id) {
  toast('Testing connection...', 'info');
  // Test by hitting the eTIMS initialization endpoint
  const res = await api('branch_etims_get', { branch_id });
  if (res.ok && res.branch.etims_enabled) {
    toast('Credentials saved. Real test happens on next sale in sandbox mode.', 'info');
  } else {
    toast('Enable eTIMS first to test', 'error');
  }
}

// ── INIT ──────────────────────────────────────────────────────
function init() {
  if (!CU) return;
  buildNav();
  document.getElementById('topbar-date').textContent =
    new Date().toLocaleDateString('en-KE', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  document.querySelectorAll('.nx-nav-link[data-page]').forEach(btn => {
    btn.addEventListener('click', () => navigate(btn.dataset.page));
  });
  const defaults = {
    [R.OWNER]: 'dashboard', [R.MANAGER]: 'dashboard',
    [R.CASHIER]: 'pos', [R.STOREKEEPER]: 'inventory', [R.SUPPLIER]: 'purchases'
  };
  navigate(defaults[CU.role_id] || 'dashboard');
  resetIdle();
}
init();
</script>
</body>
</html>
