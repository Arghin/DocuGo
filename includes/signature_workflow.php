<?php
// ================================================================
// includes/signature_workflow.php
// Core engine for the signature workflow.
// Include this on any page that needs to trigger or query signatures.
// ================================================================

/**
 * When a request moves to 'for_signature', this creates
 * one document_signatures row per required signatory_role.
 * Call this immediately after inserting/updating the status.
 */
function spawnSignatureRows(mysqli $conn, int $requestId, int $documentTypeId): void
{
    // Check if rows already exist (prevent duplicates on re-trigger)
    $check = $conn->prepare("SELECT COUNT(*) AS c FROM document_signatures WHERE request_id = ?");
    $check->bind_param("i", $requestId);
    $check->execute();
    if ($check->get_result()->fetch_assoc()['c'] > 0) {
        $check->close();
        return; // already spawned
    }
    $check->close();

    // Fetch all signatory roles for this document type (active, ordered)
    $roles = $conn->prepare("
        SELECT id FROM signatory_roles
        WHERE document_type_id = ? AND is_active = 1
        ORDER BY order_no ASC
    ");
    $roles->bind_param("i", $documentTypeId);
    $roles->execute();
    $result = $roles->get_result();
    $roles->close();

    // Insert a pending row for each role
    $ins = $conn->prepare("
        INSERT IGNORE INTO document_signatures (request_id, signatory_role_id, status)
        VALUES (?, ?, 'pending')
    ");
    while ($row = $result->fetch_assoc()) {
        $ins->bind_param("ii", $requestId, $row['id']);
        $ins->execute();
    }
    $ins->close();
}

/**
 * Get all signature rows for a request with full signatory info.
 */
function getSignatureRows(mysqli $conn, int $requestId): array
{
    $stmt = $conn->prepare("
        SELECT ds.id, ds.status, ds.signed_at, ds.remarks,
               sr.role_name, sr.role_label, sr.order_no,
               CONCAT(u.first_name,' ',u.last_name) AS signed_by_name
        FROM   document_signatures ds
        JOIN   signatory_roles sr ON ds.signatory_role_id = sr.id
        LEFT JOIN users u ON ds.signed_by = u.id
        WHERE  ds.request_id = ?
        ORDER  BY sr.order_no ASC
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Check if ALL signatures for a request are signed.
 * If yes, auto-move request status to 'approved' and calculate
 * estimated_release_date.
 * Returns true if auto-approved.
 */
function checkAndAutoApprove(mysqli $conn, int $requestId): bool
{
    // Count pending/rejected signatures still outstanding
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS outstanding
        FROM document_signatures
        WHERE request_id = ? AND status != 'signed'
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $outstanding = (int)$stmt->get_result()->fetch_assoc()['outstanding'];
    $stmt->close();

    if ($outstanding > 0) return false;

    // All signed — fetch processing_days to calculate release date
    $info = $conn->prepare("
        SELECT dr.document_type_id, dt.processing_days
        FROM   document_requests dr
        JOIN   document_types    dt ON dr.document_type_id = dt.id
        WHERE  dr.id = ? AND dr.status = 'for_signature'
        LIMIT  1
    ");
    $info->bind_param("i", $requestId);
    $info->execute();
    $req = $info->get_result()->fetch_assoc();
    $info->close();

    if (!$req) return false; // already approved or different status

    $days = (int)($req['processing_days'] ?? 3);

    // Update request: approved + estimated release date
    $upd = $conn->prepare("
        UPDATE document_requests
        SET status = 'approved',
            estimated_release_date = DATE_ADD(CURDATE(), INTERVAL ? DAY),
            updated_at = NOW()
        WHERE id = ?
    ");
    $upd->bind_param("ii", $days, $requestId);
    $upd->execute();
    $upd->close();

    // Log it (system action, changed_by = 1 = admin account)
    $log = $conn->prepare("
        INSERT INTO request_logs (request_id, changed_by, old_status, new_status, notes)
        VALUES (?, 1, 'for_signature', 'approved', 'All signatures obtained — auto-approved by system.')
    ");
    $log->bind_param("i", $requestId);
    $log->execute();
    $log->close();

    // Notify the student
    $nStmt = $conn->prepare("
        SELECT dr.user_id, dr.request_code, dt.name AS doc_name
        FROM   document_requests dr
        JOIN   document_types    dt ON dr.document_type_id = dt.id
        WHERE  dr.id = ?
    ");
    $nStmt->bind_param("i", $requestId);
    $nStmt->execute();
    $nReq = $nStmt->get_result()->fetch_assoc();
    $nStmt->close();

    if ($nReq) {
        $msg = "All signatures for your request {$nReq['request_code']} ({$nReq['doc_name']}) have been obtained. Your request is now APPROVED and being scheduled for processing.";
        $notify = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $notify->bind_param("is", $nReq['user_id'], $msg);
        $notify->execute();
        $notify->close();
    }

    return true;
}

/**
 * Sign a single document_signatures row.
 * Validates that:
 *  - the signer's signatory_role matches the row's role_name
 *  - the row is currently 'pending'
 * Then calls checkAndAutoApprove.
 */
function signDocument(
    mysqli $conn,
    int    $signatureId,
    int    $signerUserId,
    string $signerRoleName,
    string $remarks = ''
): array {
    // Fetch the signature row
    $stmt = $conn->prepare("
        SELECT ds.*, sr.role_name, dr.request_code, dr.status AS req_status
        FROM   document_signatures ds
        JOIN   signatory_roles     sr ON ds.signatory_role_id = sr.id
        JOIN   document_requests   dr ON ds.request_id = dr.id
        WHERE  ds.id = ?
        LIMIT  1
    ");
    $stmt->bind_param("i", $signatureId);
    $stmt->execute();
    $sig = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sig) {
        return ['success' => false, 'error' => 'Signature record not found.'];
    }
    if ($sig['status'] === 'signed') {
        return ['success' => false, 'error' => 'This signature has already been recorded.'];
    }
    if ($sig['req_status'] !== 'for_signature') {
        return ['success' => false, 'error' => 'This request is not currently in the signature stage.'];
    }
    if ($sig['role_name'] !== $signerRoleName) {
        return ['success' => false, 'error' => 'You are not assigned to sign this role.'];
    }

    // Record the signature
    $upd = $conn->prepare("
        UPDATE document_signatures
        SET status = 'signed', signed_by = ?, signed_at = NOW(), remarks = ?
        WHERE id = ?
    ");
    $upd->bind_param("isi", $signerUserId, $remarks, $signatureId);
    $upd->execute();
    $upd->close();

    // Check if all done → auto-approve
    $autoApproved = checkAndAutoApprove($conn, (int)$sig['request_id']);

    return [
        'success'      => true,
        'auto_approved'=> $autoApproved,
        'request_id'   => (int)$sig['request_id'],
        'error'        => null,
    ];
}

/**
 * Reject a signature (e.g. document has an issue).
 * Moves the whole request to 'cancelled' with a note.
 */
function rejectSignature(
    mysqli $conn,
    int    $signatureId,
    int    $signerUserId,
    string $reason
): array {
    $stmt = $conn->prepare("
        SELECT ds.request_id, sr.role_name, sr.role_label
        FROM   document_signatures ds
        JOIN   signatory_roles sr ON ds.signatory_role_id = sr.id
        WHERE  ds.id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $signatureId);
    $stmt->execute();
    $sig = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sig) return ['success' => false, 'error' => 'Record not found.'];

    // Mark this signature rejected
    $upd = $conn->prepare("
        UPDATE document_signatures
        SET status = 'rejected', signed_by = ?, signed_at = NOW(), remarks = ?
        WHERE id = ?
    ");
    $upd->bind_param("isi", $signerUserId, $reason, $signatureId);
    $upd->execute();
    $upd->close();

    // Cancel the whole request
    $cancel = $conn->prepare("
        UPDATE document_requests SET status = 'cancelled', remarks = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $note = "[{$sig['role_label']}] Rejected: {$reason}";
    $cancel->bind_param("si", $note, $sig['request_id']);
    $cancel->execute();
    $cancel->close();

    // Notify student
    $nStmt = $conn->prepare("
        SELECT dr.user_id, dr.request_code FROM document_requests dr WHERE dr.id = ?
    ");
    $nStmt->bind_param("i", $sig['request_id']);
    $nStmt->execute();
    $nReq = $nStmt->get_result()->fetch_assoc();
    $nStmt->close();

    if ($nReq) {
        $msg = "Your request {$nReq['request_code']} has been rejected by {$sig['role_label']}. Reason: {$reason}. Please contact the Registrar for assistance.";
        $notify = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $notify->bind_param("is", $nReq['user_id'], $msg);
        $notify->execute();
        $notify->close();
    }

    return ['success' => true, 'error' => null];
}