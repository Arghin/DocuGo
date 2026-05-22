<?php
require_once '../includes/config.php';
require_once '../includes/announcement_helper.php';
requireAdmin();

$conn = getConnection();

// ── Stats ────────────────────────────────────────────────
$totalUsers     = $conn->query("SELECT COUNT(*) as c FROM users WHERE role IN ('student','alumni')")->fetch_assoc()['c'];
$totalStudents  = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'student'")->fetch_assoc()['c'];
$totalAlumni    = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'alumni'")->fetch_assoc()['c'];
$pendingReqs    = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$approvedReqs   = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'approved'")->fetch_assoc()['c'];
$processingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'processing'")->fetch_assoc()['c'];
$readyReqs      = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'ready'")->fetch_assoc()['c'];
$releasedReqs   = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'released'")->fetch_assoc()['c'];
$totalReqs      = $conn->query("SELECT COUNT(*) as c FROM document_requests")->fetch_assoc()['c'];
$pendingAccs    = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];
$totalRevenue   = $conn->query("SELECT COALESCE(SUM(amount),0) as s FROM payment_records WHERE status='paid'")->fetch_assoc()['s'];

$paidReqs = $conn->query("
    SELECT COUNT(DISTINCT dr.id) as c
    FROM document_requests dr
    WHERE EXISTS (
        SELECT 1 FROM payment_records pr WHERE pr.request_id = dr.id
    )
")->fetch_assoc()['c'];

// ── Latest requests ──────────────────────────────────────
$latestReqs = $conn->query("
    SELECT dr.request_code, dr.status, dr.requested_at,
           u.first_name, u.last_name, u.role as user_role,
           dt.name as doc_type, dt.fee, dr.copies,
           pr.official_receipt_number
    FROM document_requests dr
    JOIN users u ON dr.user_id = u.id
    JOIN document_types dt ON dr.document_type_id = dt.id
    LEFT JOIN (
        SELECT request_id, MAX(payment_date) AS latest_payment
        FROM payment_records
        WHERE status = 'paid'
        GROUP BY request_id
    ) latest_pr ON dr.id = latest_pr.request_id
    LEFT JOIN payment_records pr
        ON pr.request_id = latest_pr.request_id
        AND pr.payment_date = latest_pr.latest_payment
    ORDER BY dr.requested_at DESC
    LIMIT 6
");

// ── Pending accounts ─────────────────────────────────────
$pendingAccounts = $conn->query("
    SELECT id, first_name, last_name, email, role, created_at
    FROM users WHERE status = 'pending'
    ORDER BY created_at DESC
    LIMIT 5
");

// ── Recent activity (request logs) ──────────────────────
$recentActivity = $conn->query("
    SELECT rl.new_status, rl.changed_at, rl.notes,
           dr.request_code,
           u.first_name as req_fn, u.last_name as req_ln,
           staff.first_name as staff_fn
    FROM request_logs rl
    JOIN document_requests dr ON rl.request_id = dr.id
    JOIN users u ON dr.user_id = u.id
    LEFT JOIN users staff ON rl.changed_by = staff.id
    ORDER BY rl.changed_at DESC
    LIMIT 5
");

// ── Recent Announcements ───────────────────────────────────
$recentAnnouncements = getAnnouncements($conn, null, 3);

$conn->close();

// Helper functions
function escape($v) { return htmlspecialchars($v ?? ''); }
function timeAgo($d){
    if (!$d) return '—';
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
    <title>Admin Dashboard — ADFC DocuGo</title>
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
            
            /* Sidebar colors - Dark mode (dark blue) */
            --sidebar-bg: #0f2a6b;
            --sidebar-border: rgba(255,255,255,0.1);
            --sidebar-text: #b8c9f0;
            --sidebar-text-hover: #ffffff;
            --sidebar-active-bg: rgba(59,107,255,0.25);
            --sidebar-active-color: #ffffff;
            --sidebar-section: #8eabff;
        }

        /* Light mode sidebar - vibrant blue */
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
            
            /* Sidebar colors - Light mode vibrant blue */
            --sidebar-bg: #2d4ed6;
            --sidebar-border: rgba(255,255,255,0.15);
            --sidebar-text: #e0e8ff;
            --sidebar-text-hover: #ffffff;
            --sidebar-active-bg: rgba(255,255,255,0.2);
            --sidebar-active-color: #ffffff;
            --sidebar-section: #c7d5ff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            transition: background 0.3s, color 0.3s;
            overflow-x: hidden;
        }

        /* ========== SIDEBAR - BLUE THEME WITH LOGO ========== */
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

        .menu-badge.yellow {
            background: var(--yellow);
            color: #1a1a2e;
        }

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
            color: #f87171;
        }

        /* ========== MAIN CONTENT ========== */
        .main {
            margin-left: var(--sidebar-width);
            padding: 1.5rem 2rem;
            min-height: 100vh;
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

        /* Pending Alert */
        .pending-alert {
            background: linear-gradient(135deg, rgba(251,191,36,0.15), rgba(251,191,36,0.05));
            border: 1px solid rgba(251,191,36,0.3);
            border-radius: var(--radius-md);
            padding: 0.8rem 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
        }

        .pa-text {
            font-size: 0.85rem;
            color: var(--yellow);
        }

        .btn-sm {
            background: var(--yellow);
            color: #1a1a2e;
            padding: 0.4rem 1rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 700;
            transition: transform 0.2s;
        }

        .btn-sm:hover {
            transform: translateY(-1px);
        }

        /* Stats Grid */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.2rem;
            margin-bottom: 1.8rem;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.2rem;
            transition: transform 0.2s, border-color 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            border-color: var(--border-hv);
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.8rem;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }

        .stat-icon.blue { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .stat-icon.yellow { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .stat-icon.green { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .stat-icon.purple { background: rgba(167,139,250,0.15); color: #a78bfa; }

        .stat-change {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 20px;
            background: rgba(76,217,138,0.15);
            color: #4cd98a;
        }

        .stat-change.warn {
            background: rgba(251,191,36,0.15);
            color: #fbbf24;
        }

        .stat-num {
            font-family: 'Sora', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
            margin-bottom: 0.2rem;
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-sub {
            font-size: 0.7rem;
            color: var(--text-dim);
            margin-top: 0.3rem;
        }

        /* Status Bar */
        .status-bar-wrap {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1rem 1.2rem;
            margin-bottom: 1.8rem;
        }

        .status-bar-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 0.8rem;
        }

        .status-bar {
            display: flex;
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 0.8rem;
        }

        .status-bar-seg {
            height: 100%;
        }

        .status-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
        }

        /* Grid Layout */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 1.5rem;
        }

        /* Cards */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.2rem;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header-left {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .card-header h2 {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
        }

        .card-header a {
            color: var(--accent2);
            text-decoration: none;
            font-size: 0.75rem;
        }

        .card-header a:hover {
            text-decoration: underline;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 0.8rem 1.2rem;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-dim);
            border-bottom: 1px solid var(--border);
        }

        td {
            padding: 0.8rem 1.2rem;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-muted);
        }

        .td-name {
            font-weight: 600;
            color: var(--text);
        }

        .td-sub {
            font-size: 0.7rem;
            color: var(--text-dim);
        }

        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge-pending { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-processing { background: rgba(14,165,233,0.15); color: #0ea5e9; }
        .badge-approved { background: rgba(59,107,255,0.15); color: #3b6bff; }
        .badge-ready { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-released { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .badge-student, .badge-alumni { background: rgba(96,165,250,0.15); color: #60a5fa; }

        .empty-row td {
            text-align: center;
            padding: 2rem;
            color: var(--text-dim);
        }

        /* Revenue Card */
        .revenue-card {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            text-align: center;
        }

        .revenue-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 0.5rem;
        }

        .revenue-amount {
            font-family: 'Sora', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            color: white;
            margin-bottom: 0.3rem;
        }

        .revenue-sub {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.6);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.6rem;
            padding: 1rem 1.2rem 1.2rem;
        }

        .qa-btn {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.7rem;
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .qa-btn:hover {
            background: var(--surface-hv);
            border-color: var(--border-hv);
            transform: translateY(-2px);
        }

        .qa-btn-icon {
            font-size: 1.1rem;
        }

        /* Activity Feed */
        .activity-feed {
            padding: 0.8rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .activity-item {
            display: flex;
            gap: 0.8rem;
        }

        .activity-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-top: 6px;
        }

        .activity-dot.pending { background: #fbbf24; }
        .activity-dot.processing { background: #0ea5e9; }
        .activity-dot.approved { background: #3b6bff; }
        .activity-dot.ready { background: #fbbf24; }
        .activity-dot.released { background: #4cd98a; }

        .activity-body {
            flex: 1;
        }

        .activity-title {
            font-size: 0.8rem;
            color: var(--text);
            line-height: 1.4;
            margin-bottom: 0.2rem;
        }

        .activity-time {
            font-size: 0.65rem;
            color: var(--text-dim);
        }

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

        .theme-toggle:hover {
            transform: scale(1.1);
            background: var(--surface-hv);
        }

        /* Mobile Responsive */
        @media (max-width: 1024px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
            }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- Sidebar - Blue Theme with ADFC Logo2.png -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <img id="sidebarLogo" src="../wlogo.png" alt="ADFC Logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%231a3ec7%22/%3E%3Ctext x=%2250%22 y=%2265%22 font-size=%2236%22 text-anchor=%22middle%22 fill=%22white%22%3EADFC%3C/text%3E%3C/svg%3E'">
            <div class="brand-text">
                <div class="brand-name">Asian Development<br>Foundation College</div>
                <div class="brand-sub">DocuGo Admin Panel</div>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-section">MAIN</div>
        <a href="dashboard.php" class="menu-item active">
            <span class="menu-icon">🏠</span> Dashboard
        </a>
        <a href="requests.php" class="menu-item">
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
        <a href="alumni.php" class="menu-item">
            <span class="menu-icon">🎓</span> Alumni
        </a>
        <a href="tracer.php" class="menu-item">
            <span class="menu-icon">📊</span> Graduate Tracer
        </a>
        <a href="reports.php" class="menu-item">
            <span class="menu-icon">📈</span> Reports
        </a>

        <div class="menu-section">COMMUNICATION</div>
        <a href="announcements.php" class="menu-item">
            <span class="menu-icon">📢</span> Announcements
        </a>

        <div class="menu-section">SETTINGS</div>
        <a href="document_types.php" class="menu-item">
            <span class="menu-icon">⚙️</span> Document Types
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php">
            <span class="menu-icon">🚪</span> Logout
        </a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Dashboard</h1>
            <p>Welcome back, here's what's happening today.</p>
        </div>
        <div class="topbar-right">
            <div class="admin-info">
                <i class="fas fa-user-circle"></i> <strong><?= escape($_SESSION['user_name']) ?></strong>
            </div>
            <div class="topbar-date">
                <i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?>
            </div>
        </div>
    </div>

    <!-- Pending accounts alert -->
    <?php if ($pendingAccs > 0): ?>
    <div class="pending-alert">
        <div class="pa-text">
            ⚠️ <span><?= $pendingAccs ?></span> account<?= $pendingAccs > 1 ? 's' : '' ?>
            pending activation — users cannot login until approved.
        </div>
        <a href="accounts.php?status=pending" class="btn-sm">Review Now</a>
    </div>
    <?php endif; ?>

    <!-- Stats grid -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-icon blue">👥</div>
                <span class="stat-change up">+<?= $totalUsers ?></span>
            </div>
            <div class="stat-num"><?= $totalUsers ?></div>
            <div class="stat-label">Total Users</div>
            <div class="stat-sub"><?= $totalStudents ?> students · <?= $totalAlumni ?> alumni</div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-icon yellow">⏳</div>
                <?php if ($pendingReqs > 0): ?>
                    <span class="stat-change warn"><?= $pendingReqs ?> new</span>
                <?php endif; ?>
            </div>
            <div class="stat-num"><?= $pendingReqs + $approvedReqs ?></div>
            <div class="stat-label">Awaiting Action</div>
            <div class="stat-sub"><?= $pendingReqs ?> pending · <?= $approvedReqs ?> approved</div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-icon green">✅</div>
                <span class="stat-change up"><?= $readyReqs ?> ready</span>
            </div>
            <div class="stat-num"><?= $processingReqs + $readyReqs ?></div>
            <div class="stat-label">In Progress</div>
            <div class="stat-sub"><?= $processingReqs ?> processing · <?= $readyReqs ?> ready</div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <div class="stat-icon purple">📦</div>
                <span class="stat-change up"><?= $releasedReqs ?> done</span>
            </div>
            <div class="stat-num"><?= $totalReqs ?></div>
            <div class="stat-label">Total Requests</div>
            <div class="stat-sub"><?= $releasedReqs ?> released · <?= $paidReqs ?> paid</div>
        </div>
    </div>

    <!-- Request status distribution bar -->
    <?php if ($totalReqs > 0):
        $pctPending    = round(($pendingReqs    / $totalReqs) * 100);
        $pctApproved   = round(($approvedReqs   / $totalReqs) * 100);
        $pctProcessing = round(($processingReqs / $totalReqs) * 100);
        $pctReady      = round(($readyReqs      / $totalReqs) * 100);
        $pctReleased   = round(($releasedReqs   / $totalReqs) * 100);
    ?>
    <div class="status-bar-wrap">
        <div class="status-bar-title">Request Status Distribution</div>
        <div class="status-bar">
            <div class="status-bar-seg" style="width:<?= $pctPending ?>%;background:var(--yellow);"></div>
            <div class="status-bar-seg" style="width:<?= $pctApproved ?>%;background:var(--blue);"></div>
            <div class="status-bar-seg" style="width:<?= $pctProcessing ?>%;background:#0ea5e9;"></div>
            <div class="status-bar-seg" style="width:<?= $pctReady ?>%;background:var(--yellow);"></div>
            <div class="status-bar-seg" style="width:<?= $pctReleased ?>%;background:var(--purple);"></div>
        </div>
        <div class="status-legend">
            <div class="legend-item"><div class="legend-dot" style="background:var(--yellow);"></div> Pending (<?= $pendingReqs ?>)</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--blue);"></div> Approved (<?= $approvedReqs ?>)</div>
            <div class="legend-item"><div class="legend-dot" style="background:#0ea5e9;"></div> Processing (<?= $processingReqs ?>)</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--yellow);"></div> Ready (<?= $readyReqs ?>)</div>
            <div class="legend-item"><div class="legend-dot" style="background:var(--purple);"></div> Released (<?= $releasedReqs ?>)</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main grid -->
    <div class="grid-2">

        <!-- Left column -->
        <div style="display:flex;flex-direction:column;gap:1.2rem;">

            <!-- Recent Announcements -->
            <?php if (!empty($recentAnnouncements)): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <h2>📢 Recent Announcements</h2>
                    </div>
                    <a href="announcements.php">View all →</a>
                </div>
                <div style="padding:1rem 1.2rem;display:flex;flex-direction:column;gap:0.8rem;">
                    <?php foreach ($recentAnnouncements as $ann): ?>
                    <div style="padding:0.85rem 1rem;background:rgba(59,107,255,0.08);border-left:4px solid var(--accent);border-radius:6px;">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;margin-bottom:0.3rem;">
                            <div style="font-weight:700;color:var(--text);font-size:0.9rem;"><?= escape($ann['title']) ?></div>
                            <div style="font-size:0.68rem;color:var(--text-dim);"><?= timeAgo($ann['created_at']) ?></div>
                        </div>
                        <div style="color:var(--text-muted);font-size:0.82rem;line-height:1.5;"><?= nl2br(escape($ann['message'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Latest Requests -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <h2>📄 Latest Requests</h2>
                    </div>
                    <a href="requests.php">View all →</a>
                </div>
                <table>
                    <thead>
                        <tr><th>Requester</th><th>Document</th><th>Amount</th><th>Status</th><th>Time</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($latestReqs && $latestReqs->num_rows > 0): ?>
                            <?php while ($r = $latestReqs->fetch_assoc()): ?>
                            <tr>
                                <td><div class="td-name"><?= escape($r['first_name'] . ' ' . $r['last_name']) ?></div><div class="td-sub"><?= escape($r['request_code']) ?></div></td>
                                <td><?= escape($r['doc_type']) ?></td>
                                <td style="font-weight:700;color:var(--green);">₱<?= number_format($r['fee'] * $r['copies'], 2) ?></td>
                                <td><span class="badge badge-<?= escape($r['status']) ?>"><?= escape($r['status']) ?></span></td>
                                <td style="font-size:0.75rem;"><?= timeAgo($r['requested_at']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-row"><td colspan="5">No requests yet.<?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pending Account Approvals -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <h2>👥 Pending Approvals</h2>
                        <?php if ($pendingAccs > 0): ?>
                            <span class="badge badge-pending"><?= $pendingAccs ?> waiting</span>
                        <?php endif; ?>
                    </div>
                    <a href="accounts.php">View all →</a>
                </div>
                <table>
                    <thead><tr><th>User</th><th>Role</th><th>Registered</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if ($pendingAccounts && $pendingAccounts->num_rows > 0): ?>
                            <?php while ($a = $pendingAccounts->fetch_assoc()): ?>
                            <tr>
                                <td><div class="td-name"><?= escape($a['first_name'] . ' ' . $a['last_name']) ?></div><div class="td-sub"><?= escape($a['email']) ?></div></td>
                                <td><span class="badge badge-<?= escape($a['role']) ?>"><?= ucfirst(escape($a['role'])) ?></span></td>
                                <td style="font-size:0.75rem;"><?= timeAgo($a['created_at']) ?></td>
                                <td><a href="approve_account.php?id=<?= $a['id'] ?>" class="btn-sm" style="background:var(--accent);color:white;">Approve</a></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-row"><td colspan="4">No pending accounts. 🎉</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right column -->
        <div style="display:flex;flex-direction:column;gap:1.2rem;">
            <!-- Revenue card -->
            <div class="revenue-card">
                <div class="revenue-label">Total Revenue Collected</div>
                <div class="revenue-amount">₱<?= number_format($totalRevenue, 2) ?></div>
                <div class="revenue-sub">From <?= $releasedReqs ?> released document<?= $releasedReqs != 1 ? 's' : '' ?></div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header"><h2>⚡ Quick Actions</h2></div>
                <div class="quick-actions">
                    <a href="requests.php?status=pending" class="qa-btn"><div class="qa-btn-icon">⏳</div> Pending Requests</a>
                    <a href="requests.php?status=ready" class="qa-btn"><div class="qa-btn-icon">📋</div> Ready for Pickup</a>
                    <a href="accounts.php?status=pending" class="qa-btn"><div class="qa-btn-icon">👤</div> Approve Accounts</a>
                    <a href="document_types.php" class="qa-btn"><div class="qa-btn-icon">⚙️</div> Document Types</a>
                    <a href="tracer.php" class="qa-btn"><div class="qa-btn-icon">📊</div> Graduate Tracer</a>
                    <a href="reports.php" class="qa-btn"><div class="qa-btn-icon">📈</div> View Reports</a>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header"><h2>🕐 Recent Activity</h2></div>
                <div class="activity-feed">
                    <?php if ($recentActivity && $recentActivity->num_rows > 0): ?>
                        <?php while ($act = $recentActivity->fetch_assoc()): ?>
                        <div class="activity-item">
                            <div class="activity-dot <?= escape($act['new_status']) ?>"></div>
                            <div class="activity-body">
                                <div class="activity-title">
                                    <strong><?= escape($act['req_fn'] . ' ' . $act['req_ln']) ?></strong>'s
                                    request <span style="font-family:'JetBrains Mono',monospace;font-size:0.7rem;"><?= escape($act['request_code']) ?></span>
                                    marked as <strong><?= ucfirst(escape($act['new_status'])) ?></strong>
                                    <?php if ($act['staff_fn']): ?> by <?= escape($act['staff_fn']) ?><?php endif; ?>
                                </div>
                                <div class="activity-time">🕐 <?= timeAgo($act['changed_at']) ?></div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="padding:2rem;text-align:center;color:var(--text-dim);">No recent activity yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Theme Toggle Button -->
<div class="theme-toggle" id="themeToggleBtn">
    <i class="fas fa-moon"></i>
</div>

<script>
// Logo stays as logo2.png in both modes (as requested)
// No logo swapping needed - always using logo2.png
const savedTheme = localStorage.getItem('docugoTheme');
const isLightOnLoad = savedTheme === 'light';

if (isLightOnLoad) {
    document.body.classList.add('light');
    document.getElementById('themeToggleBtn').innerHTML = '<i class="fas fa-sun"></i>';
} else {
    document.body.classList.remove('light');
    document.getElementById('themeToggleBtn').innerHTML = '<i class="fas fa-moon"></i>';
}

document.getElementById('themeToggleBtn').addEventListener('click', () => {
    const isLight = document.body.classList.toggle('light');
    localStorage.setItem('docugoTheme', isLight ? 'light' : 'dark');
    document.getElementById('themeToggleBtn').innerHTML = isLight ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
});
</script>
</body>
</html>