<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
requireLogin();

$conn   = getConnection();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['user_role'] ?? 'student';
$isAdmin = isset($_GET['admin']) && isAdmin();

// Redirect admin/registrar away unless viewing as admin
if (!$isAdmin && isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit();
}

// Get stub code
$stubCode = trim($_GET['code'] ?? '');

if (empty($stubCode)) {
    header('Location: my_requests.php');
    exit();
}

// Fetch stub + request + user info
// FIXED: Removed 'payment_status' from SELECT since it doesn't exist in document_requests
$stmt = $conn->prepare("
    SELECT cs.*,
           dr.request_code, dr.status,
           dr.copies, dr.purpose, dr.release_mode,
           dr.preferred_release_date, dr.requested_at,
           dt.name AS doc_type, dt.fee, dt.processing_days,
           u.first_name, u.last_name, u.middle_name,
           u.student_id, u.course, u.email, u.contact_number, u.role AS user_role,
           pr.official_receipt_number, pr.amount AS paid_amount, pr.payment_date
    FROM claim_stubs cs
    JOIN document_requests dr ON cs.request_id = dr.id
    JOIN document_types dt ON dr.document_type_id = dt.id
    JOIN users u ON cs.user_id = u.id
    LEFT JOIN payment_records pr ON dr.id = pr.request_id
    WHERE cs.stub_code = ?
");
if (!$stmt) {
    die("Database error: " . $conn->error);
}
$stmt->bind_param("s", $stubCode);
$stmt->execute();
$stub = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Security: non-admin users can only see their own stubs
if (!$stub || (!$isAdmin && $stub['user_id'] != $userId)) {
    header('Location: my_requests.php?msg=Stub+not+found.');
    exit();
}

// Mark as printed
if (!$stub['is_printed']) {
    // Use prepared statement for security
    $updateStmt = $conn->prepare("UPDATE claim_stubs SET is_printed = 1, printed_at = NOW() WHERE stub_code = ?");
    if ($updateStmt) {
        $updateStmt->bind_param("s", $stubCode);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

// Fetch request logs - Fix SQL injection vulnerability
$logStmt = $conn->prepare("
    SELECT rl.new_status, rl.changed_at, rl.notes,
           u.first_name, u.last_name
    FROM request_logs rl
    LEFT JOIN users u ON rl.changed_by = u.id
    WHERE rl.request_id = ?
    ORDER BY rl.changed_at ASC
");
$logStmt->bind_param("i", $stub['request_id']);
$logStmt->execute();
$logs = $logStmt->get_result();
$logStmt->close();

$conn->close();

function e($v)  { return htmlspecialchars($v ?? ''); }
function fd($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function fdt($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }

$totalFee  = $stub['fee'] * $stub['copies'];
$fullName  = trim($stub['first_name'] . ' ' . ($stub['middle_name'] ? $stub['middle_name'][0] . '. ' : '') . $stub['last_name']);
$initials  = strtoupper(substr($stub['first_name'],0,1) . substr($stub['last_name'],0,1));
// FIXED: Use $stub['status'] to determine payment status since payment_status doesn't exist
$isPaid    = in_array($stub['status'], ['paid', 'released']);
$isReady   = $stub['status'] === 'ready';
$isReleased= $stub['status'] === 'released';

$statusColors = [
    'pending'    => ['bg' => '#fef3c7', 'color' => '#92400e', 'label' => 'Pending'],
    'approved'   => ['bg' => '#dbeafe', 'color' => '#1e40af', 'label' => 'Approved'],
    'processing' => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'label' => 'Processing'],
    'ready'      => ['bg' => '#fef9c3', 'color' => '#854d0e', 'label' => 'Ready (Unpaid)'],
    'paid'       => ['bg' => '#d1fae5', 'color' => '#065f46', 'label' => 'Paid'],
    'released'   => ['bg' => '#ede9fe', 'color' => '#4c1d95', 'label' => 'Released'],
    'cancelled'  => ['bg' => '#fee2e2', 'color' => '#991b1b', 'label' => 'Cancelled'],
];
$sc = $statusColors[$stub['status']] ?? ['bg' => '#f3f4f6', 'color' => '#374151', 'label' => ucfirst($stub['status'])];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Stub — <?= e($stub['request_code']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            display: flex;
        }

        /* SIDEBAR — same as dashboard */
        .sidebar {
            width: 220px;
            background: #1a56db;
            color: #fff;
            height: 100vh;
            position: fixed;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            padding: 1.4rem;
            font-size: 1.5rem;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }

        .sidebar-brand small {
            display: block;
            font-size: 0.7rem;
            opacity: 0.8;
        }

        .sidebar-menu { flex: 1; padding: 1rem 0; }

        .menu-label {
            font-size: 0.7rem;
            padding: 0.5rem 1.2rem;
            opacity: 0.6;
            text-transform: uppercase;
        }

        .menu-item {
            display: block;
            padding: 0.7rem 1.2rem;
            color: #fff;
            text-decoration: none;
            opacity: 0.85;
        }

        .menu-item:hover { background: rgba(255,255,255,0.1); }
        .menu-item.active {
            background: rgba(255,255,255,0.15);
            border-left: 3px solid #fff;
            font-weight: 600;
            opacity: 1;
        }

        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        /* MAIN */
        .main {
            margin-left: 220px;
            padding: 2rem;
            width: 100%;
            min-height: 100vh;
        }

        /* TOPBAR */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .topbar h2 { font-size: 1.3rem; font-weight: 700; color: #111827; }

        .topbar-actions { display: flex; gap: 0.5rem; }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            border: none;
            transition: opacity 0.15s;
        }

        .btn:hover { opacity: 0.85; }
        .btn-back   { background: #f3f4f6; color: #374151; }
        .btn-print  { background: #1a56db; color: #fff; }

        /* Stub container */
        .stub-wrap {
            max-width: 720px;
            margin: 0 auto;
        }

        /* Main stub card */
        .stub-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 1rem;
        }

        /* Stub header - same blue as dashboard welcome */
        .stub-header {
            background: #1a56db;
            color: #fff;
            padding: 1.5rem;
        }

        .stub-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .stub-header .school-name {
            font-size: 0.75rem;
            opacity: 0.8;
            margin-bottom: 2px;
        }

        .stub-header .system-name {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -0.3px;
        }

        .stub-header .stub-title {
            font-size: 0.72rem;
            opacity: 0.75;
            margin-top: 2px;
        }

        .stub-header-right { text-align: right; }

        .ref-code-label { font-size: 0.68rem; opacity: 0.75; margin-bottom: 2px; }
        .ref-code {
            font-family: monospace;
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            margin-top: 6px;
            background: rgba(255,255,255,0.2);
            color: #fff;
        }

        /* Requester row */
        .requester-row {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            background: rgba(255,255,255,0.12);
            border-radius: 8px;
            padding: 0.75rem 1rem;
        }

        .req-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; font-weight: 700; flex-shrink: 0;
        }

        .req-name  { font-size: 0.95rem; font-weight: 700; }
        .req-meta  { font-size: 0.75rem; opacity: 0.85; margin-top: 1px; }

        /* Stub body */
        .stub-body { padding: 1.4rem; }

        /* Info grid - 2 col */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.2rem;
        }

        .info-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.75rem 1rem;
        }

        .info-item.full { grid-column: 1 / -1; }

        .info-label {
            font-size: 0.68rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 3px;
        }

        .info-value {
            font-size: 0.875rem;
            color: #111827;
            font-weight: 500;
        }

        /* Fee highlight */
        .fee-box {
            background: #1a56db;
            color: #fff;
            border-radius: 10px;
            padding: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.2rem;
        }

        .fee-box .fl { }
        .fee-box .fl .fl-label { font-size: 0.72rem; opacity: 0.8; margin-bottom: 3px; }
        .fee-box .fl .fl-amount { font-size: 1.6rem; font-weight: 800; }
        .fee-box .fr { font-size: 0.78rem; opacity: 0.85; text-align: right; line-height: 1.6; }

        /* Pay at cashier instruction */
        .cashier-instruction {
            background: #fffbeb;
            border: 1.5px solid #fcd34d;
            border-radius: 10px;
            padding: 1rem 1.2rem;
            margin-bottom: 1.2rem;
        }

        .ci-header {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .ci-icon {
            width: 28px; height: 28px;
            background: #f59e0b;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; flex-shrink: 0;
        }

        .ci-title { font-size: 0.875rem; font-weight: 700; color: #92400e; }

        .ci-steps {
            display: flex;
            gap: 0;
            flex-wrap: wrap;
        }

        .ci-step {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.78rem;
            color: #92400e;
        }

        .ci-step .cs-num {
            width: 20px; height: 20px;
            background: #f59e0b;
            color: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.68rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .ci-arrow { color: #f59e0b; font-weight: 700; margin: 0 0.4rem; }

        /* Paid banner */
        .paid-banner {
            background: #f0fdf4;
            border: 1.5px solid #bbf7d0;
            border-radius: 10px;
            padding: 1rem 1.2rem;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .paid-banner .pb-left h4  { font-size: 0.9rem; font-weight: 700; color: #15803d; }
        .paid-banner .pb-left p   { font-size: 0.78rem; color: #166534; margin-top: 2px; }
        .paid-banner .pb-right    { text-align: right; }
        .paid-banner .or-number   {
            font-size: 1rem; font-weight: 800;
            color: #15803d; font-family: monospace;
        }
        .paid-banner .or-label    { font-size: 0.7rem; color: #166534; margin-bottom: 2px; }

        /* Status tracker - same as my_requests.php */
        .tracker-section {
            border-top: 1px solid #f3f4f6;
            padding: 1rem 1.4rem;
            background: #fafafa;
        }

        .tracker-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.85rem;
        }

        .tracker { position: relative; }

        .tracker-line-bg {
            position: absolute;
            left: 14px; right: 14px; top: 13px;
            height: 4px; background: #e5e7eb;
            z-index: 0; border-radius: 4px;
        }

        .tracker-line-fill {
            position: absolute;
            left: 14px; top: 13px;
            height: 4px; background: #1a56db;
            z-index: 1; border-radius: 4px;
        }

        .tracker-steps {
            display: flex;
            justify-content: space-between;
            position: relative; z-index: 2;
            padding-bottom: 28px;
        }

        .tracker-step { display: flex; flex-direction: column; align-items: center; gap: 6px; }

        .step-dot {
            width: 28px; height: 28px; border-radius: 50%;
            background: #fff; border: 3px solid #e5e7eb;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; color: #9ca3af;
        }

        .step-dot.done    { background: #1a56db; border-color: #1a56db; color: #fff; }
        .step-dot.current {
            background: #fff; border-color: #1a56db; color: #1a56db;
            box-shadow: 0 0 0 4px rgba(26,86,219,0.15);
        }

        .step-label { font-size: 0.65rem; color: #9ca3af; text-align: center; white-space: nowrap; }
        .step-label.done    { color: #1a56db; font-weight: 600; }
        .step-label.current { color: #111827; font-weight: 700; }

        /* Activity log */
        .log-section {
            border-top: 1px solid #f3f4f6;
            padding: 1rem 1.4rem;
        }

        .log-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.75rem;
        }

        .log-item {
            display: flex;
            gap: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
            align-items: flex-start;
            font-size: 0.8rem;
        }

        .log-item:last-child { border-bottom: none; }

        .log-dot {
            width: 8px; height: 8px;
            border-radius: 50%; background: #cbd5e1;
            flex-shrink: 0; margin-top: 5px;
        }

        .log-dot.released { background: #8b5cf6; }
        .log-dot.paid     { background: #10b981; }
        .log-dot.ready    { background: #f59e0b; }
        .log-dot.approved { background: #3b82f6; }
        .log-dot.processing { background: #0ea5e9; }

        .log-status { font-weight: 600; color: #111827; }
        .log-meta   { font-size: 0.72rem; color: #9ca3af; margin-top: 1px; }

        /* Footer */
        .stub-footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 1rem 1.4rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.72rem;
            color: #9ca3af;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        /* Badge - same as dashboard */
        .badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* ── QR Code Section ──────────────────────────── */
        .qr-section {
            border-top: 2px dashed #e5e7eb;
            padding: 1.4rem 1.6rem;
            background: #f8fafc;
            display: flex;
            align-items: stretch;
            gap: 1.6rem;
            flex-wrap: wrap;
        }

        /* Left: QR code */
        .qr-left {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        .qr-box {
            background: #fff;
            padding: 12px;
            border-radius: 12px;
            border: 2px solid #e5e7eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            position: relative;
        }

        /* DocuGo watermark corners on QR */
        .qr-box::before, .qr-box::after {
            content: '';
            position: absolute;
            width: 14px; height: 14px;
            border-color: #1a56db;
            border-style: solid;
        }
        .qr-box::before { top: 4px; left: 4px; border-width: 2px 0 0 2px; border-radius: 3px 0 0 0; }
        .qr-box::after  { bottom: 4px; right: 4px; border-width: 0 2px 2px 0; border-radius: 0 0 3px 0; }

        .qr-box canvas, .qr-box img { display: block; border-radius: 4px; }

        .qr-code-label {
            font-size: 0.65rem;
            font-weight: 700;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            text-align: center;
        }

        /* Divider */
        .qr-divider {
            width: 1px;
            background: #e5e7eb;
            flex-shrink: 0;
            align-self: stretch;
        }

        /* Right: info */
        .qr-right {
            flex: 1;
            min-width: 200px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0.75rem;
        }

        .qr-right-title {
            font-size: 0.82rem;
            font-weight: 800;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .qr-right-title span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px; height: 22px;
            background: #1a56db;
            color: #fff;
            border-radius: 6px;
            font-size: 0.7rem;
        }

        .qr-details {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .qr-detail-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.78rem;
        }

        .qr-detail-row .qd-label {
            color: #9ca3af;
            font-weight: 600;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            width: 80px;
            flex-shrink: 0;
        }

        .qr-detail-row .qd-value {
            color: #111827;
            font-weight: 600;
        }

        .qr-detail-row .qd-value.mono {
            font-family: monospace;
            font-size: 0.82rem;
            color: #1a56db;
            letter-spacing: 0.5px;
        }

        .qr-instructions {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 0.65rem 0.85rem;
            font-size: 0.75rem;
            color: #1e40af;
            line-height: 1.55;
        }

        .qr-instructions strong { color: #1447c0; }

        /* Print styles */
        @media print {
            .sidebar, .topbar, .no-print { display: none !important; }
            .main { margin-left: 0 !important; padding: 0 !important; }
            body { background: #fff !important; }
            .stub-card { box-shadow: none !important; border: 1px solid #ddd; }
            .stub-wrap { max-width: 100%; }
            .qr-section {
                background: #fff;
                border-top: 1px solid #ddd;
                page-break-inside: avoid;
            }
            .qr-box { border: 2px solid #333; padding: 8px; }
            .qr-label, .qr-hint { color: #333; }
            .qr-stub-code { color: #000; }
            .qr-scan-badge { background: #e5e7eb; color: #111; }
        }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .info-grid { grid-template-columns: 1fr; }
            .ci-steps { flex-direction: column; gap: 0.4rem; }
            .ci-arrow { display: none; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR — consistent with dashboard -->
<aside class="sidebar">
    <div class="sidebar-brand">
        DocuGo
        <small><?= ucfirst($role) ?> Portal</small>
    </div>
    <div class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="dashboard.php"    class="menu-item">🏠 Dashboard</a>
        <a href="request_form.php" class="menu-item">📄 Request Document</a>
        <a href="my_requests.php"  class="menu-item active">📋 My Requests</a>

        <?php if ($role === 'alumni'): ?>
        <div class="menu-label">Alumni</div>
        <a href="graduate_tracer.php"    class="menu-item">📊 Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item">💼 Employment Profile</a>
        <a href="alumni_documents.php"   class="menu-item">🎓 Alumni Documents</a>
        <?php endif; ?>

        <div class="menu-label">Account</div>
        <a href="profile.php" class="menu-item">👤 Profile</a>
    </div>
    <div class="sidebar-footer">
        <a href="../logout.php" style="color:white;text-decoration:none;">🚪 Logout</a>
    </div>
</aside>

<main class="main">

    <!-- Topbar -->
    <div class="topbar no-print">
        <h2>📋 Claim Stub</h2>
        <div class="topbar-actions">
            <a href="my_requests.php" class="btn btn-back">← Back</a>
            <button class="btn btn-print" onclick="window.print()">🖨 Print Stub</button>
        </div>
    </div>

    <div class="stub-wrap">

        <div class="stub-card">

            <!-- STUB HEADER — blue like dashboard welcome -->
            <div class="stub-header">
                <div class="stub-header-top">
                    <div>
                        <div class="school-name">Asian Development Foundation College</div>
                        <div class="system-name">DocuGo — Claim Stub</div>
                        <div class="stub-title">Online Document Request System</div>
                    </div>
                    <div class="stub-header-right">
                        <div class="ref-code-label">Reference Number</div>
                        <div class="ref-code"><?= e($stub['request_code']) ?></div>
                        <div>
                            <span class="status-pill">
                                <?= $sc['label'] ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Requester -->
                <div class="requester-row">
                    <div class="req-avatar"><?= $initials ?></div>
                    <div>
                        <div class="req-name"><?= e($fullName) ?></div>
                        <div class="req-meta">
                            <?= e($stub['email']) ?>
                            <?php if ($stub['student_id']): ?>
                                &nbsp;·&nbsp; ID: <?= e($stub['student_id']) ?>
                            <?php endif; ?>
                            <?php if ($stub['course']): ?>
                                &nbsp;·&nbsp; <?= e($stub['course']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- STUB BODY -->
            <div class="stub-body">

                <!-- Fee box -->
                <div class="fee-box">
                    <div class="fl">
                        <div class="fl-label">Total Amount Due</div>
                        <div class="fl-amount">₱<?= number_format($totalFee, 2) ?></div>
                    </div>
                    <div class="fr">
                        <?= e($stub['doc_type']) ?><br>
                        ₱<?= number_format($stub['fee'], 2) ?> × <?= $stub['copies'] ?>
                        cop<?= $stub['copies'] > 1 ? 'ies' : 'y' ?><br>
                        <?= paymentBadge($stub['status']) ?>
                    </div>
                </div>

                <!-- Document details -->
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Document Type</div>
                        <div class="info-value"><?= e($stub['doc_type']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Number of Copies</div>
                        <div class="info-value"><?= $stub['copies'] ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Release Mode</div>
                        <div class="info-value"><?= ucfirst(e($stub['release_mode'])) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Date Submitted</div>
                        <div class="info-value"><?= fd($stub['requested_at']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Stub Code</div>
                        <div class="info-value" style="font-family:monospace;font-size:0.82rem;">
                            <?= e($stub['stub_code']) ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Preferred Release Date</div>
                        <div class="info-value"><?= fd($stub['preferred_release_date']) ?></div>
                    </div>
                    <div class="info-item full">
                        <div class="info-label">Purpose</div>
                        <div class="info-value"><?= e($stub['purpose']) ?></div>
                    </div>
                </div>

                <!-- PAID banner -->
                <?php if ($isPaid || $isReleased): ?>
                <div class="paid-banner">
                    <div class="pb-left">
                        <h4>✅ Payment Recorded</h4>
                        <p>
                            <?= $isReleased
                                ? 'Document has been successfully released.'
                                : 'Payment confirmed. Document will be released shortly.' ?>
                        </p>
                    </div>
                    <div class="pb-right">
                        <div class="or-label">Official Receipt #</div>
                        <div class="or-number">
                            <?= e($stub['official_receipt_number'] ?? '—') ?>
                        </div>
                    </div>
                </div>

                <!-- Pay at cashier instruction (ready & unpaid) -->
                <?php elseif ($isReady): ?>
                <div class="cashier-instruction">
                    <div class="ci-header">
                        <div class="ci-icon">💰</div>
                        <div class="ci-title">Pay at the Cashier to Claim Your Document</div>
                    </div>
                    <div class="ci-steps">
                        <div class="ci-step">
                            <div class="cs-num">1</div>
                            Present this stub at the Registrar's Office
                        </div>
                        <div class="ci-arrow">→</div>
                        <div class="ci-step">
                            <div class="cs-num">2</div>
                            Pay ₱<?= number_format($totalFee, 2) ?> at the cashier
                        </div>
                        <div class="ci-arrow">→</div>
                        <div class="ci-step">
                            <div class="cs-num">3</div>
                            Staff records payment &amp; releases document
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Still processing -->
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:1rem 1.2rem;margin-bottom:1.2rem;">
                    <div style="font-size:0.875rem;font-weight:700;color:#1e40af;margin-bottom:3px;">
                        ⏳ Your document is being processed
                    </div>
                    <div style="font-size:0.78rem;color:#1e40af;">
                        You will be notified once your document is ready for pickup.
                        Keep this stub for your reference.
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- end stub-body -->

            <!-- STATUS TRACKER — same as my_requests.php -->
            <?php
            $statusSteps = [
                ['key'=>'pending',    'label'=>'Submitted'],
                ['key'=>'approved',   'label'=>'Approved'],
                ['key'=>'processing', 'label'=>'Processing'],
                ['key'=>'ready',      'label'=>'Ready'],
                ['key'=>'paid',       'label'=>'Paid'],
                ['key'=>'released',   'label'=>'Released'],
            ];
            $statusOrder = ['pending'=>0,'approved'=>1,'processing'=>2,'ready'=>3,'paid'=>4,'released'=>5];
            $currentIdx  = $statusOrder[$stub['status']] ?? 0;
            $fillPct     = ($currentIdx / (count($statusSteps)-1)) * 100;
            ?>
            <div class="tracker-section">
                <div class="tracker-title">Request Status</div>
                <div class="tracker">
                    <div class="tracker-line-bg"></div>
                    <div class="tracker-line-fill" style="width:<?= $fillPct ?>%;"></div>
                    <div class="tracker-steps">
                        <?php foreach ($statusSteps as $i => $step):
                            $done    = $i < $currentIdx;
                            $current = $i === $currentIdx;
                            $cls     = $done ? 'done' : ($current ? 'current' : '');
                        ?>
                        <div class="tracker-step">
                            <div class="step-dot <?= $cls ?>">
                                <?= $done ? '✓' : ($i + 1) ?>
                            </div>
                            <div class="step-label <?= $cls ?>"><?= $step['label'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- QR CODE SECTION -->
            <div class="qr-section">
                <div class="qr-box" id="qrcode"></div>
                <div class="qr-info">
                    <div class="qr-label">📱 Scan for Faster Verification</div>
                    <div class="qr-stub-code"><?= e($stub['stub_code']) ?></div>
                    <div class="qr-hint">
                        Staff can scan this QR code to instantly verify
                        request details without manual entry.
                    </div>
                    <div class="qr-scan-badge">
                        🔍 Scan at counter
                    </div>
                </div>
            </div>

            <!-- ACTIVITY LOG -->
            <?php if ($logs && $logs->num_rows > 0): ?>
            <div class="log-section">
                <div class="log-title">Activity Log</div>
                <?php while ($log = $logs->fetch_assoc()): ?>
                <div class="log-item">
                    <div class="log-dot <?= e($log['new_status']) ?>"></div>
                    <div>
                        <div class="log-status">
                            Status changed to: <strong><?= ucfirst(e($log['new_status'])) ?></strong>
                        </div>
                        <div class="log-meta">
                            <?= fdt($log['changed_at']) ?>
                            <?php if ($log['first_name']): ?>
                                &nbsp;·&nbsp; By <?= e($log['first_name'] . ' ' . $log['last_name']) ?>
                            <?php endif; ?>
                            <?php if ($log['notes']): ?>
                                &nbsp;·&nbsp; <?= e($log['notes']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>

            <!-- STUB FOOTER -->
            <div class="stub-footer">
                <div>
                    DocuGo — Asian Development Foundation College
                    &nbsp;·&nbsp; Generated <?= fdt(date('Y-m-d H:i:s')) ?>
                </div>
                <div>
                    <?php if ($stub['is_printed']): ?>
                        Printed: <?= fdt($stub['printed_at']) ?>
                    <?php else: ?>
                        Not yet printed
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- end stub-card -->

        <!-- Print hint -->
        <div class="no-print"
             style="text-align:center;font-size:0.78rem;color:#9ca3af;margin-top:0.5rem;">
            Print or save this stub as PDF for use at the Registrar's Office.
        </div>

    </div><!-- end stub-wrap -->
</main>
<!-- QR Code Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Build QR payload with all relevant stub info
const qrPayload = JSON.stringify({
    stub:   "<?= e($stub['stub_code']) ?>",
    code:   "<?= e($stub['request_code']) ?>",
    req_id: <?= intval($stub['request_id']) ?>,
    name:   "<?= e($fullName) ?>",
    doc:    "<?= e($stub['doc_type']) ?>",
    copies: <?= intval($stub['copies']) ?>,
    fee:    "<?= number_format($totalFee, 2) ?>",
    status: "<?= e($stub['status']) ?>",
    system: "DocuGo-ADFC"
});

// Generate QR code
new QRCode(document.getElementById("qrcode"), {
    text:         qrPayload,
    width:        150,
    height:       150,
    colorDark:    "#111827",
    colorLight:   "#ffffff",
    correctLevel: QRCode.CorrectLevel.H
});
</script>
</body>
</html>