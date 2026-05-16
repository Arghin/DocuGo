<?php
// ============================================================
// includes/signature_helper.php
// REAL multi-office signature workflow helper
// DocuGo - ADFC
// ============================================================

if (!defined('SIGNATURE_HELPER_LOADED')) {
    define('SIGNATURE_HELPER_LOADED', true);
}

/**
 * ============================================================
 * STATUS FLOW
 * ============================================================
 *
 * pending
 *   -> for_signature (if requires_signature = 1)
 *   -> approved      (if no signature needed)
 *
 * for_signature
 *   -> processing    (ONLY if all offices signed)
 *
 * processing
 *   -> ready
 *
 * ready
 *   -> paid
 *
 * paid
 *   -> released
 *
 * any
 *   -> cancelled
 *
 * ============================================================
 */

function getAllowedTransitions(string $currentStatus, bool $requiresSig): array
{
    $transitions = [

        'pending' => $requiresSig
            ? ['for_signature', 'cancelled']
            : ['approved', 'cancelled'],

        'for_signature' => [
            'processing',
            'cancelled'
        ],

        'approved' => [
            'processing',
            'cancelled'
        ],

        'processing' => [
            'ready',
            'cancelled'
        ],

        'ready' => [
            'paid',
            'cancelled'
        ],

        'paid' => [
            'released'
        ],

        'released' => [],

        'cancelled' => []
    ];

    return [
        'allowed' => $transitions[$currentStatus] ?? [],
        'next'    => $transitions[$currentStatus][0] ?? null
    ];
}

/**
 * ============================================================
 * VALIDATE TRANSITION
 * ============================================================
 */
function isValidTransition(
    string $from,
    string $to,
    bool $requiresSig
): bool {

    $flow = getAllowedTransitions($from, $requiresSig);

    return in_array($to, $flow['allowed'], true);
}

/**
 * ============================================================
 * CREATE SIGNATURE ROUTES
 * ============================================================
 * Auto-create office signature rows
 * when request enters for_signature
 * ============================================================
 */
function createSignatureRoutes(mysqli $conn, int $requestId): bool
{
    // Prevent duplicate creation
    $check = $conn->prepare("
        SELECT id
        FROM request_signatures
        WHERE request_id = ?
        LIMIT 1
    ");

    $check->bind_param("i", $requestId);
    $check->execute();

    $exists = $check->get_result()->fetch_assoc();

    $check->close();

    if ($exists) {
        return true;
    }

    // Fetch all offices
    $officeQuery = $conn->query("
        SELECT id
        FROM signature_offices
        ORDER BY id ASC
    ");

    if (!$officeQuery || $officeQuery->num_rows === 0) {
        return false;
    }

    $insert = $conn->prepare("
        INSERT INTO request_signatures
        (
            request_id,
            office_id,
            status
        )
        VALUES (?, ?, 'pending')
    ");

    while ($office = $officeQuery->fetch_assoc()) {

        $officeId = (int)$office['id'];

        $insert->bind_param(
            "ii",
            $requestId,
            $officeId
        );

        $insert->execute();
    }

    $insert->close();

    return true;
}

/**
 * ============================================================
 * CHECK IF ALL OFFICES SIGNED
 * ============================================================
 */
function areAllSignaturesCompleted(
    mysqli $conn,
    int $requestId
): bool {

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_pending
        FROM request_signatures
        WHERE request_id = ?
        AND status != 'signed'
    ");

    $stmt->bind_param("i", $requestId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    return ((int)$row['total_pending']) === 0;
}

/**
 * ============================================================
 * SIGN OFFICE
 * ============================================================
 */
function signOffice(
    mysqli $conn,
    int $requestId,
    int $officeId,
    int $staffId,
    string $remarks = ''
): array {

    // Verify record exists
    $check = $conn->prepare("
        SELECT rs.id,
               rs.status,
               dr.status AS request_status
        FROM request_signatures rs
        JOIN document_requests dr
            ON dr.id = rs.request_id
        WHERE rs.request_id = ?
        AND rs.office_id = ?
        LIMIT 1
    ");

    $check->bind_param(
        "ii",
        $requestId,
        $officeId
    );

    $check->execute();

    $row = $check->get_result()->fetch_assoc();

    $check->close();

    if (!$row) {
        return [
            'success' => false,
            'error' => 'Signature route not found.'
        ];
    }

    if ($row['status'] === 'signed') {
        return [
            'success' => false,
            'error' => 'Office already signed.'
        ];
    }

    // Sign office
    $update = $conn->prepare("
        UPDATE request_signatures
        SET status = 'signed',
            signed_by = ?,
            signed_at = NOW(),
            remarks = ?
        WHERE request_id = ?
        AND office_id = ?
    ");

    $update->bind_param(
        "isii",
        $staffId,
        $remarks,
        $requestId,
        $officeId
    );

    $update->execute();

    $update->close();

    // Check if ALL signed
    if (areAllSignaturesCompleted($conn, $requestId)) {

        // Auto move to processing
        $conn->query("
            UPDATE document_requests
            SET status = 'processing',
                updated_at = NOW()
            WHERE id = {$requestId}
        ");

        logStatusChange(
            $conn,
            $requestId,
            $staffId,
            'for_signature',
            'processing',
            'All required offices signed.'
        );

        return [
            'success' => true,
            'completed' => true,
            'message' => 'All offices signed. Request moved to processing.'
        ];
    }

    return [
        'success' => true,
        'completed' => false,
        'message' => 'Office signature completed.'
    ];
}

/**
 * ============================================================
 * NOTE: updateRequestStatus() function is NOT defined here
 * ============================================================
 * This function is defined in request_helper.php
 * Do NOT duplicate it here to avoid redeclaration errors
 * ============================================================
 */

/**
 * ============================================================
 * NOTE: statusBadge() function is NOT defined here
 * ============================================================
 * This function is defined in request_helper.php
 * Do NOT duplicate it here to avoid redeclaration errors
 * ============================================================
 */

/**
 * ============================================================
 * LOG STATUS CHANGE
 * ============================================================
 */
function logStatusChange(
    mysqli $conn,
    int $requestId,
    int $changedBy,
    string $oldStatus,
    string $newStatus,
    string $notes = ''
): void {

    $stmt = $conn->prepare("
        INSERT INTO request_logs
        (
            request_id,
            changed_by,
            old_status,
            new_status,
            notes
        )
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "iisss",
        $requestId,
        $changedBy,
        $oldStatus,
        $newStatus,
        $notes
    );

    $stmt->execute();

    $stmt->close();
}

/**
 * ============================================================
 * STATUS MESSAGE
 * ============================================================
 */
function buildStatusMessage(
    string $status,
    string $code,
    string $docName
): string {

    $messages = [

        'for_signature' =>
            "Your request {$code} ({$docName}) is now routing for signatures.",

        'processing' =>
            "All required offices approved your {$docName}. Your request is now processing.",

        'ready' =>
            "Your {$docName} is READY for pickup.",

        'paid' =>
            "Payment recorded for request {$code}.",

        'released' =>
            "Your document {$docName} has been released.",

        'cancelled' =>
            "Your request {$code} has been cancelled."
    ];

    return $messages[$status]
        ?? "Request {$code} updated.";
}

/**
 * ============================================================
 * GET SIGNATURE PROGRESS
 * ============================================================
 */
function getSignatureProgress(mysqli $conn, int $requestId): array
{
    $stmt = $conn->prepare("
        SELECT 
            so.office_name,
            so.office_code,
            rs.status,
            rs.signed_at,
            CONCAT(u.first_name, ' ', u.last_name) as signed_by_name,
            rs.remarks
        FROM request_signatures rs
        JOIN signature_offices so ON so.id = rs.office_id
        LEFT JOIN users u ON u.id = rs.signed_by
        WHERE rs.request_id = ?
        ORDER BY so.id ASC
    ");

    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $signatures = [];
    $signedCount = 0;
    $totalCount = 0;
    
    while ($row = $result->fetch_assoc()) {
        $signatures[] = $row;
        $totalCount++;
        if ($row['status'] === 'signed') {
            $signedCount++;
        }
    }
    
    $stmt->close();
    
    return [
        'signatures' => $signatures,
        'signed_count' => $signedCount,
        'total_count' => $totalCount,
        'is_complete' => ($signedCount === $totalCount && $totalCount > 0),
        'progress_percent' => $totalCount > 0 ? round(($signedCount / $totalCount) * 100) : 0
    ];
}

/**
 * ============================================================
 * CHECK IF REQUEST NEEDS SIGNATURE
 * ============================================================
 */
function requestNeedsSignature(mysqli $conn, int $requestId): bool
{
    $stmt = $conn->prepare("
        SELECT dt.requires_signature
        FROM document_requests dr
        JOIN document_types dt ON dt.id = dr.document_type_id
        WHERE dr.id = ?
    ");
    
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $row ? (bool)$row['requires_signature'] : false;
}

/**
 * ============================================================
 * GET PENDING SIGNATURES FOR OFFICE
 * ============================================================
 */
function getPendingSignaturesForOffice(mysqli $conn, int $officeId, int $limit = 10): array
{
    $stmt = $conn->prepare("
        SELECT 
            rs.id as signature_id,
            rs.request_id,
            dr.request_code,
            dr.status as request_status,
            dt.name as document_name,
            CONCAT(u.first_name, ' ', u.last_name) as requester_name,
            u.student_id,
            rs.created_at
        FROM request_signatures rs
        JOIN document_requests dr ON dr.id = rs.request_id
        JOIN document_types dt ON dt.id = dr.document_type_id
        JOIN users u ON u.id = dr.user_id
        WHERE rs.office_id = ? 
        AND rs.status = 'pending'
        AND dr.status = 'for_signature'
        ORDER BY rs.created_at ASC
        LIMIT ?
    ");
    
    $stmt->bind_param("ii", $officeId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    
    $stmt->close();
    return $requests;
}

/**
 * ============================================================
 * GET SIGNATURE HISTORY FOR REQUEST
 * ============================================================
 */
function getSignatureHistory(mysqli $conn, int $requestId): array
{
    $stmt = $conn->prepare("
        SELECT 
            so.office_name,
            rs.status,
            rs.signed_at,
            CONCAT(u.first_name, ' ', u.last_name) as signed_by_name,
            rs.remarks,
            rs.created_at as requested_at
        FROM request_signatures rs
        JOIN signature_offices so ON so.id = rs.office_id
        LEFT JOIN users u ON u.id = rs.signed_by
        WHERE rs.request_id = ?
        ORDER BY rs.signed_at ASC, rs.created_at ASC
    ");
    
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    
    $stmt->close();
    return $history;
}
?>