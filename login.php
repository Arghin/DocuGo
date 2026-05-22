<?php
require_once 'includes/config.php';
redirectIfLoggedIn();

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $conn = getConnection();

        $stmt = $conn->prepare("
            SELECT id, first_name, last_name, email, password, role, status, signature_office_id
            FROM users WHERE email = ?
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (!password_verify($password, $user['password'])) {
                $error = 'Incorrect email or password.';
            } else {
                if ($user['status'] === 'inactive') {
                    $error = 'Your account is not verified yet. Please check your email for the verification link.';
                } elseif ($user['status'] === 'pending') {
                    $error = 'Your email is verified, but your account is pending admin approval.';
                } elseif ($user['status'] !== 'active') {
                    $error = 'Your account cannot log in right now. Please contact the Registrar.';
                } else {
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role']  = $user['role'];
                    
                    if ($user['role'] === 'signatory') {
                        $_SESSION['signature_office_id'] = $user['signature_office_id'];
                    }

                    if (in_array($user['role'], ['admin', 'registrar'])) {
                        header('Location: ' . SITE_URL . '/admin/dashboard.php');
                    } elseif ($user['role'] === 'signatory') {
                        header('Location: ' . SITE_URL . '/signatory/dashboard.php');
                    } else {
                        header('Location: ' . SITE_URL . '/student/dashboard.php');
                    }
                    exit();
                }
            }
        } else {
            $error = 'Incorrect email or password.';
        }

        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADFC DocuGo | Login</title>
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
            --ease-spring:cubic-bezier(0.34, 1.56, 0.64, 1);
            --overlay-dark: rgba(8,14,40,0.82);
            --overlay-light: rgba(238,242,255,0.78);
        }

        body.light {
            --bg:         #eef2ff;
            --bg2:        #e2e9ff;
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
            margin: 0;
        }

        /* ========== SCHOOL BACKGROUND (full page behind container) ========== */
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

        /* Gradient overlay for better text contrast */
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

        /* Ambient blobs (floating effect) */
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
            background: radial-gradient(circle, rgba(59,107,255,0.18) 0%, transparent 70%);
            top: -150px;
            left: -120px;
        }

        .blob-2 {
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(26,62,199,0.14) 0%, transparent 70%);
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

        /* Noise texture overlay */
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

        /* ========== LOGIN CARD - PERFECTLY CENTERED ========== */
        .login-card {
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
            margin: auto;
        }

        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(28px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Logo area */
        .logo-area {
            text-align: center;
            margin-bottom: 1.8rem;
        }

        .logo-img {
            width: 68px;
            height: 68px;
            object-fit: contain;
            margin: 0 auto 0.85rem;
            transition: transform 0.3s var(--ease-spring);
            border-radius: 16px;
        }

        .logo-img:hover {
            transform: rotate(-5deg) scale(1.05);
        }

        .school-name {
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 1.4rem;
            letter-spacing: -0.02em;
            color: var(--text);
            line-height: 1.2;
        }

        .system-sub {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--accent2);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .welcome-tag {
            font-family: 'Sora', sans-serif;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            display: inline-block;
            width: 100%;
            padding-bottom: 10px;
            border-bottom: 1px dashed var(--border);
        }

        /* Alert */
        .alert-error {
            background: rgba(220, 38, 38, 0.12);
            border-left: 3px solid #ef4444;
            border-radius: var(--radius-sm);
            padding: 0.85rem 1rem;
            font-size: 0.8rem;
            color: #f9acac;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        body.light .alert-error {
            color: #b91c1c;
            background: rgba(220, 38, 38, 0.08);
        }

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

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

        input {
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

        .password-wrap {
            position: relative;
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

        .forgot {
            text-align: right;
            margin-top: 0.5rem;
        }

        .forgot a {
            font-size: 0.7rem;
            color: var(--accent2);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .forgot a:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            border: none;
            border-radius: var(--radius-sm);
            padding: 0.9rem;
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

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(59, 107, 255, 0.45);
            background: linear-gradient(135deg, var(--primary-dk) 0%, var(--accent2) 100%);
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 1.5rem 0 1rem;
            color: var(--text-dim);
            font-size: 0.7rem;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-top: 1px solid var(--border);
        }

        .register-link {
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .register-link a {
            color: var(--accent2);
            font-weight: 700;
            text-decoration: none;
            transition: color 0.2s;
        }

        .register-link a:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        /* Theme toggle button (floating, matches main design) */
        .theme-toggle {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--surface);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text);
            font-size: 1.2rem;
            z-index: 99;
            transition: transform 0.2s, background 0.2s, border-color 0.2s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .theme-toggle:hover {
            transform: scale(1.1);
            background: var(--surface-hv);
            border-color: var(--border-hv);
        }

        /* Responsive */
        @media (max-width: 520px) {
            .login-card {
                padding: 1.8rem 1.5rem;
                margin: 1rem;
            }
            .school-name {
                font-size: 1.15rem;
            }
            .logo-img {
                width: 54px;
                height: 54px;
            }
        }

        /* Extra small height adjustment */
        @media (max-height: 600px) {
            .login-card {
                padding: 1.5rem 1.5rem;
            }
            .logo-area {
                margin-bottom: 1rem;
            }
            .welcome-tag {
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>

<!-- ========== SCHOOL BACKGROUND (full page image behind everything) ========== -->
<div class="school-background">
    <img src="school.jpeg" alt="Asian Development Foundation College Campus" onerror="this.src='https://placehold.co/1920x1080/1a3ec7/white?text=ADFC+Campus'">
    <div class="bg-overlay"></div>
</div>

<!-- Ambient floating blobs -->
<div class="blob-bg">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
</div>

<!-- Login Card - PERFECTLY CENTERED both horizontally and vertically -->
<div class="login-card">
    <div class="logo-area">
        <img id="siteLogo" class="logo-img" src="wlogo.png" alt="ADFC Logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%231a3ec7%22/%3E%3Ctext x=%2250%22 y=%2265%22 font-size=%2236%22 text-anchor=%22middle%22 fill=%22white%22%3EADFC%3C/text%3E%3C/svg%3E'">
        <div class="school-name">Asian Development Foundation College</div>
        <div class="system-sub">DOCUGO ONLINE DOCUMENT REQUEST SYSTEM</div>
    </div>

    <div style="text-align: center;">
        <span class="welcome-tag"><i class="fas fa-graduation-cap" style="margin-right: 8px;"></i> Welcome back, ADFC community</span>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert-error">
            <i class="fas fa-circle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group">
            <label><i class="fas fa-envelope"></i> EMAIL ADDRESS</label>
            <input type="email" name="email" placeholder="student@adfc.edu.ph" value="<?= htmlspecialchars($email) ?>" required autofocus>
        </div>

        <div class="form-group">
            <label><i class="fas fa-lock"></i> PASSWORD</label>
            <div class="password-wrap">
                <input type="password" id="password" name="password" placeholder="********" required>
                <button type="button" class="toggle-pw" onclick="togglePassword()">Show</button>
            </div>
            <div class="forgot"><a href="forgot_password.php"><i class="fas fa-key"></i> Forgot password?</a></div>
        </div>

        <button type="submit" class="btn-login">
            <i class="fas fa-arrow-right-to-bracket"></i> Login to DocuGo
        </button>
    </form>

    <div class="divider">
        <span>New to DocuGo?</span>
    </div>

    <p class="register-link">
        <i class="fas fa-user-plus"></i> No account yet? <a href="register.php">Create an account</a>
    </p>
</div>

<!-- Floating Theme Toggle Button -->
<div class="theme-toggle" id="themeToggleBtn" title="Switch between light/dark mode">
    <i class="fas fa-moon"></i>
</div>

<script>
    // ========== DARK/LIGHT MODE TOGGLE + LOGO SWAP ==========
    const applyLogoForTheme = (isLight) => {
        const logoImg = document.getElementById('siteLogo');
        if (logoImg) {
            logoImg.src = isLight ? 'logo.png' : 'wlogo.png';
        }
    };

    // Load saved theme preference
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

    // Theme toggle on click
    const themeBtn = document.getElementById('themeToggleBtn');
    themeBtn.addEventListener('click', () => {
        const isLight = document.body.classList.toggle('light');
        localStorage.setItem('docugoTheme', isLight ? 'light' : 'dark');
        themeBtn.innerHTML = isLight ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
        applyLogoForTheme(isLight);
    });

    // Password visibility toggle
    function togglePassword() {
        const pwInput = document.getElementById('password');
        const toggleBtn = document.querySelector('.toggle-pw');
        if (pwInput.type === 'password') {
            pwInput.type = 'text';
            toggleBtn.textContent = 'Hide';
        } else {
            pwInput.type = 'password';
            toggleBtn.textContent = 'Show';
        }
    }
</script>
</body>
</html>