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
            SELECT id, first_name, last_name, email, password, role, status
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
                    // Successful login — set session
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role']  = $user['role'];

                    // Redirect based on role
                    if (in_array($user['role'], ['admin', 'registrar'])) {
                        header('Location: ' . SITE_URL . '/admin/dashboard.php');
                    } elseif ($user['role'] === 'student') {
                        header('Location: ' . SITE_URL . '/student/dashboard.php');
                    } elseif ($user['role'] === 'alumni') {
                        header('Location: ' . SITE_URL . '/student/dashboard.php');
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
    <title>Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="../assets/js/mobile.js"></script>
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <style>
        :root {
            --royal: #1a3fb0;
            --royal-dark: #122e8a;
            --royal-mid: #3563e9;
            --accent: #4f7dff;
            --border: #dce3f5;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: linear-gradient(135deg, var(--royal-dark) 0%, var(--royal-mid) 60%, #6c9cff 100%);
            position: relative;
            overflow: hidden;
        }

        /* Decorative background blobs */
        body::before {
            content: '';
            position: fixed;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(99,153,255,0.25) 0%, transparent 70%);
            top: -150px; right: -150px;
            border-radius: 50%;
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            bottom: -100px; left: -100px;
            border-radius: 50%;
            pointer-events: none;
        }

        .floating-dots {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .fdot {
            position: absolute;
            border-radius: 50%;
            opacity: 0.4;
        }

        .fdot1 { width:14px;height:14px;background:#f97316;top:12%;left:15%; }
        .fdot2 { width:10px;height:10px;background:#a78bfa;top:25%;right:20%; }
        .fdot3 { width:8px; height:8px; background:#34d399;bottom:30%;left:25%; }
        .fdot4 { width:16px;height:16px;background:#fbbf24;bottom:15%;right:15%; }
        .fdot5 { width:6px; height:6px; background:#f472b6;top:55%;left:8%; }

        /* Card */
        .card {
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(12px);
            border-radius: 20px;
            box-shadow: 0 24px 64px rgba(10,30,100,0.28);
            width: 100%;
            max-width: 430px;
            padding: 2.5rem 2.25rem 2rem;
            position: relative;
            z-index: 10;
            animation: slideUp 0.4s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Logo */
        .logo {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .logo-icon {
            width: 58px; height: 58px;
            background: linear-gradient(135deg, var(--royal) 0%, var(--royal-mid) 100%);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 8px 24px rgba(26,63,176,0.3);
        }

        .logo h1 {
            font-size: 1.9rem;
            color: var(--royal);
            font-weight: 800;
            letter-spacing: -1px;
            line-height: 1;
        }

        .logo p {
            font-size: 0.78rem;
            color: #6b7280;
            margin-top: 4px;
        }

        /* Heading */
        .card h2 {
            font-size: 1rem;
            color: #111827;
            margin-bottom: 1.25rem;
            font-weight: 600;
            text-align: center;
        }

        /* Alert */
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 0.75rem 1rem;
            border-radius: 9px;
            font-size: 0.84rem;
            margin-bottom: 1.1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }

        /* Form */
        .form-group { margin-bottom: 1rem; }

        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.3rem;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 0.65rem 0.9rem;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-family: inherit;
            font-size: 0.88rem;
            color: #111827;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #f8faff;
        }

        input:focus {
            border-color: var(--royal-mid);
            box-shadow: 0 0 0 3px rgba(53,99,233,0.12);
            background: #fff;
        }

        .password-wrap { position: relative; }

        .toggle-pw {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            font-size: 0.78rem;
            font-weight: 600;
            user-select: none;
            transition: color 0.15s;
        }

        .toggle-pw:hover { color: var(--royal-mid); }

        .forgot {
            text-align: right;
            margin-top: 0.35rem;
            font-size: 0.78rem;
        }

        .forgot a {
            color: var(--royal-mid);
            text-decoration: none;
            font-weight: 600;
        }

        .forgot a:hover { text-decoration: underline; }

        /* Button */
        .btn {
            width: 100%;
            padding: 0.78rem;
            background: linear-gradient(135deg, var(--royal) 0%, var(--royal-mid) 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            margin-top: 0.4rem;
            box-shadow: 0 6px 20px rgba(26,63,176,0.3);
            letter-spacing: 0.01em;
        }

        .btn:hover { opacity: 0.92; transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.1rem 0;
            color: #9ca3af;
            font-size: 0.78rem;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-top: 1px solid #e5e7eb;
        }

        /* Register link */
        .register-link {
            text-align: center;
            font-size: 0.84rem;
            color: #6b7280;
        }

        .register-link a {
            color: var(--royal-mid);
            font-weight: 700;
            text-decoration: none;
        }

        .register-link a:hover { text-decoration: underline; }

    
    </style>
</head>
<body>

<div class="floating-dots">
    <div class="fdot fdot1"></div>
    <div class="fdot fdot2"></div>
    <div class="fdot fdot3"></div>
    <div class="fdot fdot4"></div>
    <div class="fdot fdot5"></div>
</div>

<div class="card">
    <div class="logo">
        <div class="logo-icon">🎓</div>
        <h1>DocuGo</h1>
        <p>Asian Development Foundation College</p>
    </div>

    <h2>Welcome back! Please login.</h2>

    <?php if (!empty($error)): ?>
        <div class="alert-error">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email"
                   placeholder="you@email.com"
                   value="<?= htmlspecialchars($email) ?>"
                   required autofocus>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div class="password-wrap">
                <input type="password" id="password" name="password"
                       placeholder="Enter your password" required>
                <span class="toggle-pw" onclick="togglePassword()">Show</span>
            </div>
            <div class="forgot"><a href="forgot_password.php">Forgot password?</a></div>
        </div>

        <button type="submit" class="btn">Login</button>
    </form>

    <div class="divider">or</div>

    <p class="register-link">
        No account yet? <a href="register.php">Register here</a>
    </p>
</div>

<script>
function togglePassword() {
    const pw = document.getElementById('password');
    const btn = document.querySelector('.toggle-pw');
    if (pw.type === 'password') {
        pw.type = 'text';
        btn.textContent = 'Hide';
    } else {
        pw.type = 'password';
        btn.textContent = 'Show';
    }
}
</script>
</body>
</html>