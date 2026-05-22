<?php
// ================================================================
// signatory/dashboard.php
// 5 SIGNATURES REQUIRED (Adviser, CompLab, Library, Dept Head, Registrar)
// + REJECTION WITH REASON + CLEARANCE SIGNATURE GRID
// ================================================================
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
require_once '../includes/signature_workflow.php';
requireLogin();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'signatory') {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$conn   = getConnection();
$userId = $_SESSION['user_id'];

// Load signatory user info (get their office/role name for rejection message)
$uStmt = $conn->prepare("
    SELECT u.id, u.first_name, u.last_name, u.signatory_role, so.office_name 
    FROM users u
    LEFT JOIN signature_offices so ON so.id = u.signature_office_id
    WHERE u.id = ? LIMIT 1
");
$uStmt->bind_param("i", $userId);
$uStmt->execute();
$signer = $uStmt->get_result()->fetch_assoc();
$uStmt->close();

if (!$signer) {
    session_destroy();
    header('Location: ' . SITE_URL . '/login.php?err=no_account');
    exit();
}

$signerName = $signer['first_name'] . ' ' . $signer['last_name'];
$officeName = $signer['office_name'] ?? ($signer['signatory_role'] ?? 'Signatory Office');

// ── Handle POST (sign / reject) ────────────────────────────
$flashSuccess = $flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']       ?? '';
    
    if ($action === 'reject_request') {
        // REJECT ENTIRE REQUEST
        $requestId = intval($_POST['request_id'] ?? 0);
        $reason    = trim($_POST['reject_reason'] ?? '');
        
        if (empty($reason)) {
            $flashError = 'Please provide a reason for rejection.';
        } else {
            $result = rejectRequest($conn, $requestId, $userId, $reason, $officeName);
            if ($result['success']) {
                $flashSuccess = '✅ Request has been rejected. Student has been notified.';
            } else {
                $flashError = $result['error'];
            }
        }
    } else {
        // SIGN a specific slot
        $sigRowId = intval($_POST['sig_id'] ?? 0);
        $remarks  = trim($_POST['remarks']  ?? '');
        
        if ($action === 'sign') {
            $result = signRequestRow($conn, $sigRowId, $userId, $remarks);
            if ($result['success']) {
                $flashSuccess = $result['auto_approved']
                    ? '✅ ALL 5 SIGNATURES COLLECTED! Request has been approved and student notified.'
                    : '✅ Signature recorded.';
            } else {
                $flashError = $result['error'];
            }
        }
    }
}

// ── Stats ──────────────────────────────────────────────────
$stats = getSignatoryStats($conn);

// ── Tab filter ─────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'pending';

// ── Build query based on tab filter ───────────────────────
$sql = "
    SELECT
        rs.id           AS sig_id,
        rs.status       AS sig_status,
        rs.signed_at,
        rs.remarks      AS sig_remarks,
        dr.id           AS req_id,
        dr.request_code,
        dr.status       AS req_status,
        dr.purpose,
        dr.copies,
        dr.requested_at,
        dr.estimated_release_date,
        dr.remarks      AS req_remarks,
        dt.name         AS doc_type,
        dt.fee,
        (dt.fee * dr.copies) AS total_fee,
        CONCAT(u.first_name,' ',u.last_name) AS student_name,
        u.student_id,
        u.course,
        u.email         AS student_email,
        (SELECT COUNT(*) FROM request_signatures WHERE request_id = dr.id AND status = 'signed') AS signed_count,
        (SELECT COUNT(*) FROM request_signatures WHERE request_id = dr.id) AS total_count
    FROM request_signatures rs
    JOIN document_requests dr ON rs.request_id = dr.id
    JOIN document_types dt ON dr.document_type_id = dt.id
    JOIN users u ON dr.user_id = u.id
";

if ($tab !== 'all') {
    $sql .= " WHERE " . match($tab) {
        'signed'   => "rs.status = 'signed'",
        'rejected' => "dr.status = 'cancelled'",
        default    => "rs.status = 'pending' AND dr.status = 'for_signature'",
    };
}

$sql .= " GROUP BY dr.id ORDER BY dr.requested_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL Error: " . $conn->error);
}
$stmt->execute();
$rows = $stmt->get_result();
$stmt->close();

// Get signature details for each request to show office-by-office status
$signatureDetails = [];
if ($rows->num_rows > 0) {
    $rows->data_seek(0);
    while ($row = $rows->fetch_assoc()) {
        $reqId = $row['req_id'];
        $sigStmt = $conn->prepare("
            SELECT 
                rs.status,
                rs.signed_at,
                CONCAT(u.first_name, ' ', u.last_name) as signed_by_name,
                CASE 
                    WHEN rs.office_id = 1 THEN 'Adviser'
                    WHEN rs.office_id = 2 THEN 'Computer Laboratory'
                    WHEN rs.office_id = 3 THEN 'Library'
                    WHEN rs.office_id = 4 THEN 'Department Head'
                    WHEN rs.office_id = 5 THEN 'Registrar'
                    ELSE 'Unknown'
                END as office_name
            FROM request_signatures rs
            LEFT JOIN users u ON rs.signed_by = u.id
            WHERE rs.request_id = ?
            ORDER BY rs.office_id ASC
        ");
        $sigStmt->bind_param("i", $reqId);
        $sigStmt->execute();
        $sigResult = $sigStmt->get_result();
        $signatureDetails[$reqId] = [];
        while ($sig = $sigResult->fetch_assoc()) {
            $signatureDetails[$reqId][] = $sig;
        }
        $sigStmt->close();
    }
    $rows->data_seek(0);
}

$conn->close();

function escape($v)  { return htmlspecialchars($v ?? ''); }
function formatDate($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function formatDateTime($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signature Panel — ADFC DocuGo</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        /* ========== THEME VARIABLES - DARK MODE (DEFAULT) ========== */
        :root {
            --primary:    #1a3ec7;
            --primary-dk: #1230a0;
            --accent:     #3b6bff;
            --accent2:    #6b9fff;
            --bg:         #080e28;
            --bg2:        #0b1535;
            --surface:    rgba(255,255,255,0.05);
            --surface-hv: rgba(255,255,255,0.08);
            --border:     rgba(255,255,255,0.08);
            --border-hv:  rgba(59,107,255,0.25);
            --text:       #dce6f8;
            --text-muted: #7a96c4;
            --text-dim:   #4a6190;
            --green:      #4cd98a;
            --yellow:     #fbbf24;
            --purple:     #a78bfa;
            --red:        #f87171;
            --blue:       #60a5fa;
            --radius-sm:  8px;
            --radius-md:  12px;
            --radius-lg:  16px;
            --radius-xl:  24px;
            --ease-out:   cubic-bezier(0.16, 1, 0.3, 1);
            --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* ========== LIGHT MODE VARIABLES ========== */
        body.light {
            --bg:         #eef2ff;
            --bg2:        #e2e9ff;
            --surface:    rgba(255,255,255,0.7);
            --surface-hv: rgba(255,255,255,0.9);
            --border:     rgba(26,62,199,0.12);
            --border-hv:  rgba(26,62,199,0.25);
            --text:       #0c1836;
            --text-muted: #3d5a92;
            --text-dim:   #7a96c4;
            --green:      #059669;
            --yellow:     #d97706;
            --purple:     #7c3aed;
            --red:        #dc2626;
            --blue:       #1a56db;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            transition: background 0.3s, color 0.3s;
        }

        /* Topbar - gradient stays consistent */
        .topbar {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            padding: 0.8rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(26,86,219,0.28);
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .topbar-left { display: flex; align-items: center; gap: 0.75rem; }
        .topbar-brand { 
            font-family: 'Sora', sans-serif;
            font-size: 1.2rem; 
            font-weight: 800; 
        }
        .topbar-logo {
            width: 32px;
            height: 32px;
            object-fit: contain;
            border-radius: 8px;
        }
        .topbar-office { 
            background: rgba(255,255,255,0.18); 
            border-radius: 20px; 
            padding: 3px 12px; 
            font-size: 0.8rem; 
            font-weight: 600; 
        }
        .topbar-right { display: flex; align-items: center; gap: 0.75rem; font-size: 0.85rem; }
        .logout-link { 
            background: rgba(255,255,255,0.16); 
            padding: 4px 12px; 
            border-radius: 7px; 
            color: #fff; 
            text-decoration: none; 
            font-size: 0.82rem; 
            font-weight: 600; 
            transition: background 0.15s; 
        }
        .logout-link:hover { background: rgba(255,255,255,0.28); }

        /* Main Content */
        .main { max-width: 1200px; margin: 0 auto; padding: 1.5rem 1.25rem; }
        .page-title { margin-bottom: 1.25rem; }
        .page-title h1 { 
            font-family: 'Sora', sans-serif;
            font-size: 1.35rem; 
            font-weight: 700; 
            color: var(--text); 
        }
        .page-title p { font-size: 0.875rem; color: var(--text-muted); margin-top: 3px; }

        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
        .stat-card { 
            background: var(--surface); 
            border-radius: var(--radius-lg); 
            padding: 1rem; 
            box-shadow: 0 1px 6px rgba(0,0,0,0.08); 
            text-align: center;
            border: 1px solid var(--border);
        }
        .stat-card.urgent { background: rgba(251,191,36,0.1); border: 1.5px solid var(--yellow); }
        .stat-n { 
            font-family: 'Sora', sans-serif;
            font-size: 1.9rem; 
            font-weight: 800; 
            color: var(--text); 
        }
        .stat-l { font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; }

        /* Alerts */
        .alert { padding: 0.85rem 1rem; border-radius: var(--radius-md); margin-bottom: 1rem; font-size: 0.875rem; }
        .alert-success { background: rgba(76,217,138,0.15); border: 1px solid rgba(76,217,138,0.3); color: var(--green); }
        body.light .alert-success { background: #d1fae5; border-color: #a7f3d0; color: #065f46; }
        .alert-error { background: rgba(248,113,113,0.15); border: 1px solid rgba(248,113,113,0.3); color: var(--red); }
        body.light .alert-error { background: #fee2e2; border-color: #fecaca; color: #991b1b; }

        /* Tabs */
        .tabs { 
            display: flex; 
            gap: 0.25rem; 
            background: var(--surface); 
            padding: 0.35rem; 
            border-radius: var(--radius-lg); 
            box-shadow: 0 1px 6px rgba(0,0,0,0.08); 
            margin-bottom: 1rem; 
            flex-wrap: wrap;
            border: 1px solid var(--border);
        }
        .tab { 
            padding: 0.4rem 0.85rem; 
            border-radius: var(--radius-sm); 
            text-decoration: none; 
            font-size: 0.8rem; 
            font-weight: 500; 
            color: var(--text-muted); 
            transition: all 0.15s; 
        }
        .tab:hover { background: var(--bg2); color: var(--text); }
        .tab.active { background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; font-weight: 600; }
        .tab .cnt { background: rgba(0,0,0,0.1); border-radius: 10px; padding: 1px 6px; font-size: 0.7rem; margin-left: 3px; }
        .tab.active .cnt { background: rgba(255,255,255,0.25); }

        /* Clearance Card */
        .clearance-card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .clearance-card.rejected { background: rgba(248,113,113,0.05); border-left: 4px solid var(--red); }
        .clearance-card.approved { background: rgba(76,217,138,0.05); border-left: 4px solid var(--green); }
        body.light .clearance-card.rejected { background: #fef2f2; }
        body.light .clearance-card.approved { background: #f0fdf4; }

        /* Card Header */
        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            padding: 16px 20px;
        }
        .request-code { font-family: monospace; font-size: 0.7rem; opacity: 0.8; margin-bottom: 4px; }
        .student-name { font-size: 1rem; font-weight: 700; }
        .student-details { font-size: 0.7rem; opacity: 0.8; margin-top: 4px; }

        /* Card Body */
        .card-body { padding: 16px 20px; }
        .document-info {
            background: var(--bg2);
            padding: 10px 14px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
        }
        .doc-name { font-weight: 700; color: var(--primary); margin-bottom: 4px; font-size: 0.85rem; }
        .purpose { font-size: 0.75rem; color: var(--text-muted); }

        /* Signature Grid */
        .signature-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-bottom: 16px;
        }
        .signature-item {
            background: var(--bg2);
            border-radius: var(--radius-md);
            padding: 10px 6px;
            text-align: center;
            transition: all 0.2s;
            position: relative;
            border: 1px solid var(--border);
        }
        .signature-item.signed {
            background: rgba(76,217,138,0.15);
            border: 1px solid var(--green);
        }
        .signature-item.pending {
            background: rgba(251,191,36,0.08);
            border: 1px dashed var(--yellow);
        }
        body.light .signature-item.signed { background: #d1fae5; }
        body.light .signature-item.pending { background: #fef3c7; }
        .signature-icon { font-size: 1.3rem; margin-bottom: 4px; }
        .signature-name { font-weight: 700; font-size: 0.7rem; margin-bottom: 3px; color: var(--text); }
        .signature-status { font-size: 0.6rem; }
        .signature-item.signed .signature-status { color: var(--green); font-weight: 600; }
        .signature-item.pending .signature-status { color: var(--yellow); }
        .signature-signer { font-size: 0.55rem; color: var(--green); margin-top: 3px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .checkmark {
            position: absolute;
            top: -6px;
            right: -6px;
            background: var(--green);
            color: white;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
        }

        /* Progress Bar */
        .progress-section { margin: 12px 0; }
        .progress-label { display: flex; justify-content: space-between; font-size: 0.7rem; color: var(--text-muted); margin-bottom: 5px; }
        .progress-bar { height: 6px; background: var(--border); border-radius: 10px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, var(--primary), var(--accent)); border-radius: 10px; transition: width 0.3s ease; }

        /* Action Area */
        .card-action { padding: 14px 20px; border-top: 1px solid var(--border); background: var(--bg2); }
        .action-inner { display: flex; gap: 15px; align-items: flex-start; flex-wrap: wrap; }
        .sign-form { flex: 2; min-width: 200px; }
        .reject-form { flex: 1; min-width: 180px; padding-left: 15px; border-left: 1px dashed var(--border); }
        .remarks-input, .reject-reason-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.75rem;
            resize: vertical;
            margin-bottom: 8px;
            background: var(--surface);
            color: var(--text);
            transition: border-color 0.2s;
        }
        .remarks-input:focus, .reject-reason-input:focus { outline: none; border-color: var(--accent); }
        .btn-sign, .btn-reject {
            width: 100%;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        .btn-sign { background: linear-gradient(135deg, var(--primary), var(--accent)); color: white; }
        .btn-sign:hover { transform: translateY(-1px); opacity: 0.92; }
        .btn-reject { background: rgba(248,113,113,0.15); color: var(--red); border: 1px solid rgba(248,113,113,0.3); }
        .btn-reject:hover { background: rgba(248,113,113,0.25); }
        body.light .btn-reject { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        body.light .btn-reject:hover { background: #fecaca; }
        .rejection-label { font-size: 0.65rem; font-weight: 600; color: var(--red); margin-bottom: 5px; display: block; }

        .state-banner { padding: 10px 16px; border-top: 1px solid var(--border); font-size: 0.8rem; text-align: center; }
        .state-approved { background: rgba(76,217,138,0.1); color: var(--green); }
        .state-rejected { background: rgba(248,113,113,0.1); color: var(--red); }
        body.light .state-approved { background: #f0fdf4; color: #065f46; }
        body.light .state-rejected { background: #fef2f2; color: #991b1b; }

        .badge { padding: 2px 8px; border-radius: 10px; font-size: 0.65rem; font-weight: 700; display: inline-block; }
        .badge-pending { background: rgba(251,191,36,0.15); color: var(--yellow); }
        .badge-approved { background: rgba(76,217,138,0.15); color: var(--green); }
        .badge-rejected { background: rgba(248,113,113,0.15); color: var(--red); }

        .empty { text-align: center; padding: 3rem 1rem; background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); }
        .empty-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .empty h3 { font-family: 'Sora', sans-serif; font-size: 1rem; color: var(--text); margin-bottom: 0.3rem; }
        .empty p { font-size: 0.875rem; color: var(--text-muted); }

        /* Theme Toggle */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--surface);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text);
            font-size: 1.1rem;
            z-index: 99;
            transition: transform 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .theme-toggle:hover { transform: scale(1.1); background: var(--surface-hv); }

        @media (max-width: 900px) {
            .signature-grid { grid-template-columns: repeat(2, 1fr); }
            .action-inner { flex-direction: column; }
            .reject-form { border-left: none; padding-left: 0; margin-top: 10px; }
        }
        @media (max-width: 500px) {
            .signature-grid { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <img id="topbarLogo" src="../wlogo.png" alt="ADFC Logo" style="width: 32px; height: 32px; object-fit: contain; border-radius: 8px;">
        <span class="topbar-brand">ADFC DocuGo</span>
        <span class="topbar-office">✍️ <?= escape($officeName) ?></span>
    </div>
    <div class="topbar-right">
        <span class="topbar-user"><i class="fas fa-user-circle"></i> <?= escape($signerName) ?></span>
        <a href="../logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<div class="main">
    <div class="page-title">
        <h1><i class="fas fa-clipboard-list"></i> Clearance Signature Panel</h1>
        <p><strong>5 signatures required</strong> — Adviser, Computer Laboratory, Library, Department Head, Registrar. Once all 5 offices sign, the request is automatically approved.</p>
    </div>

    <div class="stats">
        <div class="stat-card urgent"><div class="stat-n"><?= $stats['pending'] ?? 0 ?></div><div class="stat-l">Pending Signatures</div></div>
        <div class="stat-card"><div class="stat-n"><?= $stats['signed'] ?? 0 ?></div><div class="stat-l">Completed Requests</div></div>
        <div class="stat-card"><div class="stat-n"><?= ($stats['pending'] ?? 0) + ($stats['signed'] ?? 0) + ($stats['rejected'] ?? 0) ?></div><div class="stat-l">Total Requests</div></div>
    </div>

    <?php if ($flashSuccess): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= escape($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($flashError) ?></div><?php endif; ?>

    <div class="tabs">
        <a href="?tab=pending" class="tab <?= $tab==='pending'?'active':'' ?>"><i class="fas fa-clock"></i> Needs Signature <span class="cnt"><?= $stats['pending'] ?? 0 ?></span></a>
        <a href="?tab=signed" class="tab <?= $tab==='signed'?'active':'' ?>"><i class="fas fa-check-circle"></i> Approved <span class="cnt"><?= $stats['signed'] ?? 0 ?></span></a>
        <a href="?tab=rejected" class="tab <?= $tab==='rejected'?'active':'' ?>"><i class="fas fa-times-circle"></i> Rejected <span class="cnt"><?= $stats['rejected'] ?? 0 ?></span></a>
        <a href="?tab=all" class="tab <?= $tab==='all'?'active':'' ?>"><i class="fas fa-list"></i> All Requests</a>
    </div>

    <?php if ($rows->num_rows === 0): ?>
    <div class="empty"><div class="empty-icon">🎉</div><h3>No pending signatures</h3><p>All document requests have been processed.</p></div>
    <?php else: ?>

    <?php while ($r = $rows->fetch_assoc()):
        $sigPct = $r['total_count'] > 0 ? round(($r['signed_count'] / $r['total_count']) * 100) : 0;
        $isRejected = ($r['req_status'] === 'cancelled');
        $isApproved = ($r['req_status'] === 'approved');
        $isPending = (!$isRejected && !$isApproved && $r['req_status'] === 'for_signature');
        
        // Get signature details for this request
        $sigs = $signatureDetails[$r['req_id']] ?? [];
        $sigMap = [];
        foreach ($sigs as $sig) {
            $sigMap[$sig['office_name']] = $sig;
        }
        
        // Check if current signatory already signed
        $alreadySigned = false;
        foreach ($sigs as $sig) {
            if ($sig['signed_by_name'] == $signerName) {
                $alreadySigned = true;
                break;
            }
        }
    ?>
    <div class="clearance-card <?= $isRejected ? 'rejected' : ($isApproved ? 'approved' : '') ?>">
        <div class="card-header">
            <div class="request-code"><i class="fas fa-file-alt"></i> <?= escape($r['request_code']) ?></div>
            <div class="student-name"><i class="fas fa-user-graduate"></i> <?= escape($r['student_name']) ?></div>
            <div class="student-details">ID: <?= escape($r['student_id'] ?? 'N/A') ?> · Course: <?= escape($r['course'] ?? 'N/A') ?></div>
        </div>

        <div class="card-body">
            <div class="document-info">
                <div class="doc-name"><i class="fas fa-file-pdf"></i> <?= escape($r['doc_type']) ?></div>
                <div class="purpose">Purpose: <?= escape($r['purpose']) ?></div>
                <div class="purpose"><i class="fas fa-calendar"></i> Requested: <?= formatDate($r['requested_at']) ?> · Copies: <?= $r['copies'] ?></div>
                <?php if ($isRejected && $r['req_remarks']): ?>
                    <div class="purpose" style="color:var(--red); margin-top:6px;"><i class="fas fa-ban"></i> Rejection: <?= escape($r['req_remarks']) ?></div>
                <?php endif; ?>
            </div>

            <!-- 5 OFFICES SIGNATURE GRID -->
            <div class="signature-grid">
                <!-- ADVISER -->
                <div class="signature-item <?= isset($sigMap['Adviser']) && $sigMap['Adviser']['status'] === 'signed' ? 'signed' : 'pending' ?>">
                    <div class="signature-icon">👨‍🏫</div>
                    <div class="signature-name">ADVISER</div>
                    <?php if (isset($sigMap['Adviser']) && $sigMap['Adviser']['status'] === 'signed'): ?>
                        <div class="signature-status"><i class="fas fa-check-circle"></i> Signed</div>
                        <div class="signature-signer" title="<?= escape($sigMap['Adviser']['signed_by_name'] ?? 'Unknown') ?>">by: <?= escape(substr($sigMap['Adviser']['signed_by_name'] ?? 'Unknown', 0, 15)) ?></div>
                        <div class="checkmark">✓</div>
                    <?php else: ?>
                        <div class="signature-status"><i class="fas fa-hourglass-half"></i> Pending</div>
                    <?php endif; ?>
                </div>

                <!-- COMPUTER LABORATORY -->
                <div class="signature-item <?= isset($sigMap['Computer Laboratory']) && $sigMap['Computer Laboratory']['status'] === 'signed' ? 'signed' : 'pending' ?>">
                    <div class="signature-icon">💻</div>
                    <div class="signature-name">COMPUTER LAB</div>
                    <?php if (isset($sigMap['Computer Laboratory']) && $sigMap['Computer Laboratory']['status'] === 'signed'): ?>
                        <div class="signature-status"><i class="fas fa-check-circle"></i> Signed</div>
                        <div class="signature-signer" title="<?= escape($sigMap['Computer Laboratory']['signed_by_name'] ?? 'Unknown') ?>">by: <?= escape(substr($sigMap['Computer Laboratory']['signed_by_name'] ?? 'Unknown', 0, 15)) ?></div>
                        <div class="checkmark">✓</div>
                    <?php else: ?>
                        <div class="signature-status"><i class="fas fa-hourglass-half"></i> Pending</div>
                    <?php endif; ?>
                </div>

                <!-- LIBRARY -->
                <div class="signature-item <?= isset($sigMap['Library']) && $sigMap['Library']['status'] === 'signed' ? 'signed' : 'pending' ?>">
                    <div class="signature-icon">📚</div>
                    <div class="signature-name">LIBRARY</div>
                    <?php if (isset($sigMap['Library']) && $sigMap['Library']['status'] === 'signed'): ?>
                        <div class="signature-status"><i class="fas fa-check-circle"></i> Signed</div>
                        <div class="signature-signer" title="<?= escape($sigMap['Library']['signed_by_name'] ?? 'Unknown') ?>">by: <?= escape(substr($sigMap['Library']['signed_by_name'] ?? 'Unknown', 0, 15)) ?></div>
                        <div class="checkmark">✓</div>
                    <?php else: ?>
                        <div class="signature-status"><i class="fas fa-hourglass-half"></i> Pending</div>
                    <?php endif; ?>
                </div>

                <!-- DEPARTMENT HEAD -->
                <div class="signature-item <?= isset($sigMap['Department Head']) && $sigMap['Department Head']['status'] === 'signed' ? 'signed' : 'pending' ?>">
                    <div class="signature-icon">📋</div>
                    <div class="signature-name">DEPARTMENT HEAD</div>
                    <?php if (isset($sigMap['Department Head']) && $sigMap['Department Head']['status'] === 'signed'): ?>
                        <div class="signature-status"><i class="fas fa-check-circle"></i> Signed</div>
                        <div class="signature-signer" title="<?= escape($sigMap['Department Head']['signed_by_name'] ?? 'Unknown') ?>">by: <?= escape(substr($sigMap['Department Head']['signed_by_name'] ?? 'Unknown', 0, 15)) ?></div>
                        <div class="checkmark">✓</div>
                    <?php else: ?>
                        <div class="signature-status"><i class="fas fa-hourglass-half"></i> Pending</div>
                    <?php endif; ?>
                </div>

                <!-- REGISTRAR -->
                <div class="signature-item <?= isset($sigMap['Registrar']) && $sigMap['Registrar']['status'] === 'signed' ? 'signed' : 'pending' ?>">
                    <div class="signature-icon">🏛️</div>
                    <div class="signature-name">REGISTRAR</div>
                    <?php if (isset($sigMap['Registrar']) && $sigMap['Registrar']['status'] === 'signed'): ?>
                        <div class="signature-status"><i class="fas fa-check-circle"></i> Signed</div>
                        <div class="signature-signer" title="<?= escape($sigMap['Registrar']['signed_by_name'] ?? 'Unknown') ?>">by: <?= escape(substr($sigMap['Registrar']['signed_by_name'] ?? 'Unknown', 0, 15)) ?></div>
                        <div class="checkmark">✓</div>
                    <?php else: ?>
                        <div class="signature-status"><i class="fas fa-hourglass-half"></i> Pending</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="progress-section">
                <div class="progress-label">
                    <span><i class="fas fa-chart-line"></i> Overall Progress</span>
                    <span><?= $r['signed_count'] ?> / <?= $r['total_count'] ?: 5 ?> offices signed</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= $sigPct ?>%"></div>
                </div>
            </div>
        </div>

        <!-- SIGN / REJECT ACTIONS -->
        <?php if ($isPending && $r['signed_count'] < ($r['total_count'] ?: 5)): ?>
        <div class="card-action">
            <div class="action-inner">
                <div class="sign-form">
                    <form method="POST" action="dashboard.php?tab=<?= escape($tab) ?>">
                        <input type="hidden" name="sig_id" value="<?= $r['sig_id'] ?>">
                        <textarea name="remarks" class="remarks-input" placeholder="Optional remarks (e.g. 'Cleared', 'Verified', 'No pending obligations')"></textarea>
                        <button type="submit" name="action" value="sign" class="btn-sign"><i class="fas fa-signature"></i> SIGN CLEARANCE — <?= escape($officeName) ?></button>
                    </form>
                </div>
                
                <div class="reject-form">
                    <div class="rejection-label"><i class="fas fa-ban"></i> Reject entire request:</div>
                    <form method="POST" action="dashboard.php?tab=<?= escape($tab) ?>" onsubmit="return confirmReject()">
                        <input type="hidden" name="request_id" value="<?= $r['req_id'] ?>">
                        <textarea name="reject_reason" class="reject-reason-input" placeholder="Required: Reason for rejection (e.g. 'Student has unreturned books')" required></textarea>
                        <button type="submit" name="action" value="reject_request" class="btn-reject"><i class="fas fa-times-circle"></i> Reject Request</button>
                    </form>
                </div>
            </div>
        </div>
        <?php elseif ($isRejected): ?>
        <div class="state-banner state-rejected"><i class="fas fa-ban"></i> This request has been REJECTED.</div>
        <?php elseif ($isApproved): ?>
        <div class="state-banner state-approved"><i class="fas fa-check-circle"></i> ALL 5 SIGNATURES COMPLETE — Request has been APPROVED and is being processed.</div>
        <?php endif; ?>
    </div>
    <?php endwhile; ?>
    <?php endif; ?>
</div>

<!-- Theme Toggle Button -->
<div class="theme-toggle" id="themeToggleBtn">
    <i class="fas fa-moon"></i>
</div>

<script>
// ========== DARK/LIGHT MODE TOGGLE + LOGO SWAP ==========
const applyLogoForTheme = (isLight) => {
    const logoImg = document.getElementById('topbarLogo');
    if (logoImg) logoImg.src = isLight ? '../logo.png' : '../wlogo.png';
};

// Load saved theme preference
const savedTheme = localStorage.getItem('docugoTheme');
const isLightOnLoad = savedTheme === 'light';

if (isLightOnLoad) {
    document.body.classList.add('light');
    document.getElementById('themeToggleBtn').innerHTML = '<i class="fas fa-sun"></i>';
} else {
    document.body.classList.remove('light');
    document.getElementById('themeToggleBtn').innerHTML = '<i class="fas fa-moon"></i>';
}
applyLogoForTheme(isLightOnLoad);

// Theme toggle on click
document.getElementById('themeToggleBtn').addEventListener('click', () => {
    const isLight = document.body.classList.toggle('light');
    localStorage.setItem('docugoTheme', isLight ? 'light' : 'dark');
    document.getElementById('themeToggleBtn').innerHTML = isLight ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    applyLogoForTheme(isLight);
});

function confirmReject() {
    const reason = document.querySelector('textarea[name="reject_reason"]').value.trim();
    if (!reason) {
        alert('Please enter a reason for rejection.');
        return false;
    }
    return confirm('Are you sure you want to REJECT this request?\n\nThe student will be notified immediately with your reason.');
}
</script>
</body>
</html>