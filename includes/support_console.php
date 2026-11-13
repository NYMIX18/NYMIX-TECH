<?php
/**
 * includes/support_console.php
 * Support-only console for NYMIX TECH.
 *
 * nxs_route() is called by dashboard.php right after login. For the "support" role it renders
 * this console and returns true (dashboard.php then exits). No finance data (invoices, payments,
 * prices, MRR) is ever queried for this role. Ticket AJAX endpoints fall through to the existing
 * handlers in dashboard.php, so RBAC, CSRF, voice notes and AI suggest keep working.
 */

if (!function_exists('nxs_security_card')) { function nxs_security_card($conn) { } }

function nxs_h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function nxs_json(array $d): void { header('Content-Type: application/json'); echo json_encode($d); exit; }
function nxs_age(int $m): string {
    if ($m < 1)    return 'just now';
    if ($m < 60)   return $m . 'm';
    if ($m < 1440) return intdiv($m, 60) . 'h ' . ($m % 60) . 'm';
    return intdiv($m, 1440) . 'd';
}
/** Safe client fields only: no KRA PIN, no notes, no money. */
function nxs_client_sql(): string {
    return "SELECT c.id, c.business_name, c.owner_name, c.phone, c.email, c.address, c.status, c.logo,
            (SELECT sp.name FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id
              WHERE s.client_id = c.id ORDER BY s.id DESC LIMIT 1) AS plan_name
            FROM clients c";
}

function nxs_route($conn, $role): bool {
    if ($role !== 'support') return false;
    nxs_schema($conn);   // makes sure the AI tables exist

    $act  = (string)($_POST['_action'] ?? $_GET['action'] ?? '');
    $pass = ['ticket_poll', 'ticket_typing', 'ticket_reply_ajax', 'ticket_voice_reply', 'ticket_meta_update', 'ticket_add', 'ticket_reply', 'ai_ticket_suggest'];
    if (in_array($act, $pass, true)) return false;   // handled (and RBAC-checked) by dashboard.php; all of these exit/redirect

    $uid = (int)($_SESSION['sa_id'] ?? 0);

    if ($act === 'support_client_search') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) nxs_json(['ok' => true, 'clients' => []]);
        $esc   = fn($s) => '%' . addcslashes($s, '%_\\') . '%';
        $like  = $esc($q);
        $dig   = preg_replace('/[\s\-]+/', '', $q);
        if (preg_match('/^0\d{6,}$/', $dig)) $dig = substr($dig, 1);   // 0712... also matches +254 712...
        $likeP = $esc($dig);
        $rows = soc_rows($conn, nxs_client_sql() . " WHERE c.business_name LIKE ? OR c.owner_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR REPLACE(c.phone,' ','') LIKE ? ORDER BY c.business_name LIMIT 15",
            'sssss', [$like, $like, $like, $like, $likeP]);
        nxs_json(['ok' => true, 'clients' => $rows]);
    }

    if ($act === 'support_client_card') {
        $id = (int)($_GET['id'] ?? 0);
        $c  = soc_rows($conn, nxs_client_sql() . ' WHERE c.id = ? LIMIT 1', 'i', [$id]);
        if (!$c) nxs_json(['ok' => false]);
        $t = soc_rows($conn, "SELECT id, subject, status, priority, created_at FROM support_tickets WHERE client_id = ? ORDER BY id DESC LIMIT 10", 'i', [$id]);
        nxs_json(['ok' => true, 'client' => $c[0], 'tickets' => $t]);
    }

    if ($act === 'ticket_claim') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? '')))
            nxs_json(['ok' => false, 'msg' => 'Session expired. Reload the page.']);
        $tid = (int)($_POST['ticket_id'] ?? 0); $n = 0;
        try {
            $st = $conn->prepare("UPDATE support_tickets SET assigned_to = ?, status = IF(status = 'open', 'in_progress', status) WHERE id = ? AND (assigned_to IS NULL OR assigned_to = 0)");
            $st->bind_param('ii', $uid, $tid); $st->execute(); $n = $st->affected_rows; $st->close();
        } catch (Throwable $e) { error_log('[support] claim: ' . $e->getMessage()); }
        if ($n > 0) soc_audit($conn, 'ticket_claim', 'ticket', (string)$tid, '');
        nxs_json(['ok' => $n > 0, 'msg' => $n > 0 ? '' : 'Already assigned to someone else.']);
    }

    nxs_render($conn, $uid);
    return true;
}

function nxs_ticket_row(array $t, int $uid): string {
    $id     = (int)$t['id'];
    $isOpen = in_array($t['status'], ['open', 'in_progress'], true);
    $age    = (int)$t['age_min'];
    $needs  = $isOpen && ($t['last_sender'] ?? null) !== 'admin';
    $mine   = $isOpen && (int)$t['assigned_to'] === $uid;
    $un     = $isOpen && empty($t['assigned_to']);
    $urgent = $isOpen && in_array($t['priority'], ['critical', 'high'], true);
    $ageCls = !$needs ? '' : ($age >= 240 ? 'age-r' : ($age >= 60 ? 'age-a' : ''));
    if (!$isOpen)     $ageLbl = date('d M', strtotime($t['created_at']));
    elseif ($needs)   $ageLbl = 'Waiting ' . nxs_age($age);
    else              $ageLbl = 'Replied ' . nxs_age($age) . ' ago';
    $q    = mb_strtolower($t['business_name'] . ' ' . $t['owner_name'] . ' ' . $t['subject'] . ' #' . $id);
    $init = strtoupper(mb_substr($t['business_name'], 0, 2));
    $who  = $t['assigned_name'] ?: 'Unassigned';
    $h  = '<div class="tk" onclick="nxsOpen(' . $id . ')" data-open="' . (int)$isOpen . '" data-mine="' . (int)$mine . '" data-un="' . (int)$un . '" data-urgent="' . (int)$urgent . '" data-needs="' . (int)$needs . '" data-q="' . nxs_h($q) . '">';
    $h .= '<div class="av">' . nxs_h($init) . '</div><div class="tk-b">';
    $h .= '<div class="tk-top"><span class="tk-n">' . nxs_h($t['business_name']) . '</span><span class="tk-age ' . $ageCls . '">' . nxs_h($ageLbl) . '</span></div>';
    $h .= '<div class="tk-sub"><span class="pd pd-' . nxs_h($t['priority']) . '" title="' . nxs_h(ucfirst($t['priority'])) . ' priority"></span>' . nxs_h($t['subject']) . '</div>';
    $h .= '<div class="tk-meta"><span class="bd bd-' . nxs_h($t['status']) . '">' . nxs_h(ucfirst(str_replace('_', ' ', $t['status']))) . '</span>';
    $h .= '<span class="mut">#' . $id . ' · ' . (int)$t['reply_count'] . ' replies · ' . nxs_h($who) . '</span>';
    if ((int)($t['ai_replies'] ?? 0) > 0) $h .= '<span class="bd bd-ai">🤖 AI replied</span>';
    if (($t['ai_flag'] ?? '') === 'human' && $isOpen) $h .= '<span class="bd bd-human">⚠ Needs human</span>';
    if ($un) $h .= '<button class="btn sm gold" onclick="event.stopPropagation();nxsClaim(' . $id . ')">Claim</button>';
    $h .= '</div></div></div>';
    return $h;
}

function nxs_render($conn, int $uid): void {
    $me   = (string)($_SESSION['sa_name'] ?? 'Support');
    $tab  = (string)($_GET['tab'] ?? 'dashboard');
    $view = in_array($tab, ['tickets', 'clients'], true) ? $tab : 'home';
    $csrf = function_exists('csrf_generate') ? csrf_generate() : (string)($_SESSION['csrf_token'] ?? '');
    $JF   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    $tickets = soc_rows($conn, "SELECT x.*, TIMESTAMPDIFF(MINUTE, x.last_at, NOW()) AS age_min FROM (
            SELECT t.id, t.client_id, t.subject, t.priority, t.status, t.assigned_to, t.created_at,
                   c.business_name, c.owner_name, c.phone, c.email, c.address, c.logo, c.status AS client_status,
                   sa.name AS assigned_name,
                   (SELECT a.flag FROM nx_ai_ticket a WHERE a.ticket_id = t.id) AS ai_flag,
                   (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.sender_type = 'admin' AND r.sender_id = (SELECT id FROM super_admins WHERE username = 'nymix_ai' LIMIT 1)) AS ai_replies,
                   (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id) AS reply_count,
                   (SELECT r.sender_type FROM ticket_replies r WHERE r.ticket_id = t.id ORDER BY r.id DESC LIMIT 1) AS last_sender,
                   COALESCE((SELECT r.created_at FROM ticket_replies r WHERE r.ticket_id = t.id ORDER BY r.id DESC LIMIT 1), t.created_at) AS last_at
            FROM support_tickets t
            JOIN clients c ON c.id = t.client_id
            LEFT JOIN super_admins sa ON sa.id = t.assigned_to
        ) x
        ORDER BY FIELD(x.status,'open','in_progress','resolved','closed'), FIELD(x.priority,'critical','high','medium','low'), x.last_at ASC
        LIMIT 300");

    $stats = ['open' => 0, 'mine' => 0, 'un' => 0, 'urgent' => 0, 'waiting' => 0, 'needs' => 0, 'done' => 0];
    $map = []; $needsList = [];
    foreach ($tickets as $t) {
        $isOpen = in_array($t['status'], ['open', 'in_progress'], true);
        $needs  = $isOpen && ($t['last_sender'] ?? null) !== 'admin';
        if ($isOpen) {
            $stats['open']++;
            if ((int)$t['assigned_to'] === $uid) $stats['mine']++;
            if (empty($t['assigned_to']))        $stats['un']++;
            if (in_array($t['priority'], ['critical', 'high'], true)) $stats['urgent']++;
            if (($t['last_sender'] ?? null) === 'admin') $stats['waiting']++;
            if ($needs) { $stats['needs']++; $needsList[] = $t; }
        } else $stats['done']++;
        $map[(int)$t['id']] = ['id' => (int)$t['id'], 'client_id' => (int)$t['client_id'], 'business_name' => $t['business_name'], 'owner_name' => $t['owner_name'],
            'subject' => $t['subject'], 'status' => $t['status'], 'priority' => $t['priority'], 'phone' => $t['phone'], 'email' => $t['email'],
            'address' => $t['address'], 'client_status' => $t['client_status'], 'assigned_to' => (int)$t['assigned_to']];
    }

    $hr = (int)(new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('G');
    $greet = $hr < 12 ? 'Good morning' : ($hr < 17 ? 'Good afternoon' : 'Good evening');
    $titles = ['home' => 'MY QUEUE', 'tickets' => 'TICKETS', 'clients' => 'CLIENTS'];
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Support Console — NYMIX TECH</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--ink:#0d0f14;--ink2:#13161e;--ink3:#1a1e28;--ink4:#222636;--line:#2a2f3f;--tx:#e8eaf0;--tx2:#8b91a8;--tx3:#5a6075;--gold:#f59e0b;--green:#3ecf8e;--red:#f75555;--amber:#f5c542;--purple:#7c6af7}
html,body{background:var(--ink);color:var(--tx);font:14px/1.6 'DM Sans',sans-serif;min-height:100vh}
a{color:inherit;text-decoration:none}
button,input,select{font-family:inherit;font-size:14px}
.sb{position:fixed;inset:0 auto 0 0;width:236px;background:var(--ink2);border-right:1px solid var(--line);display:flex;flex-direction:column;z-index:50}
.sb::before{content:'';height:2px;background:linear-gradient(90deg,#f59e0b,#fb923c)}
.sb-logo{display:flex;align-items:center;gap:11px;padding:18px;border-bottom:1px solid var(--line)}
.sb-mark{width:38px;height:38px;border-radius:11px;background:var(--ink3);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font:800 15px 'Syne',sans-serif;color:var(--gold)}
.sb-name{font:800 16px 'Syne',sans-serif;letter-spacing:2px}.sb-role{font:500 9px 'DM Mono',monospace;letter-spacing:2px;color:var(--gold);text-transform:uppercase}
.sb-nav{flex:1;padding:14px 10px}
.nav{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;color:var(--tx2);font-weight:500;margin-bottom:3px;position:relative}
.nav:hover{background:var(--ink3);color:var(--tx)}.nav.on{background:var(--ink3);color:var(--gold)}
.nav.on::before{content:'';position:absolute;left:0;top:25%;bottom:25%;width:2px;background:var(--gold);border-radius:2px}
.nav svg,.bn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
.nb{margin-left:auto;background:rgba(247,85,85,.14);color:var(--red);font:500 10px 'DM Mono',monospace;padding:1px 7px;border-radius:20px}
.sb-foot{padding:10px;border-top:1px solid var(--line)}
.sb-user{display:flex;align-items:center;gap:9px;padding:9px;border-radius:11px;background:var(--ink3);border:1px solid var(--line)}
.sb-av{width:32px;height:32px;border-radius:9px;background:rgba(245,158,11,.15);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px}
.sb-un{font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sb-ur{font:500 9px 'DM Mono',monospace;letter-spacing:1.5px;color:var(--gold);text-transform:uppercase}
.lo{margin-left:auto;color:var(--tx3);padding:6px;border-radius:7px;display:flex}.lo:hover{color:var(--red);background:rgba(247,85,85,.12)}.lo svg{width:15px;height:15px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.tb{position:fixed;top:0;left:236px;right:0;height:60px;background:var(--ink2);border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 26px;gap:14px;z-index:40}
.tb-t{flex:1;font:800 17px 'Syne',sans-serif;letter-spacing:2px}.tb-g{color:var(--tx3);font-size:13px;display:flex;align-items:center;gap:7px}
.dot{width:6px;height:6px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green)}
.mn{margin-left:236px;padding:84px 26px 40px;max-width:1200px}
.ph{margin-bottom:22px}.ph small{font:500 9px 'DM Mono',monospace;letter-spacing:3px;color:var(--gold);text-transform:uppercase}
.ph h1{font:800 28px 'Syne',sans-serif;letter-spacing:1px;line-height:1.15}.ph p{color:var(--tx3);font-size:13px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;border:1px solid var(--line);background:transparent;color:var(--tx2);font-weight:600;cursor:pointer}
.btn:hover{background:var(--ink3);color:var(--tx)}.btn.gold{background:var(--gold);border-color:var(--gold);color:#000}.btn.gold:hover{background:#d97706}
.btn.sm{padding:3px 10px;font-size:12px;border-radius:7px;margin-left:auto}
.card{background:var(--ink2);border:1px solid var(--line);border-radius:16px;overflow:hidden;margin-bottom:18px}
.card-h{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--line);font-weight:600;gap:10px;flex-wrap:wrap}
.card-b{padding:18px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.stat{background:var(--ink2);border:1px solid var(--line);border-radius:14px;padding:16px 18px;display:block;transition:.15s}.stat:hover{border-color:#3a4058;transform:translateY(-2px)}
.stat .l{font:500 9px 'DM Mono',monospace;letter-spacing:1.5px;color:var(--tx3);text-transform:uppercase}.stat .v{font:700 30px 'Syne',sans-serif;line-height:1.2}.stat .s{font-size:12px;color:var(--tx3)}
.tk{display:flex;gap:12px;padding:13px 18px;border-bottom:1px solid var(--line);cursor:pointer}.tk:last-child{border-bottom:0}.tk:hover{background:var(--ink3)}
.av{width:42px;height:42px;border-radius:50%;background:rgba(124,106,247,.15);color:var(--purple);display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0}
.tk-b{flex:1;min-width:0}.tk-top{display:flex;justify-content:space-between;gap:8px}
.tk-n{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tk-age{font:500 11px 'DM Mono',monospace;color:var(--tx3);white-space:nowrap}
.age-a{color:var(--amber)}.age-r{color:var(--red);font-weight:700}
.tk-sub{color:var(--tx2);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:7px}
.tk-meta{display:flex;align-items:center;gap:9px;margin-top:5px;flex-wrap:wrap}.mut{color:var(--tx3);font-size:11px}
.pd{width:7px;height:7px;border-radius:50%;flex-shrink:0;background:var(--tx3)}.pd-critical{background:var(--red)}.pd-high{background:var(--gold)}.pd-medium{background:var(--purple)}
.bd{font-size:11px;font-weight:600;padding:2px 9px;border-radius:20px}
.bd-open{background:rgba(245,197,66,.15);color:var(--amber)}.bd-in_progress{background:rgba(124,106,247,.15);color:var(--purple)}.bd-resolved{background:rgba(62,207,142,.15);color:var(--green)}.bd-closed{background:var(--ink4);color:var(--tx3)}
.bd-active{background:rgba(62,207,142,.15);color:var(--green)}.bd-suspended{background:rgba(247,85,85,.14);color:var(--red)}.bd-read_only{background:rgba(245,197,66,.15);color:var(--amber)}.bd-cancelled{background:var(--ink4);color:var(--tx3)}
.bd-ai{background:rgba(124,106,247,.18);color:#b3a8ff}.bd-human{background:rgba(247,85,85,.14);color:var(--red)}
.wa-bub.ai{background:#2b2750;border:1px solid rgba(124,106,247,.5);border-top-right-radius:2px}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.chip{padding:6px 13px;border-radius:20px;border:1px solid var(--line);background:var(--ink2);color:var(--tx2);cursor:pointer;font-size:13px;font-weight:500}
.chip.on{background:var(--gold);border-color:var(--gold);color:#000}.chip i{font-style:normal;opacity:.7;margin-left:4px;font-size:11px}
.bar{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}
input[type=text],input[type=search],select,textarea{background:var(--ink3);border:1px solid var(--line);color:var(--tx);border-radius:10px;padding:10px 13px;outline:none;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--gold)}.bar input{flex:1;min-width:200px}
.empty{text-align:center;padding:40px 20px;color:var(--tx3)}.pad{padding:16px}
.grid2{display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start}
.cr{display:flex;align-items:center;gap:11px;padding:11px 14px;border-bottom:1px solid var(--line);cursor:pointer}.cr:hover,.cr.on{background:var(--ink3)}
.logo{width:42px;height:42px;border-radius:50%;object-fit:cover}
.kv{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid var(--line)}.kv:last-child{border:0}
.kv span:first-child{font:500 9px 'DM Mono',monospace;letter-spacing:1px;color:var(--tx3);text-transform:uppercase;padding-top:3px}.kv span:last-child,.kv a{text-align:right;word-break:break-word}.kv a{color:var(--gold)}
.hist{display:flex;align-items:center;gap:9px;padding:9px 0;border-bottom:1px solid var(--line)}.hist:last-child{border:0}.hist:hover{color:var(--gold)}
.ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:200;align-items:center;justify-content:center;padding:20px}.ov.open{display:flex}
.md{background:var(--ink2);border:1px solid var(--line);border-radius:18px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;position:relative}
.md::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,#f59e0b,#fb923c)}
.md-h{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line);font-weight:700;gap:10px}
.x{width:30px;height:30px;border-radius:8px;border:1px solid var(--line);background:var(--ink3);color:var(--tx3);cursor:pointer}.x:hover{color:var(--red)}
.md-b{padding:20px}.md-f{display:flex;justify-content:flex-end;gap:9px;padding:14px 20px;border-top:1px solid var(--line);background:var(--ink3)}
.fg{margin-bottom:14px}.fg label{display:block;font:500 9px 'DM Mono',monospace;letter-spacing:2px;color:var(--tx3);text-transform:uppercase;margin-bottom:6px}
.pick{border:1px solid var(--line);border-radius:10px;margin-top:6px;max-height:180px;overflow-y:auto}
.chosen{display:none;margin-top:8px;padding:8px 12px;border-radius:10px;background:rgba(245,158,11,.12);color:var(--gold);font-weight:600}
.md.chat{max-width:520px;height:min(660px,88vh);display:flex;flex-direction:column;padding:0;overflow:hidden}.md.chat .md-h{cursor:pointer;flex-shrink:0}
.ctl{display:flex;gap:8px;padding:10px 14px;border-bottom:1px solid var(--line);background:var(--ink3);flex-shrink:0;align-items:center}.ctl select{flex:1;padding:7px 10px}
.wa-body{background:#0b141a;flex:1;min-height:0;overflow-y:auto;padding:16px;display:flex;flex-direction:column}
.wa-row{display:flex;margin-bottom:8px}.wa-row.me{justify-content:flex-end}
.wa-bub{max-width:74%;padding:8px 11px 6px;border-radius:10px;font-size:13.5px;line-height:1.5;word-wrap:break-word}
.wa-bub.me{background:#005c4b;border-top-right-radius:2px}.wa-bub.them{background:#202c33;border-top-left-radius:2px}
.wa-name{font-size:11px;font-weight:700;color:var(--gold);margin-bottom:2px}.wa-time{font-size:10px;color:rgba(255,255,255,.5);text-align:right;margin-top:3px}
.wa-typing{display:none;gap:4px;padding:6px 14px;background:#0b141a;flex-shrink:0}.wa-typing span{width:6px;height:6px;border-radius:50%;background:#7a8a94;animation:bl 1.2s infinite}.wa-typing span:nth-child(2){animation-delay:.2s}.wa-typing span:nth-child(3){animation-delay:.4s}
@keyframes bl{0%,60%,100%{opacity:.3}30%{opacity:1}}
.canned{display:none;max-height:170px;overflow-y:auto;border-top:1px solid var(--line);background:var(--ink3);flex-shrink:0}.canned div{padding:9px 16px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--line)}.canned div:hover{background:var(--ink4);color:var(--gold)}
.comp{display:flex;align-items:center;gap:7px;padding:10px 12px;background:var(--ink3);border-top:1px solid var(--line);flex-shrink:0}
.comp input{border-radius:20px;padding:9px 15px}.ib{width:38px;height:38px;border-radius:50%;border:0;color:#000;background:var(--gold);cursor:pointer;flex-shrink:0;font-size:15px}.ib.rec{background:var(--red);animation:pu 1s infinite}
@keyframes pu{0%,100%{box-shadow:0 0 0 0 rgba(247,85,85,.5)}50%{box-shadow:0 0 0 8px rgba(247,85,85,0)}}
.bn{display:none}
@media(max-width:900px){.grid2{grid-template-columns:1fr}}
@media(max-width:768px){
 .sb{display:none}.tb{left:0;padding:0 16px}.tb-g{display:none}.mn{margin-left:0;padding:78px 14px 90px}
 .bn{display:flex;position:fixed;bottom:0;left:0;right:0;background:var(--ink2);border-top:1px solid var(--line);z-index:60}
 .bn a{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:9px 0;font-size:11px;color:var(--tx3)}.bn a.on{color:var(--gold)}
 .md{max-height:94vh}
}
</style></head>
<body>
<aside class="sb">
  <div class="sb-logo"><div class="sb-mark">NT</div><div><div class="sb-name">NYMIX TECH</div><div class="sb-role">Support Console</div></div></div>
  <nav class="sb-nav">
    <a class="nav <?= $view === 'home' ? 'on' : '' ?>" href="?tab=dashboard"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>My queue</a>
    <a class="nav <?= $view === 'tickets' ? 'on' : '' ?>" href="?tab=tickets"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Tickets<?php if ($stats['needs']): ?><span class="nb"><?= (int)$stats['needs'] ?></span><?php endif; ?></a>
    <a class="nav <?= $view === 'clients' ? 'on' : '' ?>" href="?tab=clients"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Find a client</a>
  </nav>
  <div class="sb-foot"><div class="sb-user">
    <div class="sb-av"><?= nxs_h(strtoupper(mb_substr($me, 0, 2))) ?></div>
    <div style="min-width:0"><div class="sb-un"><?= nxs_h($me) ?></div><div class="sb-ur">Support</div></div>
    <a class="lo" href="?action=logout" title="Logout"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></a>
  </div></div>
</aside>

<header class="tb"><div class="tb-t"><?= $titles[$view] ?></div><div class="tb-g"><span class="dot"></span><?= $greet ?>, <?= nxs_h(explode(' ', $me)[0]) ?></div></header>

<main class="mn">
<?php if ($view === 'home'): ?>
  <div class="ph"><small>Support</small><h1>My queue</h1><p><?= $stats['needs'] ? (int)$stats['needs'] . ' ticket' . ($stats['needs'] > 1 ? 's' : '') . ' waiting for a reply' : 'Nothing waiting. You are all caught up.' ?></p></div>
  <div class="stats">
    <a class="stat" href="?tab=tickets&f=needs"><div class="l">Needs reply</div><div class="v" style="color:<?= $stats['needs'] ? 'var(--red)' : 'var(--green)' ?>"><?= (int)$stats['needs'] ?></div><div class="s">client is waiting</div></a>
    <a class="stat" href="?tab=tickets&f=mine"><div class="l">Assigned to me</div><div class="v"><?= (int)$stats['mine'] ?></div><div class="s">open tickets</div></a>
    <a class="stat" href="?tab=tickets&f=un"><div class="l">Unassigned</div><div class="v" style="color:<?= $stats['un'] ? 'var(--amber)' : 'var(--tx)' ?>"><?= (int)$stats['un'] ?></div><div class="s">up for grabs</div></a>
    <a class="stat" href="?tab=tickets&f=urgent"><div class="l">Urgent</div><div class="v" style="color:<?= $stats['urgent'] ? 'var(--red)' : 'var(--tx)' ?>"><?= (int)$stats['urgent'] ?></div><div class="s">high or critical</div></a>
    <a class="stat" href="?tab=tickets&f=open"><div class="l">Waiting on client</div><div class="v"><?= (int)$stats['waiting'] ?></div><div class="s">you replied last</div></a>
  </div>
  <div class="card"><div class="card-h">Quick client lookup</div><div class="card-b">
    <form method="GET"><input type="hidden" name="tab" value="clients"><input type="search" name="q" placeholder="Search by business name, owner, phone or email…"></form>
  </div></div>
  <div class="card"><div class="card-h">Needs a reply <a class="btn" style="padding:4px 12px;font-size:12px" href="?tab=tickets&f=needs">View all</a></div>
    <?php if (!$needsList): ?><div class="empty">Inbox zero. Nice work.</div>
    <?php else: foreach (array_slice($needsList, 0, 8) as $t) echo nxs_ticket_row($t, $uid); endif; ?>
  </div>

<?php elseif ($view === 'tickets'): ?>
  <div class="ph" style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div><small>Support</small><h1>Tickets</h1><p><?= (int)$stats['open'] ?> open · <?= (int)$stats['done'] ?> resolved</p></div>
    <button class="btn gold" onclick="nxsNewTicket()">+ New ticket</button>
  </div>
  <div class="chips" id="chips">
    <div class="chip" data-f="open">Open<i><?= (int)$stats['open'] ?></i></div>
    <div class="chip" data-f="needs">Needs reply<i><?= (int)$stats['needs'] ?></i></div>
    <div class="chip" data-f="mine">Mine<i><?= (int)$stats['mine'] ?></i></div>
    <div class="chip" data-f="un">Unassigned<i><?= (int)$stats['un'] ?></i></div>
    <div class="chip" data-f="urgent">Urgent<i><?= (int)$stats['urgent'] ?></i></div>
    <div class="chip" data-f="done">Resolved<i><?= (int)$stats['done'] ?></i></div>
    <div class="chip" data-f="all">All</div>
  </div>
  <div class="bar"><input type="search" id="tk-q" placeholder="Search ticket #, subject or business…" oninput="nxsApply()"></div>
  <div class="card" id="tk-list">
    <?php foreach ($tickets as $t) echo nxs_ticket_row($t, $uid); ?>
    <div class="empty" id="tk-empty" style="display:none">No tickets match.</div>
  </div>

<?php else: ?>
  <div class="ph"><small>Support</small><h1>Find a client</h1><p>Look up contact details, account status and ticket history.</p></div>
  <div class="bar"><input type="search" id="cl-q" placeholder="Business name, owner, phone or email…" oninput="nxsSearchInput(this)" autofocus></div>
  <div class="grid2">
    <div class="card" id="cl-results"><div class="empty">Type at least 2 characters to search.</div></div>
    <div id="cl-card"><div class="card"><div class="empty">Select a client to see their details.</div></div></div>
  </div>
<?php endif; ?>
</main>

<nav class="bn">
  <a class="<?= $view === 'home' ? 'on' : '' ?>" href="?tab=dashboard"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>Queue</a>
  <a class="<?= $view === 'tickets' ? 'on' : '' ?>" href="?tab=tickets"><svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Tickets</a>
  <a class="<?= $view === 'clients' ? 'on' : '' ?>" href="?tab=clients"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Clients</a>
  <a href="?action=logout"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Logout</a>
</nav>

<!-- Ticket chat -->
<div class="ov" id="m-chat"><div class="md chat">
  <div class="md-h" onclick="nxsInfo()" title="View client info">
    <div style="display:flex;align-items:center;gap:11px;min-width:0"><div class="av" id="ct-av" style="width:38px;height:38px;font-size:13px">?</div>
      <div style="min-width:0"><div id="ct-name" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">—</div><div id="ct-sub" class="mut" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">—</div></div></div>
    <button class="x" onclick="event.stopPropagation();nxsCloseChat()">✕</button>
  </div>
  <div class="ctl">
    <select id="ct-status" onchange="nxsMeta()"><option value="open">Open</option><option value="in_progress">In progress</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select>
    <select id="ct-priority" onchange="nxsMeta()"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical</option></select>
    <button class="btn gold sm" id="ct-claim" style="margin:0;display:none" onclick="nxsClaim(C.id,true)">Assign to me</button>
  </div>
  <div class="wa-body" id="ct-thread"></div>
  <div class="wa-typing" id="ct-typing"><span></span><span></span><span></span></div>
  <div class="canned" id="ct-canned"></div>
  <div class="comp">
    <input type="text" id="ct-input" placeholder="Type a message…" autocomplete="off">
    <span id="ct-timer" class="mut" style="display:none;color:var(--red)"></span>
    <button class="ib" style="background:var(--ink4);color:var(--tx)" title="Canned replies" onclick="nxsCanned()">💬</button>
    <button class="ib" style="background:var(--purple);color:#fff" title="AI: suggest a reply" onclick="nxsAiSuggest(this)">✨</button>
    <button class="ib" id="ct-mic" title="Record voice note" onclick="nxsRecToggle()">🎤</button>
    <button class="ib" style="background:var(--green)" title="Send" onclick="nxsSend()">➤</button>
  </div>
</div></div>

<!-- Client info (from chat) -->
<div class="ov" id="m-info"><div class="md" style="max-width:380px">
  <div class="md-h">Client info<button class="x" onclick="closeModal('m-info')">✕</button></div>
  <div class="md-b">
    <div id="ci-name" style="font:800 18px 'Syne',sans-serif;margin-bottom:12px"></div>
    <div class="kv"><span>Owner</span><span id="ci-owner"></span></div>
    <div class="kv"><span>Phone</span><span id="ci-phone"></span></div>
    <div class="kv"><span>Email</span><span id="ci-email"></span></div>
    <div class="kv"><span>Location</span><span id="ci-addr"></span></div>
    <div class="kv"><span>Account</span><span id="ci-status"></span></div>
    <button class="btn gold" style="width:100%;justify-content:center;margin-top:16px" onclick="closeModal('m-info');openModal('m-chat')">← Back to chat</button>
  </div>
</div></div>

<!-- New ticket -->
<div class="ov" id="m-new"><div class="md">
  <div class="md-h">New ticket<button class="x" onclick="closeModal('m-new')">✕</button></div>
  <form method="POST">
    <input type="hidden" name="_action" value="ticket_add"><input type="hidden" name="csrf_token" value="<?= nxs_h($csrf) ?>">
    <input type="hidden" name="assigned_to" value="<?= (int)$uid ?>"><input type="hidden" name="client_id" id="nt-client">
    <div class="md-b">
      <div class="fg"><label>Client *</label><input type="text" id="nt-q" placeholder="Search business, phone or email…" oninput="nxsNtSearch(this.value)" autocomplete="off">
        <div class="chosen" id="nt-chosen"></div><div class="pick" id="nt-res" style="display:none"></div></div>
      <div class="fg"><label>Subject *</label><input type="text" name="subject" id="nt-subject" required placeholder="Brief description…"></div>
      <div class="fg"><label>Priority</label><select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div>
    </div>
    <div class="md-f"><button type="button" class="btn" onclick="closeModal('m-new')">Cancel</button><button type="submit" class="btn gold" id="nt-go" disabled>Create &amp; assign to me</button></div>
  </form>
</div></div>

<script>
const TICKETS = <?= json_encode($map, $JF) ?>;
const ME = <?= (int)$uid ?>;
const CSRF = <?= json_encode($csrf, $JF) ?>;
const P = new URLSearchParams(location.search);
const CANNED = [
  ['Acknowledge', "Hello, thanks for reaching out to NYMIX TECH support. We're looking into this and will get back to you shortly."],
  ['Ask for screenshot', "Could you please send a screenshot of what you see on your screen? It will help us find the problem faster."],
  ['Ask for steps', "Could you tell us the exact steps you took just before this happened, and which phone, computer or browser you are using?"],
  ['Checking now', "We're checking this now. Please give us a few minutes and we'll update you here."],
  ['Fixed - refresh', "This has been fixed on our side. Please refresh the page (or log out and back in) and let us know if it still happens."],
  ['Billing question', "For questions about invoices or payments, our billing team will assist you. I'm passing your request on to them now."],
  ['Closing', "Glad we could help! We'll mark this ticket as resolved. Message us anytime if you need anything else."]
];

function escH(s){return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.ov').forEach(o=>o.addEventListener('click',e=>{if(e.target===o){o.id==='m-chat'?nxsCloseChat():closeModal(o.id);}}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.ov.open').forEach(o=>o.id==='m-chat'?nxsCloseChat():closeModal(o.id));}});

/* ── Tickets list: filters + search ── */
let F = P.get('f') || 'open';
function nxsSetFilter(f){F=f;history.replaceState(null,'','?tab=tickets&f='+f);nxsApply();}
function nxsApply(){
  const chips=document.querySelectorAll('#chips .chip'); if(!chips.length) return;
  chips.forEach(c=>c.classList.toggle('on',c.dataset.f===F));
  const q=(document.getElementById('tk-q').value||'').trim().toLowerCase(); let shown=0;
  document.querySelectorAll('#tk-list .tk').forEach(r=>{
    const d=r.dataset; let ok;
    switch(F){case 'open':ok=d.open==='1';break;case 'needs':ok=d.needs==='1';break;case 'mine':ok=d.mine==='1';break;
      case 'un':ok=d.un==='1';break;case 'urgent':ok=d.urgent==='1';break;case 'done':ok=d.open==='0';break;default:ok=true;}
    if(ok&&q) ok=d.q.includes(q);
    r.style.display=ok?'':'none'; if(ok) shown++;
  });
  document.getElementById('tk-empty').style.display=shown?'none':'block';
}
document.querySelectorAll('#chips .chip').forEach(c=>c.addEventListener('click',()=>nxsSetFilter(c.dataset.f)));
nxsApply();

/* ── Claim ── */
async function nxsClaim(id,fromChat){
  const fd=new FormData(); fd.append('_action','ticket_claim'); fd.append('csrf_token',CSRF); fd.append('ticket_id',id);
  try{
    const j=await (await fetch('',{method:'POST',body:fd})).json();
    if(!j.ok){alert(j.msg||'Could not claim this ticket.');if(!fromChat)location.reload();return;}
    if(fromChat){C.dirty=true;if(C.t)C.t.assigned_to=ME;document.getElementById('ct-claim').style.display='none';document.getElementById('ct-status').value='in_progress';}
    else location.reload();
  }catch(e){alert('Request failed. Reload and try again.');}
}

/* ── Ticket chat ── */
const C={id:0,since:0,timer:null,rec:null,chunks:[],recording:false,t0:0,tick:null,dirty:false,t:null};
function nxsOpen(id){
  const t=TICKETS[id]; if(!t) return;
  C.id=id;C.since=0;C.t=t;C.dirty=false;
  document.getElementById('ct-av').textContent=(t.business_name||'?').substring(0,2).toUpperCase();
  document.getElementById('ct-name').textContent=t.business_name;
  document.getElementById('ct-sub').textContent='#'+t.id+' — '+t.subject;
  document.getElementById('ct-status').value=t.status;
  document.getElementById('ct-priority').value=t.priority;
  document.getElementById('ct-claim').style.display=(!t.assigned_to&&(t.status==='open'||t.status==='in_progress'))?'inline-flex':'none';
  document.getElementById('ct-thread').innerHTML='';
  document.getElementById('ct-typing').style.display='none';
  document.getElementById('ct-canned').style.display='none';
  openModal('m-chat'); nxsPoll(true);
  clearInterval(C.timer); C.timer=setInterval(()=>nxsPoll(false),3000);
}
function nxsCloseChat(){clearInterval(C.timer);nxsStopRec();closeModal('m-chat');if(C.dirty)location.reload();}
function nxsInfo(){
  const t=C.t||{}; const set=(i,v)=>document.getElementById(i).textContent=v||'—';
  set('ci-name',t.business_name);set('ci-owner',t.owner_name);set('ci-phone',t.phone);set('ci-email',t.email);set('ci-addr',t.address);
  set('ci-status',({active:'Active',suspended:'Suspended — account on hold',read_only:'Read-only',cancelled:'Cancelled'})[t.client_status]||t.client_status);
  closeModal('m-chat'); openModal('m-info');
}
async function nxsPoll(scroll){
  if(!C.id) return;
  try{
    const j=await (await fetch('?action=ticket_poll&ticket_id='+C.id+'&since_id='+C.since)).json();
    if(!j.ok) return; const th=document.getElementById('ct-thread');
    j.replies.forEach(r=>{th.insertAdjacentHTML('beforeend',nxsBubble(r));C.since=Math.max(C.since,parseInt(r.id));});
    document.getElementById('ct-typing').style.display=j.other_typing?'flex':'none';
    if(j.replies.length||scroll) th.scrollTop=th.scrollHeight;
  }catch(e){}
}
function nxsBubble(r){
  const mine=r.sender_type==='admin', ai=r.sender_name==='NYMIX AI';
  const time=new Date(String(r.created_at).replace(' ','T')).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
  const body=(parseInt(r.is_voice)===1&&r.voice_url)?'<audio controls src="'+escH(r.voice_url)+'" style="max-width:220px;height:36px"></audio>':escH(r.message).replace(/\n/g,'<br>');
  return '<div class="wa-row '+(mine?'me':'them')+'"><div class="wa-bub '+(ai?'ai':(mine?'me':'them'))+'">'+(ai?'<div class="wa-name" style="color:#b3a8ff">🤖 NYMIX AI</div>':(mine?'':'<div class="wa-name">'+escH(r.sender_name||'Client')+'</div>'))+body+'<div class="wa-time">'+time+(mine?' <span style="color:#53bdeb">✓✓</span>':'')+'</div></div></div>';
}
function nxsMeta(){
  const fd=new FormData(); fd.append('_action','ticket_meta_update'); fd.append('csrf_token',CSRF); fd.append('ticket_id',C.id);
  fd.append('status',document.getElementById('ct-status').value); fd.append('priority',document.getElementById('ct-priority').value);
  C.dirty=true; fetch('',{method:'POST',body:fd});
}
let lastTyping=0;
function nxsTyping(){
  const n=Date.now(); if(n-lastTyping<2000) return; lastTyping=n;
  const fd=new FormData(); fd.append('_action','ticket_typing'); fd.append('csrf_token',CSRF); fd.append('ticket_id',C.id); fetch('',{method:'POST',body:fd});
}
async function nxsSend(){
  const el=document.getElementById('ct-input'); const msg=el.value.trim(); if(!msg||!C.id) return;
  const fd=new FormData(); fd.append('_action','ticket_reply_ajax'); fd.append('csrf_token',CSRF); fd.append('ticket_id',C.id); fd.append('message',msg);
  el.value=''; document.getElementById('ct-canned').style.display='none';
  const j=await (await fetch('',{method:'POST',body:fd})).json(); if(j.ok){C.dirty=true;nxsPoll(true);}
}
document.getElementById('ct-input').addEventListener('input',nxsTyping);
document.getElementById('ct-input').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();nxsSend();}});

/* canned replies */
(function(){const box=document.getElementById('ct-canned');CANNED.forEach(([l,t])=>{const d=document.createElement('div');d.innerHTML='<b>'+escH(l)+'</b><br><span class="mut">'+escH(t.length>70?t.slice(0,70)+'…':t)+'</span>';d.onclick=()=>{const i=document.getElementById('ct-input');i.value=t;i.focus();box.style.display='none';};box.appendChild(d);});})();
function nxsCanned(){const b=document.getElementById('ct-canned');b.style.display=b.style.display==='block'?'none':'block';}

/* AI suggest */
async function nxsAiSuggest(btn){
  if(!C.id) return; btn.disabled=true;
  const fd=new FormData(); fd.append('_action','ai_ticket_suggest'); fd.append('csrf_token',CSRF); fd.append('ticket_id',C.id);
  try{const j=await (await fetch('',{method:'POST',body:fd})).json(); if(j.ok){const i=document.getElementById('ct-input');i.value=j.text;i.focus();}else alert(j.msg||'AI unavailable.');}
  catch(e){alert('AI request failed.');} btn.disabled=false;
}

/* voice notes */
function nxsRecToggle(){C.recording?nxsStopRec():nxsStartRec();}
async function nxsStartRec(){
  try{
    const stream=await navigator.mediaDevices.getUserMedia({audio:true});
    C.rec=new MediaRecorder(stream);C.chunks=[];
    C.rec.ondataavailable=e=>C.chunks.push(e.data); C.rec.onstop=()=>nxsSendVoice(stream);
    C.rec.start();C.recording=true;C.t0=Date.now();
    document.getElementById('ct-mic').classList.add('rec');document.getElementById('ct-timer').style.display='inline';
    C.tick=setInterval(()=>{const s=Math.floor((Date.now()-C.t0)/1000);document.getElementById('ct-timer').textContent=String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0');},500);
  }catch(e){alert('Microphone access denied or unavailable.');}
}
function nxsStopRec(){
  if(C.rec&&C.recording){C.rec.stop();C.recording=false;clearInterval(C.tick);document.getElementById('ct-mic').classList.remove('rec');document.getElementById('ct-timer').style.display='none';}
}
async function nxsSendVoice(stream){
  const blob=new Blob(C.chunks,{type:'audio/webm'}); stream.getTracks().forEach(t=>t.stop()); if(blob.size<500) return;
  const fd=new FormData(); fd.append('_action','ticket_voice_reply'); fd.append('csrf_token',CSRF); fd.append('ticket_id',C.id); fd.append('voice',blob,'note.webm');
  const j=await (await fetch('',{method:'POST',body:fd})).json(); if(j.ok){C.dirty=true;nxsPoll(true);}
}

/* ── Client lookup ── */
let stm;
function nxsSearchInput(el){clearTimeout(stm);stm=setTimeout(()=>nxsSearch(el.value.trim()),250);}
function stBadge(s){const l=({active:'Active',suspended:'Suspended — account on hold',read_only:'Read-only',cancelled:'Cancelled'})[s]||s;return '<span class="bd bd-'+escH(s)+'">'+escH(l)+'</span>';}
function avatar(c){return c.logo?'<img class="logo" src="'+escH(c.logo)+'" alt="">':'<div class="av">'+escH((c.business_name||'?').substring(0,2).toUpperCase())+'</div>';}
async function nxsSearch(q){
  const box=document.getElementById('cl-results'); if(!box) return;
  if(q.length<2){box.innerHTML='<div class="empty">Type at least 2 characters to search.</div>';return;}
  try{
    const j=await (await fetch('?action=support_client_search&q='+encodeURIComponent(q))).json();
    if(!j.clients.length){box.innerHTML='<div class="empty">No clients found.</div>';return;}
    box.innerHTML=j.clients.map(c=>'<div class="cr" data-id="'+(+c.id)+'" onclick="nxsLoadClient('+(+c.id)+')">'+avatar(c)+'<div style="min-width:0;flex:1"><div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+escH(c.business_name)+'</div><div class="mut">'+escH(c.owner_name||'')+(c.phone?' · '+escH(c.phone):'')+'</div></div>'+stBadge(c.status)+'</div>').join('');
  }catch(e){box.innerHTML='<div class="empty">Search failed. Reload and try again.</div>';}
}
async function nxsLoadClient(id){
  const host=document.getElementById('cl-card'); if(!host) return;
  document.querySelectorAll('#cl-results .cr').forEach(r=>r.classList.toggle('on',+r.dataset.id===+id));
  host.innerHTML='<div class="card"><div class="empty">Loading…</div></div>';
  try{
    const j=await (await fetch('?action=support_client_card&id='+(+id))).json();
    if(!j.ok){host.innerHTML='<div class="card"><div class="empty">Client not found.</div></div>';return;}
    const c=j.client;
    const hist=j.tickets.length?j.tickets.map(t=>'<a class="hist" href="?tab=tickets&open='+(+t.id)+'"><span class="pd pd-'+escH(t.priority)+'"></span><span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">#'+(+t.id)+' · '+escH(t.subject)+'</span><span class="bd bd-'+escH(t.status)+'">'+escH(String(t.status).replace('_',' '))+'</span></a>').join(''):'<div class="mut">No tickets yet.</div>';
    host.innerHTML='<div class="card"><div class="card-b"><div style="display:flex;align-items:center;gap:13px;margin-bottom:14px">'+avatar(c)+'<div style="min-width:0"><div style="font:800 18px Syne,sans-serif">'+escH(c.business_name)+'</div>'+stBadge(c.status)+'</div></div>'
      +'<div class="kv"><span>Owner</span><span>'+escH(c.owner_name||'—')+'</span></div>'
      +'<div class="kv"><span>Phone</span><span>'+(c.phone?'<a href="tel:'+escH(c.phone)+'">'+escH(c.phone)+'</a>':'—')+'</span></div>'
      +'<div class="kv"><span>Email</span><span>'+(c.email?'<a href="mailto:'+escH(c.email)+'">'+escH(c.email)+'</a>':'—')+'</span></div>'
      +'<div class="kv"><span>Location</span><span>'+escH(c.address||'—')+'</span></div>'
      +'<div class="kv"><span>Plan</span><span>'+escH(c.plan_name||'No plan')+'</span></div>'
      +'<button class="btn gold" style="margin-top:14px" onclick=\'nxsNewTicket('+JSON.stringify({id:+c.id,name:c.business_name}).replace(/'/g,"&#39;")+')\'>+ New ticket for this client</button></div></div>'
      +'<div class="card"><div class="card-h">Ticket history</div><div class="card-b" style="padding-top:6px;padding-bottom:6px">'+hist+'</div></div>';
  }catch(e){host.innerHTML='<div class="card"><div class="empty">Could not load client.</div></div>';}
}

/* ── New ticket modal ── */
let ntm;
function nxsPickClient(id,name){
  document.getElementById('nt-client').value=id;
  const ch=document.getElementById('nt-chosen'); ch.textContent='✓ '+name; ch.style.display='block';
  document.getElementById('nt-res').style.display='none'; document.getElementById('nt-q').value='';
  document.getElementById('nt-go').disabled=false;
}
function nxsNewTicket(c){
  document.getElementById('nt-client').value=''; document.getElementById('nt-chosen').style.display='none';
  document.getElementById('nt-res').style.display='none'; document.getElementById('nt-q').value='';
  document.getElementById('nt-subject').value=''; document.getElementById('nt-go').disabled=true;
  if(c&&c.id) nxsPickClient(c.id,c.name);
  openModal('m-new');
}
function nxsNtSearch(q){
  clearTimeout(ntm); const box=document.getElementById('nt-res');
  q=q.trim(); if(q.length<2){box.style.display='none';return;}
  ntm=setTimeout(async()=>{
    try{
      const j=await (await fetch('?action=support_client_search&q='+encodeURIComponent(q))).json();
      box.style.display='block';
      box.innerHTML=j.clients.length?'':'<div class="mut pad">No clients found.</div>';
      j.clients.forEach(c=>{const d=document.createElement('div');d.className='cr';d.innerHTML='<div style="min-width:0;flex:1"><div style="font-weight:600">'+escH(c.business_name)+'</div><div class="mut">'+escH(c.owner_name||'')+(c.phone?' · '+escH(c.phone):'')+'</div></div>';d.onclick=()=>nxsPickClient(c.id,c.business_name);box.appendChild(d);});
    }catch(e){}
  },250);
}

/* ── Deep links: ?open=ID (ticket) · ?client=ID · ?q=text ── */
if(P.get('open')){const id=parseInt(P.get('open'));history.replaceState(null,'','?tab=tickets&f='+F);if(TICKETS[id])nxsOpen(id);}
if(document.getElementById('cl-q')){
  if(P.get('q')){document.getElementById('cl-q').value=P.get('q');nxsSearch(P.get('q').trim());}
  if(P.get('client')) nxsLoadClient(parseInt(P.get('client')));
}
</script>
</body></html>
<?php
}
