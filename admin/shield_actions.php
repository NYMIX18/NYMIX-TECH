<?php
// ============================================================================
//  NYMIX SHIELD  -  admin/shield_actions.php          (NEW FILE)
//  Handles every POST button on the Shield tab. Included from dashboard.php
//  (see PATCHES).  CSRF + superadmin role + audit logging are already enforced
//  by soc_rbac_gate() before this code runs.
// ============================================================================
require_once __DIR__ . '/../includes/nx_posture.php';

const NX_SHIELD_TEST_IP = '203.0.113.77';      // reserved documentation range - used only by the "Simulate attack" button

function nx_shield_handle_action(mysqli $conn, string $action): void {
    if (strpos($action, 'shield_') !== 0) return;
    $go = function (string $section, bool $ok, string $msg): void {
        $_SESSION['shield_flash'] = [$ok ? 'ok' : 'err', $msg];
        header('Location: dashboard.php?tab=shield#' . $section); exit;
    };
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $go('overview', false, 'Actions must be submitted from the page.');
    $by  = (string)($_SESSION['sa_name'] ?? 'admin');
    $ip  = trim((string)($_POST['ip'] ?? ''));
    $ipOk = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;

    switch ($action) {
        case 'shield_block':
            if (!$ipOk) $go('blocked', false, 'Enter a valid IP address.');
            if (nx_shield_is_loopback($ip) || $ip === soc_ip()) $go('blocked', false, 'You cannot block your own address or localhost - you would lock yourself out.');
            $perm = !empty($_POST['permanent']); $mins = max(1, min(10080, (int)($_POST['minutes'] ?? 60)));
            nx_shield_block($conn, $ip, 'Manual block by ' . $by . ': ' . trim((string)($_POST['reason'] ?? 'no reason given')), $mins, $perm, $by, 'manual', 0);
            $go('blocked', true, "$ip blocked " . ($perm ? 'permanently.' : "for $mins minutes."));

        case 'shield_unblock':
            if (!$ipOk) $go('blocked', false, 'Invalid IP.');
            nx_shield_unblock($conn, $ip); $go('blocked', true, "$ip unblocked and its recent score cleared.");

        case 'shield_allow':
            if (!$ipOk) $go('blocked', false, 'Enter a valid IP address.');
            nx_shield_allow($conn, $ip, mb_substr(trim((string)($_POST['label'] ?? '')), 0, 120)); $go('blocked', true, "$ip added to the allow list (never inspected or blocked).");

        case 'shield_allow_remove':
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare("DELETE FROM nx_ip_allowlist WHERE id=?"); $s->bind_param('i', $id); $s->execute(); $s->close();
            $go('blocked', true, 'Removed from the allow list.');

        case 'shield_scan_now':
            @set_time_limit(180);
            $r = nx_mal_full_scan($conn); nx_posture_run($conn);
            $go('malware', true, sprintf('Scan finished - uploads: %d scanned, %d quarantined | code: %d scanned, %d flagged | integrity: %d changed, %d new, %d deleted.',
                $r['uploads']['scanned'], $r['uploads']['quarantined'], $r['code']['scanned'], $r['code']['findings'], $r['fim']['modified'], $r['fim']['new'], $r['fim']['deleted']));

        case 'shield_fim_baseline':
            @set_time_limit(120);
            $r = nx_fim_check($conn, true); $go('integrity', true, "Baseline created from {$r['files']} code files. Any future change will be reported.");

        case 'shield_fim_approve':
            $h = (string)($_POST['hash'] ?? '');
            if ($h === 'all') { $n = nx_fim_approve($conn, null); $go('integrity', true, "Approved $n change(s) as the new baseline."); }
            if (!preg_match('/^[a-f0-9]{40}$/', $h)) $go('integrity', false, 'Invalid file reference.');
            nx_fim_approve($conn, $h); $go('integrity', true, 'Change approved.');

        case 'shield_q_restore':
            $r = nx_mal_restore($conn, (int)($_POST['id'] ?? 0), $by); $go('malware', $r['ok'], $r['msg']);

        case 'shield_q_purge':
            $r = nx_mal_purge($conn, (int)($_POST['id'] ?? 0)); $go('malware', $r['ok'], $r['msg']);

        case 'shield_find_quarantine':
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare("SELECT * FROM nx_scan_findings WHERE id=? AND status='open'"); $s->bind_param('i', $id); $s->execute(); $f = $s->get_result()->fetch_assoc(); $s->close();
            if (!$f) $go('malware', false, 'Finding not found.');
            $r = nx_mal_quarantine($conn, NX_MAL_ROOT . '/' . $f['rel_path'], $f, 'manual', $by); $go('malware', $r['ok'], $r['msg']);

        case 'shield_find_ignore':
            $id = (int)($_POST['id'] ?? 0);
            $s = $conn->prepare("UPDATE nx_scan_findings SET status='ignored' WHERE id=?"); $s->bind_param('i', $id); $s->execute(); $s->close();
            $go('malware', true, 'Marked as reviewed / false positive.');

        case 'shield_posture_run':
            $r = nx_posture_run($conn); $go('posture', true, "Posture re-checked: {$r['score']}/100 (grade {$r['grade']}).");

        case 'shield_simulate':       // safe demo: pushes a fake attacker through the REAL detection + auto-block pipeline
            $tip = NX_SHIELD_TEST_IP; $ua = 'Mozilla/5.0 (X11; Linux x86_64)';
            $probes = [['/.env', 'Mozilla/5.0'], ['/wp-login.php', 'Mozilla/5.0'], ['/index.php?id=1%20UNION%20SELECT%20username,password%20FROM%20users', 'sqlmap/1.7.2#stable'], ['/download.php?f=../../../../etc/passwd', 'Mozilla/5.0']];
            $out = 'logged';
            foreach ($probes as [$uri, $pua]) {
                parse_str((string)parse_url($uri, PHP_URL_QUERY), $g);
                $hits = nx_shield_inspect('GET', $uri, $g, [], '', ['user-agent' => $pua]);
                if ($hits) { $r = nx_shield_register($conn, $tip, 'simulation', 'GET', $uri, $pua, $hits); if ($out !== 'blocked') $out = $r; }   // report the strongest outcome
            }
            $go('threats', true, "Simulated attacker $tip ran 4 probes (recon, scanner, SQL injection, path traversal) - result: $out. Check Threat feed and Blocked IPs, then press Clear simulation.");

        case 'shield_clear_sim':
            $tip = NX_SHIELD_TEST_IP; nx_shield_unblock($conn, $tip);
            $s = $conn->prepare("DELETE FROM nx_shield_events WHERE ip=?"); $s->bind_param('s', $tip); $s->execute(); $s->close();
            $s = $conn->prepare("DELETE FROM security_alerts WHERE ip_address=? AND alert_type='shield_block'"); $s->bind_param('s', $tip); $s->execute(); $s->close();
            $go('threats', true, 'Simulation data cleared.');
    }
    $go('overview', false, 'Unknown Shield action.');
}
