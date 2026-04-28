<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DocuGo - ADFC Online Document Request System</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ── THEME VARIABLES ─────────────────────────────────── */
:root {
    --primary:    #1a3ec7;
    --primary-dk: #1230a0;
    --accent:     #3b6bff;
    --bg:         #0b1432;
    --bg2:        #0f1d45;
    --surface:    rgba(255,255,255,0.06);
    --surface-hv: rgba(255,255,255,0.10);
    --border:     rgba(255,255,255,0.10);
    --text:       #e8edf8;
    --text-muted: #8fa3cc;
    --nav-bg:     rgba(11,20,50,0.80);
}

body.light {
    --bg:         #f0f4ff;
    --bg2:        #e4ebff;
    --surface:    rgba(255,255,255,0.75);
    --surface-hv: rgba(255,255,255,0.95);
    --border:     rgba(26,62,199,0.12);
    --text:       #0d1b3e;
    --text-muted: #4b6290;
    --nav-bg:     rgba(240,244,255,0.85);
}

/* ── RESET & BASE ─────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { scroll-behavior: smooth; }

body {
    background: var(--bg);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    font-size: 16px;
    line-height: 1.6;
    transition: background .35s, color .35s;
    overflow-x: hidden;
}

/* ── DECORATIVE BLOBS ─────────────────────────────────── */
.blob {
    position: fixed;
    border-radius: 50%;
    filter: blur(90px);
    pointer-events: none;
    z-index: 0;
    transition: opacity .35s;
}
.blob-1 {
    width: 500px; height: 500px;
    background: rgba(59,107,255,0.18);
    top: -100px; left: -120px;
}
.blob-2 {
    width: 400px; height: 400px;
    background: rgba(26,62,199,0.14);
    bottom: 100px; right: -80px;
}
body.light .blob { opacity: .5; }

/* ── NAVBAR ───────────────────────────────────────────── */
.navbar {
    position: sticky;
    top: 0;
    z-index: 1000;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 48px;
    height: 68px;
    background: var(--nav-bg);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border);
    transition: background .35s, border-color .35s;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}
.logo img {
    width: 42px;
    height: 42px;
    object-fit: contain;
    border-radius: 8px;
}
.logo-text { line-height: 1.15; }
.logo-name {
    display: block;
    font-family: 'Sora', sans-serif;
    font-weight: 700;
    font-size: 14px;
    color: var(--text);
    letter-spacing: .01em;
}
.logo-sub {
    display: block;
    font-size: 10.5px;
    font-weight: 400;
    color: var(--text-muted);
    letter-spacing: .02em;
}
.nav-links {
    display: flex;
    align-items: center;
    gap: 6px;
    list-style: none;
}
.nav-links a {
    display: block;
    padding: 6px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-family: 'Sora', sans-serif;
    font-size: 13.5px;
    font-weight: 500;
    color: var(--text-muted);
    transition: color .2s, background .2s;
}
.nav-links a:hover,
.nav-links a.active { color: var(--text); background: var(--surface); }
.nav-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.btn-login {
    display: flex;
    align-items: center;
    gap: 7px;
    background: var(--primary);
    color: #fff !important;
    padding: 8px 20px;
    border-radius: 8px;
    font-family: 'Sora', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: background .2s, transform .15s, box-shadow .2s;
    box-shadow: 0 4px 16px rgba(26,62,199,0.30);
}
.btn-login:hover {
    background: var(--accent);
    transform: translateY(-1px);
    box-shadow: 0 6px 22px rgba(59,107,255,0.40);
}
.btn-login i { font-size: 13px; }
.toggle-btn {
    cursor: pointer;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text);
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .2s, transform .15s;
}
.toggle-btn:hover { background: var(--surface-hv); transform: rotate(15deg); }

/* ── HERO ─────────────────────────────────────────────── */
.hero {
    position: relative;
    min-height: 90vh;
    display: flex;
    align-items: center;
    overflow: hidden;
    z-index: 1;
}
.hero-bg {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(105deg,
            rgba(11,20,50,.97) 0%,
            rgba(11,20,50,.90) 40%,
            rgba(11,20,50,.55) 65%,
            rgba(11,20,50,.20) 100%),
        url('school.jpeg') center / cover no-repeat;
    z-index: 0;
    transition: opacity .35s;
}
body.light .hero-bg {
    background:
        linear-gradient(105deg,
            rgba(240,244,255,.97) 0%,
            rgba(240,244,255,.90) 40%,
            rgba(240,244,255,.55) 65%,
            rgba(240,244,255,.15) 100%),
        url('school.jpeg') center / cover no-repeat;
}
.hero-content {
    position: relative;
    z-index: 1;
    max-width: 600px;
    padding: 0 64px;
    animation: heroFadeUp .7s ease both;
}
@keyframes heroFadeUp {
    from { opacity: 0; transform: translateY(28px); }
    to   { opacity: 1; transform: translateY(0); }
}
.hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: rgba(59,107,255,0.15);
    border: 1px solid rgba(59,107,255,0.35);
    color: #7fa5ff;
    font-family: 'Sora', sans-serif;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .10em;
    text-transform: uppercase;
    padding: 6px 14px;
    border-radius: 100px;
    margin-bottom: 28px;
}
.hero-badge i { font-size: 10px; }
body.light .hero-badge { color: var(--primary); background: rgba(26,62,199,0.09); border-color: rgba(26,62,199,0.25); }
.hero h1 {
    font-family: 'Sora', sans-serif;
    font-size: clamp(36px, 5.5vw, 58px);
    font-weight: 800;
    line-height: 1.10;
    letter-spacing: -.02em;
    margin-bottom: 20px;
    color: var(--text);
}
.hero h1 span { color: var(--accent); }
.hero-desc {
    font-size: 16px;
    font-weight: 300;
    color: var(--text-muted);
    max-width: 460px;
    margin-bottom: 40px;
    line-height: 1.75;
}
.hero-cta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 36px;
}
.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    background: var(--primary);
    color: #fff;
    padding: 13px 28px;
    border-radius: 10px;
    font-family: 'Sora', sans-serif;
    font-size: 14.5px;
    font-weight: 600;
    text-decoration: none;
    box-shadow: 0 8px 24px rgba(26,62,199,0.35);
    transition: background .2s, transform .15s, box-shadow .2s;
}
.btn-primary:hover {
    background: var(--accent);
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(59,107,255,0.45);
}
.btn-outline {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    background: transparent;
    color: var(--text);
    padding: 13px 28px;
    border-radius: 10px;
    border: 1.5px solid var(--border);
    font-family: 'Sora', sans-serif;
    font-size: 14.5px;
    font-weight: 500;
    text-decoration: none;
    transition: background .2s, border-color .2s, transform .15s;
}
.btn-outline:hover { background: var(--surface); border-color: var(--accent); transform: translateY(-2px); }
.hero-secure {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted);
}
.hero-secure i { color: #5ccc8a; font-size: 14px; }

/* ── FEATURES ─────────────────────────────────────────── */
.features {
    position: relative;
    z-index: 1;
    padding: 100px 64px;
}
.section-header {
    text-align: center;
    margin-bottom: 56px;
}
.section-label {
    display: inline-block;
    font-family: 'Sora', sans-serif;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--accent);
    margin-bottom: 14px;
}
.section-title {
    font-family: 'Sora', sans-serif;
    font-size: clamp(26px, 3.5vw, 36px);
    font-weight: 800;
    letter-spacing: -.02em;
    color: var(--text);
    margin-bottom: 12px;
}
.section-sub {
    font-size: 15px;
    color: var(--text-muted);
    max-width: 500px;
    margin: 0 auto;
}
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 22px;
    max-width: 1100px;
    margin: 0 auto;
}
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 32px 28px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: background .25s, transform .25s, box-shadow .25s, border-color .25s;
    opacity: 0;
    animation: cardIn .55s ease forwards;
}
.card:nth-child(1) { animation-delay: .05s; }
.card:nth-child(2) { animation-delay: .12s; }
.card:nth-child(3) { animation-delay: .19s; }
.card:nth-child(4) { animation-delay: .26s; }
@keyframes cardIn {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
.card:hover {
    background: var(--surface-hv);
    transform: translateY(-5px);
    box-shadow: 0 16px 48px rgba(0,0,0,0.18);
    border-color: rgba(59,107,255,0.30);
}
.card-icon {
    width: 50px;
    height: 50px;
    border-radius: 14px;
    background: rgba(59,107,255,0.12);
    border: 1px solid rgba(59,107,255,0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: var(--accent);
    margin-bottom: 20px;
    transition: background .2s;
}
.card:hover .card-icon { background: rgba(59,107,255,0.20); }
.card h3 {
    font-family: 'Sora', sans-serif;
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 8px;
    color: var(--text);
}
.card p {
    font-size: 14px;
    color: var(--text-muted);
    line-height: 1.65;
}

/* ── ABOUT ─────────────────────────────────────────────── */
.about {
    position: relative;
    z-index: 1;
    padding: 100px 64px;
}
.about-inner {
    max-width: 1100px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 64px;
    align-items: center;
    opacity: 0;
    animation: fadeInUp 0.8s ease forwards;
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(40px); }
    to { opacity: 1; transform: translateY(0); }
}
.about-img-wrap { position: relative; }
.about-img-box {
    position: relative;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 24px 64px rgba(0,0,0,0.35);
}
.about-img-box img {
    width: 100%;
    height: 380px;
    object-fit: cover;
    display: block;
    filter: brightness(0.85);
    transition: transform 0.5s ease;
}
.about-img-box:hover img { transform: scale(1.03); }
.about-stat-pill {
    position: absolute;
    background: var(--surface);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 12px 18px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.20);
    transition: transform 0.3s ease;
}
.pill-1 { bottom: 24px; left: -20px; animation: floatPill 3s ease-in-out infinite; }
.pill-2 { top: 24px; right: -20px; animation: floatPill 3s ease-in-out infinite 0.5s; }
@keyframes floatPill {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}
.pill-num {
    font-family: 'Sora', sans-serif;
    font-size: 22px;
    font-weight: 800;
    color: var(--accent);
    line-height: 1;
}
.pill-label {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 500;
}
.about-content .section-title {
    margin-bottom: 16px;
}
.about-desc {
    font-size: 15px;
    color: var(--text-muted);
    line-height: 1.75;
}
.about-highlights {
    margin-top: 24px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.ah-item {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    color: var(--text);
    opacity: 0;
    animation: slideInRight 0.5s ease forwards;
}
.ah-item:nth-child(1) { animation-delay: 0.1s; }
.ah-item:nth-child(2) { animation-delay: 0.2s; }
.ah-item:nth-child(3) { animation-delay: 0.3s; }
.ah-item:nth-child(4) { animation-delay: 0.4s; }
@keyframes slideInRight {
    from { opacity: 0; transform: translateX(-20px); }
    to { opacity: 1; transform: translateX(0); }
}
.ah-item i { color: #5ccc8a; font-size: 15px; flex-shrink: 0; transition: transform 0.2s; }
.ah-item:hover i { transform: scale(1.2); }

/* ── SERVICES ───────────────────────────────────────────── */
.services {
    position: relative;
    z-index: 1;
    padding: 100px 64px;
    background: var(--bg2);
}
.services-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 18px;
    max-width: 1100px;
    margin: 0 auto;
}
.service-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 24px;
    display: flex;
    gap: 18px;
    align-items: flex-start;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
    opacity: 0;
    animation: fadeUp 0.6s ease forwards;
}
.service-card:nth-child(1) { animation-delay: 0.05s; }
.service-card:nth-child(2) { animation-delay: 0.10s; }
.service-card:nth-child(3) { animation-delay: 0.15s; }
.service-card:nth-child(4) { animation-delay: 0.20s; }
.service-card:nth-child(5) { animation-delay: 0.25s; }
.service-card:nth-child(6) { animation-delay: 0.30s; }
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}
.service-card:hover {
    background: var(--surface-hv);
    transform: translateY(-4px) scale(1.01);
    border-color: rgba(59,107,255,0.35);
    box-shadow: 0 12px 36px rgba(0,0,0,0.15);
}
.sc-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(59,107,255,0.12);
    border: 1px solid rgba(59,107,255,0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: var(--accent);
    flex-shrink: 0;
    transition: all 0.2s;
}
.service-card:hover .sc-icon { 
    background: rgba(59,107,255,0.22); 
    transform: rotate(5deg) scale(1.05);
}
.sc-body h3 {
    font-family: 'Sora', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 6px;
}
.sc-body p {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.6;
    margin-bottom: 10px;
}
.sc-meta {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
}
.sc-meta span {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: var(--accent);
    font-weight: 600;
    font-family: 'Sora', sans-serif;
}
.sc-meta i { font-size: 11px; }

/* ── HOW IT WORKS ───────────────────────────────────────── */
.howitworks {
    position: relative;
    z-index: 1;
    padding: 100px 64px;
}
.steps-wrap {
    position: relative;
    max-width: 1100px;
    margin: 0 auto 40px;
}
.steps-line {
    position: absolute;
    top: 36px;
    left: calc(12.5% + 18px);
    right: calc(12.5% + 18px);
    height: 2px;
    background: linear-gradient(90deg, var(--accent), rgba(59,107,255,0.15));
    z-index: 0;
}
.steps-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
    position: relative;
    z-index: 1;
}
.step-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 28px 20px 24px;
    text-align: center;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: all 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
    position: relative;
    opacity: 0;
    animation: fadeUp 0.6s 0.2s ease forwards;
}
.step-card:hover {
    background: var(--surface-hv);
    transform: translateY(-5px);
    border-color: rgba(59,107,255,0.35);
    box-shadow: 0 14px 40px rgba(0,0,0,0.16);
}
.step-num {
    position: absolute;
    top: -14px;
    left: 50%;
    transform: translateX(-50%);
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--accent);
    color: #fff;
    font-family: 'Sora', sans-serif;
    font-size: 12px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(59,107,255,0.45);
    transition: transform 0.2s;
}
.step-card:hover .step-num { transform: translateX(-50%) scale(1.1); }
.step-icon {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    background: rgba(59,107,255,0.12);
    border: 1px solid rgba(59,107,255,0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    color: var(--accent);
    margin: 0 auto 16px;
    transition: all 0.2s;
}
.step-card:hover .step-icon { transform: scale(1.08); background: rgba(59,107,255,0.22); }
.step-card h3 {
    font-family: 'Sora', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 8px;
}
.step-card p {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.65;
}
.howitworks-note {
    max-width: 680px;
    margin: 0 auto;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: rgba(59,107,255,0.08);
    border: 1px solid rgba(59,107,255,0.22);
    border-radius: 12px;
    padding: 16px 20px;
    font-size: 14px;
    color: var(--text-muted);
    line-height: 1.65;
    opacity: 0;
    animation: fadeUp 0.6s 0.4s ease forwards;
}
.howitworks-note i {
    color: var(--accent);
    font-size: 18px;
    flex-shrink: 0;
    margin-top: 2px;
}
.howitworks-note strong { color: var(--text); }

/* ── CONTACT ────────────────────────────────────────────── */
.contact {
    position: relative;
    z-index: 1;
    padding: 100px 64px;
    background: var(--bg2);
}
.contact-grid {
    max-width: 1100px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: 1fr 1.4fr;
    gap: 48px;
    align-items: start;
}
.contact-info {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.ci-card {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px 20px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    transition: all 0.3s ease;
    opacity: 0;
    animation: slideInLeft 0.5s ease forwards;
}
.ci-card:nth-child(1) { animation-delay: 0.05s; }
.ci-card:nth-child(2) { animation-delay: 0.10s; }
.ci-card:nth-child(3) { animation-delay: 0.15s; }
.ci-card:nth-child(4) { animation-delay: 0.20s; }
@keyframes slideInLeft {
    from { opacity: 0; transform: translateX(-30px); }
    to { opacity: 1; transform: translateX(0); }
}
.ci-card:hover { 
    background: var(--surface-hv); 
    border-color: rgba(59,107,255,0.30); 
    transform: translateX(8px);
}
.ci-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(59,107,255,0.12);
    border: 1px solid rgba(59,107,255,0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: var(--accent);
    flex-shrink: 0;
    transition: transform 0.2s;
}
.ci-card:hover .ci-icon { transform: scale(1.05); }
.ci-card h4 {
    font-family: 'Sora', sans-serif;
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 4px;
}
.ci-card p {
    font-size: 13px;
    color: var(--text-muted);
    line-height: 1.6;
}
.contact-form-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 32px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    opacity: 0;
    animation: fadeUp 0.6s 0.3s ease forwards;
}
.contact-form { display: flex; flex-direction: column; gap: 16px; }
.cf-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
.cf-group { display: flex; flex-direction: column; gap: 6px; }
.cf-group label {
    font-size: 12.5px;
    font-weight: 600;
    font-family: 'Sora', sans-serif;
    color: var(--text-muted);
    letter-spacing: .02em;
}
.cf-group input,
.cf-group select,
.cf-group textarea {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 10px 14px;
    font-size: 14px;
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    outline: none;
    transition: border-color .2s, box-shadow .2s, transform .2s;
    resize: none;
}
.cf-group input:focus,
.cf-group select:focus,
.cf-group textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(59,107,255,0.12);
    transform: scale(1.01);
}
.cf-group select option { background: var(--bg); color: var(--text); }

/* ── CTA BANNER ───────────────────────────────────────── */
.cta-section {
    position: relative;
    z-index: 1;
    padding: 0 64px 100px;
}
.cta-card {
    max-width: 1100px;
    margin: 0 auto;
    background: linear-gradient(120deg, var(--primary-dk) 0%, var(--accent) 100%);
    border-radius: 24px;
    padding: 56px 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 32px;
    overflow: hidden;
    position: relative;
    box-shadow: 0 24px 80px rgba(26,62,199,0.35);
    opacity: 0;
    animation: heroFadeUp 0.7s 0.1s ease forwards;
}
.cta-card::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 260px; height: 260px;
    border-radius: 50%;
    background: rgba(255,255,255,0.07);
    pointer-events: none;
    animation: pulseGlow 4s ease-in-out infinite;
}
@keyframes pulseGlow {
    0%, 100% { transform: scale(1); opacity: 0.7; }
    50% { transform: scale(1.1); opacity: 1; }
}
.cta-card::after {
    content: '';
    position: absolute;
    bottom: -80px; left: 30%;
    width: 200px; height: 200px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    pointer-events: none;
}
.cta-icon-wrap {
    flex-shrink: 0;
    width: 70px;
    height: 70px;
    border-radius: 18px;
    background: rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    color: #fff;
    transition: transform 0.3s;
}
.cta-card:hover .cta-icon-wrap { transform: rotate(15deg) scale(1.05); }
.cta-text { flex: 1; }
.cta-text h2 {
    font-family: 'Sora', sans-serif;
    font-size: clamp(20px, 2.5vw, 26px);
    font-weight: 800;
    color: #fff;
    margin-bottom: 6px;
}
.cta-text p { font-size: 15px; color: rgba(255,255,255,0.72); }
.btn-white {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    background: #fff;
    color: var(--primary);
    padding: 13px 28px;
    border-radius: 10px;
    font-family: 'Sora', sans-serif;
    font-size: 14.5px;
    font-weight: 700;
    text-decoration: none;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    transition: transform .15s, box-shadow .2s;
}
.btn-white:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,0,0,0.22); }

/* ── FOOTER ───────────────────────────────────────────── */
footer {
    position: relative;
    z-index: 1;
    border-top: 1px solid var(--border);
    background: var(--bg2);
    padding: 22px 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: background .35s;
}
.footer-copy {
    font-size: 13px;
    color: var(--text-muted);
}
.footer-socials {
    display: flex;
    gap: 14px;
}
.footer-socials a {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    text-decoration: none;
    transition: all 0.3s ease;
}
.footer-socials a:hover { 
    color: var(--accent); 
    background: var(--surface-hv); 
    border-color: var(--accent); 
    transform: translateY(-3px) scale(1.1);
}

/* Scroll to top button */
.scroll-top {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--accent);
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    z-index: 99;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}
.scroll-top.show {
    opacity: 1;
    visibility: visible;
}
.scroll-top:hover {
    transform: translateY(-3px) scale(1.05);
    background: var(--primary);
}

/* ── RESPONSIVE ───────────────────────────────────────── */
@media (max-width: 900px) {
    .navbar { padding: 0 24px; }
    .hero-content { padding: 60px 24px; }
    .features, .cta-section, .about, .services, .howitworks, .contact { padding-left: 24px; padding-right: 24px; }
    footer { padding: 20px 24px; }
    .cta-card { flex-direction: column; text-align: center; padding: 40px 28px; }
    .cta-icon-wrap { margin: 0 auto; }
    .about-inner { grid-template-columns: 1fr; gap: 40px; }
    .about-img-box img { height: 260px; }
    .pill-1, .pill-2 { display: none; }
    .steps-grid { grid-template-columns: repeat(2, 1fr); }
    .steps-line { display: none; }
    .contact-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .nav-links { display: none; }
    .hero h1 { font-size: 32px; }
    .hero-cta { flex-direction: column; }
    .btn-primary, .btn-outline { justify-content: center; }
    .cards-grid { grid-template-columns: 1fr; }
    footer { flex-direction: column; gap: 14px; text-align: center; }
    .services-grid { grid-template-columns: 1fr; }
    .steps-grid { grid-template-columns: 1fr; }
    .cf-row { grid-template-columns: 1fr; }
    .about, .services, .howitworks, .contact { padding: 60px 24px; }
}
</style>
</head>
<body>

<!-- DECORATIVE BLOBS -->
<div class="blob blob-1"></div>
<div class="blob blob-2"></div>

<!-- ── NAVBAR ──────────────────────────────────────────── -->
<header class="navbar">
    <a href="land.php" class="logo">
        <img src="logo.png" alt="ADFC Logo">
        <div class="logo-text">
            <span class="logo-name">Asia Development Foundation College</span>
            <span class="logo-sub">Online Document Request System</span>
        </div>
    </a>
    <ul class="nav-links">
        <li><a href="#" class="active">Home</a></li>
        <li><a href="#about">About</a></li>
        <li><a href="#services">Services</a></li>
        <li><a href="#how-it-works">How It Works</a></li>
        <li><a href="#contact">Contact</a></li>
    </ul>
    <div class="nav-actions">
        <button class="toggle-btn" id="themeToggle" title="Toggle theme">🌙</button>
        <a href="login.php" class="btn-login">
            <i class="fas fa-user"></i> Login
        </a>
    </div>
</header>

<!-- ── HERO ────────────────────────────────────────────── -->
<section class="hero">
    <div class="hero-bg"></div>
    <div class="hero-content">
        <div class="hero-badge">
            <i class="fas fa-circle-dot"></i>
            Online Document Request System
        </div>
        <h1>Request Documents<br><span>Anywhere, Anytime</span></h1>
        <p class="hero-desc">A fast, secure, and convenient way for students and alumni to request academic and other official documents online.</p>
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
            Your data is secure and protected.
        </div>
    </div>
</section>

<!-- ── FEATURES ────────────────────────────────────────── -->
<section class="features" id="features">
    <div class="section-header">
        <span class="section-label">Why Choose Us</span>
        <h2 class="section-title">Why Use Our Online Document Request System?</h2>
        <p class="section-sub">Everything you need to manage your document requests — fast, secure, and stress-free.</p>
    </div>
    <div class="cards-grid">
        <div class="card"><div class="card-icon"><i class="fas fa-clock"></i></div><h3>Fast &amp; Convenient</h3><p>Request documents online anytime, anywhere. No long lines, no hassle, no wasted trips to campus.</p></div>
        <div class="card"><div class="card-icon"><i class="fas fa-shield-halved"></i></div><h3>Secure &amp; Reliable</h3><p>Your information is handled with the highest security standards. Your privacy is our priority.</p></div>
        <div class="card"><div class="card-icon"><i class="fas fa-file-lines"></i></div><h3>Track Your Requests</h3><p>Monitor the status of your requests in real-time from submission through processing to release.</p></div>
        <div class="card"><div class="card-icon"><i class="fas fa-bell"></i></div><h3>Stay Updated</h3><p>Get notified about your request updates and when your document is ready for pickup or delivery.</p></div>
    </div>
</section>

<!-- ── ABOUT ───────────────────────────────────────────── -->
<section class="about" id="about">
    <div class="about-inner">
        <div class="about-img-wrap">
            <div class="about-img-box">
                <img src="school.jpeg" alt="ADFC Campus">
                <div class="about-stat-pill pill-1"><span class="pill-num">500+</span><span class="pill-label">Documents Processed</span></div>
                <div class="about-stat-pill pill-2"><span class="pill-num">98%</span><span class="pill-label">Satisfaction Rate</span></div>
            </div>
        </div>
        <div class="about-content">
            <span class="section-label">About DocuGo</span>
            <h2 class="section-title" style="text-align:left;">Built for ADFC Students &amp; Alumni</h2>
            <p class="about-desc">DocuGo is the official Online Document Request and Graduate Tracer System of <strong>Asian Development Foundation College</strong>. Designed to eliminate long queues and manual paperwork, DocuGo provides a streamlined digital platform where students and alumni can request, track, and claim their official academic documents — anytime, from anywhere.</p>
            <p class="about-desc" style="margin-top:12px;">The system also serves as a Graduate Tracer platform, helping the institution monitor alumni employment outcomes for continuous curriculum improvement and accreditation compliance.</p>
            <div class="about-highlights">
                <div class="ah-item"><i class="fas fa-check-circle"></i><span>No more physical queues or paper forms</span></div>
                <div class="ah-item"><i class="fas fa-check-circle"></i><span>Real-time request status tracking</span></div>
                <div class="ah-item"><i class="fas fa-check-circle"></i><span>Alumni Graduate Tracer Survey built-in</span></div>
                <div class="ah-item"><i class="fas fa-check-circle"></i><span>Secure, verified accounts for all users</span></div>
            </div>
        </div>
    </div>
</section>

<!-- ── SERVICES ───────────────────────────────────────── -->
<section class="services" id="services">
    <div class="section-header">
        <span class="section-label">Our Services</span>
        <h2 class="section-title">Documents You Can Request</h2>
        <p class="section-sub">All official academic documents are available for online request through DocuGo.</p>
    </div>
    <div class="services-grid">
        <div class="service-card"><div class="sc-icon"><i class="fas fa-scroll"></i></div><div class="sc-body"><h3>Transcript of Records</h3><p>Official academic transcript reflecting your complete scholastic record.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~5 days</span><span><i class="fas fa-peso-sign"></i> ₱100.00 / copy</span></div></div></div>
        <div class="service-card"><div class="sc-icon"><i class="fas fa-id-card"></i></div><div class="sc-body"><h3>Certificate of Enrollment</h3><p>Proof of enrollment for scholarship, employment, or other requirements.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~1 day</span><span><i class="fas fa-peso-sign"></i> ₱30.00 / copy</span></div></div></div>
        <div class="service-card"><div class="sc-icon"><i class="fas fa-graduation-cap"></i></div><div class="sc-body"><h3>Certificate of Graduation</h3><p>Official certification confirming completion of academic program.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~3 days</span><span><i class="fas fa-peso-sign"></i> ₱50.00 / copy</span></div></div></div>
        <div class="service-card"><div class="sc-icon"><i class="fas fa-heart"></i></div><div class="sc-body"><h3>Good Moral Certificate</h3><p>Character reference letter issued by the institution.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~2 days</span><span><i class="fas fa-peso-sign"></i> ₱30.00 / copy</span></div></div></div>
        <div class="service-card"><div class="sc-icon"><i class="fas fa-certificate"></i></div><div class="sc-body"><h3>Diploma (Replacement)</h3><p>Replacement copy of your official diploma for lost or damaged originals.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~10 days</span><span><i class="fas fa-peso-sign"></i> ₱500.00</span></div></div></div>
        <div class="service-card"><div class="sc-icon"><i class="fas fa-stamp"></i></div><div class="sc-body"><h3>Authentication</h3><p>Official document authentication for local and international use.</p><div class="sc-meta"><span><i class="fas fa-clock"></i> ~3 days</span><span><i class="fas fa-peso-sign"></i> ₱50.00 / copy</span></div></div></div>
    </div>
    <div style="text-align:center;margin-top:40px;"><a href="login.php" class="btn-primary"><i class="fas fa-file-alt"></i> Request a Document Now</a></div>
</section>

<!-- ── HOW IT WORKS ───────────────────────────────────── -->
<section class="howitworks" id="how-it-works">
    <div class="section-header">
        <span class="section-label">How It Works</span>
        <h2 class="section-title">Request Your Document in 4 Easy Steps</h2>
        <p class="section-sub">DocuGo follows a simple Pay-on-Claim process — no online payment needed.</p>
    </div>
    <div class="steps-wrap"><div class="steps-line"></div><div class="steps-grid">
        <div class="step-card"><div class="step-num">1</div><div class="step-icon"><i class="fas fa-user-plus"></i></div><h3>Create an Account</h3><p>Register as a student or alumni and verify your email to activate your account.</p></div>
        <div class="step-card"><div class="step-num">2</div><div class="step-icon"><i class="fas fa-file-circle-plus"></i></div><h3>Submit a Request</h3><p>Select the document type, number of copies, purpose, and preferred release date.</p></div>
        <div class="step-card"><div class="step-num">3</div><div class="step-icon"><i class="fas fa-magnifying-glass"></i></div><h3>Track Your Request</h3><p>Monitor your request status in real-time — from Pending to Approved to Ready.</p></div>
        <div class="step-card"><div class="step-num">4</div><div class="step-icon"><i class="fas fa-hand-holding-dollar"></i></div><h3>Pay &amp; Claim</h3><p>Visit the Registrar's Office, present your claim stub, pay the fee, and get your document.</p></div>
    </div></div>
    <div class="howitworks-note"><i class="fas fa-circle-info"></i><div><strong>Pay-on-Claim System</strong> — No online payment required. Payment is made in cash at the Registrar's Office only when you claim your document. Your claim stub will serve as your payment reference.</div></div>
</section>

<!-- ── CONTACT ────────────────────────────────────────── -->
<section class="contact" id="contact">
    <div class="section-header">
        <span class="section-label">Contact Us</span>
        <h2 class="section-title">Need Help? Reach Out to Us</h2>
        <p class="section-sub">For concerns about your document requests, contact the Registrar's Office directly.</p>
    </div>
    <div class="contact-grid">
        <div class="contact-info">
            <div class="ci-card"><div class="ci-icon"><i class="fas fa-location-dot"></i></div><div><h4>Address</h4><p>Asian Development Foundation College<br>Maasin City, Southern Leyte, Philippines</p></div></div>
            <div class="ci-card"><div class="ci-icon"><i class="fas fa-phone"></i></div><div><h4>Phone</h4><p>(053) 000-0000<br>Mon – Fri, 8:00 AM – 5:00 PM</p></div></div>
            <div class="ci-card"><div class="ci-icon"><i class="fas fa-envelope"></i></div><div><h4>Email</h4><p>registrar@adfc.edu.ph<br>docugo@adfc.edu.ph</p></div></div>
            <div class="ci-card"><div class="ci-icon"><i class="fas fa-clock"></i></div><div><h4>Office Hours</h4><p>Monday – Friday<br>8:00 AM – 5:00 PM</p></div></div>
        </div>
        <div class="contact-form-wrap">
            <form class="contact-form" id="contactForm" onsubmit="submitContact(event)">
                <div class="cf-row"><div class="cf-group"><label>Full Name</label><input type="text" placeholder="Juan dela Cruz" required></div><div class="cf-group"><label>Email Address</label><input type="email" placeholder="you@email.com" required></div></div>
                <div class="cf-group"><label>Subject</label><select><option value="">-- Select subject --</option><option>Document Request Inquiry</option><option>Request Status Follow-up</option><option>Account / Login Issue</option><option>Graduate Tracer Concern</option><option>Other</option></select></div>
                <div class="cf-group"><label>Message</label><textarea rows="5" placeholder="Describe your concern…" required></textarea></div>
                <button type="submit" class="btn-primary" style="width:100%;justify-content:center;"><i class="fas fa-paper-plane"></i> Send Message</button>
                <div id="cf-success" style="display:none;margin-top:1rem;padding:0.85rem;background:rgba(92,204,138,0.12);border:1px solid rgba(92,204,138,0.3);border-radius:8px;color:#5ccc8a;font-size:14px;text-align:center;">✅ Message sent! We'll get back to you within 1–2 business days.</div>
            </form>
        </div>
    </div>
</section>

<!-- ── CTA BANNER ──────────────────────────────────────── -->
<section class="cta-section">
    <div class="cta-card">
        <div class="cta-icon-wrap"><i class="fas fa-folder-open"></i></div>
        <div class="cta-text"><h2>Ready to get started?</h2><p>Sign in to your account and request your documents now.</p></div>
        <a href="login.php" class="btn-white"><i class="fas fa-user"></i> Login to Your Account</a>
    </div>
</section>

<!-- ── FOOTER ──────────────────────────────────────────── -->
<footer>
    <span class="footer-copy">© 2024 Asia Development Foundation College. All rights reserved.</span>
    <div class="footer-socials">
        <a href="https://web.facebook.com/adfcofficial/?_rdc=1&_rdr#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
        <a href="https://www.instagram.com/explore/locations/110717146946286/asian-development-foundation-college-official/" title="Instagram"><i class="fab fa-instagram"></i></a>
        <a href="mailto:info@adfcollege.edu.ph" title="Email"><i class="fas fa-envelope"></i></a>
    </div>
</footer>

<!-- Scroll to top button -->
<button class="scroll-top" id="scrollTopBtn" aria-label="Scroll to top">
    <i class="fas fa-arrow-up"></i>
</button>

<script>
// Theme handling
const savedTheme = localStorage.getItem("theme");
if (savedTheme === "light") {
    document.body.classList.add("light");
    document.getElementById("themeToggle").textContent = "☀️";
}
document.getElementById("themeToggle").addEventListener("click", () => {
    const isLight = document.body.classList.toggle("light");
    localStorage.setItem("theme", isLight ? "light" : "dark");
    document.getElementById("themeToggle").textContent = isLight ? "☀️" : "🌙";
});

// Active nav on scroll with smooth highlight
const sections = document.querySelectorAll("section[id]");
const navLinks = document.querySelectorAll(".nav-links a");
window.addEventListener("scroll", () => {
    let current = "";
    sections.forEach(s => { 
        const sectionTop = s.offsetTop - 160;
        if (window.scrollY >= sectionTop) current = s.id; 
    });
    navLinks.forEach(a => {
        a.classList.remove("active");
        if (a.getAttribute("href") === `#${current}`) a.classList.add("active");
    });
    
    // Scroll to top button visibility
    const scrollBtn = document.getElementById('scrollTopBtn');
    if (window.scrollY > 300) {
        scrollBtn.classList.add('show');
    } else {
        scrollBtn.classList.remove('show');
    }
});

// Scroll to top functionality
document.getElementById('scrollTopBtn').addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

// Contact form simulation with fade effect
function submitContact(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    btn.disabled = true;
    setTimeout(() => {
        const successDiv = document.getElementById('cf-success');
        successDiv.style.display = 'block';
        successDiv.style.opacity = '0';
        setTimeout(() => successDiv.style.opacity = '1', 10);
        btn.innerHTML = '<i class="fas fa-check"></i> Sent!';
        btn.style.background = '#5ccc8a';
        e.target.reset();
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.style.background = '';
            btn.disabled = false;
            const successDiv = document.getElementById('cf-success');
            successDiv.style.opacity = '0';
            setTimeout(() => successDiv.style.display = 'none', 300);
        }, 3000);
    }, 1200);
}
</script>
</body>
</html>