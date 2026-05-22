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
    LEFT JOIN (
        SELECT request_id, MAX(payment_date) AS latest_payment
        FROM payment_records
        WHERE status = 'paid'
        GROUP BY request_id
    ) latest_pr ON dr.id = latest_pr.request_id
    LEFT JOIN payment_records pr
        ON pr.request_id = latest_pr.request_id
        AND pr.payment_date = latest_pr.latest_payment
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
    $updateStmt = $conn->prepare("UPDATE claim_stubs SET is_printed = 1, printed_at = NOW() WHERE stub_code = ?");
    if ($updateStmt) {
        $updateStmt->bind_param("s", $stubCode);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

// Fetch request logs
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

// Get unread count for notification bell
$nStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
$nStmt->bind_param("i", $userId);
$nStmt->execute();
$unreadCount = (int)$nStmt->get_result()->fetch_assoc()['c'];
$nStmt->close();

$conn->close();

// Helper functions - only declare if not already declared
if (!function_exists('escape')) {
    function escape($v) { return htmlspecialchars($v ?? ''); }
}
if (!function_exists('formatDate')) {
    function formatDate($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
}
if (!function_exists('formatDateTime')) {
    function formatDateTime($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }
}
// NOTE: paymentBadge() is already defined in request_helper.php - DO NOT redeclare here!

$totalFee  = $stub['fee'] * $stub['copies'];
$fullName  = trim($stub['first_name'] . ' ' . ($stub['middle_name'] ? $stub['middle_name'][0] . '. ' : '') . $stub['last_name']);
$initials  = strtoupper(substr($stub['first_name'],0,1) . substr($stub['last_name'],0,1));

$isPaid    = !empty($stub['official_receipt_number'])
          || in_array($stub['status'], ['paid', 'released']);
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

$initial = strtoupper(substr($stub['first_name'], 0, 1));
$isAlumni = ($stub['user_role'] === 'alumni');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Stub — <?= escape($stub['request_code']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ========== THEME VARIABLES ========== */
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
            --gold:       #ffd700;
            --purple:     #a78bfa;
            --red:        #f87171;
            --blue:       #60a5fa;
            --radius-sm:  8px;
            --radius-md:  12px;
            --radius-lg:  16px;
            --radius-xl:  24px;
            --sidebar-width: 260px;
            --ease-out:   cubic-bezier(0.16, 1, 0.3, 1);
            --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
            
            --sidebar-bg: #0f2a6b;
            --sidebar-border: rgba(255,255,255,0.1);
            --sidebar-text: #b8c9f0;
            --sidebar-text-hover: #ffffff;
            --sidebar-active-bg: rgba(59,107,255,0.25);
            --sidebar-active-color: #ffffff;
            --sidebar-section: #8eabff;
        }

        body.light {
            --bg:         #eef2ff;
            --bg2:        #e2e9ff;
            --bg3:        #d8e2ff;
            --surface:    rgba(255,255,255,0.6);
            --surface-hv: rgba(255,255,255,0.85);
            --border:     rgba(26,62,199,0.1);
            --border-hv:  rgba(26,62,199,0.25);
            --text:       #0c1836;
            --text-muted: #3d5a92;
            --text-dim:   #7a96c4;
            
            --sidebar-bg: #2d4ed6;
            --sidebar-border: rgba(255,255,255,0.15);
            --sidebar-text: #e0e8ff;
            --sidebar-text-hover: #ffffff;
            --sidebar-active-bg: rgba(255,255,255,0.2);
            --sidebar-active-color: #ffffff;
            --sidebar-section: #c7d5ff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            transition: background 0.3s, color 0.3s;
            overflow-x: hidden;
            display: flex;
        }

        /* ========== SIDEBAR ========== */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--sidebar-border);
            display: flex;
            flex-direction: column;
            z-index: 100;
            transition: transform 0.3s var(--ease-out), background 0.3s;
        }

        .sidebar-brand {
            padding: 1.5rem 1.2rem;
            border-bottom: 1px solid var(--sidebar-border);
            margin-bottom: 1rem;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-logo img {
            width: 48px;
            height: 48px;
            object-fit: contain;
            border-radius: 12px;
            transition: transform 0.3s var(--ease-spring);
        }

        .brand-logo img:hover {
            transform: rotate(-5deg) scale(1.05);
        }

        .brand-text {
            flex: 1;
        }

        .brand-name {
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 0.9rem;
            color: white;
            line-height: 1.2;
        }

        .brand-sub {
            font-size: 0.55rem;
            color: rgba(255,255,255,0.7);
            margin-top: 3px;
            letter-spacing: 0.3px;
        }

        .sidebar-menu {
            flex: 1;
            padding: 0 0.8rem;
        }

        .menu-section {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--sidebar-section);
            padding: 0.8rem 0.8rem 0.5rem;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.7rem 0.8rem;
            border-radius: var(--radius-sm);
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s;
            margin-bottom: 2px;
        }

        .menu-item:hover {
            background: var(--sidebar-active-bg);
            color: var(--sidebar-text-hover);
        }

        .menu-item.active {
            background: var(--sidebar-active-bg);
            color: var(--sidebar-active-color);
            border-left: 2px solid white;
        }

        .menu-icon {
            font-size: 1.1rem;
            width: 24px;
        }

        .menu-badge {
            margin-left: auto;
            background: rgba(255,255,255,0.25);
            color: white;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 20px;
        }

        .menu-badge.yellow { background: var(--gold); color: #1a1a2e; }

        .sidebar-footer {
            padding: 1rem 0.8rem;
            border-top: 1px solid var(--sidebar-border);
            margin-top: auto;
        }

        .sidebar-footer a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.7rem 0.8rem;
            color: var(--sidebar-text);
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: all 0.2s;
        }

        .sidebar-footer a:hover {
            background: var(--sidebar-active-bg);
            color: var(--red);
        }

        /* ========== MAIN CONTENT ========== */
        .main {
            margin-left: var(--sidebar-width);
            padding: 1.5rem 2rem;
            min-height: 100vh;
            flex: 1;
        }

        /* Topbar */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.6rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .topbar h2 {
            font-family: 'Sora', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-shrink: 0;
        }

        .topbar-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            border: none;
        }
        .btn:hover { transform: translateY(-1px); opacity: 0.95; }
        .btn-back { background: var(--surface); border: 1px solid var(--border); color: var(--text-muted); }
        .btn-back:hover { background: var(--surface-hv); border-color: var(--accent); }
        .btn-print { background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; }

        /* Notification Bell */
        .notif-wrap { position: relative; }
        .notif-btn {
            position: relative;
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
            transition: transform 0.2s, border-color 0.2s;
            color: var(--gold);
        }
        .notif-btn:hover { transform: scale(1.05); border-color: var(--gold); }
        .notif-btn.has-unread { border-color: var(--gold); animation: bellShake 0.9s ease-in-out 0.4s; }
        @keyframes bellShake {
            0%,100%{transform:rotate(0)} 20%{transform:rotate(-14deg)}
            40%{transform:rotate(14deg)} 60%{transform:rotate(-9deg)} 80%{transform:rotate(9deg)}
        }
        .notif-badge {
            position: absolute; top: -4px; right: -4px;
            background: var(--red); color: #fff;
            font-size: 0.6rem; font-weight: 800;
            min-width: 18px; height: 18px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }
        .notif-badge.hidden { display: none; }

        .user-chip {
            display: flex; align-items: center; gap: 0.5rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 0.35rem 0.85rem 0.35rem 0.45rem;
            font-size: 0.8rem;
            color: var(--text);
        }
        .chip-avatar {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
        }

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
        }
        .theme-toggle:hover { transform: scale(1.1); background: var(--surface-hv); }

        /* Stub Container */
        .stub-wrap {
            max-width: 720px;
            margin: 0 auto;
        }

        .stub-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 1rem;
        }

        /* Stub Header */
        .stub-header {
            background: linear-gradient(135deg, var(--primary), var(--accent));
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

        .school-name { font-size: 0.7rem; opacity: 0.8; margin-bottom: 2px; }
        .system-name { font-size: 1rem; font-weight: 800; letter-spacing: -0.3px; }
        .stub-title { font-size: 0.65rem; opacity: 0.75; margin-top: 2px; }

        .ref-code-label { font-size: 0.65rem; opacity: 0.75; margin-bottom: 2px; }
        .ref-code { font-family: monospace; font-size: 1.1rem; font-weight: 800; letter-spacing: 1px; }

        .status-pill {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
            margin-top: 6px;
            background: rgba(255,255,255,0.2);
            color: #fff;
        }

        .requester-row {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            background: rgba(255,255,255,0.12);
            border-radius: var(--radius-md);
            padding: 0.75rem 1rem;
        }
        .req-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; font-weight: 700; flex-shrink: 0;
        }
        .req-name { font-size: 0.9rem; font-weight: 700; }
        .req-meta { font-size: 0.7rem; opacity: 0.85; margin-top: 2px; }

        /* Stub Body */
        .stub-body { padding: 1.4rem; }

        .fee-box {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border-radius: var(--radius-md);
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .fee-box .fl-label { font-size: 0.68rem; opacity: 0.8; margin-bottom: 3px; }
        .fee-box .fl-amount { font-size: 1.4rem; font-weight: 800; }
        .fee-box .fr { font-size: 0.7rem; opacity: 0.85; text-align: right; line-height: 1.6; }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .info-item {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 0.7rem 1rem;
        }
        .info-item.full { grid-column: 1 / -1; }
        .info-label {
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 3px;
        }
        .info-value {
            font-size: 0.8rem;
            color: var(--text);
            font-weight: 500;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        .badge-paid { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .badge-unpaid { background: rgba(251,191,36,0.15); color: #fbbf24; }

        .cashier-instruction {
            background: rgba(255,215,0,0.08);
            border: 1px solid rgba(255,215,0,0.3);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .ci-header { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
        .ci-icon {
            width: 28px; height: 28px;
            background: var(--gold);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
            color: #1a1a2e;
        }
        .ci-title { font-size: 0.8rem; font-weight: 700; color: var(--gold); }
        .ci-steps { display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem; }
        .ci-step { display: flex; align-items: center; gap: 0.4rem; font-size: 0.72rem; color: var(--gold); }
        .ci-step .cs-num {
            width: 20px; height: 20px;
            background: var(--gold);
            color: #1a1a2e;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.6rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .ci-arrow { color: var(--gold); font-weight: 700; margin: 0 0.2rem; }

        .paid-banner {
            background: rgba(76,217,138,0.1);
            border: 1px solid rgba(76,217,138,0.3);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .paid-banner h4 { font-size: 0.85rem; font-weight: 700; color: var(--green); margin-bottom: 2px; }
        .paid-banner p { font-size: 0.7rem; color: var(--green); }
        .or-number { font-size: 0.9rem; font-weight: 800; color: var(--green); font-family: monospace; }
        .or-label { font-size: 0.65rem; color: var(--green); margin-bottom: 2px; }

        /* Tracker */
        .tracker-section {
            border-top: 1px solid var(--border);
            padding: 1rem;
            background: var(--bg2);
        }
        .tracker-title { font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.75rem; }
        .tracker { position: relative; }
        .tracker-line-bg {
            position: absolute;
            left: 14px; right: 14px; top: 13px;
            height: 4px; background: var(--border);
            z-index: 0; border-radius: 4px;
        }
        .tracker-line-fill {
            position: absolute;
            left: 14px; top: 13px;
            height: 4px; background: linear-gradient(90deg, var(--accent), var(--accent2));
            z-index: 1; border-radius: 4px;
        }
        .tracker-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            z-index: 2;
        }
        .tracker-step { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .step-dot {
            width: 24px; height: 24px; border-radius: 50%;
            background: var(--surface); border: 2px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.6rem; font-weight: 700; color: var(--text-dim);
        }
        .step-dot.done { background: var(--accent); border-color: var(--accent); color: #fff; }
        .step-dot.current { border-color: var(--accent); color: var(--accent); box-shadow: 0 0 0 3px rgba(59,107,255,0.2); }
        .step-label { font-size: 0.55rem; color: var(--text-dim); text-align: center; }
        .step-label.done { color: var(--accent); font-weight: 600; }
        .step-label.current { color: var(--text); font-weight: 700; }

        /* QR Section */
        .qr-section {
            border-top: 2px dashed var(--border);
            padding: 1rem;
            background: var(--surface);
            display: flex;
            align-items: center;
            gap: 1.2rem;
            flex-wrap: wrap;
        }
        .qr-box {
            background: #fff;
            padding: 8px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            flex-shrink: 0;
        }
        .qr-box canvas { display: block; border-radius: 4px; }
        .qr-info { flex: 1; min-width: 180px; }
        .qr-label { font-size: 0.7rem; font-weight: 700; color: var(--accent2); margin-bottom: 4px; }
        .qr-stub-code { font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--text); margin-bottom: 4px; }
        .qr-hint { font-size: 0.65rem; color: var(--text-dim); }
        .qr-scan-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 2px 8px;
            background: rgba(59,107,255,0.15);
            border-radius: 20px;
            font-size: 0.6rem;
            font-weight: 700;
            color: var(--accent);
        }

        /* Log Section */
        .log-section {
            border-top: 1px solid var(--border);
            padding: 1rem;
        }
        .log-title { font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.75rem; }
        .log-item {
            display: flex;
            gap: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border);
            align-items: flex-start;
            font-size: 0.75rem;
        }
        .log-item:last-child { border-bottom: none; }
        .log-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--border);
            flex-shrink: 0;
            margin-top: 5px;
        }
        .log-dot.released { background: var(--purple); }
        .log-dot.paid { background: var(--green); }
        .log-dot.ready { background: var(--gold); }
        .log-dot.approved { background: var(--blue); }
        .log-status { font-weight: 700; color: var(--text); }
        .log-meta { font-size: 0.65rem; color: var(--text-dim); margin-top: 2px; }

        /* Stub Footer */
        .stub-footer {
            background: var(--bg2);
            border-top: 1px solid var(--border);
            padding: 0.75rem 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.65rem;
            color: var(--text-dim);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .info-grid { grid-template-columns: 1fr; }
            .ci-steps { flex-direction: column; align-items: flex-start; gap: 0.4rem; }
            .ci-arrow { display: none; }
        }

        @media print {
            .sidebar, .topbar, .theme-toggle, .no-print { display: none !important; }
            .main { margin-left: 0 !important; padding: 0 !important; }
            body { background: #fff !important; }
            .stub-card { box-shadow: none !important; border: 1px solid #ddd; }
            .qr-section { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <img id="sidebarLogo" src="../wlogo.png" alt="ADFC Logo">
            <div class="brand-text">
                <div class="brand-name">Asian Development<br>Foundation College</div>
                <div class="brand-sub"><?= $isAlumni ? 'Alumni Portal' : 'Student Portal' ?></div>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-section">MAIN</div>
        <a href="dashboard.php" class="menu-item"><span class="menu-icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="menu-icon">📄</span> Request Document</a>
        <a href="my_requests.php" class="menu-item active"><span class="menu-icon">📋</span> My Requests</a>
        <a href="notifications.php" class="menu-item"><span class="menu-icon">🔔</span> Notifications</a>


        <?php if ($isAlumni): ?>
        <div class="menu-section">ALUMNI</div>
        <a href="graduate_tracer.php" class="menu-item"><span class="menu-icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item"><span class="menu-icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item"><span class="menu-icon">🎓</span> Alumni Documents</a>
        <?php endif; ?>

        <div class="menu-section">ACCOUNT</div>
        <a href="profile.php" class="menu-item"><span class="menu-icon">👤</span> Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<main class="main">

    <!-- Topbar -->
    <div class="topbar no-print">
        <h2><i class="fas fa-receipt"></i> Claim Stub</h2>
        <div class="topbar-right">
            <!-- Notification Bell -->
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-btn <?= $unreadCount > 0 ? 'has-unread' : '' ?>" id="notifBtn" onclick="togglePanel(event)">
                    <i class="fas fa-bell"></i>
                    <span class="notif-badge <?= $unreadCount === 0 ? 'hidden' : '' ?>" id="notifBadge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                </button>
            </div>
            <!-- User chip -->
            <div class="user-chip">
                <div class="chip-avatar"><?= $initial ?></div>
                <strong><?= escape($stub['first_name'] . ' ' . $stub['last_name']) ?></strong>
            </div>
            <div class="topbar-actions">
                <a href="my_requests.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back</a>
                <button class="btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print Stub</button>
            </div>
        </div>
    </div>

    <div class="stub-wrap">

        <div class="stub-card">

            <!-- STUB HEADER -->
            <div class="stub-header">
                <div class="stub-header-top">
                    <div>
                        <div class="school-name"><i class="fas fa-university"></i> Asian Development Foundation College</div>
                        <div class="system-name">DocuGo — Claim Stub</div>
                        <div class="stub-title">Online Document Request System</div>
                    </div>
                    <div class="stub-header-right">
                        <div class="ref-code-label">Reference Number</div>
                        <div class="ref-code"><?= escape($stub['request_code']) ?></div>
                        <div><span class="status-pill"><?= $sc['label'] ?></span></div>
                    </div>
                </div>

                <!-- Requester -->
                <div class="requester-row">
                    <div class="req-avatar"><?= $initials ?></div>
                    <div>
                        <div class="req-name"><?= escape($fullName) ?></div>
                        <div class="req-meta">
                            <i class="fas fa-envelope"></i> <?= escape($stub['email']) ?>
                            <?php if ($stub['student_id']): ?>
                                &nbsp;·&nbsp; <i class="fas fa-id-card"></i> ID: <?= escape($stub['student_id']) ?>
                            <?php endif; ?>
                            <?php if ($stub['course']): ?>
                                &nbsp;·&nbsp; <i class="fas fa-graduation-cap"></i> <?= escape($stub['course']) ?>
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
                        <i class="fas fa-file-alt"></i> <?= escape($stub['doc_type']) ?><br>
                        ₱<?= number_format($stub['fee'], 2) ?> × <?= $stub['copies'] ?> copy(s)
                        <br><?= paymentBadge($isPaid) ?>
                    </div>
                </div>

                <!-- Document details -->
                <div class="info-grid">
                    <div class="info-item"><div class="info-label"><i class="fas fa-file"></i> Document Type</div><div class="info-value"><?= escape($stub['doc_type']) ?></div></div>
                    <div class="info-item"><div class="info-label"><i class="fas fa-copy"></i> Copies</div><div class="info-value"><?= $stub['copies'] ?></div></div>
                    <div class="info-item"><div class="info-label"><i class="fas fa-truck"></i> Release Mode</div><div class="info-value"><?= ucfirst(escape($stub['release_mode'])) ?></div></div>
                    <div class="info-item"><div class="info-label"><i class="fas fa-calendar"></i> Date Submitted</div><div class="info-value"><?= formatDate($stub['requested_at']) ?></div></div>
                    <div class="info-item"><div class="info-label"><i class="fas fa-qrcode"></i> Stub Code</div><div class="info-value" style="font-family:monospace;"><?= escape($stub['stub_code']) ?></div></div>
                    <div class="info-item"><div class="info-label"><i class="fas fa-calendar-alt"></i> Preferred Release</div><div class="info-value"><?= formatDate($stub['preferred_release_date']) ?></div></div>
                    <div class="info-item full"><div class="info-label"><i class="fas fa-quote-left"></i> Purpose</div><div class="info-value"><?= escape($stub['purpose']) ?></div></div>
                </div>

                <!-- PAID banner -->
                <?php if ($isPaid || $isReleased): ?>
                <div class="paid-banner">
                    <div>
                        <h4><i class="fas fa-check-circle"></i> Payment Recorded</h4>
                        <p><?= $isReleased ? 'Document has been successfully released.' : 'Payment confirmed. Document will be released shortly.' ?></p>
                    </div>
                    <div class="text-right">
                        <div class="or-label">Official Receipt #</div>
                        <div class="or-number"><?= escape($stub['official_receipt_number'] ?? '—') ?></div>
                    </div>
                </div>

                <!-- Pay at cashier instruction -->
                <?php elseif ($isReady): ?>
                <div class="cashier-instruction">
                    <div class="ci-header">
                        <div class="ci-icon">💰</div>
                        <div class="ci-title">Pay at the Cashier to Claim Your Document</div>
                    </div>
                    <div class="ci-steps">
                        <div class="ci-step"><div class="cs-num">1</div> Present this stub at the Registrar's Office</div>
                        <div class="ci-arrow">→</div>
                        <div class="ci-step"><div class="cs-num">2</div> Pay ₱<?= number_format($totalFee, 2) ?> at the cashier</div>
                        <div class="ci-arrow">→</div>
                        <div class="ci-step"><div class="cs-num">3</div> Staff records payment & releases document</div>
                    </div>
                </div>
                <?php else: ?>
                <div style="background:rgba(59,107,255,0.08); border:1px solid rgba(59,107,255,0.2); border-radius:var(--radius-md); padding:1rem; margin-bottom:1rem;">
                    <div style="font-weight:700; color:var(--accent); margin-bottom:3px;"><i class="fas fa-clock"></i> Your document is being processed</div>
                    <div style="font-size:0.75rem; color:var(--text-muted);">You will be notified once your document is ready for pickup. Keep this stub for your reference.</div>
                </div>
                <?php endif; ?>

            </div>

            <!-- STATUS TRACKER -->
            <?php
            $statusSteps = [
                ['key'=>'pending', 'label'=>'Submitted'],
                ['key'=>'approved', 'label'=>'Approved'],
                ['key'=>'processing', 'label'=>'Processing'],
                ['key'=>'ready', 'label'=>'Ready'],
                ['key'=>'paid', 'label'=>'Paid'],
                ['key'=>'released', 'label'=>'Released'],
            ];
            $statusOrder = ['pending'=>0,'approved'=>1,'processing'=>2,'ready'=>3,'paid'=>4,'released'=>5];
            $currentIdx  = $statusOrder[$stub['status']] ?? 0;
            $fillPct     = ($currentIdx / (count($statusSteps)-1)) * 100;
            ?>
            <div class="tracker-section">
                <div class="tracker-title"><i class="fas fa-chart-line"></i> Request Status</div>
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
                            <div class="step-dot <?= $cls ?>"><?= $done ? '<i class="fas fa-check"></i>' : ($i + 1) ?></div>
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
                    <div class="qr-label"><i class="fas fa-qrcode"></i> Scan for Faster Verification</div>
                    <div class="qr-stub-code"><?= escape($stub['stub_code']) ?></div>
                    <div class="qr-hint">Staff can scan this QR code to instantly verify request details without manual entry.</div>
                    <div class="qr-scan-badge"><i class="fas fa-camera"></i> Scan at counter</div>
                </div>
            </div>

            <!-- ACTIVITY LOG -->
            <?php if ($logs && $logs->num_rows > 0): ?>
            <div class="log-section">
                <div class="log-title"><i class="fas fa-history"></i> Activity Log</div>
                <?php while ($log = $logs->fetch_assoc()): ?>
                <div class="log-item">
                    <div class="log-dot <?= escape($log['new_status']) ?>"></div>
                    <div>
                        <div class="log-status">Status changed to: <strong><?= ucfirst(escape($log['new_status'])) ?></strong></div>
                        <div class="log-meta"><i class="far fa-clock"></i> <?= formatDateTime($log['changed_at']) ?>
                            <?php if ($log['first_name']): ?> · By <?= escape($log['first_name'] . ' ' . $log['last_name']) ?><?php endif; ?>
                            <?php if ($log['notes']): ?> · <?= escape($log['notes']) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>

            <!-- STUB FOOTER -->
            <div class="stub-footer">
                <div><i class="fas fa-university"></i> DocuGo — Asian Development Foundation College</div>
                <div><?= $stub['is_printed'] ? '<i class="fas fa-print"></i> Printed: ' . formatDateTime($stub['printed_at']) : '<i class="fas fa-clock"></i> Not yet printed' ?></div>
            </div>

        </div>

        <div class="no-print" style="text-align:center; font-size:0.7rem; color:var(--text-dim); margin-top:0.5rem;">
            <i class="fas fa-info-circle"></i> Print or save this stub as PDF for use at the Registrar's Office.
        </div>

    </div>
</main>

<!-- Theme Toggle -->
<div class="theme-toggle" id="themeToggleBtn">
    <i class="fas fa-moon"></i>
</div>

<!-- QR Code Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Theme Toggle
const applyLogoForTheme = (isLight) => {
    const logoImg = document.getElementById('sidebarLogo');
    if (logoImg) logoImg.src = isLight ? '../wlogo.png' : '../wlogo.png';
};

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

document.getElementById('themeToggleBtn').addEventListener('click', () => {
    const isLight = document.body.classList.toggle('light');
    localStorage.setItem('docugoTheme', isLight ? 'light' : 'dark');
    document.getElementById('themeToggleBtn').innerHTML = isLight ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
    applyLogoForTheme(isLight);
});

// Notification panel functions
let panelOpen = false;

function togglePanel(e) {
    e.stopPropagation();
    panelOpen = !panelOpen;
    const panel = document.getElementById('notifPanel');
    if (panel) panel.classList.toggle('open', panelOpen);
}

document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifWrap');
    if (wrap && !wrap.contains(e.target) && panelOpen) {
        const panel = document.getElementById('notifPanel');
        if (panel) panel.classList.remove('open');
        panelOpen = false;
    }
});

// Generate QR Code
const qrPayload = JSON.stringify({
    stub:   "<?= escape($stub['stub_code']) ?>",
    code:   "<?= escape($stub['request_code']) ?>",
    req_id: <?= intval($stub['request_id']) ?>,
    name:   "<?= escape($fullName) ?>",
    doc:    "<?= escape($stub['doc_type']) ?>",
    copies: <?= intval($stub['copies']) ?>,
    fee:    "<?= number_format($totalFee, 2) ?>",
    status: "<?= escape($stub['status']) ?>",
    system: "DocuGo-ADFC"
});

const qrContainer = document.getElementById("qrcode");
if (qrContainer && typeof QRCode !== 'undefined') {
    new QRCode(qrContainer, {
        text:         qrPayload,
        width:        130,
        height:       130,
        colorDark:    "#111827",
        colorLight:   "#ffffff",
        correctLevel: QRCode.CorrectLevel.H
    });
}
</script>
</body>
</html>