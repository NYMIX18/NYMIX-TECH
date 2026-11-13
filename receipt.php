<?php
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/paystack_helper.php';

$u = $_SESSION['nx_portal_user'] ?? null;
if (!$u || ($u['role'] ?? '') !== 'owner') { header('Location: ' . NX_PORTAL_URL . '?page=login'); exit; }

$db  = db();
$ref = trim($_GET['ref'] ?? '');
$r   = $ref !== '' ? ps_receipt_row($db, 'reference', $ref) : null;
$ok  = $r && (int)$r['client_id'] === (int)$u['client_id'];
$msg = '';

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
    // AJAX call from the thank-you screen: email the receipt and answer with JSON
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        $key = (string)$r['receipt_no'];
        // don't spam: once per session per receipt, unless the user hits "Try again"
        if (empty($_POST['force']) && !empty($_SESSION['rcpt_mailed'][$key])) {
            echo json_encode(['ok' => true, 'already' => true]); exit;
        }
        $sent = (bool)ps_send_receipt_email($r);
        if ($sent) { $_SESSION['rcpt_mailed'][$key] = time(); }
        echo json_encode(['ok' => $sent]); exit;
    }
    $msg = ps_send_receipt_email($r) ? 'A copy was emailed to ' . $r['client_email'] : 'Could not send the email right now.';
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$money = fn($n) => 'KES ' . number_format((float)$n, 2);
if ($ok) {
    $verify = ps_verify_url($r['receipt_no']);
    $qr     = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=6&data=' . urlencode($verify);
    $sub    = $r['inv_amount'] !== null ? (float)$r['inv_amount'] : null;
    $tax    = $r['inv_tax']    !== null ? (float)$r['inv_tax']    : null;
    $isNew  = !empty($_GET['new']);                         // full show right after paying, quicker cut later
    $skipIntro = $_SERVER['REQUEST_METHOD'] === 'POST';     // the "Email me a copy" button reloads straight to the card
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $ok ? 'Receipt ' . $h($r['receipt_no']) : 'Receipt not found' ?> | NYMIX TECH</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<?php if ($ok && !$skipIntro): ?>
<script>document.documentElement.classList.add('intro')</script>
<?php endif; ?>
<style>
:root{--bg:#0f1117;--surface:#181c27;--card:#1e2333;--border:#2a2f42;--accent:#f59e0b;--accent2:#fb923c;--green:#22c55e;--text:#e8eaf0;--muted:#6b7280;--fh:'Space Grotesk',sans-serif;--fb:'DM Sans',sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--fb);min-height:100vh;padding:28px 14px 60px;background-image:radial-gradient(ellipse at 20% 0%,rgba(245,158,11,.12),transparent 55%),radial-gradient(ellipse at 90% 100%,rgba(251,146,60,.08),transparent 55%)}
.wrap{max-width:430px;margin:0 auto}
.rc{position:relative;background:var(--surface);border:1px solid var(--border);border-radius:26px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.5);animation:up .6s cubic-bezier(.2,1,.3,1)}
@keyframes up{from{opacity:0;transform:translateY(24px) scale(.97)}to{opacity:1;transform:none}}
.rc-head{background:linear-gradient(135deg,#f59e0b,#fb923c);color:#000;padding:22px 24px;display:flex;align-items:center;gap:12px}
.rc-head img{width:44px;height:44px;border-radius:12px;background:#fff;object-fit:contain;padding:3px}
.rc-head .fb{width:44px;height:44px;border-radius:12px;background:#000;color:#f59e0b;display:none;align-items:center;justify-content:center;font-weight:800;font-family:var(--fh)}
.rc-head b{font-family:var(--fh);font-size:1.1rem;letter-spacing:1px;display:block}
.rc-head small{font-size:.65rem;letter-spacing:2px;font-weight:600;opacity:.75}
.stamp{margin-left:auto;border:2px solid #000;border-radius:8px;padding:3px 10px;font-weight:800;font-size:.72rem;letter-spacing:2px;transform:rotate(-6deg)}
.tick{width:84px;height:84px;margin:26px auto 6px;display:block}
.tick circle{fill:rgba(34,197,94,.12);stroke:var(--green);stroke-width:2.5;stroke-dasharray:151;stroke-dashoffset:151;animation:dr .8s .1s forwards}
.tick path{fill:none;stroke:var(--green);stroke-width:3.5;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:40;stroke-dashoffset:40;animation:dr .5s .8s forwards}
@keyframes dr{to{stroke-dashoffset:0}}
.paid{text-align:center;padding:0 24px 18px}
.paid small{color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;font-size:.68rem;font-weight:600}
.paid .amt{font-family:var(--fh);font-weight:700;font-size:2.3rem;background:linear-gradient(135deg,#f59e0b,#fb923c);-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1.15;margin-top:2px}
.paid .ok{color:var(--green);font-weight:600;font-size:.85rem;margin-top:4px}
.rows{padding:4px 24px 20px}
.row{display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px dashed var(--border);font-size:.85rem}
.row span{color:var(--muted)}.row b{text-align:right;font-weight:600;word-break:break-all}
.row.tot{border-bottom:none;border-top:2px solid var(--text);margin-top:6px;padding-top:14px;font-size:1rem}.row.tot b{color:var(--accent);font-family:var(--fh)}
.tear{position:relative;border-top:2px dashed var(--border);margin:0 14px}
.tear::before,.tear::after{content:'';position:absolute;top:-13px;width:24px;height:24px;border-radius:50%;background:var(--bg);border:1px solid var(--border)}
.tear::before{left:-26px}.tear::after{right:-26px}
.qr{text-align:center;padding:22px 24px 8px}
.qr .box{display:inline-block;background:#fff;padding:10px;border-radius:18px;box-shadow:0 8px 24px rgba(0,0,0,.35)}
.qr img{width:170px;height:170px;display:block}
.qr p{font-size:.72rem;color:var(--muted);margin-top:12px;line-height:1.5}
.qr code{display:block;font-family:'Courier New',monospace;color:var(--text);letter-spacing:3px;font-size:.82rem;margin-top:6px}
.foot{text-align:center;padding:10px 24px 22px;font-size:.66rem;color:var(--muted)}
.micro{font-size:6px;letter-spacing:1px;color:rgba(255,255,255,.18);white-space:nowrap;overflow:hidden;padding:0 10px 6px;text-align:center}
.acts{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}
.acts a,.acts button{display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;border-radius:12px;font-weight:600;font-size:.85rem;cursor:pointer;text-decoration:none;font-family:var(--fb);border:1px solid var(--border);background:var(--card);color:var(--text)}
.acts .p{background:var(--accent);color:#000;border-color:var(--accent)}
.acts form{display:contents}
.note{margin-top:12px;text-align:center;font-size:.8rem;color:var(--green)}
.nf{text-align:center;padding:60px 20px}.nf h2{font-family:var(--fh);margin:10px 0}
.cf{position:fixed;inset:0;pointer-events:none;overflow:hidden;z-index:150}
.cf i{position:absolute;top:-20px;width:9px;height:13px;border-radius:2px;animation:fall linear forwards}
@keyframes fall{to{transform:translateY(110vh) rotate(720deg)}}

/* ====== intro: the receipt card stays hidden until "View receipt" ====== */
.intro .wrap{display:none}
.intro,.intro body{overflow:hidden;height:100%}

/* ====== stage + scene ====== */
.stage{--k:1;--s:1;position:fixed;inset:0;z-index:100;overflow:hidden;transition:opacity .5s;
  background:radial-gradient(ellipse at 50% 80%,rgba(245,158,11,.22),transparent 62%),var(--bg)}
.stage[hidden]{display:none}
.stage.gone{opacity:0;pointer-events:none}
.skip{position:absolute;top:16px;right:16px;z-index:5;background:rgba(255,255,255,.06);border:1px solid var(--border);color:var(--text);padding:8px 14px;border-radius:999px;font:600 .75rem var(--fb);cursor:pointer}
.skip:hover{background:rgba(255,255,255,.12)}
.skip:focus-visible,.lnk:focus-visible,.btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.scene{position:absolute;left:50%;bottom:2vh;width:340px;height:560px;margin-left:-170px;transform-origin:50% 100%;transform:scale(var(--s))}
.desk{position:absolute;left:-60px;right:-60px;bottom:0;height:26px;border-radius:50% 50% 0 0/100% 100% 0 0;background:linear-gradient(#232840,#171a28)}

/* ---- the machine ---- */
.mach{position:absolute;left:0;bottom:24px;width:340px;height:220px;transform:translateY(150%);transition:transform calc(.9s*var(--k)) cubic-bezier(.2,1.1,.3,1)}
.stage.in .mach{transform:none}
.stage.exit .mach{transform:translateY(160%);transition:transform .6s cubic-bezier(.6,0,.9,.4)}
.stage.tear .mach{animation:thud .35s}
@keyframes thud{35%{transform:translateY(4px)}}
.shadow{position:absolute;left:10px;right:10px;bottom:-8px;height:18px;border-radius:50%;background:rgba(0,0,0,.55);filter:blur(8px)}
.feet{position:absolute;bottom:-2px;left:44px;width:48px;height:14px;border-radius:0 0 8px 8px;background:#0b0d16}
.feet.r{left:auto;right:44px}
.top{position:absolute;z-index:1;left:22px;right:22px;top:0;height:28px;clip-path:polygon(5% 0,95% 0,100% 100%,0 100%);background:linear-gradient(#4a5274,#343b57)}
.slit{position:absolute;z-index:1;left:64px;width:212px;top:8px;height:10px;border-radius:6px;background:#04050a;box-shadow:inset 0 3px 5px #000}
.front{position:absolute;z-index:3;left:0;right:0;top:20px;height:180px;border-radius:18px 18px 26px 26px;border:1px solid #444c6c;
  background:linear-gradient(180deg,#323953 0%,#232839 40%,#171a29 100%);box-shadow:inset 0 1px 0 rgba(255,255,255,.14),0 24px 40px rgba(0,0,0,.5)}
.teeth{position:absolute;left:64px;width:212px;top:-1px;height:9px;
  background:linear-gradient(135deg,#aab2ca 50%,transparent 50%) 0 0/10px 9px,linear-gradient(225deg,#aab2ca 50%,transparent 50%) 0 0/10px 9px}
.plate{position:absolute;left:22px;top:20px;display:flex;align-items:center;gap:9px}
.plate em{font:800 .72rem var(--fh);font-style:normal;width:26px;height:26px;border-radius:7px;background:linear-gradient(135deg,#f59e0b,#fb923c);color:#000;display:flex;align-items:center;justify-content:center}
.plate b{display:block;font:700 1.02rem var(--fh);letter-spacing:1.5px;color:#fbbf24;line-height:1}
.plate small{display:block;font:500 .56rem var(--fb);color:#7d86a8;margin-top:3px}
.lever{position:absolute;right:20px;top:20px;padding:4px 12px;border-radius:999px;background:#1a1e2e;border:1px solid #444c6c;font:600 .58rem var(--fb);color:#9aa3c4}
.scr{position:absolute;left:22px;top:62px;width:186px;height:56px;border-radius:10px;background:#09140e;border:2px solid #0b0d16;box-shadow:inset 0 0 16px rgba(34,197,94,.22),0 1px 0 rgba(255,255,255,.1);
  padding:8px 11px;font:600 .72rem 'Courier New',monospace;color:#4ade80;text-shadow:0 0 8px rgba(74,222,128,.6)}
.scr div:first-child{color:#bbf7d0;letter-spacing:1px}
#stat::after{content:'';display:inline-block;width:6px;height:11px;margin-left:3px;vertical-align:-1px;background:#4ade80;animation:blink .8s steps(1) infinite}
@keyframes blink{50%{opacity:0}}
.btns{position:absolute;right:22px;top:64px;display:flex;gap:16px}
.btns div{text-align:center;font:500 .56rem var(--fb);color:#7d86a8}
.btns i{display:block;width:34px;height:34px;margin:0 auto 4px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#59628a,#262b43);border:2px solid #0b0d16;box-shadow:0 3px 0 #0b0d16}
.btns .pw2 i{background:radial-gradient(circle at 35% 30%,#fdba74,#c2410c)}
.stage.feed .bf{animation:press .35s}
@keyframes press{50%{transform:translateY(3px);box-shadow:0 0 0 #0b0d16}}
.leds{position:absolute;left:22px;bottom:20px;display:flex;gap:16px}
.leds div{text-align:center;font:500 .54rem var(--fb);color:#7d86a8}
.leds i{display:block;width:9px;height:9px;margin:0 auto 4px;border-radius:50%;background:#2c3250;transition:background .2s,box-shadow .2s}
.stage.s1 .l1{background:#22c55e;box-shadow:0 0 10px #22c55e}
.stage.s2 .l2{background:#f59e0b;box-shadow:0 0 10px #f59e0b}
.stage.feed .l3{background:#3b82f6;box-shadow:0 0 10px #3b82f6;animation:flick .2s steps(1) infinite}
@keyframes flick{50%{opacity:.3}}
.vents{position:absolute;right:22px;bottom:20px;display:flex;gap:6px}
.vents i{width:5px;height:26px;border-radius:3px;background:#080a12;box-shadow:0 1px 0 rgba(255,255,255,.08)}
.cable{position:absolute;z-index:0;right:-22px;top:78px;width:50px;height:150px}

/* ---- paper + hand ---- */
.pw{position:absolute;z-index:2;left:70px;width:200px;height:310px;bottom:calc(100% - 13px);overflow:hidden}
.stage.free .pw{overflow:visible}
.grp{position:absolute;inset:0}
.stage.tear .grp{animation:jolt .5s}
@keyframes jolt{30%{transform:translateY(-12px) rotate(1.6deg)}55%{transform:translateY(3px) rotate(-.6deg)}100%{transform:none}}
.stage.lift .grp{transform:translate(60px,-560px) rotate(14deg);transition:transform calc(1.4s*var(--k)) cubic-bezier(.55,0,.85,.45)}
.paper{position:absolute;left:0;right:0;bottom:0;height:300px;background:#fbf7ec;color:#1b1b1b;padding:22px 18px;font:600 .7rem 'Courier New',monospace;transform:translateY(102%);box-shadow:0 0 0 1px rgba(0,0,0,.05)}
.stage.feed .paper{transform:none;transition:transform calc(4.2s*var(--k)) steps(42,end)}
.paper::before{content:'';position:absolute;left:0;right:0;top:-6px;height:6px;
  background:linear-gradient(135deg,#fbf7ec 50%,transparent 50%) 0 0/12px 6px,linear-gradient(225deg,#fbf7ec 50%,transparent 50%) 0 0/12px 6px}
.paper h4{font:700 .95rem var(--fh);letter-spacing:2px;text-align:center}
.paper h5{font:500 .6rem 'Courier New',monospace;text-align:center;letter-spacing:2px;margin:2px 0 12px;color:#666}
.paper .pl{display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px dashed #c9c2ad}
.paper .pl b{font-weight:700;text-align:right;max-width:62%;overflow-wrap:anywhere}
.paper .big{font:700 1.5rem var(--fh);text-align:center;margin:14px 0 4px;color:#111}
.paper .bars{margin:10px 0 8px;height:22px;background:repeating-linear-gradient(90deg,#222 0 2px,transparent 2px 4px,#222 4px 5px,transparent 5px 8px,#222 8px 11px,transparent 11px 13px)}
.paper .ty{text-align:center;font-size:.66rem;color:#555}
.paper .pd{position:absolute;right:10px;bottom:58px;border:3px solid #16a34a;color:#16a34a;border-radius:8px;padding:2px 12px;font:800 1.5rem var(--fh);letter-spacing:3px;opacity:0;transform:rotate(-12deg) scale(3)}
.stage.stamp .pd{opacity:.92;transform:rotate(-12deg) scale(1);transition:transform .22s cubic-bezier(.3,1.6,.5,1),opacity .1s}
.hand{position:absolute;left:99px;top:50px;width:190px;height:104px;overflow:visible;opacity:0;transform:translate(170px,-24px) rotate(10deg);
  filter:drop-shadow(-4px 7px 6px rgba(0,0,0,.4))}
.stage.hand .hand{opacity:1;transform:none;transition:transform calc(1.1s*var(--k)) cubic-bezier(.2,.8,.2,1),opacity .3s}
.sq{transform-box:fill-box;transform-origin:right center}
.stage.grip .sq{animation:sq .45s ease}
@keyframes sq{50%{transform:scale(.95,.9)}}

/* ====== thank-you screen ====== */
.thx{position:fixed;inset:0;z-index:110;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;text-align:center;overflow:auto;
  background:radial-gradient(ellipse at 50% 28%,rgba(245,158,11,.22),transparent 62%),var(--bg);opacity:0;transition:opacity .5s}
.thx[hidden]{display:none}
.thx.show{opacity:1}
.face{width:150px;height:150px;transform:scale(0);filter:drop-shadow(0 16px 30px rgba(245,158,11,.35))}
.thx.show .face{animation:pop .9s .1s cubic-bezier(.2,1.5,.4,1) forwards,bob 3.2s 1.1s ease-in-out infinite}
@keyframes pop{to{transform:scale(1)}}
@keyframes bob{50%{transform:translateY(-8px) rotate(-2deg)}}
.smile{stroke-dasharray:1;stroke-dashoffset:1}
.thx.show .smile{animation:dr .7s .8s forwards}
.eyes{transform-box:fill-box;transform-origin:center;animation:blinkE 3.8s 2s infinite}
@keyframes blinkE{0%,93%,100%{transform:scaleY(1)}96%{transform:scaleY(.1)}}
.fu{animation:fu .6s both}
@keyframes fu{from{opacity:0;transform:translateY(14px)}}
.thx h1{font:700 clamp(1.5rem,6vw,2.1rem) var(--fh);margin-top:22px}
.thx p{max-width:380px;margin-top:10px;font-size:.95rem;line-height:1.55;color:#c3c8d8}
.thx p b{color:var(--text);word-break:break-all}
.thx .fine{font-size:.82rem;color:var(--muted)}
.mail{display:inline-flex;align-items:center;gap:9px;margin-top:20px;padding:10px 18px;border-radius:999px;background:var(--card);border:1px solid var(--border);font-size:.82rem;font-weight:600}
.mail.ok{color:var(--green);border-color:rgba(34,197,94,.4)}
.mail.bad{color:#f87171;border-color:rgba(248,113,113,.4)}
.sp{width:13px;height:13px;border-radius:50%;border:2px solid var(--border);border-top-color:var(--accent);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.mail button{margin-left:6px;background:none;border:none;color:var(--accent);font:600 .8rem var(--fb);text-decoration:underline;cursor:pointer}
.btn{display:inline-flex;margin-top:22px;padding:13px 26px;border-radius:12px;background:var(--accent);color:#000;font-weight:700;font-size:.9rem;text-decoration:none}
.lnks{margin-top:14px;display:flex;gap:18px;justify-content:center}
.lnk{background:none;border:none;color:var(--muted);font:500 .78rem var(--fb);text-decoration:underline;cursor:pointer}
.lnk:hover{color:var(--text)}

@media (prefers-reduced-motion:reduce){
  .stage{display:none}
  .rc,.tick circle,.tick path{animation:none!important}.tick circle,.tick path{stroke-dashoffset:0}
  .thx *{animation:none!important}.face{transform:none}.smile{stroke-dashoffset:0}
}
@media print{
  body{background:#fff;color:#111;padding:0}.acts,.note,.cf,.stage,.thx{display:none!important}
  .rc{box-shadow:none;border:1px solid #ccc;background:#fff;animation:none}
  .row,.tear{border-color:#bbb}.row span,.paid small,.foot,.qr p{color:#555}.row b,.qr code{color:#111}
  .tear::before,.tear::after{background:#fff;border-color:#ccc}
  *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style></head><body>

<?php if ($ok && !$skipIntro): ?>
<!-- ================= THE MACHINE ================= -->
<div class="stage" id="stage" aria-hidden="true">
  <button class="skip" id="skip" type="button">Skip</button>
  <div class="scene">
    <div class="desk"></div>
    <div class="mach">
      <div class="shadow"></div><div class="feet"></div><div class="feet r"></div>
      <svg class="cable" viewBox="0 0 50 150"><path d="M0 6 C40 6 46 40 40 80 S30 140 8 148" fill="none" stroke="#0b0d16" stroke-width="7" stroke-linecap="round"/></svg>
      <div class="top"></div><div class="slit"></div>

      <div class="pw"><div class="grp">
        <div class="paper">
          <h4>NYMIX TECH</h4><h5>PAYMENT RECEIPT</h5>
          <div class="pl"><span>Receipt</span><b><?= $h($r['receipt_no']) ?></b></div>
          <div class="pl"><span>Billed to</span><b><?= $h($r['business_name']) ?></b></div>
          <div class="pl"><span>Method</span><b><?= $h(ps_channel_label($r['channel'])) ?></b></div>
          <div class="big"><?= $h($money($r['amount'])) ?></div>
          <div class="bars"></div>
          <div class="ty">Thank you for your payment</div>
          <div class="pd">PAID</div>
        </div>
        <!-- the hand: four fingers wrap the front, thumb on top -->
        <svg class="hand" viewBox="0 0 220 120">
          <g class="sq" stroke="#6b3f17" stroke-width="2.5" stroke-linejoin="round">
            <rect x="142" y="10" width="86" height="100" rx="10" fill="#f59e0b" stroke="#b45309"/>
            <rect x="138" y="8" width="14" height="104" rx="6" fill="#d97706" stroke="#92400e"/>
            <rect x="110" y="32" width="40" height="56" rx="14" fill="#c68642"/>
            <rect x="64" y="20" width="82" height="80" rx="28" fill="#c68642"/>
            <rect x="16" y="20" width="96" height="19" rx="9.5" fill="#d29655"/>
            <rect x="8" y="39" width="104" height="19" rx="9.5" fill="#cf9150"/>
            <rect x="14" y="58" width="98" height="19" rx="9.5" fill="#d29655"/>
            <rect x="30" y="77" width="82" height="19" rx="9.5" fill="#cf9150"/>
            <rect x="50" y="6" width="74" height="21" rx="10.5" fill="#d9a066" transform="rotate(-8 87 16)"/>
            <path d="M62 30v7M60 49v7M64 68v7M74 87v7" stroke="#8d5524" stroke-width="2" stroke-linecap="round" fill="none"/>
          </g>
        </svg>
      </div></div>

      <div class="front">
        <div class="teeth"></div>
        <div class="plate"><em>NX</em><div><b>NYMIX TECH</b><small>Thermal receipt printer</small></div></div>
        <div class="lever">Open</div>
        <div class="scr"><div>NYMIX TECH</div><div id="stat">Starting</div></div>
        <div class="btns"><div class="bf"><i></i>Feed</div><div class="pw2"><i></i>Power</div></div>
        <div class="leds"><div><i class="l1"></i>Power</div><div><i class="l2"></i>Online</div><div><i class="l3"></i>Paper</div></div>
        <div class="vents"><i></i><i></i><i></i><i></i><i></i><i></i></div>
      </div>
    </div>
  </div>
</div>

<!-- ================= THANK-YOU SCREEN ================= -->
<div class="thx" id="thx" hidden>
  <svg class="face" viewBox="0 0 120 120" aria-hidden="true">
    <defs><radialGradient id="fg" cx=".35" cy=".28"><stop offset="0" stop-color="#ffd166"/><stop offset="1" stop-color="#f59e0b"/></radialGradient></defs>
    <circle cx="60" cy="60" r="54" fill="url(#fg)"/>
    <ellipse cx="29" cy="70" rx="9" ry="6" fill="#ef4444" opacity=".35"/><ellipse cx="91" cy="70" rx="9" ry="6" fill="#ef4444" opacity=".35"/>
    <g class="eyes"><ellipse cx="42" cy="48" rx="6" ry="8.5" fill="#3b2300"/><ellipse cx="78" cy="48" rx="6" ry="8.5" fill="#3b2300"/>
      <circle cx="44" cy="44.5" r="2.2" fill="#fff"/><circle cx="80" cy="44.5" r="2.2" fill="#fff"/></g>
    <path class="smile" pathLength="1" d="M32 70 Q60 102 88 70" fill="none" stroke="#3b2300" stroke-width="6" stroke-linecap="round"/>
  </svg>
  <h1 class="fu" style="animation-delay:.9s">Thanks for your payment!</h1>
  <p class="fu" style="animation-delay:1.1s">We're mailing your receipt to <b><?= $h($r['client_email']) ?></b> right now.</p>
  <p class="fu fine" style="animation-delay:1.3s">Thanks for trusting our services. <?= $h($money($r['amount'])) ?> received · <?= $h($r['receipt_no']) ?></p>
  <div class="mail fu" id="mail" style="animation-delay:1.5s"><span class="sp"></span><span id="mailTxt">Sending your receipt…</span></div>
  <a class="btn fu" style="animation-delay:1.7s" href="<?= $h(NX_PORTAL_URL) ?>?page=dashboard">Back to portal</a>
  <div class="lnks fu" style="animation-delay:1.8s">
    <button class="lnk" id="view" type="button">View receipt</button>
    <button class="lnk" id="replay" type="button">↻ Watch again</button>
  </div>
</div>
<?php endif; ?>

<div class="wrap">
<?php if (!$ok): ?>
  <div class="rc nf"><div style="font-size:3rem">🧾</div><h2>Receipt not found</h2>
    <p style="color:var(--muted)">This receipt doesn't exist or belongs to another account.</p>
    <div class="acts"><a class="p" href="<?= $h(NX_PORTAL_URL) ?>?page=payments">Back to payments</a></div></div>
<?php else: ?>
  <div class="rc">
    <div class="rc-head">
      <img src="logo.png" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><div class="fb">NX</div>
      <div><b>NYMIX TECH</b><small>PAYMENT RECEIPT</small></div><span class="stamp">PAID</span>
    </div>
    <svg class="tick" viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M14 27l8 8 16-17"/></svg>
    <div class="paid"><small>Amount paid</small><div class="amt"><?= $h($money($r['amount'])) ?></div><div class="ok">Payment successful</div></div>
    <div class="rows">
      <div class="row"><span>Receipt no.</span><b><?= $h($r['receipt_no']) ?></b></div>
      <div class="row"><span>Date &amp; time</span><b><?= $h(date('d M Y, H:i', strtotime((string)($r['paid_at'] ?: ($r['confirmed_at'] ?: ($r['payment_date'] ?: 'now')))))) ?></b></div>
      <div class="row"><span>Billed to</span><b><?= $h($r['business_name']) ?></b></div>
      <?php if (!empty($r['kra_pin'])): ?><div class="row"><span>KRA PIN</span><b><?= $h($r['kra_pin']) ?></b></div><?php endif; ?>
      <div class="row"><span>Invoice</span><b><?= $h($r['invoice_no'] ?: '—') ?></b></div>
      <?php if ($r['period_start']): ?><div class="row"><span>Period</span><b><?= $h($r['period_start']) ?> → <?= $h($r['period_end']) ?></b></div><?php endif; ?>
      <div class="row"><span>Paid with</span><b><?= $h(ps_channel_label($r['channel'])) ?> · Paystack</b></div>
      <div class="row"><span>Reference</span><b><?= $h($r['reference']) ?></b></div>
      <?php if ($sub !== null && $tax !== null && $tax > 0): ?>
        <div class="row"><span>Subtotal</span><b><?= $h($money($sub)) ?></b></div>
        <div class="row"><span>VAT</span><b><?= $h($money($tax)) ?></b></div>
      <?php endif; ?>
      <div class="row tot"><span style="color:inherit">Total paid</span><b><?= $h($money($r['amount'])) ?></b></div>
    </div>
    <div class="tear"></div>
    <div class="qr">
      <div class="box"><img src="<?= $h($qr) ?>" alt="Scan to verify"></div>
      <p>Scan to verify this receipt is genuine</p>
      <code><?= $h(ps_sig($r['receipt_no'])) ?></code>
    </div>
    <div class="foot">Thank you for choosing NYMIX TECH · Built for Kenyan businesses 🇰🇪<br>Computer generated, no signature required</div>
    <div class="micro"><?= str_repeat($h($r['receipt_no']) . ' · NYMIX TECH · ', 12) ?></div>
  </div>

  <div class="acts">
    <button class="p" onclick="window.print()">🖨 Print / Save PDF</button>
    <a href="<?= $h(NX_PORTAL_URL) ?>?page=dashboard">← Back to portal</a>
    <form method="POST"><input type="hidden" name="_csrf" value="<?= $h($_SESSION['csrf'] ?? '') ?>">
      <button type="submit" style="grid-column:1/-1">✉ Email me a copy</button></form>
  </div>
  <?php if ($msg): ?><div class="note"><?= $h($msg) ?></div><?php endif; ?>
<?php endif; ?>
</div>

<?php if ($ok && !$skipIntro): ?>
<script>
(function(){
  var root  = document.documentElement;
  var stage = document.getElementById('stage');
  var stat  = document.getElementById('stat');
  var thx   = document.getElementById('thx');
  var mail  = document.getElementById('mail');
  var mailTxt = document.getElementById('mailTxt');
  var isNew = <?= $isNew ? 'true' : 'false' ?>;
  var csrf  = <?= json_encode((string)($_SESSION['csrf'] ?? '')) ?>;
  var email = <?= json_encode((string)$r['client_email']) ?>;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var timers = [], finished = false, mailStarted = false;

  function at(ms, fn){ timers.push(setTimeout(fn, ms)); }
  function say(t){ stat.textContent = t; }
  function fit(){ stage.style.setProperty('--s', Math.min(1.45, innerWidth/360, innerHeight/590)); }
  addEventListener('resize', fit);

  /* ---- email the receipt (server de-dupes per session) ---- */
  function setMail(state){
    mail.className = 'mail ' + (state === 'ok' ? 'ok' : state === 'bad' ? 'bad' : '');
    if (state === 'ok'){ mail.innerHTML = '✓ <span>Receipt sent to your inbox</span>'; }
    else if (state === 'bad'){ mail.innerHTML = '<span>Couldn\'t send the email just now.</span><button type="button" id="retry">Try again</button>';
      document.getElementById('retry').addEventListener('click', function(){ sendMail(true); }); }
    else { mail.innerHTML = '<span class="sp"></span><span>Sending your receipt…</span>'; }
  }
  function sendMail(force){
    mailStarted = true; setMail('wait');
    fetch(location.pathname + location.search, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'ajax=1&_csrf=' + encodeURIComponent(csrf) + (force ? '&force=1' : '')
    }).then(function(r){ return r.json(); })
      .then(function(j){ setMail(j && j.ok ? 'ok' : 'bad'); })
      .catch(function(){ setMail('bad'); });
  }

  function confetti(){
    var c = document.createElement('div'); c.className = 'cf';
    var k = ['#f59e0b','#22c55e','#3b82f6','#ef4444','#a78bfa','#fb923c'];
    for (var i = 0; i < 70; i++){
      var e = document.createElement('i');
      e.style.cssText = 'left:'+Math.random()*100+'%;background:'+k[i%6]+';animation-duration:'+(2+Math.random()*2.5)+'s;animation-delay:'+Math.random()*1.2+'s';
      c.appendChild(e);
    }
    document.body.appendChild(c);
    setTimeout(function(){ c.remove(); }, 6500);
  }

  /* ---- hand over to the smiley ---- */
  function showThanks(){
    if (finished) return; finished = true;
    timers.forEach(clearTimeout); timers = [];
    stage.classList.add('gone');
    setTimeout(function(){ stage.hidden = true; }, 550);
    if (!mailStarted) sendMail(false);
    thx.hidden = false; void thx.offsetWidth; thx.classList.add('show');
    if (isNew && !reduce) confetti();
  }

  function play(){
    finished = false;
    var k = isNew ? 1 : 0.55;                 // full show after paying, quicker cut later
    var T = function(ms){ return ms * k; };
    fit(); stage.style.setProperty('--k', k);
    stage.className = 'stage'; stage.hidden = false;
    thx.classList.remove('show'); thx.hidden = true;
    root.classList.add('intro');
    say('Starting'); void stage.offsetWidth;

    at(30,       function(){ stage.classList.add('in'); });
    at(T(800),   function(){ stage.classList.add('s1'); say('Powering on'); });
    at(T(1500),  function(){ stage.classList.add('s2'); say('Verifying payment'); });
    at(T(2200),  function(){ say('Payment verified ✓'); });
    at(T(2600),  function(){ stage.classList.add('feed'); say('Printing receipt'); if (!mailStarted) sendMail(false); });
    at(T(6900),  function(){ stage.classList.add('stamp'); say('Receipt ready ✓'); });
    at(T(7500),  function(){ stage.classList.add('free','hand'); });
    at(T(8700),  function(){ stage.classList.add('grip'); });
    at(T(9100),  function(){ stage.classList.add('tear'); say('Receipt taken'); });
    at(T(9600),  function(){ stage.classList.add('lift'); });
    at(T(11000), function(){ stage.classList.add('exit'); });
    at(T(11500), showThanks);
  }

  document.getElementById('skip').addEventListener('click', showThanks);
  document.getElementById('view').addEventListener('click', function(){
    thx.hidden = true; root.classList.remove('intro'); window.scrollTo(0,0);
  });
  document.getElementById('replay').addEventListener('click', play);

  if (reduce){ stage.hidden = true; fit(); showThanks(); } else { play(); }
})();
</script>
<?php endif; ?>
</body></html>