<?php
// ============================================================
//  NYMIX TECH — SOC Integration Patch for dashboard.php
//
//  HOW TO INTEGRATE:
//  ─────────────────
//  1. Run soc_schema.sql on your database first.
//  2. This file is split into clearly marked BLOCKS.
//     Each block has a comment telling you exactly WHERE
//     to paste it inside dashboard.php.
//  3. Search for the anchor comment in dashboard.php and
//     paste the block right after/before it.
// ============================================================


// ════════════════════════════════════════════════════════════
//  BLOCK 1 — HELPER FUNCTIONS
//  PASTE: right after the existing function redirect($tab) { ... }
//  (around line 70 in your dashboard.php)
// ════════════════════════════════════════════════════════════

/*
// ── GEO-IP LOOKUP ─────────────────────────────────────────
// Uses ip-api.com (free, no key, 45 req/min — plenty for admin logins)
function geoip_lookup(string $ip): array {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return []; // private/loopback — skip
    }
    $ctx = stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true]]);
    $raw = @file_get_contents("http://ip-api.com/json/{$ip}?fields=country,regionName,city,lat,lon,isp,status", false, $ctx);
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return ($d && $d['status']==='success') ? $d : [];
}

// ── LOG A LOGIN ATTEMPT ────────────────────────────────────
function log_login_attempt($conn, string $email, string $result, string $ip,
                            string $ua, ?int $admin_id, ?string $admin_name,
                            string $failure_reason = ''): void {
    $geo        = geoip_lookup($ip);
    $country    = $geo['country']    ?? null;
    $city       = $geo['city']       ?? null;
    $region     = $geo['regionName'] ?? null;
    $lat        = isset($geo['lat'])  ? (float)$geo['lat'] : null;
    $lon        = isset($geo['lon'])  ? (float)$geo['lon'] : null;
    $isp        = $geo['isp']        ?? null;

    $stmt = $conn->prepare("
        INSERT INTO login_attempts
          (email, admin_id, admin_name, result, ip_address, user_agent,
           country, city, region, latitude, longitude, isp, failure_reason, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
    ");
    $stmt->bind_param('sisssssssddss',
        $email, $admin_id, $admin_name, $result, $ip, $ua,
        $country, $city, $region, $lat, $lon, $isp, $failure_reason
    );
    $stmt->execute();
    $stmt->close();

    // Auto-raise brute-force alert if ≥5 failures from this IP in 15 min
    if ($result === 'failure') {
        $chk = $conn->prepare("
            SELECT COUNT(*) AS cnt, COUNT(DISTINCT email) AS emails
            FROM login_attempts
            WHERE ip_address=? AND result='failure' AND created_at >= NOW() - INTERVAL 15 MINUTE
        ");
        $chk->bind_param('s', $ip);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ((int)$row['cnt'] >= 5) {
            $dup = $conn->prepare("
                SELECT id FROM security_alerts
                WHERE ip_address=? AND alert_type='brute_force' AND is_resolved=0
                  AND created_at >= NOW() - INTERVAL 15 MINUTE LIMIT 1
            ");
            $dup->bind_param('s', $ip); $dup->execute();
            $already = $dup->get_result()->fetch_assoc(); $dup->close();

            if (!$already) {
                $desc = "Brute-force detected: {$row['cnt']} failed login attempts from $ip in 15 minutes targeting {$row['emails']} email(s).";
                $meta = json_encode(['attempts'=>$row['cnt'],'distinct_emails'=>$row['emails'],'ip'=>$ip]);
                $sev  = $row['cnt'] >= 10 ? 'critical' : 'high';
                $ins  = $conn->prepare("
                    INSERT INTO security_alerts
                      (alert_type, severity, ip_address, email, description, meta, created_at)
                    VALUES ('brute_force',?,?,?,?,?,NOW())
                ");
                $ins->bind_param('sssss', $sev, $ip, $email, $desc, $meta);
                $ins->execute(); $ins->close();
            }
        }

        // Credential spray alert: 1 IP hitting 10+ different emails
        if ((int)$row['emails'] >= 10) {
            $dup2 = $conn->prepare("
                SELECT id FROM security_alerts
                WHERE ip_address=? AND alert_type='credential_spray' AND is_resolved=0
                  AND created_at >= NOW() - INTERVAL 1 HOUR LIMIT 1
            ");
            $dup2->bind_param('s', $ip); $dup2->execute();
            $already2 = $dup2->get_result()->fetch_assoc(); $dup2->close();
            if (!$already2) {
                $desc2 = "Credential spray: $ip tried {$row['emails']} different email addresses.";
                $meta2 = json_encode(['emails'=>$row['emails'],'ip'=>$ip]);
                $ins2  = $conn->prepare("
                    INSERT INTO security_alerts
                      (alert_type, severity, ip_address, description, meta, created_at)
                    VALUES ('credential_spray','high',?,?,?,NOW())
                ");
                $ins2->bind_param('sss', $ip, $desc2, $meta2);
                $ins2->execute(); $ins2->close();
            }
        }
    }
}

// ── UPSERT ACTIVE SESSION ──────────────────────────────────
function upsert_session($conn, int $admin_id, string $admin_name, string $admin_role,
                         string $ip, string $ua, ?string $country, ?string $city): void {
    $token = hash('sha256', session_id());
    $stmt  = $conn->prepare("
        INSERT INTO admin_sessions
          (session_token, admin_id, admin_name, admin_role, ip_address, user_agent, country, city, started_at, last_active)
        VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())
        ON DUPLICATE KEY UPDATE last_active=NOW(), ended_at=NULL
    ");
    $stmt->bind_param('sissssss', $token, $admin_id, $admin_name, $admin_role, $ip, $ua, $country, $city);
    $stmt->execute(); $stmt->close();
}

// ── END SESSION ────────────────────────────────────────────
function end_session($conn): void {
    $token = hash('sha256', session_id());
    $stmt  = $conn->prepare("UPDATE admin_sessions SET ended_at=NOW() WHERE session_token=?");
    $stmt->bind_param('s', $token); $stmt->execute(); $stmt->close();
}

// ── WRITE AUDIT LOG ────────────────────────────────────────
function audit($conn, string $action, string $entity_type = '', $entity_id = null,
               string $entity_label = '', array $details = []): void {
    global $sa_role;
    $admin_id   = (int)($_SESSION['sa_id']   ?? 0);
    $admin_name = $_SESSION['sa_name']        ?? 'system';
    $admin_role = $_SESSION['sa_role']        ?? '';
    $ip         = $_SERVER['REMOTE_ADDR']     ?? '';
    $ua         = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $det_json   = !empty($details) ? json_encode($details) : null;

    $stmt = $conn->prepare("
        INSERT INTO audit_log
          (admin_id, admin_name, admin_role, action, entity_type, entity_id,
           entity_label, details, ip_address, user_agent, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
    ");
    $stmt->bind_param('isssssssss',
        $admin_id, $admin_name, $admin_role, $action,
        $entity_type, $entity_id, $entity_label, $det_json, $ip, $ua
    );
    $stmt->execute(); $stmt->close();
}
*/


// ════════════════════════════════════════════════════════════
//  BLOCK 2 — CALL log_login_attempt() INSIDE THE LOGIN BLOCK
//
//  FIND this inside your existing login block (around line 110):
//      if ($admin && password_verify($password, $admin['password_hash'])) {
//
//  REPLACE the success branch with:
// ════════════════════════════════════════════════════════════
/*
if ($admin && password_verify($password, $admin['password_hash'])) {
    unset($_SESSION[$attempt_key]);
    session_regenerate_id(true);
    $_SESSION['sa_id']         = $admin['id'];
    $_SESSION['sa_name']       = $admin['name'];
    $_SESSION['sa_role']       = $admin['role'];
    $_SESSION['last_activity'] = time();

    // ── SOC: log successful login + geo ──────────────────
    $ip   = $_SERVER['REMOTE_ADDR']     ?? '';
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    log_login_attempt($conn, $email, 'success', $ip, $ua, (int)$admin['id'], $admin['name']);

    $geo = geoip_lookup($ip);
    upsert_session($conn, (int)$admin['id'], $admin['name'], $admin['role'],
                   $ip, $ua, $geo['country'] ?? null, $geo['city'] ?? null);

    // ── New-country alert ────────────────────────────────
    if (!empty($geo['country'])) {
        $prev = $conn->prepare("
            SELECT id FROM login_attempts
            WHERE admin_id=? AND result='success' AND country != ? LIMIT 1
        ");
        $prev->bind_param('is', $admin['id'], $geo['country']);
        $prev->execute();
        if ($prev->get_result()->num_rows) {
            // Check we haven't already alerted for this country pair today
            $dup = $conn->prepare("
                SELECT id FROM security_alerts
                WHERE alert_type='new_country' AND admin_id=?
                  AND created_at >= CURDATE() LIMIT 1
            ");
            $dup->bind_param('i', $admin['id']); $dup->execute();
            if (!$dup->get_result()->fetch_assoc()) {
                $desc = "{$admin['name']} logged in from a new country: {$geo['country']} ({$geo['city']}).";
                $meta = json_encode(['country'=>$geo['country'],'city'=>$geo['city'],'ip'=>$ip]);
                $ins  = $conn->prepare("
                    INSERT INTO security_alerts
                      (alert_type, severity, ip_address, admin_id, admin_name, email, description, meta, created_at)
                    VALUES ('new_country','medium',?,?,?,?,?,?,NOW())
                ");
                $ins->bind_param('sisss', $ip, $admin['id'], $admin['name'], $email, $desc, $meta);
                $ins->execute(); $ins->close();
            }
            $dup->close();
        }
        $prev->close();
    }
    // ─────────────────────────────────────────────────────

    $lid = (int)$admin['id'];
    $upd = $conn->prepare("UPDATE super_admins SET last_login = NOW() WHERE id = ?");
    $upd->bind_param('i', $lid); $upd->execute(); $upd->close();
    header('Location: dashboard.php?tab=dashboard'); exit;

} else {
    $attempts['count']++;
    $login_error = 'Invalid credentials.';

    // ── SOC: log failed attempt ──────────────────────────
    $ip = $_SERVER['REMOTE_ADDR']     ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $aid  = $admin ? (int)$admin['id']   : null;
    $aname = $admin ? $admin['name']     : null;
    $reason = $admin ? 'wrong password'  : 'unknown email';
    log_login_attempt($conn, $email, 'failure', $ip, $ua, $aid, $aname, $reason);
    // ─────────────────────────────────────────────────────
}
*/


// ════════════════════════════════════════════════════════════
//  BLOCK 3 — END SESSION ON LOGOUT
//
//  FIND your logout block (around line 80):
//      if (isset($_GET['action']) && $_GET['action'] === 'logout') {
//
//  ADD end_session($conn); right before session_unset();
// ════════════════════════════════════════════════════════════
/*
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if ($is_logged_in) {
        end_session($conn); // ← ADD THIS LINE
        audit($conn, 'logout', 'admin', (int)$_SESSION['sa_id'], $_SESSION['sa_name'] ?? '');
    }
    session_unset(); session_destroy(); // ... rest unchanged
*/


// ════════════════════════════════════════════════════════════
//  BLOCK 4 — AUDIT CALLS ON CRUD ACTIONS
//
//  PASTE each audit() call right after the $conn->query / $stmt->execute
//  that performs the action in your existing CRUD blocks.
// ════════════════════════════════════════════════════════════
/*
// After client_add succeeds:
audit($conn, 'client_add', 'client', $conn->insert_id, $name);

// After client_edit succeeds:
audit($conn, 'client_edit', 'client', $id, $name);

// After client_delete:
audit($conn, 'client_delete', 'client', $id, "ID #$id");

// After sub_add:
audit($conn, 'sub_add', 'subscription', $new_sub_id, $sub['business_name'] ?? "client $cid");

// After invoice_mark_paid:
audit($conn, 'invoice_mark_paid', 'invoice', $id, "Invoice #$id");

// After payment_confirm:
audit($conn, 'payment_confirm', 'payment', $id, "Payment #$id");

// After admin_add:
audit($conn, 'admin_add', 'admin', $conn->insert_id, $name);

// After admin_delete:
audit($conn, 'admin_delete', 'admin', $id, "ID #$id");
*/


// ════════════════════════════════════════════════════════════
//  BLOCK 5 — UPSERT SESSION ON EVERY LOGGED-IN PAGE LOAD
//
//  FIND the line (around line 165):
//      $is_logged_in = isset($_SESSION['sa_id']);
//  ADD right below it:
// ════════════════════════════════════════════════════════════
/*
if ($is_logged_in) {
    $ip_s = $_SERVER['REMOTE_ADDR']     ?? '';
    $ua_s = $_SERVER['HTTP_USER_AGENT'] ?? '';
    upsert_session($conn, (int)$_SESSION['sa_id'], $_SESSION['sa_name'] ?? '',
                   $_SESSION['sa_role'] ?? '', $ip_s, $ua_s, null, null);

    // Prune sessions idle > 1 hour
    $conn->query("UPDATE admin_sessions SET ended_at=NOW() WHERE ended_at IS NULL AND last_active < NOW() - INTERVAL 1 HOUR");
}
*/


// ════════════════════════════════════════════════════════════
//  BLOCK 6 — SOC ALERT RESOLVE ACTION
//
//  PASTE inside the big if/elseif action chain (near $action === 'ticket_delete')
// ════════════════════════════════════════════════════════════
/*
if ($action === 'alert_resolve') {
    $aid   = (int)($_POST['id'] ?? 0);
    $admin = (int)$_SESSION['sa_id'];
    if ($aid) {
        $s = $conn->prepare("UPDATE security_alerts SET is_resolved=1, resolved_by=?, resolved_at=NOW() WHERE id=?");
        $s->bind_param('ii', $admin, $aid); $s->execute(); $s->close();
        audit($conn, 'alert_resolve', 'security_alert', $aid, "Alert #$aid");
    }
    redirect('security');
}
if ($action === 'alert_resolve_all') {
    $admin = (int)$_SESSION['sa_id'];
    $conn->prepare("UPDATE security_alerts SET is_resolved=1, resolved_by=?, resolved_at=NOW() WHERE is_resolved=0")
         ->bind_param('i', $admin);
    $s = $conn->prepare("UPDATE security_alerts SET is_resolved=1, resolved_by=?, resolved_at=NOW() WHERE is_resolved=0");
    $s->bind_param('i', $admin); $s->execute(); $s->close();
    redirect('security');
}
*/


// ════════════════════════════════════════════════════════════
//  BLOCK 7 — DATA FETCHING FOR THE SECURITY TAB
//
//  PASTE inside the DATA FETCHING section, right before the closing
//  PHP block that starts the HTML output (search for: $active_tab = $tab;)
// ════════════════════════════════════════════════════════════
/*
// SOC summary
$soc_defaults = ['logins_24h'=>0,'failures_24h'=>0,'successes_24h'=>0,
    'active_sessions'=>0,'open_alerts'=>0,'critical_alerts'=>0,
    'audit_events_24h'=>0,'suspicious_ips'=>0];
$soc_r   = $conn->query("SELECT * FROM v_soc_summary LIMIT 1");
$soc_sum = $soc_r ? array_merge($soc_defaults, $soc_r->fetch_assoc() ?? []) : $soc_defaults;

// Login attempts (last 200)
$login_log = [];
$ll_r = $conn->query("SELECT * FROM login_attempts ORDER BY created_at DESC LIMIT 200");
if ($ll_r) while ($row = $ll_r->fetch_assoc()) $login_log[] = $row;

// Active sessions
$active_sessions = [];
$as_r = $conn->query("
    SELECT * FROM admin_sessions
    WHERE ended_at IS NULL AND last_active >= NOW() - INTERVAL 1 HOUR
    ORDER BY last_active DESC
");
if ($as_r) while ($row = $as_r->fetch_assoc()) $active_sessions[] = $row;

// Security alerts (unresolved first, then resolved, last 100)
$sec_alerts = [];
$sa_r = $conn->query("
    SELECT a.*, sa.name AS resolved_by_name
    FROM security_alerts a
    LEFT JOIN super_admins sa ON sa.id = a.resolved_by
    ORDER BY a.is_resolved ASC, a.created_at DESC
    LIMIT 100
");
if ($sa_r) while ($row = $sa_r->fetch_assoc()) $sec_alerts[] = $row;

// Audit log (last 200)
$audit_entries = [];
$ae_r = $conn->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200");
if ($ae_r) while ($row = $ae_r->fetch_assoc()) $audit_entries[] = $row;

// Failed logins by IP (brute-force view)
$bf_ips = [];
$bf_r = $conn->query("SELECT * FROM v_failed_logins_24h LIMIT 30");
if ($bf_r) while ($row = $bf_r->fetch_assoc()) $bf_ips[] = $row;

// Geo breakdown of last 48h logins for the map/chart
$geo_countries = [];
$gc_r = $conn->query("
    SELECT country, COUNT(*) AS cnt, result
    FROM login_attempts
    WHERE created_at >= NOW() - INTERVAL 48 HOUR AND country IS NOT NULL
    GROUP BY country, result
    ORDER BY cnt DESC LIMIT 20
");
if ($gc_r) while ($row = $gc_r->fetch_assoc()) $geo_countries[] = $row;
*/
?>


<!-- ============================================================
     BLOCK 8 — SIDEBAR NAV ITEM
     PASTE inside <nav class="sb-nav"> right after the Admins nav-item
     ============================================================ -->
<!--
<div class="nav-lbl">Security</div>
<a href="?tab=security" class="nav-item <?= $active_tab==='security'?'active':'' ?>">
  <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
  Security (SOC)
  <?php
    $open_alert_cnt = (int)($soc_sum['critical_alerts'] ?? 0);
    if ($open_alert_cnt): ?>
  <span class="nav-badge"><?= $open_alert_cnt ?></span>
  <?php endif; ?>
</a>
-->


<?php
// ════════════════════════════════════════════════════════════
//  BLOCK 9 — THE FULL SECURITY TAB HTML
//
//  PASTE right before the closing:   <?php endif; // end dashboard ?>
//  or after the last  <?php endif; ?>  block in the tabs section.
// ════════════════════════════════════════════════════════════
?>

<!-- PASTE START ─────────────────────────────────────────── -->
<?php if ($active_tab === 'security'): ?>

<div class="page-header">
  <div>
    <div class="page-eyebrow">Security Operations Centre</div>
    <div class="page-title">Security</div>
    <div class="page-sub">
      Login attempts · Active sessions · Brute-force alerts · Geo-location · Audit trail
    </div>
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

<!-- ── SOC STAT CARDS ── -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:22px;">

  <div class="stat-card">
    <div class="sc-icon" style="background:var(--blue-d);">
      <svg viewBox="0 0 24 24" stroke="var(--blue)"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
    </div>
    <div class="sc-label">Logins (24h)</div>
    <div class="sc-value"><?= number_format($soc_sum['logins_24h']) ?></div>
    <div class="sc-change up"><?= $soc_sum['successes_24h'] ?> success</div>
  </div>

  <div class="stat-card">
    <div class="sc-icon" style="background:var(--red-d);">
      <svg viewBox="0 0 24 24" stroke="var(--red)"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    </div>
    <div class="sc-label">Failed Attempts (24h)</div>
    <div class="sc-value" style="color:<?= $soc_sum['failures_24h']>0?'var(--red)':'var(--tx)' ?>;"><?= number_format($soc_sum['failures_24h']) ?></div>
    <div class="sc-change <?= $soc_sum['failures_24h']>0?'down':'' ?>"><?= $soc_sum['suspicious_ips'] ?> suspicious IPs</div>
  </div>

  <div class="stat-card">
    <div class="sc-icon" style="background:var(--green-d);">
      <svg viewBox="0 0 24 24" stroke="var(--green)"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="23 11 17 11"/></svg>
    </div>
    <div class="sc-label">Active Sessions</div>
    <div class="sc-value"><?= number_format($soc_sum['active_sessions']) ?></div>
    <div class="sc-change">Right now</div>
  </div>

  <div class="stat-card">
    <div class="sc-icon" style="background:<?= $soc_sum['critical_alerts']>0?'var(--red-d)':'var(--amber-d)' ?>;">
      <svg viewBox="0 0 24 24" stroke="<?= $soc_sum['critical_alerts']>0?'var(--red)':'var(--amber)' ?>">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
    </div>
    <div class="sc-label">Open Alerts</div>
    <div class="sc-value" style="color:<?= $soc_sum['open_alerts']>0?'var(--red)':'var(--green)' ?>;"><?= number_format($soc_sum['open_alerts']) ?></div>
    <div class="sc-change <?= $soc_sum['critical_alerts']>0?'down':'' ?>"><?= $soc_sum['critical_alerts'] ?> critical</div>
  </div>

  <div class="stat-card">
    <div class="sc-icon" style="background:var(--purple-d);">
      <svg viewBox="0 0 24 24" stroke="var(--purple)"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    </div>
    <div class="sc-label">Audit Events (24h)</div>
    <div class="sc-value"><?= number_format($soc_sum['audit_events_24h']) ?></div>
    <div class="sc-change">Admin actions logged</div>
  </div>

</div>

<!-- ── ACTIVE SESSIONS + BRUTE-FORCE IPS ── -->
<div class="grid-2" style="margin-bottom:20px;">

  <!-- Active Sessions -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        Active Sessions
        <?php if (!empty($active_sessions)): ?>
        <span style="background:var(--green-d);color:var(--green);font-family:'DM Mono',monospace;font-size:10px;padding:2px 8px;border-radius:10px;"><?= count($active_sessions) ?> online</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body-flush">
      <?php if (empty($active_sessions)): ?>
      <div class="empty-state" style="padding:30px 20px;">
        <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <h3>No active sessions</h3>
        <p>Sessions appear here once admins log in.</p>
      </div>
      <?php else: ?>
      <?php foreach ($active_sessions as $sess):
        $idle = round((time() - strtotime($sess['last_active'])) / 60);
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--line);">
        <div style="position:relative;flex-shrink:0;">
          <div style="width:36px;height:36px;border-radius:10px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:var(--blue);">
            <?= strtoupper(substr($sess['admin_name'],0,2)) ?>
          </div>
          <div style="position:absolute;bottom:-2px;right:-2px;width:9px;height:9px;border-radius:50%;background:var(--green);border:2px solid var(--ink2);"></div>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:600;"><?= esc($sess['admin_name']) ?></div>
          <div style="font-size:11px;color:var(--tx3);display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            <span><?= esc($sess['ip_address']) ?></span>
            <?php if ($sess['country']): ?><span>·</span><span>📍 <?= esc($sess['city'] ? $sess['city'].', '.$sess['country'] : $sess['country']) ?></span><?php endif; ?>
          </div>
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

  <!-- Brute-Force IPs -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Suspicious IPs (24h)
        <?php if (!empty($bf_ips)): ?>
        <span style="background:var(--red-d);color:var(--red);font-family:'DM Mono',monospace;font-size:10px;padding:2px 8px;border-radius:10px;"><?= count($bf_ips) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body-flush">
      <?php if (empty($bf_ips)): ?>
      <div class="empty-state" style="padding:30px 20px;">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
        <h3>No suspicious IPs detected</h3>
        <p>All login traffic looks normal.</p>
      </div>
      <?php else: ?>
      <?php foreach (array_slice($bf_ips, 0, 8) as $bfip):
        $risk = $bfip['attempt_count'] >= 10 ? ['critical','var(--red)'] : ['high','var(--amber)'];
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:11px 16px;border-bottom:1px solid var(--line);">
        <div style="width:34px;height:34px;border-radius:9px;background:var(--red-d);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:var(--tx);"><?= esc($bfip['ip_address']) ?></div>
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

<!-- ── SECURITY ALERTS ── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <div class="card-title">
      <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Security Alerts
      <?php $ua_cnt = count(array_filter($sec_alerts, fn($a)=>!$a['is_resolved'])); ?>
      <?php if ($ua_cnt): ?><span style="background:var(--red-d);color:var(--red);font-family:'DM Mono',monospace;font-size:10px;padding:2px 8px;border-radius:10px;"><?= $ua_cnt ?> open</span><?php endif; ?>
    </div>
  </div>
  <div class="card-body-flush"><div class="table-wrap">
    <?php if (empty($sec_alerts)): ?>
    <div class="empty-state" style="padding:40px 20px;">
      <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
      <h3>No security alerts</h3><p>System is clean — no threats detected.</p>
    </div>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Type</th><th>Severity</th><th>IP</th><th>Admin / Email</th><th>Description</th><th>Time</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($sec_alerts as $al):
        $sev_color = match($al['severity']) {
          'critical' => 'var(--red)',
          'high'     => 'var(--amber)',
          'medium'   => 'var(--cyan)',
          default    => 'var(--tx3)',
        };
        $type_labels = [
          'brute_force'       => '🔨 Brute Force',
          'credential_spray'  => '💧 Cred Spray',
          'csrf_violation'    => '🛡 CSRF',
          'rate_limit'        => '⚡ Rate Limit',
          'session_hijack'    => '👤 Session Hijack',
          'impossible_travel' => '✈️ Impossible Travel',
          'new_country'       => '🌍 New Country',
          'suspicious_ua'     => '🤖 Suspicious UA',
        ];
        $type_lbl = $type_labels[$al['alert_type']] ?? ucfirst($al['alert_type']);
      ?>
      <tr style="<?= !$al['is_resolved'] ? 'background:rgba(240,64,96,0.02);' : 'opacity:0.6;' ?>">
        <td class="td-mono">#<?= $al['id'] ?></td>
        <td><span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:600;white-space:nowrap;"><?= $type_lbl ?></span></td>
        <td><span style="font-family:'DM Mono',monospace;font-size:10px;font-weight:700;text-transform:uppercase;color:<?= $sev_color ?>;"><?= $al['severity'] ?></span></td>
        <td class="td-mono"><?= esc($al['ip_address']??'—') ?></td>
        <td class="td-muted" style="font-size:12px;"><?= esc($al['admin_name'] ?? $al['email'] ?? '—') ?></td>
        <td style="max-width:260px;font-size:12px;color:var(--tx2);"><?= esc($al['description']) ?></td>
        <td class="td-mono" style="font-size:11px;white-space:nowrap;"><?= date('d M H:i', strtotime($al['created_at'])) ?></td>
        <td>
          <?php if ($al['is_resolved']): ?>
            <span class="badge badge-active">✓ Resolved</span>
          <?php else: ?>
            <span class="badge badge-suspended">⚠ Open</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$al['is_resolved']): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="_action" value="alert_resolve">
            <input type="hidden" name="id" value="<?= $al['id'] ?>">
            <?= csrf_token_field() ?>
            <button type="submit" class="btn btn-success btn-sm">Resolve</button>
          </form>
          <?php else: ?>
          <span style="font-size:11px;color:var(--tx3);"><?= esc($al['resolved_by_name']??'—') ?></span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div></div>
</div>

<!-- ── GEO-LOCATION + LOGIN TIMELINE ── -->
<div class="grid-2" style="margin-bottom:20px;">

  <!-- Country breakdown -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        Login Geo-location (48h)
      </div>
    </div>
    <div class="card-body">
      <?php if (empty($geo_countries)): ?>
      <div class="empty-state" style="padding:24px 0;"><h3>No geo data yet</h3><p>Geo-location appears after first login attempt.</p></div>
      <?php else:
        // Group by country
        $by_country = [];
        foreach ($geo_countries as $gc) {
          $c = $gc['country'] ?? 'Unknown';
          if (!isset($by_country[$c])) $by_country[$c] = ['success'=>0,'failure'=>0];
          $by_country[$c][$gc['result']] = (int)$gc['cnt'];
        }
        arsort($by_country);
        $max_total = max(array_map(fn($v)=>$v['success']+$v['failure'], $by_country)) ?: 1;
      ?>
      <?php foreach ($by_country as $country => $counts):
        $total = $counts['success'] + $counts['failure'];
        $pct   = round($total / $max_total * 100);
        $is_ke = ($country === 'Kenya');
      ?>
      <div style="margin-bottom:13px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
          <span style="color:<?= $is_ke?'var(--tx)':'var(--tx2)' ?>;font-weight:<?= $is_ke?'600':'400' ?>;">
            <?= $is_ke ? '🇰🇪 ' : '🌍 ' ?><?= esc($country) ?>
          </span>
          <span style="font-family:'DM Mono',monospace;color:var(--tx3);">
            <span style="color:var(--green);"><?= $counts['success'] ?>✓</span>
            <?php if ($counts['failure']): ?> <span style="color:var(--red);"><?= $counts['failure'] ?>✗</span><?php endif; ?>
          </span>
        </div>
        <div class="prog-bar">
          <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $counts['failure']>0?'var(--amber)':'var(--green)' ?>;"></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Login Activity Chart -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        Login Activity (24h)
      </div>
    </div>
    <div class="card-body">
      <div class="chart-wrap"><canvas id="loginActivityChart"></canvas></div>
    </div>
  </div>

</div>

<!-- ── LOGIN ATTEMPTS TABLE ── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <div class="card-title">
      <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
      Login Attempts Log
    </div>
    <div style="display:flex;gap:9px;align-items:center;">
      <span style="font-size:12px;color:var(--tx3);">Last 200 entries</span>
    </div>
  </div>
  <div class="card-body-flush"><div class="table-wrap">
    <?php if (empty($login_log)): ?>
    <div class="empty-state" style="padding:30px 20px;"><h3>No login attempts recorded yet</h3><p>Attempts will appear here once the logging code is integrated.</p></div>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Result</th><th>Email</th><th>Admin</th><th>IP Address</th><th>Country / City</th><th>ISP</th><th>Reason</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($login_log as $ll): ?>
      <tr>
        <td class="td-mono"><?= $ll['id'] ?></td>
        <td>
          <?php if ($ll['result'] === 'success'): ?>
            <span class="badge badge-active">✓ Success</span>
          <?php elseif ($ll['result'] === 'blocked'): ?>
            <span class="badge badge-suspended">⛔ Blocked</span>
          <?php else: ?>
            <span class="badge badge-suspended">✗ Failed</span>
          <?php endif; ?>
        </td>
        <td class="td-mono" style="font-size:12px;"><?= esc($ll['email']) ?></td>
        <td class="td-muted"><?= esc($ll['admin_name']??'—') ?></td>
        <td>
          <span style="font-family:'DM Mono',monospace;font-size:12px;color:<?= $ll['result']==='failure'?'var(--red)':'var(--tx2)' ?>;"><?= esc($ll['ip_address']) ?></span>
        </td>
        <td style="font-size:12px;">
          <?php if ($ll['country']): ?>
            <?= $ll['country']==='Kenya'?'🇰🇪 ':'🌍 ' ?><?= esc($ll['city'] ? $ll['city'].', '.$ll['country'] : $ll['country']) ?>
          <?php else: ?>
            <span class="td-muted">—</span>
          <?php endif; ?>
        </td>
        <td class="td-muted" style="font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= esc($ll['isp']??'—') ?></td>
        <td class="td-muted" style="font-size:12px;"><?= esc($ll['failure_reason']??'—') ?></td>
        <td class="td-mono" style="font-size:11px;white-space:nowrap;"><?= date('d M H:i:s', strtotime($ll['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div></div>
</div>

<!-- ── AUDIT LOG TABLE ── -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <div class="card-title">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Audit Log — Who Did What
    </div>
    <span style="font-size:12px;color:var(--tx3);"><?= $soc_sum['audit_events_24h'] ?> events in last 24h</span>
  </div>
  <div class="card-body-flush"><div class="table-wrap">
    <?php if (empty($audit_entries)): ?>
    <div class="empty-state" style="padding:30px 20px;"><h3>No audit entries yet</h3><p>Add the audit() calls (Block 4) to your CRUD actions to start logging.</p></div>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Admin</th><th>Role</th><th>Action</th><th>Entity</th><th>Label</th><th>IP</th><th>Time</th></tr></thead>
      <tbody>
      <?php foreach ($audit_entries as $ae):
        $action_colors = [
          'client_add'       => 'var(--green)',
          'client_edit'      => 'var(--blue)',
          'client_delete'    => 'var(--red)',
          'sub_add'          => 'var(--cyan)',
          'invoice_mark_paid'=> 'var(--green)',
          'payment_confirm'  => 'var(--green)',
          'admin_add'        => 'var(--gold)',
          'admin_delete'     => 'var(--red)',
          'logout'           => 'var(--tx3)',
          'alert_resolve'    => 'var(--purple)',
        ];
        $ac = $action_colors[$ae['action']] ?? 'var(--tx2)';
      ?>
      <tr>
        <td class="td-mono"><?= $ae['id'] ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:7px;">
            <div style="width:26px;height:26px;border-radius:7px;background:var(--blue-d);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:var(--blue);flex-shrink:0;"><?= strtoupper(substr($ae['admin_name']??'?',0,2)) ?></div>
            <span style="font-size:13px;font-weight:600;"><?= esc($ae['admin_name']??'System') ?></span>
          </div>
        </td>
        <td><span class="badge badge-<?= strtolower($ae['admin_role']??'support') ?>"><?= ucfirst($ae['admin_role']??'—') ?></span></td>
        <td><span style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:<?= $ac ?>;"><?= esc($ae['action']) ?></span></td>
        <td class="td-mono" style="font-size:11px;"><?= esc($ae['entity_type']??'—') ?></td>
        <td style="max-width:160px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= esc($ae['entity_label']??'') ?>"><?= esc($ae['entity_label']??'—') ?></td>
        <td class="td-mono" style="font-size:11px;"><?= esc($ae['ip_address']??'—') ?></td>
        <td class="td-mono" style="font-size:11px;white-space:nowrap;"><?= date('d M H:i', strtotime($ae['created_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div></div>
</div>

<!-- Login Activity Chart JS -->
<script>
(function(){
  var el = document.getElementById('loginActivityChart');
  if (!el) return;

  // Build hourly buckets from PHP data (last 24h)
  var rawLog = <?= json_encode(array_map(fn($l)=>[
    'hour'   => (int)date('H', strtotime($l['created_at'])),
    'result' => $l['result'],
  ], $login_log)) ?>;

  var hours   = Array.from({length:24},(_,i)=>i);
  var success = new Array(24).fill(0);
  var failure = new Array(24).fill(0);
  rawLog.forEach(function(r){
    if(r.result==='success') success[r.hour]++;
    else failure[r.hour]++;
  });
  var labels = hours.map(function(h){return h.toString().padStart(2,'0')+':00';});

  new Chart(el, {
    type:'bar',
    data:{
      labels:labels,
      datasets:[
        {label:'Success',data:success,backgroundColor:'rgba(14,200,122,0.55)',borderRadius:4},
        {label:'Failed', data:failure, backgroundColor:'rgba(240,64,96,0.55)', borderRadius:4},
      ]
    },
    options:{
      responsive:true,maintainAspectRatio:false,
      interaction:{mode:'index',intersect:false},
      plugins:{
        legend:{display:true,labels:{color:'#7A93B8',font:{family:"'DM Mono',monospace",size:10}}},
        tooltip:{backgroundColor:'#090E1B',borderColor:'rgba(255,255,255,0.1)',borderWidth:1,padding:10,titleColor:'#7A93B8',bodyColor:'#E2EBF8'}
      },
      scales:{
        x:{grid:{color:'rgba(255,255,255,0.03)'},ticks:{color:'#3E546F',font:{family:"'DM Mono',monospace",size:9},maxTicksLimit:12}},
        y:{grid:{color:'rgba(255,255,255,0.03)'},ticks:{color:'#3E546F',font:{family:"'DM Mono',monospace",size:10},stepSize:1}}
      }
    }
  });
})();
</script>

<?php endif; // end security tab ?>
<!-- PASTE END ───────────────────────────────────────────── -->