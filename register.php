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
    <title>Register</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <script src="../assets/js/mobile.js"></script>
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
            padding: 1.5rem 1rem;
            background: linear-gradient(135deg, var(--royal-dark) 0%, var(--royal-mid) 60%, #6c9cff 100%);
            position: relative;
            overflow-x: hidden;
        }

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

        .floating-dots { position: fixed; inset: 0; pointer-events: none; overflow: hidden; }
        .fdot { position: absolute; border-radius: 50%; opacity: 0.4; }
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
            max-width: 460px;
            padding: 2.25rem 2.25rem 2rem;
            position: relative;
            z-index: 10;
            animation: slideUp 0.4s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Logo */
        .logo { text-align: center; margin-bottom: 1.5rem; }
        .logo-icon {
            width: 52px; height: 52px;
            background: linear-gradient(135deg, var(--royal) 0%, var(--royal-mid) 100%);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-bottom: 0.6rem;
            box-shadow: 0 8px 24px rgba(26,63,176,0.3);
        }
        .logo h1 {
            font-size: 1.75rem;
            color: var(--royal);
            font-weight: 800;
            letter-spacing: -1px;
            line-height: 1;
        }
        .logo p { font-size: 0.76rem; color: #6b7280; margin-top: 3px; }

        /* ── Step progress bar ── */
        .steps {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 1.75rem;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            position: relative;
        }

        .step-circle {
            width: 30px; height: 30px;
            border-radius: 50%;
            border: 2px solid var(--border);
            background: #f8faff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.72rem;
            font-weight: 700;
            color: #9ca3af;
            transition: all 0.3s ease;
            position: relative;
            z-index: 2;
        }

        .step-circle.active {
            border-color: var(--royal-mid);
            background: var(--royal-mid);
            color: #fff;
            box-shadow: 0 0 0 4px rgba(53,99,233,0.15);
        }

        .step-circle.done {
            border-color: #10b981;
            background: #10b981;
            color: #fff;
        }

        .step-label {
            font-size: 0.64rem;
            font-weight: 600;
            color: #9ca3af;
            text-align: center;
            white-space: nowrap;
            transition: color 0.3s;
        }

        .step-item.active .step-label  { color: var(--royal-mid); }
        .step-item.done .step-label    { color: #10b981; }

        .step-line {
            flex: 1;
            height: 2px;
            background: var(--border);
            margin: 0 4px;
            margin-bottom: 19px;
            min-width: 24px;
            transition: background 0.3s;
            position: relative;
            z-index: 1;
        }
        .step-line.done { background: #10b981; }

        /* Panel */
        .step-panel { display: none; animation: fadeIn 0.25s ease; }
        .step-panel.active { display: block; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(10px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        .panel-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1.5px solid #f3f4f6;
        }

        /* Form elements */
        .form-group { margin-bottom: 0.85rem; }

        label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.28rem;
        }

        label .req { color: #e11d48; }

        input[type="email"],
        input[type="password"],
        input[type="text"],
        input[type="date"],
        input[type="tel"],
        select, textarea {
            width: 100%;
            padding: 0.6rem 0.9rem;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            font-family: inherit;
            font-size: 0.875rem;
            color: #111827;
            background: #f8faff;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--royal-mid);
            box-shadow: 0 0 0 3px rgba(53,99,233,0.12);
            background: #fff;
        }

        textarea { resize: vertical; min-height: 72px; }

        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .password-wrap { position: relative; }
        .toggle-pw {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #9ca3af;
            font-size: 0.75rem;
            font-weight: 600;
            user-select: none;
            transition: color 0.15s;
        }
        .toggle-pw:hover { color: var(--royal-mid); }

        /* Strength bar */
        .strength-wrap { margin-top: 0.3rem; }
        .strength-track {
            height: 4px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 4px;
            transition: all 0.3s;
        }
        .strength-text { font-size: 0.72rem; color: #9ca3af; margin-top: 3px; }

        /* Role tabs */
        .role-tabs { display: flex; gap: 0.55rem; margin-bottom: 0.85rem; }
        .role-tab {
            flex: 1;
            padding: 0.6rem 0.5rem;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            background: #f8faff;
            text-align: center;
            cursor: pointer;
            font-size: 0.8rem;
            color: #6b7280;
            font-weight: 500;
            transition: all 0.2s;
            user-select: none;
        }
        .role-tab.active {
            border-color: var(--royal-mid);
            background: #eff6ff;
            color: var(--royal-mid);
            font-weight: 700;
        }

        /* Alert */
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            padding: 0.7rem 0.9rem;
            border-radius: 9px;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }
        .alert-error ul { padding-left: 1.1rem; }
        .alert-error ul li { margin-bottom: 2px; }

        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 0.65rem 0.85rem;
            border-radius: 9px;
            font-size: 0.78rem;
            line-height: 1.5;
            margin-top: 0.5rem;
        }

        /* Navigation buttons */
        .btn-row {
            display: flex;
            gap: 0.65rem;
            margin-top: 1.1rem;
        }

        .btn-prev, .btn-next, .btn-submit {
            flex: 1;
            padding: 0.72rem;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            border: none;
            letter-spacing: 0.01em;
        }

        .btn-prev {
            background: #f3f4f6;
            color: #374151;
            border: 1.5px solid #e5e7eb;
        }
        .btn-prev:hover { background: #e5e7eb; }

        .btn-next, .btn-submit {
            background: linear-gradient(135deg, var(--royal) 0%, var(--royal-mid) 100%);
            color: #fff;
            box-shadow: 0 6px 20px rgba(26,63,176,0.28);
        }
        .btn-next:hover, .btn-submit:hover { opacity: 0.92; transform: translateY(-1px); }
        .btn-next:active, .btn-submit:active { transform: translateY(0); }

        .btn-full {
            width: 100%;
            padding: 0.72rem;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            background: linear-gradient(135deg, var(--royal) 0%, var(--royal-mid) 100%);
            color: #fff;
            border: none;
            box-shadow: 0 6px 20px rgba(26,63,176,0.28);
            transition: opacity 0.2s, transform 0.15s;
            margin-top: 1.1rem;
            letter-spacing: 0.01em;
        }
        .btn-full:hover { opacity: 0.92; transform: translateY(-1px); }

        /* Login link */
        .login-link {
            text-align: center;
            font-size: 0.82rem;
            color: #6b7280;
            margin-top: 1.1rem;
        }
        .login-link a {
            color: var(--royal-mid);
            font-weight: 700;
            text-decoration: none;
        }
        .login-link a:hover { text-decoration: underline; }

        /* Success screen */
        .success-screen {
            text-align: center;
            padding: 1rem 0 0.5rem;
        }
        .success-icon { font-size: 3rem; margin-bottom: 0.75rem; }
        .success-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.4rem;
        }
        .success-body {
            color: #6b7280;
            font-size: 0.84rem;
            line-height: 1.65;
            margin-bottom: 1.2rem;
        }

        /* Validation hint */
        .field-error {
            font-size: 0.72rem;
            color: #b91c1c;
            margin-top: 3px;
            display: none;
        }

        input.invalid, select.invalid { border-color: #fca5a5; background: #fef2f2; }

        @media (max-width: 480px) {
            .card { padding: 1.75rem 1.25rem 1.5rem; }
            .row-2 { grid-template-columns: 1fr; gap: 0; }
            .step-label { font-size: 0.58rem; }
        }
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

    <?php if ($success): ?>
    <!-- ── SUCCESS SCREEN ── -->
    <div class="success-screen">
        <div class="success-icon">📧</div>
        <div class="success-title">Check your email!</div>
        <p class="success-body">
            We sent a verification link to<br>
            <strong><?= htmlspecialchars($formData['email'] ?? '') ?></strong><br><br>
            Click the link to activate your account.<br>
            <span style="color:#9ca3af;font-size:0.76rem;">
                The link expires in 24 hours. Check spam if you don't see it.
            </span>
        </p>
        <a href="login.php" style="color:var(--royal-mid);font-weight:700;text-decoration:none;font-size:0.875rem;">
            ← Back to Login
        </a>
    </div>

    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="alert-error">
        <ul><?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <!-- ── STEP PROGRESS ── -->
    <div class="steps" id="stepProgress">
        <div class="step-item active" id="si-1">
            <div class="step-circle active" id="sc-1">1</div>
            <span class="step-label">Account</span>
        </div>
        <div class="step-line" id="sl-1"></div>
        <div class="step-item" id="si-2">
            <div class="step-circle" id="sc-2">2</div>
            <span class="step-label">Personal</span>
        </div>
        <div class="step-line" id="sl-2"></div>
        <div class="step-item" id="si-3">
            <div class="step-circle" id="sc-3">3</div>
            <span class="step-label">Details</span>
        </div>
        <div class="step-line" id="sl-3"></div>
        <div class="step-item" id="si-4">
            <div class="step-circle" id="sc-4">4</div>
            <span class="step-label">Confirm</span>
        </div>
    </div>

    <form method="POST" action="register.php" id="regForm" novalidate>

        <!-- ════════════════════════════════
             STEP 1 — Account type + credentials
        ════════════════════════════════ -->
        <div class="step-panel active" id="panel-1">
            <div class="panel-title">Account Setup</div>

            <div style="font-size:0.78rem;font-weight:600;color:#374151;margin-bottom:0.4rem;">I am a</div>
            <div class="role-tabs">
                <div class="role-tab <?= (($formData['role'] ?? 'student') === 'student') ? 'active' : '' ?>"
                     onclick="setRole('student', this)">🎓 Student</div>
                <div class="role-tab <?= (($formData['role'] ?? '') === 'alumni') ? 'active' : '' ?>"
                     onclick="setRole('alumni', this)">🏅 Alumni / Graduate</div>
            </div>
            <input type="hidden" name="role" id="roleInput"
                   value="<?= htmlspecialchars($formData['role'] ?? 'student') ?>">

            <div class="form-group">
                <label for="email">Email Address <span class="req">*</span></label>
                <input type="email" id="email" name="email"
                       placeholder="you@email.com"
                       value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                       required>
                <div class="field-error" id="err-email">Please enter a valid email.</div>
            </div>

            <div class="form-group">
                <label for="password">Password <span class="req">*</span></label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password"
                           placeholder="Min. 8 characters" required>
                    <span class="toggle-pw" onclick="togglePw('password', this)">Show</span>
                </div>
                <div class="strength-wrap">
                    <div class="strength-track"><div class="strength-bar" id="strength-bar"></div></div>
                    <div class="strength-text" id="strength-text"></div>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_pass">Confirm Password <span class="req">*</span></label>
                <div class="password-wrap">
                    <input type="password" id="confirm_pass" name="confirm_pass"
                           placeholder="Repeat password" required>
                    <span class="toggle-pw" onclick="togglePw('confirm_pass', this)">Show</span>
                </div>
                <div class="field-error" id="err-confirm">Passwords do not match.</div>
            </div>

            <div class="btn-row">
                <button type="button" class="btn-next" onclick="goNext(1)">Next →</button>
            </div>
        </div>

        <!-- ════════════════════════════════
             STEP 2 — Personal info
        ════════════════════════════════ -->
        <div class="step-panel" id="panel-2">
            <div class="panel-title">Personal Information</div>

            <div class="row-2">
                <div class="form-group">
                    <label>First Name <span class="req">*</span></label>
                    <input type="text" name="first_name" id="first_name"
                           placeholder="Juan"
                           value="<?= htmlspecialchars($formData['first_name'] ?? '') ?>" required>
                    <div class="field-error" id="err-fname">First name is required.</div>
                </div>
                <div class="form-group">
                    <label>Last Name <span class="req">*</span></label>
                    <input type="text" name="last_name" id="last_name"
                           placeholder="dela Cruz"
                           value="<?= htmlspecialchars($formData['last_name'] ?? '') ?>" required>
                    <div class="field-error" id="err-lname">Last name is required.</div>
                </div>
            </div>

            <div class="form-group">
                <label>Middle Name <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
                <input type="text" name="middle_name" placeholder="Santos"
                       value="<?= htmlspecialchars($formData['middle_name'] ?? '') ?>">
            </div>

            <div class="row-2">
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="">-- Select --</option>
                        <option value="male"   <?= ($formData['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= ($formData['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="prefer_not_to_say" <?= ($formData['gender'] ?? '') === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="date" name="birthdate"
                           value="<?= htmlspecialchars($formData['birthdate'] ?? '') ?>"
                           max="<?= date('Y-m-d') ?>">
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

        <!-- ════════════════════════════════
             STEP 3 — Academic / alumni details
        ════════════════════════════════ -->
        <div class="step-panel" id="panel-3">
            <div class="panel-title">Academic Details</div>

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

            <!-- Alumni-only -->
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

        <!-- ════════════════════════════════
             STEP 4 — Review & submit
        ════════════════════════════════ -->
        <div class="step-panel" id="panel-4">
            <div class="panel-title">Review &amp; Confirm</div>

            <div id="review-box" style="background:#f8faff;border:1.5px solid var(--border);border-radius:10px;padding:1rem;font-size:0.8rem;color:#374151;line-height:1.9;margin-bottom:0.85rem;">
                <!-- filled by JS -->
            </div>

            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:0.65rem 0.85rem;font-size:0.78rem;color:#92400e;margin-bottom:0.1rem;">
                ⚠️ Please review your details before submitting. A verification email will be sent to your address.
            </div>

            <button type="submit" class="btn-full">Create Account</button>

            <div class="btn-row" style="margin-top:0.65rem;">
                <button type="button" class="btn-prev" style="flex:1;" onclick="goBack(4)">← Edit details</button>
            </div>
        </div>

    </form>
    <?php endif; ?>

    <p class="login-link">Already have an account? <a href="login.php">Login here</a></p>
</div>

<script>
// ── Password toggle ──
function togglePw(id, el) {
    const inp = document.getElementById(id);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    el.textContent = show ? 'Hide' : 'Show';
}

// ── Role toggle ──
function setRole(role, el) {
    document.getElementById('roleInput').value = role;
    document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    const alumni = document.getElementById('alumni-academic');
    if (alumni) alumni.style.display = (role === 'alumni') ? 'block' : 'none';
}

window.addEventListener('DOMContentLoaded', function () {
    const role = document.getElementById('roleInput')?.value;
    if (role === 'alumni') {
        const alumni = document.getElementById('alumni-academic');
        if (alumni) alumni.style.display = 'block';
    }

    // If PHP returned errors, jump to the appropriate panel
    <?php if (!empty($errors)): ?>
    updateProgress(1);
    showPanel(1);
    <?php endif; ?>
});

// ── Strength meter ──
document.getElementById('password')?.addEventListener('input', function () {
    const val = this.value;
    const bar = document.getElementById('strength-bar');
    const txt = document.getElementById('strength-text');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        { w: '0%',   color: '',        label: '' },
        { w: '25%',  color: '#ef4444', label: 'Weak' },
        { w: '50%',  color: '#f59e0b', label: 'Fair' },
        { w: '75%',  color: '#3b82f6', label: 'Good' },
        { w: '100%', color: '#22c55e', label: 'Strong' },
    ];
    bar.style.width      = levels[score].w;
    bar.style.background = levels[score].color;
    txt.textContent      = levels[score].label;
    txt.style.color      = levels[score].color;
});

// ── Step navigation ──
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
        const item   = document.getElementById('si-' + i);
        const line   = document.getElementById('sl-' + i);

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
            circle.textContent = i;
            if (line) line.classList.remove('done');
        } else {
            circle.textContent = i;
            if (line) line.classList.remove('done');
        }
    }
}

function validateStep(step) {
    let valid = true;

    if (step === 1) {
        const email   = document.getElementById('email');
        const pw      = document.getElementById('password');
        const cpw     = document.getElementById('confirm_pass');
        const errE    = document.getElementById('err-email');
        const errC    = document.getElementById('err-confirm');

        const emailOk = email.value && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value);
        email.classList.toggle('invalid', !emailOk);
        errE.style.display = emailOk ? 'none' : 'block';
        if (!emailOk) valid = false;

        const pwOk = pw.value.length >= 8;
        pw.classList.toggle('invalid', !pwOk);
        if (!pwOk) valid = false;

        const matchOk = pw.value === cpw.value && cpw.value !== '';
        cpw.classList.toggle('invalid', !matchOk);
        errC.style.display = matchOk ? 'none' : 'block';
        if (!matchOk) valid = false;
    }

    if (step === 2) {
        const fn = document.getElementById('first_name');
        const ln = document.getElementById('last_name');
        const errFn = document.getElementById('err-fname');
        const errLn = document.getElementById('err-lname');

        const fnOk = fn.value.trim() !== '';
        fn.classList.toggle('invalid', !fnOk);
        errFn.style.display = fnOk ? 'none' : 'block';
        if (!fnOk) valid = false;

        const lnOk = ln.value.trim() !== '';
        ln.classList.toggle('invalid', !lnOk);
        errLn.style.display = lnOk ? 'none' : 'block';
        if (!lnOk) valid = false;
    }

    return valid;
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

function v(id) {
    const el = document.querySelector('[name="' + id + '"]');
    return el ? (el.value || '—') : '—';
}

function buildReview() {
    const role = document.getElementById('roleInput').value;
    const rows = [
        ['Role',        role === 'alumni' ? '🏅 Alumni / Graduate' : '🎓 Student'],
        ['Email',       document.getElementById('email').value || '—'],
        ['Name',        [v('first_name'), v('middle_name') !== '—' ? v('middle_name') : '', v('last_name')].filter(Boolean).join(' ')],
        ['Gender',      v('gender')],
        ['Birthdate',   v('birthdate')],
        ['Contact',     v('contact_number')],
        ['Student ID',  v('student_id')],
        ['Course',      v('course')],
    ];
    if (role === 'alumni') rows.push(['Year Graduated', v('year_graduated')]);

    let html = '';
    rows.forEach(([label, val]) => {
        html += `<div style="display:flex;gap:0.5rem;border-bottom:1px solid #eef1f8;padding:3px 0;">
            <span style="min-width:110px;font-weight:600;color:#6b7280;">${label}</span>
            <span style="color:#111827;">${val}</span>
        </div>`;
    });
    document.getElementById('review-box').innerHTML = html;
}
</script>
</body>
</html>