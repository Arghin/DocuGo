<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
requireAdmin();

$conn      = getConnection();
$userId    = $_SESSION['user_id'];
$requestId = intval($_GET['id'] ?? 0);

if ($requestId <= 0) {
    header('Location: requests.php');
    exit();
}

// ── Fetch request details ────────────────────────────────────
$stmt = $conn->prepare("
    SELECT dr.*,
           u.first_name, u.last_name, u.email, u.student_id,
           u.contact_number, u.course, u.year_graduated, u.role,
           dt.name AS doc_type, dt.fee, dt.processing_days,
           (dt.fee * dr.copies) AS total_fee
    FROM document_requests dr
    JOIN users u  ON dr.user_id  = u.id
    JOIN document_types dt ON dr.document_type_id = dt.id
    WHERE dr.id = ?
");
$stmt->bind_param("i", $requestId);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$r) {
    header('Location: requests.php?msg=Request+not+found.&msgtype=error');
    exit();
}

// ── Fetch payment record ─────────────────────────────────────
$payStmt = $conn->prepare("
    SELECT pr.*, u.first_name AS staff_fn, u.last_name AS staff_ln
    FROM payment_records pr
    LEFT JOIN users u ON pr.processed_by = u.id
    WHERE pr.request_id = ?
");
$payStmt->bind_param("i", $requestId);
$payStmt->execute();
$payment = $payStmt->get_result()->fetch_assoc();
$payStmt->close();

// ── Fetch release info ───────────────────────────────────────
$relStmt = $conn->prepare("
    SELECT rs.*, u.first_name AS rel_fn, u.last_name AS rel_ln
    FROM release_schedules rs
    LEFT JOIN users u ON rs.released_by = u.id
    WHERE rs.request_id = ?
");
$relStmt->bind_param("i", $requestId);
$relStmt->execute();
$release = $relStmt->get_result()->fetch_assoc();
$relStmt->close();

// ── Fetch claim stub ─────────────────────────────────────────
$stubStmt = $conn->prepare("SELECT * FROM claim_stubs WHERE request_id = ?");
$stubStmt->bind_param("i", $requestId);
$stubStmt->execute();
$stub = $stubStmt->get_result()->fetch_assoc();
$stubStmt->close();

// ── Fetch request logs ───────────────────────────────────────
$logStmt = $conn->prepare("
    SELECT rl.*, u.first_name, u.last_name, u.role
    FROM request_logs rl
    LEFT JOIN users u ON rl.changed_by = u.id
    WHERE rl.request_id = ?
    ORDER BY rl.changed_at ASC
");
$logStmt->bind_param("i", $requestId);
$logStmt->execute();
$logs = $logStmt->get_result();
$logStmt->close();

$conn->close();

function e($v)  { return htmlspecialchars($v ?? ''); }
function fd($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function fdt($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request #<?= $requestId ?> — Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8; min-height: 100vh; display: flex;
        }
        .sidebar {
            width: 220px; background: #1a56db; color: #fff;
            min-height: 100vh; flex-shrink: 0; display: flex;
            flex-direction: column; position: fixed; top: 0; left: 0; height: 100%;
        }
        .sidebar-brand {
            padding: 1.4rem 1.2rem; font-size: 1.5rem; font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.15); letter-spacing: -0.5px;
        }
        .sidebar-brand small {
            display: block; font-size: 0.7rem;
            font-weight: 400; opacity: 0.75; margin-top: 2px;
        }
        .sidebar-menu { padding: 1rem 0; flex: 1; }
        .menu-label {
            font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.08em; opacity: 0.55; padding: 0.6rem 1.2rem 0.3rem;
        }
        .menu-item {
            display: flex; align-items: center; gap: 0.7rem;
            padding: 0.65rem 1.2rem; color: rgba(255,255,255,0.85);
            text-decoration: none; font-size: 0.875rem; font-weight: 500;
            transition: background 0.15s; border-left: 3px solid transparent;
        }
        .menu-item:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .menu-item.active {
            background: rgba(255,255,255,0.15); color: #fff;
            border-left-color: #fff; font-weight: 600;
        }
        .menu-item .icon { font-size: 1rem; width: 20px; text-align: center; }
        .sidebar-footer {
            padding: 1rem 1.2rem;
            border-top: 1px solid rgba(255,255,255,0.15); font-size: 0.8rem;
        }
        .sidebar-footer a {
            color: #fff; text-decoration: none;
            display: flex; align-items: center; gap: 0.5rem; opacity: 0.85;
        }
        .sidebar-footer a:hover { opacity: 1; }
        .main { margin-left: 220px; flex: 1; padding: 2rem; }

        /* Topbar */
        .topbar {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 1.5rem; gap: 1rem;
        }
        .topbar-left { display: flex; align-items: center; gap: 1rem; }
        .topbar h1  { font-size: 1.3rem; font-weight: 700; color: #111827; }
        .btn {
            padding: 6px 14px; border: none; border-radius: 7px;
            font-size: 0.82rem; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-block;
            transition: opacity 0.15s; white-space: nowrap;
        }
        .btn:hover { opacity: 0.85; }
        .btn-back    { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
        .btn-approve { background: #dbeafe; color: #1e40af; }
        .btn-process { background: #e0f2fe; color: #0369a1; }
        .btn-ready   { background: #fef9c3; color: #854d0e; }
        .btn-release { background: #1a56db; color: #fff; padding: 8px 18px; font-size: 0.875rem; }
        .btn-cancel  { background: #fee2e2; color: #991b1b; }
        .btn-stub    { background: #ede9fe; color: #4c1d95; }
        .btn-print   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

        /* Layout grid */
        .grid-3 {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.2rem;
        }
        .col-left  { display: flex; flex-direction: column; gap: 1.2rem; }
        .col-right { display: flex; flex-direction: column; gap: 1.2rem; }

        /* Cards */
        .card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07); overflow: hidden;
        }
        .card-header {
            padding: 0.9rem 1.2rem; border-bottom: 1px solid #f3f4f6;
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-header h2 { font-size: 0.95rem; font-weight: 700; color: #111827; }
        .card-body  { padding: 1.2rem; }

        /* Fields */
        .field-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;
        }
        .field {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 8px; padding: 0.75rem 1rem;
        }
        .field.full { grid-column: 1 / -1; }
        .field .lbl {
            font-size: 0.7rem; font-weight: 700; color: #94a3b8;
            text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px;
        }
        .field .val { font-size: 0.875rem; color: #111827; font-weight: 500; }
        .field .val.empty { color: #9ca3af; font-weight: 400; font-style: italic; }

        /* Status banner */
        .status-banner {
            border-radius: 10px; padding: 1rem 1.4rem;
            display: flex; align-items: center;
            justify-content: space-between; gap: 1rem;
            margin-bottom: 1.2rem; flex-wrap: wrap;
        }
        .status-banner.pending    { background: #fffbeb; border: 1px solid #fcd34d; }
        .status-banner.approved   { background: #eff6ff; border: 1px solid #bfdbfe; }
        .status-banner.processing { background: #f0f9ff; border: 1px solid #bae6fd; }
        .status-banner.ready      { background: #fefce8; border: 2px solid #facc15; }
        .status-banner.paid       { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .status-banner.released   { background: #faf5ff; border: 1px solid #e9d5ff; }
        .status-banner.cancelled  { background: #fef2f2; border: 1px solid #fecaca; }

        .status-banner .sb-left h3 { font-size: 1rem; font-weight: 700; margin-bottom: 3px; }
        .status-banner .sb-left p  { font-size: 0.82rem; opacity: 0.8; }

        /* Pay instruction box */
        .pay-instruction {
            background: #fffbeb; border: 2px dashed #f59e0b;
            border-radius: 10px; padding: 1rem 1.2rem;
            margin-bottom: 1.2rem;
        }
        .pay-instruction h4 { color: #92400e; font-size: 0.9rem; margin-bottom: 4px; }
        .pay-instruction p  { color: #92400e; font-size: 0.82rem; line-height: 1.5; }

        /* Timeline / logs */
        .timeline { padding: 0.5rem 0; }
        .log-entry {
            display: flex; gap: 0.85rem; padding: 0.75rem 1.2rem;
            border-bottom: 1px solid #f3f4f6; align-items: flex-start;
        }
        .log-entry:last-child { border-bottom: none; }
        .log-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: #cbd5e1; flex-shrink: 0; margin-top: 5px;
        }
        .log-dot.approved   { background: #3b82f6; }
        .log-dot.processing { background: #0ea5e9; }
        .log-dot.ready      { background: #f59e0b; }
        .log-dot.paid       { background: #10b981; }
        .log-dot.released   { background: #8b5cf6; }
        .log-dot.cancelled  { background: #ef4444; }
        .log-content { flex: 1; }
        .log-content .log-title {
            font-size: 0.82rem; font-weight: 600; color: #111827;
        }
        .log-content .log-meta {
            font-size: 0.72rem; color: #9ca3af; margin-top: 2px;
        }
        .log-content .log-notes {
            font-size: 0.78rem; color: #6b7280;
            margin-top: 4px; font-style: italic;
        }

        /* Payment card highlight */
        .payment-confirmed {
            background: #f0fdf4; border: 1px solid #bbf7d0;
            border-radius: 10px; padding: 1rem 1.2rem;
        }
        .payment-confirmed .pc-row {
            display: flex; justify-content: space-between;
            padding: 0.4rem 0; border-bottom: 1px solid #dcfce7;
            font-size: 0.85rem;
        }
        .payment-confirmed .pc-row:last-child { border-bottom: none; }
        .payment-confirmed .pc-label { color: #374151; }
        .payment-confirmed .pc-value { font-weight: 700; color: #111827; }
        .payment-confirmed .pc-value.amount { color: #059669; font-size: 1rem; }

        /* Fee display */
        .fee-box {
            background: linear-gradient(135deg, #1a56db, #1447c0);
            color: #fff; border-radius: 10px;
            padding: 1rem 1.2rem; text-align: center;
        }
        .fee-box .fee-label { font-size: 0.75rem; opacity: 0.8; margin-bottom: 4px; }
        .fee-box .fee-amount { font-size: 1.8rem; font-weight: 800; }
        .fee-box .fee-sub { font-size: 0.75rem; opacity: 0.75; margin-top: 2px; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff; border-radius: 14px; padding: 2rem;
            width: 100%; max-width: 440px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal h2 { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.4rem; color: #111827; }
        .modal p  { font-size: 0.875rem; color: #6b7280; margin-bottom: 1.2rem; }
        .modal .mfield { margin-bottom: 1rem; }
        .modal label {
            display: block; font-size: 0.8rem;
            font-weight: 600; color: #374151; margin-bottom: 0.3rem;
        }
        .modal input, .modal textarea {
            width: 100%; padding: 0.6rem 0.85rem;
            border: 1.5px solid #d1d5db; border-radius: 7px;
            font-size: 0.875rem; outline: none; font-family: inherit;
        }
        .modal input:focus, .modal textarea:focus { border-color: #1a56db; }
        .modal textarea { min-height: 70px; resize: vertical; }
        .modal-actions { display: flex; gap: 0.75rem; margin-top: 1.2rem; }
        .modal-actions button {
            flex: 1; padding: 0.7rem; border: none;
            border-radius: 8px; font-size: 0.9rem;
            font-weight: 600; cursor: pointer;
        }
        .modal-confirm { background: #1a56db; color: #fff; }
        .modal-confirm:hover { background: #1447c0; }
        .modal-cancel  { background: #f3f4f6; color: #374151; }

        .warn-box {
            background: #fffbeb; border: 1px solid #fcd34d;
            border-radius: 8px; padding: 0.75rem 1rem;
            font-size: 0.82rem; color: #92400e; margin-bottom: 0.5rem;
        }

        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .grid-3 { grid-template-columns: 1fr; }
            .field-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">DocuGo <small>Admin Panel</small></div>
    <nav class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="dashboard.php" class="menu-item"><span class="icon">🏠</span> Dashboard</a>
        <a href="requests.php"  class="menu-item active"><span class="icon">📄</span> Document Requests</a>
        <a href="accounts.php"  class="menu-item"><span class="icon">👥</span> User Accounts</a>
        <div class="menu-label">Records</div>
        <a href="alumni.php"    class="menu-item"><span class="icon">🎓</span> Alumni</a>
        <a href="tracer.php"    class="menu-item"><span class="icon">📊</span> Graduate Tracer</a>
        <a href="reports.php"   class="menu-item"><span class="icon">📈</span> Reports</a>

        <div class="menu-label">Communication</div>
        <a href="announcements.php" class="menu-item"><span class="icon">📢</span> Announcements</a>

        <div class="menu-label">Settings</div>
        <a href="document_types.php" class="menu-item"><span class="icon">⚙️</span> Document Types</a>
    </nav>
    <div class="sidebar-footer"><a href="../logout.php">🚪 Logout</a></div>
</aside>

<main class="main">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <a href="requests.php" class="btn btn-back">← Back</a>
            <h1>Request: <code style="font-size:1rem;"><?= e($r['request_code']) ?></code></h1>
        </div>
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
            <!-- Approve -->
            <?php if ($r['status'] === 'pending'): ?>
                <form method="POST" action="requests.php" style="display:inline;">
                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                    <input type="hidden" name="action" value="approve">
                    <button class="btn btn-approve" onclick="return confirm('Approve this request?')">✓ Approve</button>
                </form>
            <?php endif; ?>
            <!-- Process -->
            <?php if ($r['status'] === 'approved'): ?>
                <form method="POST" action="requests.php" style="display:inline;">
                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                    <input type="hidden" name="action" value="process">
                    <button class="btn btn-process" onclick="return confirm('Mark as Processing?')">⚙ Mark Processing</button>
                </form>
            <?php endif; ?>
            <!-- Ready -->
            <?php if ($r['status'] === 'processing'): ?>
                <form method="POST" action="requests.php" style="display:inline;">
                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                    <input type="hidden" name="action" value="ready">
                    <button class="btn btn-ready" onclick="return confirm('Mark as Ready for pickup?')">📋 Mark Ready</button>
                </form>
            <?php endif; ?>
            <!-- Pay & Release -->
            <?php if ($r['status'] === 'ready'): ?>
                <button class="btn btn-release" onclick="openPayModal()">
                    💳 Pay & Release
                </button>
            <?php endif; ?>
            <!-- View Stub -->
            <?php if ($stub): ?>
                <a href="../student/claim_stub.php?code=<?= e($stub['stub_code']) ?>&admin=1"
                   target="_blank" class="btn btn-stub">🖨 View Claim Stub</a>
            <?php endif; ?>
            <!-- Cancel -->
            <?php if (!in_array($r['status'], ['released','cancelled'])): ?>
                <form method="POST" action="requests.php" style="display:inline;">
                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button class="btn btn-cancel" onclick="return confirm('Cancel this request?')">✕ Cancel</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Banner -->
    <?php
    $bannerText = [
        'pending'    => ['⏳ Pending Review',        'This request is waiting for admin approval.'],
        'approved'   => ['✓ Approved',               'Request approved. Mark as processing when document preparation starts.'],
        'processing' => ['⚙ Being Processed',         'Document is being prepared by the registrar.'],
        'ready'      => ['📋 Ready for Pickup — UNPAID','Document is ready. Student must go to the cashier to pay and claim.'],
        'paid'       => ['✓ Paid',                   'Payment has been recorded.'],
        'released'   => ['📦 Released',               'Document has been successfully claimed by the student.'],
        'cancelled'  => ['✕ Cancelled',               'This request has been cancelled.'],
    ];
    $bn = $bannerText[$r['status']] ?? [$r['status'], ''];
    ?>
    <div class="status-banner <?= e($r['status']) ?>">
        <div class="sb-left">
            <h3><?= $bn[0] ?></h3>
            <p><?= $bn[1] ?></p>
        </div>
        <div><?= statusBadge($r['status']) ?></div>
    </div>

    <!-- Pay Instruction (ready status only) -->
    <?php if ($r['status'] === 'ready'): ?>
    <div class="pay-instruction">
        <h4>⚠️ Payment Required Before Release</h4>
        <p>
            The document is ready but has NOT been paid yet.
            Student must present their claim stub at the cashier,
            pay <strong>₱<?= number_format($r['fee'] * $r['copies'], 2) ?></strong>,
            and the staff must click <strong>Pay & Release</strong>
            to record the payment and release the document.
        </p>
    </div>
    <?php endif; ?>

    <div class="grid-3">

        <!-- LEFT COLUMN -->
        <div class="col-left">

            <!-- Request Info -->
            <div class="card">
                <div class="card-header"><h2>📄 Request Information</h2></div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <div class="lbl">Reference Code</div>
                            <div class="val"><code><?= e($r['request_code']) ?></code></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Date Submitted</div>
                            <div class="val"><?= fdt($r['requested_at']) ?></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Document Type</div>
                            <div class="val"><?= e($r['doc_type']) ?></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Number of Copies</div>
                            <div class="val"><?= $r['copies'] ?></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Release Mode</div>
                            <div class="val"><?= ucfirst(e($r['release_mode'])) ?></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Preferred Release Date</div>
                            <div class="val"><?= fd($r['preferred_release_date']) ?></div>
                        </div>
                        <div class="field full">
                            <div class="lbl">Purpose</div>
                            <div class="val"><?= e($r['purpose']) ?></div>
                        </div>
                        <?php if ($r['delivery_address']): ?>
                        <div class="field full">
                            <div class="lbl">Delivery Address</div>
                            <div class="val"><?= e($r['delivery_address']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($r['remarks']): ?>
                        <div class="field full">
                            <div class="lbl">Remarks</div>
                            <div class="val"><?= e($r['remarks']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Requester Info -->
            <div class="card">
                <div class="card-header"><h2>👤 Requester Information</h2></div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <div class="lbl">Full Name</div>
                            <div class="val"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Role</div>
                            <div class="val"><?= ucfirst(e($r['role'])) ?></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Email</div>
                            <div class="val"><?= e($r['email']) ?></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Student / Alumni ID</div>
                            <div class="val <?= $r['student_id'] ? '' : 'empty' ?>">
                                <?= $r['student_id'] ? e($r['student_id']) : 'Not provided' ?>
                            </div>
                        </div>
                        <div class="field">
                            <div class="lbl">Course</div>
                            <div class="val <?= $r['course'] ? '' : 'empty' ?>">
                                <?= $r['course'] ? e($r['course']) : 'Not provided' ?>
                            </div>
                        </div>
                        <div class="field">
                            <div class="lbl">Contact Number</div>
                            <div class="val <?= $r['contact_number'] ? '' : 'empty' ?>">
                                <?= $r['contact_number'] ? e($r['contact_number']) : 'Not provided' ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Info -->
            <div class="card">
                <div class="card-header">
                    <h2>💳 Payment Information</h2>
                    <?= paymentBadge($r['payment_status']) ?>
                </div>
                <div class="card-body">
                    <?php if ($payment): ?>
                    <div class="payment-confirmed">
                        <div class="pc-row">
                            <span class="pc-label">Amount Paid</span>
                            <span class="pc-value amount">₱<?= number_format($payment['amount'], 2) ?></span>
                        </div>
                        <div class="pc-row">
                            <span class="pc-label">Official Receipt #</span>
                            <span class="pc-value"><?= e($payment['official_receipt_number'] ?? '—') ?></span>
                        </div>
                        <div class="pc-row">
                            <span class="pc-label">Payment Date</span>
                            <span class="pc-value"><?= fdt($payment['payment_date']) ?></span>
                        </div>
                        <div class="pc-row">
                            <span class="pc-label">Processed By</span>
                            <span class="pc-value">
                                <?= $payment['staff_fn']
                                    ? e($payment['staff_fn'] . ' ' . $payment['staff_ln'])
                                    : '—' ?>
                            </span>
                        </div>
                        <?php if ($payment['notes']): ?>
                        <div class="pc-row">
                            <span class="pc-label">Notes</span>
                            <span class="pc-value"><?= e($payment['notes']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div style="text-align:center;padding:1.5rem;color:#9ca3af;font-size:0.875rem;">
                        💳 No payment recorded yet.<br>
                        <small>Payment will be recorded when the document is claimed at the cashier.</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Release Info -->
            <?php if ($release && $release['released_at']): ?>
            <div class="card">
                <div class="card-header"><h2>📦 Release Information</h2></div>
                <div class="card-body">
                    <div class="field-grid">
                        <div class="field">
                            <div class="lbl">Released At</div>
                            <div class="val"><?= fdt($release['released_at']) ?></div>
                        </div>
                        <div class="field">
                            <div class="lbl">Released By</div>
                            <div class="val">
                                <?= $release['rel_fn']
                                    ? e($release['rel_fn'] . ' ' . $release['rel_ln'])
                                    : '—' ?>
                            </div>
                        </div>
                        <?php if ($release['notes']): ?>
                        <div class="field full">
                            <div class="lbl">Notes</div>
                            <div class="val"><?= e($release['notes']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- end col-left -->

        <!-- RIGHT COLUMN -->
        <div class="col-right">

            <!-- Fee Summary -->
            <div class="fee-box">
                <div class="fee-label">Total Amount Due</div>
                <div class="fee-amount">₱<?= number_format($r['fee'] * $r['copies'], 2) ?></div>
                <div class="fee-sub">
                    ₱<?= number_format($r['fee'], 2) ?> × <?= $r['copies'] ?> cop<?= $r['copies'] > 1 ? 'ies' : 'y' ?>
                </div>
            </div>

            <!-- Claim Stub -->
            <div class="card">
                <div class="card-header"><h2>🖨 Claim Stub</h2></div>
                <div class="card-body">
                    <?php if ($stub): ?>
                        <div style="text-align:center;margin-bottom:0.85rem;">
                            <div style="font-size:0.72rem;color:#9ca3af;margin-bottom:4px;">Stub Code</div>
                            <div style="font-family:monospace;font-size:1rem;font-weight:700;
                                        color:#1a56db;letter-spacing:1px;">
                                <?= e($stub['stub_code']) ?>
                            </div>
                        </div>
                        <div style="font-size:0.78rem;color:#6b7280;margin-bottom:1rem;text-align:center;">
                            Generated: <?= fdt($stub['created_at']) ?>
                            <?php if ($stub['is_printed']): ?>
                                <br><span style="color:#059669;">✓ Printed <?= fdt($stub['printed_at']) ?></span>
                            <?php endif; ?>
                        </div>
                        <a href="../student/claim_stub.php?code=<?= e($stub['stub_code']) ?>&admin=1"
                           target="_blank" class="btn btn-stub"
                           style="width:100%;text-align:center;display:block;padding:8px;">
                            🖨 View / Print Stub
                        </a>
                    <?php else: ?>
                        <div style="text-align:center;padding:1rem;color:#9ca3af;font-size:0.82rem;">
                            No claim stub yet.<br>
                            <small>Generated automatically when status is set to Ready.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="card">
                <div class="card-header"><h2>📋 Activity Log</h2></div>
                <div class="timeline">
                    <?php if ($logs->num_rows > 0): ?>
                        <?php while ($log = $logs->fetch_assoc()): ?>
                        <div class="log-entry">
                            <div class="log-dot <?= e($log['new_status']) ?>"></div>
                            <div class="log-content">
                                <div class="log-title">
                                    <?= ucfirst(e($log['old_status'] ?? 'created')) ?>
                                    → <?= ucfirst(e($log['new_status'])) ?>
                                </div>
                                <div class="log-meta">
                                    By <?= $log['first_name']
                                        ? e($log['first_name'] . ' ' . $log['last_name'])
                                        : 'System' ?>
                                    (<?= ucfirst(e($log['role'] ?? 'system')) ?>)
                                    · <?= fdt($log['changed_at']) ?>
                                </div>
                                <?php if ($log['notes']): ?>
                                <div class="log-notes">"<?= e($log['notes']) ?>"</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="padding:1.5rem;text-align:center;color:#9ca3af;font-size:0.82rem;">
                            No activity logged yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- end col-right -->
    </div><!-- end grid -->
</main>

<!-- PAY & RELEASE Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <h2>💳 Pay & Release Document</h2>
        <p>
            <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong> —
            <?= e($r['doc_type']) ?> (<?= $r['copies'] ?> cop<?= $r['copies'] > 1 ? 'ies' : 'y' ?>)
        </p>

        <form method="POST" action="pay_release.php">
            <input type="hidden" name="request_id" value="<?= $requestId ?>">

            <div class="mfield">
                <label>Official Receipt Number <span style="color:#e11d48;">*</span></label>
                <input type="text" name="receipt_number"
                       placeholder="e.g. OR-2024-00123" required autofocus>
            </div>

            <div class="mfield">
                <label>Amount to Collect</label>
                <input type="text"
                       value="₱<?= number_format($r['fee'] * $r['copies'], 2) ?>"
                       readonly style="background:#f9fafb;font-weight:700;color:#059669;">
            </div>

            <div class="mfield">
                <label>Notes <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
                <textarea name="notes" placeholder="Any additional notes…"></textarea>
            </div>

            <div class="warn-box">
                ⚠️ This will record the payment and immediately release the document.
                This action <strong>cannot be undone</strong>.
            </div>

            <div class="modal-actions">
                <button type="button" class="modal-cancel" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="modal-confirm">✓ Confirm & Release</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayModal() {
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() {
    document.getElementById('payModal').classList.remove('open');
}
document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});
</script>
</body>
</html>