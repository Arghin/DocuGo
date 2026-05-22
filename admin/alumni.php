<?php
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

$conn->query("
    CREATE TABLE IF NOT EXISTS alumni_employment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        company_name VARCHAR(200),
        job_title VARCHAR(150),
        industry VARCHAR(100),
        employment_type ENUM('full_time','part_time','contract','freelance','internship') DEFAULT 'full_time',
        work_setup ENUM('onsite','remote','hybrid') DEFAULT 'onsite',
        date_started DATE,
        date_ended DATE DEFAULT NULL,
        is_current TINYINT(1) DEFAULT 1,
        salary_range VARCHAR(50),
        work_location VARCHAR(200),
        description TEXT,
        skills TEXT,
        linkedin_url VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$search = trim($_GET['q'] ?? '');
$page   = intval($_GET['page'] ?? 1);
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// Query alumni with tracer and employment counts
$sql = "
    SELECT 
        u.id, u.first_name, u.last_name, u.email, u.student_id, u.course, u.year_graduated, u.created_at,
        gt.date_submitted AS tracer_date,
        COUNT(ae.id) AS employment_count
    FROM users u
    LEFT JOIN graduate_tracer gt ON u.id = gt.user_id
    LEFT JOIN alumni_employment ae ON u.id = ae.user_id
    WHERE u.role = 'alumni'
";
$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ? OR u.course LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sssss';
}

$sql .= " GROUP BY u.id ORDER BY u.last_name ASC, u.first_name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$alumni = $stmt->get_result();

// Get total count
$countSql = "
    SELECT COUNT(DISTINCT u.id) AS total
    FROM users u
    WHERE u.role = 'alumni'
";
if ($search !== '') {
    $countSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ? OR u.course LIKE ?)";
}
$countStmt = $conn->prepare($countSql);
if ($search !== '') {
    $countStmt->bind_param('sssss', $like, $like, $like, $like, $like);
}
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);
$countStmt->close();

// Get stats
$totalAlumni = $totalRows;
$tracerCount = $conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM graduate_tracer")->fetch_assoc()['c'];
$empCount = $conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM alumni_employment")->fetch_assoc()['c'];
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

// Get pending accounts count for sidebar badge
$pendingAccs = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];
$pendingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];

function escape($v) { return htmlspecialchars($v ?? ''); }
function timeAgo($datetime) {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alumni Records — ADFC DocuGo</title>
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

        /* Stats Cards */
        .stats {
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); border-color: var(--border-hv); }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }
        .stat-icon.blue { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .stat-icon.green { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .stat-icon.purple { background: rgba(167,139,250,0.15); color: #a78bfa; }
        .stat-icon.orange { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .stat-info .num {
            font-family: 'Sora', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
        }
        .stat-info .label {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 500;
            letter-spacing: 0.04em;
        }

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
            width: 260px;
        }
        .search-form button {
            padding: 0.5rem 1rem;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-clear {
            padding: 0.5rem 1rem;
            background: var(--text-dim);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
        }
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
        .count-badge {
            background: var(--accent);
            color: #fff;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
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

        .user-info { font-weight: 600; color: var(--text); font-size: 0.8rem; }
        .user-meta { font-size: 0.65rem; color: var(--text-dim); margin-top: 2px; }

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
        .badge-green { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .badge-yellow { background: rgba(251,191,36,0.15); color: #fbbf24; }

        /* Button */
        .btn {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.12s;
            display: inline-block;
            background: var(--accent);
            color: #fff;
        }
        .btn:hover { background: var(--primary-dk); transform: translateY(-1px); }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 2.5rem;
            color: var(--text-dim);
        }

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
        .pagination .current {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
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
        .theme-toggle:hover { transform: scale(1.1); background: var(--surface-hv); }

        @media (max-width: 1024px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .stats { grid-template-columns: 1fr; }
            .filters { flex-direction: column; align-items: stretch; }
            .search-form { justify-content: stretch; }
            .search-form input { flex: 1; }
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
        <a href="alumni.php" class="menu-item active">
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
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>🎓 Alumni Records</h1>
            <p>View and manage alumni information, tracer responses, and employment data.</p>
        </div>
        <div class="topbar-right">
            <div class="admin-info"><i class="fas fa-user-circle"></i> <strong><?= escape($_SESSION['user_name']) ?></strong></div>
            <div class="topbar-date"><i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-graduation-cap"></i></div>
            <div class="stat-info"><div class="num"><?= $totalAlumni ?></div><div class="label">Total Alumni</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-chart-line"></i></div>
            <div class="stat-info"><div class="num"><?= $tracerCount ?></div><div class="label">Tracer Responses</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-briefcase"></i></div>
            <div class="stat-info"><div class="num"><?= $empCount ?></div><div class="label">Employment Profiles</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange"><i class="fas fa-chart-simple"></i></div>
            <div class="stat-info"><div class="num"><?= number_format($avgEmp ?? 0, 1) ?></div><div class="label">Avg Employment Entries</div></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div></div>
        <form method="GET" class="search-form">
            <input type="text" name="q" placeholder="Search by name, email, course…" value="<?= escape($search) ?>">
            <button type="submit"><i class="fas fa-search"></i> Search</button>
            <?php if ($search): ?>
                <a href="alumni.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table Card -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-list"></i> Alumni Directory</h2>
            <span class="count-badge"><?= number_format($totalRows) ?> total</span>
        </div>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr><th>Name</th><th>Course & Year</th><th>Contact</th><th>Tracer</th><th>Employment</th><th>Joined</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($alumni->num_rows > 0): ?>
                        <?php while ($a = $alumni->fetch_assoc()): ?>
                            <tr>
                                <td><div class="user-info"><?= escape($a['first_name'] . ' ' . $a['last_name']) ?></div><div class="user-meta"><?php if (!empty($a['student_id'])): ?>ID: <?= escape($a['student_id']) ?><?php endif; ?></div></td>
                                <td><div class="user-info"><?= escape($a['course'] ?? 'N/A') ?></div><div class="user-meta">Graduated: <?= escape($a['year_graduated'] ?? 'N/A') ?></div></td>
                                <td><div class="user-info"><?= escape($a['email']) ?></div></td>
                                <td><?php if ($a['tracer_date']): ?><span class="badge badge-green"><i class="fas fa-check-circle"></i> Completed</span><div class="user-meta"><?= date('M d, Y', strtotime($a['tracer_date'])) ?></div><?php else: ?><span class="badge badge-yellow"><i class="fas fa-clock"></i> Not Submitted</span><?php endif; ?></td>
                                <td><div class="user-info"><?= (int)$a['employment_count'] ?> entries</div><?php if ((int)$a['employment_count'] > 0): ?><div class="user-meta"><i class="fas fa-check-circle"></i> Profile complete</div><?php else: ?><div class="user-meta">No data yet</div><?php endif; ?></td>
                                <td style="font-size:0.75rem;"><?= timeAgo($a['created_at']) ?></td>
                                <td><a href="tracer.php?user=<?= $a['id'] ?>" class="btn"><i class="fas fa-eye"></i> View Details</a></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-graduation-cap" style="font-size:2rem; opacity:0.5;"></i><p>No alumni found matching your search.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(['q' => $search, 'page' => $page - 1]) ?>"><i class="fas fa-chevron-left"></i> Prev</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?<?= http_build_query(['q' => $search, 'page' => $i]) ?>" class="<?= $i === $page ? 'current' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(['q' => $search, 'page' => $page + 1]) ?>">Next <i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
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
<?php
$stmt->close();
$conn->close();
?>