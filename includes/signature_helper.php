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
 * UPDATE REQUEST STATUS
 * ============================================================
 */
function updateRequestStatus(
    mysqli $conn,
    int $requestId,
    string $newStatus,
    int $adminId,
    string $notes = ''
): array {

    // ========================================================
    // FETCH REQUEST
    // ========================================================

    $stmt = $conn->prepare("
        SELECT
            dr.*,
            dt.requires_signature,
            dt.processing_days,
            dt.name AS document_name,
            dt.fee,
            u.email,
            CONCAT(u.first_name, ' ', u.last_name) AS student_name
        FROM document_requests dr
        JOIN document_types dt
            ON dt.id = dr.document_type_id
        JOIN users u
            ON u.id = dr.user_id
        WHERE dr.id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $requestId);

    $stmt->execute();

    $request = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$request) {

        return [
            'success' => false,
            'error' => 'Request not found.'
        ];
    }

    $oldStatus = $request['status'];

    $requiresSig = (bool)$request['requires_signature'];

    // ========================================================
    // VALIDATE FLOW
    // ========================================================

    if (!isValidTransition(
        $oldStatus,
        $newStatus,
        $requiresSig
    )) {

        return [
            'success' => false,
            'error' => "Invalid status transition."
        ];
    }

    // ========================================================
    // CREATE SIGNATURE ROUTES
    // ========================================================

    if (
        $newStatus === 'for_signature'
        && $requiresSig
    ) {

        createSignatureRoutes(
            $conn,
            $requestId
        );
    }

    // ========================================================
    // CALCULATE ESTIMATED RELEASE
    // ========================================================

    $estimatedRelease = null;

    if (
        in_array(
            $newStatus,
            ['approved', 'processing']
        )
    ) {

        $days = (int)$request['processing_days'];

        $estimatedRelease = date(
            'Y-m-d',
            strtotime("+{$days} days")
        );
    }

    // ========================================================
    // UPDATE REQUEST
    // ========================================================

    if ($estimatedRelease) {

        $update = $conn->prepare("
            UPDATE document_requests
            SET status = ?,
                remarks = ?,
                estimated_release_date = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $update->bind_param(
            "sssi",
            $newStatus,
            $notes,
            $estimatedRelease,
            $requestId
        );

    } else {

        $update = $conn->prepare("
            UPDATE document_requests
            SET status = ?,
                remarks = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $update->bind_param(
            "ssi",
            $newStatus,
            $notes,
            $requestId
        );
    }

    $success = $update->execute();

    $update->close();

    if (!$success) {

        return [
            'success' => false,
            'error' => 'Failed to update request.'
        ];
    }

    // ========================================================
    // LOG
    // ========================================================

    logStatusChange(
        $conn,
        $requestId,
        $adminId,
        $oldStatus,
        $newStatus,
        $notes
    );

    // ========================================================
    // NOTIFICATION
    // ========================================================

    $message = buildStatusMessage(
        $newStatus,
        $request['request_code'],
        $request['document_name']
    );

    sendNotification(
        $conn,
        $request['user_id'],
        $message,
        $requestId
    );

    return [
        'success' => true,
        'error' => null
    ];
}

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
 * STATUS BADGE
 * ============================================================
 */
function statusBadge(string $status): string
{
    $map = [

        'pending' =>
            ['#fef3c7', '#92400e', 'Pending'],

        'for_signature' =>
            ['#ede9fe', '#5b21b6', 'For Signature'],

        'approved' =>
            ['#dbeafe', '#1d4ed8', 'Approved'],

        'processing' =>
            ['#cffafe', '#155e75', 'Processing'],

        'ready' =>
            ['#dcfce7', '#166534', 'Ready'],

        'paid' =>
            ['#bbf7d0', '#166534', 'Paid'],

        'released' =>
            ['#e0e7ff', '#3730a3', 'Released'],

        'cancelled' =>
            ['#fee2e2', '#991b1b', 'Cancelled']
    ];

    [$bg, $color, $label] = $map[$status]
        ?? ['#f3f4f6', '#374151', ucfirst($status)];

    return "
        <span style='
            background: {$bg};
            color: {$color};
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        '>
            {$label}
        </span>
    ";
}