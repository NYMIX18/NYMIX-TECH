<?php
// ============================================================
//  NYMIX TECH — Subscriptions Management
//  File: subscriptions.php
//  Requires: db.php (provides $conn as mysqli)
// ============================================================
session_start();
require_once '../includes/db.php';

// ── AUTH GUARD ──────────────────────────────────────────────
if (!isset($_SESSION['sa_id'])) {
    header('Location: dashboard.php');
    exit;
}

$admin_name = htmlspecialchars($_SESSION['sa_name'] ?? 'Admin');
$admin_role = htmlspecialchars($_SESSION['sa_role'] ?? 'support');

// ── FILTERS ─────────────────────────────────────────────────
$filter_status = $_GET['filter'] ?? 'all';
$filter_plan   = isset($_GET['plan']) ? (int)$_GET['plan'] : 0;
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;
$offset        = ($page - 1) * $per_page;

// ── FLASH MESSAGE ────────────────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── HANDLE ACTIONS ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $sub_id = (int)($_POST['sub_id'] ?? 0);

    if ($action === 'renew' && $sub_id) {
        $stmt = $conn->prepare("SELECT s.*, s.client_id AS cid FROM subscriptions s WHERE s.id = ? LIMIT 1");
        $stmt->bind_param('i', $sub_id);
        $stmt->execute();
        $sub = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($sub) {
            $months    = $sub['billing_cycle'] === 'quarterly' ? 3 : ($sub['billing_cycle'] === 'annual' ? 12 : 1);
            $new_start = $sub['end_date'];
            $new_end   = date('Y-m-d', strtotime($sub['end_date'] . " +{$months} months"));

            $upd = $conn->prepare("UPDATE subscriptions SET start_date=?, end_date=?, status='active', updated_at=NOW() WHERE id=?");
            $upd->bind_param('ssi', $new_start, $new_end, $sub_id);
            $upd->execute();
            $upd->close();

            $cid = (int)$sub['cid'];
            $conn->query("UPDATE clients SET status='active' WHERE id=$cid AND status IN ('read_only','grace','suspended')");

            $log     = $conn->prepare("INSERT INTO admin_activity_log (admin_id, client_id, action, details, ip_address) VALUES (?,?,'subscription_renewed',?,?)");
            $details = json_encode(['sub_id' => $sub_id, 'new_end' => $new_end]);
            $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
            $log->bind_param('iiss', $_SESSION['sa_id'], $cid, $details, $ip);
            $log->execute();
            $log->close();

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Subscription #$sub_id renewed until $new_end"];
        }
    }

    if ($action === 'cancel' && $sub_id) {
        $reason = trim($_POST['reason'] ?? 'Cancelled by admin');
        $stmt   = $conn->prepare("UPDATE subscriptions SET status='cancelled', cancelled_at=NOW(), cancel_reason=?, auto_renew=0, updated_at=NOW() WHERE id=?");
        $stmt->bind_param('si', $reason, $sub_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => "Subscription #$sub_id has been cancelled."];
    }

    if ($action === 'change_plan' && $sub_id) {
        $new_plan_id = (int)$_POST['new_plan_id'];
        $ps          = $conn->prepare("SELECT base_price, branch_addon_price FROM subscription_plans WHERE id=? LIMIT 1");
        $ps->bind_param('i', $new_plan_id);
        $ps->execute();
        $plan = $ps->get_result()->fetch_assoc();
        $ps->close();

        if ($plan) {
            $bc = $conn->prepare("SELECT branch_count FROM subscriptions WHERE id=? LIMIT 1");
            $bc->bind_param('i', $sub_id);
            $bc->execute();
            $sc       = $bc->get_result()->fetch_assoc();
            $bc->close();
            $branches = (int)($sc['branch_count'] ?? 1);
            $addon    = max(0, $branches - 1) * $plan['branch_addon_price'];
            $total    = $plan['base_price'] + $addon;

            $upd = $conn->prepare("UPDATE subscriptions SET plan_id=?, base_price=?, addon_price=?, total_monthly=?, updated_at=NOW() WHERE id=?");
            $upd->bind_param('idddi', $new_plan_id, $plan['base_price'], $addon, $total, $sub_id);
            $upd->execute();
            $upd->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Plan updated for subscription #$sub_id"];
        }
    }

    header('Location: subscriptions.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ── PLANS ────────────────────────────────────────────────────
$plans = [];
$res   = $conn->query("SELECT id, name, base_price FROM subscription_plans WHERE is_active=1 ORDER BY base_price");
if ($res) while ($r = $res->fetch_assoc()) $plans[] = $r;

// ── GET LATEST SUBSCRIPTION IDs PER CLIENT ───────────────────
// Step 1: fetch max(id) per client — simple GROUP BY, no joins, no subquery issues
$latest_ids_res = $conn->query("SELECT MAX(id) AS mid FROM subscriptions GROUP BY client_id");
$latest_ids     = [];
if ($latest_ids_res) {
    while ($row = $latest_ids_res->fetch_assoc()) {
        $latest_ids[] = (int)$row['mid'];
    }
}

// If no subscriptions at all, skip everything
$subscriptions = [];
$total_rows    = 0;
$total_pages   = 1;

if (!empty($latest_ids)) {
    $ids_str = implode(',', $latest_ids); // safe — all are cast to int

    // Step 2: build extra WHERE filters (all user input escaped)
    $filter_parts = [];

    if ($filter_status === 'expiring') {
        $filter_parts[] = "s.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        $filter_parts[] = "s.status IN ('active','grace')";
    } elseif ($filter_status && $filter_status !== 'all') {
        $safe           = $conn->real_escape_string($filter_status);
        $filter_parts[] = "s.status = '$safe'";
    }

    if ($filter_plan) {
        $filter_parts[] = "s.plan_id = " . (int)$filter_plan;
    }

    if ($search) {
        $esc            = $conn->real_escape_string($search);
        $like           = "%$esc%";
        $filter_parts[] = "(c.business_name LIKE '$like' OR c.phone LIKE '$like' OR c.email LIKE '$like')";
    }

    $extra = $filter_parts ? 'AND ' . implode(' AND ', $filter_parts) : '';

    // Step 3: count
    $count_sql = "
        SELECT COUNT(*)
        FROM subscriptions s
        JOIN clients c ON c.id = s.client_id
        JOIN subscription_plans sp ON sp.id = s.plan_id
        WHERE s.id IN ($ids_str)
        $extra
    ";
    $count_res = $conn->query($count_sql);
    if (!$count_res) {
        die('Count query failed: ' . htmlspecialchars($conn->error));
    }
    $total_rows  = (int)$count_res->fetch_row()[0];
    $total_pages = max(1, ceil($total_rows / $per_page));

    // Step 4: fetch page of data
    $lim      = (int)$per_page;
    $off      = (int)$offset;
    $data_sql = "
        SELECT
            c.id              AS client_id,
            c.business_name,
            c.phone,
            c.email,
            c.status          AS client_status,
            s.id              AS sub_id,
            s.plan_id,
            s.branch_count,
            s.base_price,
            s.addon_price,
            s.total_monthly,
            s.billing_cycle,
            s.start_date,
            s.end_date,
            s.grace_end_date,
            s.status          AS sub_status,
            s.auto_renew,
            s.cancelled_at,
            sp.name           AS plan_name,
            DATEDIFF(s.end_date, CURDATE()) AS days_remaining
        FROM subscriptions s
        JOIN clients c ON c.id = s.client_id
        JOIN subscription_plans sp ON sp.id = s.plan_id
        WHERE s.id IN ($ids_str)
        $extra
        ORDER BY
            CASE s.status
                WHEN 'grace'     THEN 0
                WHEN 'read_only' THEN 1
                WHEN 'active'    THEN 2
                ELSE 3
            END,
            s.end_date ASC
        LIMIT $lim OFFSET $off
    ";
    $data_res = $conn->query($data_sql);
    if (!$data_res) {
        die('Data query failed: ' . htmlspecialchars($conn->error));
    }
    $subscriptions = $data_res->fetch_all(MYSQLI_ASSOC);

    // Step 5: fetch total_paid per client for this page only
    if (!empty($subscriptions)) {
        $client_ids = array_unique(array_map(fn($r) => (int)$r['client_id'], $subscriptions));
        $cids_str   = implode(',', $client_ids);
        $paid_res   = $conn->query("SELECT client_id, COALESCE(SUM(amount),0) AS total_paid FROM subscription_payments WHERE client_id IN ($cids_str) GROUP BY client_id");
        $paid_map   = [];
        if ($paid_res) while ($pr = $paid_res->fetch_assoc()) $paid_map[(int)$pr['client_id']] = $pr['total_paid'];
        foreach ($subscriptions as &$s) $s['total_paid'] = $paid_map[(int)$s['client_id']] ?? 0;
        unset($s);
    }
}

// ── SUMMARY STATS ─────────────────────────────────────────────
$stats = ['active_count'=>0,'grace_count'=>0,'readonly_count'=>0,'lapsed_count'=>0,'total_mrr'=>0,'total_subs'=>0];
if (!empty($latest_ids)) {
    $sr = $conn->query("
        SELECT
            SUM(status='active')                              AS active_count,
            SUM(status='grace')                               AS grace_count,
            SUM(status='read_only')                           AS readonly_count,
            SUM(status IN ('expired','cancelled'))            AS lapsed_count,
            COALESCE(SUM(total_monthly),0)                    AS total_mrr,
            COUNT(*)                                          AS total_subs
        FROM subscriptions
        WHERE id IN ($ids_str)
    ");
    if ($sr) $stats = array_merge($stats, $sr->fetch_assoc());
}

// Expiring in 7 days
$expiring_count = 0;
$ec = $conn->query("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','grace') AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
if ($ec) $expiring_count = (int)$ec->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subscriptions — NYMIX TECH</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:#0B1F3A;--blue:#1A4A8A;--blue2:#2563C4;
  --accent:#E8A020;--teal:#0D9E75;--teal2:#0FC484;
  --red:#E03535;--red2:#FF5252;--amber:#F59E0B;--purple:#7C3AED;--coral:#E85D30;
  --bg:#080F1E;--bg2:#0E1829;--bg3:#131F35;--bg4:#1A2A42;
  --border:rgba(255,255,255,0.07);--border2:rgba(255,255,255,0.12);
  --text:#E8EDF5;--text2:#94A3B8;--text3:#60748A;
  --font-head:'Syne',sans-serif;--font-body:'DM Sans',sans-serif;
  --radius:14px;--sidebar:242px;--topbar:64px;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:var(--font-body);font-size:14px;line-height:1.6;overflow-x:hidden}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--bg4);border-radius:4px}
.layout{display:grid;grid-template-columns:var(--sidebar) 1fr;grid-template-rows:var(--topbar) 1fr;min-height:100vh}

/* Sidebar */
.sidebar{grid-row:1/-1;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto;z-index:20}
.sidebar-logo{padding:22px 20px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.logo-mark{width:34px;height:34px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--blue2),var(--accent));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:800;font-size:15px;color:#fff}
.logo-text{font-family:var(--font-head);font-weight:700;font-size:14px}
.logo-sub{font-size:10px;color:var(--text3);letter-spacing:.8px;text-transform:uppercase;margin-top:1px}
.nav-section{padding:16px 12px 6px;font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text3)}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 14px;margin:1px 8px;border-radius:9px;color:var(--text2);font-size:13.5px;text-decoration:none;transition:all .18s;width:calc(100% - 16px)}
.nav-item:hover{background:var(--bg3);color:var(--text)}
.nav-item.active{background:rgba(37,99,196,.18);color:var(--blue2);font-weight:500}
.nav-icon{width:18px;height:18px;opacity:.7;flex-shrink:0}
.nav-item.active .nav-icon{opacity:1}
.nav-badge{margin-left:auto;background:var(--red);color:#fff;font-size:10px;font-weight:600;padding:1px 7px;border-radius:20px;line-height:18px}
.nav-badge.info{background:rgba(37,99,196,.25);color:var(--blue2)}
.sidebar-footer{margin-top:auto;padding:14px 12px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px}
.avatar{width:32px;height:32px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--blue2),var(--purple));display:flex;align-items:center;justify-content:center;font-family:var(--font-head);font-weight:700;font-size:13px;color:#fff}
.avatar-info{flex:1;min-width:0}
.avatar-name{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.avatar-role{font-size:11px;color:var(--text3)}
.logout-btn{color:var(--text3);text-decoration:none;font-size:12px;padding:4px 8px;border-radius:6px;border:1px solid var(--border);transition:.15s;white-space:nowrap}
.logout-btn:hover{color:var(--red2);border-color:rgba(255,82,82,.3)}

/* Topbar */
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:16px;padding:0 28px;position:sticky;top:0;z-index:10}
.topbar-title{font-family:var(--font-head);font-size:16px;font-weight:700;flex:1}
.topbar-pill{display:flex;align-items:center;gap:6px;background:rgba(13,158,117,.12);border:1px solid rgba(13,158,117,.25);color:var(--teal2);font-size:12px;font-weight:500;padding:4px 12px;border-radius:20px}
.status-dot{width:6px;height:6px;background:var(--teal2);border-radius:50%;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.topbar-time{color:var(--text3);font-size:13px}

/* Main */
.main{padding:28px;overflow-y:auto;display:flex;flex-direction:column;gap:22px}

/* Page header */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.page-title{font-family:var(--font-head);font-size:22px;font-weight:800}
.page-sub{color:var(--text3);font-size:13px;margin-top:3px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-size:13px;font-weight:600;font-family:var(--font-body);cursor:pointer;text-decoration:none;border:none;transition:.18s;line-height:1}
.btn-primary{background:linear-gradient(135deg,var(--blue2),#1e4fa8);color:#fff;box-shadow:0 4px 14px rgba(37,99,196,.3)}
.btn-primary:hover{transform:translateY(-1px)}
.btn-ghost{background:var(--bg3);border:1px solid var(--border);color:var(--text2)}
.btn-ghost:hover{background:var(--bg4);color:var(--text)}
.btn-danger{background:linear-gradient(135deg,var(--red),#c42828);color:#fff}

/* Flash */
.flash{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:500}
.flash-success{background:rgba(13,158,117,.1);border:1px solid rgba(13,158,117,.25);color:var(--teal2)}
.flash-warning{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:var(--amber)}
.flash-error{background:rgba(224,53,53,.1);border:1px solid rgba(224,53,53,.25);color:var(--red2)}

/* KPI row */
.kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:14px}
.kpi{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;display:flex;flex-direction:column;gap:8px;position:relative;overflow:hidden;transition:.2s;cursor:pointer;text-decoration:none}
.kpi::after{content:'';position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--kpi-color,var(--blue2));opacity:.6;border-radius:0 0 var(--radius) var(--radius)}
.kpi:hover{border-color:var(--border2);transform:translateY(-1px)}
.kpi.kpi-selected{border-color:var(--kpi-color,var(--blue2));background:var(--bg3)}
.kpi-label{font-size:11px;color:var(--text3);font-weight:500;letter-spacing:.3px;text-transform:uppercase}
.kpi-val{font-family:var(--font-head);font-size:26px;font-weight:800;line-height:1}
.kpi-sub{font-size:11px;color:var(--text3)}

/* Toolbar */
.toolbar-wrap{padding:16px 22px;border-bottom:1px solid var(--border)}
.toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.search-wrap{flex:1;min-width:200px;position:relative}
.search-wrap svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--text3);pointer-events:none}
.search-input{width:100%;background:var(--bg3);border:1px solid var(--border2);color:var(--text);font-family:var(--font-body);font-size:13px;padding:9px 12px 9px 34px;border-radius:9px;outline:none;transition:.18s}
.search-input:focus{border-color:var(--blue2)}
.search-input::placeholder{color:var(--text3)}
.filter-select{background:var(--bg3);border:1px solid var(--border2);color:var(--text2);font-family:var(--font-body);font-size:13px;padding:9px 12px;border-radius:9px;outline:none;cursor:pointer}
.filter-select:focus{border-color:var(--blue2)}

/* Card */
.card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius)}
.card-head{display:flex;align-items:center;justify-content:space-between;padding:16px 22px;border-bottom:1px solid var(--border)}
.card-title{font-family:var(--font-head);font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px}
.card-badge{font-size:11px;padding:2px 9px;border-radius:20px;font-weight:600}
.badge-blue{background:rgba(37,99,196,.15);color:var(--blue2)}

/* Table */
.table-wrap{overflow-x:auto}
.data-table{width:100%;border-collapse:collapse;white-space:nowrap}
.data-table th{padding:10px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--text3);letter-spacing:.7px;text-transform:uppercase;border-bottom:1px solid var(--border);background:var(--bg3)}
.data-table td{padding:13px 16px;border-bottom:1px solid rgba(255,255,255,.035);font-size:13px;color:var(--text2);vertical-align:middle}
.data-table tr:last-child td{border-bottom:none}
.data-table tbody tr:hover td{background:rgba(255,255,255,.025);color:var(--text)}
.data-table td.primary{color:var(--text);font-weight:500}
.data-table td.muted{color:var(--text3);font-size:12px}

/* Badges */
.status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.status::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor;opacity:.8}
.s-active{background:rgba(13,158,117,.12);color:var(--teal2)}
.s-grace{background:rgba(245,158,11,.12);color:var(--amber)}
.s-read_only{background:rgba(245,158,11,.14);color:var(--amber)}
.s-expired{background:rgba(255,255,255,.06);color:var(--text3)}
.s-cancelled{background:rgba(255,255,255,.06);color:var(--text3)}

.plan-badge{display:inline-flex;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase}
.plan-starter{background:rgba(96,116,138,.12);color:var(--text2)}
.plan-business{background:rgba(37,99,196,.15);color:#60a5fa}
.plan-enterprise{background:rgba(232,160,32,.14);color:var(--accent)}

.cycle{font-size:11px;padding:2px 8px;border-radius:5px;background:rgba(255,255,255,.05);color:var(--text3)}

.days-chip{display:inline-flex;padding:3px 9px;border-radius:6px;font-size:12px;font-weight:700;font-family:var(--font-head)}
.days-urgent{background:rgba(224,53,53,.15);color:var(--red2)}
.days-warn{background:rgba(245,158,11,.15);color:var(--amber)}
.days-ok{background:rgba(13,158,117,.12);color:var(--teal2)}
.days-past{background:rgba(255,255,255,.05);color:var(--text3)}

/* Action buttons */
.action-row{display:flex;align-items:center;gap:5px}
.act-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:7px;font-size:12px;font-weight:600;font-family:var(--font-body);cursor:pointer;border:1px solid transparent;transition:.15s;text-decoration:none;background:none}
.act-view{background:rgba(255,255,255,.05);color:var(--text2);border-color:var(--border)}
.act-view:hover{background:rgba(255,255,255,.09);color:var(--text)}
.act-renew{background:rgba(13,158,117,.1);color:var(--teal2);border-color:rgba(13,158,117,.2)}
.act-renew:hover{background:rgba(13,158,117,.2)}
.act-plan{background:rgba(37,99,196,.1);color:var(--blue2);border-color:rgba(37,99,196,.2)}
.act-plan:hover{background:rgba(37,99,196,.2)}
.act-cancel{background:rgba(224,53,53,.08);color:var(--red2);border-color:rgba(224,53,53,.15)}
.act-cancel:hover{background:rgba(224,53,53,.18)}

/* Pagination */
.pagination{display:flex;align-items:center;gap:6px;padding:16px 22px;border-top:1px solid var(--border);flex-wrap:wrap}
.pagination-info{flex:1;font-size:12px;color:var(--text3)}
.pg-btn{min-width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:7px;font-size:13px;text-decoration:none;color:var(--text2);background:var(--bg3);border:1px solid var(--border);transition:.15s;padding:0 8px}
.pg-btn:hover{background:var(--bg4);color:var(--text)}
.pg-btn.active{background:rgba(37,99,196,.2);color:var(--blue2);border-color:rgba(37,99,196,.35);font-weight:700}
.pg-btn.disabled{opacity:.3;pointer-events:none}

/* Empty */
.empty-state{text-align:center;padding:48px 24px;color:var(--text3)}
.empty-icon{font-size:36px;margin-bottom:12px}
.empty-title{font-family:var(--font-head);font-size:16px;font-weight:700;color:var(--text2);margin-bottom:6px}

/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(3px)}
.modal-overlay.open{display:flex}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:18px;padding:32px;width:100%;max-width:440px;box-shadow:0 32px 80px rgba(0,0,0,.5);position:relative}
.modal-title{font-family:var(--font-head);font-size:18px;font-weight:800;margin-bottom:6px}
.modal-sub{color:var(--text3);font-size:13px;margin-bottom:22px}
.modal-close{position:absolute;top:16px;right:16px;background:none;border:none;color:var(--text3);cursor:pointer;font-size:20px;padding:4px 8px;border-radius:6px;transition:.15s}
.modal-close:hover{background:var(--bg3);color:var(--text)}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:11px;font-weight:600;color:var(--text3);letter-spacing:.7px;text-transform:uppercase;margin-bottom:7px}
.form-control{width:100%;background:var(--bg3);border:1px solid var(--border2);color:var(--text);font-family:var(--font-body);font-size:14px;padding:11px 13px;border-radius:10px;outline:none;transition:.18s}
.form-control:focus{border-color:var(--blue2)}
.modal-actions{display:flex;gap:10px;margin-top:22px;justify-content:flex-end}

/* Animations */
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.main>*{animation:fadeUp .3s ease both}
.main>*:nth-child(2){animation-delay:.05s}
.main>*:nth-child(3){animation-delay:.1s}
.main>*:nth-child(4){animation-delay:.15s}

@media(max-width:1024px){.kpi-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){:root{--sidebar:0px}.sidebar{display:none}.layout{grid-template-columns:1fr}.main{padding:16px}.kpi-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.kpi-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-mark">NT</div>
    <div>
      <div class="logo-text">NYMIX TECH</div>
      <div class="logo-sub">Super Admin</div>
    </div>
  </div>

  <div class="nav-section">Overview</div>
  <a class="nav-item" href="dashboard.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
    Dashboard
  </a>
  <a class="nav-item" href="health.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    System Health
  </a>

  <div class="nav-section">Clients</div>
  <a class="nav-item" href="clients.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    All Clients
  </a>
  <a class="nav-item" href="clients.php?status=read_only">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    Read-Only
  </a>
  <a class="nav-item" href="clients_new.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
    Onboard Client
  </a>

  <div class="nav-section">Billing</div>
  <a class="nav-item active" href="subscriptions.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
    Subscriptions
  </a>
  <a class="nav-item" href="invoices.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    Invoices
  </a>
  <a class="nav-item" href="payments.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
    Payments
  </a>

  <div class="nav-section">Support</div>
  <a class="nav-item" href="tickets.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    Tickets
  </a>
  <a class="nav-item" href="errors.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Error Log
  </a>

  <div class="nav-section">Settings</div>
  <a class="nav-item" href="admins.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
    Admin Users
  </a>
  <a class="nav-item" href="plans.php">
    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    Plans & Pricing
  </a>

  <div class="sidebar-footer">
    <div class="avatar"><?= strtoupper(substr($admin_name, 0, 1)) ?></div>
    <div class="avatar-info">
      <div class="avatar-name"><?= $admin_name ?></div>
      <div class="avatar-role"><?= $admin_role ?></div>
    </div>
    <a class="logout-btn" href="dashboard.php?action=logout">Sign out</a>
  </div>
</aside>

<!-- TOPBAR -->
<header class="topbar">
  <div class="topbar-title">Subscriptions</div>
  <div class="topbar-pill"><span class="status-dot"></span>System Online</div>
  <div class="topbar-time"><?= date('D, d M Y · H:i') ?></div>
</header>

<!-- MAIN -->
<main class="main">

  <?php if ($flash): ?>
  <div class="flash flash-<?= $flash['type'] ?>">
    <?= $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'warning' ? '⚠️' : '❌') ?>
    <?= htmlspecialchars($flash['msg']) ?>
  </div>
  <?php endif; ?>

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="page-title">💳 Subscriptions</div>
      <div class="page-sub">Manage all client subscriptions, renewals and billing cycles</div>
    </div>
    <a class="btn btn-primary" href="clients_new.php">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
      New Subscription
    </a>
  </div>

  <!-- KPI Row -->
  <div class="kpi-row">
    <a class="kpi <?= $filter_status==='all'?'kpi-selected':'' ?>" style="--kpi-color:var(--blue2)" href="subscriptions.php">
      <div class="kpi-label">Total</div>
      <div class="kpi-val"><?= (int)$stats['total_subs'] ?></div>
      <div class="kpi-sub">KES <?= number_format((float)$stats['total_mrr']) ?>/mo MRR</div>
    </a>
    <a class="kpi <?= $filter_status==='active'?'kpi-selected':'' ?>" style="--kpi-color:var(--teal2)" href="subscriptions.php?filter=active">
      <div class="kpi-label">Active</div>
      <div class="kpi-val" style="color:var(--teal2)"><?= (int)$stats['active_count'] ?></div>
      <div class="kpi-sub">Fully paid &amp; running</div>
    </a>
    <a class="kpi <?= $filter_status==='grace'?'kpi-selected':'' ?>" style="--kpi-color:var(--amber)" href="subscriptions.php?filter=grace">
      <div class="kpi-label">Grace Period</div>
      <div class="kpi-val" style="color:var(--amber)"><?= (int)$stats['grace_count'] ?></div>
      <div class="kpi-sub">3-day grace window</div>
    </a>
    <a class="kpi <?= $filter_status==='expiring'?'kpi-selected':'' ?>" style="--kpi-color:var(--coral)" href="subscriptions.php?filter=expiring">
      <div class="kpi-label">Expiring Soon</div>
      <div class="kpi-val" style="color:var(--coral)"><?= $expiring_count ?></div>
      <div class="kpi-sub">Next 7 days</div>
    </a>
    <a class="kpi <?= $filter_status==='cancelled'?'kpi-selected':'' ?>" style="--kpi-color:var(--text3)" href="subscriptions.php?filter=cancelled">
      <div class="kpi-label">Cancelled</div>
      <div class="kpi-val"><?= (int)$stats['lapsed_count'] ?></div>
      <div class="kpi-sub">Expired / cancelled</div>
    </a>
  </div>

  <!-- Table Card -->
  <div class="card">
    <!-- Filters -->
    <div class="toolbar-wrap">
      <form method="GET" action="subscriptions.php" class="toolbar">
        <?php if ($filter_status && $filter_status !== 'all'): ?>
          <input type="hidden" name="filter" value="<?= htmlspecialchars($filter_status) ?>">
        <?php endif; ?>
        <div class="search-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input class="search-input" type="text" name="q" placeholder="Search client, phone, email…" value="<?= htmlspecialchars($search) ?>">
        </div>
        <select class="filter-select" name="plan" onchange="this.form.submit()">
          <option value="0">All Plans</option>
          <?php foreach ($plans as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $filter_plan==$p['id']?'selected':'' ?>>
            <?= htmlspecialchars($p['name']) ?> — KES <?= number_format($p['base_price']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary" style="padding:9px 16px">Search</button>
        <?php if ($search || $filter_plan): ?>
        <a href="subscriptions.php?filter=<?= urlencode($filter_status) ?>" class="btn btn-ghost" style="padding:9px 14px">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="card-head">
      <div class="card-title">
        Subscriptions
        <span class="card-badge badge-blue"><?= number_format($total_rows) ?> results</span>
      </div>
      <span style="font-size:12px;color:var(--text3)">Page <?= $page ?> of <?= $total_pages ?></span>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Client</th>
            <th>Plan</th>
            <th>Branches</th>
            <th>Monthly</th>
            <th>Cycle</th>
            <th>Start</th>
            <th>Expires</th>
            <th>Remaining</th>
            <th>Status</th>
            <th>Total Paid</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($subscriptions)): ?>
          <tr><td colspan="12">
            <div class="empty-state">
              <div class="empty-icon">📋</div>
              <div class="empty-title">No subscriptions found</div>
              <div>Try adjusting your search or filter</div>
            </div>
          </td></tr>
        <?php else: foreach ($subscriptions as $s):
          $plan_cls = match(strtolower($s['plan_name'] ?? '')) {
            'starter'    => 'plan-starter',
            'business'   => 'plan-business',
            'enterprise' => 'plan-enterprise',
            default      => 'plan-starter'
          };
          $sub_status = $s['sub_status'] ?? 'expired';
          $st_cls     = 's-' . str_replace(' ', '_', $sub_status);
          $days       = (int)($s['days_remaining'] ?? -99);
          $days_cls   = $days < 0 ? 'days-past' : ($days <= 3 ? 'days-urgent' : ($days <= 7 ? 'days-warn' : 'days-ok'));
          $days_label = $days < 0 ? 'Expired' : ($days === 0 ? 'Today' : $days . 'd left');
          $can_renew  = in_array($sub_status, ['active','grace','read_only','expired']);
          $can_cancel = in_array($sub_status, ['active','grace']);
        ?>
          <tr>
            <td class="muted">#<?= $s['sub_id'] ?></td>
            <td>
              <div class="primary"><?= htmlspecialchars($s['business_name']) ?></div>
              <div class="muted"><?= htmlspecialchars($s['phone']) ?></div>
            </td>
            <td><span class="plan-badge <?= $plan_cls ?>"><?= htmlspecialchars($s['plan_name']) ?></span></td>
            <td style="text-align:center"><?= (int)$s['branch_count'] ?></td>
            <td style="color:var(--accent);font-family:var(--font-head);font-weight:700">KES <?= number_format($s['total_monthly']) ?></td>
            <td><span class="cycle"><?= ucfirst($s['billing_cycle']) ?></span></td>
            <td class="muted"><?= date('d M Y', strtotime($s['start_date'])) ?></td>
            <td style="<?= ($days<=7&&$days>=0)?'color:var(--amber)':'' ?>"><?= date('d M Y', strtotime($s['end_date'])) ?></td>
            <td><span class="days-chip <?= $days_cls ?>"><?= $days_label ?></span></td>
            <td><span class="status <?= $st_cls ?>"><?= str_replace('_',' ',$sub_status) ?></span></td>
            <td class="muted"><?= $s['total_paid'] > 0 ? 'KES '.number_format($s['total_paid']) : '—' ?></td>
            <td>
              <div class="action-row">
                <a class="act-btn act-view" href="clients_view.php?id=<?= $s['client_id'] ?>">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </a>
                <?php if ($can_renew): ?>
                <button class="act-btn act-renew" onclick="openRenewModal(<?= $s['sub_id'] ?>,'<?= htmlspecialchars(addslashes($s['business_name'])) ?>','<?= $s['end_date'] ?>','<?= $s['billing_cycle'] ?>')">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                  Renew
                </button>
                <?php endif; ?>
                <button class="act-btn act-plan" onclick="openPlanModal(<?= $s['sub_id'] ?>,'<?= htmlspecialchars(addslashes($s['business_name'])) ?>',<?= $s['plan_id'] ?>)">Plan</button>
                <?php if ($can_cancel): ?>
                <button class="act-btn act-cancel" onclick="openCancelModal(<?= $s['sub_id'] ?>,'<?= htmlspecialchars(addslashes($s['business_name'])) ?>')">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <div class="pagination-info">
        Showing <?= ($offset+1) ?>–<?= min($offset+$per_page,$total_rows) ?> of <?= number_format($total_rows) ?>
      </div>
      <?php
        $qs       = http_build_query(array_filter(['filter'=>$filter_status,'plan'=>$filter_plan?:null,'q'=>$search]));
        $base_url = 'subscriptions.php?'.($qs?$qs.'&':'');
        $s_pg     = max(1,$page-2);
        $e_pg     = min($total_pages,$page+2);
      ?>
      <a class="pg-btn <?= $page<=1?'disabled':'' ?>" href="<?= $base_url ?>page=<?= $page-1 ?>">‹</a>
      <?php if($s_pg>1) echo "<a class='pg-btn' href='{$base_url}page=1'>1</a>"; ?>
      <?php if($s_pg>2) echo "<span style='color:var(--text3);padding:0 4px'>…</span>"; ?>
      <?php for($i=$s_pg;$i<=$e_pg;$i++): ?>
      <a class="pg-btn <?= $i===$page?'active':'' ?>" href="<?= $base_url ?>page=<?= $i ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if($e_pg<$total_pages-1) echo "<span style='color:var(--text3);padding:0 4px'>…</span>"; ?>
      <?php if($e_pg<$total_pages) echo "<a class='pg-btn' href='{$base_url}page={$total_pages}'>{$total_pages}</a>"; ?>
      <a class="pg-btn <?= $page>=$total_pages?'disabled':'' ?>" href="<?= $base_url ?>page=<?= $page+1 ?>">›</a>
    </div>
    <?php endif; ?>
  </div>

</main>
</div>

<!-- MODALS -->

<!-- Renew -->
<div class="modal-overlay" id="renewModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('renewModal')">✕</button>
    <div class="modal-title">🔄 Renew Subscription</div>
    <div class="modal-sub" id="renewModalSub">Extend this client's subscription by one billing cycle.</div>
    <form method="POST">
      <input type="hidden" name="action" value="renew">
      <input type="hidden" name="sub_id" id="renewSubId">
      <div class="form-group">
        <label class="form-label">Current Expiry</label>
        <div id="renewCurrentEnd" style="color:var(--text2);font-size:14px;padding:10px 13px;background:var(--bg3);border-radius:9px;border:1px solid var(--border)"></div>
      </div>
      <div class="form-group">
        <label class="form-label">New Expiry (after renewal)</label>
        <div id="renewNewEnd" style="color:var(--teal2);font-size:14px;font-weight:600;padding:10px 13px;background:rgba(13,158,117,.08);border-radius:9px;border:1px solid rgba(13,158,117,.2)"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('renewModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Confirm Renewal</button>
      </div>
    </form>
  </div>
</div>

<!-- Cancel -->
<div class="modal-overlay" id="cancelModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('cancelModal')">✕</button>
    <div class="modal-title">⚠️ Cancel Subscription</div>
    <div class="modal-sub" id="cancelModalSub">This will immediately cancel the subscription.</div>
    <form method="POST">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="sub_id" id="cancelSubId">
      <div class="form-group">
        <label class="form-label">Cancellation Reason</label>
        <select class="form-control" name="reason">
          <option>Client requested cancellation</option>
          <option>Non-payment</option>
          <option>Business closed</option>
          <option>Switched to competitor</option>
          <option>Cancelled by admin</option>
          <option>Other</option>
        </select>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('cancelModal')">Go Back</button>
        <button type="submit" class="btn btn-danger">Cancel Subscription</button>
      </div>
    </form>
  </div>
</div>

<!-- Change Plan -->
<div class="modal-overlay" id="planModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal('planModal')">✕</button>
    <div class="modal-title">🔀 Change Plan</div>
    <div class="modal-sub" id="planModalSub">Select a new plan for this subscription.</div>
    <form method="POST">
      <input type="hidden" name="action" value="change_plan">
      <input type="hidden" name="sub_id" id="planSubId">
      <div class="form-group">
        <label class="form-label">New Plan</label>
        <select class="form-control" name="new_plan_id" id="planSelect">
          <?php foreach ($plans as $p): ?>
          <option value="<?= $p['id'] ?>">
            <?= htmlspecialchars($p['name']) ?> — KES <?= number_format($p['base_price']) ?>/mo base
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="padding:12px 14px;background:rgba(37,99,196,.08);border:1px solid rgba(37,99,196,.2);border-radius:9px;font-size:13px;color:var(--text2)">
        ℹ️ Pricing recalculates immediately. A new invoice may be needed for the difference.
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="closeModal('planModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Plan</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open') }
function closeModal(id) { document.getElementById(id).classList.remove('open') }

document.querySelectorAll('.modal-overlay').forEach(el =>
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open') })
);
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'))
});

function openRenewModal(subId, name, endDate, cycle) {
  document.getElementById('renewSubId').value = subId;
  document.getElementById('renewModalSub').textContent = 'Renewing subscription for: ' + name;
  document.getElementById('renewCurrentEnd').textContent = endDate;
  const d = new Date(endDate + 'T00:00:00');
  const months = cycle === 'quarterly' ? 3 : cycle === 'annual' ? 12 : 1;
  d.setMonth(d.getMonth() + months);
  const newEnd = d.toISOString().split('T')[0];
  document.getElementById('renewNewEnd').textContent = newEnd + ' (+' + months + ' month' + (months > 1 ? 's' : '') + ')';
  openModal('renewModal');
}

function openCancelModal(subId, name) {
  document.getElementById('cancelSubId').value = subId;
  document.getElementById('cancelModalSub').textContent = 'You are about to cancel the subscription for: ' + name;
  openModal('cancelModal');
}

function openPlanModal(subId, name, currentPlanId) {
  document.getElementById('planSubId').value = subId;
  document.getElementById('planModalSub').textContent = 'Change plan for: ' + name;
  document.getElementById('planSelect').value = currentPlanId;
  openModal('planModal');
}
</script>
</body>
</html>
