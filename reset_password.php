<?php
// ================================================================
// reset_password.php
// Step 2 — User clicks the emailed link, enters a new password.
//
// URL format: reset_password.php?token=<64-char-hex>
//
// Place this file in the ROOT of your DocuGo project (same level
// as login.php and forgot_password.php).
// ================================================================
require_once 'includes/config.php';

// Already logged in? Redirect away
if (isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/student/dashboard.php');
    exit();
}

$token  = trim($_GET['token'] ?? '');
$error  = '';
$step   = 'form';   // 'invalid' | 'form' | 'done'

// ── Validate token immediately on GET ────────────────────────
if (empty($token)) {
    $step = 'invalid';
} else {
    $conn = getConnection();

    $stmt = $conn->prepare("
        SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at,
               u.first_name, u.last_name, u.email, u.status
        FROM   password_resets pr
        JOIN   users           u  ON pr.user_id = u.id
        WHERE  pr.token = ?
        LIMIT  1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $reset = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reset) {
        $step = 'invalid'; // token doesn't exist
    } elseif ($reset['used_at'] !== null) {
        $step = 'invalid'; // already used
    } elseif (strtotime($reset['expires_at']) < time()) {
        $step = 'invalid'; // expired
    } elseif ($reset['status'] !== 'active') {
        $step = 'invalid'; // account not active
    }

    // ── Handle POST (form submission) ────────────────────────
    if ($step === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $password     = $_POST['password']      ?? '';
        $confirmPass  = $_POST['confirm_pass']  ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPass) {
            $error = 'Passwords do not match. Please try again.';
        } else {
            // ── Hash and update ───────────────────────────────
            $hashed = password_hash($password, PASSWORD_BCRYPT);

            $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $upd->bind_param("si", $hashed, $reset['user_id']);
            $upd->execute();
            $upd->close();

            // ── Mark token as used ────────────────────────────
            $mark = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
            $mark->bind_param("i", $reset['id']);
            $mark->execute();
            $mark->close();

            // ── Invalidate all other tokens for this user ──────
            $del = $conn->prepare("
                DELETE FROM password_resets
                WHERE user_id = ? AND id != ?
            ");
            $del->bind_param("ii", $reset['user_id'], $reset['id']);
            $del->execute();
            $del->close();

            // ── Destroy any active session for security ────────
            session_regenerate_id(true);

            $step = 'done';
        }
    }

    $conn->close();
}

function escape($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — ADFC DocuGo</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ========== THEME VARIABLES ========== */
        :root {
            --primary:    #1a3ec7;
            --primary-dk: #1230a0;
            --accent:     #3b6bff;
            --accent2:    #6b9fff;
            --bg:         #080e28;
            --bg2:        #0b1535;
            --bg3:        #0e1c42;
            --surface:    rgba(255,255,255,0.06);
            --surface-hv: rgba(255,255,255,0.1);
            --border:     rgba(255,255,255,0.08);
            --border-hv:  rgba(59,107,255,0.35);
            --text:       #dce6f8;
            --text-muted: #7a96c4;
            --text-dim:   #4a6190;
            --green:      #4cd98a;
            --radius-sm:  8px;
            --radius-md:  14px;
            --radius-lg:  20px;
            --radius-xl:  28px;
            --ease-out:   cubic-bezier(0.16, 1, 0.3, 1);
            --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
            --overlay-dark: rgba(8,14,40,0.82);
            --overlay-light: rgba(238,242,255,0.78);
        }

        body.light {
            --bg:         #eef2ff;
            --bg2:        #e2e9ff;
            --bg3:        #d8e2ff;
            --surface:    rgba(255,255,255,0.75);
            --surface-hv: rgba(255,255,255,0.95);
            --border:     rgba(26,62,199,0.12);
            --border-hv:  rgba(26,62,199,0.30);
            --text:       #0c1836;
            --text-muted: #3d5a92;
            --text-dim:   #7a96c4;
            --overlay-dark: rgba(238,242,255,0.85);
            --overlay-light: rgba(238,242,255,0.92);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            transition: background 0.4s, color 0.4s;
        }

        /* ========== SCHOOL BACKGROUND ========== */
        .school-background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }

        .school-background img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.7) saturate(1.1);
            transition: filter 0.4s;
        }

        body.light .school-background img {
            filter: brightness(0.95) saturate(1.05);
        }

        .bg-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--overlay-dark) 0%, rgba(8,14,40,0.65) 100%);
            transition: background 0.4s;
        }

        body.light .bg-overlay {
            background: linear-gradient(135deg, var(--overlay-light) 0%, rgba(238,242,255,0.7) 100%);
        }

        /* Ambient blobs */
        .blob-bg {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 1;
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            animation: blobDrift 18s ease-in-out infinite alternate;
        }

        .blob-1 {
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(59,107,255,0.16) 0%, transparent 70%);
            top: -150px;
            left: -120px;
        }

        .blob-2 {
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(26,62,199,0.13) 0%, transparent 70%);
            bottom: -80px;
            right: -100px;
        }

        .blob-3 {
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(107,159,255,0.1) 0%, transparent 70%);
            top: 50%;
            left: 75%;
            transform: translate(-50%, -50%);
        }

        @keyframes blobDrift {
            0%   { transform: translate(0,0) scale(1); }
            33%  { transform: translate(30px,-20px) scale(1.05); }
            66%  { transform: translate(-20px,30px) scale(0.96); }
            100% { transform: translate(10px,10px) scale(1.02); }
        }

        body.light .blob {
            opacity: 0.45;
        }

        /* Noise texture */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.035'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 2;
            opacity: 0.4;
        }
        body.light::before { opacity: 0.12; }

        /* Card */
        .card {
            position: relative;
            z-index: 10;
            background: var(--surface);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            width: 100%;
            max-width: 460px;
            padding: 2.5rem 2rem 2.2rem;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.4);
            transition: background 0.3s, border-color 0.3s, box-shadow 0.3s;
            animation: cardAppear 0.6s var(--ease-out);
        }

        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(28px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Logo */
        .logo-area {
            text-align: center;
            margin-bottom: 1.8rem;
        }

        .logo-img {
            width: 64px;
            height: 64px;
            object-fit: contain;
            margin: 0 auto 0.75rem;
            transition: transform 0.3s var(--ease-spring);
            border-radius: 16px;
        }

        .logo-img:hover {
            transform: rotate(-5deg) scale(1.05);
        }

        .school-name {
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 1.35rem;
            letter-spacing: -0.02em;
            color: var(--text);
            line-height: 1.2;
        }

        .system-sub {
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--accent2);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .card-title {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
            text-align: center;
            margin-bottom: 0.5rem;
        }

        .card-subtitle {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: 1.4rem;
            line-height: 1.55;
        }

        /* Alert */
        .alert-error {
            background: rgba(220, 38, 38, 0.12);
            border-left: 3px solid #ef4444;
            border-radius: var(--radius-sm);
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            color: #f9acac;
            margin-bottom: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        body.light .alert-error {
            color: #b91c1c;
            background: rgba(220, 38, 38, 0.08);
        }

        /* State screens */
        .state-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.2rem;
        }
        .state-icon.success { background: rgba(76,217,138,0.15); color: var(--green); }
        .state-icon.error   { background: rgba(248,113,113,0.15); color: var(--red); }
        .state-title {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            color: var(--text);
            text-align: center;
            margin-bottom: 0.6rem;
        }
        .state-body {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-align: center;
            line-height: 1.65;
            margin-bottom: 1.4rem;
        }

        /* Form */
        .form-group { margin-bottom: 1rem; }

        label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            font-family: 'Sora', sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-dim);
            margin-bottom: 0.5rem;
        }

        label i {
            margin-right: 6px;
        }

        .password-wrap { position: relative; }

        input[type="password"],
        input[type="text"] {
            width: 100%;
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 0.85rem 1rem;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.85rem;
            color: var(--text);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 107, 255, 0.2);
            background: var(--surface-hv);
        }

        .toggle-pw {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(59, 107, 255, 0.12);
            border: none;
            font-size: 0.68rem;
            font-weight: 700;
            font-family: 'Sora', sans-serif;
            color: var(--accent2);
            cursor: pointer;
            padding: 4px 10px;
            border-radius: 20px;
            transition: background 0.2s;
        }

        .toggle-pw:hover {
            background: rgba(59, 107, 255, 0.28);
            color: var(--accent);
        }

        /* Strength meter */
        .strength-wrap { margin-top: 0.4rem; }
        .strength-track {
            height: 4px;
            background: var(--border);
            border-radius: 4px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: width 0.3s, background 0.3s;
        }
        .strength-text {
            font-size: 0.68rem;
            color: var(--text-dim);
            margin-top: 4px;
        }

        /* Requirements checklist */
        .reqs {
            list-style: none;
            margin: 8px 0 0;
            padding: 0;
        }
        .reqs li {
            font-size: 0.7rem;
            color: var(--text-dim);
            padding: 2px 0;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s;
        }
        .reqs li.ok { color: var(--green); }
        .reqs li::before { content: '○'; font-size: 0.6rem; flex-shrink: 0; }
        .reqs li.ok::before { content: '✓'; }

        /* Button */
        .btn {
            width: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.85rem;
            font-family: 'Sora', sans-serif;
            font-weight: 700;
            font-size: 0.85rem;
            color: white;
            margin-top: 0.5rem;
            cursor: pointer;
            transition: transform 0.2s var(--ease-spring), box-shadow 0.2s;
            box-shadow: 0 8px 20px rgba(26, 62, 199, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(59, 107, 255, 0.45);
            background: linear-gradient(135deg, var(--primary-dk) 0%, var(--accent2) 100%);
        }

        .btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.1rem 0;
            color: var(--text-dim);
            font-size: 0.7rem;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-top: 1px solid var(--border);
        }

        .link-row {
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .link-row a {
            color: var(--accent2);
            font-weight: 700;
            text-decoration: none;
        }

        .link-row a:hover {
            text-decoration: underline;
        }

        .match-message {
            font-size: 0.7rem;
            margin-top: 4px;
            display: none;
        }

        /* Theme Toggle */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--surface);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text);
            font-size: 1.1rem;
            z-index: 99;
            transition: transform 0.2s;
        }

        .theme-toggle:hover {
            transform: scale(1.1);
            background: var(--surface-hv);
        }

        /* Responsive */
        @media (max-width: 520px) {
            .card {
                padding: 1.8rem 1.5rem;
            }
            .school-name {
                font-size: 1.15rem;
            }
            .logo-img {
                width: 54px;
                height: 54px;
            }
        }
    </style>
</head>
<body>

<!-- School Background -->
<div class="school-background">
    <img src="school.jpeg" alt="Asian Development Foundation College Campus" onerror="this.src='https://placehold.co/1920x1080/1a3ec7/white?text=ADFC+Campus'">
    <div class="bg-overlay"></div>
</div>

<!-- Ambient Blobs -->
<div class="blob-bg">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
</div>

<!-- Main Card -->
<div class="card">
    <div class="logo-area">
        <img id="siteLogo" class="logo-img" src="wlogo.png" alt="ADFC Logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%231a3ec7%22/%3E%3Ctext x=%2250%22 y=%2265%22 font-size=%2236%22 text-anchor=%22middle%22 fill=%22white%22%3EADFC%3C/text%3E%3C/svg%3E'">
        <div class="school-name">Asian Development Foundation College</div>
        <div class="system-sub">DOCUGO — ONLINE DOCUMENT REQUEST SYSTEM</div>
    </div>

    <?php if ($step === 'invalid'): ?>
    <!-- INVALID / EXPIRED TOKEN -->
    <div class="state-icon error"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="state-title">Link Invalid or Expired</div>
    <div class="state-body">
        This password reset link is invalid, has already been used,
        or has expired (links are valid for 1 hour only).
        <br><br>
        Please request a new reset link.
    </div>
    <a href="forgot_password.php" class="btn" style="display:block;text-align:center;text-decoration:none;line-height:1.5">
        <i class="fas fa-redo-alt"></i> Request New Link
    </a>
    <div class="divider"></div>
    <div class="link-row"><a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a></div>

    <?php elseif ($step === 'done'): ?>
    <!-- SUCCESS -->
    <div class="state-icon success"><i class="fas fa-check-circle"></i></div>
    <div class="state-title">Password Reset!</div>
    <div class="state-body">
        Your password has been updated successfully.
        You can now log in with your new password.
    </div>
    <a href="login.php" class="btn" style="display:block;text-align:center;text-decoration:none;line-height:1.5">
        <i class="fas fa-sign-in-alt"></i> Login Now
    </a>

    <?php else: ?>
    <!-- RESET FORM -->
    <div class="card-title">Set New Password</div>
    <div class="card-subtitle">
        Hello, <strong><?= escape($reset['first_name'] ?? 'User') ?></strong>.
        Choose a strong new password for your account.
    </div>

    <?php if ($error): ?>
    <div class="alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?= escape($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="reset_password.php?token=<?= urlencode($token) ?>" id="resetForm">
        <div class="form-group">
            <label for="password"><i class="fas fa-lock"></i> New Password</label>
            <div class="password-wrap">
                <input type="password" id="password" name="password" placeholder="Min. 8 characters" required autofocus autocomplete="new-password">
                <button type="button" class="toggle-pw" onclick="togglePassword('password', this)">Show</button>
            </div>
            <div class="strength-wrap">
                <div class="strength-track"><div class="strength-bar" id="strengthBar"></div></div>
                <div class="strength-text" id="strengthText"></div>
            </div>
            <ul class="reqs" id="reqs">
                <li id="req-len"><i class="fas fa-circle"></i> At least 8 characters</li>
                <li id="req-upper"><i class="fas fa-circle"></i> One uppercase letter</li>
                <li id="req-num"><i class="fas fa-circle"></i> One number</li>
                <li id="req-special"><i class="fas fa-circle"></i> One special character (!@#$…)</li>
            </ul>
        </div>

        <div class="form-group">
            <label for="confirm_pass"><i class="fas fa-check-circle"></i> Confirm New Password</label>
            <div class="password-wrap">
                <input type="password" id="confirm_pass" name="confirm_pass" placeholder="Repeat new password" required autocomplete="new-password">
                <button type="button" class="toggle-pw" onclick="togglePassword('confirm_pass', this)">Show</button>
            </div>
            <div id="matchMsg" class="match-message"></div>
        </div>

        <button type="submit" class="btn" id="submitBtn" disabled>
            <i class="fas fa-key"></i> Reset Password
        </button>
    </form>

    <div class="divider"></div>
    <div class="link-row"><a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a></div>

    <script>
    function togglePassword(id, btn) {
        const inp = document.getElementById(id);
        const isPassword = inp.type === 'password';
        inp.type = isPassword ? 'text' : 'password';
        btn.textContent = isPassword ? 'Hide' : 'Show';
    }

    // Password strength and validation
    const pwInput = document.getElementById('password');
    const cpInput = document.getElementById('confirm_pass');
    const bar = document.getElementById('strengthBar');
    const txt = document.getElementById('strengthText');
    const matchMsg = document.getElementById('matchMsg');
    const submitBtn = document.getElementById('submitBtn');

    const levels = [
        { w: '0%', color: '', label: '' },
        { w: '25%', color: '#ef4444', label: 'Weak' },
        { w: '50%', color: '#f59e0b', label: 'Fair' },
        { w: '75%', color: '#3b82f6', label: 'Good' },
        { w: '100%', color: '#22c55e', label: 'Strong' }
    ];

    function checkReq(id, ok) {
        const el = document.getElementById(id);
        el.classList.toggle('ok', ok);
        if (ok) {
            el.innerHTML = '<i class="fas fa-check-circle"></i> ' + el.innerHTML.replace(/.*?<\/i>/, '');
        } else {
            el.innerHTML = '<i class="fas fa-circle"></i> ' + el.innerHTML.replace(/.*?<\/i>/, '');
        }
    }

    function validate() {
        const pw = pwInput.value;
        const cp = cpInput.value;

        const hasLen = pw.length >= 8;
        const hasUpper = /[A-Z]/.test(pw);
        const hasNum = /[0-9]/.test(pw);
        const hasSpecial = /[^A-Za-z0-9]/.test(pw);

        checkReq('req-len', hasLen);
        checkReq('req-upper', hasUpper);
        checkReq('req-num', hasNum);
        checkReq('req-special', hasSpecial);

        const score = [hasLen, hasUpper, hasNum, hasSpecial].filter(Boolean).length;
        bar.style.width = levels[score].w;
        bar.style.background = levels[score].color;
        txt.textContent = levels[score].label;
        txt.style.color = levels[score].color;

        const allReqsMet = hasLen && hasUpper && hasNum && hasSpecial;

        if (cp.length > 0) {
            matchMsg.style.display = 'block';
            if (pw === cp) {
                matchMsg.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                matchMsg.style.color = '#4cd98a';
            } else {
                matchMsg.innerHTML = '<i class="fas fa-times-circle"></i> Passwords do not match';
                matchMsg.style.color = '#f87171';
            }
        } else {
            matchMsg.style.display = 'none';
        }

        submitBtn.disabled = !(allReqsMet && pw === cp && cp.length > 0);
    }

    pwInput.addEventListener('input', validate);
    cpInput.addEventListener('input', validate);

    document.getElementById('resetForm').addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    });
    </script>
    <?php endif; ?>
</div>

<!-- Theme Toggle -->
<div class="theme-toggle" id="themeToggleBtn">
    <i class="fas fa-moon"></i>
</div>

<script>
// Theme Toggle
const applyLogoForTheme = (isLight) => {
    const logoImg = document.getElementById('siteLogo');
    if (logoImg) logoImg.src = isLight ? 'logo.png' : 'wlogo.png';
};

const savedTheme = localStorage.getItem('docugoTheme');
const isLightOnLoad = savedTheme === 'light';

if (isLightOnLoad) {
    document.body.classList.add('light');
    document.getElementById('themeToggleBtn').innerHTML = '<i class="fas fa-sun"></i>';
} else {
    document.body.classList.remove('light');
    document.getElementById('themeToggleBtn').innerHTML = '<i class="fas fa-moon"></i>';
}
applyLogoForTheme(isLightOnLoad);

document.getElementById('themeToggleBtn').addEventListener('click', () => {
    const isLight = document.body.classList.toggle('light');
    localStorage.setItem('docugoTheme', isLight ? 'light' : 'dark');
    document.getElementById('themeToggleBtn').innerHTML = isLight ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    applyLogoForTheme(isLight);
});
</script>

</body>
</html>