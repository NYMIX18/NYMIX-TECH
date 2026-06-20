<?php $year = date('Y'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#E87800">
<meta name="description" content="NYMIX TECH — Business Management Platform for Kenyan businesses.">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="NYMIX">
<link rel="apple-touch-icon" href="nymix_hardwares/icons/icon-192.png">
<link rel="manifest" href="nymix_hardwares/manifest.json">
<title>NYMIX TECH — Business Management Platform</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Outfit:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

<style>
/* ══════════════════════════════════════════════
   TOKENS
══════════════════════════════════════════════ */
:root {
  --black:   #060608;
  --ink:     #0A0A0F;
  --ink2:    #0F0F16;
  --ink3:    #16161F;
  --ink4:    #1E1E2A;
  --card:    #111118;
  --border:  rgba(255,255,255,0.06);
  --border2: rgba(255,255,255,0.12);

  --orange:  #E87800;
  --orange2: #FF9A20;
  --orange3: #FFB347;
  --orange-dim: rgba(232,120,0,0.08);

  --white:   #F0F0F2;
  --green:   #00D68F;

  --tx:      #EEEEF2;
  --tx2:     #6E6E8A;
  --tx3:     #303040;

  --font-display: 'Bebas Neue', sans-serif;
  --font-body:    'Outfit', sans-serif;
  --font-mono:    'DM Mono', monospace;
  --ease: cubic-bezier(.4,0,.2,1);
  --ease-spring: cubic-bezier(.34,1.56,.64,1);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; font-size: 15px; }
body {
  background: var(--black);
  color: var(--tx);
  font-family: var(--font-body);
  min-height: 100vh;
  overflow-x: hidden;
}
::selection { background: rgba(232,120,0,0.3); color: #fff; }
::-webkit-scrollbar { width: 3px; }
::-webkit-scrollbar-track { background: var(--black); }
::-webkit-scrollbar-thumb { background: var(--ink4); border-radius: 3px; }

/* ── GRAIN OVERLAY (the key effect) ── */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 9999;
  pointer-events: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='400' height='400' filter='url(%23n)' opacity='0.055'/%3E%3C/svg%3E");
  opacity: 1;
  mix-blend-mode: overlay;
}

/* ── SCANLINES ── */
body::after {
  content: '';
  position: fixed; inset: 0; z-index: 9998;
  pointer-events: none;
  background: repeating-linear-gradient(
    0deg,
    transparent,
    transparent 2px,
    rgba(0,0,0,0.04) 2px,
    rgba(0,0,0,0.04) 4px
  );
}

/* ── AMBIENT GLOW ORBS ── */
.orb {
  position: fixed; border-radius: 50%;
  filter: blur(160px); pointer-events: none; z-index: 0;
}
.orb-1 { width: 900px; height: 900px; background: rgba(232,120,0,0.045); top: -40%; left: -30%; animation: drift1 20s ease-in-out infinite; }
.orb-2 { width: 500px; height: 500px; background: rgba(255,154,32,0.03); bottom: -10%; right: -15%; animation: drift2 16s ease-in-out infinite; }
.orb-3 { width: 300px; height: 300px; background: rgba(0,214,143,0.02); top: 50%; left: 60%; animation: drift1 25s ease-in-out infinite reverse; }
@keyframes drift1 { 0%,100%{transform:translate(0,0)} 33%{transform:translate(40px,-30px)} 66%{transform:translate(-20px,50px)} }
@keyframes drift2 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-40px,-40px)} }

.page { position: relative; z-index: 1; }

/* ══════════════════════════════════════
   NAV
══════════════════════════════════════ */
.nav {
  position: fixed; top: 0; left: 0; right: 0; z-index: 200;
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 48px; height: 64px;
  background: rgba(6,6,8,0.7);
  backdrop-filter: blur(32px) saturate(1.4);
  border-bottom: 1px solid var(--border);
}
.nav-brand {
  display: flex; align-items: center; gap: 14px; text-decoration: none;
}
.nav-logo-mark {
  width: 36px; height: 36px;
  border: 1px solid rgba(232,120,0,0.3);
  border-radius: 8px;
  display: grid; place-items: center;
  background: rgba(232,120,0,0.06);
  position: relative; overflow: hidden;
}
.nav-logo-mark::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(135deg, rgba(232,120,0,0.25) 0%, transparent 60%);
}
.nav-logo-mark span {
  font-family: var(--font-display);
  font-size: 16px; letter-spacing: 1px;
  background: linear-gradient(135deg, var(--orange), var(--orange3));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  position: relative; z-index: 1;
}
.nav-wordmark {
  display: flex; flex-direction: column; gap: 0;
}
.nav-name {
  font-family: var(--font-display);
  font-size: 18px; letter-spacing: 4px; color: var(--tx); line-height: 1;
}
.nav-sub {
  font-family: var(--font-mono);
  font-size: 8px; letter-spacing: 3px; color: var(--tx3); line-height: 1;
  margin-top: 2px;
}
.nav-links {
  display: flex; align-items: center; gap: 2px;
  position: absolute; left: 50%; transform: translateX(-50%);
}
.nav-link {
  font-family: var(--font-mono); font-size: 10px; letter-spacing: 2px;
  color: var(--tx2); padding: 7px 14px; border-radius: 6px;
  text-decoration: none; text-transform: uppercase;
  transition: color .2s var(--ease), background .2s var(--ease);
}
.nav-link:hover { color: var(--tx); background: var(--ink3); }
.nav-right { display: flex; align-items: center; gap: 10px; }
.nav-status {
  display: flex; align-items: center; gap: 6px;
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 2px; color: var(--tx3);
}
.nav-status-dot {
  width: 5px; height: 5px; border-radius: 50%;
  background: var(--green); box-shadow: 0 0 8px var(--green);
  animation: blink 2.5s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }
.nav-cta {
  font-family: var(--font-mono); font-size: 10px; letter-spacing: 2px;
  color: #060608; background: linear-gradient(135deg, var(--orange), var(--orange2));
  padding: 9px 20px; border-radius: 7px; text-transform: uppercase;
  border: none; cursor: pointer; font-family: var(--font-body);
  font-weight: 700; transition: all .2s var(--ease);
  box-shadow: 0 4px 20px rgba(232,120,0,0.3);
}
.nav-cta:hover { transform: translateY(-1px); box-shadow: 0 8px 28px rgba(232,120,0,0.45); }

/* ══════════════════════════════════════
   HERO — CINEMATIC FULL SCREEN
══════════════════════════════════════ */
.hero {
  min-height: 100vh;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 120px 40px 80px;
  text-align: center;
  position: relative; overflow: hidden;
}

/* horizontal rule lines across hero */
.hero-lines {
  position: absolute; inset: 0; pointer-events: none;
}
.hero-line {
  position: absolute; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 0%, rgba(232,120,0,0.08) 30%, rgba(232,120,0,0.08) 70%, transparent 100%);
}
.hero-line:nth-child(1) { top: 25%; }
.hero-line:nth-child(2) { top: 60%; }
.hero-line:nth-child(3) { top: 85%; }

/* vertical lines */
.hero-vline {
  position: absolute; top: 0; bottom: 0; width: 1px;
  background: linear-gradient(180deg, transparent 0%, rgba(232,120,0,0.06) 30%, rgba(232,120,0,0.06) 70%, transparent 100%);
}
.hero-vline:nth-child(4) { left: 20%; }
.hero-vline:nth-child(5) { right: 20%; }

.hero-tag {
  display: inline-flex; align-items: center; gap: 10px;
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 3px;
  color: var(--orange); text-transform: uppercase;
  border: 1px solid rgba(232,120,0,0.2);
  padding: 6px 18px; border-radius: 2px;
  margin-bottom: 48px;
  animation: fadeUp .5s var(--ease) .1s both;
  background: rgba(232,120,0,0.04);
}
.hero-tag::before { content: '◆'; font-size: 6px; }
.hero-tag::after  { content: '◆'; font-size: 6px; }

.hero-title {
  font-family: var(--font-display);
  font-size: clamp(80px, 13vw, 180px);
  letter-spacing: 8px; line-height: .88;
  color: var(--tx);
  margin-bottom: 0;
  animation: fadeUp .6s var(--ease) .18s both;
  position: relative;
}
.hero-title .stroke {
  -webkit-text-stroke: 1px rgba(232,120,0,0.6);
  color: transparent;
}
.hero-title .fill {
  background: linear-gradient(135deg, #fff 30%, rgba(232,120,0,0.9) 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}

.hero-title-row2 {
  font-family: var(--font-display);
  font-size: clamp(40px, 7vw, 96px);
  letter-spacing: 20px; line-height: 1;
  color: var(--tx2);
  margin-bottom: 60px;
  animation: fadeUp .6s var(--ease) .25s both;
}

.hero-meta {
  display: flex; align-items: center; justify-content: center;
  gap: 48px; margin-bottom: 56px;
  animation: fadeUp .6s var(--ease) .32s both;
  flex-wrap: wrap;
}
.hero-stat { text-align: center; }
.hero-stat-num {
  font-family: var(--font-display);
  font-size: 42px; letter-spacing: 2px; line-height: 1;
  background: linear-gradient(135deg, var(--orange), var(--orange3));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.hero-stat-label {
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 2px;
  color: var(--tx3); margin-top: 4px; text-transform: uppercase;
}
.hero-meta-divider {
  width: 1px; height: 48px; background: var(--border2);
}

.hero-desc {
  font-size: 15px; font-weight: 300; color: var(--tx2);
  max-width: 520px; line-height: 1.85; margin-bottom: 52px;
  animation: fadeUp .6s var(--ease) .38s both;
}

.hero-cta-row {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap; justify-content: center;
  animation: fadeUp .6s var(--ease) .44s both;
}
.hero-btn-primary {
  display: inline-flex; align-items: center; gap: 12px;
  background: linear-gradient(135deg, var(--orange), var(--orange2));
  color: #060608; padding: 16px 36px; border-radius: 3px;
  font-size: 14px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
  text-decoration: none; border: none; cursor: pointer; font-family: var(--font-body);
  transition: all .25s var(--ease);
  box-shadow: 0 8px 32px rgba(232,120,0,0.35);
}
.hero-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 16px 48px rgba(232,120,0,0.45); }
.hero-btn-outline {
  display: inline-flex; align-items: center; gap: 10px;
  background: transparent; color: var(--tx2); padding: 16px 32px; border-radius: 3px;
  font-size: 13px; font-weight: 500; letter-spacing: 1px; text-transform: uppercase;
  text-decoration: none; cursor: pointer; font-family: var(--font-body);
  border: 1px solid var(--border2);
  transition: all .2s var(--ease);
}
.hero-btn-outline:hover { background: var(--ink3); color: var(--tx); border-color: rgba(232,120,0,0.3); }

.scroll-indicator {
  position: absolute; bottom: 36px; left: 50%; transform: translateX(-50%);
  display: flex; flex-direction: column; align-items: center; gap: 10px;
  animation: fadeUp .6s var(--ease) 1s both;
}
.scroll-indicator span {
  font-family: var(--font-mono); font-size: 8px; letter-spacing: 3px;
  color: var(--tx3); text-transform: uppercase;
}
.scroll-track {
  width: 1px; height: 60px;
  background: linear-gradient(180deg, rgba(232,120,0,0.5), transparent);
  position: relative; overflow: hidden;
}
.scroll-track::after {
  content: '';
  position: absolute; width: 100%; height: 30%;
  background: var(--orange);
  animation: scrollDown 2s ease-in-out infinite;
}
@keyframes scrollDown { 0%{top:-30%} 100%{top:130%} }

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(28px); }
  to   { opacity: 1; transform: none; }
}

/* ══════════════════════════════════════
   MARQUEE TICKER
══════════════════════════════════════ */
.ticker-wrap {
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  background: var(--ink);
  overflow: hidden; padding: 14px 0;
  position: relative; z-index: 2;
}
.ticker-wrap::before, .ticker-wrap::after {
  content: ''; position: absolute; top: 0; bottom: 0; width: 80px; z-index: 3;
}
.ticker-wrap::before { left: 0; background: linear-gradient(90deg, var(--ink), transparent); }
.ticker-wrap::after  { right: 0; background: linear-gradient(-90deg, var(--ink), transparent); }
.ticker {
  display: flex; align-items: center; gap: 0;
  animation: ticker 28s linear infinite; white-space: nowrap;
  width: max-content;
}
.ticker-item {
  font-family: var(--font-mono); font-size: 10px; letter-spacing: 3px;
  color: var(--tx3); text-transform: uppercase;
  padding: 0 32px;
}
.ticker-item.accent { color: var(--orange); }
.ticker-item.accent::before { content: '◆  '; font-size: 7px; }
@keyframes ticker { 0%{transform:translateX(0)} 100%{transform:translateX(-50%)} }

/* ══════════════════════════════════════
   SECTION SHARED
══════════════════════════════════════ */
.section-label {
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 4px;
  color: var(--tx3); text-transform: uppercase;
  display: flex; align-items: center; gap: 12px; margin-bottom: 48px;
}
.section-label::after {
  content: ''; flex: 1; max-width: 80px; height: 1px; background: var(--border2);
}
.section-heading {
  font-family: var(--font-display);
  font-size: clamp(48px, 6vw, 88px);
  letter-spacing: 3px; line-height: .92;
  color: var(--tx); margin-bottom: 24px;
}
.section-heading .dim { color: var(--tx3); }
.section-body {
  font-size: 15px; font-weight: 300; color: var(--tx2); line-height: 1.85;
}

/* ══════════════════════════════════════
   FEATURES — MAGAZINE GRID
══════════════════════════════════════ */
.features-section {
  padding: 120px 48px; max-width: 1200px; margin: 0 auto;
}
.features-layout {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 0; border: 1px solid var(--border);
}
.feat-block {
  padding: 48px 40px;
  border-right: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  position: relative; overflow: hidden;
  transition: background .3s var(--ease);
}
.feat-block:nth-child(even) { border-right: none; }
.feat-block:nth-last-child(-n+2) { border-bottom: none; }
.feat-block:hover { background: var(--ink2); }
.feat-block::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, var(--orange), transparent);
  opacity: 0; transition: opacity .3s var(--ease);
}
.feat-block:hover::before { opacity: 1; }
.feat-num {
  font-family: var(--font-display); font-size: 72px; letter-spacing: 2px;
  color: var(--ink4); line-height: 1; margin-bottom: 24px;
  position: absolute; top: 20px; right: 30px;
}
.feat-icon-lg { font-size: 28px; margin-bottom: 20px; display: block; }
.feat-name {
  font-family: var(--font-display); font-size: 28px; letter-spacing: 2px;
  color: var(--tx); margin-bottom: 10px; line-height: 1;
}
.feat-desc { font-size: 13px; color: var(--tx2); line-height: 1.75; }
.feat-tags {
  display: flex; flex-wrap: wrap; gap: 6px; margin-top: 20px;
}
.feat-tag {
  font-family: var(--font-mono); font-size: 8px; letter-spacing: 2px;
  color: var(--orange); border: 1px solid rgba(232,120,0,0.2);
  padding: 3px 10px; border-radius: 2px; text-transform: uppercase;
}

/* ══════════════════════════════════════
   PRICING — FULL BLEED DARK
══════════════════════════════════════ */
.pricing-section {
  background: var(--ink);
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  padding: 120px 48px;
  position: relative; overflow: hidden;
}
.pricing-section::before {
  content: '';
  position: absolute; top: -200px; left: 50%; transform: translateX(-50%);
  width: 600px; height: 600px; border-radius: 50%;
  background: radial-gradient(circle, rgba(232,120,0,0.07) 0%, transparent 70%);
  pointer-events: none;
}
.pricing-inner { max-width: 1200px; margin: 0 auto; }
.pricing-header { margin-bottom: 72px; }

.pricing-grid {
  display: grid; grid-template-columns: repeat(4,1fr); gap: 1px;
  background: var(--border); border: 1px solid var(--border);
}
.plan-card {
  background: var(--black); padding: 40px 28px;
  display: flex; flex-direction: column;
  position: relative; overflow: hidden;
  transition: background .3s var(--ease);
}
.plan-card:hover { background: var(--ink2); }
.plan-card.highlighted {
  background: var(--ink2);
  position: relative;
}
.plan-card.highlighted::after {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, var(--orange), var(--orange3));
}
.plan-badge {
  font-family: var(--font-mono); font-size: 8px; letter-spacing: 2px;
  color: #060608; background: linear-gradient(135deg, var(--orange), var(--orange2));
  padding: 3px 10px; border-radius: 2px;
  display: inline-block; margin-bottom: 28px; text-transform: uppercase;
}
.plan-tier {
  font-family: var(--font-mono); font-size: 8px; letter-spacing: 3px;
  color: var(--tx3); text-transform: uppercase; margin-bottom: 6px;
}
.plan-name {
  font-family: var(--font-display); font-size: 32px; letter-spacing: 3px;
  color: var(--tx); margin-bottom: 28px; line-height: 1;
}
.plan-price-row { display: flex; align-items: flex-end; gap: 4px; margin-bottom: 6px; }
.plan-kes { font-family: var(--font-mono); font-size: 13px; color: var(--tx3); margin-bottom: 8px; }
.plan-amount {
  font-family: var(--font-display); font-size: 52px; letter-spacing: 1px; line-height: 1; color: var(--tx);
}
.plan-cycle { font-family: var(--font-mono); font-size: 10px; color: var(--tx3); margin-bottom: 8px; }
.plan-branches {
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 1.5px;
  color: var(--orange); margin-bottom: 28px; padding: 6px 10px;
  background: rgba(232,120,0,0.07); border: 1px solid rgba(232,120,0,0.15);
  border-radius: 2px; display: inline-block;
}
.plan-divider { height: 1px; background: var(--border); margin-bottom: 24px; }
.plan-feats { list-style: none; margin-bottom: 32px; flex: 1; }
.plan-feats li {
  font-size: 13px; color: var(--tx2); padding: 8px 0;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: flex-start; gap: 10px; line-height: 1.5;
}
.plan-feats li:last-child { border-bottom: none; }
.f-check { color: var(--green); font-size: 11px; margin-top: 2px; flex-shrink: 0; }
.f-x { color: var(--tx3); font-size: 11px; margin-top: 2px; flex-shrink: 0; }
.plan-feats li.off { opacity: .35; }
.plan-addon {
  font-family: var(--font-mono); font-size: 9px; color: var(--tx3);
  background: var(--ink3); border: 1px solid var(--border); padding: 6px 10px;
  border-radius: 2px; margin-bottom: 20px; line-height: 1.6;
}
.plan-addon strong { color: var(--orange2); }
.plan-btn {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  padding: 14px; border-radius: 2px; font-size: 12px; font-weight: 700;
  letter-spacing: 1px; text-transform: uppercase; cursor: pointer;
  border: none; font-family: var(--font-body); transition: all .2s var(--ease);
}
.plan-btn-fill {
  background: linear-gradient(135deg, var(--orange), var(--orange2));
  color: #060608; box-shadow: 0 6px 24px rgba(232,120,0,0.3);
}
.plan-btn-fill:hover { filter: brightness(1.1); transform: translateY(-1px); }
.plan-btn-outline {
  background: transparent; color: var(--tx2); border: 1px solid var(--border2);
}
.plan-btn-outline:hover { background: var(--ink4); color: var(--tx); border-color: rgba(232,120,0,0.3); }
.pricing-note {
  text-align: center; margin-top: 40px;
  font-family: var(--font-mono); font-size: 10px; letter-spacing: 2px; color: var(--tx3);
}
.pricing-note strong { color: var(--orange); }

/* ══════════════════════════════════════
   HOW IT WORKS — HORIZONTAL FILM STRIP
══════════════════════════════════════ */
.process-section {
  padding: 120px 48px;
  max-width: 1200px; margin: 0 auto;
}
.process-strip {
  display: grid; grid-template-columns: repeat(3,1fr);
  gap: 0; border: 1px solid var(--border); margin-top: 64px;
  position: relative;
}
.process-strip::before {
  content: '';
  position: absolute; top: 60px; left: calc(100%/6); right: calc(100%/6);
  height: 1px; background: linear-gradient(90deg, var(--orange), rgba(232,120,0,0.2), var(--orange));
  pointer-events: none;
}
.process-step {
  padding: 52px 40px;
  border-right: 1px solid var(--border);
  position: relative; overflow: hidden;
  transition: background .3s var(--ease);
}
.process-step:last-child { border-right: none; }
.process-step:hover { background: var(--ink2); }
.step-circle {
  width: 64px; height: 64px; border-radius: 50%;
  border: 1px solid rgba(232,120,0,0.3);
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 32px;
  background: var(--ink3);
  font-family: var(--font-display); font-size: 26px;
  color: var(--orange); position: relative; z-index: 1;
}
.step-label {
  font-family: var(--font-mono); font-size: 8px; letter-spacing: 3px;
  color: var(--tx3); text-transform: uppercase; margin-bottom: 10px;
}
.step-title {
  font-family: var(--font-display); font-size: 30px; letter-spacing: 2px;
  color: var(--tx); margin-bottom: 14px;
}
.step-desc { font-size: 13px; color: var(--tx2); line-height: 1.75; }

/* ══════════════════════════════════════
   DOWNLOAD — SPLIT CARD
══════════════════════════════════════ */
.install-section {
  background: var(--ink); border-top: 1px solid var(--border);
  padding: 120px 48px; position: relative; overflow: hidden;
}
.install-inner { max-width: 1200px; margin: 0 auto; }
.install-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); margin-top: 64px; }
.install-card {
  background: var(--black); padding: 44px 32px;
  position: relative; overflow: hidden;
  transition: background .3s var(--ease);
}
.install-card:hover { background: var(--ink2); }
.install-card::after {
  content: '';
  position: absolute; bottom: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--orange), transparent);
  opacity: 0; transition: opacity .3s var(--ease);
}
.install-card:hover::after { opacity: 1; }
.install-os-icon { font-size: 36px; margin-bottom: 24px; display: block; }
.install-platform {
  font-family: var(--font-display); font-size: 36px; letter-spacing: 3px;
  color: var(--tx); margin-bottom: 6px;
}
.install-os {
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 2px;
  color: var(--orange); margin-bottom: 28px;
}
.install-steps { list-style: none; margin-bottom: 28px; }
.install-steps li {
  font-size: 13px; color: var(--tx2); padding: 8px 0;
  border-bottom: 1px solid var(--border);
  display: flex; gap: 12px; line-height: 1.5; align-items: flex-start;
}
.install-steps li:last-child { border-bottom: none; }
.step-n {
  font-family: var(--font-mono); font-size: 9px; color: var(--orange);
  min-width: 20px; padding-top: 2px;
}
.file-badge {
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 1px;
  color: var(--tx3); background: var(--ink3); border: 1px solid var(--border);
  padding: 4px 10px; border-radius: 2px; display: inline-block; margin-bottom: 20px;
}
.dl-prog-wrap { display: none; margin-bottom: 12px; background: var(--ink3); height: 2px; overflow: hidden; }
.dl-prog-bar { height: 100%; width: 0%; background: linear-gradient(90deg, var(--orange), var(--orange2)); transition: width .4s var(--ease); }
.install-btn {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  width: 100%; padding: 14px; border-radius: 2px;
  font-family: var(--font-body); font-size: 13px; font-weight: 700;
  letter-spacing: 1px; text-transform: uppercase; text-decoration: none;
  cursor: pointer; border: none; transition: all .2s var(--ease);
}
.install-btn-fill {
  background: linear-gradient(135deg, var(--orange), var(--orange2));
  color: #060608; box-shadow: 0 6px 24px rgba(232,120,0,0.28);
}
.install-btn-fill:hover { transform: translateY(-2px); box-shadow: 0 12px 36px rgba(232,120,0,0.4); }
.install-btn-ghost {
  background: transparent; color: var(--tx2);
  border: 1px solid var(--border2);
}
.install-btn-ghost:hover { background: var(--ink3); color: var(--tx); border-color: rgba(232,120,0,0.3); }

/* ══════════════════════════════════════
   TESTIMONIALS
══════════════════════════════════════ */
.testi-section { padding: 120px 48px; max-width: 1200px; margin: 0 auto; }
.testi-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); margin-top: 64px; }
.testi-card {
  background: var(--black); padding: 40px 32px;
  position: relative; overflow: hidden;
  transition: background .3s var(--ease);
  display: flex; flex-direction: column;
}
.testi-card:hover { background: var(--ink2); }
.testi-quote-mark {
  font-family: var(--font-display); font-size: 100px; line-height: 1;
  color: rgba(232,120,0,0.06); position: absolute; top: 10px; right: 20px;
  pointer-events: none; user-select: none;
}
.testi-stars { color: var(--orange); font-size: 12px; letter-spacing: 3px; margin-bottom: 18px; }
.testi-text {
  font-size: 14px; color: var(--tx2); line-height: 1.85;
  font-style: italic; flex: 1; margin-bottom: 28px; position: relative; z-index: 1;
}
.testi-author { display: flex; align-items: center; gap: 14px; }
.testi-avatar {
  width: 44px; height: 44px; border-radius: 50%;
  background: var(--ink3); border: 1px solid var(--border2);
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-display); font-size: 18px; color: var(--orange); flex-shrink: 0;
}
.testi-name { font-family: var(--font-display); font-size: 18px; letter-spacing: 1px; color: var(--tx); }
.testi-role { font-family: var(--font-mono); font-size: 8px; letter-spacing: 2px; color: var(--tx3); margin-top: 3px; }
.testi-plan {
  margin-left: auto; align-self: flex-start;
  font-family: var(--font-mono); font-size: 8px; letter-spacing: 2px;
  color: var(--orange); background: rgba(232,120,0,0.07);
  border: 1px solid rgba(232,120,0,0.15); padding: 3px 8px; border-radius: 2px;
}

/* ══════════════════════════════════════
   CTA FINALE
══════════════════════════════════════ */
.cta-section {
  padding: 160px 48px;
  text-align: center; position: relative; overflow: hidden;
  border-top: 1px solid var(--border);
}
.cta-bg-text {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
  font-family: var(--font-display); font-size: clamp(120px, 18vw, 240px);
  letter-spacing: 20px; color: rgba(232,120,0,0.025);
  pointer-events: none; user-select: none; white-space: nowrap; z-index: 0;
}
.cta-section > * { position: relative; z-index: 1; }
.cta-eyebrow {
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 4px;
  color: var(--tx3); text-transform: uppercase; margin-bottom: 32px;
  display: flex; align-items: center; justify-content: center; gap: 12px;
}
.cta-eyebrow::before, .cta-eyebrow::after { content: '——'; }
.cta-heading {
  font-family: var(--font-display);
  font-size: clamp(60px, 9vw, 128px);
  letter-spacing: 6px; line-height: .88;
  color: var(--tx); margin-bottom: 28px;
}
.cta-sub {
  font-size: 15px; font-weight: 300; color: var(--tx2); margin-bottom: 56px; max-width: 480px; margin-left: auto; margin-right: auto; line-height: 1.8;
}
.cta-buttons { display: flex; align-items: center; justify-content: center; gap: 14px; flex-wrap: wrap; }

/* ══════════════════════════════════════
   PORTAL OVERLAY
══════════════════════════════════════ */
.portal-overlay {
  position: fixed; inset: 0; z-index: 9997;
  background: rgba(6,6,8,0.95);
  backdrop-filter: blur(24px) saturate(1.2);
  display: none; align-items: center; justify-content: center; padding: 24px;
}
.portal-overlay.active { display: flex; animation: overlayIn .3s var(--ease); }
@keyframes overlayIn { from{opacity:0} to{opacity:1} }
.portal-overlay-box {
  width: 100%; max-width: 800px; position: relative;
  animation: slideUp .4s var(--ease-spring) .05s both;
}
@keyframes slideUp {
  from{opacity:0;transform:translateY(30px) scale(0.96)}
  to{opacity:1;transform:none}
}
.portal-close {
  position: absolute; top: -4px; right: 0;
  width: 40px; height: 40px; border-radius: 2px;
  background: var(--ink3); border: 1px solid var(--border2);
  display: grid; place-items: center;
  color: var(--tx2); cursor: pointer; font-size: 18px;
  transition: all .2s var(--ease);
}
.portal-close:hover { background: var(--ink4); color: var(--tx); border-color: rgba(232,120,0,0.35); }
.portal-head { text-align: center; margin-bottom: 12px; }
.portal-eyebrow {
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 3px;
  color: var(--tx3); text-transform: uppercase; margin-bottom: 20px;
  display: flex; align-items: center; justify-content: center; gap: 12px;
}
.portal-eyebrow::before, .portal-eyebrow::after { content: '——'; }
.portal-title {
  font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px);
  letter-spacing: 6px; color: var(--tx); line-height: .9; margin-bottom: 12px;
}
.portal-sub { font-size: 14px; font-weight: 300; color: var(--tx2); margin-bottom: 40px; }
.portal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; background: var(--border); border: 1px solid var(--border); }
.portal-card {
  background: var(--black); padding: 44px 36px;
  text-decoration: none; position: relative; overflow: hidden;
  transition: background .3s var(--ease); display: flex; flex-direction: column;
  cursor: pointer;
}
.portal-card:hover { background: var(--ink2); }
.portal-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  opacity: 0; transition: opacity .3s var(--ease);
}
.portal-card:hover::before { opacity: 1; }
.portal-client::before { background: linear-gradient(90deg, var(--orange), var(--orange3)); }
.portal-staff::before  { background: linear-gradient(90deg, var(--white), #888); }
.portal-card-icon { font-size: 32px; margin-bottom: 20px; display: block; }
.portal-tag {
  font-family: var(--font-mono); font-size: 8px; letter-spacing: 3px;
  color: var(--tx3); text-transform: uppercase; margin-bottom: 8px;
}
.portal-name {
  font-family: var(--font-display); font-size: 36px; letter-spacing: 3px;
  color: var(--tx); margin-bottom: 12px;
}
.portal-desc { font-size: 13px; color: var(--tx2); line-height: 1.75; margin-bottom: 28px; flex: 1; }
.portal-perms { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 28px; }
.portal-perm {
  font-family: var(--font-mono); font-size: 8px; letter-spacing: 1.5px;
  color: var(--tx3); background: var(--ink3); border: 1px solid var(--border);
  padding: 3px 9px; border-radius: 2px;
}
.portal-action {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 16px; border-radius: 2px;
  font-size: 12px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
  transition: all .2s var(--ease);
}
.portal-client .portal-action {
  background: linear-gradient(135deg, var(--orange), var(--orange2)); color: #060608;
}
.portal-staff .portal-action {
  background: var(--ink4); color: var(--tx); border: 1px solid var(--border2);
}
.portal-card:hover .portal-action { filter: brightness(1.1); }
.portal-note {
  text-align: center; margin-top: 20px;
  font-family: var(--font-mono); font-size: 9px; letter-spacing: 2px; color: var(--tx3);
}
.portal-note a { color: var(--orange); text-decoration: none; }
.portal-note a:hover { opacity: .75; }

/* ══════════════════════════════════════
   TERMS MODAL
══════════════════════════════════════ */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(6,6,8,0.97); z-index: 9999;
  align-items: center; justify-content: center; padding: 20px; display: flex;
}
.modal-box {
  background: var(--ink); border: 1px solid rgba(232,120,0,0.15);
  border-radius: 2px; width: 100%; max-width: 680px; max-height: 88vh;
  display: flex; flex-direction: column; box-shadow: 0 40px 120px rgba(0,0,0,.9);
}
.modal-head {
  padding: 28px 32px 20px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.modal-title { font-family: var(--font-display); font-size: 24px; letter-spacing: 3px; color: var(--tx); }
.modal-title-sub { font-family: var(--font-mono); font-size: 9px; color: var(--orange); letter-spacing: 2px; margin-top: 4px; }
.modal-body { overflow-y: auto; padding: 28px 32px; flex: 1; font-size: 13px; color: var(--tx2); line-height: 1.9; }
.modal-body h3 { font-family: var(--font-display); font-size: 16px; letter-spacing: 1.5px; color: var(--tx); margin: 22px 0 6px; }
.modal-body h3:first-child { margin-top: 0; }
.modal-warn { background: rgba(232,120,0,0.05); border: 1px solid rgba(232,120,0,0.15); padding: 12px 16px; margin-bottom: 24px; font-size: 12px; color: var(--orange); border-radius: 2px; }
.modal-foot { padding: 20px 32px; border-top: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; gap: 14px; }
.modal-copy { font-family: var(--font-mono); font-size: 9px; color: var(--tx3); letter-spacing: 1px; }
.modal-btns { display: flex; gap: 10px; }
.btn-decline { background: transparent; border: 1px solid var(--border2); color: var(--tx2); padding: 10px 20px; border-radius: 2px; font-family: var(--font-body); font-size: 12px; font-weight: 600; cursor: pointer; transition: all .2s; letter-spacing: 1px; text-transform: uppercase; }
.btn-decline:hover { border-color: rgba(255,255,255,0.2); color: var(--tx); }
.btn-accept { background: linear-gradient(135deg, var(--orange), var(--orange2)); color: #060608; padding: 10px 24px; border-radius: 2px; font-family: var(--font-body); font-size: 12px; font-weight: 700; cursor: pointer; transition: all .2s; border: none; box-shadow: 0 4px 18px rgba(232,120,0,0.3); letter-spacing: 1px; text-transform: uppercase; }
.btn-accept:hover { filter: brightness(1.1); transform: translateY(-1px); }

/* ══════════════════════════════════════
   REGISTRATION MODAL
══════════════════════════════════════ */
.reg-overlay {
  position: fixed; inset: 0; background: rgba(6,6,8,0.96); z-index: 9998;
  align-items: center; justify-content: center; padding: 20px;
  display: none; backdrop-filter: blur(10px);
}
.reg-box {
  background: var(--ink); border: 1px solid rgba(232,120,0,0.2);
  border-radius: 2px; width: 100%; max-width: 580px; max-height: 92vh;
  display: flex; flex-direction: column;
  box-shadow: 0 40px 120px rgba(0,0,0,.9);
  position: relative; overflow: hidden;
}
.reg-box::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, var(--orange), var(--orange2), var(--green));
}
.reg-head { padding: 28px 32px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.reg-title { font-family: var(--font-display); font-size: 26px; letter-spacing: 2px; color: var(--tx); }
.reg-title-sub { font-family: var(--font-mono); font-size: 9px; color: var(--orange); letter-spacing: 2px; margin-top: 4px; }
.reg-close { width: 34px; height: 34px; border-radius: 2px; background: var(--ink3); border: 1px solid var(--border2); display: grid; place-items: center; color: var(--tx2); cursor: pointer; font-size: 16px; transition: all .2s; }
.reg-close:hover { background: var(--ink4); color: var(--tx); border-color: rgba(232,120,0,0.3); }
.reg-body { overflow-y: auto; padding: 24px 32px; flex: 1; }
.reg-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.reg-field { display: flex; flex-direction: column; gap: 6px; }
.reg-field.full { grid-column: 1/-1; }
.reg-label { font-family: var(--font-mono); font-size: 8px; letter-spacing: 2.5px; color: var(--orange); text-transform: uppercase; }
.reg-label em { color: var(--tx3); font-style: normal; }
.reg-input, .reg-select, .reg-textarea { background: var(--ink3); border: 1px solid var(--border2); border-radius: 2px; padding: 11px 14px; color: var(--tx); font-family: var(--font-body); font-size: 14px; transition: border-color .2s, box-shadow .2s; outline: none; width: 100%; }
.reg-input::placeholder, .reg-textarea::placeholder { color: var(--tx3); }
.reg-input:focus, .reg-select:focus, .reg-textarea:focus { border-color: rgba(232,120,0,0.5); box-shadow: 0 0 0 3px rgba(232,120,0,0.07); }
.reg-select { appearance: none; cursor: pointer; }
.reg-sel-wrap { position: relative; }
.reg-sel-wrap::after { content: '▾'; position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--tx3); pointer-events: none; font-size: 11px; }
.reg-textarea { resize: vertical; min-height: 72px; }
.reg-foot { padding: 18px 32px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.reg-submit { flex: 1; padding: 13px; border-radius: 2px; border: none; cursor: pointer; background: linear-gradient(135deg, #25D366, #128C7E); color: #fff; font-family: var(--font-body); font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all .2s var(--ease); box-shadow: 0 6px 20px rgba(37,211,102,0.3); letter-spacing: 1px; text-transform: uppercase; }
.reg-submit:hover { filter: brightness(1.08); transform: translateY(-1px); }
.reg-cancel { padding: 13px 20px; border-radius: 2px; cursor: pointer; background: transparent; border: 1px solid var(--border2); color: var(--tx2); font-family: var(--font-body); font-size: 13px; font-weight: 600; transition: all .2s; }
.reg-cancel:hover { background: var(--ink3); color: var(--tx); }
.reg-note { font-family: var(--font-mono); font-size: 9px; letter-spacing: 1px; color: var(--tx3); text-align: center; margin-top: 14px; }

/* ══════════════════════════════════════
   FOOTER
══════════════════════════════════════ */
.footer {
  background: var(--ink); border-top: 1px solid var(--border);
  padding: 48px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 24px;
}
.footer-brand { font-family: var(--font-display); font-size: 18px; letter-spacing: 5px; color: var(--tx3); }
.footer-links { display: flex; gap: 28px; flex-wrap: wrap; }
.footer-link { font-family: var(--font-mono); font-size: 9px; letter-spacing: 2px; color: var(--tx3); text-decoration: none; text-transform: uppercase; transition: color .2s; }
.footer-link:hover { color: var(--orange); }
.footer-copy { font-family: var(--font-mono); font-size: 9px; letter-spacing: 1.5px; color: var(--tx3); }

/* ══════════════════════════════════════
   WHATSAPP FLOAT
══════════════════════════════════════ */
.wa-float {
  position: fixed; bottom: 32px; right: 32px; z-index: 200;
  background: linear-gradient(135deg, #25D366, #128C7E);
  color: #fff; padding: 14px 24px; border-radius: 2px;
  font-family: var(--font-body); font-size: 13px; font-weight: 700;
  text-decoration: none; letter-spacing: .5px; text-transform: uppercase;
  box-shadow: 0 8px 32px rgba(37,211,102,0.3);
  display: flex; align-items: center; gap: 10px;
  transition: all .25s var(--ease);
  animation: fadeUp .8s var(--ease) 1.2s both;
}
.wa-float:hover { transform: translateY(-3px); box-shadow: 0 16px 48px rgba(37,211,102,0.4); }
.wa-float svg { width: 18px; height: 18px; flex-shrink: 0; }

/* ══════════════════════════════════════
   TOAST
══════════════════════════════════════ */
.toast {
  position: fixed; bottom: 100px; right: 32px; z-index: 300;
  background: var(--ink3); border: 1px solid rgba(232,120,0,0.25);
  color: var(--tx); padding: 14px 20px; border-radius: 2px;
  font-family: var(--font-body); font-size: 13px;
  box-shadow: 0 8px 32px rgba(0,0,0,.5);
  transform: translateY(20px); opacity: 0;
  transition: all .3s var(--ease); pointer-events: none;
  max-width: 320px;
}
.toast.show { transform: none; opacity: 1; }

/* ══════════════════════════════════════
   SCROLL REVEAL
══════════════════════════════════════ */
.reveal { opacity: 0; transform: translateY(32px); transition: opacity .8s var(--ease), transform .8s var(--ease); }
.reveal.visible { opacity: 1; transform: none; }
.reveal-d1 { transition-delay: .1s; }
.reveal-d2 { transition-delay: .2s; }
.reveal-d3 { transition-delay: .3s; }

/* ══════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════ */
@media (max-width: 1100px) {
  .pricing-grid { grid-template-columns: repeat(2,1fr); }
  .features-layout { grid-template-columns: 1fr; }
  .feat-block:nth-child(even) { border-right: none; }
}
@media (max-width: 900px) {
  .nav { padding: 0 24px; }
  .nav-links { display: none; }
  .process-strip { grid-template-columns: 1fr; }
  .process-strip::before { display: none; }
  .process-step { border-right: none; border-bottom: 1px solid var(--border); }
  .process-step:last-child { border-bottom: none; }
  .install-grid { grid-template-columns: 1fr; }
  .testi-grid { grid-template-columns: 1fr; }
  .portal-grid { grid-template-columns: 1fr; }
  .pricing-grid { grid-template-columns: 1fr; }
  .features-section, .pricing-section, .process-section, .install-section, .testi-section, .cta-section { padding: 80px 24px; }
  .footer { padding: 32px 24px; }
}
@media (max-width: 600px) {
  .hero { padding: 100px 24px 80px; }
  .hero-meta { gap: 24px; }
  .hero-meta-divider { display: none; }
  .wa-float span { display: none; }
  .wa-float { padding: 14px; border-radius: 50%; }
  .reg-grid { grid-template-columns: 1fr; }
  .reg-field.full { grid-column: auto; }
}
</style>
</head>
<body>

<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<div class="page">

<!-- NAV -->
<nav class="nav">
  <a href="#" class="nav-brand">
    <div class="nav-logo-mark"><span>NT</span></div>
    <div class="nav-wordmark">
      <div class="nav-name">NYMIX</div>
      <div class="nav-sub">TECH · KE</div>
    </div>
  </a>
  <div class="nav-links">
    <a href="#features" class="nav-link">Features</a>
    <a href="#pricing" class="nav-link">Pricing</a>
    <a href="#process" class="nav-link">How It Works</a>
    <a href="#install" class="nav-link">Download</a>
  </div>
  <div class="nav-right">
    <div class="nav-status"><div class="nav-status-dot"></div>Live</div>
    <button class="nav-cta" onclick="openPortalOverlay()">Access Portal →</button>
  </div>
</nav>

<!-- HERO -->
<section class="hero" id="home">
  <div class="hero-lines">
    <div class="hero-line"></div>
    <div class="hero-line"></div>
    <div class="hero-line"></div>
    <div class="hero-vline"></div>
    <div class="hero-vline"></div>
  </div>

  <div class="hero-tag">Business Management · Kenya · Est <?= $year ?></div>

  <h1 class="hero-title">
    <span class="fill">NYM</span><span class="stroke">IX</span>
  </h1>
  <div class="hero-title-row2">BUSINESS PLATFORM</div>

  <div class="hero-meta">
    <div class="hero-stat">
      <div class="hero-stat-num">2</div>
      <div class="hero-stat-label">Portals</div>
    </div>
    <div class="hero-meta-divider"></div>
    <div class="hero-stat">
      <div class="hero-stat-num">24/7</div>
      <div class="hero-stat-label">Uptime Target</div>
    </div>
    <div class="hero-meta-divider"></div>
    <div class="hero-stat">
      <div class="hero-stat-num">M-PESA</div>
      <div class="hero-stat-label">Integrated</div>
    </div>
    <div class="hero-meta-divider"></div>
    <div class="hero-stat">
      <div class="hero-stat-num">∞</div>
      <div class="hero-stat-label">Branches</div>
    </div>
  </div>

  <p class="hero-desc">
    One integrated platform to run your business — point of sale, inventory, branches, billing, and real-time reporting. Built for Kenyan businesses.
  </p>

  <div class="hero-cta-row">
    <button class="hero-btn-primary" onclick="openPortalOverlay()">
      Access Your Portal →
    </button>
    <a href="#features" class="hero-btn-outline">Explore Features</a>
    <button class="hero-btn-outline" onclick="openRegisterModal()">Register Business</button>
  </div>

  <div class="scroll-indicator">
    <span>Scroll</span>
    <div class="scroll-track"></div>
  </div>
</section>

<!-- TICKER -->
<div class="ticker-wrap">
  <div class="ticker">
    <span class="ticker-item accent">POS System</span>
    <span class="ticker-item">Inventory Management</span>
    <span class="ticker-item accent">M-Pesa Integration</span>
    <span class="ticker-item">Multi-Branch Control</span>
    <span class="ticker-item accent">Real-Time Reports</span>
    <span class="ticker-item">Supplier Management</span>
    <span class="ticker-item accent">Staff Portals</span>
    <span class="ticker-item">Support Tickets</span>
    <span class="ticker-item accent">Role-Based Access</span>
    <span class="ticker-item">Customer CRM</span>
    <!-- Duplicate for seamless loop -->
    <span class="ticker-item accent">POS System</span>
    <span class="ticker-item">Inventory Management</span>
    <span class="ticker-item accent">M-Pesa Integration</span>
    <span class="ticker-item">Multi-Branch Control</span>
    <span class="ticker-item accent">Real-Time Reports</span>
    <span class="ticker-item">Supplier Management</span>
    <span class="ticker-item accent">Staff Portals</span>
    <span class="ticker-item">Support Tickets</span>
    <span class="ticker-item accent">Role-Based Access</span>
    <span class="ticker-item">Customer CRM</span>
  </div>
</div>

<!-- FEATURES -->
<section class="features-section" id="features">
  <div class="reveal">
    <div class="section-label">Platform Features</div>
    <div class="section-heading">EVERYTHING YOUR<br><span class="dim">BUSINESS NEEDS</span></div>
    <p class="section-body" style="max-width:500px;margin-bottom:0">A complete management suite — no third-party tools needed.</p>
  </div>
  <div class="features-layout reveal" style="margin-top:64px">
    <div class="feat-block">
      <div class="feat-num">01</div>
      <span class="feat-icon-lg">🧾</span>
      <div class="feat-name">POINT OF SALE</div>
      <div class="feat-desc">Barcode scanning, cart management, M-Pesa and cash payments, instant receipts, held sales and customer credit.</div>
      <div class="feat-tags"><span class="feat-tag">Barcode</span><span class="feat-tag">M-Pesa</span><span class="feat-tag">Receipts</span></div>
    </div>
    <div class="feat-block">
      <div class="feat-num">02</div>
      <span class="feat-icon-lg">📦</span>
      <div class="feat-name">INVENTORY</div>
      <div class="feat-desc">Real-time stock levels, low-stock alerts, branch-to-branch transfers, stock adjustments with full audit trail.</div>
      <div class="feat-tags"><span class="feat-tag">Real-Time</span><span class="feat-tag">Alerts</span><span class="feat-tag">Transfers</span></div>
    </div>
    <div class="feat-block">
      <div class="feat-num">03</div>
      <span class="feat-icon-lg">🚚</span>
      <div class="feat-name">PURCHASE ORDERS</div>
      <div class="feat-desc">Generate LPOs, receive stock directly into inventory, track supplier balances and pending deliveries.</div>
      <div class="feat-tags"><span class="feat-tag">LPO</span><span class="feat-tag">Suppliers</span><span class="feat-tag">Receiving</span></div>
    </div>
    <div class="feat-block">
      <div class="feat-num">04</div>
      <span class="feat-icon-lg">👥</span>
      <div class="feat-name">CUSTOMER CRM</div>
      <div class="feat-desc">Customer credit accounts, balance tracking, payment collection, purchase history per branch.</div>
      <div class="feat-tags"><span class="feat-tag">Credit</span><span class="feat-tag">History</span><span class="feat-tag">Collections</span></div>
    </div>
    <div class="feat-block">
      <div class="feat-num">05</div>
      <span class="feat-icon-lg">📊</span>
      <div class="feat-name">ANALYTICS</div>
      <div class="feat-desc">Daily, weekly, and monthly sales reports, stock valuation, branch performance comparisons, CSV exports.</div>
      <div class="feat-tags"><span class="feat-tag">Reports</span><span class="feat-tag">Valuation</span><span class="feat-tag">CSV Export</span></div>
    </div>
    <div class="feat-block">
      <div class="feat-num">06</div>
      <span class="feat-icon-lg">🔐</span>
      <div class="feat-name">ROLE ACCESS</div>
      <div class="feat-desc">Owner, Manager, Cashier, Stock Clerk roles — each with fine-grained permissions per portal and branch.</div>
      <div class="feat-tags"><span class="feat-tag">Owner</span><span class="feat-tag">Staff</span><span class="feat-tag">Permissions</span></div>
    </div>
  </div>
</section>

<!-- PRICING -->
<section class="pricing-section" id="pricing">
  <div class="pricing-inner">
    <div class="pricing-header reveal">
      <div class="section-label">Subscription Plans</div>
      <div class="section-heading">SIMPLE,<br><span class="dim">TRANSPARENT PRICING</span></div>
      <p class="section-body" style="max-width:480px;margin-top:16px">All plans include core POS, inventory, and reporting. Pay monthly, cancel anytime.</p>
    </div>
    <div class="pricing-grid reveal">
      <!-- STARTER -->
      <div class="plan-card">
        <div class="plan-tier">Tier 01</div>
        <div class="plan-name">STARTER</div>
        <div class="plan-price-row"><span class="plan-kes">KES</span><span class="plan-amount">1,500</span></div>
        <div class="plan-cycle">/ month</div>
        <div class="plan-branches">🏪 1 Branch included</div>
        <div class="plan-divider"></div>
        <ul class="plan-feats">
          <li><span class="f-check">✓</span> Point of Sale (POS)</li>
          <li><span class="f-check">✓</span> Inventory Management</li>
          <li><span class="f-check">✓</span> Customer Accounts</li>
          <li><span class="f-check">✓</span> Basic Reports</li>
          <li><span class="f-check">✓</span> M-Pesa Payments</li>
          <li class="off"><span class="f-x">✗</span> Purchase Orders</li>
          <li class="off"><span class="f-x">✗</span> Branch Transfers</li>
          <li class="off"><span class="f-x">✗</span> Support Tickets</li>
        </ul>
        <div class="plan-addon">➕ Extra branch: <strong>+KES 500/mo</strong></div>
        <button class="plan-btn plan-btn-outline" onclick="openRegisterModal('Starter')">GET STARTED →</button>
      </div>
      <!-- BASIC -->
      <div class="plan-card">
        <div class="plan-tier">Tier 02</div>
        <div class="plan-name">BASIC</div>
        <div class="plan-price-row"><span class="plan-kes">KES</span><span class="plan-amount">1,500</span></div>
        <div class="plan-cycle">/ month</div>
        <div class="plan-branches">🏪 1 Branch included</div>
        <div class="plan-divider"></div>
        <ul class="plan-feats">
          <li><span class="f-check">✓</span> Everything in Starter</li>
          <li><span class="f-check">✓</span> Purchase Orders (LPOs)</li>
          <li><span class="f-check">✓</span> Supplier Management</li>
          <li><span class="f-check">✓</span> Stock Adjustments</li>
          <li><span class="f-check">✓</span> Advanced Reports</li>
          <li><span class="f-check">✓</span> CSV Exports</li>
          <li class="off"><span class="f-x">✗</span> Branch Transfers</li>
          <li class="off"><span class="f-x">✗</span> Priority Support</li>
        </ul>
        <div class="plan-addon">➕ Extra branch: <strong>+KES 500/mo</strong></div>
        <button class="plan-btn plan-btn-outline" onclick="openRegisterModal('Basic')">GET STARTED →</button>
      </div>
      <!-- PRO -->
      <div class="plan-card highlighted">
        <span class="plan-badge">⭐ Most Popular</span>
        <div class="plan-tier">Tier 03</div>
        <div class="plan-name">PRO</div>
        <div class="plan-price-row"><span class="plan-kes">KES</span><span class="plan-amount">3,500</span></div>
        <div class="plan-cycle">/ month</div>
        <div class="plan-branches">🏪 Up to 3 Branches</div>
        <div class="plan-divider"></div>
        <ul class="plan-feats">
          <li><span class="f-check">✓</span> Everything in Basic</li>
          <li><span class="f-check">✓</span> Branch-to-Branch Transfers</li>
          <li><span class="f-check">✓</span> Multi-Branch Reports</li>
          <li><span class="f-check">✓</span> Role-Based Staff Access</li>
          <li><span class="f-check">✓</span> Support Ticket System</li>
          <li><span class="f-check">✓</span> Stock Valuation Reports</li>
          <li><span class="f-check">✓</span> Priority Support</li>
          <li><span class="f-check">✓</span> Android App Access</li>
        </ul>
        <div class="plan-addon">➕ Extra branch: <strong>+KES 750/mo</strong></div>
        <button class="plan-btn plan-btn-fill" onclick="openRegisterModal('Professional')">CHOOSE PRO →</button>
      </div>
      <!-- ENTERPRISE -->
      <div class="plan-card">
        <div class="plan-tier">Tier 04</div>
        <div class="plan-name">ENTERPRISE</div>
        <div class="plan-price-row"><span class="plan-kes">KES</span><span class="plan-amount">7,000</span></div>
        <div class="plan-cycle">/ month</div>
        <div class="plan-branches">🏪 Unlimited Branches</div>
        <div class="plan-divider"></div>
        <ul class="plan-feats">
          <li><span class="f-check">✓</span> Everything in Pro</li>
          <li><span class="f-check">✓</span> Unlimited Branches</li>
          <li><span class="f-check">✓</span> Dedicated Onboarding</li>
          <li><span class="f-check">✓</span> Custom Integrations</li>
          <li><span class="f-check">✓</span> API Access</li>
          <li><span class="f-check">✓</span> SLA Guarantee</li>
          <li><span class="f-check">✓</span> WhatsApp Direct Support</li>
          <li><span class="f-check">✓</span> Free Staff Training</li>
        </ul>
        <div class="plan-addon">🏢 Branches: <strong>Unlimited included</strong></div>
        <button class="plan-btn plan-btn-outline" onclick="openRegisterModal('Enterprise')">CONTACT SALES →</button>
      </div>
    </div>
    <div class="pricing-note">All prices in <strong>Kenyan Shillings (KES)</strong> · VAT may apply · <strong>30-day free trial</strong> available — contact us via WhatsApp</div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section class="process-section" id="process">
  <div class="reveal">
    <div class="section-label">Getting Started</div>
    <div class="section-heading">HOW IT<br><span class="dim">WORKS</span></div>
    <p class="section-body" style="max-width:440px;margin-top:16px">Up and running in three steps — no technical expertise needed.</p>
  </div>
  <div class="process-strip reveal">
    <div class="process-step">
      <div class="step-circle">01</div>
      <div class="step-label">Step One</div>
      <div class="step-title">REGISTER</div>
      <div class="step-desc">Contact NYMIX TECH via WhatsApp to register your business. We set up your account, plan, and first branch within 24 hours.</div>
    </div>
    <div class="process-step">
      <div class="step-circle">02</div>
      <div class="step-label">Step Two</div>
      <div class="step-title">ACTIVATE</div>
      <div class="step-desc">Use your Business PIN to activate your owner portal. Add staff users, set their roles, and configure your branch details.</div>
    </div>
    <div class="process-step">
      <div class="step-circle">03</div>
      <div class="step-label">Step Three</div>
      <div class="step-title">OPERATE</div>
      <div class="step-desc">Staff log in to run daily operations — POS, stock, purchases — while you monitor everything from the Owner Portal.</div>
    </div>
  </div>
</section>

<!-- DOWNLOAD -->
<section class="install-section" id="install">
  <div class="install-inner">
    <div class="reveal">
      <div class="section-label">Download The App</div>
      <div class="section-heading">GET NYMIX<br><span class="dim">ON ANY DEVICE</span></div>
      <p class="section-body" style="max-width:460px;margin-top:16px">Download the native app for Windows or Android. iPhone users access the platform via web.</p>
    </div>
    <div class="install-grid reveal">
      <!-- WINDOWS -->
      <div class="install-card">
        <span class="install-os-icon">🖥️</span>
        <div class="install-platform">WINDOWS</div>
        <div class="install-os">Windows 10 / 11</div>
        <div class="file-badge">📦 .exe installer</div>
        <ul class="install-steps">
          <li><span class="step-n">01</span> Click Download below</li>
          <li><span class="step-n">02</span> Open the downloaded <strong style="color:var(--tx)">NYMIX-TECH-windows.exe</strong></li>
          <li><span class="step-n">03</span> If SmartScreen appears, click <strong style="color:var(--tx)">Run Anyway</strong></li>
          <li><span class="step-n">04</span> Follow the installer — done!</li>
        </ul>
        <div class="dl-prog-wrap" id="dl-prog-win"><div class="dl-prog-bar" id="dl-bar-win"></div></div>
        <a href="https://nymix-tech.top/NYMIX-TECH-windows.exe" download="NYMIX-TECH-windows.exe"
           class="install-btn install-btn-fill" onclick="animateDl('win')">
          ⬇ Download for Windows
        </a>
      </div>
      <!-- ANDROID -->
      <div class="install-card">
        <span class="install-os-icon">📱</span>
        <div class="install-platform">ANDROID</div>
        <div class="install-os">Android 7.0+</div>
        <div class="file-badge">📦 APK · 68 MB</div>
        <ul class="install-steps">
          <li><span class="step-n">01</span> Click Download below</li>
          <li><span class="step-n">02</span> Open the downloaded <strong style="color:var(--tx)">.apk</strong> file</li>
          <li><span class="step-n">03</span> Allow <strong style="color:var(--tx)">Install from unknown sources</strong> if prompted</li>
          <li><span class="step-n">04</span> Tap Install — NYMIX is on your phone!</li>
        </ul>
        <div class="dl-prog-wrap" id="dl-prog-apk"><div class="dl-prog-bar" id="dl-bar-apk"></div></div>
        <a href="https://nymix-tech.top/159509_Nymix (1).apk" download="NYMIX-TECH.apk"
           class="install-btn install-btn-fill" onclick="animateDl('apk')">
          ⬇ Download for Android
        </a>
      </div>
      <!-- IPHONE -->
      <div class="install-card">
        <span class="install-os-icon">🍎</span>
        <div class="install-platform">IPHONE</div>
        <div class="install-os">Safari Browser</div>
        <div class="file-badge">🌐 Web Access</div>
        <ul class="install-steps">
          <li><span class="step-n">01</span> Open this site in <strong style="color:var(--tx)">Safari</strong></li>
          <li><span class="step-n">02</span> Tap the Share button <strong style="color:var(--tx)">□↑</strong></li>
          <li><span class="step-n">03</span> Tap <strong style="color:var(--tx)">"Add to Home Screen"</strong></li>
          <li><span class="step-n">04</span> Tap Add — shortcut on home screen</li>
        </ul>
        <a href="#" onclick="openPortalOverlay(); return false;" class="install-btn install-btn-ghost">
          🌐 Access Web Portal
        </a>
      </div>
    </div>
  </div>
</section>

<!-- TESTIMONIALS -->
<section class="testi-section">
  <div class="reveal">
    <div class="section-label">Client Feedback</div>
    <div class="section-heading">WHAT OUR<br><span class="dim">CLIENTS SAY</span></div>
  </div>
  <div class="testi-grid reveal">
    <div class="testi-card">
      <div class="testi-quote-mark">"</div>
      <div class="testi-stars">★★★★★</div>
      <p class="testi-text">NYMIX transformed how we run our hardware shop. The POS is fast, the inventory tracking is accurate, and the M-Pesa integration means we never miss a payment. Best investment we've made.</p>
      <div class="testi-author">
        <div class="testi-avatar">JK</div>
        <div>
          <div class="testi-name">James Kariuki</div>
          <div class="testi-role">Owner · Kariuki Hardware · Nakuru</div>
        </div>
        <div class="testi-plan">PRO</div>
      </div>
    </div>
    <div class="testi-card">
      <div class="testi-quote-mark">"</div>
      <div class="testi-stars">★★★★★</div>
      <p class="testi-text">Managing 3 branches used to be a nightmare. Now I see every sale, every stock movement from one dashboard. The staff portal keeps my team accountable and it's so easy to use.</p>
      <div class="testi-author">
        <div class="testi-avatar">WN</div>
        <div>
          <div class="testi-name">Wanjiku Njoroge</div>
          <div class="testi-role">Director · BuildRight Supplies · Nairobi</div>
        </div>
        <div class="testi-plan">ENTERPRISE</div>
      </div>
    </div>
    <div class="testi-card">
      <div class="testi-quote-mark">"</div>
      <div class="testi-stars">★★★★★</div>
      <p class="testi-text">Setup was done within 24 hours as promised. The team walked us through everything and the support via WhatsApp has been incredible. Our cashiers love how simple the POS is.</p>
      <div class="testi-author">
        <div class="testi-avatar">PM</div>
        <div>
          <div class="testi-name">Peter Mutua</div>
          <div class="testi-role">Manager · Mutua Agro Supplies · Machakos</div>
        </div>
        <div class="testi-plan">PRO</div>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta-section">
  <div class="cta-bg-text">NYMIX</div>
  <div class="cta-eyebrow reveal">Ready to grow?</div>
  <div class="cta-heading reveal">READY TO GET<br>STARTED?</div>
  <p class="cta-sub reveal">Choose your portal, download the app, or register your business today.</p>
  <div class="cta-buttons reveal">
    <button class="hero-btn-primary" onclick="openPortalOverlay()">🏢 Access Portal</button>
    <a href="#install" class="hero-btn-outline">⬇ Download App</a>
    <button class="hero-btn-outline" onclick="openRegisterModal()">💬 Register Business</button>
  </div>
</section>

<!-- ══════════════════════════════════
     PORTAL OVERLAY
══════════════════════════════════ -->
<div class="portal-overlay" id="modal-portal" onclick="handlePortalClick(event)">
  <div class="portal-overlay-box">
    <button class="portal-close" onclick="closePortalOverlay()">✕</button>
    <div class="portal-head">
      <div class="portal-eyebrow">NYMIX TECH · Secure Access</div>
      <div class="portal-title">CHOOSE YOUR<br>ROLE</div>
      <p class="portal-sub">Select your portal to continue. Your session will be secured.</p>
    </div>
    <div class="portal-grid">
      <a href="nymix_hardwares/client_portal.php" class="portal-card portal-client" onclick="return checkTerms(this.href)">
        <span class="portal-card-icon">🏢</span>
        <div class="portal-tag">Business Owner</div>
        <div class="portal-name">CLIENT PORTAL</div>
        <p class="portal-desc">Manage subscriptions, invoices, branches, users and support tickets from your owner dashboard.</p>
        <div class="portal-perms">
          <span class="portal-perm">Branches</span>
          <span class="portal-perm">Users</span>
          <span class="portal-perm">Invoices</span>
          <span class="portal-perm">Payments</span>
          <span class="portal-perm">Support</span>
        </div>
        <div class="portal-action">SIGN IN AS OWNER <span>→</span></div>
      </a>
      <a href="nymix_hardwares/staff_portal.php" class="portal-card portal-staff" onclick="return checkTerms(this.href)">
        <span class="portal-card-icon">⚡</span>
        <div class="portal-tag">Staff & Cashier</div>
        <div class="portal-name">STAFF PORTAL</div>
        <p class="portal-desc">Daily operations — POS, stock management, purchase orders, customers and branch reports.</p>
        <div class="portal-perms">
          <span class="portal-perm">POS</span>
          <span class="portal-perm">Inventory</span>
          <span class="portal-perm">Purchases</span>
          <span class="portal-perm">Customers</span>
          <span class="portal-perm">Reports</span>
        </div>
        <div class="portal-action">SIGN IN AS STAFF <span>→</span></div>
      </a>
    </div>
    <div class="portal-note">
      New to NYMIX? <a href="#" onclick="closePortalOverlay(); openRegisterModal(); return false;">Register your business</a>
      &nbsp;·&nbsp; Need help? <a href="https://wa.me/254797583976" target="_blank" rel="noopener">WhatsApp us</a>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════
     TERMS MODAL
══════════════════════════════════ -->
<div class="modal-overlay" id="modal-terms" style="display:none">
  <div class="modal-box">
    <div class="modal-head">
      <div>
        <div class="modal-title">TERMS &amp; CONDITIONS</div>
        <div class="modal-title-sub">NYMIX TECH · SOFTWARE LICENSE AGREEMENT</div>
      </div>
      <div style="font-family:var(--font-mono);font-size:9px;color:var(--tx3);letter-spacing:1px">v1.0 · <?= $year ?></div>
    </div>
    <div class="modal-body">
      <div class="modal-warn">⚠️ Please read these terms carefully before accessing the NYMIX Business Management Platform.</div>
      <h3>1. ACCEPTANCE OF TERMS</h3>
      <p>By accessing or using the NYMIX Business Management Platform ("Platform"), you agree to be bound by these Terms and Conditions. If you do not agree, you must not access or use the Platform.</p>
      <h3>2. LICENSE &amp; ACCESS</h3>
      <p>NYMIX TECH grants you a limited, non-exclusive, non-transferable, revocable license to access and use the Platform solely for your internal business operations. You may not sublicense, resell, or transfer access to any third party without prior written consent.</p>
      <h3>3. SUBSCRIPTION &amp; BILLING</h3>
      <p>Access is provided on a subscription basis. Fees are billed as agreed during onboarding. NYMIX TECH reserves the right to suspend access upon non-payment. All fees are non-refundable unless otherwise agreed in writing.</p>
      <h3>4. DATA OWNERSHIP &amp; PRIVACY</h3>
      <p>All business data entered into the Platform remains the property of the subscribing business. NYMIX TECH will not sell or share your data without consent, except as required by law.</p>
      <h3>5. ACCEPTABLE USE</h3>
      <p>You agree not to use the Platform for any unlawful purpose, attempt unauthorized access, reverse engineer any portion of the software, or introduce malicious code. Violations result in immediate termination.</p>
      <h3>6. UPTIME &amp; AVAILABILITY</h3>
      <p>NYMIX TECH aims for high availability but does not guarantee uninterrupted access. NYMIX TECH shall not be liable for losses arising from service unavailability.</p>
      <h3>7. LIMITATION OF LIABILITY</h3>
      <p>NYMIX TECH shall not be liable for any indirect, incidental, or consequential damages. Total liability shall not exceed subscription fees paid in the preceding 3 months.</p>
      <h3>8. TERMINATION</h3>
      <p>Either party may terminate with 30 days written notice. NYMIX TECH may terminate immediately for breach. Upon termination, you may request a data export within 14 days.</p>
      <h3>9. GOVERNING LAW</h3>
      <p>These Terms are governed by the laws of the Republic of Kenya. Disputes are subject to the exclusive jurisdiction of the courts of Nairobi, Kenya.</p>
    </div>
    <div class="modal-foot">
      <div class="modal-copy">© <?= $year ?> NYMIX TECH · All rights reserved</div>
      <div class="modal-btns">
        <button class="btn-decline" onclick="declineTerms()">Decline</button>
        <button class="btn-accept" onclick="acceptTerms()">I Accept ✓</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════
     REGISTRATION MODAL
══════════════════════════════════ -->
<div class="reg-overlay" id="modal-register">
  <div class="reg-box">
    <div class="reg-head">
      <div>
        <div class="reg-title">REGISTER YOUR BUSINESS</div>
        <div class="reg-title-sub">NYMIX TECH · ONBOARDING FORM</div>
      </div>
      <div class="reg-close" onclick="closeRegisterModal()">✕</div>
    </div>
    <div class="reg-body">
      <div class="reg-grid" id="reg-form">
        <div class="reg-field">
          <label class="reg-label">Business Name <span>*</span></label>
          <input type="text" class="reg-input" id="reg-biz-name" placeholder="e.g. Kariuki Hardware" required>
        </div>
        <div class="reg-field">
          <label class="reg-label">Your Name <span>*</span></label>
          <input type="text" class="reg-input" id="reg-owner-name" placeholder="e.g. James Kariuki" required>
        </div>
        <div class="reg-field">
          <label class="reg-label">Phone Number <span>*</span></label>
          <input type="tel" class="reg-input" id="reg-phone" placeholder="e.g. 0712 345 678" required>
        </div>
        <div class="reg-field">
          <label class="reg-label">Location / Town <span>*</span></label>
          <input type="text" class="reg-input" id="reg-location" placeholder="e.g. Nakuru Town" required>
        </div>
        <div class="reg-field">
          <label class="reg-label">Number of Branches <span>*</span></label>
          <div class="reg-sel-wrap">
            <select class="reg-select reg-input" id="reg-branches">
              <option value="" disabled selected>Select…</option>
              <option value="1">1 Branch</option>
              <option value="2">2 Branches</option>
              <option value="3">3 Branches</option>
              <option value="4–6">4–6 Branches</option>
              <option value="7+">7+ Branches</option>
            </select>
          </div>
        </div>
        <div class="reg-field">
          <label class="reg-label">Business Type <span>*</span></label>
          <div class="reg-sel-wrap">
            <select class="reg-select reg-input" id="reg-industry">
              <option value="" disabled selected>Select…</option>
              <option value="Hardware Store">🔩 Hardware Store</option>
              <option value="Building Materials">🧱 Building Materials</option>
              <option value="Electrical & Plumbing">⚡ Electrical &amp; Plumbing</option>
              <option value="Paint & Décor">🎨 Paint &amp; Décor</option>
              <option value="Agro / Farm Supplies">🌱 Agro / Farm Supplies</option>
              <option value="General Retail">🛒 General Retail</option>
              <option value="Wholesale / Distribution">🚛 Wholesale / Distribution</option>
              <option value="Other">📦 Other</option>
            </select>
          </div>
        </div>
        <div class="reg-field">
          <label class="reg-label">Interested Plan <em>(optional)</em></label>
          <div class="reg-sel-wrap">
            <select class="reg-select reg-input" id="reg-plan">
              <option value="">Not sure yet</option>
              <option value="Starter">Starter — KES 1,500/mo</option>
              <option value="Basic">Basic — KES 1,500/mo</option>
              <option value="Professional">Pro — KES 3,500/mo</option>
              <option value="Enterprise">Enterprise — KES 7,000/mo</option>
            </select>
          </div>
        </div>
        <div class="reg-field">
          <label class="reg-label">How did you hear about us? <em>(optional)</em></label>
          <div class="reg-sel-wrap">
            <select class="reg-select reg-input" id="reg-source">
              <option value="">Select…</option>
              <option value="WhatsApp / Word of mouth">💬 WhatsApp / Word of mouth</option>
              <option value="Referred by a friend">👥 Referred by a friend</option>
              <option value="Facebook / Social media">📱 Facebook / Social media</option>
              <option value="Google Search">🔍 Google Search</option>
              <option value="NYMIX Sales Agent">🤝 NYMIX Sales Agent</option>
              <option value="Other">📌 Other</option>
            </select>
          </div>
        </div>
        <div class="reg-field full">
          <label class="reg-label">Additional Notes <em>(optional)</em></label>
          <textarea class="reg-textarea reg-input" id="reg-notes" placeholder="Any specific requirements, questions, or details about your business…"></textarea>
        </div>
      </div>
      <div class="reg-note">✅ Your details will be sent to our team via WhatsApp. We'll respond within a few hours.</div>
    </div>
    <div class="reg-foot">
      <button class="reg-cancel" onclick="closeRegisterModal()">Cancel</button>
      <button class="reg-submit" onclick="submitRegForm()">
        <svg viewBox="0 0 24 24" fill="currentColor" width="17" height="17"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
        Send via WhatsApp
      </button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast"></div>

<!-- WA FLOAT -->
<a href="#" class="wa-float" onclick="openRegisterModal(); return false;">
  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
  <span>Register My Business</span>
</a>

<!-- FOOTER -->
<footer class="footer">
  <div class="footer-brand">NYMIX TECH</div>
  <div class="footer-links">
    <a href="#" class="footer-link" onclick="openPortalOverlay(); return false;">Client Portal</a>
    <a href="#" class="footer-link" onclick="openPortalOverlay(); return false;">Staff Portal</a>
    <a href="#pricing" class="footer-link">Pricing</a>
    <a href="#install" class="footer-link">Download</a>
    <a href="#" class="footer-link" onclick="openRegisterModal(); return false;">Register</a>
  </div>
  <div class="footer-copy">© <?= $year ?> NYMIX TECH · All rights reserved</div>
</footer>

</div>

<script>
/* ═════════════════════════════
   PORTAL
═════════════════════════════ */
function openPortalOverlay() {
  document.getElementById('modal-portal').classList.add('active');
  document.body.style.overflow = 'hidden';
}
function closePortalOverlay() {
  document.getElementById('modal-portal').classList.remove('active');
  document.body.style.overflow = '';
}
function handlePortalClick(e) {
  if (e.target === document.getElementById('modal-portal')) closePortalOverlay();
}

/* ═════════════════════════════
   TERMS
═════════════════════════════ */
var _accepted = false, _pendingUrl = null;
function getCookie(n) { return document.cookie.split(';').some(c => c.trim().startsWith(n+'=')); }
function showTerms() { document.getElementById('modal-terms').style.display='flex'; document.body.style.overflow='hidden'; }
function acceptTerms() {
  _accepted = true;
  sessionStorage.setItem('nx_terms','1');
  var exp = new Date(Date.now()+30*24*60*60*1000).toUTCString();
  document.cookie = 'nx_terms_ok=1; expires='+exp+'; path=/; SameSite=Strict';
  document.getElementById('modal-terms').style.display='none';
  document.body.style.overflow='';
  if (_pendingUrl) { window.location.href = _pendingUrl; _pendingUrl = null; }
}
function declineTerms() {
  _pendingUrl = null;
  document.getElementById('modal-terms').style.display='none';
  document.body.style.overflow='';
}
function checkTerms(url) {
  if (_accepted) return true;
  _pendingUrl = url; showTerms(); return false;
}
document.addEventListener('DOMContentLoaded', function() {
  if (getCookie('nx_terms_ok') || sessionStorage.getItem('nx_terms')==='1') _accepted = true;
  else showTerms();
});

/* ═════════════════════════════
   REGISTER MODAL
═════════════════════════════ */
function openRegisterModal(plan) {
  var m = document.getElementById('modal-register');
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  if (plan) {
    var sel = document.getElementById('reg-plan');
    for (var i=0;i<sel.options.length;i++) if (sel.options[i].value===plan) { sel.selectedIndex=i; break; }
  }
}
function closeRegisterModal() {
  document.getElementById('modal-register').style.display = 'none';
  document.body.style.overflow = '';
}
document.getElementById('modal-register').addEventListener('click', function(e) {
  if (e.target===this) closeRegisterModal();
});

function submitRegForm() {
  var biz  = document.getElementById('reg-biz-name').value.trim();
  var own  = document.getElementById('reg-owner-name').value.trim();
  var tel  = document.getElementById('reg-phone').value.trim();
  var loc  = document.getElementById('reg-location').value.trim();
  var br   = document.getElementById('reg-branches').value;
  var ind  = document.getElementById('reg-industry').value;
  var pl   = document.getElementById('reg-plan').value;
  var src  = document.getElementById('reg-source').value;
  var nt   = document.getElementById('reg-notes').value.trim();

  if (!biz||!own||!tel||!loc||!br||!ind) { showToast('⚠️ Please fill in all required fields.'); return; }

  var msg = "Hello NYMIX TECH 👋\n\nI'd like to register my business on the NYMIX Platform.\n\n"
    + "━━━━━━━━━━━━━━━━━━━━\n"
    + "🏢 Business Name: " + biz + "\n"
    + "👤 Owner Name: " + own + "\n"
    + "📞 Phone: " + tel + "\n"
    + "📍 Location / Town: " + loc + "\n"
    + "🏪 No. of Branches: " + br + "\n"
    + "🔩 Business Type: " + ind + "\n"
    + (pl  ? "💳 Interested Plan: " + pl + "\n" : "")
    + (src ? "📢 Heard via: " + src + "\n" : "")
    + (nt  ? "\n💬 Notes:\n" + nt + "\n" : "")
    + "━━━━━━━━━━━━━━━━━━━━\n\nPlease guide me on the next steps. Thank you!";

  window.open('https://wa.me/254797583976?text='+encodeURIComponent(msg), '_blank');
  closeRegisterModal();
  showToast('✅ WhatsApp opened! Send the message to complete registration.');
}

/* ═════════════════════════════
   DOWNLOAD PROGRESS
═════════════════════════════ */
function animateDl(type) {
  var wrap = document.getElementById('dl-prog-'+type);
  var bar  = document.getElementById('dl-bar-'+type);
  if (!wrap||!bar) return;
  wrap.style.display='block'; bar.style.width='0%';
  var pct=0, iv=setInterval(function(){
    pct += Math.random()*18;
    if(pct>=95){pct=95;clearInterval(iv);}
    bar.style.width=pct+'%';
  },200);
  setTimeout(function(){
    clearInterval(iv); bar.style.width='100%';
    showToast('✅ Download started! Check your Downloads folder.');
    setTimeout(function(){wrap.style.display='none';},3000);
  },2000);
}

/* ═════════════════════════════
   TOAST
═════════════════════════════ */
function showToast(msg) {
  var t = document.getElementById('toast');
  t.textContent=msg; t.classList.add('show');
  setTimeout(function(){t.classList.remove('show');},4000);
}

/* ═════════════════════════════
   KEYBOARD ESC
═════════════════════════════ */
document.addEventListener('keydown', function(e) {
  if (e.key==='Escape') { closePortalOverlay(); closeRegisterModal(); }
});

/* ═════════════════════════════
   SERVICE WORKER
═════════════════════════════ */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('nymix_hardwares/sw.js')
      .then(r=>console.log('SW:',r.scope))
      .catch(e=>console.log('SW fail:',e));
  });
}

/* ═════════════════════════════
   SCROLL REVEAL
═════════════════════════════ */
var revObs = new IntersectionObserver(function(entries){
  entries.forEach(function(e){
    if(e.isIntersecting){e.target.classList.add('visible');revObs.unobserve(e.target);}
  });
},{threshold:0.08});
document.querySelectorAll('.reveal').forEach(function(el){revObs.observe(el);});

/* ═════════════════════════════
   NAV ACTIVE
═════════════════════════════ */
var navLinks = document.querySelectorAll('.nav-link');
var sections = document.querySelectorAll('section[id]');
window.addEventListener('scroll', function(){
  var cur='';
  sections.forEach(function(s){if(window.scrollY>=s.offsetTop-120)cur=s.id;});
  navLinks.forEach(function(l){l.style.color=l.getAttribute('href')==='#'+cur?'var(--tx)':'';});
},{ passive: true });
</script>
</body>
</html>