<?php
// ============================================================================
//  NYMIX SHIELD  -  admin/shield_cron.php        (NEW FILE)   CLI only
//  Scheduled sweep: uploads malware scan + code signature scan + file-integrity
//  check + security posture + housekeeping.  Safe to run every 5-15 minutes.
//
//  XAMPP / Windows Task Scheduler:
//     Program : C:\xampp\php\php.exe
//     Arguments: C:\xampp\htdocs\nymix_hardwares\admin\shield_cron.php
//     Trigger : every 10 minutes
//  Linux cPanel cron:  */10 * * * *  php /home/USER/public_html/nymix_hardwares/admin/shield_cron.php
// ============================================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/nx_posture.php';
$t = microtime(true);
$u = nx_mal_scan_uploads($conn);
$k = nx_mal_scan_code($conn);
$f = nx_fim_check($conn);
$p = nx_posture_run($conn);
$conn->query("DELETE FROM nx_shield_events WHERE created_at < NOW() - INTERVAL " . (int)NX_SHIELD_KEEP_DAYS . " DAY");
$conn->query("DELETE FROM nx_scan_cache WHERE scanned_at < NOW() - INTERVAL 90 DAY");
echo sprintf("NYMIX Shield sweep %s | uploads: %d scanned, %d quarantined | code: %d scanned, %d flagged | FIM: %d modified %d new %d deleted | posture %d/100 (%s) | %.1fs\n",
    date('Y-m-d H:i:s'), $u['scanned'], $u['quarantined'], $k['scanned'], $k['findings'], $f['modified'], $f['new'], $f['deleted'], $p['score'], $p['grade'], microtime(true) - $t);
