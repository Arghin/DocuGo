<?php
require_once 'includes/config.php';
redirectIfLoggedIn();

function insertUser($data) {
    require_once 'includes/mailer.php';
    $conn = getConnection();

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $hashed = password_hash($data['password'], PASSWORD_BCRYPT);

    $studentId  = $data['student_id'] ?: null;
    $middleName = $data['middle_name'] ?: null;
    $birthdate  = $data['birthdate'] ?: null;
    $gender     = $data['gender'] ?: null;
    $course     = $data['course'] ?: null;
    $yearGrad   = $data['year_graduated'] ?: null;
    $contact    = $data['contact_number'] ?: null;
    $address    = $data['address'] ?: null;
    $role       = $data['role'] ?: 'student';
    $status     = 'pending';

    $stmt = $conn->prepare("
        INSERT INTO users (
            student_id, first_name, middle_name, last_name, email,
            gender, birthdate, password, role, course, year_graduated,
            contact_number, address, status
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "ssssssssssssss",
        $studentId, $data['first_name'], $middleName, $data['last_name'],
        $data['email'], $gender, $birthdate, $hashed, $role, $course,
        $yearGrad, $contact, $address, $status
    );

    $stmt->execute();
    $userId = $conn->insert_id;
    $stmt->close();

    $employment_status   = $data['employment_status'] ?? 'unemployed';
    $employer_name       = $data['employer_name'] ?? null;
    $job_title           = $data['job_title'] ?? null;
    $employment_sector   = $data['employment_sector'] ?? null;
    $degree_relevance    = $data['degree_relevance'] ?? null;
    $further_studies     = $data['further_studies'] ?? 0;
    $school_further      = $data['further_studies_school'] ?? null;
    $prof_license        = $data['professional_license'] ?? null;

    $tracerStmt = $conn->prepare("
        INSERT INTO graduate_tracer (
            user_id, employment_status, employer_name, job_title,
            employment_sector, degree_relevance, further_studies,
            school_further_studies, professional_license
        ) VALUES (?,?,?,?,?,?,?,?,?)
    ");

    if (!$tracerStmt) die("Tracer prepare failed: " . $conn->error);

    $tracerStmt->bind_param(
        "issssssss",
        $userId, $employment_status, $employer_name, $job_title,
        $employment_sector, $degree_relevance, $further_studies,
        $school_further, $prof_license
    );

    $tracerStmt->execute();
    $tracerStmt->close();

    $token     = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $tokenStmt = $conn->prepare("
        INSERT INTO email_verifications (user_id, token, expires_at)
        VALUES (?, ?, ?)
    ");
    $tokenStmt->bind_param("iss", $userId, $token, $expiresAt);
    $tokenStmt->execute();
    $tokenStmt->close();
    $conn->close();

    $sent = sendVerificationEmail($data['email'], $data['first_name'], $token);
    return $sent ? true : 'Account created but email failed to send.';
}

$errors  = [];
$success = false;
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'student_id'          => sanitize($_POST['student_id'] ?? ''),
        'first_name'          => sanitize($_POST['first_name'] ?? ''),
        'middle_name'         => sanitize($_POST['middle_name'] ?? ''),
        'last_name'           => sanitize($_POST['last_name'] ?? ''),
        'email'               => sanitize($_POST['email'] ?? ''),
        'password'            => $_POST['password'] ?? '',
        'confirm_pass'        => $_POST['confirm_pass'] ?? '',
        'gender'              => sanitize($_POST['gender'] ?? ''),
        'birthdate'           => sanitize($_POST['birthdate'] ?? ''),
        'role'                => sanitize($_POST['role'] ?? 'student'),
        'course'              => sanitize($_POST['course'] ?? ''),
        'year_graduated'      => sanitize($_POST['year_graduated'] ?? ''),
        'contact_number'      => sanitize($_POST['contact_number'] ?? ''),
        'address'             => sanitize($_POST['address'] ?? ''),
        'employment_status'   => sanitize($_POST['employment_status'] ?? ''),
        'employer_name'       => sanitize($_POST['employer_name'] ?? ''),
        'job_title'           => sanitize($_POST['job_title'] ?? ''),
        'employment_sector'   => sanitize($_POST['employment_sector'] ?? ''),
        'degree_relevance'    => sanitize($_POST['degree_relevance'] ?? ''),
        'professional_license'=> sanitize($_POST['professional_license'] ?? ''),
        'further_studies'     => isset($_POST['further_studies']) ? 1 : 0,
        'further_studies_school' => sanitize($_POST['further_studies_school'] ?? ''),
    ];

    if (empty($formData['first_name']))  $errors[] = "First name is required.";
    if (empty($formData['last_name']))   $errors[] = "Last name is required.";
    if (empty($formData['email']))       $errors[] = "Email is required.";
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email.";
    if (strlen($formData['password']) < 8) $errors[] = "Password must be at least 8 characters.";
    if ($formData['password'] !== $formData['confirm_pass']) $errors[] = "Passwords do not match.";

    if (empty($errors)) {
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $formData['email']);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) $errors[] = "This email is already registered.";
        $stmt->close();
        $conn->close();
    }

    if (empty($errors)) {
        $result = insertUser($formData);
        if ($result === true) {
            $success = true;
        } else {
            $errors[] = $result;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADFC DocuGo | Register</title>
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
            --overlay-dark: rgba(8,14,40,0.85);
            --overlay-light: rgba(238,242,255,0.82);
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
            padding: 2rem 1rem;
            position: relative;
            transition: background 0.4s, color 0.4s;
        }

        /* School Background */
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

        /* Ambient Blobs */
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

        /* Noise Texture */
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

        /* Main Card */
        .card {
            position: relative;
            z-index: 10;
            background: var(--surface);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            width: 100%;
            max-width: 560px;
            padding: 2.2rem 2rem;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.4);
            transition: background 0.3s, border-color 0.3s;
            animation: cardAppear 0.6s var(--ease-out);
            margin: auto;
        }

        @keyframes cardAppear {
            from { opacity: 0; transform: translateY(28px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Logo Area */
        .logo-area {
            text-align: center;
            margin-bottom: 1.5rem;
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

        /* Steps */
        .steps {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 1.8rem;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 2px solid var(--border);
            background: var(--bg2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-dim);
            transition: all 0.3s ease;
        }

        .step-circle.active {
            border-color: var(--accent);
            background: var(--accent);
            color: #fff;
            box-shadow: 0 0 0 4px rgba(59,107,255,0.2);
        }

        .step-circle.done {
            border-color: var(--green);
            background: var(--green);
            color: #fff;
        }

        .step-circle.done::after {
            content: '✓';
            font-size: 0.7rem;
        }
        .step-circle.done span { display: none; }
        .step-circle span { display: inline; }

        .step-label {
            font-size: 0.62rem;
            font-weight: 600;
            color: var(--text-dim);
            text-align: center;
        }

        .step-item.active .step-label { color: var(--accent); }
        .step-item.done .step-label { color: var(--green); }

        .step-line {
            flex: 1;
            height: 2px;
            background: var(--border);
            margin: 0 6px;
            margin-bottom: 22px;
            transition: background 0.3s;
        }
        .step-line.done { background: var(--green); }

        /* Panels */
        .step-panel { display: none; animation: fadeIn 0.25s ease; }
        .step-panel.active { display: block; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(8px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        .panel-title {
            font-family: 'Sora', sans-serif;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 1.2rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border);
        }

        /* Form Elements */
        .form-group { margin-bottom: 1rem; }

        label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            font-family: 'Sora', sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-dim);
            margin-bottom: 0.35rem;
        }

        label .req { color: #ef4444; }

        input, select, textarea {
            width: 100%;
            padding: 0.7rem 0.9rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.85rem;
            color: var(--text);
            background: var(--bg2);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59,107,255,0.15);
            background: var(--surface-hv);
        }

        input::placeholder, textarea::placeholder { color: var(--text-dim); opacity: 0.6; }

        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.9rem;
        }

        .password-wrap { position: relative; }
        .toggle-pw {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(59,107,255,0.12);
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
        .toggle-pw:hover { background: rgba(59,107,255,0.28); }

        /* Strength Meter */
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
        .strength-text { font-size: 0.68rem; color: var(--text-dim); margin-top: 4px; }

        /* Role Tabs */
        .role-tabs { display: flex; gap: 0.6rem; margin-bottom: 1.2rem; }
        .role-tab {
            flex: 1;
            padding: 0.6rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--bg2);
            text-align: center;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .role-tab.active {
            border-color: var(--accent);
            background: var(--surface-hv);
            color: var(--accent);
        }

        /* Alert & Info */
        .alert-error {
            background: rgba(220, 38, 38, 0.12);
            border-left: 3px solid #ef4444;
            border-radius: var(--radius-sm);
            padding: 0.7rem 1rem;
            font-size: 0.78rem;
            color: #f9acac;
            margin-bottom: 1.2rem;
        }
        body.light .alert-error {
            color: #b91c1c;
            background: rgba(220, 38, 38, 0.08);
        }
        .alert-error ul { padding-left: 1.2rem; }

        .info-box {
            background: rgba(59,107,255,0.1);
            border: 1px solid rgba(59,107,255,0.2);
            border-radius: var(--radius-sm);
            padding: 0.65rem 0.85rem;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.6rem;
        }

        /* Buttons */
        .btn-row {
            display: flex;
            gap: 0.8rem;
            margin-top: 1.2rem;
        }
        .btn-prev, .btn-next, .btn-submit, .btn-full {
            flex: 1;
            padding: 0.75rem;
            border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif;
            font-size: 0.8rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s var(--ease-spring), opacity 0.2s;
            border: none;
            text-align: center;
        }
        .btn-prev {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-muted);
        }
        .btn-prev:hover { background: var(--surface-hv); transform: translateY(-1px); }
        .btn-next, .btn-submit, .btn-full {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: #fff;
            box-shadow: 0 4px 14px rgba(26,62,199,0.3);
        }
        .btn-next:hover, .btn-submit:hover, .btn-full:hover {
            transform: translateY(-2px);
            opacity: 0.92;
        }

        .login-link {
            text-align: center;
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 1.2rem;
            padding-top: 0.8rem;
            border-top: 1px solid var(--border);
        }
        .login-link a {
            color: var(--accent2);
            font-weight: 700;
            text-decoration: none;
        }
        .login-link a:hover { text-decoration: underline; }

        /* Success Screen */
        .success-screen {
            text-align: center;
            padding: 0.5rem 0;
        }
        .success-icon { font-size: 3.5rem; margin-bottom: 0.8rem; }
        .success-title {
            font-family: 'Sora', sans-serif;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 0.5rem;
        }
        .success-body {
            color: var(--text-muted);
            font-size: 0.85rem;
            line-height: 1.6;
            margin-bottom: 1.2rem;
        }

        /* Theme Toggle */
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
            transition: transform 0.2s, background 0.2s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .theme-toggle:hover { transform: scale(1.1); background: var(--surface-hv); }

        /* Field Error */
        .field-error { font-size: 0.7rem; color: #ef4444; margin-top: 3px; display: none; }
        input.invalid, select.invalid { border-color: #ef4444; background: rgba(239,68,68,0.05); }

        /* Responsive */
        @media (max-width: 560px) {
            .card { padding: 1.5rem; margin: 0.5rem; }
            .row-2 { grid-template-columns: 1fr; gap: 0; }
            .step-label { font-size: 0.55rem; }
            .step-circle { width: 28px; height: 28px; font-size: 0.7rem; }
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

    <?php if ($success): ?>
    <!-- Success Screen -->
    <div class="success-screen">
        <div class="success-icon">📧</div>
        <div class="success-title">Check your email!</div>
        <p class="success-body">
            We sent a verification link to<br>
            <strong><?= htmlspecialchars($formData['email'] ?? '') ?></strong><br><br>
            Click the link to activate your account.<br>
            <span style="font-size:0.7rem;">The link expires in 24 hours. Check spam if you don't see it.</span>
        </p>
        <a href="login.php" class="btn-full" style="display:inline-block; width:auto; padding:0.6rem 1.5rem; text-decoration:none;">← Back to Login</a>
    </div>

    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="alert-error">
        <ul><?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <!-- Step Progress -->
    <div class="steps" id="stepProgress">
        <div class="step-item active" id="si-1">
            <div class="step-circle active" id="sc-1"><span>1</span></div>
            <span class="step-label">Account</span>
        </div>
        <div class="step-line" id="sl-1"></div>
        <div class="step-item" id="si-2">
            <div class="step-circle" id="sc-2"><span>2</span></div>
            <span class="step-label">Personal</span>
        </div>
        <div class="step-line" id="sl-2"></div>
        <div class="step-item" id="si-3">
            <div class="step-circle" id="sc-3"><span>3</span></div>
            <span class="step-label">Details</span>
        </div>
        <div class="step-line" id="sl-3"></div>
        <div class="step-item" id="si-4">
            <div class="step-circle" id="sc-4"><span>4</span></div>
            <span class="step-label">Confirm</span>
        </div>
    </div>

    <form method="POST" action="register.php" id="regForm" novalidate>

        <!-- STEP 1 - Account Setup -->
        <div class="step-panel active" id="panel-1">
            <div class="panel-title">📝 Account Setup</div>

            <div style="font-size:0.7rem;font-weight:700;color:var(--text-dim);margin-bottom:0.5rem;">I am a</div>
            <div class="role-tabs">
                <div class="role-tab <?= (($formData['role'] ?? 'student') === 'student') ? 'active' : '' ?>"
                     onclick="setRole('student', this)">🎓 Student</div>
                <div class="role-tab <?= (($formData['role'] ?? '') === 'alumni') ? 'active' : '' ?>"
                     onclick="setRole('alumni', this)">🏅 Alumni / Graduate</div>
            </div>
            <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($formData['role'] ?? 'student') ?>">

            <div class="form-group">
                <label>Email Address <span class="req">*</span></label>
                <input type="email" id="email" name="email" placeholder="you@adfc.edu.ph"
                       value="<?= htmlspecialchars($formData['email'] ?? '') ?>" required>
                <div class="field-error" id="err-email">Valid email is required.</div>
            </div>

            <div class="form-group">
                <label>Password <span class="req">*</span></label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password" placeholder="Min. 8 characters" required>
                    <button type="button" class="toggle-pw" onclick="togglePw('password', this)">Show</button>
                </div>
                <div class="strength-wrap">
                    <div class="strength-track"><div class="strength-bar" id="strength-bar"></div></div>
                    <div class="strength-text" id="strength-text"></div>
                </div>
            </div>

            <div class="form-group">
                <label>Confirm Password <span class="req">*</span></label>
                <div class="password-wrap">
                    <input type="password" id="confirm_pass" name="confirm_pass" placeholder="Repeat password" required>
                    <button type="button" class="toggle-pw" onclick="togglePw('confirm_pass', this)">Show</button>
                </div>
                <div class="field-error" id="err-confirm">Passwords do not match.</div>
            </div>

            <div class="btn-row">
                <button type="button" class="btn-next" onclick="goNext(1)">Next →</button>
            </div>
        </div>

        <!-- STEP 2 - Personal Information -->
        <div class="step-panel" id="panel-2">
            <div class="panel-title">👤 Personal Information</div>

            <div class="row-2">
                <div class="form-group">
                    <label>First Name <span class="req">*</span></label>
                    <input type="text" name="first_name" id="first_name" placeholder="Juan"
                           value="<?= htmlspecialchars($formData['first_name'] ?? '') ?>" required>
                    <div class="field-error" id="err-fname">First name required.</div>
                </div>
                <div class="form-group">
                    <label>Last Name <span class="req">*</span></label>
                    <input type="text" name="last_name" id="last_name" placeholder="dela Cruz"
                           value="<?= htmlspecialchars($formData['last_name'] ?? '') ?>" required>
                    <div class="field-error" id="err-lname">Last name required.</div>
                </div>
            </div>

            <div class="form-group">
                <label>Middle Name <span style="opacity:0.6;">(optional)</span></label>
                <input type="text" name="middle_name" placeholder="Santos"
                       value="<?= htmlspecialchars($formData['middle_name'] ?? '') ?>">
            </div>

            <div class="row-2">
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="">-- Select --</option>
                        <option value="male" <?= ($formData['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= ($formData['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="prefer_not_to_say" <?= ($formData['gender'] ?? '') === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="date" name="birthdate" value="<?= htmlspecialchars($formData['birthdate'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Contact Number</label>
                <input type="tel" name="contact_number" placeholder="09XXXXXXXXX"
                       value="<?= htmlspecialchars($formData['contact_number'] ?? '') ?>">
            </div>

            <div class="btn-row">
                <button type="button" class="btn-prev" onclick="goBack(2)">← Back</button>
                <button type="button" class="btn-next" onclick="goNext(2)">Next →</button>
            </div>
        </div>

        <!-- STEP 3 - Academic Details -->
        <div class="step-panel" id="panel-3">
            <div class="panel-title">🎓 Academic Details</div>

            <div class="row-2">
                <div class="form-group">
                    <label>Student / Alumni ID</label>
                    <input type="text" name="student_id" placeholder="2020-00001"
                           value="<?= htmlspecialchars($formData['student_id'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Course / Program</label>
                    <input type="text" name="course" placeholder="e.g. BSIT"
                           value="<?= htmlspecialchars($formData['course'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Address</label>
                <textarea name="address" placeholder="Current address (optional)"><?= htmlspecialchars($formData['address'] ?? '') ?></textarea>
            </div>

            <div id="alumni-academic" style="display:none;">
                <div class="form-group">
                    <label>Year Graduated</label>
                    <select name="year_graduated">
                        <option value="">-- Select Year --</option>
                        <?php for ($y = date('Y'); $y >= 1990; $y--): ?>
                            <option value="<?= $y ?>" <?= (($formData['year_graduated'] ?? '') == $y) ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="info-box">
                    📋 After account approval, complete your <strong>Graduate Tracer Survey</strong> from your dashboard.
                </div>
            </div>

            <div class="btn-row">
                <button type="button" class="btn-prev" onclick="goBack(3)">← Back</button>
                <button type="button" class="btn-next" onclick="goNext(3)">Next →</button>
            </div>
        </div>

        <!-- STEP 4 - Review & Submit -->
        <div class="step-panel" id="panel-4">
            <div class="panel-title">✅ Review & Confirm</div>

            <div id="review-box" style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem;font-size:0.8rem;line-height:1.8;margin-bottom:1rem;">
                <!-- Filled by JS -->
            </div>

            <div class="info-box" style="margin-top:0;">
                ⚠️ Please review your details before submitting. A verification email will be sent.
            </div>

            <button type="submit" class="btn-full" style="margin-top:1.2rem;">Create Account</button>

            <div class="btn-row" style="margin-top:0.6rem;">
                <button type="button" class="btn-prev" style="flex:1;" onclick="goBack(4)">← Edit details</button>
            </div>
        </div>

    </form>
    <?php endif; ?>

    <p class="login-link">Already have an account? <a href="login.php">Login here</a></p>
</div>

<!-- Theme Toggle Button -->
<div class="theme-toggle" id="themeToggleBtn" title="Switch between light/dark mode">
    <i class="fas fa-moon"></i>
</div>

<script>
// ========== DARK/LIGHT MODE TOGGLE + LOGO SWAP ==========
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

// ========== PASSWORD TOGGLE ==========
function togglePw(id, el) {
    const inp = document.getElementById(id);
    const isPassword = inp.type === 'password';
    inp.type = isPassword ? 'text' : 'password';
    el.textContent = isPassword ? 'Hide' : 'Show';
}

// ========== ROLE TOGGLE ==========
function setRole(role, el) {
    document.getElementById('roleInput').value = role;
    document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    const alumniDiv = document.getElementById('alumni-academic');
    alumniDiv.style.display = role === 'alumni' ? 'block' : 'none';
}

// Initialize role display
document.addEventListener('DOMContentLoaded', function() {
    const role = document.getElementById('roleInput')?.value;
    if (role === 'alumni') {
        const alumniDiv = document.getElementById('alumni-academic');
        if (alumniDiv) alumniDiv.style.display = 'block';
    }
});

// ========== PASSWORD STRENGTH METER ==========
const pwInput = document.getElementById('password');
if (pwInput) {
    pwInput.addEventListener('input', function() {
        const val = this.value;
        const bar = document.getElementById('strength-bar');
        const txt = document.getElementById('strength-text');
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const levels = [
            { w: '0%', color: '', label: '' },
            { w: '25%', color: '#ef4444', label: 'Weak' },
            { w: '50%', color: '#f59e0b', label: 'Fair' },
            { w: '75%', color: '#3b82f6', label: 'Good' },
            { w: '100%', color: '#22c55e', label: 'Strong' }
        ];
        bar.style.width = levels[score].w;
        bar.style.background = levels[score].color;
        txt.textContent = levels[score].label;
        txt.style.color = levels[score].color;
    });
}

// ========== STEP NAVIGATION ==========
let currentStep = 1;
const TOTAL = 4;

function showPanel(n) {
    document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('panel-' + n);
    if (panel) panel.classList.add('active');
}

function updateProgress(active) {
    for (let i = 1; i <= TOTAL; i++) {
        const circle = document.getElementById('sc-' + i);
        const item = document.getElementById('si-' + i);
        const line = document.getElementById('sl-' + i);
        
        circle.classList.remove('active', 'done');
        item.classList.remove('active', 'done');
        
        if (i < active) {
            circle.classList.add('done');
            item.classList.add('done');
            circle.innerHTML = '✓';
            if (line) line.classList.add('done');
        } else if (i === active) {
            circle.classList.add('active');
            item.classList.add('active');
            circle.innerHTML = '<span>' + i + '</span>';
            if (line) line.classList.remove('done');
        } else {
            circle.innerHTML = '<span>' + i + '</span>';
            if (line) line.classList.remove('done');
        }
    }
}

function validateStep(step) {
    let valid = true;
    
    if (step === 1) {
        const email = document.getElementById('email');
        const pw = document.getElementById('password');
        const cpw = document.getElementById('confirm_pass');
        const emailOk = email.value && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value);
        const pwOk = pw.value.length >= 8;
        const matchOk = pw.value === cpw.value && cpw.value !== '';
        
        email.classList.toggle('invalid', !emailOk);
        document.getElementById('err-email').style.display = emailOk ? 'none' : 'block';
        pw.classList.toggle('invalid', !pwOk);
        cpw.classList.toggle('invalid', !matchOk);
        document.getElementById('err-confirm').style.display = matchOk ? 'none' : 'block';
        
        if (!emailOk || !pwOk || !matchOk) valid = false;
    }
    
    if (step === 2) {
        const fn = document.getElementById('first_name');
        const ln = document.getElementById('last_name');
        const fnOk = fn.value.trim() !== '';
        const lnOk = ln.value.trim() !== '';
        
        fn.classList.toggle('invalid', !fnOk);
        ln.classList.toggle('invalid', !lnOk);
        document.getElementById('err-fname').style.display = fnOk ? 'none' : 'block';
        document.getElementById('err-lname').style.display = lnOk ? 'none' : 'block';
        
        if (!fnOk || !lnOk) valid = false;
    }
    
    return valid;
}

function getFieldValue(name) {
    const el = document.querySelector(`[name="${name}"]`);
    return el ? (el.value || '—') : '—';
}

function buildReview() {
    const role = document.getElementById('roleInput').value;
    const rows = [
        ['Role', role === 'alumni' ? '🏅 Alumni / Graduate' : '🎓 Student'],
        ['Email', document.getElementById('email')?.value || '—'],
        ['Full Name', [getFieldValue('first_name'), getFieldValue('middle_name') !== '—' ? getFieldValue('middle_name') : '', getFieldValue('last_name')].filter(Boolean).join(' ')],
        ['Gender', getFieldValue('gender')],
        ['Birthdate', getFieldValue('birthdate')],
        ['Contact', getFieldValue('contact_number')],
        ['Student ID', getFieldValue('student_id')],
        ['Course', getFieldValue('course')],
        ['Address', getFieldValue('address')]
    ];
    if (role === 'alumni') rows.push(['Year Graduated', getFieldValue('year_graduated')]);
    
    let html = '';
    rows.forEach(([label, val]) => {
        html += `<div style="display:flex;gap:0.6rem;border-bottom:1px solid var(--border);padding:5px 0;">
            <span style="min-width:110px;font-weight:700;color:var(--text-dim);">${label}</span>
            <span style="color:var(--text);">${val}</span>
        </div>`;
    });
    document.getElementById('review-box').innerHTML = html;
}

function goNext(step) {
    if (!validateStep(step)) return;
    if (step === TOTAL - 1) buildReview();
    currentStep = step + 1;
    updateProgress(currentStep);
    showPanel(currentStep);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goBack(step) {
    currentStep = step - 1;
    updateProgress(currentStep);
    showPanel(currentStep);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
</body>
</html>