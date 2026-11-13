<?php
// ============================================================================
//  NYMIX SHIELD - shield_404.php   (NEW FILE, project root)
//  Apache sends every missing page (404) and every blocked folder (403) here
//  (see .htaccess).  This is how scanners that probe /.env, /wp-login.php,
//  /phpmyadmin ... are caught even though those files do not exist.
// ============================================================================
require_once __DIR__ . '/includes/nx_shield.php';

$status = (int)($_SERVER['REDIRECT_STATUS'] ?? 0);
if (!in_array($status, [403, 404], true)) {          // opened directly, not via an Apache error redirect
    http_response_code(404); header('Content-Type: text/plain'); exit('Not found');
}
nx_shield_guard('edge', $status);                      // inspects, scores, may block + exit
http_response_code($status);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
$title = $status === 403 ? 'Forbidden' : 'Page not found';
echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title>'
   . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0d2415;color:#eaf3ec;font-family:Segoe UI,Arial,sans-serif;text-align:center">'
   . '<div><div style="font-size:64px;font-weight:700;color:#17C548">' . $status . '</div><div style="color:#8fb09a">' . $title . '</div></div></body>';
