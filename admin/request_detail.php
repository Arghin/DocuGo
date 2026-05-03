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
function ago($d){
    $diff = time() - strtotime($d);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60).'m ago';
    if ($diff < 86400)  return floor($diff/3600).'h ago';
    return floor($diff/86400).'d ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request #<?= e($r['request_code']) ?> — Admin</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:      #1a56db;
            --blue-dk:   #1447c0;
            --blue-lt:   #eff6ff;
            --green:     #059669;
            --green-lt:  #f0fdf4;
            --yellow:    #d97706;
            --yellow-lt: #fffbeb;
            --purple:    #7c3aed;
            --purple-lt: #faf5ff;
            --red:       #dc2626;
            --red-lt:    #fef2f2;
            --bg:        #f0f4f8;
            --card:      #ffffff;
            --border:    #e5e7eb;
            --border-lt: #f3f4f6;
            --text:      #111827;
            --text-2:    #374151;
            --text-3:    #6b7280;
            --text-4:    #9ca3af;
            --sidebar:   220px;
            --shadow:    0 1px 4px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar);
            background: var(--blue);
            color: #fff;
            min-height: 100vh;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; height: 100%;
            z-index: 100;
        }

        .sidebar-brand {
            padding: 1.4rem 1.2rem 1.2rem;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin-bottom: 0.2rem;
        }

        .brand-icon {
            width: 34px; height: 34px;
            background: var(--blue);
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(26,86,219,0.4);
        }

        .brand-name {
            font-size: 1.2rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.4px;
        }

        .brand-sub {
            font-size: 0.67rem;
            color: rgba(255,255,255,0.4);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            padding-left: 2.9rem;
        }

        .sidebar-menu { padding: 0.85rem 0; flex: 1; overflow-y: auto; }

        .sidebar-footer {
            padding: 0.9rem 1rem;
            border-top: 1px solid rgba(255,255,255,0.15);
            font-size: 0.8rem;
        }

        .sidebar-footer a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .menu-section {
            padding: 0.8rem 1rem 0.2rem;
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255,255,255,0.3);
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.58rem 1rem;
            margin: 1px 0.6rem;
            border-radius: 8px;
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            font-size: 0.845rem;
            font-weight: 500;
        }

        .menu-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.9); }
        .menu-item.active { background: rgba(255,255,255,0.15); color: #fff; font-weight: 600; }

        /* Main */
        .main {
            margin-left: var(--sidebar);
            flex: 1;
            padding: 1.8rem 2rem;
            min-width: 0;
        }

        /* Topbar */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.6rem;
            gap: 1rem;
        }

        .topbar-left h1 {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .topbar-left p {
            font-size: 0.82rem;
            color: var(--text-3);
            margin-top: 1px;
        }

        .btn {
            padding: 6px 14px;
            border: none;
            border-radius: 7px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.15s;
        }

        .btn-back { background: var(--bg); color: var(--text-2); border: 1px solid var(--border); }
        .btn-approve { background: #dbeafe; color: #1e40af; }
        .btn-process { background: #e0f2fe; color: #0369a1; }
        .btn-ready { background: #fef9c3; color: #854d0e; }
        .btn-release { background: var(--blue); color: #fff; }
        .btn-danger { background: var(--red-lt); color: var(--red); }
        .btn-stub { background: var(--purple-lt); color: var(--purple); }
        .btn:hover { opacity: 0.85; transform: translateY(-1px); }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Cards */
        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            overflow: hidden;
            margin-bottom: 1.2rem;
        }

        .card-header {
            padding: 0.9rem 1.2rem;
            border-bottom: 1px solid var(--border-lt);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h2 {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
        }

        .card-body {
            padding: 1.2rem;
        }

        /* Status Banner */
        .status-banner {
            border-radius: 12px;
            padding: 1rem 1.4rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.2rem;
            flex-wrap: wrap;
        }

        .status-banner.pending { background: var(--yellow-lt); border: 1px solid #fcd34d; }
        .status-banner.approved { background: var(--blue-lt); border: 1px solid #bfdbfe; }
        .status-banner.processing { background: #f0f9ff; border: 1px solid #bae6fd; }
        .status-banner.ready { background: #fefce8; border: 2px solid #facc15; }
        .status-banner.paid { background: var(--green-lt); border: 1px solid #bbf7d0; }
        .status-banner.released { background: var(--purple-lt); border: 1px solid #e9d5ff; }
        .status-banner.cancelled { background: var(--red-lt); border: 1px solid #fecaca; }

        .status-banner h3 { font-size: 1rem; font-weight: 700; margin-bottom: 3px; }
        .status-banner p { font-size: 0.82rem; opacity: 0.8; }

        /* Grid Layout */
        .grid-2 {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.2rem;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        .info-item {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.75rem 1rem;
        }

        .info-item.full { grid-column: 1 / -1; }

        .info-label {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--text-4);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 3px;
        }

        .info-value {
            font-size: 0.875rem;
            color: var(--text);
            font-weight: 500;
        }

        /* Fee Box */
        .fee-box {
            background: linear-gradient(135deg, var(--blue), var(--blue-dk));
            color: #fff;
            border-radius: 12px;
            padding: 1.2rem;
            margin-bottom: 1.2rem;
            text-align: center;
        }

        .fee-label { font-size: 0.75rem; opacity: 0.8; margin-bottom: 4px; }
        .fee-amount { font-size: 1.8rem; font-weight: 800; }

        /* Payment Info */
        .payment-confirmed {
            background: var(--green-lt);
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 1rem;
        }

        .pc-row {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            border-bottom: 1px solid #dcfce7;
            font-size: 0.85rem;
        }

        .pc-row:last-child { border-bottom: none; }
        .pc-label { color: var(--text-2); }
        .pc-value { font-weight: 700; color: var(--text); }

        /* Badges */
        .badge {
            display: inline-flex;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
        }

        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #dbeafe; color: #1e40af; }
        .badge-processing { background: #e0f2fe; color: #0369a1; }
        .badge-ready { background: #fef9c3; color: #854d0e; }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-released { background: #ede9fe; color: #4c1d95; }

        /* Timeline */
        .timeline { padding: 0.5rem 0; }
        .log-entry {
            display: flex;
            gap: 0.85rem;
            padding: 0.75rem 1.2rem;
            border-bottom: 1px solid var(--border-lt);
        }

        .log-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 5px;
        }

        .log-dot.pending { background: var(--yellow); }
        .log-dot.approved { background: var(--blue); }
        .log-dot.processing { background: #0ea5e9; }
        .log-dot.ready { background: var(--yellow); }
        .log-dot.paid { background: var(--green); }
        .log-dot.released { background: var(--purple); }

        .log-title { font-size: 0.82rem; font-weight: 600; color: var(--text); }
        .log-meta { font-size: 0.72rem; color: var(--text-4); margin-top: 2px; }
        .log-notes { font-size: 0.78rem; color: var(--text-3); margin-top: 4px; font-style: italic; }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open { display: flex; }

        .modal {
            background: var(--card);
            border-radius: 14px;
            padding: 2rem;
            width: 100%;
            max-width: 440px;
        }

        .modal h2 { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.4rem; }
        .modal .mfield { margin-bottom: 1rem; }
        .modal label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.3rem; }
        .modal input, .modal textarea {
            width: 100%;
            padding: 0.6rem 0.85rem;
            border: 1.5px solid var(--border);
            border-radius: 7px;
            font-size: 0.875rem;
        }

        .modal-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.2rem;
        }

        .modal-confirm { background: var(--blue); color: #fff; flex: 1; padding: 0.7rem; border: none; border-radius: 8px; cursor: pointer; }
        .modal-cancel { background: var(--bg); color: var(--text-2); flex: 1; padding: 0.7rem; border: none; border-radius: 8px; cursor: pointer; }

        .warn-box {
            background: var(--yellow-lt);
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.82rem;
            color: #92400e;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .grid-2 { grid-template-columns: 1fr; }
            .info-grid { grid-template-columns: 1fr; }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon">📄</div>
            <div class="brand-name">DocuGo</div>
        </div>
        <div class="brand-sub">Admin Panel</div>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-section">Main</div>
        <a href="dashboard.php" class="menu-item">🏠 Dashboard</a>
        <a href="requests.php" class="menu-item active">📄 Document Requests</a>
        <a href="accounts.php" class="menu-item">👥 User Accounts</a>
        <div class="menu-section">Records</div>
        <a href="alumni.php" class="menu-item">🎓 Alumni</a>
        <a href="tracer.php" class="menu-item">📊 Graduate Tracer</a>
        <a href="reports.php" class="menu-item">📈 Reports</a>
        <div class="menu-section">Communication</div>
        <a href="announcements.php" class="menu-item">📢 Announcements</a>
        <div class="menu-section">Settings</div>
        <a href="document_types.php" class="menu-item">⚙️ Document Types</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Request Details</h1>
            <p>View and manage request #<?= e($r['request_code']) ?></p>
        </div>
        <div>
            <a href="requests.php" class="btn btn-back">← Back to Requests</a>
        </div>
    </div>

    <!-- Status Banner -->
    <?php
    $bannerText = [
        'pending'    => ['⏳ Pending Review', 'This request is waiting for admin approval.'],
        'approved'   => ['✓ Approved', 'Request approved. Mark as processing when document preparation starts.'],
        'for_signature' => ['✍ For Signature', 'Document requires signature approval before processing.'],
        'processing' => ['⚙ Being Processed', 'Document is being prepared by the registrar.'],
        'ready'      => ['📋 Ready for Pickup — UNPAID', 'Document is ready. Student must go to the cashier to pay and claim.'],
        'paid'       => ['✓ Paid', 'Payment has been recorded.'],
        'released'   => ['📦 Released', 'Document has been successfully claimed by the student.'],
        'cancelled'  => ['✕ Cancelled', 'This request has been cancelled.'],
    ];
    $bn = $bannerText[$r['status']] ?? [$r['status'], ''];
    ?>
    <div class="status-banner <?= e($r['status']) ?>">
        <div>
            <h3><?= $bn[0] ?></h3>
            <p><?= $bn[1] ?></p>
        </div>
        <div><?= statusBadge($r['status']) ?></div>
    </div>

    <!-- Action Buttons -->
    <div class="card">
        <div class="card-header">
            <h2>⚡ Actions</h2>
        </div>
        <div class="card-body">
            <div class="action-buttons">
                <?php if ($r['status'] === 'pending'): ?>
                    <form method="POST" action="requests.php" style="display:inline;">
                        <input type="hidden" name="request_id" value="<?= $requestId ?>">
                        <input type="hidden" name="action" value="approve">
                        <button class="btn btn-approve" onclick="return confirm('Approve this request?')">✓ Approve</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($r['status'], ['approved', 'for_signature'])): ?>
                    <form method="POST" action="requests.php" style="display:inline;">
                        <input type="hidden" name="request_id" value="<?= $requestId ?>">
                        <input type="hidden" name="action" value="process">
                        <button class="btn btn-process" onclick="return confirm('Mark as Processing?')">⚙ Mark Processing</button>
                    </form>
                <?php endif; ?>

                <?php if ($r['status'] === 'processing'): ?>
                    <form method="POST" action="requests.php" style="display:inline;">
                        <input type="hidden" name="request_id" value="<?= $requestId ?>">
                        <input type="hidden" name="action" value="ready">
                        <button class="btn btn-ready" onclick="return confirm('Mark as Ready for pickup?')">📋 Mark Ready</button>
                    </form>
                <?php endif; ?>

                <?php if ($r['status'] === 'ready'): ?>
                    <button class="btn btn-release" onclick="openPayModal()">💳 Pay & Release</button>
                <?php endif; ?>

                <?php if ($stub): ?>
                    <a href="../student/claim_stub.php?code=<?= e($stub['stub_code']) ?>&admin=1" target="_blank" class="btn btn-stub">🖨 View Claim Stub</a>
                <?php endif; ?>

                <?php if (!in_array($r['status'], ['released', 'cancelled'])): ?>
                    <form method="POST" action="requests.php" style="display:inline;">
                        <input type="hidden" name="request_id" value="<?= $requestId ?>">
                        <input type="hidden" name="action" value="cancel">
                        <button class="btn btn-danger" onclick="return confirm('Cancel this request?')">✕ Cancel</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid-2">
        <!-- Left Column -->
        <div>
            <!-- Request Information -->
            <div class="card">
                <div class="card-header">
                    <h2>📄 Request Information</h2>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Reference Code</div>
                            <div class="info-value"><code><?= e($r['request_code']) ?></code></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Date Submitted</div>
                            <div class="info-value"><?= fdt($r['requested_at']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Document Type</div>
                            <div class="info-value"><?= e($r['doc_type']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Number of Copies</div>
                            <div class="info-value"><?= $r['copies'] ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Release Mode</div>
                            <div class="info-value"><?= ucfirst(e($r['release_mode'])) ?></div>
                        </div>
                        <?php if ($r['preferred_release_date']): ?>
                        <div class="info-item">
                            <div class="info-label">Preferred Release Date</div>
                            <div class="info-value"><?= fd($r['preferred_release_date']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($r['estimated_release_date']): ?>
                        <div class="info-item">
                            <div class="info-label">Est. Release Date</div>
                            <div class="info-value"><?= fd($r['estimated_release_date']) ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="info-item full">
                            <div class="info-label">Purpose</div>
                            <div class="info-value"><?= nl2br(e($r['purpose'])) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Requester Information -->
            <div class="card">
                <div class="card-header">
                    <h2>👤 Requester Information</h2>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Role</div>
                            <div class="info-value"><?= ucfirst(e($r['role'])) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?= e($r['email']) ?></div>
                        </div>
                        <?php if ($r['student_id']): ?>
                        <div class="info-item">
                            <div class="info-label">Student ID</div>
                            <div class="info-value"><?= e($r['student_id']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($r['course']): ?>
                        <div class="info-item">
                            <div class="info-label">Course</div>
                            <div class="info-value"><?= e($r['course']) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($r['contact_number']): ?>
                        <div class="info-item">
                            <div class="info-label">Contact Number</div>
                            <div class="info-value"><?= e($r['contact_number']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Release Information (if released) -->
            <?php if ($release && $release['released_at']): ?>
            <div class="card">
                <div class="card-header">
                    <h2>📦 Release Information</h2>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Released At</div>
                            <div class="info-value"><?= fdt($release['released_at']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Released By</div>
                            <div class="info-value"><?= e($release['rel_fn'] . ' ' . $release['rel_ln']) ?></div>
                        </div>
                        <?php if ($release['notes']): ?>
                        <div class="info-item full">
                            <div class="info-label">Notes</div>
                            <div class="info-value"><?= e($release['notes']) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right Column -->
        <div>
            <!-- Fee Summary -->
            <div class="fee-box">
                <div class="fee-label">Total Amount Due</div>
                <div class="fee-amount">₱<?= number_format($r['total_fee'], 2) ?></div>
                <div class="fee-sub">
                    ₱<?= number_format($r['fee'], 2) ?> × <?= $r['copies'] ?> copy<?= $r['copies'] > 1 ? 'ies' : '' ?>
                </div>
            </div>

            <!-- Payment Information -->
            <div class="card">
                <div class="card-header">
                    <h2>💰 Payment Information</h2>
                    <?= paymentBadge($r['status']) ?>
                </div>
                <div class="card-body">
                    <?php if ($payment): ?>
                    <div class="payment-confirmed">
                        <div class="pc-row">
                            <span class="pc-label">Amount Paid</span>
                            <span class="pc-value">₱<?= number_format($payment['amount'], 2) ?></span>
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
                            <span class="pc-value"><?= e($payment['staff_fn'] . ' ' . $payment['staff_ln']) ?></span>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="text-align:center;padding:1rem;color:var(--text-4);">
                        💳 No payment recorded yet.<br>
                        <small>Payment will be recorded when the document is claimed.</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="card">
                <div class="card-header">
                    <h2>📋 Activity Log</h2>
                </div>
                <div class="timeline">
                    <?php if ($logs && $logs->num_rows > 0): ?>
                        <?php while ($log = $logs->fetch_assoc()): ?>
                        <div class="log-entry">
                            <div class="log-dot <?= e($log['new_status']) ?>"></div>
                            <div class="log-content">
                                <div class="log-title">
                                    <?= ucfirst(e($log['old_status'] ?? 'created')) ?>
                                    → <?= ucfirst(e($log['new_status'])) ?>
                                </div>
                                <div class="log-meta">
                                    By <?= $log['first_name'] ? e($log['first_name'] . ' ' . $log['last_name']) : 'System' ?>
                                    · <?= ago($log['changed_at']) ?>
                                </div>
                                <?php if ($log['notes']): ?>
                                <div class="log-notes">"<?= e($log['notes']) ?>"</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="padding:1rem;text-align:center;color:var(--text-4);">
                            No activity logged yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Pay & Release Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <h2>💳 Pay & Release Document</h2>
        <p><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><br>
        <?= e($r['doc_type']) ?> (<?= $r['copies'] ?> copy<?= $r['copies'] > 1 ? 'ies' : '' ?>)</p>

        <form method="POST" action="pay_release.php">
            <input type="hidden" name="request_id" value="<?= $requestId ?>">

            <div class="mfield">
                <label>Official Receipt Number <span style="color:var(--red);">*</span></label>
                <input type="text" name="receipt_number" placeholder="e.g., OR-2024-00123" required autofocus>
            </div>

            <div class="mfield">
                <label>Amount to Collect</label>
                <input type="text" value="₱<?= number_format($r['total_fee'], 2) ?>" readonly style="background:var(--bg);font-weight:700;color:var(--green);">
            </div>

            <div class="mfield">
                <label>Notes <span style="color:var(--text-4);">(optional)</span></label>
                <textarea name="notes" rows="3" placeholder="Any additional notes..."></textarea>
            </div>

            <div class="warn-box">
                ⚠️ This will record the payment and immediately release the document. This action <strong>cannot be undone</strong>.
            </div>

            <div class="modal-actions">
                <button type="button" class="modal-cancel" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="modal-confirm">✓ Confirm Payment & Release</button>
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