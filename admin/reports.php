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

function e($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics — DocuGo Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & Base ─────────────────────────────── */
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

        /* ── Sidebar (matching dashboard) ───────────────── */
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
            border-right: 1px solid rgba(255,255,255,0.1);
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
            transition: color 0.15s;
        }

        .sidebar-footer a:hover { color: #fff; }

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
            transition: background 0.15s, color 0.15s;
            position: relative;
        }

        .menu-item:hover  { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.9); }
        .menu-item.active { background: rgba(255,255,255,0.15); color: #fff; font-weight: 600; }
        .menu-item.active::before {
            content: '';
            position: absolute;
            left: -0.6rem; top: 50%;
            transform: translateY(-50%);
            width: 3px; height: 20px;
            background: #fff;
            border-radius: 0 3px 3px 0;
        }

        .menu-icon { font-size: 0.95rem; width: 18px; text-align: center; flex-shrink: 0; }
        .menu-badge {
            margin-left: auto;
            background: var(--red);
            color: #fff;
            font-size: 0.6rem;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 8px;
            min-width: 18px;
            text-align: center;
        }
        .menu-badge.yellow { background: var(--yellow); }

        /* ── Main content ──────────────────────────────── */
        .main { margin-left: var(--sidebar); flex: 1; padding: 1.8rem 2rem; min-width: 0; }

        /* ── Topbar ───────────────────────────────────── */
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
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .admin-info {
            font-size: 0.85rem;
            background: var(--card);
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            border: 1px solid var(--border);
        }
        .topbar-date {
            font-size: 0.78rem;
            color: var(--text-3);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.4rem 0.85rem;
        }

        /* ── Stats Grid ───────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.4rem;
        }
        .stat-card {
            background: var(--card);
            border-radius: 12px;
            padding: 1rem 1.1rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .stat-num { font-size: 1.6rem; font-weight: 800; color: var(--text); line-height: 1; }
        .stat-label { font-size: 0.72rem; color: var(--text-4); font-weight: 500; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-sub { font-size: 0.7rem; color: var(--text-3); margin-top: 6px; padding-top: 6px; border-top: 1px solid var(--border-lt); }

        /* ── Main Grid ────────────────────────────────── */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.2rem;
            margin-bottom: 1.2rem;
        }

        /* ── Cards ────────────────────────────────────── */
        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            overflow: hidden;
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
        .card-header .badge {
            background: var(--blue-lt);
            color: var(--blue);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .card-body { padding: 1.2rem; }

        /* Stats rows inside cards */
        .stat-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid var(--border-lt);
        }
        .stat-row:last-child { border-bottom: none; }
        .stat-row .label {
            font-size: 0.8rem;
            color: var(--text-2);
            font-weight: 500;
        }
        .stat-row .value {
            font-weight: 700;
            color: var(--text);
            font-size: 0.9rem;
        }
        .stat-row .small {
            font-size: 0.7rem;
            font-weight: 400;
            color: var(--text-4);
            margin-left: 0.25rem;
        }

        /* Progress bar */
        .progress-bar {
            background: #e5e7eb;
            border-radius: 6px;
            height: 8px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: var(--blue);
            border-radius: 6px;
            transition: width 0.3s;
        }

        /* Monthly chart */
        .monthly-chart {
            display: flex;
            align-items: flex-end;
            gap: 0.4rem;
            height: 160px;
            margin-top: 1rem;
        }
        .month-bar {
            flex: 1;
            background: var(--blue);
            border-radius: 6px 6px 0 0;
            position: relative;
            transition: height 0.3s;
        }
        .month-bar .count {
            position: absolute;
            top: -22px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-2);
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
            font-size: 0.65rem;
            color: var(--text-4);
            font-weight: 600;
        }

        /* Table */
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th {
            text-align: left;
            padding: 0.6rem 1rem;
            background: #fafafa;
            color: var(--text-4);
            font-weight: 700;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1px solid var(--border-lt);
        }
        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-lt);
            color: var(--text-2);
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafbff; }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
        }
        .badge-green  { background: #d1fae5; color: #065f46; }
        .badge-blue   { background: #dbeafe; color: #1e40af; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        .badge-yellow { background: #fef3c7; color: #92400e; }

        /* Revenue highlight */
        .revenue-highlight {
            background: linear-gradient(135deg, #1a56db 0%, #1a56db 100%);
            border-radius: 12px;
            padding: 1.2rem;
            color: #fff;
            margin-bottom: 1.2rem;
            text-align: center;
        }
        .revenue-highlight .amount {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -1px;
        }
        .revenue-highlight .label {
            font-size: 0.7rem;
            opacity: 0.75;
            margin-top: 4px;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 700px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<!-- Sidebar (identical to dashboard) -->
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
        <div class="menu-section">Records</div>
        <a href="alumni.php" class="menu-item">
            <span class="menu-icon">🎓</span> Alumni
        </a>
        <a href="tracer.php" class="menu-item">
            <span class="menu-icon">📊</span> Graduate Tracer
        </a>
        <a href="reports.php" class="menu-item active">
            <span class="menu-icon">📈</span> Reports
        </a>
        <div class="menu-section">Communication</div>
        <a href="announcements.php" class="menu-item">
            <span class="menu-icon">📢</span> Announcements
        </a>
        <div class="menu-section">Settings</div>
        <a href="document_types.php" class="menu-item">
            <span class="menu-icon">⚙️</span> Document Types
        </a>
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
            <h1>📈 Reports & Analytics</h1>
            <p>Comprehensive overview of system performance and alumni outcomes.</p>
        </div>
        <div class="topbar-right">
            <div class="admin-info">
                <strong><?= e($_SESSION['user_name']) ?></strong>
            </div>
            <div class="topbar-date">
                📅 <?= date('l, F j, Y') ?>
            </div>
        </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-num"><?= $totalRequests ?></div>
            <div class="stat-label">Total Requests</div>
            <div class="stat-sub"><?= $releasedCount ?> completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= number_format($totalRevenue, 0) ?></div>
            <div class="stat-label">Total Revenue</div>
            <div class="stat-sub">₱<?= number_format($totalRevenue, 2) ?> collected</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $alumniCount ?></div>
            <div class="stat-label">Alumni</div>
            <div class="stat-sub"><?= $tracerRate ?>% tracer response rate</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $completionRate ?>%</div>
            <div class="stat-label">Completion Rate</div>
            <div class="stat-sub"><?= $releasedCount ?> released out of <?= $totalRequests ?></div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid-2">
        <!-- Document Requests Overview -->
        <div class="card">
            <div class="card-header">
                <h2>📄 Document Requests</h2>
                <span class="badge"><?= $totalRequests ?> total</span>
            </div>
            <div class="card-body">
                <?php foreach (['pending' => '⏳ Pending', 'approved' => '✓ Approved', 'processing' => '⚙ Processing', 'ready' => '📋 Ready', 'paid' => '💰 Paid', 'released' => '🎉 Released', 'cancelled' => '✕ Cancelled'] as $status => $label): ?>
                    <?php $count = $requestsByStatus[$status] ?? 0; ?>
                    <div class="stat-row">
                        <span class="label"><?= $label ?></span>
                        <div class="value">
                            <?= $count ?>
                            <span class="small">(<?= $totalRequests > 0 ? round(($count / $totalRequests) * 100, 1) : 0 ?>%)</span>
                        </div>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $totalRequests > 0 ? ($count / $totalRequests * 100) : 0 ?>%"></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- User Accounts -->
        <div class="card">
            <div class="card-header">
                <h2>👥 User Accounts</h2>
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
                        <div class="value">
                            <?= $total ?>
                            <span class="small">(<?= $active ?> active)</span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div style="margin-top: 0.75rem; padding-top: 0.5rem; border-top: 1px solid var(--border-lt);">
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
                <h2>💰 Revenue Analytics</h2>
            </div>
            <div class="card-body">
                <div class="revenue-highlight" style="margin-bottom: 1rem;">
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
                <h2>🎓 Alumni Engagement</h2>
            </div>
            <div class="card-body">
                <div class="stat-row">
                    <span class="label">Total Alumni</span>
                    <div class="value"><?= $alumniCount ?></div>
                </div>
                <div class="stat-row">
                    <span class="label">Tracer Responses</span>
                    <div class="value">
                        <?= $tracerCount ?>
                        <span class="small">(<?= $tracerRate ?>% response rate)</span>
                    </div>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?= $tracerRate ?>%"></div>
                </div>
                <div class="stat-row" style="margin-top: 0.75rem;">
                    <span class="label">Employment Profiles</span>
                    <div class="value">
                        <?= $empCount ?>
                        <span class="small">(avg <?= $avgEmp ?> entries/alumni)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Trends -->
    <div class="card" style="margin-bottom: 1.2rem;">
        <div class="card-header">
            <h2>📅 Monthly Request Trends</h2>
            <span class="badge">Last 12 months</span>
        </div>
        <div class="card-body">
            <?php
            $max = !empty($monthlyData) ? max($monthlyData) : 1;
            $max = $max > 0 ? $max : 1;
            ?>
            <div class="monthly-chart">
                <?php for ($i = 11; $i >= 0; $i--): ?>
                    <?php $date = date('Y-m', strtotime("-$i months")); ?>
                    <?php $count = $monthlyData[$date] ?? 0; ?>
                    <?php $height = $max > 0 ? max(8, ($count / $max) * 100) : 8; ?>
                    <div class="month-bar" style="height: <?= $height ?>%;">
                        <div class="count"><?= $count ?></div>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="month-labels">
                <?php for ($i = 11; $i >= 0; $i--): ?>
                    <div class="month-label"><?= date('M', strtotime("-$i months")) ?></div>
                <?php endfor; ?>
            </div>
            <div style="margin-top: 1rem; text-align: center; font-size: 0.7rem; color: var(--text-4);">
                Peak month: <?= !empty($monthlyData) ? max($monthlyData) : 0 ?> requests
            </div>
        </div>
    </div>

    <!-- Document Popularity -->
    <div class="card">
        <div class="card-header">
            <h2>📋 Document Popularity</h2>
            <span class="badge">Most requested</span>
        </div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Document Type</th>
                        <th>Total Requests</th>
                        <th>Popularity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $doc): ?>
                        <?php $percent = $totalRequests > 0 ? round(($doc['requests'] / $totalRequests) * 100, 1) : 0; ?>
                        <tr>
                            <td><strong><?= e($doc['name']) ?></strong></td>
                            <td><?= $doc['requests'] ?></td>
                            <td>
                                <div class="progress-bar" style="width: 120px;">
                                    <div class="progress-fill" style="width: <?= min(100, $percent * 2) ?>%"></div>
                                </div>
                                <span style="font-size: 0.7rem; margin-left: 0.5rem; color: var(--text-4);"><?= $percent ?>%</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

</body>
</html>