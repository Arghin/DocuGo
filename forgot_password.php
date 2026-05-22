<?php
// ================================================================
// forgot_password.php
// Step 1 — User enters their email, system sends a reset link.
// Step 2 — User clicks the link → reset_password.php
//
// Place this file in the ROOT of your DocuGo project (same level
// as login.php and register.php).
// ================================================================
require_once 'includes/config.php';
require_once 'includes/mailer.php';

// Already logged in? Redirect away
if (isset($_SESSION['user_id'])) {
    header('Location: ' . SITE_URL . '/student/dashboard.php');
    exit();
}

$step    = 'request';   // 'request' | 'sent'
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    // ── Basic validation ──────────────────────────────────
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $conn = getConnection();

        // ── Look up the user ──────────────────────────────
        $stmt = $conn->prepare("
            SELECT id, first_name, last_name, status
            FROM users
            WHERE email = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Security: always show the "sent" screen whether the email
        // exists or not — prevents email enumeration attacks.
        if ($user && $user['status'] === 'active') {

            // ── Delete any existing unused tokens for this user ──
            $del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $del->bind_param("i", $user['id']);
            $del->execute();
            $del->close();

            // ── Generate a secure token ───────────────────────
            $token     = bin2hex(random_bytes(32)); // 64-char hex string
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // ── Store it ──────────────────────────────────────
            $ins = $conn->prepare("
                INSERT INTO password_resets (user_id, token, expires_at)
                VALUES (?, ?, ?)
            ");
            $ins->bind_param("iss", $user['id'], $token, $expiresAt);
            $ins->execute();
            $ins->close();

            $conn->close();

            // ── Send the email ────────────────────────────────
            $resetLink = SITE_URL . '/reset_password.php?token=' . $token;
            $firstName = htmlspecialchars($user['first_name']);

            $subject = '[DocuGo] Password Reset Request';
            $body    = "
            <div style='font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;color:#111827'>
                <div style='background:linear-gradient(135deg,#1a3fb0,#3563e9);padding:22px 28px;border-radius:10px 10px 0 0'>
                    <h1 style='color:#fff;font-size:20px;margin:0;font-weight:800'>DocuGo</h1>
                    <p style='color:rgba(255,255,255,0.75);font-size:12px;margin:4px 0 0'>Asian Development Foundation College</p>
                </div>
                <div style='background:#fff;padding:28px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 10px 10px'>
                    <p style='margin:0 0 14px'>Hello, <strong>{$firstName}</strong>,</p>
                    <p style='margin:0 0 14px;color:#374151;line-height:1.6'>
                        We received a request to reset the password for your DocuGo account.
                        Click the button below to set a new password.
                    </p>
                    <div style='text-align:center;margin:24px 0'>
                        <a href='{$resetLink}'
                           style='background:#1a56db;color:#fff;padding:12px 28px;border-radius:8px;
                                  text-decoration:none;font-weight:700;font-size:15px;display:inline-block'>
                            Reset My Password
                        </a>
                    </div>
                    <p style='color:#6b7280;font-size:13px;margin:0 0 8px'>
                        This link will expire in <strong>1 hour</strong>.
                    </p>
                    <p style='color:#6b7280;font-size:13px;margin:0 0 18px'>
                        If you did not request a password reset, you can safely ignore this email.
                        Your password will not change.
                    </p>
                    <div style='background:#f9fafb;border-radius:8px;padding:10px 14px;font-size:12px;color:#9ca3af;word-break:break-all'>
                        Or copy this link: {$resetLink}
                    </div>
                    <hr style='border:none;border-top:1px solid #f3f4f6;margin:20px 0'>
                    <p style='font-size:11px;color:#9ca3af;margin:0'>
                        This is an automated message. Please do not reply.
                    </p>
                </div>
            </div>";

            // Use your existing sendMail wrapper from mailer.php
            try {
                sendMail($email, $user['first_name'] . ' ' . $user['last_name'], $subject, $body);
            } catch (Exception $e) {
                // Log silently — don't expose mailer errors to user
                error_log('DocuGo forgot_password mailer error: ' . $e->getMessage());
            }

        } else {
            // User not found or not active — still close connection
            if (isset($conn)) $conn->close();
        }

        // Always show "sent" screen for security
        $step = 'sent';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — ADFC DocuGo</title>
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

        input[type="email"] {
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

        input[type="email"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59, 107, 255, 0.2);
            background: var(--surface-hv);
        }

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

        /* Divider */
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

        /* Links */
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

        /* Sent Screen */
        .sent-icon {
            width: 72px;
            height: 72px;
            background: rgba(76,217,138,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.2rem;
            color: var(--green);
        }

        .sent-title {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            color: var(--text);
            text-align: center;
            margin-bottom: 0.6rem;
        }

        .sent-body {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-align: center;
            line-height: 1.65;
            margin-bottom: 1.4rem;
        }

        .info-box {
            background: rgba(59,107,255,0.08);
            border: 1px solid rgba(59,107,255,0.2);
            border-radius: var(--radius-sm);
            padding: 0.8rem 1rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            line-height: 1.55;
            margin-bottom: 1.2rem;
        }

        .info-box ul {
            padding-left: 1.2rem;
            margin-top: 4px;
        }

        .info-box ul li {
            margin-bottom: 3px;
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
        <div class="system-sub">DOCUGO  ONLINE DOCUMENT REQUEST SYSTEM</div>
    </div>

    <?php if ($step === 'sent'): ?>
    <!-- SENT SCREEN -->
    <div class="sent-icon"><i class="fas fa-envelope-open-text"></i></div>
    <div class="sent-title">Check Your Email</div>
    <div class="sent-body">
        If an account exists for <strong><?= htmlspecialchars($_POST['email'] ?? 'that email') ?></strong>,
        we've sent a password reset link. It expires in <strong>1 hour</strong>.
    </div>
    <div class="info-box">
        <strong><i class="fas fa-question-circle"></i> Didn't receive it?</strong>
        <ul>
            <li>Check your spam or junk folder</li>
            <li>Make sure you typed the correct email</li>
            <li>Wait a minute and try again</li>
        </ul>
    </div>
    <a href="forgot_password.php" class="btn" style="display:block;text-align:center;text-decoration:none;line-height:1.5">
        <i class="fas fa-redo-alt"></i> Try a Different Email
    </a>
    <div class="divider"></div>
    <div class="link-row"><a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a></div>

    <?php else: ?>
    <!-- REQUEST FORM -->
    <div class="card-title">Forgot Your Password?</div>
    <div class="card-subtitle">
        Enter the email address linked to your DocuGo account
        and we'll send you a reset link.
    </div>

    <?php if ($error): ?>
    <div class="alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php" id="fpForm">
        <div class="form-group">
            <label for="email"><i class="fas fa-envelope"></i> EMAIL ADDRESS</label>
            <input type="email" id="email" name="email"
                   placeholder="you@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   required autofocus>
        </div>

        <button type="submit" class="btn" id="submitBtn">
            <i class="fas fa-paper-plane"></i> Send Reset Link
        </button>
    </form>

    <div class="divider"></div>
    <div class="link-row"><a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a></div>
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

// Form submit handling
document.getElementById('fpForm')?.addEventListener('submit', function () {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
});
</script>

</body>
</html>