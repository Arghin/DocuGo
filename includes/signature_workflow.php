<?php
// ================================================================
// includes/signature_workflow.php
// 4 SIGNATURES REQUIRED FOR APPROVAL + REJECTION FEATURE
// ================================================================

/**
 * Create 4 signature slots for a request
 */
function spawnSignatureRows(mysqli $conn, int $requestId, int $documentTypeId): void
{
    // Check if signature rows already exist
    $check = $conn->prepare("SELECT id FROM request_signatures WHERE request_id = ? LIMIT 1");
    $check->bind_param("i", $requestId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    
    if ($exists) {
        return;
    }
    
    // Create 4 pending signature slots
    $ins = $conn->prepare("INSERT INTO request_signatures (request_id, office_id, status) VALUES (?, ?, 'pending')");
    
    for ($i = 1; $i <= 4; $i++) {
        $ins->bind_param("ii", $requestId, $i);
        $ins->execute();
    }
    $ins->close();
}

/**
 * Get all signature rows for a request
 */
function getSignatureRows(mysqli $conn, int $requestId): array
{
    $stmt = $conn->prepare("
        SELECT
            rs.id,
            rs.status,
            rs.signed_at,
            rs.remarks,
            rs.signed_by,
            CONCAT(u.first_name,' ',u.last_name) AS signed_by_name,
            CONCAT('Signatory ', rs.office_id) AS office_name
        FROM request_signatures rs
        LEFT JOIN users u ON rs.signed_by = u.id
        WHERE rs.request_id = ?
        ORDER BY rs.id ASC
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/**
 * Check if 4 signatures are collected, then auto-approve
 */
function checkAndAutoApprove(mysqli $conn, int $requestId): bool
{
    // First check if request is already rejected
    $checkStatus = $conn->prepare("SELECT status FROM document_requests WHERE id = ?");
    $checkStatus->bind_param("i", $requestId);
    $checkStatus->execute();
    $currentStatus = $checkStatus->get_result()->fetch_assoc();
    $checkStatus->close();
    
    if ($currentStatus['status'] === 'cancelled') {
        return false;
    }
    
    // Count how many signatures have been collected
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total_signed
        FROM request_signatures
        WHERE request_id = ? AND status = 'signed'
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $signedCount = (int)$result['total_signed'];
    $stmt->close();

    $REQUIRED_SIGNATURES = 4;
    
    if ($signedCount < $REQUIRED_SIGNATURES) {
        return false;
    }

    // Check if request is still in signature stage
    $info = $conn->prepare("
        SELECT dr.status, dr.user_id, dr.request_code,
               dt.processing_days, dt.name AS doc_name
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        WHERE dr.id = ? LIMIT 1
    ");
    $info->bind_param("i", $requestId);
    $info->execute();
    $req = $info->get_result()->fetch_assoc();
    $info->close();

    if (!$req || $req['status'] !== 'for_signature') {
        return false;
    }

    $days = max(1, (int) $req['processing_days']);

    // Approve + set estimated release date
    $upd = $conn->prepare("
        UPDATE document_requests
        SET status = 'approved',
            approved_at = NOW(),
            estimated_release_date = DATE_ADD(CURDATE(), INTERVAL ? DAY),
            updated_at = NOW()
        WHERE id = ?
    ");
    $upd->bind_param("ii", $days, $requestId);
    $upd->execute();
    $upd->close();

    // Log
    $log = $conn->prepare("
        INSERT INTO request_logs (request_id, changed_by, old_status, new_status, notes)
        VALUES (?, 1, 'for_signature', 'approved',
                'All 4 signatures collected — request auto-approved.')
    ");
    $log->bind_param("i", $requestId);
    $log->execute();
    $log->close();

    // Notify student
    $msg = "✅ Your request {$req['request_code']} ({$req['doc_name']}) has been signed by all 4 signatories and APPROVED! Your document will now be processed.";
    $notify = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $notify->bind_param("is", $req['user_id'], $msg);
    $notify->execute();
    $notify->close();

    return true;
}

/**
 * Sign a signature slot
 */
function signRequestRow(mysqli $conn, int $sigRowId, int $signerUserId, string $remarks = ''): array
{
    $stmt = $conn->prepare("
        SELECT rs.id, rs.request_id, rs.status AS sig_status,
               dr.status AS req_status, dr.request_code
        FROM request_signatures rs
        JOIN document_requests dr ON rs.request_id = dr.id
        WHERE rs.id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $sigRowId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'auto_approved' => false, 'error' => 'Signature record not found.'];
    }
    
    if ($row['sig_status'] === 'signed') {
        return ['success' => false, 'auto_approved' => false, 'error' => 'This signature slot has already been taken.'];
    }
    
    if ($row['req_status'] !== 'for_signature') {
        return ['success' => false, 'auto_approved' => false, 'error' => 'Request is not in the signature stage.'];
    }

    $upd = $conn->prepare("
        UPDATE request_signatures
        SET status = 'signed', signed_by = ?, signed_at = NOW(), remarks = ?
        WHERE id = ?
    ");
    $upd->bind_param("isi", $signerUserId, $remarks, $sigRowId);
    $upd->execute();
    $upd->close();

    $autoApproved = checkAndAutoApprove($conn, (int)$row['request_id']);

    return [
        'success' => true,
        'auto_approved' => $autoApproved,
        'request_id' => (int)$row['request_id'],
        'error' => null
    ];
}

/**
 * REJECT a request - Cancels the entire request with a reason
 */
function rejectRequest(mysqli $conn, int $requestId, int $signerUserId, string $reason, string $officeName = ''): array
{
    // Check if request is already rejected or approved
    $checkStmt = $conn->prepare("SELECT status FROM document_requests WHERE id = ?");
    $checkStmt->bind_param("i", $requestId);
    $checkStmt->execute();
    $current = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if ($current['status'] !== 'for_signature') {
        return ['success' => false, 'error' => 'Request cannot be rejected at this stage.'];
    }
    
    // Update all pending signature slots to rejected
    $updateSigs = $conn->prepare("
        UPDATE request_signatures 
        SET status = 'rejected', signed_by = ?, signed_at = NOW(), remarks = ? 
        WHERE request_id = ? AND status = 'pending'
    ");
    $updateSigs->bind_param("isi", $signerUserId, $reason, $requestId);
    $updateSigs->execute();
    $updateSigs->close();
    
    // Cancel the document request
    $cancelMsg = "Rejected by {$officeName}: {$reason}";
    $cancelReq = $conn->prepare("
        UPDATE document_requests 
        SET status = 'cancelled', remarks = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $cancelReq->bind_param("si", $cancelMsg, $requestId);
    $cancelReq->execute();
    $cancelReq->close();
    
    // Get request details for notification
    $info = $conn->prepare("
        SELECT dr.request_code, dr.user_id, dt.name as doc_name
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        WHERE dr.id = ?
    ");
    $info->bind_param("i", $requestId);
    $info->execute();
    $request = $info->get_result()->fetch_assoc();
    $info->close();
    
    // Log the rejection
    $log = $conn->prepare("
        INSERT INTO request_logs (request_id, changed_by, old_status, new_status, notes) 
        VALUES (?, ?, 'for_signature', 'cancelled', ?)
    ");
    $log->bind_param("iis", $requestId, $signerUserId, $cancelMsg);
    $log->execute();
    $log->close();
    
    // Notify student
    $msg = "❌ Your request {$request['request_code']} ({$request['doc_name']}) was REJECTED by {$officeName}.\n\nReason: {$reason}\n\nPlease contact the office for more information.";
    $notify = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $notify->bind_param("is", $request['user_id'], $msg);
    $notify->execute();
    $notify->close();
    
    return ['success' => true, 'error' => null];
}

/**
 * Get stats for dashboard - FIXED: Counts UNIQUE requests, not signature slots
 */
function getSignatoryStats(mysqli $conn, int $officeId = null): array
{
    // Count UNIQUE document requests, not signature slots
    $stmt = $conn->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN rs.status = 'pending' AND dr.status = 'for_signature' THEN dr.id END) AS pending_cnt,
            COUNT(DISTINCT CASE WHEN dr.status = 'approved' THEN dr.id END) AS signed_cnt,
            COUNT(DISTINCT CASE WHEN dr.status = 'cancelled' THEN dr.id END) AS rejected_cnt
        FROM request_signatures rs
        JOIN document_requests dr ON rs.request_id = dr.id
    ");
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return [
        'pending'  => (int)($s['pending_cnt'] ?? 0),
        'signed'   => (int)($s['signed_cnt'] ?? 0),
        'rejected' => (int)($s['rejected_cnt'] ?? 0),
    ];
}

/**
 * Alias for backward compatibility
 */
function createSignatureRoutes(mysqli $conn, int $requestId): bool
{
    $stmt = $conn->prepare("SELECT document_type_id FROM document_requests WHERE id = ?");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $request = $result->fetch_assoc();
    $stmt->close();
    
    if (!$request) {
        return false;
    }
    
    spawnSignatureRows($conn, $requestId, $request['document_type_id']);
    return true;
}

/**
 * Get signature progress with counts
 */
function getSignatureProgress(mysqli $conn, int $requestId): array
{
    $signatures = getSignatureRows($conn, $requestId);
    
    $signedCount = 0;
    $totalCount = count($signatures);
    
    foreach ($signatures as $sig) {
        if ($sig['status'] === 'signed') {
            $signedCount++;
        }
    }
    
    return [
        'signatures' => $signatures,
        'signed_count' => $signedCount,
        'total_count' => $totalCount,
        'is_complete' => ($signedCount === $totalCount && $totalCount > 0),
        'progress_percent' => $totalCount > 0 ? round(($signedCount / $totalCount) * 100) : 0
    ];
}
?>