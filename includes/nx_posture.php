<?php
// ============================================================================
//  NYMIX SECURITY POSTURE  -  includes/nx_posture.php          (NEW FILE)
//  Runs ~25 automatic checks and returns a 0-100 score, a letter grade and a
//  fix-list.  Localhost aware: things that only matter on a live server
//  (HTTPS, HSTS, secure cookies) are shown as "info" and do NOT hurt the score
//  while you develop on XAMPP - they become real checks the day $isLocal=false.
// ============================================================================
if (defined('NX_POSTURE_LOADED')) { return; }
define('NX_POSTURE_LOADED', true);
require_once __DIR__ . '/nx_malware.php';

function nx_posture_run(mysqli $c): array {
    $local = !empty($GLOBALS['isLocal']) || in_array(strtolower($_SERVER['SERVER_NAME'] ?? ''), ['localhost', '127.0.0.1', '::1'], true);
    $root  = rtrim(NX_MAL_ROOT, '/');
    $web   = PHP_SAPI !== 'cli';
    $chk   = [];
    // status: pass | warn | fail | info(not scored)       w = weight
    $add = function (string $id, string $cat, string $title, int $w, string $status, string $detail, string $fix = '') use (&$chk) {
        $chk[] = ['id' => $id, 'cat' => $cat, 'title' => $title, 'w' => $w, 'status' => $status, 'detail' => $detail, 'fix' => $fix];
    };
    $one = function (string $sql) use ($c): int { try { $r = $c->query($sql); $x = $r ? $r->fetch_row() : null; return $x ? (int)$x[0] : 0; } catch (Throwable $e) { return 0; } };
    $ini = fn(string $k) => strtolower(trim((string)ini_get($k)));
    $off = fn(string $v) => in_array($v, ['', '0', 'off', 'false', 'no', 'stderr'], true);

    // ---------- PLATFORM ----------
    $v = PHP_VERSION_ID;
    $add('php_version', 'Platform', 'PHP version is current', 6, $v >= 80300 ? 'pass' : ($v >= 80200 ? 'warn' : 'fail'),
         'Running PHP ' . PHP_VERSION, 'Upgrade XAMPP / your host to a currently supported PHP release (8.3+).');
    $add('display_errors', 'Platform', 'Errors are not shown to visitors', 8, $off($ini('display_errors')) ? 'pass' : 'fail',
         'display_errors = ' . ($ini('display_errors') ?: 'off'), 'Set display_errors=Off in php.ini. Error pages leak file paths and SQL.');
    $add('expose_php', 'Platform', 'PHP version header hidden', 2, $off($ini('expose_php')) ? 'pass' : 'warn',
         'expose_php = ' . ($ini('expose_php') ?: 'off'), 'Set expose_php=Off so scanners cannot read your PHP version.');
    $add('url_include', 'Platform', 'Remote file inclusion disabled', 8, $off($ini('allow_url_include')) ? 'pass' : 'fail',
         'allow_url_include = ' . ($ini('allow_url_include') ?: 'off'), 'Set allow_url_include=Off.');
    $add('log_errors', 'Platform', 'Errors are logged to a file', 2, !$off($ini('log_errors')) ? 'pass' : 'warn', 'log_errors = ' . ($ini('log_errors') ?: 'off'), 'Set log_errors=On.');

    // ---------- TRANSPORT / SESSIONS ----------
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$web)        $add('https', 'Transport', 'HTTPS in use', 10, 'info', 'Cannot be checked from the command line.');
    elseif ($local)   $add('https', 'Transport', 'HTTPS in use', 10, 'info', 'Localhost runs on HTTP - fine while developing. This becomes a scored check on your live server.');
    else              $add('https', 'Transport', 'HTTPS in use', 10, $https ? 'pass' : 'fail', $https ? 'Connection is encrypted.' : 'This page was served over plain HTTP.', 'Install an SSL certificate and force HTTPS.');
    if ($web) {
        $hl = implode("\n", headers_list());
        $has = fn(string $h) => stripos($hl, $h . ':') !== false;
        $add('hdr_core', 'Transport', 'Security headers sent (CSP, X-Frame-Options, nosniff)', 6,
             ($has('Content-Security-Policy') && $has('X-Frame-Options') && $has('X-Content-Type-Options')) ? 'pass' : 'warn',
             'Checked on the admin response.', 'Send Content-Security-Policy, X-Frame-Options and X-Content-Type-Options on every page.');
        $csp = '';
        foreach (headers_list() as $h) if (stripos($h, 'Content-Security-Policy:') === 0) $csp = $h;
        $add('csp_inline', 'Transport', "CSP does not allow 'unsafe-inline' scripts", 4, $csp === '' ? 'warn' : (stripos($csp, "script-src") !== false && preg_match("/script-src[^;]*'unsafe-inline'/i", $csp) ? 'warn' : 'pass'),
             $csp === '' ? 'No CSP header found.' : (preg_match("/script-src[^;]*'unsafe-inline'/i", $csp) ? "script-src allows 'unsafe-inline' - an injected <script> would still run." : 'Inline scripts are blocked.'),
             "Move inline <script> blocks to files or use nonces, then remove 'unsafe-inline'.");
        $add('hsts', 'Transport', 'HSTS enabled', 3, $local ? 'info' : ($has('Strict-Transport-Security') ? 'pass' : 'warn'), $local ? 'Only relevant on HTTPS.' : 'Strict-Transport-Security header.', 'Send Strict-Transport-Security once HTTPS works.');
    }
    $add('sess_httponly', 'Sessions', 'Session cookie is HttpOnly + SameSite', 5,
         (!$off($ini('session.cookie_httponly')) && in_array($ini('session.cookie_samesite'), ['strict', 'lax'], true)) ? 'pass' : ($web ? 'warn' : 'info'),
         'httponly=' . ($ini('session.cookie_httponly') ?: 'off') . ', samesite=' . ($ini('session.cookie_samesite') ?: 'none'), 'Set session.cookie_httponly=1 and session.cookie_samesite=Strict before session_start().');
    $add('sess_strict', 'Sessions', 'Strict session mode (no fixation)', 4, !$off($ini('session.use_strict_mode')) ? 'pass' : ($web ? 'warn' : 'info'), 'session.use_strict_mode = ' . ($ini('session.use_strict_mode') ?: 'off'), 'Set session.use_strict_mode=1.');
    $add('sess_life', 'Sessions', 'Session lifetime is 1 hour or less', 2, ((int)ini_get('session.gc_maxlifetime') <= 3600) ? 'pass' : 'warn', 'gc_maxlifetime = ' . (int)ini_get('session.gc_maxlifetime') . 's', 'Lower session.gc_maxlifetime to 3600.');

    // ---------- DATABASE ----------
    $rootNoPw = defined('DB_USER') && DB_USER === 'root' && defined('DB_PASS') && DB_PASS === '';
    $add('db_root', 'Database', 'App does not connect as root with no password', 10, !$rootNoPw ? 'pass' : ($local ? 'warn' : 'fail'),
         $rootNoPw ? 'db.php connects as root with an empty password.' : 'Dedicated database user in use.',
         "Create a MySQL user limited to this one database (GRANT SELECT,INSERT,UPDATE,DELETE...) with a strong password and use it in db.php. Mandatory before going live.");

    // ---------- FILES & HARDENING ----------
    $need = ['.htaccess' => 'project root', 'uploads/.htaccess' => 'uploads/', 'includes/.htaccess' => 'includes/', 'logs/.htaccess' => 'logs/'];
    $miss = []; foreach ($need as $f => $lbl) { $p = $root . '/' . $f; if (!is_file($p) || strpos((string)@file_get_contents($p, false, null, 0, 600), 'NYMIX-SHIELD') === false) $miss[] = $lbl; }
    $add('htaccess', 'Hardening', 'Folder protection (.htaccess) installed', 10, !$miss ? 'pass' : (in_array('uploads/', $miss, true) ? 'fail' : 'warn'),
         !$miss ? 'uploads/, includes/, logs/ and the root are protected.' : 'Missing in: ' . implode(', ', $miss),
         'Copy the .htaccess files from the shield package into those folders. Without them an uploaded .php file can run, and config files may be downloadable.');
    $dev = []; foreach (['diag.php', 'test_db.php', 'debug.php', 'admin/debug.php', 'phpinfo.php', 'info.php'] as $f) if (is_file($root . '/' . $f)) $dev[] = $f;
    $add('dev_files', 'Hardening', 'No debug / diagnostic scripts left on the server', 8, !$dev ? 'pass' : 'fail',
         !$dev ? 'None found.' : 'Found: ' . implode(', ', $dev), 'Delete these files. diag.php prints partial API keys and is protected only by a key written inside the file.');
    $warnf = []; foreach (['viewlog.php', 'admin/setup.php', 'error_log.php'] as $f) if (is_file($root . '/' . $f)) $warnf[] = $f;
    $add('setup_files', 'Hardening', 'Setup / log viewer scripts removed after use', 3, !$warnf ? 'pass' : 'warn', !$warnf ? 'None found.' : 'Found: ' . implode(', ', $warnf), 'admin/setup.php is only needed to create the first admin; delete it afterwards. Keep logs outside the web folder.');
    $dumps = array_merge((array)glob($root . '/*.sql'), (array)glob($root . '/*.zip'), (array)glob($root . '/*.bak'), (array)glob($root . '/admin/*.sql'));
    $dumps = array_values(array_filter($dumps, fn($f) => basename($f) !== 'fa.zip'));
    $add('db_dump', 'Hardening', 'No database dumps / archives inside the web folder', 10, !$dumps ? 'pass' : 'fail',
         !$dumps ? 'None found.' : 'Downloadable by anyone who guesses the name: ' . implode(', ', array_map('basename', $dumps)), 'Move SQL dumps and backups OUT of htdocs (or at least out of the project folder). A dump contains every client, user and password hash.');
    $big = is_file($root . '/fa.zip') ? 'fa.zip' : '';
    if ($big) $add('stray_zip', 'Hardening', 'No leftover installer archives', 1, 'warn', "$big is sitting in the web folder.", 'Delete it - Font Awesome is already unpacked in assets/.');

    $secrets = [];
    if (defined('NX_SETUP_KEY') && NX_SETUP_KEY === 'CHANGE-ME-to-a-long-random-string') $secrets[] = 'NX_SETUP_KEY';
    if (defined('NX_ALERT_EMAIL') && NX_ALERT_EMAIL === 'your@email.com') $secrets[] = 'NX_ALERT_EMAIL';
    if ($web || defined('NX_SETUP_KEY')) $add('placeholders', 'Hardening', 'No placeholder secrets left in dashboard.php', 6, !$secrets ? 'pass' : (in_array('NX_SETUP_KEY', $secrets, true) ? 'fail' : 'warn'),
         !$secrets ? 'None found.' : 'Still default: ' . implode(', ', $secrets), 'Set NX_SETUP_KEY to a long random string and NX_ALERT_EMAIL to a real address so security alerts reach you.');
    $qd = nx_mal_quarantine_dir();
    $add('quarantine_loc', 'Hardening', 'Quarantine folder is outside the project', 2, strpos(str_replace('\\', '/', $qd), $root . '/') === 0 ? 'warn' : 'pass', $qd, 'Make the folder next to the project writable, or define NX_MAL_QUARANTINE_DIR.');

    // ---------- DETECTION COVERAGE ----------
    $uncovered = [];
    foreach (['staff_portal.php', 'client_portal.php', 'warehouse.php', 'admin/dashboard.php', 'shield_404.php'] as $f) {
        $p = $root . '/' . $f; if (!is_file($p)) { $uncovered[] = $f . ' (missing)'; continue; }
        if (strpos((string)@file_get_contents($p, false, null, 0, 40000), 'nx_shield_guard(') === false) $uncovered[] = $f;
    }
    $add('shield_cov', 'Detection', 'NYMIX Shield watches every entry point', 10, !$uncovered ? 'pass' : (count($uncovered) >= 3 ? 'fail' : 'warn'),
         !$uncovered ? 'Admin, staff, client, warehouse and the 404 catcher all report to the SOC.' : 'Not protected: ' . implode(', ', $uncovered), 'Add the one-line guard to each file (see PATCHES).');
    $lockout = function_exists('soc_is_locked');
    $add('lockout', 'Detection', 'Brute-force lockout on admin login', 5, $lockout ? 'pass' : 'warn', $lockout ? 'Active (5 failures / IP, 8 / account per 15 min).' : 'soc_is_locked() not loaded here.', '');

    $oc = $one("SELECT COUNT(*) FROM nx_scan_findings WHERE status='open' AND severity='critical'");
    $oh = $one("SELECT COUNT(*) FROM nx_scan_findings WHERE status='open' AND severity='high'");
    $add('malware_open', 'Malware', 'No unresolved malware findings', 12, $oc ? 'fail' : ($oh ? 'warn' : 'pass'), $oc + $oh ? "$oc critical / $oh high finding(s) waiting for review." : 'Nothing open.', 'Open Shield -> Malware and quarantine, ignore or fix each finding.');
    $lu = nx_state_get($c, 'last_upload_scan'); $lc = nx_state_get($c, 'last_code_scan');
    $age = $lu ? (int)$lu['age'] : null;
    $add('scan_fresh', 'Malware', 'Malware scan ran in the last 24 hours', 6, $age === null ? 'warn' : ($age <= 1440 ? 'pass' : 'warn'), $age === null ? 'The scanner has never run.' : "Last upload sweep $age minutes ago.", 'Click "Scan now", and schedule admin/shield_cron.php (Windows Task Scheduler on XAMPP).');
    $fim = nx_state_get($c, 'last_fim'); $fimN = $one("SELECT COUNT(*) FROM nx_fim_files WHERE status<>'ok'"); $fimT = $one("SELECT COUNT(*) FROM nx_fim_files");
    $add('fim', 'Malware', 'File integrity baseline exists and is clean', 8, $fimT === 0 ? 'warn' : ($fimN ? 'warn' : 'pass'),
         $fimT === 0 ? 'No baseline yet.' : ($fimN ? "$fimN code file(s) changed since the approved baseline." : "$fimT code files match the approved baseline."), 'Shield -> File integrity: create the baseline, review changes, then approve them.');
    $eng = nx_mal_engine();
    $add('av_engine', 'Malware', 'Real antivirus engine connected', 3, $eng ? 'pass' : 'info', $eng ? $eng['name'] . ' scans new uploads.' : 'Using built-in rules only. Windows Defender / ClamAV are used automatically if found.', 'Optional: install ClamAV, or define NX_MAL_DEFENDER / NX_MAL_CLAMSCAN with the exe path.');

    // ---------- ACCESS CONTROL ----------
    $admins = []; try { $r = $c->query("SELECT id, role, is_active, password_hash, email_verified_at, last_login FROM super_admins"); if ($r) $admins = $r->fetch_all(MYSQLI_ASSOC); } catch (Throwable $e) {}
    $weak = 0; $stale = 0; $unver = 0; $act = 0;
    foreach ($admins as $a) {
        if (!(int)$a['is_active']) continue; $act++;
        if (strpos((string)$a['password_hash'], '$argon2') !== 0 && strpos((string)$a['password_hash'], '$2y$') !== 0) $weak++;
        if (!$a['email_verified_at']) $unver++;
        if ($a['last_login'] && strtotime($a['last_login']) < time() - 90 * 86400) $stale++;
    }
    $add('hash_algo', 'Access', 'Admin passwords use a strong hash', 8, $weak ? 'fail' : 'pass', $weak ? "$weak admin(s) use a weak/legacy hash." : "All $act active admin(s) use Argon2/bcrypt.", 'Force a password reset for those accounts.');
    $add('admin_hygiene', 'Access', 'No stale or unverified admin accounts', 4, ($stale || $unver) ? 'warn' : 'pass', ($stale || $unver) ? "$stale not logged in for 90+ days, $unver email(s) unverified." : 'Admin list is tidy.', 'Deactivate accounts that are no longer used.');
    $has2fa = false; try { $r = $c->query("SHOW COLUMNS FROM super_admins LIKE 'totp_secret'"); $has2fa = $r && $r->num_rows > 0; } catch (Throwable $e) {}
    $add('twofa', 'Access', 'Two-factor authentication for admins', 8, $has2fa ? 'pass' : 'warn', $has2fa ? '2FA column present.' : 'Admins sign in with a password only - one leaked password = full control of every client.', 'Planned for the next phase: TOTP (Google Authenticator) for superadmins.');
    $openAl = $one("SELECT COUNT(*) FROM security_alerts WHERE is_resolved=0 AND severity IN ('critical','high')");
    $add('open_alerts', 'Access', 'No unresolved high/critical alerts', 5, $openAl > 5 ? 'fail' : ($openAl ? 'warn' : 'pass'), $openAl ? "$openAl alert(s) still open." : 'Inbox zero.', 'Review and resolve alerts in the Security tab.');

    // ---------- SCORE ----------
    $tot = 0; $got = 0; $cnt = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
    foreach ($chk as $x) {
        $cnt[$x['status']]++;
        if ($x['status'] === 'info') continue;
        $tot += $x['w']; $got += $x['w'] * ($x['status'] === 'pass' ? 1 : ($x['status'] === 'warn' ? 0.5 : 0));
    }
    $score = $tot ? (int)round(100 * $got / $tot) : 0;
    $grade = $score >= 90 ? 'A' : ($score >= 80 ? 'B' : ($score >= 70 ? 'C' : ($score >= 55 ? 'D' : 'F')));
    usort($chk, fn($a, $b) => [['fail' => 0, 'warn' => 1, 'pass' => 2, 'info' => 3][$a['status']], -$a['w']] <=> [['fail' => 0, 'warn' => 1, 'pass' => 2, 'info' => 3][$b['status']], -$b['w']]);
    $res = ['score' => $score, 'grade' => $grade, 'counts' => $cnt, 'checks' => $chk, 'local' => $local, 'at' => date('Y-m-d H:i:s')];
    try { nx_state_set($c, 'posture', ['score' => $score, 'grade' => $grade, 'counts' => $cnt, 'at' => $res['at']]); } catch (Throwable $e) {}
    return $res;
}
