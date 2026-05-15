<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/signature_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['office_role'])) {
    header("Location: ../index.php");
    exit();
}

$signatureId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$remarks = isset($_GET['remarks']) ? $_GET['remarks'] : null;

if ($signatureId <= 0 || !in_array($action, ['sign', 'reject'])) {
    header("Location: signatures.php?error=invalid_action");
    exit();
}

$userId = $_SESSION['user_id'];

// Get request_id from signature
$getRequestStmt = $conn->prepare("
    SELECT request_id FROM request_signatures WHERE id = ?
");
$getRequestStmt->bind_param("i", $signatureId);
$getRequestStmt->execute();
$result = $getRequestStmt->get_result();
$signatureData = $result->fetch_assoc();
$getRequestStmt->close();

if (!$signatureData) {
    header("Location: signatures.php?error=not_found");
    exit();
}

$requestId = $signatureData['request_id'];

if ($action == 'sign') {
    // Update signature status
    $updateStmt = $conn->prepare("
        UPDATE request_signatures
        SET status = 'signed',
            signed_by = ?,
            signed_at = NOW(),
            remarks = NULL
        WHERE id = ?
    ");
    
    $updateStmt->bind_param("ii", $userId, $signatureId);
    $updateStmt->execute();
    $updateStmt->close();
    
    // Check if all signatures are complete
    updateRequestStatusIfAllSigned($conn, $requestId);
    
    $_SESSION['message'] = "Document signed successfully";
    
} elseif ($action == 'reject') {
    // Update signature as rejected
    $updateStmt = $conn->prepare("
        UPDATE request_signatures
        SET status = 'rejected',
            signed_by = ?,
            signed_at = NOW(),
            remarks = ?
        WHERE id = ?
    ");
    
    $updateStmt->bind_param("isi", $userId, $remarks, $signatureId);
    $updateStmt->execute();
    $updateStmt->close();
    
    // Update document request status to rejected
    $updateRequestStmt = $conn->prepare("
        UPDATE document_requests
        SET status = 'rejected',
            remarks = ?
        WHERE id = ?
    ");
    
    $updateRequestStmt->bind_param("si", $remarks, $requestId);
    $updateRequestStmt->execute();
    $updateRequestStmt->close();
    
    $_SESSION['message'] = "Document rejected: " . htmlspecialchars($remarks);
}

header("Location: signatures.php");
exit();
?>