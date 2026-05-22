<?php
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

// Get counts for sidebar badges
$pendingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$pendingAccs = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];

// Document Requests Stats
$reqStats = $conn->query("
    SELECT status, COUNT(*) AS count
    FROM document_requests
    GROUP BY status
");
$requestsByStatus = [];
while ($r = $reqStats->fetch_assoc()) {
    $requestsByStatus[$r['status']] = (int)$r['count'];
}
$totalRequests = array_sum($requestsByStatus);

// User Stats
$userStats = $conn->query("
    SELECT role, status, COUNT(*) AS count
    FROM users
    GROUP BY role, status
");
$usersByRoleStatus = [];
while ($u = $userStats->fetch_assoc()) {
    $usersByRoleStatus[$u['role']][$u['status']] = (int)$u['count'];
}

// Document Types Popularity
$docStats = $conn->query("
    SELECT dt.name, COUNT(dr.id) AS requests
    FROM document_types dt
    LEFT JOIN document_requests dr ON dt.id = dr.document_type_id
    GROUP BY dt.id
    ORDER BY requests DESC
");
$docs = [];
while ($d = $docStats->fetch_assoc()) {
    $docs[] = $d;
}

// Monthly Requests (last 12 months)
$monthlyReqs = $conn->query("
    SELECT DATE_FORMAT(requested_at, '%Y-%m') AS month,
           COUNT(*) AS count
    FROM document_requests
    WHERE requested_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month
");
$monthlyData = [];
while ($m = $monthlyReqs->fetch_assoc()) {
    $monthlyData[$m['month']] = (int)$m['count'];
}

// Tracer Stats
$tracerCount = (int)$conn->query("SELECT COUNT(*) AS c FROM graduate_tracer")->fetch_assoc()['c'];
$alumniCount = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'alumni'")->fetch_assoc()['c'];
$tracerRate = $alumniCount > 0 ? round(($tracerCount / $alumniCount) * 100, 1) : 0;

// Employment Stats — check if alumni_employment table exists first
$empTableExists = $conn->query("SHOW TABLES LIKE 'alumni_employment'")->num_rows > 0;

if ($empTableExists) {
    $empCount = (int)$conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM alumni_employment")->fetch_assoc()['c'];
    $avgEmp = $conn->query("
        SELECT AVG(employment_count) AS avg
        FROM (
            SELECT COUNT(ae.id) AS employment_count
            FROM users u
            LEFT JOIN alumni_employment ae ON u.id = ae.user_id
            WHERE u.role = 'alumni'
            GROUP BY u.id
        ) AS sub
    ")->fetch_assoc()['avg'];
    $avgEmp = round($avgEmp ?? 0, 1);
} else {
    $empCount = 0;
    $avgEmp   = 0;
}

// Payment Stats
$totalRevenue = $conn->query("SELECT COALESCE(SUM(amount),0) as s FROM payment_records WHERE status='paid'")->fetch_assoc()['s'];
$paidRequests = $conn->query("SELECT COUNT(*) as c FROM payment_records WHERE status='paid'")->fetch_assoc()['c'];

// Completion Rate
$releasedCount = $requestsByStatus['released'] ?? 0;
$completionRate = $totalRequests > 0 ? round(($releasedCount / $totalRequests) * 100, 1) : 0;

$conn->close();

function escape($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics — ADFC DocuGo</title>
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

        /* Stats Grid */
        .stats-grid {
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
        .stat-num {
            font-family: 'Sora', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
        }
        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 4px;
        }
        .stat-sub {
            font-size: 0.65rem;
            color: var(--text-dim);
            margin-top: 6px;
            padding-top: 5px;
            border-top: 1px solid var(--border);
        }

        /* Main Grid */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.2rem;
            margin-bottom: 1.2rem;
        }

        /* Cards */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .card-header {
            padding: 0.9rem 1.2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-header h2 {
            font-family: 'Sora', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
        }
        .card-header .badge {
            background: var(--accent);
            color: #fff;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        .card-body { padding: 1.2rem; }

        /* Stat Rows */
        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--border);
        }
        .stat-row:last-child { border-bottom: none; }
        .stat-row .label {
            font-size: 0.8rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        .stat-row .value {
            font-weight: 700;
            color: var(--text);
            font-size: 0.85rem;
        }
        .stat-row .small {
            font-size: 0.65rem;
            font-weight: 400;
            color: var(--text-dim);
            margin-left: 0.25rem;
        }

        /* Progress Bar */
        .progress-bar {
            background: var(--bg2);
            border-radius: 6px;
            height: 6px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 6px;
            transition: width 0.3s;
        }

        /* Revenue Highlight */
        .revenue-highlight {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius-lg);
            padding: 1rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        .revenue-highlight .amount {
            font-family: 'Sora', sans-serif;
            font-size: 1.8rem;
            font-weight: 800;
            color: white;
        }
        .revenue-highlight .label {
            font-size: 0.65rem;
            opacity: 0.8;
            margin-top: 4px;
            color: rgba(255,255,255,0.8);
        }

        /* Monthly Chart */
        .monthly-chart {
            display: flex;
            align-items: flex-end;
            gap: 0.4rem;
            height: 150px;
            margin-top: 1rem;
        }
        .month-bar {
            flex: 1;
            background: var(--accent);
            border-radius: 6px 6px 0 0;
            position: relative;
            transition: height 0.3s;
            min-height: 4px;
        }
        .month-bar .count {
            position: absolute;
            top: -22px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.65rem;
            font-weight: 700;
            color: var(--text);
        }
        .month-labels {
            display: flex;
            justify-content: space-between;
            gap: 0.4rem;
            margin-top: 0.6rem;
        }
        .month-label {
            flex: 1;
            text-align: center;
            font-size: 0.6rem;
            color: var(--text-dim);
            font-weight: 600;
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
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr; }
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
        <a href="reports.php" class="menu-item active">
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
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1><i class="fas fa-chart-line"></i> Reports & Analytics</h1>
            <p>Comprehensive overview of system performance and alumni outcomes.</p>
        </div>
        <div class="topbar-right">
            <div class="admin-info"><i class="fas fa-user-circle"></i> <strong><?= escape($_SESSION['user_name']) ?></strong></div>
            <div class="topbar-date"><i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num"><?= $totalRequests ?></div>
            <div class="stat-label">Total Requests</div>
            <div class="stat-sub"><i class="fas fa-check-circle" style="color: var(--green);"></i> <?= $releasedCount ?> completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-num">₱<?= number_format($totalRevenue, 0) ?></div>
            <div class="stat-label">Total Revenue</div>
            <div class="stat-sub"><i class="fas fa-peso-sign"></i> <?= number_format($totalRevenue, 2) ?> collected</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $alumniCount ?></div>
            <div class="stat-label">Alumni</div>
            <div class="stat-sub"><i class="fas fa-chart-simple"></i> <?= $tracerRate ?>% tracer response rate</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $completionRate ?>%</div>
            <div class="stat-label">Completion Rate</div>
            <div class="stat-sub"><i class="fas fa-rocket"></i> <?= $releasedCount ?> released out of <?= $totalRequests ?></div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid-2">
        <!-- Document Requests Overview -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-file-alt"></i> Document Requests</h2>
                <span class="badge"><?= $totalRequests ?> total</span>
            </div>
            <div class="card-body">
                <?php foreach (['pending' => '⏳ Pending', 'approved' => '✓ Approved', 'processing' => '⚙ Processing', 'ready' => '📋 Ready', 'released' => '🎉 Released', 'cancelled' => '✕ Cancelled'] as $status => $label): ?>
                    <?php $count = $requestsByStatus[$status] ?? 0; ?>
                    <div class="stat-row">
                        <span class="label"><?= $label ?></span>
                        <div class="value"><?= $count ?> <span class="small">(<?= $totalRequests > 0 ? round(($count / $totalRequests) * 100, 1) : 0 ?>%)</span></div>
                    </div>
                    <div class="progress-bar"><div class="progress-fill" style="width: <?= $totalRequests > 0 ? ($count / $totalRequests * 100) : 0 ?>%"></div></div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- User Accounts -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-users"></i> User Accounts</h2>
                <span class="badge">by role</span>
            </div>
            <div class="card-body">
                <?php
                $roleLabels = ['student' => '🎓 Students', 'alumni' => '👨‍🎓 Alumni', 'registrar' => '📋 Registrar', 'admin' => '🛡️ Admins'];
                foreach ($roleLabels as $role => $label):
                    $total = array_sum($usersByRoleStatus[$role] ?? []);
                    $active = $usersByRoleStatus[$role]['active'] ?? 0;
                ?>
                    <div class="stat-row">
                        <span class="label"><?= $label ?></span>
                        <div class="value"><?= $total ?> <span class="small">(<?= $active ?> active)</span></div>
                    </div>
                <?php endforeach; ?>
                <div style="margin-top: 0.75rem; padding-top: 0.5rem; border-top: 1px solid var(--border);">
                    <div class="stat-row">
                        <span class="label">⏳ Pending Approval</span>
                        <div class="value"><?= $pendingAccs ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenue & Payments -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-coins"></i> Revenue Analytics</h2>
            </div>
            <div class="card-body">
                <div class="revenue-highlight">
                    <div class="amount">₱<?= number_format($totalRevenue, 2) ?></div>
                    <div class="label">Total Revenue Collected</div>
                </div>
                <div class="stat-row">
                    <span class="label">Paid Transactions</span>
                    <div class="value"><?= $paidRequests ?></div>
                </div>
                <div class="stat-row">
                    <span class="label">Average Payment</span>
                    <div class="value">₱<?= $paidRequests > 0 ? number_format($totalRevenue / $paidRequests, 2) : '0.00' ?></div>
                </div>
                <div class="stat-row">
                    <span class="label">Ready for Pickup (Unpaid)</span>
                    <div class="value"><?= $requestsByStatus['ready'] ?? 0 ?></div>
                </div>
            </div>
        </div>

        <!-- Alumni Engagement -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-graduation-cap"></i> Alumni Engagement</h2>
            </div>
            <div class="card-body">
                <div class="stat-row">
                    <span class="label">Total Alumni</span>
                    <div class="value"><?= $alumniCount ?></div>
                </div>
                <div class="stat-row">
                    <span class="label">Tracer Responses</span>
                    <div class="value"><?= $tracerCount ?> <span class="small">(<?= $tracerRate ?>% response rate)</span></div>
                </div>
                <div class="progress-bar"><div class="progress-fill" style="width: <?= $tracerRate ?>%"></div></div>
                <div class="stat-row" style="margin-top: 0.75rem;">
                    <span class="label">Employment Profiles</span>
                    <div class="value"><?= $empCount ?> <span class="small">(avg <?= $avgEmp ?> entries/alumni)</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Trends -->
    <div class="card" style="margin-bottom: 1.2rem;">
        <div class="card-header">
            <h2><i class="fas fa-calendar-alt"></i> Monthly Request Trends</h2>
            <span class="badge">Last 12 months</span>
        </div>
        <div class="card-body">
            <?php $max = !empty($monthlyData) ? max($monthlyData) : 1; $max = $max > 0 ? $max : 1; ?>
            <div class="monthly-chart">
                <?php for ($i = 11; $i >= 0; $i--): ?>
                    <?php $date = date('Y-m', strtotime("-$i months")); ?>
                    <?php $count = $monthlyData[$date] ?? 0; ?>
                    <?php $height = $max > 0 ? max(8, ($count / $max) * 100) : 8; ?>
                    <div class="month-bar" style="height: <?= $height ?>%;"><div class="count"><?= $count ?></div></div>
                <?php endfor; ?>
            </div>
            <div class="month-labels">
                <?php for ($i = 11; $i >= 0; $i--): ?>
                    <div class="month-label"><?= date('M', strtotime("-$i months")) ?></div>
                <?php endfor; ?>
            </div>
            <div style="margin-top: 1rem; text-align: center; font-size: 0.7rem; color: var(--text-dim);">
                <i class="fas fa-chart-line"></i> Peak month: <?= !empty($monthlyData) ? max($monthlyData) : 0 ?> requests
            </div>
        </div>
    </div>

    <!-- Document Popularity -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-chart-bar"></i> Document Popularity</h2>
            <span class="badge">Most requested</span>
        </div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr><th>Document Type</th><th>Total Requests</th><th>Popularity</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $doc): ?>
                        <?php $percent = $totalRequests > 0 ? round(($doc['requests'] / $totalRequests) * 100, 1) : 0; ?>
                        <tr>
                            <td><strong><?= escape($doc['name']) ?></strong></td>
                            <td><?= $doc['requests'] ?></td>
                            <td><div style="display: flex; align-items: center; gap: 0.5rem;"><div class="progress-bar" style="width: 100px;"><div class="progress-fill" style="width: <?= min(100, $percent * 2) ?>%"></div></div><span style="font-size: 0.7rem; color: var(--text-dim);"><?= $percent ?>%</span></div></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

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
</script>

</body>
</html>