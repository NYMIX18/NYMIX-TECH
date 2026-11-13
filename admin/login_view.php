<?php
// NYMIX TECH — Staff sign-in screen (view only). Loaded by dashboard.php.
if (!isset($login_error)) { http_response_code(403); exit; }
$hasPhoto = is_file(__DIR__ . '/assets/helpline.jpg');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>NYMIX TECH — Staff Sign in</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#111316;--card:#1a1d21;--field:#14171a;
  --line:#2a2e34;--line2:#383d45;
  --tx:#e9ebee;--tx2:#a2a8b1;--tx3:#6c737d;
  --acc:#d5d9df;--acc2:#b4bac3;--focus:#8d97a6;
  --ok:#7fbf92;--red:#f08a8a;--amber:#e0b15c;
}
html,body{height:100%}
body{background:var(--bg);color:var(--tx);font-family:'Outfit',sans-serif;min-height:100vh;overflow-x:hidden}
#bg{position:fixed;inset:0;width:100%;height:100%;z-index:0}
.shell{position:relative;z-index:2;min-height:100vh;display:grid;grid-template-columns:1fr 440px;align-items:center;gap:56px;max-width:1120px;margin:0 auto;padding:32px 28px}

/* left: photo panel */
.photo{position:relative;border-radius:22px;overflow:hidden;aspect-ratio:4/4.4;max-height:640px;border:1px solid var(--line2);background:#20242a;box-shadow:0 30px 70px rgba(0,0,0,.45);opacity:0;transform:translateX(-22px);animation:in .9s cubic-bezier(.22,1,.36,1) .1s forwards}
@keyframes in{to{opacity:1;transform:none}}
.photo img,.photo svg.fb{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;animation:zoom 22s ease-in-out infinite alternate}
@keyframes zoom{from{transform:scale(1)}to{transform:scale(1.06)}}
.photo::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(17,19,22,0) 45%,rgba(17,19,22,.88) 100%);pointer-events:none}
.cap{position:absolute;left:0;right:0;bottom:0;padding:26px 28px;z-index:2}
.cap b{display:block;font-size:22px;font-weight:600;letter-spacing:-.2px}
.cap span{display:block;margin-top:6px;font-size:14px;color:var(--tx2);font-weight:300;line-height:1.55;max-width:380px}

/* right: card */
.wrap{opacity:0;transform:translateY(26px);animation:in .9s cubic-bezier(.22,1,.36,1) .25s forwards}
.brand{display:flex;align-items:center;gap:13px;margin-bottom:22px}
.mark{width:44px;height:44px;border-radius:12px;background:linear-gradient(145deg,#3a3f47,#262a30);border:1px solid var(--line2);display:grid;place-items:center;font-weight:700;font-size:16px;letter-spacing:.5px;color:var(--acc)}
.bn{font-weight:600;font-size:17px;letter-spacing:.8px}
.bs{font-family:'DM Mono',monospace;font-size:10px;letter-spacing:2.5px;color:var(--tx3);text-transform:uppercase;margin-top:2px}
.card{background:var(--card);border:1px solid var(--line2);border-radius:20px;padding:34px 32px 26px;box-shadow:0 30px 60px rgba(0,0,0,.4);position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#6b727c,transparent);background-size:200% 100%;animation:sweep 4s linear infinite}
@keyframes sweep{from{background-position:200% 0}to{background-position:-200% 0}}
.card.shake{animation:shake .5s cubic-bezier(.36,.07,.19,.97)}
@keyframes shake{10%,90%{transform:translateX(-2px)}20%,80%{transform:translateX(4px)}30%,50%,70%{transform:translateX(-7px)}40%,60%{transform:translateX(7px)}}
.card h2{font-size:24px;font-weight:600}
.sub{font-size:13.5px;color:var(--tx3);margin:5px 0 22px}
.err{display:flex;gap:10px;align-items:flex-start;background:rgba(240,138,138,.09);border:1px solid rgba(240,138,138,.3);color:var(--red);padding:11px 14px;border-radius:11px;font-size:13px;margin-bottom:18px}
.fg{margin-bottom:16px}
.fg label{display:block;font-family:'DM Mono',monospace;font-size:10px;letter-spacing:1.8px;text-transform:uppercase;color:var(--tx3);margin-bottom:7px}
.iw{position:relative}
.iw>svg.ic{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:16px;height:16px;stroke:var(--tx3);fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;pointer-events:none;transition:stroke .2s}
.iw:focus-within>svg.ic{stroke:var(--acc)}
.iw input{width:100%;background:var(--field);border:1px solid var(--line2);border-radius:12px;padding:13px 44px 13px 42px;color:var(--tx);font:400 14.5px 'Outfit',sans-serif;outline:none;transition:border-color .2s,box-shadow .2s,background .2s}
.iw input::placeholder{color:#555c66}
.iw input:focus{border-color:var(--focus);background:#171a1e;box-shadow:0 0 0 4px rgba(141,151,166,.16)}
.eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:0;color:var(--tx3);cursor:pointer;padding:6px;display:grid;place-items:center}
.eye:hover{color:var(--tx)}.eye svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.caps{display:none;align-items:center;gap:6px;font-size:12px;color:var(--amber);margin-top:7px}
.caps.on{display:flex}
.btn{width:100%;margin-top:6px;padding:14px;border:0;border-radius:12px;cursor:pointer;background:linear-gradient(180deg,var(--acc),var(--acc2));color:#16191d;font:600 15px 'Outfit',sans-serif;position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s;box-shadow:0 8px 22px rgba(0,0,0,.35)}
.btn::before{content:'';position:absolute;top:0;left:-120%;width:80%;height:100%;background:linear-gradient(100deg,transparent,rgba(255,255,255,.45),transparent);transform:skewX(-20deg);transition:left .6s}
.btn:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(0,0,0,.45)}
.btn:hover::before{left:140%}
.btn:active{transform:none}
.btn .in2{display:inline-flex;align-items:center;gap:9px;justify-content:center}
.btn svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round;transition:transform .2s}
.btn:hover svg{transform:translateX(4px)}
.btn.busy{pointer-events:none;opacity:.85}
.btn.busy svg{animation:spin .8s linear infinite;transform:none}
@keyframes spin{to{transform:rotate(360deg)}}
.help{margin-top:18px;font-size:12.5px;color:var(--tx3);text-align:center;line-height:1.6}
.meta{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:18px;padding-top:16px;border-top:1px solid var(--line);font-size:11.5px;color:var(--tx3);flex-wrap:wrap}
.live{display:inline-flex;align-items:center;gap:6px}
.live i{width:6px;height:6px;border-radius:50%;background:var(--ok);animation:pulse 2s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(127,191,146,.55)}70%{box-shadow:0 0 0 7px rgba(127,191,146,0)}100%{box-shadow:0 0 0 0 rgba(127,191,146,0)}}

@media(max-width:920px){.shell{grid-template-columns:1fr;max-width:460px;gap:0;padding:22px 16px}.photo{display:none}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition:none!important}}
</style>
</head>
<body>
<canvas id="bg"></canvas>

<div class="shell">
  <aside class="photo" aria-hidden="true">
    <?php if ($hasPhoto): ?>
      <img src="assets/helpdesk.jpg" alt="">
    <?php else: ?>
      <svg class="fb" viewBox="0 0 400 440" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
        <defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2b3036"/><stop offset="1" stop-color="#1b1e23"/></linearGradient></defs>
        <rect width="400" height="440" fill="url(#g1)"/>
        <circle cx="300" cy="90" r="120" fill="#ffffff" opacity=".03"/>
        <circle cx="70" cy="360" r="140" fill="#ffffff" opacity=".025"/>
        <path d="M60 440c0-78 62-122 140-122s140 44 140 122z" fill="#4a5058"/>
        <path d="M170 318l30 34 30-34" fill="#d9ddE2" opacity=".9"/>
        <rect x="178" y="270" width="44" height="54" rx="18" fill="#e3b79a"/>
        <ellipse cx="200" cy="215" rx="68" ry="78" fill="#efc4a6"/>
        <path d="M130 205c-4-62 34-92 72-92s76 30 68 92c-14-34-34-52-70-52s-56 18-70 52z" fill="#2a2420"/>
        <ellipse cx="174" cy="218" rx="6" ry="8" fill="#2a2420"/><ellipse cx="226" cy="218" rx="6" ry="8" fill="#2a2420"/>
        <path d="M168 248c10 22 54 22 64 0" stroke="#8a3f3a" stroke-width="5" stroke-linecap="round" fill="none"/>
        <path d="M134 214c-2-56 28-84 66-84s68 28 66 84" stroke="#9aa1ab" stroke-width="9" stroke-linecap="round" fill="none"/>
        <rect x="120" y="206" width="20" height="44" rx="10" fill="#9aa1ab"/><rect x="260" y="206" width="20" height="44" rx="10" fill="#9aa1ab"/>
        <path d="M130 248c2 26 24 40 56 42" stroke="#9aa1ab" stroke-width="5" stroke-linecap="round" fill="none"/>
        <circle cx="190" cy="291" r="8" fill="#6f7680"/>
      </svg>
    <?php endif; ?>
    <div class="cap">
      <b>Need a hand signing in?</b>
      <span>Our support team is happy to help. Ask a super admin to reset your password or unlock your account.</span>
    </div>
  </aside>

  <div class="wrap">
    <div class="brand"><div class="mark">NT</div><div><div class="bn">NYMIX TECH</div><div class="bs">Staff Portal</div></div></div>
    <div class="card" id="card">
      <h2>Staff sign in</h2>
      <div class="sub">Use your work email and password.</div>

      <?php if (!empty($login_error)): ?>
      <div class="err" role="alert"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><span><?= esc($login_error) ?></span></div>
      <?php endif; ?>

      <form method="POST" id="lf" autocomplete="on">
        <input type="hidden" name="sa_login" value="1">
        <?= csrf_token_field() ?>
        <div class="fg">
          <label for="email">Work email</label>
          <div class="iw">
            <input type="email" id="email" name="email" placeholder="name@nymixtech.com" required autocomplete="username" value="<?= esc($_POST['email'] ?? '') ?>">
            <svg class="ic" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
          </div>
        </div>
        <div class="fg">
          <label for="pw">Password</label>
          <div class="iw">
            <input type="password" id="pw" name="password" placeholder="Your password" required autocomplete="current-password">
            <svg class="ic" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <button type="button" class="eye" id="eye" aria-label="Show or hide password"><svg id="eyei" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
          </div>
          <div class="caps" id="caps"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>Caps Lock is on</div>
        </div>
        <button type="submit" class="btn" id="sb"><span class="in2"><span id="sbt">Sign in</span><svg viewBox="0 0 24 24"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></span></button>
      </form>

      <div class="help">Forgot your password? Ask a super admin to reset it.</div>
      <div class="meta">
        <span class="live"><i></i>System online</span>
        <span id="clock"></span>
      </div>
    </div>
    <div class="help" style="margin-top:14px;">Authorised staff only. Sign-ins are logged.</div>
  </div>
</div>

<script>
// subtle grey particle network
(function(){
  var c=document.getElementById('bg'),x=c.getContext('2d'),W,H,P=[],m={x:-999,y:-999},reduce=matchMedia('(prefers-reduced-motion:reduce)').matches;
  function init(){W=c.width=innerWidth;H=c.height=innerHeight;var n=Math.min(70,Math.floor(W*H/22000));P=[];
    for(var i=0;i<n;i++)P.push({x:Math.random()*W,y:Math.random()*H,vx:(Math.random()-.5)*.28,vy:(Math.random()-.5)*.28,r:Math.random()*1.3+.4});}
  function tick(){x.clearRect(0,0,W,H);
    P.forEach(function(p){p.x+=p.vx;p.y+=p.vy;if(p.x<0||p.x>W)p.vx*=-1;if(p.y<0||p.y>H)p.vy*=-1;
      var dx=p.x-m.x,dy=p.y-m.y,d=Math.hypot(dx,dy);if(d<100&&d>0){p.x+=dx/d*1.2;p.y+=dy/d*1.2;}});
    for(var i=0;i<P.length;i++)for(var j=i+1;j<P.length;j++){var d=Math.hypot(P[i].x-P[j].x,P[i].y-P[j].y);
      if(d<125){x.strokeStyle='rgba(170,178,190,'+(1-d/125)*.14+')';x.lineWidth=.6;x.beginPath();x.moveTo(P[i].x,P[i].y);x.lineTo(P[j].x,P[j].y);x.stroke();}}
    P.forEach(function(p){x.fillStyle='rgba(190,197,207,.4)';x.beginPath();x.arc(p.x,p.y,p.r,0,6.283);x.fill();});
    if(!reduce)requestAnimationFrame(tick);}
  addEventListener('resize',init);addEventListener('mousemove',function(e){m.x=e.clientX;m.y=e.clientY;});
  init();tick();
})();

(function(){
  var pw=document.getElementById('pw'),eye=document.getElementById('eye'),ei=document.getElementById('eyei'),caps=document.getElementById('caps'),card=document.getElementById('card');
  var open='<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  var shut='<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
  eye.addEventListener('click',function(){var s=pw.type==='password';pw.type=s?'text':'password';ei.innerHTML=s?shut:open;pw.focus();});
  function capsCheck(e){if(e.getModifierState)caps.classList.toggle('on',e.getModifierState('CapsLock'));}
  pw.addEventListener('keydown',capsCheck);pw.addEventListener('keyup',capsCheck);pw.addEventListener('blur',function(){caps.classList.remove('on');});
  function clock(){var d=new Date();document.getElementById('clock').textContent=d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})+' · '+d.toLocaleDateString([], {day:'2-digit',month:'short',year:'numeric'});}
  clock();setInterval(clock,20000);
  if(document.querySelector('.err')){card.classList.add('shake');pw.focus();}
  else{var em=document.getElementById('email');if(em&&!em.value)em.focus();}
  document.getElementById('lf').addEventListener('submit',function(){
    var b=document.getElementById('sb');if(b.classList.contains('busy'))return;
    b.classList.add('busy');document.getElementById('sbt').textContent='Signing in...';
    b.querySelector('svg').innerHTML='<path d="M21 12a9 9 0 1 1-6.2-8.55"/>';
  });
})();
</script>
</body>
</html>