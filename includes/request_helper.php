<?php
// includes/request_helper.php
// Shared helper functions for document request workflow

// ── Valid status transitions ─────────────────────────────────
// Strict state machine: only allow valid workflow transitions
$allowedTransitions = [
    'pending' => ['approved'],
    'approved' => ['for_signature','processing'],
    'for_signature' => ['processing'],
    'processing' => ['ready'],
    'ready' => ['paid'],
    'paid' => ['released'],
];

// Add cancelled as valid from any state (emergency abort)
foreach ($allowedTransitions as $state => $transitions) {
    $allowedTransitions[$state][] = 'cancelled';
}
unset($allowedTransitions['cancelled']);

function isValidStatusTransition($currentStatus, $newStatus) {
    global $allowedTransitions;
    if (!isset($allowedTransitions[$currentStatus])) {
        return false;
    }
    return in_array($newStatus, $allowedTransitions[$currentStatus]);
}

// Legacy constant for backward compatibility
const STATUS_FLOW = [
    'pending'    => ['approved', 'cancelled'],
    'approved'   => ['for_signature', 'processing', 'cancelled'],
    'for_signature' => ['processing', 'cancelled'],
    'processing' => ['ready', 'cancelled'],
    'ready'      => ['paid', 'cancelled'],
    'paid'       => ['released', 'cancelled'],
    'released'   => ['cancelled'],
    'cancelled'  => [],
];

// Helper function to validate database connection
function validateConnection($conn) {
    if (!$conn) {
        throw new Exception('Database connection not established');
    }
    if ($conn->connect_error) {
        throw new Exception('Database connection error: ' . $conn->connect_error);
    }
    return true;
}

// ── Log a request action ─────────────────────────────────────
function logRequestAction($conn, $requestId, $changedBy, $oldStatus, $newStatus, $notes = '') {
    try {
        validateConnection($conn);
        
        $stmt = $conn->prepare("
            INSERT INTO request_logs
                (request_id, changed_by, old_status, new_status, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            error_log("Failed to prepare statement in logRequestAction: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("iisss", $requestId, $changedBy, $oldStatus, $newStatus, $notes);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Error in logRequestAction: " . $e->getMessage());
        return false;
    }
}

// ── Validate and update request status ──────────────────────
function updateRequestStatus($conn, $requestId, $newStatus, $changedBy, $notes = '') {
    try {
        validateConnection($conn);
        
        // Get current status
        $stmt = $conn->prepare("SELECT status FROM document_requests WHERE id = ?");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error: ' . $conn->error];
        }
        
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

        // Calculate estimated release date when approved or processing
        $estimatedDate = null;
        if (in_array($newStatus, ['approved', 'processing'])) {
            $procStmt = $conn->prepare("
                SELECT dt.processing_days 
                FROM document_requests dr 
                JOIN document_types dt ON dr.document_type_id = dt.id 
                WHERE dr.id = ?
            ");
            if ($procStmt) {
                $procStmt->bind_param("i", $requestId);
                $procStmt->execute();
                $procRow = $procStmt->get_result()->fetch_assoc();
                $procStmt->close();
                
                if ($procRow && isset($procRow['processing_days'])) {
                    $days = intval($procRow['processing_days']);
                    $estimatedDate = date('Y-m-d', strtotime("+$days days"));
                }
            }
        }

        // Update status with all calculated fields
        if ($newStatus === 'approved') {
            if ($estimatedDate) {
                $sql = "UPDATE document_requests SET status = ?, estimated_release_date = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssi", $newStatus, $estimatedDate, $requestId);
            } else {
                $sql = "UPDATE document_requests SET status = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $newStatus, $requestId);
            }
        } elseif ($newStatus === 'processing') {
            if ($estimatedDate) {
                $sql = "UPDATE document_requests SET status = ?, estimated_release_date = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssi", $newStatus, $estimatedDate, $requestId);
            } else {
                $sql = "UPDATE document_requests SET status = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $newStatus, $requestId);
            }
        } elseif ($newStatus === 'paid') {
            $sql = "UPDATE document_requests SET status = ?, paid_at = NOW(), updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $newStatus, $requestId);
        } elseif ($newStatus === 'released') {
            $sql = "UPDATE document_requests SET status = ?, released_at = NOW(), updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $newStatus, $requestId);
        } else {
            $sql = "UPDATE document_requests SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $newStatus, $requestId);
        }
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'Failed to prepare update statement: ' . $conn->error];
        }
        
        $stmt->execute();
        $stmt->close();

        // Log the action
        logRequestAction($conn, $requestId, $changedBy, $oldStatus, $newStatus, $notes);

        // Send notification to user
        $reqStmt = $conn->prepare("SELECT user_id FROM document_requests WHERE id = ?");
        if ($reqStmt) {
            $reqStmt->bind_param("i", $requestId);
            $reqStmt->execute();
            $reqRow = $reqStmt->get_result()->fetch_assoc();
            $reqStmt->close();

            if ($reqRow) {
                sendNotification(
                    $conn,
                    $reqRow['user_id'],
                    buildNotificationMessage($newStatus, $requestId),
                    $requestId
                );
            }
        }

        return ['success' => true, 'message' => "Status updated to '$newStatus'."];
    } catch (Exception $e) {
        error_log("Error in updateRequestStatus: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred while updating status: ' . $e->getMessage()];
    }
}

// ── Build notification message ───────────────────────────────
function buildNotificationMessage($status, $requestId) {
    $messages = [
        'approved'   => "Your document request #$requestId has been approved and is now being processed.",
        'for_signature' => "Your document request #$requestId requires signature/approval. Please wait for authorization.",
        'processing' => "Your document request #$requestId is now being prepared.",
        'ready'      => "Your document request #$requestId is ready for pickup. Please proceed to the Registrar's Office to pay and claim your document.",
        'paid'       => "Payment for request #$requestId has been recorded. Your document will be released shortly.",
        'released'   => "Your document request #$requestId has been released. Thank you!",
        'cancelled'  => "Your document request #$requestId has been cancelled. Please contact the Registrar for more details.",
    ];
    return $messages[$status] ?? "Your document request #$requestId status has been updated to: $status.";
}

// ── Send notification ────────────────────────────────────────
function sendNotification($conn, $userId, $message, $requestId = null) {
    try {
        validateConnection($conn);
        
        // Insert notification into database
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, message) VALUES (?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("is", $userId, $message);
            $stmt->execute();
            $stmt->close();
        }
        
        // Send email notification for specific statuses
        if ($requestId !== null) {
            // Get request details
            $reqStmt = $conn->prepare("
                SELECT dr.*, u.email, u.first_name, u.last_name, dt.name as doc_type
                FROM document_requests dr
                JOIN users u ON dr.user_id = u.id
                JOIN document_types dt ON dr.document_type_id = dt.id
                WHERE dr.id = ?
            ");
            if ($reqStmt) {
                $reqStmt->bind_param("i", $requestId);
                $reqStmt->execute();
                $request = $reqStmt->get_result()->fetch_assoc();
                $reqStmt->close();
                
                if ($request) {
                    // Send email for ready (unpaid) and released statuses
                    $status = $request['status'];
                    if (in_array($status, ['ready', 'released'])) {
                        sendEmailNotification($request, $message);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error in sendNotification: " . $e->getMessage());
    }
}

// ── Send email notification ─────────────────────────────────
function sendEmailNotification($request, $message) {
    // Define SITE_URL if not already defined
    if (!defined('SITE_URL')) {
        define('SITE_URL', 'http://localhost/docugo');
    }
    
    // Import mailer functions
    $mailerPath = __DIR__ . '/mailer.php';
    if (file_exists($mailerPath)) {
        require_once $mailerPath;
    } else {
        error_log("Mailer file not found at: " . $mailerPath);
        return false;
    }
    
    $subject = "DocuGo Notification: Request #{$request['request_code']}";
    
    // Customize message based on status
    switch ($request['status']) {
        case 'ready':
            $stubCode = $request['stub_code'] ?? '';
            $amountDue = number_format($request['fee'] * $request['copies'], 2);
            $body = "
            <div style='font-family:Arial;background:#f0f4f8;padding:20px;'>
                <div style='max-width:520px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;'>
                    <div style='background:#1a56db;padding:20px;color:white;'>
                        <h2 style='margin:0;'>DocuGo</h2>
                    </div>
                    <div style='padding:20px;'>
                        <h3>Hi {$request['first_name']} 👋</h3>
                        <p>Your document request #{$request['request_code']} is ready for pickup.</p>
                        <p><strong>Document:</strong> {$request['doc_type']}</p>
                        <p><strong>Amount Due:</strong> ₱{$amountDue}</p>
                        <p>Please proceed to the Registrar's Office to pay and claim your document.</p>
                        <div style='text-align:center;margin:20px 0;'>
                            <a href='" . SITE_URL . "/student/claim_stub.php?code=" . $stubCode . "' 
                               style='background:#1a56db;color:#fff;padding:12px 20px;
                                      text-decoration:none;border-radius:6px;'>
                                View Claim Stub
                            </a>
                        </div>
                        <p style='font-size:12px;color:#666;'>
                            Keep this notification for your reference.
                        </p>
                    </div>
                </div>
            </div>";
            break;
        case 'released':
            $stubCode = $request['stub_code'] ?? '';
            $body = "
            <div style='font-family:Arial;background:#f0f4f8;padding:20px;'>
                <div style='max-width:520px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;'>
                    <div style='background:#1a56db;padding:20px;color:white;'>
                        <h2 style='margin:0;'>DocuGo</h2>
                    </div>
                    <div style='padding:20px;'>
                        <h3>Hi {$request['first_name']} 👋</h3>
                        <p>Your document request #{$request['request_code']} has been released!</p>
                        <p><strong>Document:</strong> {$request['doc_type']}</p>
                        <p><strong>Status:</strong> Successfully claimed</p>
                        <div style='text-align:center;margin:20px 0;'>
                            <a href='" . SITE_URL . "/student/claim_stub.php?code=" . $stubCode . "' 
                               style='background:#1a56db;color:#fff;padding:12px 20px;
                                      text-decoration:none;border-radius:6px;'>
                                View Claim Stub
                            </a>
                        </div>
                        <p style='font-size:12px;color:#666;'>
                            Thank you for using DocuGo.
                        </p>
                    </div>
                </div>
            </div>";
            break;
        default:
            $body = "
            <div style='font-family:Arial;background:#f0f4f8;padding:20px;'>
                <div style='max-width:520px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;'>
                    <div style='background:#1a56db;padding:20px;color:white;'>
                        <h2 style='margin:0;'>DocuGo</h2>
                    </div>
                    <div style='padding:20px;'>
                        <h3>Hi {$request['first_name']} 👋</h3>
                        <p>" . nl2br($message) . "</p>
                        <p style='font-size:12px;color:#666;'>
                            Keep this notification for your reference.
                        </p>
                    </div>
                </div>
            </div>";
            break;
    }
    
    // Send email
    if (function_exists('sendWithPHPMailer')) {
        return sendWithPHPMailer($request['email'], $request['first_name'], $subject, $body);
    } else {
        error_log("sendWithPHPMailer function not found");
        return false;
    }
}

// ── Generate claim stub ──────────────────────────────────────
function generateClaimStub($conn, $requestId, $userId, $totalFee) {
    try {
        validateConnection($conn);
        
        // Check if stub already exists
        $check = $conn->prepare("SELECT id, stub_code FROM claim_stubs WHERE request_id = ?");
        if (!$check) {
            error_log("Failed to prepare check statement: " . $conn->error);
            return false;
        }
        
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
        
        if (!$stmt) {
            error_log("Failed to prepare insert statement: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("iissd", $requestId, $userId, $stubCode, $qrData, $totalFee);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Error in generateClaimStub: " . $e->getMessage());
        return false;
    }
}

// ── Process Pay & Release ────────────────────────────────────
function processPayAndRelease($conn, $requestId, $staffId, $receiptNumber, $notes = '') {
    try {
        validateConnection($conn);
        
        // 1. Validate request is in 'ready' status
        $stmt = $conn->prepare("
            SELECT dr.*, dt.fee, dt.name as doc_type, dr.copies
            FROM document_requests dr
            JOIN document_types dt ON dr.document_type_id = dt.id
            WHERE dr.id = ?
        ");
        
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error: ' . $conn->error];
        }
        
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

        if ($request['status'] === 'paid' || $request['status'] === 'released') {
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
        
        if (!$payStmt) {
            return ['success' => false, 'message' => 'Failed to prepare payment statement: ' . $conn->error];
        }
        
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
            return ['success' => false, 'message' => 'Failed to record payment: ' . $payStmt->error];
        }
        $paymentId = $conn->insert_id;
        $payStmt->close();

        // 3. Update document_requests: status from ready -> paid
        $updStmt = $conn->prepare("
            UPDATE document_requests
            SET status = 'paid',
                paid_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        
        if (!$updStmt) {
            return ['success' => false, 'message' => 'Failed to prepare update statement: ' . $conn->error];
        }
        
        $updStmt->bind_param("i", $requestId);
        $updStmt->execute();
        $updStmt->close();

        // Generate claim stub when status becomes paid
        generateClaimStub($conn, $requestId, $request['user_id'], $amount);

        // 4. Insert/update release_schedules
        $relStmt = $conn->prepare("
            INSERT INTO release_schedules (request_id, released_by, released_at, notes)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                released_by = VALUES(released_by),
                released_at = VALUES(released_at),
                notes = VALUES(notes)
        ");
        
        if ($relStmt) {
            $relStmt->bind_param("iiss", $requestId, $staffId, $now, $notes);
            $relStmt->execute();
            $relStmt->close();
        }

        // 5. Log the action for payment
        logRequestAction(
            $conn, $requestId, $staffId,
            'ready', 'paid',
            "Payment recorded. OR#: $receiptNumber. $notes"
        );

        // 6. Now update status from paid to released (auto-release after payment)
        $updStmt2 = $conn->prepare("
            UPDATE document_requests
            SET status = 'released',
                released_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        
        if (!$updStmt2) {
            return ['success' => false, 'message' => 'Failed to prepare release statement: ' . $conn->error];
        }
        
        $updStmt2->bind_param("i", $requestId);
        $updStmt2->execute();
        $updStmt2->close();

        // 7. Log the action for release
        logRequestAction(
            $conn, $requestId, $staffId,
            'paid', 'released',
            "Document released after payment. OR#: $receiptNumber. $notes"
        );

        // 8. Notify student
        sendNotification(
            $conn,
            $request['user_id'],
            "✅ Your document request #{$requestId} has been paid (OR#: {$receiptNumber}) and released. Thank you!"
        );

        // 9. Log payment audit
        $auditStmt = $conn->prepare("
            INSERT INTO payment_audit_logs
                (request_id, payment_id, action, performed_by, old_value, new_value, notes)
            VALUES (?, ?, 'payment_recorded', ?, 'unpaid', 'paid', ?)
        ");
        
        if ($auditStmt) {
            $auditStmt->bind_param("iiis", $requestId, $paymentId, $staffId, $notes);
            $auditStmt->execute();
            $auditStmt->close();
        }

        return [
            'success'    => true,
            'message'    => 'Payment recorded and document released successfully.',
            'payment_id' => $paymentId,
            'amount'     => $amount,
        ];
    } catch (Exception $e) {
        error_log("Error in processPayAndRelease: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()];
    }
}

// ── Get status badge HTML ────────────────────────────────────
function statusBadge($status) {
    $badges = [
        'pending'    => ['bg' => '#fef3c7', 'color' => '#92400e', 'label' => 'Pending'],
        'approved'   => ['bg' => '#dbeafe', 'color' => '#1e40af', 'label' => 'Approved'],
        'for_signature' => ['bg' => '#fffbeb', 'color' => '#d97706', 'label' => '✍ For Signature'],
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
    if ($status === 'paid' || $status === 'released') {
        return "<span style='background:#dcfce7;color:#166534;padding:2px 8px;border-radius:8px;font-size:0.72rem;font-weight:700;'>✓ Paid</span>";
    } elseif ($status === 'ready') {
        return "<span style='background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:8px;font-size:0.72rem;font-weight:700;'>⚠ Unpaid</span>";
    }
    return "<span style='background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:8px;font-size:0.72rem;font-weight:700;'>—</span>";
}
?>