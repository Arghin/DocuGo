<?php
// ============================================================
// includes/signature_helper.php
// All helper functions for the signature workflow.
// require_once this file in any admin page that handles
// request status transitions.
// ============================================================

if (!defined('SIGNATURE_HELPER_LOADED')) {
    define('SIGNATURE_HELPER_LOADED', true);
}

/**
 * Determine the NEXT valid status for a request.
 *
 * Flow:
 *   pending
 *     → for_signature  (if document requires_signature AND not yet obtained)
 *     → approved       (otherwise, or after signature obtained)
 *     → processing
 *     → ready
 *     → paid           (set by payment processing, not this helper)
 *     → released
 *   Any → cancelled
 *
 * @param  string $currentStatus   Current status of the request
 * @param  bool   $requiresSig     Whether the document type requires a signature
 * @param  bool   $sigObtained     Whether signature_obtained_at is set
 * @return array  ['allowed' => string[], 'next' => string|null]
 */
function getAllowedTransitions(string $currentStatus, bool $requiresSig, bool $sigObtained): array
{
    $transitions = [
        'pending'       => $requiresSig && !$sigObtained ? ['for_signature', 'cancelled'] : ['approved', 'cancelled'],
        'for_signature' => ['approved', 'cancelled'],
        'approved'      => ['processing', 'cancelled'],
        'processing'    => ['ready', 'cancelled'],
        'ready'         => ['paid', 'cancelled'],   // paid is set by payment flow
        'paid'          => ['released'],
        'released'      => [],
        'cancelled'     => [],
    ];

    $allowed = $transitions[$currentStatus] ?? [];
    $next    = $allowed[0] ?? null; // first option is the "happy path" next step

    return ['allowed' => $allowed, 'next' => $next];
}

/**
 * Check whether a status transition is valid.
 */
function isValidTransition(string $from, string $to, bool $requiresSig, bool $sigObtained): bool
{
    $t = getAllowedTransitions($from, $requiresSig, $sigObtained);
    return in_array($to, $t['allowed'], true);
}

/**
 * Perform a status update with full validation, logging, and notifications.
 *
 * @param  mysqli  $conn
 * @param  int     $requestId
 * @param  string  $newStatus
 * @param  int     $adminId       ID of the admin/registrar making the change
 * @param  string  $notes         Optional remarks
 * @return array   ['success' => bool, 'error' => string|null]
 */
function updateRequestStatus(
    mysqli $conn,
    int    $requestId,
    string $newStatus,
    int    $adminId,
    string $notes = ''
): array {

    // --- Fetch current request ---
    $stmt = $conn->prepare("
        SELECT dr.status, dr.user_id, dr.request_code, dr.document_type_id,
               dr.signature_obtained_at, dr.copies,
               dt.requires_signature, dt.name AS doc_name, dt.processing_days,
               dt.fee,
               CONCAT(u.first_name,' ',u.last_name) AS student_name,
               u.email AS student_email
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        JOIN users u ON dr.user_id = u.id
        WHERE dr.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $requestId);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$req) {
        return ['success' => false, 'error' => 'Request not found.'];
    }

    $oldStatus    = $req['status'];
    $requiresSig  = (bool)$req['requires_signature'];
    $sigObtained  = !empty($req['signature_obtained_at']);

    // --- Validate transition ---
    if (!isValidTransition($oldStatus, $newStatus, $requiresSig, $sigObtained)) {
        return [
            'success' => false,
            'error'   => "Invalid transition: {$oldStatus} → {$newStatus}."
        ];
    }

    // --- Build UPDATE fields ---
    $extraSql = '';
    $extraTypes = '';
    $extraParams = [];

    // When moving to for_signature, stamp the request timestamp
    if ($newStatus === 'for_signature') {
        $extraSql    .= ', signature_requested_at = NOW()';
    }

    // When moving to approved (from for_signature), stamp obtained
    if ($newStatus === 'approved' && $oldStatus === 'for_signature') {
        $extraSql    .= ', signature_obtained_at = NOW()';
    }

    // When moving to approved (from pending), calculate estimated release date
    if ($newStatus === 'approved') {
        $extraSql    .= ', estimated_release_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)';
        $extraTypes  .= 'i';
        $extraParams[] = (int)$req['processing_days'];
    }

    // --- Execute UPDATE ---
    $sql   = "UPDATE document_requests SET status = ?, remarks = ? {$extraSql}, updated_at = NOW() WHERE id = ?";
    $types = 'ss' . $extraTypes . 'i';
    $params = array_merge([$newStatus, $notes], $extraParams, [$requestId]);

    $upStmt = $conn->prepare($sql);
    $upStmt->bind_param($types, ...$params);

    if (!$upStmt->execute()) {
        $upStmt->close();
        return ['success' => false, 'error' => 'Database update failed: ' . $conn->error];
    }
    $upStmt->close();

    // --- Log the change ---
    logStatusChange($conn, $requestId, $adminId, $oldStatus, $newStatus, $notes);

    // --- Send notification to student ---
    $message = buildStatusMessage($newStatus, $req['request_code'], $req['doc_name']);
    sendNotification($conn, $req['user_id'], $message);

    // --- Send email for key status changes ---
    sendStatusEmail($req['student_email'], $req['student_name'], $newStatus,
                    $req['request_code'], $req['doc_name'],
                    $req['fee'] * $req['copies']);

    return ['success' => true, 'error' => null];
}

/**
 * Mark signature as obtained (from for_signature → approved shortcut).
 * Call this when the registrar confirms the physical signature is complete.
 */
function markSignatureObtained(mysqli $conn, int $requestId, int $adminId, string $notes = ''): array
{
    return updateRequestStatus($conn, $requestId, 'approved', $adminId,
        $notes ?: 'Signature obtained and verified.');
}

/**
 * Write a row to request_logs.
 */
function logStatusChange(
    mysqli $conn,
    int    $requestId,
    int    $changedBy,
    string $oldStatus,
    string $newStatus,
    string $notes = ''
): void {
    $stmt = $conn->prepare("
        INSERT INTO request_logs (request_id, changed_by, old_status, new_status, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iisss", $requestId, $changedBy, $oldStatus, $newStatus, $notes);
    $stmt->execute();
    $stmt->close();
}

/**
 * Build a human-readable notification message for the student.
 */
function buildStatusMessage(string $status, string $code, string $docName): string
{
    $messages = [
        'for_signature' => "Your request {$code} ({$docName}) requires a signature before processing. We will update you once it is obtained.",
        'approved'      => "Your request {$code} ({$docName}) has been approved and is now being processed.",
        'processing'    => "Your request {$code} ({$docName}) is now being processed by the Registrar.",
        'ready'         => "Your request {$code} ({$docName}) is READY for pickup. Please proceed to the Registrar's Office to pay and claim your document.",
        'paid'          => "Payment for request {$code} ({$docName}) has been recorded. Your claim stub has been generated.",
        'released'      => "Your document {$code} ({$docName}) has been successfully released. Thank you!",
        'cancelled'     => "Your request {$code} ({$docName}) has been cancelled. Please contact the Registrar for more information.",
    ];

    return $messages[$status] ?? "Your request {$code} status has been updated to: {$status}.";
}

/**
 * Send status-change email via PHPMailer.
 * Only sends for statuses that need student action.
 */
function sendStatusEmail(
    string $email,
    string $name,
    string $status,
    string $code,
    string $docName,
    float  $fee
): void {
    // Only email for statuses where the student needs to act or be informed
    $emailStatuses = ['for_signature', 'approved', 'ready', 'released', 'cancelled'];
    if (!in_array($status, $emailStatuses, true)) return;

    // PHPMailer may not be configured in all environments — wrap in try/catch
    try {
        require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
        require_once __DIR__ . '/../PHPMailer/src/Exception.php';
        require_once __DIR__ . '/mailer.php'; // your existing sendMail wrapper

        $subject = match($status) {
            'for_signature' => "[DocuGo] Signature Required — {$code}",
            'approved'      => "[DocuGo] Request Approved — {$code}",
            'ready'         => "[DocuGo] Ready for Pickup — {$code}",
            'released'      => "[DocuGo] Document Released — {$code}",
            'cancelled'     => "[DocuGo] Request Cancelled — {$code}",
            default         => "[DocuGo] Request Update — {$code}",
        };

        $body = buildStatusEmailBody($name, $status, $code, $docName, $fee);

        // Use your existing sendMail() function from mailer.php
        if (function_exists('sendMail')) {
            sendMail($email, $name, $subject, $body);
        }
    } catch (\Throwable $e) {
        // Email failure must never break the request flow — log silently
        error_log("DocuGo email error [{$status}][{$code}]: " . $e->getMessage());
    }
}

/**
 * Build the HTML email body for each status.
 */
function buildStatusEmailBody(
    string $name,
    string $status,
    string $code,
    string $docName,
    float  $fee
): string {
    $feeFormatted = '₱' . number_format($fee, 2);
    $siteUrl      = defined('SITE_URL') ? SITE_URL : '';

    $content = match($status) {
        'for_signature' =>
            "<p>Your request for <strong>{$docName}</strong> (Ref: <code>{$code}</code>) requires an authorized signature before it can be processed.</p>
             <p>We will notify you as soon as the signature has been obtained and your request moves to the next stage. No action is needed from you at this time.</p>",

        'approved' =>
            "<p>Great news! Your request for <strong>{$docName}</strong> (Ref: <code>{$code}</code>) has been <strong>approved</strong> and is now being processed.</p>
             <p>You will receive another notification when your document is ready for pickup.</p>",

        'ready' =>
            "<p>Your document <strong>{$docName}</strong> (Ref: <code>{$code}</code>) is <strong>READY for pickup</strong>.</p>
             <p>Please proceed to the <strong>Registrar's Office</strong> and present your reference number. You will need to pay <strong>{$feeFormatted}</strong> in cash before claiming your document.</p>
             <p><strong>Reference Number:</strong> <code style='font-size:18px;letter-spacing:2px'>{$code}</code></p>",

        'released' =>
            "<p>Your document <strong>{$docName}</strong> (Ref: <code>{$code}</code>) has been successfully <strong>released</strong>.</p>
             <p>Thank you for using DocuGo!</p>",

        'cancelled' =>
            "<p>Unfortunately, your request for <strong>{$docName}</strong> (Ref: <code>{$code}</code>) has been <strong>cancelled</strong>.</p>
             <p>Please contact the Registrar's Office for more information or to submit a new request.</p>",

        default => "<p>Your request <code>{$code}</code> has been updated. Please check your dashboard for details.</p>",
    };

    return "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;color:#111827'>
        <div style='background:#1a56db;padding:20px 24px;border-radius:10px 10px 0 0'>
            <h1 style='color:#fff;font-size:20px;margin:0'>DocuGo</h1>
            <p style='color:rgba(255,255,255,0.8);font-size:13px;margin:4px 0 0'>Asian Development Foundation College</p>
        </div>
        <div style='background:#fff;padding:24px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 10px 10px'>
            <p style='margin:0 0 12px'>Hello, <strong>" . htmlspecialchars($name) . "</strong>,</p>
            {$content}
            <hr style='border:none;border-top:1px solid #f3f4f6;margin:20px 0'>
            <p style='font-size:12px;color:#9ca3af;margin:0'>
                This is an automated message from DocuGo. Please do not reply to this email.
                <br>For questions, contact the Registrar's Office directly.
            </p>
        </div>
    </div>";
}

/**
 * Human-readable label for each status (used in UI badges).
 */
function statusLabel(string $status): string
{
    return match($status) {
        'pending'       => 'Pending',
        'for_signature' => 'For Signature',
        'approved'      => 'Approved',
        'processing'    => 'Processing',
        'ready'         => 'Ready for Pickup',
        'paid'          => 'Paid',
        'released'      => 'Released',
        'cancelled'     => 'Cancelled',
        default         => ucfirst($status),
    };
}

/**
 * CSS badge class for each status (matches dashboard.php badge classes).
 */
function statusBadgeClass(string $status): string
{
    return match($status) {
        'pending'       => 'badge-pending',
        'for_signature' => 'badge-signature',
        'approved'      => 'badge-approved',
        'processing'    => 'badge-processing',
        'ready'         => 'badge-ready',
        'paid'          => 'badge-paid',
        'released'      => 'badge-released',
        'cancelled'     => 'badge-cancelled',
        default         => 'badge-pending',
    };
}

/**
 * Full badge HTML span.
 */
function statusBadge(string $status): string
{
    $cls   = statusBadgeClass($status);
    $label = statusLabel($status);
    return "<span class='badge {$cls}'>" . htmlspecialchars($label) . "</span>";
}

/**
 * Full badge HTML with payment status.
 */
function paymentBadge(string $payStatus): string
{
    $map = [
        'unpaid'   => ['badge-pending',  'Unpaid'],
        'paid'     => ['badge-paid',     'Paid'],
        'refunded' => ['badge-cancelled','Refunded'],
    ];
    [$cls, $label] = $map[$payStatus] ?? ['badge-pending', ucfirst($payStatus)];
    return "<span class='badge {$cls}'>{$label}</span>";
}