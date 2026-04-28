<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
requireAdmin();

$conn   = getConnection();
$userId = $_SESSION['user_id'];

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: requests.php');
    exit();
}

$requestId     = intval($_POST['request_id'] ?? 0);
$receiptNumber = trim($_POST['receipt_number'] ?? '');
$notes         = trim($_POST['notes'] ?? '');

// ── Validate inputs ──────────────────────────────────────────
if ($requestId <= 0) {
    header('Location: requests.php?msg=Invalid+request.&msgtype=error');
    exit();
}

if (empty($receiptNumber)) {
    header("Location: requests.php?msg=Official+receipt+number+is+required.&msgtype=error&status=ready");
    exit();
}

// ── Process Pay & Release ────────────────────────────────────
$result = processPayAndRelease($conn, $requestId, $userId, $receiptNumber, $notes);
$conn->close();

if ($result['success']) {
    $msg = urlencode("✅ Payment recorded (OR#: {$receiptNumber}) and document released successfully. Amount collected: ₱" . number_format($result['amount'], 2));
    header("Location: requests.php?status=released&msg={$msg}&msgtype=success");
} else {
    $msg = urlencode($result['message']);
    header("Location: requests.php?status=ready&msg={$msg}&msgtype=error");
}
exit();