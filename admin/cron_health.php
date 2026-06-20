<?php
// ============================================================
//  NYMIX TECH — cron_health.php
//  Collects server + app metrics and inserts into system_health.
//
//  HOW TO RUN:
//  • Linux cron (every 5 min):
//      */5 * * * * /usr/local/bin/php /home/ytaglyyp/public_html/nymix_hardwares/admin/cron_health.php
// ============================================================

// ── Allow CLI-only OR restrict by IP when called via HTTP ──
if (PHP_SAPI !== 'cli') {
    $allowed_ips = ['127.0.0.1', '::1'];
    $caller_ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($caller_ip, $allowed_ips, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

define('CRON_RUNNING', true);
require_once __DIR__ . '/../includes/db.php';   // provides $conn (mysqli)

// ── Helper: safely call shell_exec only if available ────────
function safe_shell(string $cmd): string {
    if (!function_exists('shell_exec') || !is_callable('shell_exec')) return '';
    $out = @shell_exec($cmd);
    return $out ?? '';
}

// ── 1. SERVER LOAD (CPU) ────────────────────────────────────
function get_cpu_pct(): float {
    // Linux — use /proc/loadavg (always readable, no shell needed)
    if (file_exists('/proc/loadavg')) {
        $load  = (float)explode(' ', file_get_contents('/proc/loadavg'))[0];
        $cores_out = safe_shell('nproc');
        $cores = (int)$cores_out ?: 1;
        return min(100, round($load / $cores * 100, 2));
    }
    return 0.0;
}

// ── 2. MEMORY ───────────────────────────────────────────────
function get_memory(): array {
    if (file_exists('/proc/meminfo')) {
        $lines = file('/proc/meminfo');
        $mem   = [];
        foreach ($lines as $l) {
            if (preg_match('/^(\w+):\s+(\d+)/', $l, $m))
                $mem[$m[1]] = (int)$m[2]; // kB
        }
        $total_mb     = round(($mem['MemTotal']     ?? 0) / 1024, 2);
        $available_mb = round(($mem['MemAvailable'] ?? ($mem['MemFree'] ?? 0)) / 1024, 2);
        $used_mb      = max(0, $total_mb - $available_mb);
        $pct          = $total_mb > 0 ? round($used_mb / $total_mb * 100, 2) : 0;
        return ['used' => $used_mb, 'total' => $total_mb, 'pct' => $pct];
    }

    // PHP process memory fallback (shared hosting — always works)
    $used_mb  = round(memory_get_usage(true) / 1048576, 2);
    $limit    = ini_get('memory_limit');
    $total_mb = ($limit === '-1' || $limit === false) ? 512 : (float)$limit;
    $pct      = $total_mb > 0 ? round($used_mb / $total_mb * 100, 2) : 0;
    return ['used' => $used_mb, 'total' => $total_mb, 'pct' => $pct];
}

// ── 3. DISK ─────────────────────────────────────────────────
function get_disk(): array {
    $path     = '/';
    $free_gb  = round(@disk_free_space($path)  / 1073741824, 2);
    $total_gb = round(@disk_total_space($path) / 1073741824, 2);
    $used_gb  = max(0, $total_gb - $free_gb);
    $pct      = $total_gb > 0 ? round($used_gb / $total_gb * 100, 2) : 0;
    return ['free' => $free_gb, 'total' => $total_gb, 'pct' => $pct];
}

// ── 4. DATABASE METRICS ─────────────────────────────────────
function get_db_metrics(mysqli $conn): array {
    $metrics = ['size_mb' => 0, 'connections' => 0, 'slow_queries' => 0, 'uptime_secs' => 0];

    // DB size
    $db_row = $conn->query("SELECT DATABASE()");
    $db_name = $db_row ? $db_row->fetch_row()[0] ?? '' : '';
    if ($db_name) {
        $db_esc = $conn->real_escape_string($db_name);
        $r = $conn->query("
            SELECT ROUND(SUM(data_length + index_length) / 1048576, 2) AS size_mb
            FROM information_schema.tables
            WHERE table_schema = '$db_esc'
        ");
        if ($r) $metrics['size_mb'] = (float)($r->fetch_assoc()['size_mb'] ?? 0);
    }

    // Global status variables
    foreach (['Threads_connected', 'Slow_queries', 'Uptime'] as $var) {
        $r = $conn->query("SHOW GLOBAL STATUS LIKE '$var'");
        if ($r && $row = $r->fetch_assoc()) {
            switch ($var) {
                case 'Threads_connected': $metrics['connections']  = (int)$row['Value']; break;
                case 'Slow_queries':      $metrics['slow_queries'] = (int)$row['Value']; break;
                case 'Uptime':            $metrics['uptime_secs']  = (int)$row['Value']; break;
            }
        }
    }

    return $metrics;
}

// ── 5. APP METRICS ──────────────────────────────────────────
function get_app_metrics(mysqli $conn): array {
    $m = [
        'active_clients'    => 0,
        'suspended_clients' => 0,
        'active_subs'       => 0,
        'unpaid_invoices'   => 0,
        'open_tickets'      => 0,
        'errors_24h'        => 0,
    ];

    $queries = [
        'active_clients'    => "SELECT COUNT(*) FROM clients WHERE status='active'",
        'suspended_clients' => "SELECT COUNT(*) FROM clients WHERE status='suspended'",
        'active_subs'       => "SELECT COUNT(*) FROM subscriptions WHERE status='active'",
        'unpaid_invoices'   => "SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','overdue','draft','sent')",
        'open_tickets'      => "SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')",
        'errors_24h'        => "SELECT COUNT(*) FROM error_log WHERE level IN ('error','critical') AND created_at >= NOW() - INTERVAL 24 HOUR",
    ];

    foreach ($queries as $key => $sql) {
        $r = $conn->query($sql);
        if ($r) $m[$key] = (int)$r->fetch_row()[0];
    }

    return $m;
}

// ── COLLECT ALL METRICS ─────────────────────────────────────
$cpu    = get_cpu_pct();
$mem    = get_memory();
$disk   = get_disk();
$db     = get_db_metrics($conn);
$app    = get_app_metrics($conn);
$phpver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;

// ── INSERT INTO system_health ────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO system_health
      (server_load, memory_used_mb, memory_total_mb, memory_pct,
       disk_free_gb, disk_total_gb, disk_pct,
       db_size_mb, db_connections, db_slow_queries, db_uptime_secs, php_version,
       active_clients, suspended_clients, active_subs,
       unpaid_invoices, open_tickets, errors_24h,
       recorded_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
");

if (!$stmt) {
    echo "Prepare failed: " . $conn->error . PHP_EOL;
    exit(1);
}

$stmt->bind_param(
    'ddddddddiiissiiiii',
    $cpu,
    $mem['used'],  $mem['total'],  $mem['pct'],
    $disk['free'], $disk['total'], $disk['pct'],
    $db['size_mb'], $db['connections'], $db['slow_queries'], $db['uptime_secs'],
    $phpver,
    $app['active_clients'], $app['suspended_clients'], $app['active_subs'],
    $app['unpaid_invoices'], $app['open_tickets'], $app['errors_24h']
);

if ($stmt->execute()) {
    echo "[" . date('Y-m-d H:i:s') . "] Health snapshot recorded. CPU:{$cpu}% MEM:{$mem['pct']}% DISK:{$disk['pct']}%" . PHP_EOL;
} else {
    echo "Execute failed: " . $stmt->error . PHP_EOL;
}
$stmt->close();

// ── PRUNE OLD RECORDS — keep last 7 days only ────────────────
$conn->query("DELETE FROM system_health WHERE recorded_at < NOW() - INTERVAL 7 DAY");

echo "Done." . PHP_EOL;