<?php
// includes/request_helper.php
// Shared helper functions for document request workflow

// ── Valid status transitions ─────────────────────────────────
const STATUS_FLOW = [
    'pending'    => ['approved', 'cancelled'],
    'approved'   => ['processing', 'cancelled'],
    'processing' => ['ready', 'cancelled'],
    'ready'      => ['paid', 'cancelled'],
    'paid'       => ['released'],
    'released'   => [],
    'cancelled'  => [],
];

// ── Log a request action ─────────────────────────────────────
function logRequestAction($conn, $requestId, $changedBy, $oldStatus, $newStatus, $notes = '') {
    $stmt = $conn->prepare("
        INSERT INTO request_logs
            (request_id, changed_by, old_status, new_status, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iisss", $requestId, $changedBy, $oldStatus, $newStatus, $notes);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// ── Validate and update request status ──────────────────────
function updateRequestStatus($conn, $requestId, $newStatus, $changedBy, $notes = '') {
    // Get current status
    $stmt = $conn->prepare("SELECT status FROM document_requests WHERE id = ?");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return ['success' => false, 'message' => 'Request not found.'];

    $oldStatus = $row['status'];
    $allowed   = STATUS_FLOW[$oldStatus] ?? [];

    if (!in_array($newStatus, $allowed)) {
        return [
            'success' => false,
            'message' => "Cannot change status from '$oldStatus' to '$newStatus'."
        ];
    }

    // Update status
    $stmt = $conn->prepare("
        UPDATE document_requests SET status = ?, updated_at = NOW() WHERE id = ?
    ");
    $stmt->bind_param("si", $newStatus, $requestId);
    $stmt->execute();
    $stmt->close();

    // Log the action
    logRequestAction($conn, $requestId, $changedBy, $oldStatus, $newStatus, $notes);

    // Send notification to user
    $reqStmt = $conn->prepare("SELECT user_id FROM document_requests WHERE id = ?");
    $reqStmt->bind_param("i", $requestId);
    $reqStmt->execute();
    $reqRow = $reqStmt->get_result()->fetch_assoc();
    $reqStmt->close();

    if ($reqRow) {
        sendNotification($conn, $reqRow['user_id'], buildNotificationMessage($newStatus, $requestId));
    }

    return ['success' => true, 'message' => "Status updated to '$newStatus'."];
}

// ── Build notification message ───────────────────────────────
function buildNotificationMessage($status, $requestId) {
    $messages = [
        'approved'   => "Your document request #$requestId has been approved and is now being processed.",
        'processing' => "Your document request #$requestId is now being prepared.",
        'ready'      => "Your document request #$requestId is ready for pickup. Please proceed to the Registrar's Office to pay and claim your document.",
        'paid'       => "Payment for request #$requestId has been recorded. Your document will be released shortly.",
        'released'   => "Your document request #$requestId has been released. Thank you!",
        'cancelled'  => "Your document request #$requestId has been cancelled. Please contact the Registrar for more details.",
    ];
    return $messages[$status] ?? "Your document request #$requestId status has been updated to: $status.";
}

// ── Send notification ────────────────────────────────────────
function sendNotification($conn, $userId, $message) {
    $stmt = $conn->prepare("
        INSERT INTO notifications (user_id, message) VALUES (?, ?)
    ");
    $stmt->bind_param("is", $userId, $message);
    $stmt->execute();
    $stmt->close();
}

// ── Generate claim stub ──────────────────────────────────────
function generateClaimStub($conn, $requestId, $userId, $totalFee) {
    // Check if stub already exists
    $check = $conn->prepare("SELECT id, stub_code FROM claim_stubs WHERE request_id = ?");
    $check->bind_param("i", $requestId);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) return true; // Already generated

    // Generate stub code
    $stubCode = 'STUB-' . strtoupper(substr(md5($requestId . $userId . time()), 0, 10));

    // Build QR data payload (JSON string)
    $qrData = json_encode([
        'stub'    => $stubCode,
        'req'     => $requestId,
        'fee'     => number_format($totalFee, 2),
        'system'  => 'DocuGo-ADFC',
    ]);

    $stmt = $conn->prepare("
        INSERT INTO claim_stubs (request_id, user_id, stub_code, qr_code_data, total_fee)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iissd", $requestId, $userId, $stubCode, $qrData, $totalFee);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// ── Process Pay & Release ────────────────────────────────────
function processPayAndRelease($conn, $requestId, $staffId, $receiptNumber, $notes = '') {
    // 1. Validate request is in 'ready' status
    $stmt = $conn->prepare("
        SELECT dr.*, dt.fee, dt.name as doc_type, dr.copies
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        WHERE dr.id = ?
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        return ['success' => false, 'message' => 'Request not found.'];
    }

    if ($request['status'] !== 'ready') {
        return ['success' => false, 'message' => 'Only requests with status READY can be paid and released.'];
    }

    if ($request['payment_status'] === 'paid') {
        return ['success' => false, 'message' => 'This request has already been paid.'];
    }

    $amount = $request['fee'] * $request['copies'];
    $now    = date('Y-m-d H:i:s');

    // 2. Insert payment record
    $payStmt = $conn->prepare("
        INSERT INTO payment_records
            (request_id, user_id, amount, official_receipt_number, payment_date, processed_by, status)
        VALUES (?, ?, ?, ?, ?, ?, 'paid')
    ");
    $payStmt->bind_param(
        "iidssi",
        $requestId,
        $request['user_id'],
        $amount,
        $receiptNumber,
        $now,
        $staffId
    );
    if (!$payStmt->execute()) {
        $payStmt->close();
        return ['success' => false, 'message' => 'Failed to record payment.'];
    }
    $paymentId = $conn->insert_id;
    $payStmt->close();

    // 3. Update document_requests: payment_status + status
    $updStmt = $conn->prepare("
        UPDATE document_requests
        SET payment_status = 'paid',
            status = 'released',
            updated_at = NOW()
        WHERE id = ?
    ");
    $updStmt->bind_param("i", $requestId);
    $updStmt->execute();
    $updStmt->close();

    // 4. Insert/update release_schedules
    $relStmt = $conn->prepare("
        INSERT INTO release_schedules (request_id, released_by, released_at, notes)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            released_by = VALUES(released_by),
            released_at = VALUES(released_at),
            notes = VALUES(notes)
    ");
    $relStmt->bind_param("iiss", $requestId, $staffId, $now, $notes);
    $relStmt->execute();
    $relStmt->close();

    // 5. Log the action
    logRequestAction(
        $conn, $requestId, $staffId,
        'ready', 'released',
        "Payment recorded. OR#: $receiptNumber. $notes"
    );

    // 6. Notify student
    sendNotification(
        $conn,
        $request['user_id'],
        "✅ Your document request #{$requestId} has been paid (OR#: {$receiptNumber}) and released. Thank you!"
    );

    // 7. Log payment audit
    $auditStmt = $conn->prepare("
        INSERT INTO payment_audit_logs
            (request_id, payment_id, action, performed_by, old_value, new_value, notes)
        VALUES (?, ?, 'payment_recorded', ?, 'unpaid', 'paid', ?)
    ");
    $auditStmt->bind_param("iiis", $requestId, $paymentId, $staffId, $notes);
    $auditStmt->execute();
    $auditStmt->close();

    return [
        'success'    => true,
        'message'    => 'Payment recorded and document released successfully.',
        'payment_id' => $paymentId,
        'amount'     => $amount,
    ];
}

// ── Get status badge HTML ────────────────────────────────────
function statusBadge($status) {
    $badges = [
        'pending'    => ['bg' => '#fef3c7', 'color' => '#92400e', 'label' => 'Pending'],
        'approved'   => ['bg' => '#dbeafe', 'color' => '#1e40af', 'label' => 'Approved'],
        'processing' => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'label' => 'Processing'],
        'ready'      => ['bg' => '#fef9c3', 'color' => '#854d0e', 'label' => '⚠ Ready (Unpaid)'],
        'paid'       => ['bg' => '#dcfce7', 'color' => '#166534', 'label' => '✓ Paid'],
        'released'   => ['bg' => '#ede9fe', 'color' => '#4c1d95', 'label' => '📦 Released'],
        'cancelled'  => ['bg' => '#fee2e2', 'color' => '#991b1b', 'label' => '✕ Cancelled'],
    ];
    $b = $badges[$status] ?? ['bg' => '#f3f4f6', 'color' => '#374151', 'label' => ucfirst($status)];
    return "<span style='background:{$b['bg']};color:{$b['color']};padding:3px 10px;border-radius:10px;font-size:0.72rem;font-weight:700;'>{$b['label']}</span>";
}

// ── Get payment status badge ─────────────────────────────────
function paymentBadge($status) {
    if ($status === 'paid') {
        return "<span style='background:#dcfce7;color:#166534;padding:2px 8px;border-radius:8px;font-size:0.72rem;font-weight:700;'>✓ Paid</span>";
    }
    return "<span style='background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:8px;font-size:0.72rem;font-weight:700;'>✕ Unpaid</span>";
}