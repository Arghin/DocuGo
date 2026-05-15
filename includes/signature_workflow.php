<?php
// ================================================================
// includes/signature_workflow.php
// Uses: request_signatures, signature_offices, signatory_roles
// ================================================================

/**
 * Spawn one request_signatures row per required office
 * when a request moves to 'for_signature'.
 * Uses INSERT IGNORE — safe to call multiple times.
 */
function spawnSignatureRows(mysqli $conn, int $requestId, int $documentTypeId): void
{
    $stmt = $conn->prepare("
        SELECT so.id AS office_id
        FROM   signatory_roles   sr
        JOIN   signature_offices so ON so.office_code = sr.role_name
        WHERE  sr.document_type_id = ? AND sr.is_active = 1
        ORDER  BY sr.order_no ASC
    ");
    $stmt->bind_param("i", $documentTypeId);
    $stmt->execute();
    $offices = $stmt->get_result();
    $stmt->close();

    $ins = $conn->prepare("
        INSERT IGNORE INTO request_signatures (request_id, office_id, status)
        VALUES (?, ?, 'pending')
    ");
    while ($o = $offices->fetch_assoc()) {
        $ins->bind_param("ii", $requestId, $o['office_id']);
        $ins->execute();
    }
    $ins->close();
}

/**
 * Return all signature rows for a request with office + signer info.
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
            so.id           AS office_id,
            so.office_name,
            so.office_code,
            sr.order_no,
            sr.role_label,
            CONCAT(u.first_name,' ',u.last_name) AS signed_by_name
        FROM   request_signatures rs
        JOIN   signature_offices  so ON rs.office_id = so.id
        LEFT JOIN signatory_roles sr ON sr.role_name = so.office_code
                                     AND sr.document_type_id = (
                                         SELECT document_type_id
                                         FROM   document_requests
                                         WHERE  id = rs.request_id LIMIT 1
                                     )
        LEFT JOIN users u ON rs.signed_by = u.id
        WHERE  rs.request_id = ?
        ORDER  BY COALESCE(sr.order_no, 99) ASC
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * If all request_signatures are 'signed', auto-approve the request.
 * Returns true if auto-approved.
 */
function checkAndAutoApprove(mysqli $conn, int $requestId): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c FROM request_signatures
        WHERE request_id = ? AND status != 'signed'
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $pending = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($pending > 0) return false;

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

    if (!$req || $req['status'] !== 'for_signature') return false;

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
                'All office signatures obtained — auto-approved by system.')
    ");
    $log->bind_param("i", $requestId);
    $log->execute();
    $log->close();

    // Notify student
    $msg = "All signatures for your request {$req['request_code']} ({$req['doc_name']}) are complete. "
         . "Your request is now APPROVED and will be processed soon.";
    $notify = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $notify->bind_param("is", $req['user_id'], $msg);
    $notify->execute();
    $notify->close();

    return true;
}

/**
 * Sign a request_signatures row.
 * Validates office match via users.signature_office_id.
 */
function signRequestRow(mysqli $conn, int $sigRowId, int $signerUserId, string $remarks = ''): array
{
    $stmt = $conn->prepare("
        SELECT rs.id, rs.request_id, rs.office_id, rs.status AS sig_status,
               dr.status AS req_status, dr.request_code,
               u.signature_office_id AS signer_office_id
        FROM   request_signatures rs
        JOIN   document_requests  dr ON rs.request_id = dr.id
        JOIN   users              u  ON u.id = ?
        WHERE  rs.id = ?
        LIMIT  1
    ");
    $stmt->bind_param("ii", $signerUserId, $sigRowId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row)
        return ['success'=>false,'auto_approved'=>false,'error'=>'Record not found.'];
    if ($row['sig_status'] === 'signed')
        return ['success'=>false,'auto_approved'=>false,'error'=>'Already signed.'];
    if ($row['req_status'] !== 'for_signature')
        return ['success'=>false,'auto_approved'=>false,'error'=>'Request is not in the signature stage.'];
    if ((int)$row['signer_office_id'] !== (int)$row['office_id'])
        return ['success'=>false,'auto_approved'=>false,'error'=>'This signature is not assigned to your office.'];

    $upd = $conn->prepare("
        UPDATE request_signatures
        SET status='signed', signed_by=?, signed_at=NOW(), remarks=?
        WHERE id=?
    ");
    $upd->bind_param("isi", $signerUserId, $remarks, $sigRowId);
    $upd->execute();
    $upd->close();

    $autoApproved = checkAndAutoApprove($conn, (int)$row['request_id']);

    return ['success'=>true,'auto_approved'=>$autoApproved,'request_id'=>(int)$row['request_id'],'error'=>null];
}

/**
 * Reject a signature — cancels the entire request.
 */
function rejectSignatureRow(mysqli $conn, int $sigRowId, int $signerUserId, string $reason): array
{
    $stmt = $conn->prepare("
        SELECT rs.request_id, rs.office_id, so.office_name,
               dr.user_id, dr.request_code, dr.status AS req_status,
               u.signature_office_id AS signer_office_id
        FROM   request_signatures rs
        JOIN   signature_offices  so ON rs.office_id = so.id
        JOIN   document_requests  dr ON rs.request_id = dr.id
        JOIN   users              u  ON u.id = ?
        WHERE  rs.id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $signerUserId, $sigRowId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return ['success'=>false,'error'=>'Record not found.'];
    if ($row['req_status'] !== 'for_signature') return ['success'=>false,'error'=>'Not in signature stage.'];
    if ((int)$row['signer_office_id'] !== (int)$row['office_id']) return ['success'=>false,'error'=>'Not your office.'];

    $upd = $conn->prepare("UPDATE request_signatures SET status='rejected',signed_by=?,signed_at=NOW(),remarks=? WHERE id=?");
    $upd->bind_param("isi",$signerUserId,$reason,$sigRowId);
    $upd->execute(); $upd->close();

    $note = "[{$row['office_name']}] Rejected: {$reason}";
    $cancel = $conn->prepare("UPDATE document_requests SET status='cancelled',remarks=?,updated_at=NOW() WHERE id=?");
    $cancel->bind_param("si",$note,$row['request_id']);
    $cancel->execute(); $cancel->close();

    $log = $conn->prepare("INSERT INTO request_logs (request_id,changed_by,old_status,new_status,notes) VALUES (?,?,'for_signature','cancelled',?)");
    $log->bind_param("iis",$row['request_id'],$signerUserId,$note);
    $log->execute(); $log->close();

    $msg = "Your request {$row['request_code']} was rejected by {$row['office_name']}. Reason: {$reason}. Please contact the Registrar.";
    $notify = $conn->prepare("INSERT INTO notifications (user_id,message) VALUES (?,?)");
    $notify->bind_param("is",$row['user_id'],$msg);
    $notify->execute(); $notify->close();

    return ['success'=>true,'error'=>null];
}

/** Counts for the signatory dashboard header stats. */
function getSignatoryStats(mysqli $conn, int $officeId): array
{
    $stmt = $conn->prepare("
        SELECT
            SUM(rs.status='pending' AND dr.status='for_signature') AS pending_cnt,
            SUM(rs.status='signed')   AS signed_cnt,
            SUM(rs.status='rejected') AS rejected_cnt
        FROM   request_signatures rs
        JOIN   document_requests  dr ON rs.request_id = dr.id
        WHERE  rs.office_id = ?
    ");
    $stmt->bind_param("i",$officeId);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return [
        'pending'  => (int)($s['pending_cnt']  ?? 0),
        'signed'   => (int)($s['signed_cnt']   ?? 0),
        'rejected' => (int)($s['rejected_cnt'] ?? 0),
    ];
}