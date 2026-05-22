<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DocuGo ADFC Online Document Request System</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ══════════════════════════════════════════════
   THEME VARIABLES
══════════════════════════════════════════════ */
:root {
  --primary:    #1a3ec7;
  --primary-dk: #1230a0;
  --accent:     #3b6bff;
  --accent2:    #6b9fff;
  --bg:         #080e28;
  --bg2:        #0b1535;
  --bg3:        #0e1c42;
  --surface:    rgba(255,255,255,0.055);
  --surface-hv: rgba(255,255,255,0.095);
  --surface-glow: rgba(59,107,255,0.08);
  --border:     rgba(255,255,255,0.08);
  --border-hv:  rgba(59,107,255,0.35);
  --text:       #dce6f8;
  --text-muted: #7a96c4;
  --text-dim:   #4a6190;
  --nav-bg:     rgba(8,14,40,0.75);
  --glow:       rgba(59,107,255,0.20);
  --green:      #4cd98a;
  --radius-sm:  8px;
  --radius-md:  14px;
  --radius-lg:  20px;
  --radius-xl:  28px;
  --ease-out:   cubic-bezier(0.16, 1, 0.3, 1);
  --ease-spring:cubic-bezier(0.34, 1.56, 0.64, 1);
}

body.light {
  --bg:         #eef2ff;
  --bg2:        #e2e9ff;
  --bg3:        #d8e2ff;
  --surface:    rgba(255,255,255,0.70);
  --surface-hv: rgba(255,255,255,0.95);
  --surface-glow: rgba(26,62,199,0.06);
  --border:     rgba(26,62,199,0.10);
  --border-hv:  rgba(26,62,199,0.30);
  --text:       #0c1836;
  --text-muted: #3d5a92;
  --text-dim:   #7a96c4;
  --nav-bg:     rgba(238,242,255,0.82);
  --glow:       rgba(26,62,199,0.10);
}

/* ══════════════════════════════════════════════
   RESET & BASE
══════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; font-size: 16px; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'DM Sans', sans-serif;
  line-height: 1.65;
  transition: background .4s, color .4s;
  overflow-x: hidden;
}
img { display: block; max-width: 100%; }
a { text-decoration: none; }

/* ══════════════════════════════════════════════
   CUSTOM SCROLLBAR
══════════════════════════════════════════════ */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--accent); border-radius: 10px; }

/* ══════════════════════════════════════════════
   NOISE OVERLAY — subtle texture
══════════════════════════════════════════════ */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
  pointer-events: none;
  z-index: 999;
  opacity: .45;
}
body.light::before { opacity: .18; }

/* ══════════════════════════════════════════════
   AMBIENT BLOBS
══════════════════════════════════════════════ */
.blob {
  position: fixed;
  border-radius: 50%;
  filter: blur(100px);
  pointer-events: none;
  z-index: 0;
  will-change: transform;
  animation: blobDrift 18s ease-in-out infinite alternate;
}
.blob-1 {
  width: 600px; height: 600px;
  background: radial-gradient(circle, rgba(59,107,255,0.16) 0%, transparent 70%);
  top: -180px; left: -160px;
  animation-delay: 0s;
}
.blob-2 {
  width: 500px; height: 500px;
  background: radial-gradient(circle, rgba(26,62,199,0.13) 0%, transparent 70%);
  bottom: 80px; right: -120px;
  animation-delay: -6s;
}
.blob-3 {
  width: 350px; height: 350px;
  background: radial-gradient(circle, rgba(107,159,255,0.10) 0%, transparent 70%);
  top: 50%; left: 50%;
  transform: translate(-50%,-50%);
  animation-delay: -12s;
}
@keyframes blobDrift {
  0%   { transform: translate(0,0) scale(1); }
  33%  { transform: translate(30px,-20px) scale(1.05); }
  66%  { transform: translate(-20px,30px) scale(0.96); }
  100% { transform: translate(10px,10px) scale(1.02); }
}
body.light .blob { opacity: .5; }

/* ══════════════════════════════════════════════
   NAVBAR
══════════════════════════════════════════════ */
.navbar {
  position: sticky;
  top: 0;
  z-index: 900;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 52px;
  height: 68px;
  background: var(--nav-bg);
  backdrop-filter: blur(22px) saturate(180%);
  -webkit-backdrop-filter: blur(22px) saturate(180%);
  border-bottom: 1px solid var(--border);
  transition: background .4s, border-color .4s, box-shadow .4s;
}
.navbar.scrolled {
  box-shadow: 0 4px 32px rgba(0,0,0,0.25);
  border-bottom-color: var(--border-hv);
}

/* Logo */
.logo { display: flex; align-items: center; gap: 12px; }
.logo img {
  width: 40px; height: 40px;
  object-fit: contain; border-radius: 9px;
  transition: transform .3s var(--ease-spring), opacity .3s;
}
.logo:hover img { transform: rotate(-6deg) scale(1.08); }
.logo-name {
  display: block;
  font-family: 'Sora', sans-serif;
  font-weight: 700; font-size: 13.5px;
  color: var(--text); letter-spacing: .01em;
  line-height: 1.2;
}
.logo-sub {
  display: block;
  font-size: 10px; font-weight: 400;
  color: var(--text-muted); letter-spacing: .025em;
}

/* Nav links */
.nav-links { display: flex; align-items: center; gap: 2px; list-style: none; }
.nav-links a {
  display: block; padding: 7px 15px;
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 13px; font-weight: 500;
  color: var(--text-muted);
  transition: color .2s, background .2s;
  position: relative;
}
.nav-links a::after {
  content: '';
  position: absolute;
  bottom: 4px; left: 50%; transform: translateX(-50%);
  width: 0; height: 2px;
  background: var(--accent);
  border-radius: 2px;
  transition: width .25s var(--ease-out);
}
.nav-links a:hover, .nav-links a.active { color: var(--text); }
.nav-links a:hover::after, .nav-links a.active::after { width: 20px; }

/* Nav actions */
.nav-actions { display: flex; align-items: center; gap: 10px; }
.btn-login {
  display: flex; align-items: center; gap: 7px;
  background: var(--primary);
  color: #fff !important;
  padding: 8px 22px;
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 13px; font-weight: 600;
  box-shadow: 0 4px 18px rgba(26,62,199,0.32);
  transition: background .2s, transform .2s var(--ease-spring), box-shadow .2s;
}
.btn-login:hover {
  background: var(--accent);
  transform: translateY(-2px) scale(1.02);
  box-shadow: 0 8px 28px rgba(59,107,255,0.42);
}
.btn-login i { font-size: 12px; }
.toggle-btn {
  cursor: pointer;
  width: 36px; height: 36px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
  font-size: 15px;
  display: flex; align-items: center; justify-content: center;
  transition: background .2s, transform .3s var(--ease-spring), border-color .2s;
}
.toggle-btn:hover {
  background: var(--surface-hv);
  border-color: var(--border-hv);
  transform: rotate(20deg) scale(1.1);
}

/* Hamburger */
.hamburger {
  display: none;
  flex-direction: column; gap: 5px;
  cursor: pointer;
  width: 36px; height: 36px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border);
  background: var(--surface);
  align-items: center; justify-content: center;
  transition: background .2s;
}
.hamburger span {
  display: block; width: 18px; height: 2px;
  background: var(--text);
  border-radius: 2px;
  transition: transform .3s var(--ease-out), opacity .3s, width .3s;
}
.hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; width: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

/* Mobile nav drawer */
.mobile-nav {
  display: none;
  position: fixed;
  top: 68px; left: 0; right: 0;
  background: var(--nav-bg);
  backdrop-filter: blur(22px);
  -webkit-backdrop-filter: blur(22px);
  border-bottom: 1px solid var(--border);
  padding: 16px 24px 24px;
  z-index: 899;
  transform: translateY(-10px);
  opacity: 0;
  transition: transform .3s var(--ease-out), opacity .3s;
}
.mobile-nav.open {
  display: flex;
  flex-direction: column;
  gap: 4px;
  transform: translateY(0);
  opacity: 1;
}
.mobile-nav a {
  padding: 11px 16px;
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 14px; font-weight: 500;
  color: var(--text-muted);
  transition: background .2s, color .2s;
}
.mobile-nav a:hover { background: var(--surface); color: var(--text); }
.mobile-nav .btn-login { margin-top: 8px; justify-content: center; }

/* ══════════════════════════════════════════════
   HERO
══════════════════════════════════════════════ */
.hero {
  position: relative;
  min-height: 92vh;
  display: flex; align-items: center;
  overflow: hidden; z-index: 1;
  padding: 80px 0 60px;
}
.hero-bg {
  position: absolute; inset: 0;
  background:
    linear-gradient(115deg,
      rgba(8,14,40,.98) 0%,
      rgba(8,14,40,.92) 38%,
      rgba(8,14,40,.60) 62%,
      rgba(8,14,40,.18) 100%),
    url('school.jpeg') center / cover no-repeat;
  z-index: 0;
}
body.light .hero-bg {
  background:
    linear-gradient(115deg,
      rgba(238,242,255,.98) 0%,
      rgba(238,242,255,.92) 38%,
      rgba(238,242,255,.60) 62%,
      rgba(238,242,255,.15) 100%),
    url('school.jpeg') center / cover no-repeat;
}
/* Parallax shimmer lines */
.hero-lines {
  position: absolute; inset: 0; z-index: 0;
  overflow: hidden; pointer-events: none;
}
.hero-lines::before, .hero-lines::after {
  content: '';
  position: absolute;
  width: 1px;
  top: 0; bottom: 0;
  background: linear-gradient(to bottom, transparent, rgba(59,107,255,0.18), transparent);
  animation: lineScroll 8s ease-in-out infinite;
}
.hero-lines::before { left: 35%; animation-delay: 0s; }
.hero-lines::after  { left: 65%; animation-delay: -4s; opacity: .5; }
@keyframes lineScroll {
  0%, 100% { transform: scaleY(0) translateY(-100%); opacity: 0; }
  40%       { transform: scaleY(1) translateY(0%);   opacity: 1; }
  80%       { transform: scaleY(1) translateY(100%); opacity: 0; }
}

.hero-content {
  position: relative; z-index: 2;
  max-width: 640px;
  padding: 0 72px;
}

/* Floating dot orb */
.hero-orb {
  position: absolute;
  right: 6%;
  top: 50%;
  transform: translateY(-50%);
  width: 340px; height: 340px;
  z-index: 1;
  pointer-events: none;
}
.hero-orb-ring {
  position: absolute; inset: 0;
  border-radius: 50%;
  border: 1px solid rgba(59,107,255,0.18);
  animation: orbSpin 20s linear infinite;
}
.hero-orb-ring:nth-child(2) {
  inset: 20px;
  border-color: rgba(59,107,255,0.12);
  animation-duration: 14s; animation-direction: reverse;
}
.hero-orb-ring:nth-child(3) { inset: 44px; border-color: rgba(59,107,255,0.08); animation-duration: 9s; }
.hero-orb-dot {
  position: absolute; width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--accent);
  top: 0; left: 50%; transform: translate(-50%, -50%);
  box-shadow: 0 0 16px rgba(59,107,255,0.6);
}
@keyframes orbSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.hero-badge {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(59,107,255,0.12);
  border: 1px solid rgba(59,107,255,0.30);
  color: var(--accent2);
  font-family: 'Sora', sans-serif;
  font-size: 10.5px; font-weight: 600;
  letter-spacing: .12em; text-transform: uppercase;
  padding: 6px 14px;
  border-radius: 100px;
  margin-bottom: 28px;
  animation: heroFadeUp .6s .1s var(--ease-out) both;
}
.hero-badge i { font-size: 8px; color: var(--green); }
body.light .hero-badge { color: var(--primary); background: rgba(26,62,199,0.08); border-color: rgba(26,62,199,0.22); }

.hero h1 {
  font-family: 'Sora', sans-serif;
  font-size: clamp(38px, 5.8vw, 62px);
  font-weight: 800;
  line-height: 1.08;
  letter-spacing: -.025em;
  color: var(--text);
  margin-bottom: 22px;
  animation: heroFadeUp .65s .2s var(--ease-out) both;
}
.hero h1 em {
  font-style: normal;
  background: linear-gradient(120deg, var(--accent2) 0%, var(--accent) 60%, #a78bff 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.hero-desc {
  font-size: 16px; font-weight: 300;
  color: var(--text-muted);
  max-width: 440px;
  margin-bottom: 42px;
  line-height: 1.80;
  animation: heroFadeUp .65s .3s var(--ease-out) both;
}
.hero-cta {
  display: flex; flex-wrap: wrap; gap: 14px;
  margin-bottom: 38px;
  animation: heroFadeUp .65s .4s var(--ease-out) both;
}

.hero-secure {
  display: flex; align-items: center; gap: 8px;
  font-size: 12.5px; color: var(--text-dim);
  animation: heroFadeUp .65s .5s var(--ease-out) both;
}
.hero-secure i { color: var(--green); font-size: 13px; }

/* Stats strip */
.hero-stats {
  display: flex; gap: 32px; flex-wrap: wrap;
  margin-top: 56px;
  padding-top: 32px;
  border-top: 1px solid var(--border);
  animation: heroFadeUp .65s .55s var(--ease-out) both;
}
.hs-item {}
.hs-num {
  font-family: 'Sora', sans-serif;
  font-size: 28px; font-weight: 800;
  color: var(--text);
  line-height: 1;
  background: linear-gradient(120deg, var(--accent2), var(--accent));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
.hs-label { font-size: 12px; color: var(--text-dim); margin-top: 3px; }

@keyframes heroFadeUp {
  from { opacity: 0; transform: translateY(30px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ══════════════════════════════════════════════
   SHARED BUTTONS
══════════════════════════════════════════════ */
.btn-primary {
  display: inline-flex; align-items: center; gap: 9px;
  background: var(--primary);
  color: #fff;
  padding: 13px 28px;
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 14px; font-weight: 600;
  box-shadow: 0 8px 26px rgba(26,62,199,0.35);
  transition: background .2s, transform .2s var(--ease-spring), box-shadow .2s;
  position: relative; overflow: hidden;
}
.btn-primary::before {
  content: '';
  position: absolute;
  top: 0; left: -100%;
  width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.12), transparent);
  transition: left .5s;
}
.btn-primary:hover::before { left: 100%; }
.btn-primary:hover {
  background: var(--accent);
  transform: translateY(-2px) scale(1.02);
  box-shadow: 0 14px 36px rgba(59,107,255,0.45);
}

.btn-outline {
  display: inline-flex; align-items: center; gap: 9px;
  background: transparent;
  color: var(--text);
  padding: 12px 28px;
  border-radius: var(--radius-sm);
  border: 1.5px solid var(--border);
  font-family: 'Sora', sans-serif;
  font-size: 14px; font-weight: 500;
  transition: background .2s, border-color .2s, transform .2s var(--ease-spring);
}
.btn-outline:hover {
  background: var(--surface);
  border-color: var(--border-hv);
  transform: translateY(-2px);
}

/* ══════════════════════════════════════════════
   SECTION SHARED
══════════════════════════════════════════════ */
section { position: relative; z-index: 1; }
.section-inner { max-width: 1120px; margin: 0 auto; }

.section-header { text-align: center; margin-bottom: 60px; }
.section-label {
  display: inline-flex; align-items: center; gap: 7px;
  font-family: 'Sora', sans-serif;
  font-size: 10.5px; font-weight: 700;
  letter-spacing: .16em; text-transform: uppercase;
  color: var(--accent);
  margin-bottom: 14px;
}
.section-label::before, .section-label::after {
  content: '';
  display: inline-block;
  width: 24px; height: 1px;
  background: var(--accent);
  opacity: .5;
}
.section-title {
  font-family: 'Sora', sans-serif;
  font-size: clamp(26px, 3.2vw, 38px);
  font-weight: 800;
  letter-spacing: -.02em;
  color: var(--text);
  margin-bottom: 14px;
  line-height: 1.18;
}
.section-sub {
  font-size: 15px;
  color: var(--text-muted);
  max-width: 480px;
  margin: 0 auto;
  line-height: 1.72;
}

/* ══════════════════════════════════════════════
   SCROLL REVEAL
══════════════════════════════════════════════ */
.reveal {
  opacity: 0;
  transform: translateY(36px);
  transition: opacity .75s var(--ease-out), transform .75s var(--ease-out);
}
.reveal.visible { opacity: 1; transform: translateY(0); }
.reveal-left {
  opacity: 0;
  transform: translateX(-40px);
  transition: opacity .75s var(--ease-out), transform .75s var(--ease-out);
}
.reveal-left.visible { opacity: 1; transform: translateX(0); }
.reveal-right {
  opacity: 0;
  transform: translateX(40px);
  transition: opacity .75s var(--ease-out), transform .75s var(--ease-out);
}
.reveal-right.visible { opacity: 1; transform: translateX(0); }
.reveal-scale {
  opacity: 0;
  transform: scale(0.92);
  transition: opacity .65s var(--ease-out), transform .65s var(--ease-spring);
}
.reveal-scale.visible { opacity: 1; transform: scale(1); }

/* Stagger delays */
.stagger-1 { transition-delay: .05s !important; }
.stagger-2 { transition-delay: .12s !important; }
.stagger-3 { transition-delay: .19s !important; }
.stagger-4 { transition-delay: .26s !important; }
.stagger-5 { transition-delay: .33s !important; }
.stagger-6 { transition-delay: .40s !important; }

/* ══════════════════════════════════════════════
   FEATURES
══════════════════════════════════════════════ */
.features { padding: 110px 52px; }
.cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 20px;
}
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 34px 28px;
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  transition: background .3s, transform .35s var(--ease-spring), box-shadow .3s, border-color .3s;
  cursor: default;
  position: relative;
  overflow: hidden;
}
.card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
  opacity: 0;
  transition: opacity .35s;
}
.card:hover { background: var(--surface-hv); transform: translateY(-7px) scale(1.01); box-shadow: 0 20px 56px rgba(0,0,0,0.20); border-color: var(--border-hv); }
.card:hover::before { opacity: 1; }
.card-icon {
  width: 52px; height: 52px;
  border-radius: var(--radius-md);
  background: var(--surface-glow);
  border: 1px solid rgba(59,107,255,0.22);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; color: var(--accent);
  margin-bottom: 22px;
  transition: transform .35s var(--ease-spring), background .3s;
}
.card:hover .card-icon { transform: rotate(-8deg) scale(1.1); background: rgba(59,107,255,0.16); }
.card h3 { font-family: 'Sora', sans-serif; font-size: 16px; font-weight: 700; margin-bottom: 9px; color: var(--text); }
.card p { font-size: 13.5px; color: var(--text-muted); line-height: 1.68; }

/* ══════════════════════════════════════════════
   MARQUEE STRIP
══════════════════════════════════════════════ */
.marquee-wrap {
  overflow: hidden;
  padding: 20px 0;
  background: var(--bg2);
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
}
.marquee-track {
  display: flex; gap: 48px;
  width: max-content;
  animation: marquee 22s linear infinite;
}
.marquee-item {
  display: flex; align-items: center; gap: 10px;
  font-family: 'Sora', sans-serif;
  font-size: 12px; font-weight: 600;
  letter-spacing: .10em; text-transform: uppercase;
  color: var(--text-dim);
  white-space: nowrap;
}
.marquee-item i { color: var(--accent); font-size: 10px; }
@keyframes marquee { from { transform: translateX(0); } to { transform: translateX(-50%); } }

/* ══════════════════════════════════════════════
   ABOUT
══════════════════════════════════════════════ */
.about { padding: 110px 52px; }
.about-inner {
  max-width: 1120px; margin: 0 auto;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 72px; align-items: center;
}
.about-img-wrap { position: relative; }
.about-img-box {
  position: relative;
  border-radius: var(--radius-xl);
  overflow: hidden;
  box-shadow: 0 32px 80px rgba(0,0,0,0.40);
}
.about-img-box img {
  width: 100%; height: 400px;
  object-fit: cover;
  filter: brightness(0.82) saturate(0.9);
  transition: transform .7s var(--ease-out), filter .4s;
}
.about-img-box:hover img { transform: scale(1.04); filter: brightness(0.9) saturate(1); }
/* Glow overlay */
.about-img-box::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(to top, rgba(8,14,40,0.55) 0%, transparent 50%);
  pointer-events: none;
}
body.light .about-img-box::after {
  background: linear-gradient(to top, rgba(238,242,255,0.45) 0%, transparent 50%);
}
.about-pill {
  position: absolute;
  background: var(--surface);
  backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 14px 20px;
  display: flex; flex-direction: column; gap: 2px;
  box-shadow: 0 12px 36px rgba(0,0,0,0.22);
}
.pill-1 { bottom: 28px; left: -22px; animation: floatPill 4s ease-in-out infinite; }
.pill-2 { top: 28px; right: -22px; animation: floatPill 4s ease-in-out infinite .8s; }
@keyframes floatPill {
  0%, 100% { transform: translateY(0); }
  50%       { transform: translateY(-10px); }
}
.pill-num { font-family: 'Sora', sans-serif; font-size: 24px; font-weight: 800; color: var(--accent); line-height: 1; }
.pill-label { font-size: 11px; color: var(--text-muted); font-weight: 500; }

.about-content .section-title { text-align: left; margin-bottom: 18px; }
.about-desc { font-size: 15px; color: var(--text-muted); line-height: 1.80; }
.about-highlights { margin-top: 28px; display: flex; flex-direction: column; gap: 11px; }
.ah-item {
  display: flex; align-items: center; gap: 11px;
  font-size: 14px; color: var(--text);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  background: var(--surface);
  border: 1px solid transparent;
  transition: background .25s, border-color .25s, transform .25s var(--ease-spring);
}
.ah-item:hover { background: var(--surface-hv); border-color: var(--border-hv); transform: translateX(6px); }
.ah-item i { color: var(--green); font-size: 15px; flex-shrink: 0; }

/* ══════════════════════════════════════════════
   SERVICES
══════════════════════════════════════════════ */
.services { padding: 110px 52px; background: var(--bg2); }
.services-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
  gap: 18px;
  max-width: 1120px; margin: 0 auto;
}
.service-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 26px;
  display: flex; gap: 18px; align-items: flex-start;
  backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
  transition: background .3s, transform .35s var(--ease-spring), box-shadow .3s, border-color .3s;
  position: relative; overflow: hidden;
}
.service-card::after {
  content: '';
  position: absolute; bottom: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
  transform: scaleX(0);
  transition: transform .35s var(--ease-out);
}
.service-card:hover { background: var(--surface-hv); transform: translateY(-5px) scale(1.005); border-color: var(--border-hv); box-shadow: 0 16px 48px rgba(0,0,0,0.16); }
.service-card:hover::after { transform: scaleX(1); }
.sc-icon {
  width: 46px; height: 46px;
  border-radius: var(--radius-sm);
  background: var(--surface-glow);
  border: 1px solid rgba(59,107,255,0.22);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; color: var(--accent); flex-shrink: 0;
  transition: transform .35s var(--ease-spring), background .3s;
}
.service-card:hover .sc-icon { transform: rotate(8deg) scale(1.08); background: rgba(59,107,255,0.18); }
.sc-body h3 { font-family: 'Sora', sans-serif; font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.sc-body p { font-size: 13px; color: var(--text-muted); line-height: 1.62; margin-bottom: 11px; }
.sc-meta { display: flex; gap: 14px; flex-wrap: wrap; }
.sc-meta span {
  display: flex; align-items: center; gap: 5px;
  font-size: 11.5px; color: var(--accent);
  font-weight: 600; font-family: 'Sora', sans-serif;
  background: rgba(59,107,255,0.08);
  padding: 3px 9px; border-radius: 100px;
}
.sc-meta i { font-size: 10px; }

/* ══════════════════════════════════════════════
   HOW IT WORKS
══════════════════════════════════════════════ */
.howitworks { padding: 110px 52px; }
.steps-wrap { position: relative; max-width: 1120px; margin: 0 auto 44px; }
.steps-line {
  position: absolute;
  top: 38px;
  left: calc(12.5% + 20px);
  right: calc(12.5% + 20px);
  height: 1px;
  background: linear-gradient(90deg, var(--accent) 0%, rgba(59,107,255,0.08) 100%);
  z-index: 0;
}
.steps-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 24px; position: relative; z-index: 1;
}
.step-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 30px 20px 26px;
  text-align: center;
  backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
  transition: background .3s, transform .35s var(--ease-spring), box-shadow .3s, border-color .3s;
  position: relative;
}
.step-card:hover { background: var(--surface-hv); transform: translateY(-7px); border-color: var(--border-hv); box-shadow: 0 18px 52px rgba(0,0,0,0.18); }
.step-num {
  position: absolute;
  top: -15px; left: 50%; transform: translateX(-50%);
  width: 30px; height: 30px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
  color: #fff;
  font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 16px rgba(59,107,255,0.5);
  transition: transform .35s var(--ease-spring);
}
.step-card:hover .step-num { transform: translateX(-50%) scale(1.18) rotate(-10deg); }
.step-icon {
  width: 56px; height: 56px;
  border-radius: var(--radius-md);
  background: var(--surface-glow);
  border: 1px solid rgba(59,107,255,0.20);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: var(--accent);
  margin: 0 auto 18px;
  transition: transform .35s var(--ease-spring), background .3s;
}
.step-card:hover .step-icon { transform: scale(1.1) rotate(5deg); background: rgba(59,107,255,0.18); }
.step-card h3 { font-family: 'Sora', sans-serif; font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.step-card p { font-size: 13px; color: var(--text-muted); line-height: 1.67; }

.howitworks-note {
  max-width: 680px; margin: 0 auto;
  display: flex; align-items: flex-start; gap: 14px;
  background: var(--surface-glow);
  border: 1px solid rgba(59,107,255,0.20);
  border-radius: var(--radius-md);
  padding: 18px 22px;
  font-size: 14px; color: var(--text-muted); line-height: 1.68;
}
.howitworks-note i { color: var(--accent); font-size: 18px; flex-shrink: 0; margin-top: 2px; }
.howitworks-note strong { color: var(--text); }

/* ══════════════════════════════════════════════
   CONTACT
══════════════════════════════════════════════ */
.contact { padding: 110px 52px; background: var(--bg2); }
.contact-grid {
  max-width: 1120px; margin: 0 auto;
  display: grid; grid-template-columns: 1fr 1.5fr;
  gap: 52px; align-items: start;
}
.contact-info { display: flex; flex-direction: column; gap: 14px; }
.ci-card {
  display: flex; align-items: flex-start; gap: 14px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md); padding: 18px 20px;
  backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
  transition: background .25s, border-color .25s, transform .3s var(--ease-spring);
}
.ci-card:hover { background: var(--surface-hv); border-color: var(--border-hv); transform: translateX(8px); }
.ci-icon {
  width: 42px; height: 42px;
  border-radius: 10px;
  background: var(--surface-glow);
  border: 1px solid rgba(59,107,255,0.22);
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; color: var(--accent); flex-shrink: 0;
  transition: transform .35s var(--ease-spring);
}
.ci-card:hover .ci-icon { transform: scale(1.1) rotate(-8deg); }
.ci-card h4 { font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.ci-card p { font-size: 13px; color: var(--text-muted); line-height: 1.62; }

.contact-form-wrap {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-xl); padding: 36px;
  backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
}
.contact-form { display: flex; flex-direction: column; gap: 16px; }
.cf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.cf-group { display: flex; flex-direction: column; gap: 6px; }
.cf-group label {
  font-size: 12px; font-weight: 600;
  font-family: 'Sora', sans-serif;
  color: var(--text-dim); letter-spacing: .04em;
}
.cf-group input,
.cf-group select,
.cf-group textarea {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 11px 14px;
  font-size: 14px; color: var(--text);
  font-family: 'DM Sans', sans-serif;
  outline: none; resize: none;
  transition: border-color .2s, box-shadow .2s, background .2s;
}
.cf-group input:focus,
.cf-group select:focus,
.cf-group textarea:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(59,107,255,0.14);
  background: var(--bg2);
}
.cf-group select option { background: var(--bg); color: var(--text); }

/* ══════════════════════════════════════════════
   CTA BANNER
══════════════════════════════════════════════ */
.cta-section { padding: 0 52px 110px; }
.cta-card {
  max-width: 1120px; margin: 0 auto;
  background: linear-gradient(115deg, var(--primary-dk) 0%, #2851e5 50%, var(--accent) 100%);
  border-radius: var(--radius-xl);
  padding: 60px 68px;
  display: flex; align-items: center; justify-content: space-between; gap: 32px;
  overflow: hidden; position: relative;
  box-shadow: 0 28px 90px rgba(26,62,199,0.38);
}
.cta-card::before {
  content: '';
  position: absolute; top: -80px; right: -80px;
  width: 300px; height: 300px; border-radius: 50%;
  background: rgba(255,255,255,0.07);
  animation: ctaPulse 5s ease-in-out infinite;
}
.cta-card::after {
  content: '';
  position: absolute; bottom: -100px; left: 25%;
  width: 240px; height: 240px; border-radius: 50%;
  background: rgba(255,255,255,0.04);
}
/* grid lines */
.cta-card-grid {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px);
  background-size: 48px 48px;
  pointer-events: none;
}
@keyframes ctaPulse {
  0%, 100% { transform: scale(1); }
  50%       { transform: scale(1.12); }
}
.cta-icon-wrap {
  flex-shrink: 0; width: 72px; height: 72px;
  border-radius: 18px;
  background: rgba(255,255,255,0.14);
  border: 1px solid rgba(255,255,255,0.2);
  display: flex; align-items: center; justify-content: center;
  font-size: 30px; color: #fff;
  transition: transform .4s var(--ease-spring);
}
.cta-card:hover .cta-icon-wrap { transform: rotate(18deg) scale(1.08); }
.cta-text { flex: 1; position: relative; z-index: 1; }
.cta-text h2 {
  font-family: 'Sora', sans-serif;
  font-size: clamp(20px, 2.4vw, 28px);
  font-weight: 800; color: #fff; margin-bottom: 6px;
}
.cta-text p { font-size: 15px; color: rgba(255,255,255,0.72); }
.btn-white {
  flex-shrink: 0; position: relative; z-index: 1;
  display: inline-flex; align-items: center; gap: 9px;
  background: #fff; color: var(--primary);
  padding: 13px 30px;
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 14px; font-weight: 700;
  box-shadow: 0 8px 24px rgba(0,0,0,0.18);
  transition: transform .2s var(--ease-spring), box-shadow .2s;
}
.btn-white:hover { transform: translateY(-3px) scale(1.03); box-shadow: 0 14px 36px rgba(0,0,0,0.26); }

/* ══════════════════════════════════════════════
   FOOTER
══════════════════════════════════════════════ */
footer {
  position: relative; z-index: 1;
  border-top: 1px solid var(--border);
  background: var(--bg2);
  padding: 24px 52px;
  display: flex; align-items: center; justify-content: space-between;
  transition: background .4s;
}
.footer-copy { font-size: 13px; color: var(--text-dim); }
.footer-socials { display: flex; gap: 10px; }
.footer-socials a {
  width: 34px; height: 34px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text-muted);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px;
  transition: color .2s, background .2s, border-color .2s, transform .25s var(--ease-spring);
}
.footer-socials a:hover { color: var(--accent); background: var(--surface-hv); border-color: var(--border-hv); transform: translateY(-4px) scale(1.12); }

/* ══════════════════════════════════════════════
   SCROLL-TO-TOP
══════════════════════════════════════════════ */
.scroll-top {
  position: fixed; bottom: 28px; right: 28px;
  width: 46px; height: 46px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
  color: #fff; border: none; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
  opacity: 0; visibility: hidden;
  transition: opacity .3s, visibility .3s, transform .3s var(--ease-spring), box-shadow .3s;
  z-index: 99;
  box-shadow: 0 6px 24px rgba(59,107,255,0.40);
}
.scroll-top.show { opacity: 1; visibility: visible; }
.scroll-top:hover { transform: translateY(-4px) scale(1.1); box-shadow: 0 12px 36px rgba(59,107,255,0.55); }

/* ══════════════════════════════════════════════
   CURSOR GLOW (desktop only)
══════════════════════════════════════════════ */
.cursor-glow {
  position: fixed;
  width: 300px; height: 300px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(59,107,255,0.07) 0%, transparent 70%);
  pointer-events: none;
  z-index: 1;
  transform: translate(-50%, -50%);
  transition: left .12s, top .12s;
  display: none;
}
@media (hover: hover) { .cursor-glow { display: block; } }

/* ══════════════════════════════════════════════
   RESPONSIVE
══════════════════════════════════════════════ */
@media (max-width: 1024px) {
  .hero-orb { display: none; }
  .about-inner { gap: 48px; }
}

@media (max-width: 900px) {
  .navbar { padding: 0 24px; }
  .nav-links { display: none; }
  .hamburger { display: flex; }

  .hero-content { padding: 60px 28px; }
  .hero-stats { gap: 24px; }

  .features, .about, .services,
  .howitworks, .contact, .cta-section { padding-left: 28px; padding-right: 28px; }
  footer { padding: 20px 24px; }

  .about-inner { grid-template-columns: 1fr; gap: 44px; }
  .about-img-box img { height: 280px; }
  .pill-1, .pill-2 { display: none; }

  .steps-grid { grid-template-columns: repeat(2, 1fr); }
  .steps-line { display: none; }

  .contact-grid { grid-template-columns: 1fr; }
  .cta-card { flex-direction: column; text-align: center; padding: 44px 32px; }
  .cta-icon-wrap { margin: 0 auto; }
}

@media (max-width: 640px) {
  .hero h1 { font-size: 34px; }
  .hero-content { padding: 50px 20px; }
  .hero-cta { flex-direction: column; }
  .btn-primary, .btn-outline { justify-content: center; width: 100%; }
  .hero-stats { gap: 18px; }
  .hs-num { font-size: 22px; }

  .features { padding: 72px 20px; }
  .cards-grid { grid-template-columns: 1fr; }

  .about, .services, .howitworks, .contact { padding: 72px 20px; }
  .about-img-box img { height: 220px; }

  .services-grid { grid-template-columns: 1fr; }
  .steps-grid { grid-template-columns: 1fr; }

  .cf-row { grid-template-columns: 1fr; }
  .cta-section { padding: 0 20px 72px; }
  .cta-card { padding: 36px 24px; }

  footer { flex-direction: column; gap: 16px; text-align: center; }
  .scroll-top { bottom: 18px; right: 18px; width: 40px; height: 40px; font-size: 14px; }
}
</style>
</head>
<body>

<!-- Cursor glow -->
<div class="cursor-glow" id="cursorGlow"></div>

<!-- Ambient blobs -->
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>
<div class="blob blob-3"></div>

<!-- ══════════════════════════════════════════════
     NAVBAR
══════════════════════════════════════════════ -->
<header class="navbar" id="navbar">
  <a href="index.php" class="logo">
    <img id="siteLogo" src="logo2.png" alt="ADFC Logo">
    <div>
      <span class="logo-name">Asian Development Foundation College</span>
      <span class="logo-sub">Online Document Request System</span>
    </div>
  </a>

  <ul class="nav-links" id="navLinks">
    <li><a href="index.php" class="active">Home</a></li>
    <li><a href="#about">About</a></li>
    <li><a href="#services">Services</a></li>
    <li><a href="#how-it-works">How It Works</a></li>
    <li><a href="#contact">Contact</a></li>
  </ul>

  <div class="nav-actions">
    <button class="toggle-btn" id="themeToggle" title="Toggle theme">🌙</button>
    <div class="hamburger" id="hamburger" aria-label="Menu">
      <span></span><span></span><span></span>
    </div>
    <a href="login.php" class="btn-login">
      <i class="fas fa-user"></i> Login
    </a>
  </div>
</header>

<!-- Mobile nav -->
<nav class="mobile-nav" id="mobileNav">
  <a href="index.php">Home</a>
  <a href="#about">About</a>
  <a href="#services">Services</a>
  <a href="#how-it-works">How It Works</a>
  <a href="#contact">Contact</a>
  <a href="login.php" class="btn-login"><i class="fas fa-user"></i> Login</a>
</nav>

<!-- ══════════════════════════════════════════════
     HERO
══════════════════════════════════════════════ -->
<section class="hero" id="home">
  <div class="hero-bg"></div>
  <div class="hero-lines"></div>

  <!-- Decorative orbital rings -->
  <div class="hero-orb">
    <div class="hero-orb-ring"><div class="hero-orb-dot"></div></div>
    <div class="hero-orb-ring"></div>
    <div class="hero-orb-ring"></div>
  </div>

  <div class="hero-content">
    <div class="hero-badge">
      <i class="fas fa-circle"></i>
      Online Document Request System
    </div>
    <h1>Request Documents<br><em>Anywhere, Anytime</em></h1>
    <p class="hero-desc">A fast, secure, and convenient way for students and alumni to request academic and official documents all online, all the time.</p>
    <div class="hero-cta">
      <a href="login.php" class="btn-primary">
        <i class="fas fa-file-alt"></i> Request a Document
      </a>
      <a href="#features" class="btn-outline">
        <i class="fas fa-circle-info"></i> Learn More
      </a>
    </div>
    <div class="hero-secure">
      <i class="fas fa-shield-halved"></i>
      Your data is encrypted and protected.
    </div>
    <div class="hero-stats">
      <div class="hs-item"><div class="hs-num" data-count="500">0</div><div class="hs-label">Documents Processed</div></div>
      <div class="hs-item"><div class="hs-num" data-count="98" data-suffix="%">0</div><div class="hs-label">Satisfaction Rate</div></div>
      <div class="hs-item"><div class="hs-num" data-count="6" data-prefix="~">0</div><div class="hs-label">Document Types</div></div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════
     MARQUEE
══════════════════════════════════════════════ -->
<div class="marquee-wrap">
  <div class="marquee-track">
    <!-- duplicated for seamless loop -->
    <span class="marquee-item"><i class="fas fa-scroll"></i> Transcript of Records</span>
    <span class="marquee-item"><i class="fas fa-id-card"></i> Certificate of Enrollment</span>
    <span class="marquee-item"><i class="fas fa-graduation-cap"></i> Certificate of Graduation</span>
    <span class="marquee-item"><i class="fas fa-heart"></i> Good Moral Certificate</span>
    <span class="marquee-item"><i class="fas fa-certificate"></i> Diploma Replacement</span>
    <span class="marquee-item"><i class="fas fa-stamp"></i> Authentication</span>
    <span class="marquee-item"><i class="fas fa-scroll"></i> Transcript of Records</span>
    <span class="marquee-item"><i class="fas fa-id-card"></i> Certificate of Enrollment</span>
    <span class="marquee-item"><i class="fas fa-graduation-cap"></i> Certificate of Graduation</span>
    <span class="marquee-item"><i class="fas fa-heart"></i> Good Moral Certificate</span>
    <span class="marquee-item"><i class="fas fa-certificate"></i> Diploma Replacement</span>
    <span class="marquee-item"><i class="fas fa-stamp"></i> Authentication</span>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     FEATURES
══════════════════════════════════════════════ -->
<section class="features" id="features">
  <div class="section-inner">
    <div class="section-header reveal">
      <span class="section-label">Why Choose Us</span>
      <h2 class="section-title">Why Use DocuGo?</h2>
      <p class="section-sub">Everything you need to manage your document requests fast, secure, and stress-free.</p>
    </div>
    <div class="cards-grid">
      <div class="card reveal stagger-1">
        <div class="card-icon"><i class="fas fa-clock"></i></div>
        <h3>Fast &amp; Convenient</h3>
        <p>Request documents online anytime, anywhere. No long lines, no hassle, no wasted trips to campus.</p>
      </div>
      <div class="card reveal stagger-2">
        <div class="card-icon"><i class="fas fa-shield-halved"></i></div>
        <h3>Secure &amp; Reliable</h3>
        <p>Your information is handled with the highest security standards. Your privacy is our priority.</p>
      </div>
      <div class="card reveal stagger-3">
        <div class="card-icon"><i class="fas fa-file-lines"></i></div>
        <h3>Track Your Requests</h3>
        <p>Monitor the status of your requests in real-time from submission through processing to release.</p>
      </div>
      <div class="card reveal stagger-4">
        <div class="card-icon"><i class="fas fa-bell"></i></div>
        <h3>Stay Updated</h3>
        <p>Get notified about your request updates and when your document is ready for pickup or delivery.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════
     ABOUT
══════════════════════════════════════════════ -->
<section class="about" id="about">
  <div class="about-inner">
    <div class="about-img-wrap reveal-left">
      <div class="about-img-box">
        <img src="school.jpeg" alt="ADFC Campus">
        <div class="about-pill pill-1">
          <span class="pill-num">500+</span>
          <span class="pill-label">Documents Processed</span>
        </div>
        <div class="about-pill pill-2">
          <span class="pill-num">98%</span>
          <span class="pill-label">Satisfaction Rate</span>
        </div>
      </div>
    </div>
    <div class="about-content reveal-right">
      <span class="section-label">About DocuGo</span>
      <h2 class="section-title" style="text-align:left;">Built for ADFC Students &amp; Alumni</h2>
      <p class="about-desc">DocuGo is the official Online Document Request and Graduate Tracer System of <strong>Asian Development Foundation College</strong>. Designed to eliminate long queues and manual paperwork, DocuGo provides a streamlined digital platform where students and alumni can request, track, and claim their official academic documents — anytime, from anywhere.</p>
      <p class="about-desc" style="margin-top:14px;">The system also serves as a Graduate Tracer platform, helping the institution monitor alumni employment outcomes for continuous curriculum improvement and accreditation compliance.</p>
      <div class="about-highlights">
        <div class="ah-item reveal stagger-1"><i class="fas fa-check-circle"></i><span>No more physical queues or paper forms</span></div>
        <div class="ah-item reveal stagger-2"><i class="fas fa-check-circle"></i><span>Real-time request status tracking</span></div>
        <div class="ah-item reveal stagger-3"><i class="fas fa-check-circle"></i><span>Alumni Graduate Tracer Survey built-in</span></div>
        <div class="ah-item reveal stagger-4"><i class="fas fa-check-circle"></i><span>Secure, verified accounts for all users</span></div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════
     SERVICES
══════════════════════════════════════════════ -->
<section class="services" id="services">
  <div class="section-inner">
    <div class="section-header reveal">
      <span class="section-label">Our Services</span>
      <h2 class="section-title">Documents You Can Request</h2>
      <p class="section-sub">All official academic documents are available for online request through DocuGo.</p>
    </div>
    <div class="services-grid">
      <div class="service-card reveal stagger-1">
        <div class="sc-icon"><i class="fas fa-scroll"></i></div>
        <div class="sc-body"><h3>Transcript of Records</h3><p>Official academic transcript reflecting your complete scholastic record.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~5 days</span><span><i class="fas fa-peso-sign"></i> ₱100.00 / copy</span></div></div>
      </div>
      <div class="service-card reveal stagger-2">
        <div class="sc-icon"><i class="fas fa-id-card"></i></div>
        <div class="sc-body"><h3>Certificate of Enrollment</h3><p>Proof of enrollment for scholarship, employment, or other requirements.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~1 day</span><span><i class="fas fa-peso-sign"></i> ₱30.00 / copy</span></div></div>
      </div>
      <div class="service-card reveal stagger-3">
        <div class="sc-icon"><i class="fas fa-graduation-cap"></i></div>
        <div class="sc-body"><h3>Certificate of Graduation</h3><p>Official certification confirming completion of academic program.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~3 days</span><span><i class="fas fa-peso-sign"></i> ₱50.00 / copy</span></div></div>
      </div>
      <div class="service-card reveal stagger-4">
        <div class="sc-icon"><i class="fas fa-heart"></i></div>
        <div class="sc-body"><h3>Good Moral Certificate</h3><p>Character reference letter issued by the institution.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~2 days</span><span><i class="fas fa-peso-sign"></i> ₱30.00 / copy</span></div></div>
      </div>
      <div class="service-card reveal stagger-5">
        <div class="sc-icon"><i class="fas fa-certificate"></i></div>
        <div class="sc-body"><h3>Diploma (Replacement)</h3><p>Replacement copy of your official diploma for lost or damaged originals.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~10 days</span><span><i class="fas fa-peso-sign"></i> ₱500.00</span></div></div>
      </div>
      <div class="service-card reveal stagger-6">
        <div class="sc-icon"><i class="fas fa-stamp"></i></div>
        <div class="sc-body"><h3>Authentication</h3><p>Official document authentication for local and international use.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~3 days</span><span><i class="fas fa-peso-sign"></i> ₱50.00 / copy</span></div></div>
      </div>
    </div>
    <div style="text-align:center;margin-top:44px;" class="reveal">
      <a href="login.php" class="btn-primary"><i class="fas fa-file-alt"></i> Request a Document Now</a>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════
     HOW IT WORKS
══════════════════════════════════════════════ -->
<section class="howitworks" id="how-it-works">
  <div class="section-inner">
    <div class="section-header reveal">
      <span class="section-label">How It Works</span>
      <h2 class="section-title">Request in 4 Easy Steps</h2>
      <p class="section-sub">DocuGo follows a simple Pay-on-Claim process — no online payment needed.</p>
    </div>
    <div class="steps-wrap">
      <div class="steps-line"></div>
      <div class="steps-grid">
        <div class="step-card reveal stagger-1">
          <div class="step-num">1</div>
          <div class="step-icon"><i class="fas fa-user-plus"></i></div>
          <h3>Create an Account</h3>
          <p>Register as a student or alumni and verify your email to activate your account.</p>
        </div>
        <div class="step-card reveal stagger-2">
          <div class="step-num">2</div>
          <div class="step-icon"><i class="fas fa-file-circle-plus"></i></div>
          <h3>Submit a Request</h3>
          <p>Select the document type, number of copies, purpose, and preferred release date.</p>
        </div>
        <div class="step-card reveal stagger-3">
          <div class="step-num">3</div>
          <div class="step-icon"><i class="fas fa-magnifying-glass"></i></div>
          <h3>Track Your Request</h3>
          <p>Monitor your request status in real-time — from Pending to Approved to Ready.</p>
        </div>
        <div class="step-card reveal stagger-4">
          <div class="step-num">4</div>
          <div class="step-icon"><i class="fas fa-hand-holding-dollar"></i></div>
          <h3>Pay &amp; Claim</h3>
          <p>Visit the Registrar's Office, present your claim stub, pay the fee, and get your document.</p>
        </div>
      </div>
    </div>
    <div class="howitworks-note reveal">
      <i class="fas fa-circle-info"></i>
      <div><strong>Pay-on-Claim System</strong> No online payment required. Payment is made in cash at the Registrar's Office only when you claim your document. Your claim stub will serve as your payment reference.</div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════
     CONTACT
══════════════════════════════════════════════ -->
<section class="contact" id="contact">
  <div class="section-inner">
    <div class="section-header reveal">
      <span class="section-label">Contact Us</span>
      <h2 class="section-title">Need Help? Reach Out to Us</h2>
      <p class="section-sub">For concerns about your document requests, contact the Registrar's Office directly.</p>
    </div>
    <div class="contact-grid">
      <div class="contact-info">
        <div class="ci-card reveal stagger-1"><div class="ci-icon"><i class="fas fa-location-dot"></i></div><div><h4>Address</h4><p>Asian Development Foundation College<br>Maasin City, Southern Leyte, Philippines</p></div></div>
        <div class="ci-card reveal stagger-2"><div class="ci-icon"><i class="fas fa-phone"></i></div><div><h4>Phone</h4><p>(053) 000-0000<br>Mon – Fri, 8:00 AM – 5:00 PM</p></div></div>
        <div class="ci-card reveal stagger-3"><div class="ci-icon"><i class="fas fa-envelope"></i></div><div><h4>Email</h4><p>registrar@adfc.edu.ph<br>docugo@adfc.edu.ph</p></div></div>
        <div class="ci-card reveal stagger-4"><div class="ci-icon"><i class="fas fa-clock"></i></div><div><h4>Office Hours</h4><p>Monday – Friday<br>8:00 AM – 5:00 PM</p></div></div>
      </div>
      <div class="contact-form-wrap reveal-right">
        <form class="contact-form" id="contactForm" onsubmit="submitContact(event)">
          <div class="cf-row">
            <div class="cf-group"><label>Full Name</label><input type="text" placeholder="Juan dela Cruz" required></div>
            <div class="cf-group"><label>Email Address</label><input type="email" placeholder="you@email.com" required></div>
          </div>
          <div class="cf-group"><label>Subject</label>
            <select>
              <option value="">-- Select subject --</option>
              <option>Document Request Inquiry</option>
              <option>Request Status Follow-up</option>
              <option>Account / Login Issue</option>
              <option>Graduate Tracer Concern</option>
              <option>Other</option>
            </select>
          </div>
          <div class="cf-group"><label>Message</label><textarea rows="5" placeholder="Describe your concern…" required></textarea></div>
          <button type="submit" class="btn-primary" style="width:100%;justify-content:center;">
            <i class="fas fa-paper-plane"></i> Send Message
          </button>
          <div id="cf-success" style="display:none;margin-top:1rem;padding:.9rem;background:rgba(76,217,138,0.10);border:1px solid rgba(76,217,138,0.28);border-radius:var(--radius-sm);color:#4cd98a;font-size:14px;text-align:center;transition:opacity .3s;">
            ✅ Message sent! We'll get back to you within 1–2 business days.
          </div>
        </form>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════════
     CTA
══════════════════════════════════════════════ -->
<section class="cta-section">
  <div class="cta-card reveal-scale">
    <div class="cta-card-grid"></div>
    <div class="cta-icon-wrap"><i class="fas fa-folder-open"></i></div>
    <div class="cta-text">
      <h2>Ready to get started?</h2>
      <p>Sign in to your account and request your documents now — it only takes a minute.</p>
    </div>
    <a href="login.php" class="btn-white"><i class="fas fa-user"></i> Login to Your Account</a>
  </div>
</section>

<!-- ══════════════════════════════════════════════
     FOOTER
══════════════════════════════════════════════ -->
<footer>
  <span class="footer-copy">© 2025 Asian Development Foundation College. All rights reserved.</span>
  <div class="footer-socials">
    <a href="https://web.facebook.com/adfcofficial/" title="Facebook"><i class="fab fa-facebook-f"></i></a>
    <a href="https://www.instagram.com/explore/locations/110717146946286/asian-development-foundation-college-official/" title="Instagram"><i class="fab fa-instagram"></i></a>
    <a href="mailto:info@adfcollege.edu.ph" title="Email"><i class="fas fa-envelope"></i></a>
  </div>
</footer>

<button class="scroll-top" id="scrollTopBtn" aria-label="Scroll to top">
  <i class="fas fa-arrow-up"></i>
</button>

<script>
/* ══════════════════════════════════
   LOGO SWAP
══════════════════════════════════ */
function applyLogoForTheme(isLight) {
  document.getElementById('siteLogo').src = isLight ? 'logo.png' : 'wlogo.png';
}

/* ══════════════════════════════════
   THEME INIT
══════════════════════════════════ */
const savedTheme = localStorage.getItem('theme');
const isLightOnLoad = savedTheme === 'light';
if (isLightOnLoad) {
  document.body.classList.add('light');
  document.getElementById('themeToggle').textContent = '☀️';
}
applyLogoForTheme(isLightOnLoad);

document.getElementById('themeToggle').addEventListener('click', () => {
  const isLight = document.body.classList.toggle('light');
  localStorage.setItem('theme', isLight ? 'light' : 'dark');
  document.getElementById('themeToggle').textContent = isLight ? '☀️' : '🌙';
  applyLogoForTheme(isLight);
});

/* ══════════════════════════════════
   HAMBURGER MENU
══════════════════════════════════ */
const hamburger = document.getElementById('hamburger');
const mobileNav = document.getElementById('mobileNav');

hamburger.addEventListener('click', () => {
  hamburger.classList.toggle('open');
  if (mobileNav.classList.contains('open')) {
    mobileNav.classList.remove('open');
    setTimeout(() => { mobileNav.style.display = ''; }, 310);
  } else {
    mobileNav.style.display = 'flex';
    requestAnimationFrame(() => mobileNav.classList.add('open'));
  }
});

// Close mobile nav when link is clicked
mobileNav.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', () => {
    hamburger.classList.remove('open');
    mobileNav.classList.remove('open');
    setTimeout(() => { mobileNav.style.display = ''; }, 310);
  });
});

/* ══════════════════════════════════
   NAVBAR SCROLL SHADOW
══════════════════════════════════ */
const navbar = document.getElementById('navbar');
const scrollBtn = document.getElementById('scrollTopBtn');
const navLinks = document.querySelectorAll('.nav-links a');
const sections = document.querySelectorAll('section[id]');

window.addEventListener('scroll', () => {
  // Navbar shadow
  if (window.scrollY > 20) navbar.classList.add('scrolled');
  else navbar.classList.remove('scrolled');

  // Active nav link
  let current = '';
  sections.forEach(s => {
    if (window.scrollY >= s.offsetTop - 180) current = s.id;
  });
  navLinks.forEach(a => {
    a.classList.remove('active');
    if (a.getAttribute('href') === `#${current}` || (current === '' && a.getAttribute('href') === 'index.php')) a.classList.add('active');
  });

  // Scroll-to-top
  if (window.scrollY > 400) scrollBtn.classList.add('show');
  else scrollBtn.classList.remove('show');
}, { passive: true });

scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

/* ══════════════════════════════════
   SCROLL REVEAL — IntersectionObserver
══════════════════════════════════ */
const revealEls = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale');
const revealObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('visible');
      revealObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
revealEls.forEach(el => revealObserver.observe(el));

/* ══════════════════════════════════
   COUNTER ANIMATION
══════════════════════════════════ */
function animateCounter(el) {
  const target = parseInt(el.dataset.count, 10);
  const suffix = el.dataset.suffix || '';
  const prefix = el.dataset.prefix || '';
  const duration = 1400;
  const start = performance.now();
  const tick = (now) => {
    const t = Math.min((now - start) / duration, 1);
    const ease = 1 - Math.pow(1 - t, 3);
    el.textContent = prefix + Math.floor(ease * target) + suffix;
    if (t < 1) requestAnimationFrame(tick);
    else el.textContent = prefix + target + suffix;
  };
  requestAnimationFrame(tick);
}
const counterEls = document.querySelectorAll('[data-count]');
const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      animateCounter(entry.target);
      counterObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.5 });
counterEls.forEach(el => counterObserver.observe(el));

/* ══════════════════════════════════
   CURSOR GLOW (desktop)
══════════════════════════════════ */
const cursorGlow = document.getElementById('cursorGlow');
if (window.matchMedia('(hover: hover)').matches) {
  document.addEventListener('mousemove', (e) => {
    cursorGlow.style.left = e.clientX + 'px';
    cursorGlow.style.top = e.clientY + 'px';
  }, { passive: true });
}

/* ══════════════════════════════════
   CONTACT FORM
══════════════════════════════════ */
function submitContact(e) {
  e.preventDefault();
  const btn = e.target.querySelector('button[type="submit"]');
  const original = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
  btn.disabled = true;
  setTimeout(() => {
    const ok = document.getElementById('cf-success');
    ok.style.display = 'block';
    ok.style.opacity = '0';
    requestAnimationFrame(() => { ok.style.opacity = '1'; });
    btn.innerHTML = '<i class="fas fa-check"></i> Sent!';
    btn.style.background = '#4cd98a';
    e.target.reset();
    setTimeout(() => {
      btn.innerHTML = original;
      btn.style.background = '';
      btn.disabled = false;
      ok.style.opacity = '0';
      setTimeout(() => { ok.style.display = 'none'; }, 320);
    }, 3200);
  }, 1200);
}

/* ══════════════════════════════════
   PARALLAX HERO BG — subtle
══════════════════════════════════ */
const heroBg = document.querySelector('.hero-bg');
window.addEventListener('scroll', () => {
  if (window.scrollY < window.innerHeight) {
    heroBg.style.transform = `translateY(${window.scrollY * 0.28}px)`;
  }
}, { passive: true });
</script>
</body>
</html>