<?php
// ============================================================================
//  NYMIX SHIELD  -  includes/nx_shield.php          (NEW FILE - no patching)
//  Request sensor + IP reputation + auto-block.
//
//  Include it ONCE at the top of every public entry file, right after db.php:
//      require_once __DIR__ . '/includes/nx_shield.php';  nx_shield_guard('staff');
//
//  On EVERY request (fail-open: if anything breaks, the app keeps working):
//    1. Allow-listed IP        -> skipped
//    2. Blocked IP             -> 403 page, request stops here
//    3. Inspects URL / query / form fields / JSON body / key headers for:
//         recon paths (/.env, /wp-login.php, /phpmyadmin ...), scanner tools
//         (sqlmap, nikto, nmap ...), SQL injection, XSS, path traversal,
//         file-inclusion wrappers, command injection, Log4Shell, Shellshock,
//         bad HTTP verbs and request floods
//    4. Every hit is scored and stored in nx_shield_events. When an IP's score
//       over 24h reaches NX_SHIELD_BLOCK_SCORE it is auto-blocked (escalating
//       block times) and a security_alert is raised - your existing mailer
//       (soc_watch.php) already emails high/critical alerts.
// ============================================================================
if (defined('NX_SHIELD_LOADED')) { return; }
define('NX_SHIELD_LOADED', true);

// ---------------------------- SETTINGS (define before including to override) -
defined('NX_SHIELD_ENABLED')     || define('NX_SHIELD_ENABLED', true);
defined('NX_SHIELD_BLOCK_SCORE') || define('NX_SHIELD_BLOCK_SCORE', 60);   // 24h score that triggers an auto-block
defined('NX_SHIELD_BLOCK_MIN')   || define('NX_SHIELD_BLOCK_MIN', 60);     // 1st block length (minutes); x4 on every repeat, max 7 days
defined('NX_SHIELD_RATE_LIMIT')  || define('NX_SHIELD_RATE_LIMIT', 600);   // requests / minute / IP before a "flood" hit
defined('NX_SHIELD_404_BURST')   || define('NX_SHIELD_404_BURST', 6);      // missing-page hits in 2 min = directory brute force
defined('NX_SHIELD_LOCAL_TEST')  || define('NX_SHIELD_LOCAL_TEST', true);  // true: 127.0.0.1 / ::1 are logged + scored but NEVER blocked (no self lock-out on localhost)
defined('NX_SHIELD_ALLOW_IPS')   || define('NX_SHIELD_ALLOW_IPS', '');     // comma separated IPs never inspected, e.g. '41.90.1.2,192.168.0.10'
defined('NX_SHIELD_KEEP_DAYS')   || define('NX_SHIELD_KEEP_DAYS', 30);     // event retention in days

// ---------------------------- DATABASE ---------------------------------------
if (!isset($conn) || !($conn instanceof mysqli)) {
    // File scope = the including file's scope (global for every NYMIX entry file),
    // so $conn stays a normal global exactly as db.php intended.
    require_once __DIR__ . '/db.php';
}

function nx_shield_db(): ?mysqli {
    return (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
}

function nx_shield_schema(mysqli $c): void {
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $c->query("CREATE TABLE IF NOT EXISTS nx_shield_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(64) NOT NULL, app VARCHAR(16) NOT NULL DEFAULT '',
        method VARCHAR(10) NULL, path VARCHAR(400) NULL, ua VARCHAR(255) NULL,
        rule VARCHAR(40) NOT NULL, category VARCHAR(24) NOT NULL, severity VARCHAR(10) NOT NULL,
        score SMALLINT NOT NULL DEFAULT 0, evidence VARCHAR(400) NULL,
        outcome VARCHAR(12) NOT NULL DEFAULT 'logged',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY k_ip (ip, created_at), KEY k_time (created_at), KEY k_rule (rule), KEY k_cat (category)
    ) $E");
    $c->query("CREATE TABLE IF NOT EXISTS nx_ip_blocklist (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(64) NOT NULL, reason VARCHAR(220) NULL, score INT NOT NULL DEFAULT 0,
        block_count INT NOT NULL DEFAULT 1, permanent TINYINT(1) NOT NULL DEFAULT 0,
        blocked_until DATETIME NULL, source VARCHAR(12) NOT NULL DEFAULT 'auto',
        created_by VARCHAR(80) NULL, hits INT NOT NULL DEFAULT 0, last_hit DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY u_ip (ip), KEY k_until (blocked_until)
    ) $E");
    $c->query("CREATE TABLE IF NOT EXISTS nx_ip_allowlist (
        id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(64) NOT NULL, label VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_ip (ip)
    ) $E");
}

// ---------------------------- SMALL HELPERS ----------------------------------
function nx_shield_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // Only believe Cloudflare's header if the project explicitly says it sits behind Cloudflare.
    if (defined('SOC_TRUST_CF') && SOC_TRUST_CF && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
        && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return substr($ip, 0, 64);
}
function nx_shield_is_loopback(string $ip): bool {
    return $ip === '::1' || $ip === '::ffff:127.0.0.1' || strpos($ip, '127.') === 0;
}
function nx_shield_is_allowed_const(string $ip): bool {
    $list = array_filter(array_map('trim', explode(',', (string)NX_SHIELD_ALLOW_IPS)));
    return in_array($ip, $list, true);
}
function nx_shield_clean(string $s, int $max = 300): string {
    $s = (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s);
    return mb_substr(trim($s), 0, $max);
}

// Tiny per-IP counters kept in temp files (no DB write on normal traffic).
function nx_shield_counter(string $key, int $window, int $inc = 1): int {
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'nx_shield';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $fh = @fopen($dir . DIRECTORY_SEPARATOR . md5($key) . '.cnt', 'c+');
    if (!$fh) return 0;
    $n = $inc; $now = time();
    if (flock($fh, LOCK_EX)) {
        $raw = trim((string)stream_get_contents($fh));
        [$start, $cnt] = array_pad(array_map('intval', explode('|', $raw)), 2, 0);
        if ($start > 0 && ($now - $start) <= $window) { $n = $cnt + $inc; } else { $start = $now; }
        ftruncate($fh, 0); rewind($fh); fwrite($fh, $start . '|' . $n);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $n;
}

// ---------------------------- DETECTION RULES --------------------------------
// [rule, category, severity, score, regex, scope]
//   path   = URL path only            ua     = User-Agent only
//   strict = every field + the URI    loose  = every field EXCEPT free-text ones (messages, notes ...)
//   any    = every field + key headers + URI      header = key headers only
function nx_shield_rules(): array {
    static $r = null;
    if ($r !== null) return $r;
    $r = [
        // --- reconnaissance: things a normal user never asks this app for ---
        ['recon_path', 'recon', 'high', 35, '#(^|/)\.(env|git|svn|hg|aws|ssh|idea|npmrc|bash_history|htaccess|htpasswd|ds_store)(/|$|\.)#i', 'path'],
        ['recon_path', 'recon', 'high', 35, '#wp-(login|admin|content|includes|config)|xmlrpc\.php|wlwmanifest#i', 'path'],
        ['recon_path', 'recon', 'high', 35, '#(^|/)(phpmyadmin|pma|myadmin|mysqladmin|adminer|dbadmin|sqlmanager|phpinfo)(\.php)?(/|$)#i', 'path'],
        ['recon_path', 'recon', 'high', 35, '#(^|/)(backup|backups|db|dump|database|site|www|data|admin|nymix[^/]*)\.(sql|zip|tar|tgz|gz|rar|7z|bak)$#i', 'path'],
        ['recon_path', 'recon', 'high', 35, '#\.(bak|old|orig|swp|save|sql|sqlite|sqlite3|db|log|ini|inc)$#i', 'path'],
        ['recon_path', 'recon', 'high', 35, '#cgi-bin|/shell\.php|/cmd\.php|/c99|/r57|/wso\.php|b374k|webshell|eval-stdin\.php|vendor/phpunit|/(actuator|server-status|server-info|jenkins|solr|manager/html|boaform|hnap1|owa/auth|autodiscover)#i', 'path'],
        ['recon_path', 'recon', 'high', 35, '#/(etc/passwd|proc/self|windows/win\.ini)#i', 'path'],
        ['scanner_ua', 'scanner', 'high', 45, '#(sqlmap|nikto|nmap|masscan|acunetix|nessus|openvas|wpscan|gobuster|\bdirb\b|dirbuster|ffuf|feroxbuster|wfuzz|nuclei|zgrab|hydra|havij|netsparker|appscan|w3af|arachni|skipfish|metasploit|jaeles|commix|xsstrike|whatweb|burpcollaborator)#i', 'ua'],
        // --- injection attempts ---
        ['sqli', 'injection', 'high', 40, '/\bunion\b[\s\/*+()]+(all[\s\/*+]+)?select\b/i', 'loose'],
        ['sqli', 'injection', 'high', 40, '/[\'"\)]\s*(?:or|and)\s+[\'"]?([a-z0-9_]+)[\'"]?\s*=\s*[\'"]?\1\b/i', 'loose'],
        ['sqli', 'injection', 'high', 40, '/\b(?:sleep|benchmark|pg_sleep)\s*\(\s*[\'"]?\d|waitfor\s+delay\s+[\'"]/i', 'loose'],
        ['sqli', 'injection', 'high', 40, '/\b(?:information_schema|mysql\.user|sysobjects|pg_catalog|sqlite_master)\b/i', 'loose'],
        ['sqli', 'injection', 'high', 40, '/\bload_file\s*\(|into\s+(?:out|dump)file\b|\b(?:extractvalue|updatexml)\s*\(/i', 'loose'],
        ['sqli', 'injection', 'high', 40, '/;\s*(?:drop|truncate|alter)\s+(?:table|database)\b/i', 'loose'],
        ['xss', 'injection', 'medium', 20, '/<\s*script\b|<\s*(?:iframe|svg|img|body)[^>]{0,80}\bon\w+\s*=|javascript\s*:\s*\S|document\.cookie/i', 'loose'],
        ['traversal', 'injection', 'high', 40, '#(?:\.\.[/\\\\]|%2e%2e(?:%2f|%5c)|\.\.%2f|%252e%252e)#i', 'strict'],
        ['traversal', 'injection', 'high', 40, '#(?:/etc/(?:passwd|shadow)|/proc/self|boot\.ini|win\.ini|c:[/\\\\]windows)#i', 'strict'],
        ['lfi_wrapper', 'injection', 'high', 40, '#\b(?:php|data|expect|phar|zip|glob|gopher|dict|file)://#i', 'strict'],
        ['cmd_injection', 'injection', 'critical', 55, '#(?:;|\|\|?|&&|`|\$\()\s*(?:cat\s+/|ls\s+-|id\b|whoami|uname\s+-|wget\s+http|curl\s+http|nc\s+-|bash\s+-|/bin/(?:ba)?sh|cmd(?:\.exe)?\s+/c|powershell)#i', 'strict'],
        ['cmd_injection', 'injection', 'critical', 55, '#\b(?:system|passthru|shell_exec|proc_open|popen|assert|eval)\s*\(|base64_decode\s*\(|<\?(?:php|=)#i', 'strict'],
        ['log4shell', 'injection', 'critical', 60, '/\$\{\s*(?:jndi|env|sys|lower|upper|java)\s*[:}]/i', 'any'],
        ['shellshock', 'injection', 'critical', 60, '/^\s*\(\s*\)\s*\{/', 'header'],
        ['null_byte', 'injection', 'medium', 25, '/%00|\x00/', 'path'],
    ];
    return $r;
}
function nx_shield_free_text_key(string $k): bool {
    return (bool)preg_match('/(^|_)(message|msg|body|html|content|notes?|description|reply|text|comment|bio|address|remarks?|details?|voice|signature)(_|$)/i', $k);
}
function nx_shield_skip_key(string $k): bool {
    return (bool)preg_match('/pass(word)?|pwd|^csrf|token|secret|otp|pin$/i', $k);
}
function nx_shield_flatten($data, string $prefix, int $depth, array &$out): void {
    if ($depth > 4 || count($out) > 300) return;
    if (is_array($data)) { foreach ($data as $k => $v) nx_shield_flatten($v, (string)$k, $depth + 1, $out); }
    elseif (is_scalar($data) && (string)$data !== '') { $out[] = [$prefix, mb_substr((string)$data, 0, 2000)]; }
}

/**
 * Pure function: look at one request and return the detections (no DB needed, unit-testable).
 * Each: ['rule','category','severity','score','evidence'].  One event per rule per request.
 */
function nx_shield_inspect(string $method, string $uri, array $get, array $post, string $rawBody, array $hdr): array {
    $hits = []; $seen = [];
    $add = function (array $rule, string $where, string $val) use (&$hits, &$seen) {
        if (isset($seen[$rule[0]])) return;
        $seen[$rule[0]] = true;
        $hits[] = ['rule' => $rule[0], 'category' => $rule[1], 'severity' => $rule[2], 'score' => $rule[3],
                   'evidence' => nx_shield_clean($where . ': ' . $val, 300)];
    };
    $path  = (string)parse_url($uri, PHP_URL_PATH);
    $pathD = urldecode(urldecode($path));
    $qs    = urldecode((string)parse_url($uri, PHP_URL_QUERY));
    $uriD  = $pathD . ($qs !== '' ? '?' . $qs : '');
    $rules = nx_shield_rules();

    // 1) URL path / whole URI
    foreach ($rules as $rl) {
        if ($rl[5] === 'path' && preg_match($rl[4], $pathD)) $add($rl, 'path', $pathD);
        elseif (($rl[5] === 'strict' || $rl[5] === 'any') && preg_match($rl[4], $uriD)) $add($rl, 'uri', $uriD);
    }
    // 2) headers
    $ua = (string)($hdr['user-agent'] ?? '');
    if ($ua === '') {
        $hits[] = ['rule' => 'empty_ua', 'category' => 'scanner', 'severity' => 'low', 'score' => 5, 'evidence' => 'no User-Agent header'];
    } else {
        foreach ($rules as $rl) if ($rl[5] === 'ua' && preg_match($rl[4], $ua)) $add($rl, 'ua', $ua);
    }
    foreach (['user-agent', 'referer', 'cookie', 'x-forwarded-for', 'x-api-version', 'accept-language'] as $h) {
        $v = (string)($hdr[$h] ?? ''); if ($v === '') continue;
        foreach ($rules as $rl) if (($rl[5] === 'any' || $rl[5] === 'header') && preg_match($rl[4], $v)) $add($rl, 'header ' . $h, $v);
    }
    // 3) verbs / sizes
    if (in_array(strtoupper($method), ['TRACE', 'TRACK', 'CONNECT', 'DEBUG', 'PROPFIND', 'MKCOL', 'MOVE', 'COPY', 'LOCK'], true)) {
        $hits[] = ['rule' => 'bad_method', 'category' => 'recon', 'severity' => 'medium', 'score' => 15, 'evidence' => 'HTTP ' . $method];
    }
    if (strlen($uri) > 2000) {
        $hits[] = ['rule' => 'long_uri', 'category' => 'injection', 'severity' => 'medium', 'score' => 15, 'evidence' => 'URI length ' . strlen($uri)];
    }
    // 4) parameters (GET + POST + JSON body)
    $fields = [];
    nx_shield_flatten($get,  '', 0, $fields);
    nx_shield_flatten($post, '', 0, $fields);
    if ($rawBody !== '' && !$post && strlen($rawBody) <= 20000) {
        $j = json_decode($rawBody, true);
        if (is_array($j)) nx_shield_flatten($j, '', 0, $fields); else $fields[] = ['body', mb_substr($rawBody, 0, 2000)];
    }
    foreach ($fields as [$k, $v]) {
        if (nx_shield_skip_key($k)) continue;
        $free = nx_shield_free_text_key($k);
        $vd = urldecode($v);
        foreach ($rules as $rl) {
            $scope = $rl[5];
            if ($scope === 'strict' || $scope === 'any' || ($scope === 'loose' && !$free)) {
                if (preg_match($rl[4], $v) || ($vd !== $v && preg_match($rl[4], $vd))) $add($rl, ($k !== '' ? $k : 'value'), $v);
            }
        }
    }
    return $hits;
}

// ---------------------------- BLOCK / UNBLOCK --------------------------------
function nx_shield_block(mysqli $c, string $ip, string $reason, int $minutes = 0, bool $permanent = false, string $by = 'shield', string $source = 'auto', int $score = 0): void {
    $minutes = max(1, $minutes ?: (int)NX_SHIELD_BLOCK_MIN);
    $until   = $permanent ? null : date('Y-m-d H:i:s', time() + $minutes * 60);
    $perm    = $permanent ? 1 : 0;
    $reason  = nx_shield_clean($reason, 220);
    $s = $c->prepare("INSERT INTO nx_ip_blocklist (ip, reason, score, block_count, permanent, blocked_until, source, created_by, created_at)
                      VALUES (?,?,?,1,?,?,?,?,NOW())
                      ON DUPLICATE KEY UPDATE reason=VALUES(reason), score=VALUES(score), block_count=block_count+1,
                      permanent=VALUES(permanent), blocked_until=VALUES(blocked_until), source=VALUES(source), created_by=VALUES(created_by)");
    $s->bind_param('ssiisss', $ip, $reason, $score, $perm, $until, $source, $by);
    $s->execute(); $s->close();
}
function nx_shield_unblock(mysqli $c, string $ip): void {
    $s = $c->prepare("DELETE FROM nx_ip_blocklist WHERE ip=?"); $s->bind_param('s', $ip); $s->execute(); $s->close();
    // forgive the score so the IP is not instantly re-blocked by its old events
    $s = $c->prepare("UPDATE nx_shield_events SET score=0, outcome='forgiven' WHERE ip=? AND created_at >= NOW() - INTERVAL 24 HOUR");
    $s->bind_param('s', $ip); $s->execute(); $s->close();
}
function nx_shield_allow(mysqli $c, string $ip, string $label = ''): void {
    $s = $c->prepare("INSERT INTO nx_ip_allowlist (ip,label) VALUES (?,?) ON DUPLICATE KEY UPDATE label=VALUES(label)");
    $s->bind_param('ss', $ip, $label); $s->execute(); $s->close();
    $s = $c->prepare("DELETE FROM nx_ip_blocklist WHERE ip=?"); $s->bind_param('s', $ip); $s->execute(); $s->close();
}

function nx_shield_deny(string $ip): void {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }
    $ref = strtoupper(substr(hash('sha256', $ip . date('Y-m-d')), 0, 8));
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access blocked</title>'
       . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0d2415;color:#eaf3ec;font-family:Segoe UI,Arial,sans-serif">'
       . '<div style="max-width:440px;margin:20px;padding:32px;border:1px solid #2b5a3a;border-radius:20px;background:#14351F;text-align:center">'
       . '<div style="font-size:42px">&#128737;</div><h2 style="margin:8px 0 6px;color:#17C548">Access blocked</h2>'
       . '<p style="color:#8fb09a;line-height:1.6">NYMIX Shield stopped this request because it looked like an attack. '
       . 'If you are a genuine user, wait a while and try again, or contact your administrator.</p>'
       . '<p style="font-family:monospace;font-size:12px;color:#8fb09a">Reference: ' . $ref . '</p></div></body>';
    exit;
}

// ---------------------------- REGISTER HITS + DECIDE -------------------------
// returns 'blocked' | 'simulated' | 'protected' | 'logged'
function nx_shield_register(mysqli $c, string $ip, string $app, string $method, string $uri, string $ua, array $hits): string {
    $q    = parse_url($uri, PHP_URL_QUERY);
    $path = nx_shield_clean((string)parse_url($uri, PHP_URL_PATH) . ($q ? '?' . $q : ''), 400);
    $ua   = nx_shield_clean($ua, 255);
    $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
    $total = 0; $worst = 'low'; $rules = [];
    foreach ($hits as $h) {
        $total += (int)$h['score'];
        if ($rank[$h['severity']] > $rank[$worst]) $worst = $h['severity'];
        $rules[] = $h['rule'];
    }
    $outcome = 'logged';
    $isLoop  = nx_shield_is_loopback($ip);

    $r = $c->prepare("SELECT COALESCE(SUM(score),0) s FROM nx_shield_events WHERE ip=? AND created_at >= NOW() - INTERVAL 24 HOUR");
    $r->bind_param('s', $ip); $r->execute(); $row = $r->get_result()->fetch_assoc(); $r->close();
    $score24 = $total + (int)($row['s'] ?? 0);

    if ($score24 >= (int)NX_SHIELD_BLOCK_SCORE) {
        $b = $c->prepare("SELECT 1 FROM nx_ip_blocklist WHERE ip=? AND (permanent=1 OR blocked_until > NOW()) LIMIT 1");
        $b->bind_param('s', $ip); $b->execute(); $already = (bool)$b->get_result()->fetch_row(); $b->close();
        if (!$already) {
            if ($isLoop && NX_SHIELD_LOCAL_TEST) {
                $outcome = 'simulated';                       // would have been blocked
            } else {
                // never block an IP that has a live admin session (it could be the admin on a shared IP)
                $prot = false;
                try {
                    $a = $c->prepare("SELECT 1 FROM admin_sessions WHERE ip_address=? AND ended_at IS NULL AND last_active >= NOW() - INTERVAL 1 HOUR LIMIT 1");
                    $a->bind_param('s', $ip); $a->execute(); $prot = (bool)$a->get_result()->fetch_row(); $a->close();
                } catch (Throwable $e) { /* admin_sessions may not exist yet */ }
                if ($prot) {
                    $outcome = 'protected';
                } else {
                    $pr = $c->prepare("SELECT COALESCE(MAX(block_count),0) n FROM nx_ip_blocklist WHERE ip=?");
                    $pr->bind_param('s', $ip); $pr->execute(); $n = (int)($pr->get_result()->fetch_assoc()['n'] ?? 0); $pr->close();
                    $mins = min(7 * 24 * 60, (int)NX_SHIELD_BLOCK_MIN * (int)pow(4, $n));
                    $ur = implode(', ', array_unique($rules));
                    nx_shield_block($c, $ip, "Auto-block: $ur (score $score24)", $mins, false, 'shield', 'auto', $score24);
                    $outcome = 'blocked';
                    $sev  = ($worst === 'critical' || $score24 >= 100) ? 'critical' : 'high';
                    $desc = "NYMIX Shield blocked $ip for $mins min - $ur on $app";
                    $meta = json_encode(['ip' => $ip, 'score' => $score24, 'app' => $app, 'minutes' => $mins, 'rules' => array_values(array_unique($rules)), 'last_path' => mb_substr($path, 0, 120)]);
                    try {
                        $al = $c->prepare("SELECT id FROM security_alerts WHERE alert_type='shield_block' AND ip_address=? AND is_resolved=0 AND created_at >= NOW() - INTERVAL 30 MINUTE LIMIT 1");
                        $al->bind_param('s', $ip); $al->execute(); $dup = (bool)$al->get_result()->fetch_row(); $al->close();
                        if (!$dup) {
                            $in = $c->prepare("INSERT INTO security_alerts (alert_type, severity, ip_address, description, meta, created_at) VALUES ('shield_block',?,?,?,?,NOW())");
                            $in->bind_param('ssss', $sev, $ip, $desc, $meta); $in->execute(); $in->close();
                        }
                    } catch (Throwable $e) { error_log('[NYMIX Shield] alert insert: ' . $e->getMessage()); }
                }
            }
        }
    }
    $ins = $c->prepare("INSERT INTO nx_shield_events (ip, app, method, path, ua, rule, category, severity, score, evidence, outcome, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
    foreach ($hits as $h) {
        $sc = (int)$h['score'];
        $ins->bind_param('ssssssssiss', $ip, $app, $method, $path, $ua, $h['rule'], $h['category'], $h['severity'], $sc, $h['evidence'], $outcome);
        $ins->execute();
    }
    $ins->close();
    if (mt_rand(1, 400) === 1) { $c->query("DELETE FROM nx_shield_events WHERE created_at < NOW() - INTERVAL " . (int)NX_SHIELD_KEEP_DAYS . " DAY"); }
    return $outcome;
}

// ---------------------------- THE GUARD (call this) --------------------------
function nx_shield_guard(string $app = 'app', ?int $edgeStatus = null): void {
    if (!NX_SHIELD_ENABLED || PHP_SAPI === 'cli') return;
    try {
        $c = nx_shield_db();
        if (!$c) return;
        $ip = nx_shield_ip();
        if ($ip === '' || nx_shield_is_allowed_const($ip)) return;

        $sql = "SELECT (SELECT COUNT(*) FROM nx_ip_allowlist WHERE ip=?) a,
                       (SELECT COUNT(*) FROM nx_ip_blocklist WHERE ip=? AND (permanent=1 OR blocked_until > NOW())) b";
        $q = $c->prepare($sql);                              // throws "doesn't exist" on first ever run -> schema created in catch
        $q->bind_param('ss', $ip, $ip); $q->execute(); $st = $q->get_result()->fetch_assoc(); $q->close();
        if ((int)$st['a'] > 0) return;
        $isLoop = nx_shield_is_loopback($ip);
        if ((int)$st['b'] > 0 && !($isLoop && NX_SHIELD_LOCAL_TEST)) {
            $u = $c->prepare("UPDATE nx_ip_blocklist SET hits=hits+1, last_hit=NOW() WHERE ip=?");
            $u->bind_param('s', $ip); $u->execute(); $u->close();
            nx_shield_deny($ip);
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $hdr = [];
        foreach (['user-agent' => 'HTTP_USER_AGENT', 'referer' => 'HTTP_REFERER', 'cookie' => 'HTTP_COOKIE',
                  'x-forwarded-for' => 'HTTP_X_FORWARDED_FOR', 'x-api-version' => 'HTTP_X_API_VERSION',
                  'accept-language' => 'HTTP_ACCEPT_LANGUAGE'] as $k => $sv) {
            if (isset($_SERVER[$sv])) $hdr[$k] = (string)$_SERVER[$sv];
        }
        $raw = '';
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && empty($_POST) && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/') === false) {
            $raw = (string)file_get_contents('php://input', false, null, 0, 20001);
        }
        $hits = nx_shield_inspect($method, $uri, $_GET, $_POST, $raw, $hdr);

        if ($edgeStatus !== null) {                          // called from shield_404.php (missing / forbidden page)
            $n = nx_shield_counter('e404:' . $ip, 120);
            if ($n === (int)NX_SHIELD_404_BURST) {
                $hits[] = ['rule' => 'dir_bruteforce', 'category' => 'recon', 'severity' => 'high', 'score' => 30, 'evidence' => $n . ' missing/denied pages in 2 minutes'];
            }
            $p = nx_shield_clean((string)parse_url($uri, PHP_URL_PATH), 200);
            if ($edgeStatus === 403) $hits[] = ['rule' => 'forbidden_probe', 'category' => 'recon', 'severity' => 'medium', 'score' => 20, 'evidence' => 'tried a protected folder: ' . $p];
            elseif (!$hits)          $hits[] = ['rule' => 'not_found', 'category' => 'recon', 'severity' => 'low', 'score' => 2, 'evidence' => '404 ' . $p];
        } else {
            $n = nx_shield_counter('rate:' . $ip, 60);
            if ($n === (int)NX_SHIELD_RATE_LIMIT) {
                $hits[] = ['rule' => 'rate_flood', 'category' => 'flood', 'severity' => 'medium', 'score' => 20, 'evidence' => $n . ' requests in 60s'];
            }
        }
        if (!$hits) return;

        $outcome = nx_shield_register($c, $ip, $app, $method, $uri, $hdr['user-agent'] ?? '', $hits);
        if ($outcome === 'blocked') nx_shield_deny($ip);
    } catch (Throwable $e) {
        error_log('[NYMIX Shield] ' . $e->getMessage());     // fail-open: never break the app
        try {
            $c2 = nx_shield_db();
            if ($c2 && stripos($e->getMessage(), "doesn't exist") !== false) nx_shield_schema($c2);
        } catch (Throwable $e2) { /* ignore */ }
    }
}

// ---------------------------- NUMBERS FOR THE DASHBOARD ----------------------
function nx_shield_stats(mysqli $c): array {
    $one = function (string $sql) use ($c): int { try { $r = $c->query($sql); $x = $r ? $r->fetch_row() : null; return $x ? (int)$x[0] : 0; } catch (Throwable $e) { return 0; } };
    $all = function (string $sql) use ($c): array { try { $r = $c->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : []; } catch (Throwable $e) { return []; } };
    try { nx_shield_schema($c); } catch (Throwable $e) { /* ignore */ }
    $noise = "rule NOT IN ('not_found','empty_ua')";
    return [
        'events_24h'    => $one("SELECT COUNT(*) FROM nx_shield_events WHERE created_at >= NOW() - INTERVAL 24 HOUR AND $noise"),
        'attackers_24h' => $one("SELECT COUNT(DISTINCT ip) FROM nx_shield_events WHERE created_at >= NOW() - INTERVAL 24 HOUR AND score >= 15"),
        'blocked_now'   => $one("SELECT COUNT(*) FROM nx_ip_blocklist WHERE permanent=1 OR blocked_until > NOW()"),
        'blocked_hits'  => $one("SELECT COALESCE(SUM(hits),0) FROM nx_ip_blocklist"),
        'critical_24h'  => $one("SELECT COUNT(*) FROM nx_shield_events WHERE severity='critical' AND created_at >= NOW() - INTERVAL 24 HOUR"),
        'by_category'   => $all("SELECT category, COUNT(*) n FROM nx_shield_events WHERE created_at >= NOW() - INTERVAL 24 HOUR AND $noise GROUP BY category ORDER BY n DESC"),
        'by_hour'       => $all("SELECT DATE_FORMAT(created_at,'%H:00') h, COUNT(*) n FROM nx_shield_events WHERE created_at >= NOW() - INTERVAL 24 HOUR AND $noise GROUP BY h ORDER BY MIN(created_at)"),
        'top_ips'       => $all("SELECT e.ip, SUM(e.score) score, COUNT(*) hits, MAX(e.created_at) last_seen, GROUP_CONCAT(DISTINCT e.rule ORDER BY e.rule SEPARATOR ', ') rules,
                                 (SELECT 1 FROM nx_ip_blocklist b WHERE b.ip=e.ip AND (b.permanent=1 OR b.blocked_until > NOW()) LIMIT 1) is_blocked
                                 FROM nx_shield_events e WHERE e.created_at >= NOW() - INTERVAL 24 HOUR GROUP BY e.ip HAVING score > 0 ORDER BY score DESC LIMIT 8"),
        'recent'        => $all("SELECT ip, app, rule, category, severity, score, evidence, outcome, path, created_at FROM nx_shield_events WHERE $noise ORDER BY id DESC LIMIT 40"),
        'blocklist'     => $all("SELECT * FROM nx_ip_blocklist WHERE permanent=1 OR blocked_until > NOW() ORDER BY id DESC LIMIT 100"),
        'allowlist'     => $all("SELECT * FROM nx_ip_allowlist ORDER BY id DESC LIMIT 50"),
    ];
}
