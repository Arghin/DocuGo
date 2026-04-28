<?php
require_once 'includes/config.php';

$message = '';
$type    = 'error';
$token   = sanitize($_GET['token'] ?? '');

if (empty($token)) {
    $message = 'Invalid verification link.';
} else {
    $conn = getConnection();

    // Find valid token that hasn't expired
    $stmt = $conn->prepare("
        SELECT ev.user_id, ev.expires_at, u.status, u.first_name
        FROM email_verifications ev
        JOIN users u ON ev.user_id = u.id
        WHERE ev.token = ?
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $message = 'This verification link is invalid or has already been used.';
    } else {
        $row = $result->fetch_assoc();

        if (strtotime($row['expires_at']) < time()) {
            $message = 'This verification link has expired. Please register again or request a new link.';
        } elseif ($row['status'] === 'active') {
            $message = 'Your account is already verified! You can now login.';
            $type = 'success';
        } else {
            // Activate the account
            $update = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $update->bind_param("i", $row['user_id']);
            $update->execute();
            $update->close();

            // Delete used token
            $delete = $conn->prepare("DELETE FROM email_verifications WHERE token = ?");
            $delete->bind_param("s", $token);
            $delete->execute();
            $delete->close();

            $message = 'Your account has been verified successfully! You can now login.';
            $type = 'success';
        }
    }

    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification — DocuGo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.10);
            width: 100%;
            max-width: 420px;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .logo h1 { font-size: 2rem; color: #1a56db; font-weight: 800; letter-spacing: -1px; }
        .logo p   { font-size: 0.82rem; color: #6b7280; margin-top: 2px; margin-bottom: 2rem; }
        .icon { font-size: 3.5rem; margin-bottom: 1rem; }
        h2 { font-size: 1.15rem; font-weight: 700; color: #111827; margin-bottom: 0.6rem; }
        p  { font-size: 0.875rem; color: #6b7280; line-height: 1.6; margin-bottom: 1.5rem; }
        .btn {
            display: inline-block;
            padding: 0.7rem 2rem;
            background: #1a56db;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: background 0.2s;
        }
        .btn:hover { background: #1447c0; }
        .btn-outline {
            background: transparent;
            color: #1a56db;
            border: 1.5px solid #1a56db;
            margin-left: 0.5rem;
        }
        .btn-outline:hover { background: #eff6ff; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <h1>DocuGo</h1>
        <p>Asian Development Foundation College</p>
    </div>

    <?php if ($type === 'success'): ?>
        <div class="icon">✅</div>
        <h2>Account Verified!</h2>
        <p><?= htmlspecialchars($message) ?></p>
        <a href="login.php" class="btn">Login Now →</a>

    <?php else: ?>
        <div class="icon">❌</div>
        <h2>Verification Failed</h2>
        <p><?= htmlspecialchars($message) ?></p>
        <a href="login.php" class="btn">Go to Login</a>
        <a href="register.php" class="btn btn-outline">Register Again</a>
    <?php endif; ?>
</div>
</body>
</html>