<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_requests.php');
    exit();
}

$conn      = getConnection();
$userId    = $_SESSION['user_id'];
$requestId = intval($_POST['request_id'] ?? 0);

if ($requestId <= 0) {
    header('Location: my_requests.php?msg=Invalid+request.&msgtype=error');
    exit();
}

// Verify this request belongs to the user
$stmt = $conn->prepare("SELECT id, status FROM document_requests WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $requestId, $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    $conn->close();
    header('Location: my_requests.php?msg=Request+not+found.&msgtype=error');
    exit();
}

// Only allow cancellation of pending requests by student
if (!in_array($row['status'], ['pending'])) {
    $conn->close();
    header('Location: my_requests.php?msg=Only+pending+requests+can+be+cancelled.&msgtype=error');
    exit();
}

$result = updateRequestStatus($conn, $requestId, 'cancelled', $userId, 'Cancelled by student.');
$conn->close();

if ($result['success']) {
    header('Location: my_requests.php?msg=Request+cancelled+successfully.&msgtype=success');
} else {
    header('Location: my_requests.php?msg=' . urlencode($result['message']) . '&msgtype=error');
}
exit();