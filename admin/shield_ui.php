<?php
// ============================================================================
//  NYMIX SHIELD  -  admin/shield_ui.php               (NEW FILE)
//    nx_shield_front($conn)       -> the new "Command Centre" block on the admin front page
//    nx_shield_render_tab($conn)  -> the full Shield tab (6 sections)
//  Pure output. Uses the dashboard's own classes + CSS variables (green theme).
// ============================================================================
require_once __DIR__ . '/../includes/nx_posture.php';

function nx_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nx_ago(?string $dt): string {
    if (!$dt) return '-'; $t = strtotime($dt); if (!$t) return '-'; $d = time() - $t;
    if ($d < 60) return 'just now'; if ($d < 3600) return floor($d / 60) . 'm ago'; if ($d < 86400) return floor($d / 3600) . 'h ago'; return floor($d / 86400) . 'd ago';
}
function nx_bytes($n): string { $n = (float)$n; foreach (['B', 'KB', 'MB', 'GB'] as $u) { if ($n < 1024) return round($n, $u === 'B' ? 0 : 1) . ' ' . $u; $n /= 1024; } return round($n, 1) . ' TB'; }
function nx_sev_col(string $s): string { return ['critical' => 'var(--red)', 'high' => 'var(--red)', 'medium' => 'var(--amber)', 'low' => 'var(--cyan)'][$s] ?? 'var(--tx3)'; }
function nx_pill(string $t, string $col): string { return '<span style="font-family:\'DM Mono\',monospace;font-size:10px;padding:2px 9px;border-radius:10px;background:var(--ink4);color:' . $col . ';text-transform:uppercase;letter-spacing:.6px;white-space:nowrap">' . nx_e($t) . '</span>'; }
function nx_outcome_pill(string $o): string { $m = ['blocked' => 'var(--red)', 'simulated' => 'var(--amber)', 'protected' => 'var(--green)', 'forgiven' => 'var(--tx3)', 'logged' => 'var(--tx2)']; return nx_pill($o, $m[$o] ?? 'var(--tx2)'); }
function nx_post_form(string $action, array $fields, string $label, string $cls = 'btn-ghost', string $confirm = ''): string {
    $h = '<form method="POST" style="display:inline"' . ($confirm ? ' onsubmit="return confirm(\'' . nx_e(addslashes($confirm)) . '\')"' : '') . '><input type="hidden" name="_action" value="' . nx_e($action) . '">' . csrf_token_field();
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . nx_e($k) . '" value="' . nx_e($v) . '">';
    return $h . '<button type="submit" class="btn ' . $cls . ' btn-sm">' . $label . '</button></form>';
}
function nx_ring(int $score, string $grade, int $size = 132): string {
    $col = $score >= 80 ? 'var(--green)' : ($score >= 60 ? 'var(--amber)' : 'var(--red)'); $r = 52; $c = 2 * M_PI * $r; $d = $c * $score / 100;
    return '<div class="sh-ring" style="width:' . $size . 'px;height:' . $size . 'px"><svg viewBox="0 0 120 120" width="100%" height="100%"><circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="var(--ink4)" stroke-width="10"/>'
         . '<circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="' . $col . '" stroke-width="10" stroke-linecap="round" stroke-dasharray="' . round($d, 1) . ' ' . round($c, 1) . '"/></svg>'
         . '<div class="n"><div><div style="font-family:\'Fraunces\',serif;font-size:' . round($size * 0.27) . 'px;font-weight:600;line-height:1;color:' . $col . '">' . $score . '</div>'
         . '<div style="font:10px \'DM Mono\',monospace;letter-spacing:1.2px;color:var(--tx3);margin-top:3px">GRADE ' . nx_e($grade) . '</div></div></div></div>';
}
function nx_shield_css(): void {
    static $done = false; if ($done) return; $done = true; ?>
<style>
.sh-hero{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr);gap:18px;margin-bottom:20px}
.sh-ring{position:relative;flex-shrink:0}.sh-ring svg{transform:rotate(-90deg)}.sh-ring .n{position:absolute;inset:0;display:grid;place-items:center;text-align:center}
.sh-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.sh-kpi{background:var(--ink3);border:1px solid var(--line2);border-radius:14px;padding:12px 14px}
.sh-kpi b{display:block;font-family:'Fraunces',serif;font-size:24px;font-weight:600;line-height:1.1}
.sh-kpi span{font:10px 'DM Mono',monospace;letter-spacing:1.2px;text-transform:uppercase;color:var(--tx3)}
.sh-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px}
.sh-tabs a{padding:8px 15px;border-radius:20px;border:1px solid var(--line2);color:var(--tx2);text-decoration:none;font-size:13px;font-weight:600;display:inline-flex;gap:7px;align-items:center}
.sh-tabs a:hover{color:var(--tx);background:var(--ink3)}.sh-tabs a.on{background:var(--blue-d);color:var(--blue);border-color:var(--blue)}
.sh-tabs a i{font-style:normal;font:700 10px 'DM Mono',monospace;background:var(--red);color:#fff;border-radius:9px;padding:1px 6px}
.sh-sec{display:none}.sh-sec.on{display:block}
.sh-row{display:flex;gap:11px;padding:11px 16px;border-bottom:1px solid var(--line);align-items:flex-start}.sh-row:last-child{border-bottom:none}
.sh-dot{width:9px;height:9px;border-radius:50%;margin-top:6px;flex-shrink:0}
.sh-mono{font-family:'DM Mono',monospace;font-size:11.5px}.sh-muted{color:var(--tx3);font-size:11.5px}
.sh-flash{padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:13px;border:1px solid}
.sh-flash.ok{background:var(--green-d);color:var(--green);border-color:var(--green)}.sh-flash.err{background:var(--red-d);color:var(--red);border-color:var(--red)}
.sh-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)}
.sh-form input[type=text],.sh-form input[type=number]{background:var(--ink3);border:1px solid var(--line2);color:var(--tx);border-radius:10px;padding:8px 11px;font-size:13px;min-width:120px}
.sh-bar{height:7px;border-radius:5px;background:var(--ink4);overflow:hidden;min-width:70px}.sh-bar>div{height:100%;border-radius:5px}
.sh-pulse{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:20px}
.sh-delta{font:11px 'DM Mono',monospace}
@media(max-width:1000px){.sh-hero{grid-template-columns:1fr}.sh-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<?php }

function nx_shield_chart(string $id, string $type, array $labels, array $data, string $colorVar = '--blue'): void { ?>
<script>
(function(){
  if(!window.Chart) return; var el=document.getElementById(<?= json_encode($id) ?>); if(!el) return;
  var cs=getComputedStyle(document.documentElement), col=(cs.getPropertyValue(<?= json_encode($colorVar) ?>)||'#17C548').trim(), tx=(cs.getPropertyValue('--tx3')||'#8fb09a').trim(), ln=(cs.getPropertyValue('--line2')||'#2b5a3a').trim();
  var pal=['#17C548','#E87800','#f87171','#60a5fa','#a78bfa','#facc15'];
  new Chart(el,{type:<?= json_encode($type) ?>,data:{labels:<?= json_encode($labels) ?>,datasets:[{data:<?= json_encode($data) ?>,backgroundColor:<?= json_encode($type) ?>==='doughnut'?pal:col,borderColor:<?= json_encode($type) ?>==='doughnut'?'transparent':col,borderRadius:5,maxBarThickness:22,borderWidth:0}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:<?= json_encode($type) ?>==='doughnut',position:'bottom',labels:{color:tx,boxWidth:10,font:{size:11}}}},
  scales:<?= json_encode($type) ?>==='doughnut'?{}:{x:{ticks:{color:tx,maxTicksLimit:8,font:{size:10}},grid:{display:false}},y:{beginAtZero:true,ticks:{color:tx,precision:0,font:{size:10}},grid:{color:ln}}}}});
})();
</script>
<?php }

// ============================================================================
//  FRONT PAGE: "Command Centre"
// ============================================================================
function nx_shield_front(mysqli $c): void {
    nx_shield_css();
    $sh = nx_shield_stats($c); $mal = nx_mal_stats($c); $po = nx_posture_run($c);
    $greet = $GLOBALS['greeting'] ?? 'Hello'; $who = $GLOBALS['admin_name'] ?? 'Admin';
    $openCrit = (int)soc_scalar($c, "SELECT COUNT(*) FROM security_alerts WHERE is_resolved=0 AND severity IN ('critical','high')");
    $level = ($mal['open_critical'] > 0 || $openCrit > 0) ? 'red' : (($sh['events_24h'] > 0 || $mal['fim_changes'] > 0 || $po['score'] < 70) ? 'amber' : 'green');
    $head = ['red' => ['Action needed', 'var(--red)', 'There are unresolved critical findings or alerts waiting for you.'],
             'amber' => ['Protected - attention advised', 'var(--amber)', 'Shield is active and handling threats. A few items need review.'],
             'green' => ['All systems secure', 'var(--green)', 'No active threats. Shield, the scanner and integrity monitor are watching.']][$level];
    $fix = array_slice(array_values(array_filter($po['checks'], fn($x) => in_array($x['status'], ['fail', 'warn'], true))), 0, 3);

    // business pulse
    $q = fn(string $sql) => (float)soc_scalar($c, $sql);
    $nc = $q("SELECT COUNT(*) FROM clients WHERE created_at >= NOW() - INTERVAL 7 DAY"); $ncp = $q("SELECT COUNT(*) FROM clients WHERE created_at >= NOW() - INTERVAL 14 DAY AND created_at < NOW() - INTERVAL 7 DAY");
    $rv = $q("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE confirmed=1 AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
    $rvp = $q("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE confirmed=1 AND created_at >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH,'%Y-%m-01') AND created_at < DATE_FORMAT(NOW(),'%Y-%m-01')");
    $exp = $q("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','grace') AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
    $od  = $q("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','overdue','sent','draft') AND due_date < CURDATE()");
    $pp  = $q("SELECT COUNT(*) FROM subscription_payments WHERE confirmed=0 AND created_at > NOW() - INTERVAL 3 DAY");
    $onb = $q("SELECT COUNT(*) FROM onboarding_requests WHERE status='manual_review'");
    $ut  = $q("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress') AND priority IN ('critical','high')");
    $delta = function (float $now, float $prev, bool $upGood = true) { if ($prev <= 0) return $now > 0 ? '<span class="sh-delta" style="color:var(--green)">new activity</span>' : '<span class="sh-delta" style="color:var(--tx3)">no change</span>';
        $p = round(($now - $prev) / $prev * 100); $good = ($p >= 0) === $upGood; return '<span class="sh-delta" style="color:' . ($p === 0 ? 'var(--tx3)' : ($good ? 'var(--green)' : 'var(--red)')) . '">' . ($p > 0 ? '▲ +' : ($p < 0 ? '▼ ' : '')) . $p . '% vs previous</span>'; };
    $audit = soc_rows($c, "SELECT admin_name, action, entity_label, created_at FROM audit_log ORDER BY id DESC LIMIT 6");
    ?>
<div class="sh-hero">
  <div class="card" style="border-color:<?= $head[1] ?>">
    <div class="card-body" style="display:flex;gap:22px;align-items:center;flex-wrap:wrap">
      <?= nx_ring($po['score'], $po['grade']) ?>
      <div style="flex:1;min-width:230px">
        <div class="sh-muted" style="font-family:'DM Mono',monospace;letter-spacing:1.4px;text-transform:uppercase"><?= nx_e($greet) ?>, <?= $who ?></div>
        <div style="font-family:'Fraunces',serif;font-size:24px;font-weight:600;color:<?= $head[1] ?>;margin:4px 0 4px"><?= $head[0] ?></div>
        <div style="font-size:13px;color:var(--tx2);line-height:1.55"><?= $head[2] ?></div>
        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
          <a class="btn btn-primary btn-sm" href="?tab=shield">Open Shield</a>
          <?= nx_post_form('shield_scan_now', [], '⟳ Scan now', 'btn-ghost') ?>
        </div>
      </div>
    </div>
    <?php if ($fix): ?><div style="border-top:1px solid var(--line)"><div class="sh-muted" style="padding:10px 16px 2px;font-family:'DM Mono',monospace;letter-spacing:1.2px;text-transform:uppercase">Top things to fix</div>
      <?php foreach ($fix as $x): ?><div class="sh-row" style="padding:8px 16px"><span class="sh-dot" style="background:<?= $x['status'] === 'fail' ? 'var(--red)' : 'var(--amber)' ?>"></span><div style="font-size:13px"><?= nx_e($x['title']) ?> <a href="?tab=shield#posture" class="sh-muted" style="text-decoration:none">→ how</a></div></div><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Threat activity · last 24h</div></div>
    <div class="card-body"><div class="sh-kpis" style="margin-bottom:14px">
      <div class="sh-kpi"><b><?= number_format($sh['events_24h']) ?></b><span>Attacks seen</span></div>
      <div class="sh-kpi"><b><?= number_format($sh['attackers_24h']) ?></b><span>Attackers</span></div>
      <div class="sh-kpi"><b style="color:<?= $sh['blocked_now'] ? 'var(--red)' : 'inherit' ?>"><?= number_format($sh['blocked_now']) ?></b><span>IPs blocked</span></div>
      <div class="sh-kpi"><b style="color:<?= $mal['quarantined'] ? 'var(--amber)' : 'inherit' ?>"><?= number_format($mal['quarantined']) ?></b><span>Quarantined</span></div>
    </div><div style="height:150px"><canvas id="shFrontChart"></canvas></div></div>
  </div>
</div>
<?php nx_shield_chart('shFrontChart', 'bar', array_column($sh['by_hour'], 'h'), array_map('intval', array_column($sh['by_hour'], 'n'))); ?>

<div class="sh-pulse">
  <div class="stat-card"><div class="sc-label">New clients · 7 days</div><div class="sc-value"><?= number_format($nc) ?></div><div class="sc-change"><?= $delta($nc, $ncp) ?></div></div>
  <div class="stat-card"><div class="sc-label">Revenue this month</div><div class="sc-value money"><?= money($rv) ?></div><div class="sc-change"><?= $delta($rv, $rvp) ?></div></div>
  <div class="stat-card"><div class="sc-label">Expiring in 7 days</div><div class="sc-value"><?= number_format($exp) ?></div><div class="sc-change <?= $exp ? 'down' : '' ?>"><?= $exp ? 'Renewals to chase' : '✓ None' ?></div></div>
  <div class="stat-card"><div class="sc-label">Overdue invoices</div><div class="sc-value"><?= number_format($od) ?></div><div class="sc-change <?= $od ? 'down' : '' ?>"><?= $od ? '⚠ Follow up' : '✓ All current' ?></div></div>
  <div class="stat-card"><div class="sc-label">Payments awaiting confirm</div><div class="sc-value"><?= number_format($pp) ?></div><div class="sc-change <?= $pp ? 'down' : '' ?>"><?= $pp ? 'Check M-Pesa / Pesapal' : '✓ Clear' ?></div></div>
  <div class="stat-card"><div class="sc-label">Onboarding in review</div><div class="sc-value"><?= number_format($onb) ?></div><div class="sc-change <?= $onb ? 'down' : '' ?>"><?= $onb ? 'Needs a decision' : '✓ Clear' ?></div></div>
  <div class="stat-card"><div class="sc-label">Urgent tickets</div><div class="sc-value"><?= number_format($ut) ?></div><div class="sc-change <?= $ut ? 'down' : '' ?>"><?= $ut ? '⚠ High / critical open' : '✓ None' ?></div></div>
</div>

<div class="grid-2" style="margin-bottom:20px">
  <div class="card"><div class="card-header"><div class="card-title">🎯 Top attackers · 24h</div><a href="?tab=shield#threats" class="btn btn-ghost btn-sm">All threats</a></div>
    <div class="card-body-flush">
    <?php if (!$sh['top_ips']): ?><div class="empty-state" style="padding:26px 20px"><h3>No hostile traffic</h3><p>Probes, scanners and injection attempts will appear here.</p></div>
    <?php else: foreach ($sh['top_ips'] as $t): ?>
      <div class="sh-row"><span class="sh-dot" style="background:<?= $t['is_blocked'] ? 'var(--red)' : 'var(--amber)' ?>"></span>
        <div style="flex:1;min-width:0"><div class="sh-mono"><?= nx_e($t['ip']) ?> <?= $t['is_blocked'] ? nx_pill('blocked', 'var(--red)') : '' ?></div><div class="sh-muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= nx_e($t['rules']) ?></div></div>
        <div style="text-align:right"><div class="sh-mono">score <?= (int)$t['score'] ?></div><div class="sh-muted"><?= nx_ago($t['last_seen']) ?></div></div></div>
    <?php endforeach; endif; ?></div></div>
  <div class="card"><div class="card-header"><div class="card-title">⚡ Live threat feed</div><a href="?tab=shield#threats" class="btn btn-ghost btn-sm">Open</a></div>
    <div class="card-body-flush">
    <?php $feed = array_slice($sh['recent'], 0, 6); if (!$feed): ?><div class="empty-state" style="padding:26px 20px"><h3>Quiet</h3><p>Nothing suspicious has hit the platform.</p></div>
    <?php else: foreach ($feed as $e): ?>
      <div class="sh-row"><span class="sh-dot" style="background:<?= nx_sev_col($e['severity']) ?>"></span>
        <div style="flex:1;min-width:0"><div style="font-size:13px"><b><?= nx_e(str_replace('_', ' ', $e['rule'])) ?></b> <span class="sh-muted">· <?= nx_e($e['app']) ?></span></div><div class="sh-muted sh-mono" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= nx_e($e['ip']) ?> · <?= nx_e(mb_substr($e['path'] ?? '', 0, 60)) ?></div></div>
        <div style="text-align:right"><?= nx_outcome_pill($e['outcome']) ?><div class="sh-muted"><?= nx_ago($e['created_at']) ?></div></div></div>
    <?php endforeach; endif; ?></div></div>
</div>

<div class="grid-2" style="margin-bottom:22px">
  <div class="card"><div class="card-header"><div class="card-title">🛡 Protection status</div></div><div class="card-body-flush">
    <?php $lu = $mal['last_upload']; $lf = $mal['last_fim'];
    $rows = [
      ['Request sensor (Shield)', 'Active on admin, staff, client, warehouse', !empty($po['checks']) && !array_filter($po['checks'], fn($x) => $x['id'] === 'shield_cov' && $x['status'] !== 'pass') ? 'ok' : 'warn'],
      ['Malware scanner', $lu ? 'Last sweep ' . nx_ago($lu['data']['at'] ?? null) . ' · ' . ($mal['engine'] ?: 'built-in rules') : 'Never run - press Scan now', $lu ? 'ok' : 'warn'],
      ['File integrity monitor', $mal['fim_files'] ? number_format($mal['fim_files']) . ' files watched · ' . ($mal['fim_changes'] ? $mal['fim_changes'] . ' change(s) to review' : 'no changes') : 'No baseline yet', $mal['fim_files'] ? ($mal['fim_changes'] ? 'warn' : 'ok') : 'warn'],
      ['Quarantine vault', number_format($mal['quarantined']) . ' file(s) held · ' . number_format($mal['rejected_30d']) . ' malicious upload(s) rejected in 30 days', 'ok'],
      ['Admin login defence', 'Lockout · geo-IP · session guard · AI analyst', 'ok'],
    ];
    foreach ($rows as [$t, $d, $s]): ?>
      <div class="sh-row"><span class="sh-dot" style="background:<?= $s === 'ok' ? 'var(--green)' : 'var(--amber)' ?>"></span><div style="flex:1"><div style="font-size:13px;font-weight:600"><?= nx_e($t) ?></div><div class="sh-muted"><?= nx_e($d) ?></div></div></div>
    <?php endforeach; ?></div></div>
  <div class="card"><div class="card-header"><div class="card-title">🕑 Recent admin activity</div><a href="?tab=security" class="btn btn-ghost btn-sm">Audit trail</a></div><div class="card-body-flush">
    <?php if (!$audit): ?><div class="empty-state" style="padding:26px 20px"><h3>No activity yet</h3></div>
    <?php else: foreach ($audit as $a): ?>
      <div class="sh-row"><span class="sh-dot" style="background:var(--blue)"></span><div style="flex:1;min-width:0"><div style="font-size:13px"><b><?= nx_e($a['admin_name'] ?: 'system') ?></b> · <?= nx_e(str_replace('_', ' ', $a['action'])) ?></div><?php if (!empty($a['entity_label'])): ?><div class="sh-muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= nx_e($a['entity_label']) ?></div><?php endif; ?></div><div class="sh-muted"><?= nx_ago($a['created_at']) ?></div></div>
    <?php endforeach; endif; ?></div></div>
</div>
<div class="page-eyebrow" style="margin:6px 0 12px">Business overview</div>
<?php }

// ============================================================================
//  SHIELD TAB
// ============================================================================
function nx_shield_render_tab(mysqli $c): void {
    nx_shield_css();
    $sh = nx_shield_stats($c); $mal = nx_mal_stats($c); $po = nx_posture_run($c);
    $flash = $_SESSION['shield_flash'] ?? null; unset($_SESSION['shield_flash']);
    $simActive = (int)soc_scalar($c, "SELECT COUNT(*) FROM nx_shield_events WHERE ip='" . $c->real_escape_string(NX_SHIELD_TEST_IP) . "'");
    ?>
<div class="page-header"><div><div class="page-eyebrow">Security Operations Centre</div><div class="page-title">Shield</div>
  <div class="page-sub">Intrusion detection · IP blocking · anti-malware · file integrity · security posture</div></div>
  <div class="page-actions"><?= nx_post_form('shield_scan_now', [], '⟳ Scan now', 'btn-primary') ?><a class="btn btn-ghost" href="?tab=security">Login security →</a></div></div>
<?php if ($flash): ?><div class="sh-flash <?= nx_e($flash[0]) ?>"><?= nx_e($flash[1]) ?></div><?php endif; ?>

<div class="sh-tabs">
  <a href="#overview">Overview</a>
  <a href="#threats">Threat feed <?php if ($sh['critical_24h']): ?><i><?= (int)$sh['critical_24h'] ?></i><?php endif; ?></a>
  <a href="#blocked">Blocked IPs <?php if ($sh['blocked_now']): ?><i><?= (int)$sh['blocked_now'] ?></i><?php endif; ?></a>
  <a href="#malware">Malware <?php if ($mal['open_findings'] + $mal['quarantined']): ?><i><?= (int)($mal['open_findings']) ?: (int)$mal['quarantined'] ?></i><?php endif; ?></a>
  <a href="#integrity">File integrity <?php if ($mal['fim_changes']): ?><i><?= (int)$mal['fim_changes'] ?></i><?php endif; ?></a>
  <a href="#posture">Posture <span class="sh-muted" style="font-weight:700"><?= (int)$po['score'] ?></span></a>
</div>

<!-- ===================== OVERVIEW ===================== -->
<div class="sh-sec" id="sec-overview">
  <div class="grid-2" style="margin-bottom:20px">
    <div class="card"><div class="card-body" style="display:flex;gap:20px;align-items:center;flex-wrap:wrap">
      <?= nx_ring($po['score'], $po['grade'], 120) ?>
      <div style="flex:1;min-width:200px"><div style="font-family:'Fraunces',serif;font-size:20px;font-weight:600">Security posture</div>
        <div class="sh-muted" style="margin:3px 0 10px"><?= $po['counts']['pass'] ?> passed · <?= $po['counts']['warn'] ?> warnings · <?= $po['counts']['fail'] ?> failed</div>
        <a href="#posture" class="btn btn-ghost btn-sm">See fixes</a></div></div></div>
    <div class="card"><div class="card-header"><div class="card-title">Attack types · 24h</div></div><div class="card-body"><div style="height:170px">
      <?php if ($sh['by_category']): ?><canvas id="shCatChart"></canvas><?php else: ?><div class="empty-state" style="padding:40px 10px"><h3>No attacks recorded</h3><p>Use "Simulate attack" in the Threat feed to see the system react.</p></div><?php endif; ?></div></div></div>
  </div>
  <?php if ($sh['by_category']) nx_shield_chart('shCatChart', 'doughnut', array_column($sh['by_category'], 'category'), array_map('intval', array_column($sh['by_category'], 'n'))); ?>
  <div class="card"><div class="card-header"><div class="card-title">How Shield protects you</div></div><div class="card-body-flush">
    <?php foreach ([
      ['Request sensor', 'Every request to the admin, staff, client and warehouse apps is inspected for scanners (sqlmap, nikto, nmap), /.env and /wp-login probes, SQL injection, XSS, path traversal, file-inclusion wrappers, command injection, Log4Shell and request floods.'],
      ['Auto-block', 'Each hit adds to the attacker\'s 24-hour score. At ' . (int)NX_SHIELD_BLOCK_SCORE . ' points the IP is blocked (' . (int)NX_SHIELD_BLOCK_MIN . ' min, x4 for repeat offenders) and a critical alert is raised. Admins with a live session and allow-listed IPs are never blocked.'],
      ['Honeypot 404 catcher', 'Missing pages are routed through shield_404.php, so directory brute-forcing and probes for files that do not exist are still caught.'],
      ['Anti-malware', 'Uploads are checked before saving and swept regularly: PHP hidden in images, web shells, double extensions, .htaccess attacks, EICAR test files, plus Windows Defender / ClamAV when installed. Bad files are quarantined, never silently deleted.'],
      ['File integrity', 'A SHA-256 fingerprint of every code file. If someone adds a backdoor or edits db.php, you see it here.'],
    ] as [$t, $d]): ?><div class="sh-row"><span class="sh-dot" style="background:var(--green)"></span><div><div style="font-weight:600;font-size:13px"><?= nx_e($t) ?></div><div class="sh-muted" style="font-size:12.5px;line-height:1.55;margin-top:2px"><?= nx_e($d) ?></div></div></div><?php endforeach; ?>
  </div></div>
</div>

<!-- ===================== THREAT FEED ===================== -->
<div class="sh-sec" id="sec-threats">
  <div class="card" style="margin-bottom:20px"><div class="card-header"><div class="card-title">Intrusion events</div>
    <div style="display:flex;gap:8px"><?= nx_post_form('shield_simulate', [], '▶ Simulate attack', 'btn-ghost') ?><?php if ($simActive): ?><?= nx_post_form('shield_clear_sim', [], 'Clear simulation', 'btn-ghost') ?><?php endif; ?></div></div>
    <div class="card-body-flush" style="overflow-x:auto">
    <?php if (!$sh['recent']): ?><div class="empty-state" style="padding:36px 20px"><h3>No intrusion events yet</h3><p>Scanners, injection attempts and probes appear here in real time. Press "Simulate attack" for a safe demonstration.</p></div>
    <?php else: ?><table><thead><tr><th>Time</th><th>Source IP</th><th>App</th><th>Detection</th><th>Severity</th><th>Score</th><th>Outcome</th><th>Evidence</th></tr></thead><tbody>
      <?php foreach ($sh['recent'] as $e): ?><tr>
        <td class="sh-muted" style="white-space:nowrap"><?= nx_ago($e['created_at']) ?></td><td class="sh-mono"><?= nx_e($e['ip']) ?></td><td><?= nx_e($e['app']) ?></td>
        <td><b><?= nx_e(str_replace('_', ' ', $e['rule'])) ?></b></td><td><?= nx_pill($e['severity'], nx_sev_col($e['severity'])) ?></td><td class="sh-mono"><?= (int)$e['score'] ?></td><td><?= nx_outcome_pill($e['outcome']) ?></td>
        <td class="sh-muted sh-mono" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= nx_e($e['evidence']) ?>"><?= nx_e($e['evidence']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
  <div class="card"><div class="card-header"><div class="card-title">Top attackers · 24h</div></div><div class="card-body-flush">
    <?php if (!$sh['top_ips']) echo '<div class="empty-state" style="padding:26px"><h3>None</h3></div>'; foreach ($sh['top_ips'] as $t): ?>
      <div class="sh-row"><span class="sh-dot" style="background:<?= $t['is_blocked'] ? 'var(--red)' : 'var(--amber)' ?>"></span><div style="flex:1;min-width:0"><div class="sh-mono"><?= nx_e($t['ip']) ?> <?= $t['is_blocked'] ? nx_pill('blocked', 'var(--red)') : '' ?></div><div class="sh-muted"><?= nx_e($t['rules']) ?> · <?= (int)$t['hits'] ?> hits</div></div>
        <div style="text-align:right;display:flex;gap:8px;align-items:center"><span class="sh-mono">score <?= (int)$t['score'] ?></span>
        <?= $t['is_blocked'] ? nx_post_form('shield_unblock', ['ip' => $t['ip']], 'Unblock') : nx_post_form('shield_block', ['ip' => $t['ip'], 'minutes' => 1440, 'reason' => 'Blocked from Threat feed'], 'Block 24h', 'btn-danger', 'Block ' . $t['ip'] . ' for 24 hours?') ?></div></div>
    <?php endforeach; ?></div></div>
</div>

<!-- ===================== BLOCKED IPs ===================== -->
<div class="sh-sec" id="sec-blocked">
  <div class="card" style="margin-bottom:20px"><div class="card-header"><div class="card-title">Blocked IP addresses</div></div>
    <form method="POST" class="sh-form"><input type="hidden" name="_action" value="shield_block"><?= csrf_token_field() ?>
      <input type="text" name="ip" placeholder="IP address, e.g. 41.90.12.3" required><input type="number" name="minutes" value="1440" min="1" max="10080" title="minutes" style="min-width:90px">
      <input type="text" name="reason" placeholder="Reason" style="flex:1"><label class="sh-muted"><input type="checkbox" name="permanent" value="1"> permanent</label><button class="btn btn-danger btn-sm">Block IP</button></form>
    <div class="card-body-flush" style="overflow-x:auto">
    <?php if (!$sh['blocklist']): ?><div class="empty-state" style="padding:30px"><h3>No IPs are blocked</h3></div>
    <?php else: ?><table><thead><tr><th>IP</th><th>Reason</th><th>Until</th><th>Source</th><th>Blocked hits</th><th></th></tr></thead><tbody>
      <?php foreach ($sh['blocklist'] as $b): ?><tr><td class="sh-mono"><?= nx_e($b['ip']) ?></td><td style="max-width:300px"><?= nx_e($b['reason']) ?></td>
        <td class="sh-muted"><?= $b['permanent'] ? 'permanent' : nx_e($b['blocked_until']) ?></td><td><?= nx_pill($b['source'], $b['source'] === 'auto' ? 'var(--amber)' : 'var(--blue)') ?></td><td class="sh-mono"><?= (int)$b['hits'] ?></td>
        <td style="text-align:right;white-space:nowrap"><?= nx_post_form('shield_unblock', ['ip' => $b['ip']], 'Unblock') ?> <?= nx_post_form('shield_allow', ['ip' => $b['ip'], 'label' => 'Trusted (from block list)'], 'Trust', 'btn-ghost', 'Trust ' . $b['ip'] . '? It will never be inspected or blocked.') ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
  <div class="card"><div class="card-header"><div class="card-title">Trusted IPs (allow list)</div></div>
    <form method="POST" class="sh-form"><input type="hidden" name="_action" value="shield_allow"><?= csrf_token_field() ?><input type="text" name="ip" placeholder="IP to trust, e.g. your office" required><input type="text" name="label" placeholder="Label" style="flex:1"><button class="btn btn-ghost btn-sm">Add</button></form>
    <div class="card-body-flush"><?php if (!$sh['allowlist']) echo '<div class="empty-state" style="padding:24px"><h3>No trusted IPs</h3><p>On localhost 127.0.0.1 is already never blocked.</p></div>'; foreach ($sh['allowlist'] as $a): ?>
      <div class="sh-row"><div style="flex:1"><span class="sh-mono"><?= nx_e($a['ip']) ?></span> <span class="sh-muted">· <?= nx_e($a['label']) ?></span></div><?= nx_post_form('shield_allow_remove', ['id' => $a['id']], 'Remove') ?></div><?php endforeach; ?></div></div>
</div>

<!-- ===================== MALWARE ===================== -->
<div class="sh-sec" id="sec-malware">
  <div class="sh-kpis" style="margin-bottom:18px">
    <div class="sh-kpi"><b style="color:<?= $mal['open_critical'] ? 'var(--red)' : 'inherit' ?>"><?= (int)$mal['open_findings'] ?></b><span>Open findings</span></div>
    <div class="sh-kpi"><b><?= (int)$mal['quarantined'] ?></b><span>In quarantine</span></div>
    <div class="sh-kpi"><b><?= (int)$mal['rejected_30d'] ?></b><span>Uploads rejected · 30d</span></div>
    <div class="sh-kpi"><b style="font-size:15px;padding-top:6px"><?= nx_e($mal['engine'] ?: 'Built-in rules') ?></b><span>Scan engine</span></div>
  </div>
  <div class="card" style="margin-bottom:20px"><div class="card-header"><div class="card-title">Findings awaiting review</div>
    <span class="sh-muted"><?php $lu = $mal['last_upload']; echo $lu ? 'last sweep ' . nx_ago($lu['data']['at'] ?? null) : 'never scanned'; ?> · files in uploads/ are quarantined automatically</span></div>
    <div class="card-body-flush" style="overflow-x:auto">
    <?php if (!$mal['findings']): ?><div class="empty-state" style="padding:32px"><h3>✓ Nothing to review</h3><p>No suspicious files found in uploads/ or your code.</p></div>
    <?php else: ?><table><thead><tr><th>File</th><th>Finding</th><th>Severity</th><th>Area</th><th>Seen</th><th></th></tr></thead><tbody>
      <?php foreach ($mal['findings'] as $f): ?><tr><td class="sh-mono" style="max-width:280px;overflow:hidden;text-overflow:ellipsis" title="<?= nx_e($f['rel_path']) ?>"><?= nx_e($f['rel_path']) ?></td>
        <td><b><?= nx_e(str_replace('_', ' ', $f['rule'])) ?></b><div class="sh-muted"><?= nx_e($f['detail']) ?></div></td><td><?= nx_pill($f['severity'], nx_sev_col($f['severity'])) ?></td><td><?= nx_e($f['area']) ?></td><td class="sh-muted"><?= nx_ago($f['first_seen']) ?></td>
        <td style="text-align:right;white-space:nowrap"><?php if (strpos($f['rel_path'], 'REJECTED/') !== 0): ?><?= nx_post_form('shield_find_quarantine', ['id' => $f['id']], 'Quarantine', 'btn-danger', 'Move ' . $f['rel_path'] . ' to quarantine?') ?> <?php endif; ?><?= nx_post_form('shield_find_ignore', ['id' => $f['id']], 'False positive') ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
  <div class="card"><div class="card-header"><div class="card-title">Quarantine vault</div><span class="sh-muted">files are renamed, moved out of reach and cannot execute</span></div>
    <div class="card-body-flush" style="overflow-x:auto">
    <?php if (!$mal['quarantine']): ?><div class="empty-state" style="padding:28px"><h3>Vault is empty</h3></div>
    <?php else: ?><table><thead><tr><th>Original location</th><th>Why</th><th>Size</th><th>When</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($mal['quarantine'] as $q): ?><tr><td class="sh-mono" style="max-width:260px;overflow:hidden;text-overflow:ellipsis"><?= nx_e($q['original_rel']) ?></td><td><b><?= nx_e(str_replace('_', ' ', $q['rule'])) ?></b></td><td class="sh-mono"><?= nx_bytes($q['size']) ?></td><td class="sh-muted"><?= nx_ago($q['created_at']) ?></td>
        <td><?= nx_pill($q['status'], $q['status'] === 'quarantined' ? 'var(--amber)' : 'var(--tx3)') ?></td>
        <td style="text-align:right;white-space:nowrap"><?php if ($q['status'] === 'quarantined'): ?><?= nx_post_form('shield_q_restore', ['id' => $q['id']], 'Restore', 'btn-ghost', 'Restore this file to its original place? Only do this if you are sure it is safe.') ?> <?= nx_post_form('shield_q_purge', ['id' => $q['id']], 'Delete forever', 'btn-danger', 'Permanently delete this file?') ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
</div>

<!-- ===================== FILE INTEGRITY ===================== -->
<div class="sh-sec" id="sec-integrity">
  <div class="card"><div class="card-header"><div class="card-title">Code file integrity</div>
    <div style="display:flex;gap:8px"><?php if ($mal['fim_changes']) echo nx_post_form('shield_fim_approve', ['hash' => 'all'], '✓ Approve all changes', 'btn-primary', 'Accept every listed change as the new trusted baseline?'); ?>
    <?= nx_post_form('shield_fim_baseline', [], $mal['fim_files'] ? '↺ Rebuild baseline' : 'Create baseline', 'btn-ghost', 'Take a fresh fingerprint of every code file and treat the current state as trusted?') ?></div></div>
    <div class="sh-row" style="background:var(--ink3)"><div class="sh-muted" style="line-height:1.6"><?= $mal['fim_files'] ? '<b style="color:var(--tx)">' . number_format($mal['fim_files']) . '</b> code files are fingerprinted. While you develop, edits will show up below - approve them when they are yours. A file you did NOT change, or a new .php file you did not create, is the sign of an intrusion.' : 'No baseline yet. Click <b>Create baseline</b> once your code is in a known-good state.' ?></div></div>
    <div class="card-body-flush" style="overflow-x:auto">
    <?php if ($mal['fim_files'] && !$mal['fim']): ?><div class="empty-state" style="padding:30px"><h3>✓ Every file matches the baseline</h3></div>
    <?php elseif ($mal['fim']): ?><table><thead><tr><th>Status</th><th>File</th><th>Changed</th><th></th></tr></thead><tbody>
      <?php foreach ($mal['fim'] as $f): $col = ['new' => 'var(--red)', 'modified' => 'var(--amber)', 'deleted' => 'var(--cyan)'][$f['status']] ?? 'var(--tx2)'; ?><tr><td><?= nx_pill($f['status'], $col) ?></td><td class="sh-mono"><?= nx_e($f['rel_path']) ?></td><td class="sh-muted"><?= nx_ago($f['changed_at']) ?></td>
        <td style="text-align:right"><?= nx_post_form('shield_fim_approve', ['hash' => $f['path_hash']], 'Approve') ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
</div>

<!-- ===================== POSTURE ===================== -->
<div class="sh-sec" id="sec-posture">
  <div class="card" style="margin-bottom:20px"><div class="card-body" style="display:flex;gap:22px;align-items:center;flex-wrap:wrap">
    <?= nx_ring($po['score'], $po['grade'], 120) ?>
    <div style="flex:1;min-width:220px"><div style="font-family:'Fraunces',serif;font-size:22px;font-weight:600">Security posture: <?= (int)$po['score'] ?>/100</div>
      <div class="sh-muted" style="margin:4px 0 10px"><?= $po['counts']['pass'] ?> passed · <?= $po['counts']['warn'] ?> warnings · <?= $po['counts']['fail'] ?> failed · <?= $po['counts']['info'] ?> informational</div>
      <?php if ($po['local']): ?><div class="sh-muted" style="margin-bottom:10px">Running on localhost: HTTPS-only checks are shown as informational and do not lower your score yet.</div><?php endif; ?>
      <?= nx_post_form('shield_posture_run', [], '⟳ Re-check now', 'btn-ghost') ?></div></div></div>
  <div class="card"><div class="card-body-flush">
    <?php $ic = ['pass' => ['✓', 'var(--green)'], 'warn' => ['!', 'var(--amber)'], 'fail' => ['✕', 'var(--red)'], 'info' => ['i', 'var(--tx3)']];
    foreach ($po['checks'] as $x): [$sym, $col] = $ic[$x['status']]; ?>
      <div class="sh-row" style="gap:13px"><div style="width:24px;height:24px;border-radius:50%;border:1.5px solid <?= $col ?>;color:<?= $col ?>;display:grid;place-items:center;font:700 12px 'DM Mono',monospace;flex-shrink:0;margin-top:1px"><?= $sym ?></div>
        <div style="flex:1;min-width:0"><div style="font-size:13.5px;font-weight:600"><?= nx_e($x['title']) ?> <span class="sh-muted" style="font-weight:400">· <?= nx_e($x['cat']) ?></span></div>
          <div class="sh-muted" style="font-size:12.5px;margin-top:2px"><?= nx_e($x['detail']) ?></div>
          <?php if (in_array($x['status'], ['fail', 'warn'], true) && $x['fix']): ?><div style="font-size:12.5px;margin-top:5px;color:var(--tx2)"><b style="color:<?= $col ?>">Fix:</b> <?= nx_e($x['fix']) ?></div><?php endif; ?></div></div>
    <?php endforeach; ?></div></div>
</div>

<script>
(function(){
  var secs=document.querySelectorAll('.sh-sec'),tabs=document.querySelectorAll('.sh-tabs a');
  function show(){var h=(location.hash||'#overview').slice(1);if(!document.getElementById('sec-'+h))h='overview';
    secs.forEach(function(s){s.classList.toggle('on',s.id==='sec-'+h)});tabs.forEach(function(a){a.classList.toggle('on',a.getAttribute('href')==='#'+h)});}
  window.addEventListener('hashchange',show);show();
})();
</script>
<?php }
