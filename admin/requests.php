<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
requireAdmin();

$conn   = getConnection();
$userId = $_SESSION['user_id'];

// ── Filters ──────────────────────────────────────────────────
$status  = $_GET['status'] ?? '';
$search  = trim($_GET['q'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// ── Handle quick status actions (POST) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $requestId = intval($_POST['request_id'] ?? 0);
    $msg = '';
    $msgType = 'error';

    if ($requestId > 0) {
        if ($action === 'approve') {
            $checkStmt = $conn->prepare("
                SELECT dt.requires_signature 
                FROM document_requests dr 
                JOIN document_types dt ON dr.document_type_id = dt.id 
                WHERE dr.id = ?
            ");
            $checkStmt->bind_param("i", $requestId);
            $checkStmt->execute();
            $checkRow = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            
            if ($checkRow && $checkRow['requires_signature']) {
                $newStatus = 'for_signature';
            } else {
                $newStatus = 'approved';
            }
        } else {
            $actionMap = [
                'process'      => 'processing',
                'ready'        => 'ready',
                'for_sign'     => 'for_signature',
                'cancel'       => 'cancelled',
            ];
            $newStatus = $actionMap[$action] ?? null;
        }
        
        if ($newStatus) {
            $result = updateRequestStatus($conn, $requestId, $newStatus, $userId);
            $msg = $result['message'];
            $msgType = $result['success'] ? 'success' : 'error';
        }
    }

    header("Location: requests.php?" . http_build_query([
        'status'  => $status,
        'q'       => $search,
        'page'    => $page,
        'msg'     => $msg,
        'msgtype' => $msgType,
    ]));
    exit();
}

// ── Build query ───────────────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($status !== '') {
    $where[]  = "dr.status = ?";
    $params[] = $status;
    $types   .= 's';
}

if ($search !== '') {
    $like     = "%$search%";
    $where[]  = "(u.first_name LIKE ? OR u.last_name LIKE ? OR dr.request_code LIKE ? OR u.email LIKE ?)";
    $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like;
    $types   .= 'ssss';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countSQL  = "SELECT COUNT(*) AS total FROM document_requests dr JOIN users u ON dr.user_id = u.id $whereSQL";
$countStmt = $conn->prepare($countSQL);
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows  = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $perPage));
$countStmt->close();

// Fetch
$params[] = $perPage;
$params[] = $offset;
$types   .= 'ii';

$sql  = "
    SELECT dr.*, 
           u.first_name, u.last_name, u.email, u.student_id,
           dt.name AS doc_type, dt.fee,
           (dt.fee * dr.copies) AS total_fee,
           pr.official_receipt_number, pr.payment_date,
           cs.stub_code
    FROM document_requests dr
    JOIN users u  ON dr.user_id = u.id
    JOIN document_types dt ON dr.document_type_id = dt.id
    LEFT JOIN (
        SELECT request_id, MAX(payment_date) as latest_payment
        FROM payment_records
        WHERE status = 'paid'
        GROUP BY request_id
    ) latest_pr ON dr.id = latest_pr.request_id
    LEFT JOIN payment_records pr 
        ON pr.request_id = latest_pr.request_id 
        AND pr.payment_date = latest_pr.latest_payment
    LEFT JOIN claim_stubs cs ON dr.id = cs.request_id
    $whereSQL
    ORDER BY dr.requested_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result();
$stmt->close();

// ── Status counts for tabs & stats ───────────────────────────
$tabCounts = [];
$tabResult = $conn->query("SELECT status, COUNT(*) AS c FROM document_requests GROUP BY status");
while ($t = $tabResult->fetch_assoc()) {
    $tabCounts[$t['status']] = $t['c'];
}
$tabCounts['all'] = array_sum($tabCounts);

// Additional stats for sidebar badges
$pendingReqs   = $tabCounts['pending'] ?? 0;
$pendingAccs   = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];

$conn->close();

// Helper functions - ONLY declare if they don't exist in request_helper.php
if (!function_exists('escape')) {
    function escape($v) { return htmlspecialchars($v ?? ''); }
}
if (!function_exists('formatDate')) {
    function formatDate($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
}
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        if (!$datetime) return '—';
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff/60) . 'm ago';
        if ($diff < 86400) return floor($diff/3600) . 'h ago';
        return floor($diff/86400) . 'd ago';
    }
}
// NOTE: statusBadge() and paymentBadge() are already defined in request_helper.php
// Do NOT redeclare them here!
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Requests — ADFC DocuGo</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
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
            --bg3:        #0e1c42;
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
            --card-bg: rgba(255,255,255,0.05);
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
            --card-bg: #ffffff;
            
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

        .menu-badge.yellow { background: var(--yellow); color: #1a1a2e; }

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
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .topbar-left h1 {
            font-family: 'Sora', sans-serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.2rem;
        }

        .topbar-left p {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .admin-info, .topbar-date {
            background: var(--surface);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            border: 1px solid var(--border);
        }

        /* Alert */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 10px;
            margin-bottom: 1.2rem;
            font-size: 0.85rem;
        }
        .alert-success { background: rgba(76,217,138,0.15); color: var(--green); border-left: 4px solid var(--green); }
        .alert-error { background: rgba(248,113,113,0.15); color: var(--red); border-left: 4px solid var(--red); }

        /* Stats Mini */
        .stats-mini {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1rem 1.1rem;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); border-color: var(--border-hv); }
        .stat-num { font-family: 'Sora', sans-serif; font-size: 1.8rem; font-weight: 800; color: var(--text); line-height: 1; }
        .stat-label { font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 5px; }
        .stat-sub { font-size: 0.65rem; color: var(--text-dim); margin-top: 6px; padding-top: 5px; border-top: 1px solid var(--border); }

        /* Tabs */
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            background: var(--surface);
            padding: 0.5rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.2rem;
            border: 1px solid var(--border);
        }
        .tab {
            padding: 0.45rem 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: var(--radius-sm);
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .tab:hover { background: var(--bg2); color: var(--accent); }
        .tab.active { background: var(--accent); color: #fff; }
        .cnt {
            background: rgba(0,0,0,0.1);
            padding: 2px 7px;
            border-radius: 20px;
            font-size: 0.6rem;
            font-weight: 700;
        }
        .tab.active .cnt { background: rgba(255,255,255,0.2); }

        /* Filters */
        .filters {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1.2rem;
        }
        .search-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .search-form input {
            padding: 0.5rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            background: var(--bg2);
            color: var(--text);
            width: 240px;
        }
        .search-form button, .btn-clear {
            padding: 0.5rem 1rem;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-clear { background: var(--text-dim); }
        .btn-clear:hover { opacity: 0.85; }

        /* Card */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.2rem;
            border-bottom: 1px solid var(--border);
        }
        .card-header h2 {
            font-family: 'Sora', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
        }

        /* Table */
        table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        th {
            text-align: left;
            padding: 0.75rem 1rem;
            background: var(--bg2);
            color: var(--text-dim);
            font-weight: 700;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-muted);
            vertical-align: middle;
        }
        tr:hover td { background: var(--surface-hv); }

        .code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.7rem;
            background: var(--bg2);
            padding: 2px 6px;
            border-radius: 6px;
        }
        .user-name { font-weight: 600; color: var(--text); font-size: 0.8rem; }
        .user-meta { font-size: 0.65rem; color: var(--text-dim); margin-top: 2px; }
        .fee { font-weight: 700; color: var(--green); }

        /* Buttons */
        .actions { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .btn {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.68rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.12s;
            font-family: inherit;
        }
        .btn-view { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .btn-approve { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .btn-process { background: rgba(59,107,255,0.15); color: #3b6bff; }
        .btn-ready { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .btn-release { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .btn-stub { background: rgba(167,139,250,0.15); color: #a78bfa; }
        .btn-cancel { background: rgba(248,113,113,0.15); color: #f87171; }
        .btn:hover { filter: brightness(0.9); transform: translateY(-1px); }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.3rem;
            margin-top: 1.5rem;
        }
        .pagination a, .pagination span {
            padding: 0.4rem 0.8rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.75rem;
        }
        .pagination .active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        .empty-state { text-align: center; padding: 2.5rem; color: var(--text-dim); }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: 0.2s;
            z-index: 1000;
        }
        .modal-overlay.open { visibility: visible; opacity: 1; }
        .modal {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 460px;
            padding: 1.5rem;
        }
        .modal h2 { font-family: 'Sora', sans-serif; font-size: 1.1rem; margin-bottom: 0.5rem; color: var(--text); }
        .field { margin: 1rem 0; }
        .field label { display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 4px; color: var(--text-muted); }
        .field input, .field textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            background: var(--bg2);
            color: var(--text);
        }
        .modal-actions { display: flex; gap: 0.8rem; justify-content: flex-end; margin-top: 1rem; }
        .modal-cancel, .modal-confirm {
            padding: 0.5rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            border: none;
        }
        .modal-cancel { background: var(--surface); color: var(--text-muted); }
        .modal-confirm { background: var(--green); color: #fff; }

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
        }
        .theme-toggle:hover { transform: scale(1.1); background: var(--surface-hv); }

        @media (max-width: 1024px) {
            .stats-mini { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .stats-mini { grid-template-columns: 1fr; }
            .tabs { overflow-x: auto; flex-wrap: nowrap; }
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
                <div class="brand-sub">DocuGo Admin Panel</div>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-section">MAIN</div>
        <a href="dashboard.php" class="menu-item">
            <span class="menu-icon">🏠</span> Dashboard
        </a>
        <a href="requests.php" class="menu-item active">
            <span class="menu-icon">📄</span> Document Requests
            <?php if ($pendingReqs > 0): ?>
                <span class="menu-badge yellow"><?= $pendingReqs ?></span>
            <?php endif; ?>
        </a>
        <a href="accounts.php" class="menu-item">
            <span class="menu-icon">👥</span> User Accounts
            <?php if ($pendingAccs > 0): ?>
                <span class="menu-badge"><?= $pendingAccs ?></span>
            <?php endif; ?>
        </a>

        <div class="menu-section">RECORDS</div>
        <a href="alumni.php" class="menu-item"><span class="menu-icon">🎓</span> Alumni</a>
        <a href="tracer.php" class="menu-item"><span class="menu-icon">📊</span> Graduate Tracer</a>
        <a href="reports.php" class="menu-item"><span class="menu-icon">📈</span> Reports</a>

        <div class="menu-section">COMMUNICATION</div>
        <a href="announcements.php" class="menu-item"><span class="menu-icon">📢</span> Announcements</a>

        <div class="menu-section">SETTINGS</div>
        <a href="document_types.php" class="menu-item"><span class="menu-icon">⚙️</span> Document Types</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h1>Document Requests</h1>
            <p>Manage and process all document requests from students and alumni.</p>
        </div>
        <div class="topbar-right">
            <div class="admin-info"><i class="fas fa-user-circle"></i> <strong><?= escape($_SESSION['user_name']) ?></strong></div>
            <div class="topbar-date"><i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= escape($_GET['msgtype'] ?? 'success') ?>">
        <?= escape($_GET['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <div class="stats-mini">
        <div class="stat-card"><div class="stat-num"><?= $tabCounts['pending'] ?? 0 ?></div><div class="stat-label">Pending Approval</div><div class="stat-sub">Awaiting review</div></div>
        <div class="stat-card"><div class="stat-num"><?= ($tabCounts['approved'] ?? 0) + ($tabCounts['for_signature'] ?? 0) ?></div><div class="stat-label">In Review</div><div class="stat-sub">Approved / For Signature</div></div>
        <div class="stat-card"><div class="stat-num"><?= ($tabCounts['processing'] ?? 0) + ($tabCounts['ready'] ?? 0) ?></div><div class="stat-label">Active</div><div class="stat-sub">Processing + Ready</div></div>
        <div class="stat-card"><div class="stat-num"><?= $tabCounts['released'] ?? 0 ?></div><div class="stat-label">Completed</div><div class="stat-sub">Successfully released</div></div>
    </div>

    <!-- Status Tabs -->
    <div class="tabs">
        <?php
        $tabs = [
            ''           => ['All', $tabCounts['all'] ?? 0],
            'pending'    => ['Pending', $tabCounts['pending'] ?? 0],
            'approved'   => ['Approved', $tabCounts['approved'] ?? 0],
            'for_signature' => ['For Signature', $tabCounts['for_signature'] ?? 0],
            'processing' => ['Processing', $tabCounts['processing'] ?? 0],
            'ready'      => ['Ready', $tabCounts['ready'] ?? 0],
            'released'   => ['Released', $tabCounts['released'] ?? 0],
            'cancelled'  => ['Cancelled', $tabCounts['cancelled'] ?? 0],
        ];
        foreach ($tabs as $val => $data):
            $label = $data[0];
            $cnt = $data[1];
            $active = ($status === $val) ? 'active' : '';
            $url = '?' . http_build_query(['status' => $val, 'q' => $search]);
        ?>
            <a href="<?= $url ?>" class="tab <?= $active ?>"><?= $label ?> <span class="cnt"><?= $cnt ?></span></a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div style="font-size:0.75rem; color:var(--text-muted);">Showing <strong><?= $totalRows ?></strong> request<?= $totalRows != 1 ? 's' : '' ?></div>
        <form method="GET" class="search-form">
            <input type="hidden" name="status" value="<?= escape($status) ?>">
            <input type="text" name="q" placeholder="Search name, code, email…" value="<?= escape($search) ?>">
            <button type="submit"><i class="fas fa-search"></i> Search</button>
            <?php if ($search): ?>
                <a href="?status=<?= escape($status) ?>" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Requests Table -->
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-list"></i> Request List</h2></div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr><th>Code</th><th>Requester</th><th>Document</th><th>Fee</th><th>Status</th><th>Payment</th><th>Date</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if ($requests->num_rows > 0): ?>
                    <?php while ($r = $requests->fetch_assoc()): ?>
                        <tr>
                            <td><span class="code"><?= escape($r['request_code']) ?></span></td>
                            <td><div class="user-name"><?= escape($r['first_name'] . ' ' . $r['last_name']) ?></div><div class="user-meta"><?= escape($r['email']) ?></div><?php if ($r['student_id']): ?><div class="user-meta">ID: <?= escape($r['student_id']) ?></div><?php endif; ?></td>
                            <td><?= escape($r['doc_type']) ?><div class="user-meta"><?= $r['copies'] ?> copy(s)</div></td>
                            <td><span class="fee">₱<?= number_format($r['total_fee'], 2) ?></span></td>
                            <td><?= statusBadge($r['status']) ?></td>
                            <td><?php $isPaid = !empty($r['official_receipt_number']) || $r['status'] === 'released'; echo paymentBadge($isPaid); ?></td>
                            <td><?= formatDate($r['requested_at']) ?><?php if ($r['payment_date']): ?><div class="user-meta">Paid: <?= formatDate($r['payment_date']) ?></div><?php endif; ?></td>
                            <td><div class="actions">
                                <a href="request_detail.php?id=<?= $r['id'] ?>" class="btn btn-view"><i class="fas fa-eye"></i> View</a>
                                <?php if ($r['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline;"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-approve" onclick="return confirm('Approve this request?')"><i class="fas fa-check"></i> Approve</button></form>
                                <?php endif; ?>
                                <?php if (in_array($r['status'], ['for_signature', 'approved'])): ?>
                                <form method="POST" style="display:inline;"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="process"><button class="btn btn-process" onclick="return confirm('Mark as Processing?')"><i class="fas fa-cog"></i> Process</button></form>
                                <?php endif; ?>
                                <?php if ($r['status'] === 'processing'): ?>
                                <form method="POST" style="display:inline;"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="ready"><button class="btn btn-ready" onclick="return confirm('Mark as Ready for pickup?')"><i class="fas fa-clipboard-list"></i> Ready</button></form>
                                <?php endif; ?>
                                <?php if ($r['status'] === 'ready'): ?>
                                <button class="btn btn-release" onclick="openPayModal(<?= $r['id'] ?>, '<?= escape($r['request_code']) ?>', <?= $r['total_fee'] ?>, '<?= escape($r['first_name'] . ' ' . $r['last_name']) ?>')"><i class="fas fa-credit-card"></i> Pay & Release</button>
                                <?php endif; ?>
                                <?php if ($r['stub_code']): ?>
                                <a href="../student/claim_stub.php?code=<?= escape($r['stub_code']) ?>&admin=1" target="_blank" class="btn btn-stub"><i class="fas fa-receipt"></i> Stub</a>
                                <?php endif; ?>
                                <?php if (!in_array($r['status'], ['released', 'cancelled'])): ?>
                                <form method="POST" style="display:inline;"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-cancel" onclick="return confirm('Cancel this request?')"><i class="fas fa-times"></i> Cancel</button></form>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state"><td colspan="8">✨ No requests found.<?php if ($search): ?> Try a different search.<?php endif; ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$page-1]) ?>"><i class="fas fa-chevron-left"></i> Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$i]) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$page+1]) ?>">Next <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>

<!-- Pay & Release Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <h2><i class="fas fa-credit-card"></i> Pay & Release Document</h2>
        <p id="modalDesc" style="color: var(--text-muted); margin-bottom: 0.5rem; font-size:0.8rem;">Record payment and release the document.</p>
        <form method="POST" action="pay_release.php">
            <input type="hidden" name="request_id" id="modalRequestId">
            <div class="field">
                <label>Official Receipt Number <span style="color:var(--red);">*</span></label>
                <input type="text" name="receipt_number" id="modalReceipt" placeholder="e.g. OR-2024-00123" required>
            </div>
            <div class="field">
                <label>Amount to Collect</label>
                <input type="text" id="modalAmount" readonly style="background:var(--bg2); font-weight:700; color:var(--green);">
            </div>
            <div class="field">
                <label>Notes (optional)</label>
                <textarea name="notes" placeholder="Any additional notes…" rows="2"></textarea>
            </div>
            <div style="background:rgba(251,191,36,0.1); border-left:4px solid var(--yellow); border-radius:8px; padding:0.75rem; margin-bottom:0.5rem; font-size:0.75rem; color:var(--yellow);">
                <i class="fas fa-exclamation-triangle"></i> This action will record payment and release the document. Cannot be undone.
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-cancel" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="modal-confirm"><i class="fas fa-check"></i> Confirm Payment & Release</button>
            </div>
        </form>
    </div>
</div>

<!-- Theme Toggle -->
<div class="theme-toggle" id="themeToggleBtn">
    <i class="fas fa-moon"></i>
</div>

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

// Modal functions
function openPayModal(id, code, fee, name) {
    document.getElementById('modalRequestId').value = id;
    document.getElementById('modalDesc').innerHTML = `Request ${code} — ${name}`;
    document.getElementById('modalAmount').value = '₱' + parseFloat(fee).toFixed(2);
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