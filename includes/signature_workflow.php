<?php
// ================================================================
// includes/signature_workflow.php
// SIMPLIFIED: Any signatory can sign, ONE signature = approved
// ================================================================

/**
 * Spawn ONE request_signatures row for a request
 * when a request moves to 'for_signature'.
 */
function spawnSignatureRows(mysqli $conn, int $requestId, int $documentTypeId): void
{
    // Check if signature row already exists
    $check = $conn->prepare("
        SELECT id FROM request_signatures WHERE request_id = ? LIMIT 1
    ");
    $check->bind_param("i", $requestId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    
    if ($exists) {
        return; // Already has signature row
    }
    
    // Create ONE signature row (office_id = 1 as default)
    $ins = $conn->prepare("
        INSERT INTO request_signatures (request_id, office_id, status)
        VALUES (?, 1, 'pending')
    ");
    $ins->bind_param("i", $requestId);
    $ins->execute();
    $ins->close();
}

/**
 * Return signature row for a request (simplified)
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
            'Signature Required' AS office_name,
            CONCAT(u.first_name,' ',u.last_name) AS signed_by_name
        FROM   request_signatures rs
        LEFT JOIN users u ON rs.signed_by = u.id
        WHERE  rs.request_id = ?
        LIMIT 1
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
 * Auto-approve request after ONE signature.
 * Returns true if auto-approved.
 */
function checkAndAutoApprove(mysqli $conn, int $requestId): bool
{
    // Check if request has been signed (ANY signature)
    $stmt = $conn->prepare("
        SELECT status FROM request_signatures
        WHERE request_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // If not signed yet, return false
    if (!$row || $row['status'] !== 'signed') {
        return false;
    }

    // Fetch request details
    $info = $conn->prepare("
        SELECT dr.status, dr.user_id, dr.request_code,
               dt.processing_days, dt.name AS doc_name
        FROM   document_requests dr
        JOIN   document_types    dt ON dr.document_type_id = dt.id
        WHERE  dr.id = ? LIMIT 1
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
        SET    status = 'approved',
               approved_at = NOW(),
               estimated_release_date = DATE_ADD(CURDATE(), INTERVAL ? DAY),
               updated_at = NOW()
        WHERE  id = ?
    ");
    $upd->bind_param("ii", $days, $requestId);
    $upd->execute();
    $upd->close();

    // Log
    $log = $conn->prepare("
        INSERT INTO request_logs (request_id, changed_by, old_status, new_status, notes)
        VALUES (?, 1, 'for_signature', 'approved',
                'Document signed and approved by signatory staff.')
    ");
    $log->bind_param("i", $requestId);
    $log->execute();
    $log->close();

    // Notify student
    $msg = "✅ Your request {$req['request_code']} ({$req['doc_name']}) has been signed and APPROVED! "
         . "Your document will now be processed.";
    $notify = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $notify->bind_param("is", $req['user_id'], $msg);
    $notify->execute();
    $notify->close();

    return true;
}

/**
 * Sign a request - ANY signatory can sign (no office matching)
 */
function signRequestRow(mysqli $conn, int $sigRowId, int $signerUserId, string $remarks = ''): array
{
    // Get the signature row
    $stmt = $conn->prepare("
        SELECT rs.id, rs.request_id, rs.status AS sig_status,
               dr.status AS req_status, dr.request_code
        FROM   request_signatures rs
        JOIN   document_requests  dr ON rs.request_id = dr.id
        WHERE  rs.id = ?
        LIMIT  1
    ");
    $stmt->bind_param("i", $sigRowId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'auto_approved' => false, 'error' => 'Signature record not found.'];
    }
    
    if ($row['sig_status'] === 'signed') {
        return ['success' => false, 'auto_approved' => false, 'error' => 'This request has already been signed.'];
    }
    
    if ($row['req_status'] !== 'for_signature') {
        return ['success' => false, 'auto_approved' => false, 'error' => 'Request is not in the signature stage.'];
    }

    // NO office matching check - ANY signatory can sign

    // Update signature row to signed
    $upd = $conn->prepare("
        UPDATE request_signatures
        SET status = 'signed', signed_by = ?, signed_at = NOW(), remarks = ?
        WHERE id = ?
    ");
    $upd->bind_param("isi", $signerUserId, $remarks, $sigRowId);
    $upd->execute();
    $upd->close();

    // Auto-approve the request
    $autoApproved = checkAndAutoApprove($conn, (int)$row['request_id']);

    return [
        'success' => true, 
        'auto_approved' => $autoApproved, 
        'request_id' => (int)$row['request_id'], 
        'error' => null
    ];
}

/**
 * Reject a signature - ANY signatory can reject (no office matching)
 */
function rejectSignatureRow(mysqli $conn, int $sigRowId, int $signerUserId, string $reason): array
{
    $stmt = $conn->prepare("
        SELECT rs.request_id, dr.user_id, dr.request_code, dr.status AS req_status
        FROM   request_signatures rs
        JOIN   document_requests  dr ON rs.request_id = dr.id
        WHERE  rs.id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $sigRowId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['success' => false, 'error' => 'Record not found.'];
    }
    
    if ($row['req_status'] !== 'for_signature') {
        return ['success' => false, 'error' => 'Request is not in signature stage.'];
    }

    // NO office matching check - ANY signatory can reject

    // Update signature status to rejected
    $upd = $conn->prepare("
        UPDATE request_signatures 
        SET status = 'rejected', signed_by = ?, signed_at = NOW(), remarks = ? 
        WHERE id = ?
    ");
    $upd->bind_param("isi", $signerUserId, $reason, $sigRowId);
    $upd->execute();
    $upd->close();

    // Cancel the request
    $cancel = $conn->prepare("
        UPDATE document_requests 
        SET status = 'cancelled', remarks = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $cancel->bind_param("si", $reason, $row['request_id']);
    $cancel->execute();
    $cancel->close();

    // Log the action
    $log = $conn->prepare("
        INSERT INTO request_logs (request_id, changed_by, old_status, new_status, notes) 
        VALUES (?, ?, 'for_signature', 'cancelled', ?)
    ");
    $log->bind_param("iis", $row['request_id'], $signerUserId, $reason);
    $log->execute();
    $log->close();

    // Notify student
    $msg = "❌ Your request {$row['request_code']} was rejected. Reason: {$reason}. Please contact the Registrar's Office.";
    $notify = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $notify->bind_param("is", $row['user_id'], $msg);
    $notify->execute();
    $notify->close();

    return ['success' => true, 'error' => null];
}

/**
 * Counts for the signatory dashboard header stats (ALL signatories)
 */
function getSignatoryStats(mysqli $conn, int $officeId = null): array
{
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN rs.status = 'pending' AND dr.status = 'for_signature' THEN 1 ELSE 0 END) AS pending_cnt,
            SUM(CASE WHEN rs.status = 'signed' THEN 1 ELSE 0 END) AS signed_cnt,
            SUM(CASE WHEN rs.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_cnt
        FROM   request_signatures rs
        JOIN   document_requests  dr ON rs.request_id = dr.id
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
 * Alias function for backward compatibility with signature_helper.php
 */
function createSignatureRoutes(mysqli $conn, int $requestId): bool
{
    // Get document_type_id from the request
    $stmt = $conn->prepare("
        SELECT document_type_id FROM document_requests WHERE id = ?
    ");
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
 * Get signature progress for a request
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