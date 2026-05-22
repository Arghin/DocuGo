<?php
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

$userId  = intval($_GET['user'] ?? 0);
$search  = trim($_GET['q'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$tracer     = null;
$employment = null;
$tracers    = null;
$totalRows  = 0;
$totalPages = 1;

// Get counts for sidebar badges
$pendingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$pendingAccs = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];

// ── View specific user ───────────────────────────────────────
if ($userId > 0) {
    $stmt = $conn->prepare("
        SELECT gt.*, u.first_name, u.last_name, u.email,
               u.student_id, u.course, u.year_graduated
        FROM graduate_tracer gt
        JOIN users u ON gt.user_id = u.id
        WHERE gt.user_id = ?
        ORDER BY gt.date_submitted DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $tracer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($tracer) {
        if ($conn->query("SHOW TABLES LIKE 'alumni_employment'")->num_rows > 0) {
            $empStmt = $conn->prepare("
                SELECT * FROM alumni_employment
                WHERE user_id = ?
                ORDER BY is_current DESC, date_started DESC
            ");
            $empStmt->bind_param("i", $userId);
            $empStmt->execute();
            $employment = $empStmt->get_result();
            $empStmt->close();
        }
    }

} else {
    $like = "%$search%";

    if ($search !== '') {
        $stmt = $conn->prepare("
            SELECT gt.*, u.first_name, u.last_name, u.email,
                   u.student_id, u.course, u.year_graduated
            FROM graduate_tracer gt
            JOIN users u ON gt.user_id = u.id
            WHERE u.first_name LIKE ? OR u.last_name LIKE ?
               OR u.email LIKE ? OR u.course LIKE ?
            ORDER BY gt.date_submitted DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("ssssii", $like, $like, $like, $like, $perPage, $offset);

        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM graduate_tracer gt
            JOIN users u ON gt.user_id = u.id
            WHERE u.first_name LIKE ? OR u.last_name LIKE ?
               OR u.email LIKE ? OR u.course LIKE ?
        ");
        $countStmt->bind_param("ssss", $like, $like, $like, $like);
    } else {
        $stmt = $conn->prepare("
            SELECT gt.*, u.first_name, u.last_name, u.email,
                   u.student_id, u.course, u.year_graduated
            FROM graduate_tracer gt
            JOIN users u ON gt.user_id = u.id
            ORDER BY gt.date_submitted DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("ii", $perPage, $offset);

        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM graduate_tracer gt
            JOIN users u ON gt.user_id = u.id
        ");
    }

    $stmt->execute();
    $tracers = $stmt->get_result();
    $stmt->close();

    $countStmt->execute();
    $totalRows  = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = max(1, ceil($totalRows / $perPage));
    $countStmt->close();
}

// Get stats for dashboard cards
$totalAlumni = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'alumni'")->fetch_assoc()['c'];
$tracerCount = $conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM graduate_tracer")->fetch_assoc()['c'];
$employedCount = $conn->query("SELECT COUNT(*) as c FROM graduate_tracer WHERE employment_status IN ('employed', 'self_employed')")->fetch_assoc()['c'];

// Helper functions
function escape($v) { return htmlspecialchars($v ?? ''); }
function formatDate($d) { return $d ? date('M d, Y', strtotime($d)) : 'N/A'; }
function timeAgo($datetime) {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}

function labelType($t) {
    $labels = [
        'employed'        => 'Employed',
        'unemployed'      => 'Unemployed',
        'self_employed'   => 'Self-Employed',
        'further_studies' => 'Further Studies',
        'not_looking'     => 'Not Looking',
    ];
    return $labels[$t] ?? ucfirst(str_replace('_', ' ', $t ?? ''));
}

function relLabel($r) {
    $rels = [
        'very_relevant'     => 'Very Relevant',
        'relevant'          => 'Relevant',
        'somewhat_relevant' => 'Somewhat Relevant',
        'not_relevant'      => 'Not Relevant',
    ];
    return $rels[$r] ?? 'N/A';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graduate Tracer — ADFC DocuGo</title>
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

        .btn-back {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-muted);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-back:hover {
            background: var(--surface-hv);
            border-color: var(--accent);
            color: var(--accent);
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

        /* Profile Header */
        .profile-header {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.2rem;
            flex-wrap: wrap;
        }
        .profile-header .avatar {
            width: 70px; height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            flex-shrink: 0;
            color: white;
        }
        .profile-header .info h2 { font-size: 1.3rem; margin-bottom: 0.2rem; color: white; }
        .profile-header .info .meta { font-size: 0.8rem; opacity: 0.9; margin-top: 2px; color: rgba(255,255,255,0.8); }

        /* Cards */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 1.2rem;
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
        .card-body { padding: 1.2rem; }

        /* Grid */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem;
            margin-bottom: 1.2rem;
        }

        /* Fields */
        .field {
            background: var(--bg2);
            padding: 0.85rem 1rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            margin-bottom: 0.75rem;
        }
        .field .label {
            font-size: 0.65rem;
            color: var(--text-dim);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .field .value {
            font-size: 0.85rem;
            color: var(--text);
            margin-top: 0.3rem;
            font-weight: 500;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
        }
        .badge-green { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .badge-blue { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .badge-purple { background: rgba(167,139,250,0.15); color: #a78bfa; }
        .badge-yellow { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-gray { background: rgba(255,255,255,0.1); color: var(--text-muted); }

        /* Timeline */
        .timeline { position: relative; padding-left: 1.8rem; padding-top: 0.5rem; }
        .timeline::before {
            content: '';
            position: absolute;
            left: 8px; top: 12px; bottom: 12px;
            width: 2px;
            background: var(--border);
        }
        .entry { position: relative; padding-bottom: 1rem; }
        .entry::before {
            content: '';
            position: absolute;
            left: -1.8rem; top: 10px;
            width: 14px; height: 14px;
            border-radius: 50%;
            background: var(--border);
            border: 3px solid var(--bg2);
        }
        .entry.current::before { background: var(--green); }
        .entry-card {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1rem 1.2rem;
        }
        .entry-card.current { border-left: 3px solid var(--green); }
        .entry-card .title { font-size: 0.9rem; font-weight: 700; color: var(--text); }
        .entry-card .company { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; margin-top: 2px; }
        .entry-card .dates { font-size: 0.7rem; color: var(--text-dim); margin-top: 2px; }
        .entry-card .desc { margin-top: 0.5rem; font-size: 0.75rem; color: var(--text-muted); line-height: 1.5; }

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

        .btn-primary {
            background: var(--accent);
            color: #fff;
            padding: 5px 14px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        .btn-primary:hover { background: var(--primary-dk); }

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

        .empty-state {
            text-align: center;
            padding: 2.5rem;
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
        .theme-toggle:hover { transform: scale(1.1); background: var(--surface-hv); }

        @media (max-width: 1024px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
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
        <a href="alumni.php" class="menu-item">
            <span class="menu-icon">🎓</span> Alumni
        </a>
        <a href="tracer.php" class="menu-item active">
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

<main class="main">

<?php if ($userId > 0 && $tracer): ?>
<!-- DETAIL VIEW -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Graduate Tracer Details</h1>
            <p>Viewing tracer response and employment history.</p>
        </div>
        <div class="topbar-right">
            <a href="tracer.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to List</a>
            <div class="admin-info"><i class="fas fa-user-circle"></i> <strong><?= escape($_SESSION['user_name']) ?></strong></div>
        </div>
    </div>

    <!-- Profile header -->
    <div class="profile-header">
        <div class="avatar"><?= strtoupper(substr($tracer['first_name'], 0, 1)) ?></div>
        <div class="info">
            <h2><?= escape($tracer['first_name'] . ' ' . $tracer['last_name']) ?></h2>
            <div class="meta"><i class="fas fa-envelope"></i> <?= escape($tracer['email']) ?></div>
            <div class="meta"><i class="fas fa-graduation-cap"></i> <?= escape($tracer['course'] ?? 'N/A') ?>
                <?php if (!empty($tracer['year_graduated'])): ?> · Class of <?= escape($tracer['year_graduated']) ?><?php endif; ?>
            </div>
            <div class="meta"><i class="fas fa-calendar"></i> Submitted: <?= formatDate($tracer['date_submitted']) ?></div>
        </div>
    </div>

    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h2><i class="fas fa-briefcase"></i> Employment Information</h2></div>
            <div class="card-body">
                <div class="field">
                    <div class="label">Employment Status</div>
                    <div class="value">
                        <?php
                        $badgeClass = match($tracer['employment_status'] ?? '') {
                            'employed'        => 'badge-green',
                            'self_employed'   => 'badge-blue',
                            'unemployed'      => 'badge-yellow',
                            'further_studies' => 'badge-purple',
                            default           => 'badge-gray'
                        };
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= labelType($tracer['employment_status']) ?></span>
                    </div>
                </div>
                <?php if (!empty($tracer['employer_name'])): ?>
                <div class="field">
                    <div class="label">Employer / Company</div>
                    <div class="value"><i class="fas fa-building"></i> <?= escape($tracer['employer_name']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($tracer['job_title'])): ?>
                <div class="field">
                    <div class="label">Job Title / Position</div>
                    <div class="value"><i class="fas fa-badge"></i> <?= escape($tracer['job_title']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($tracer['employment_sector'])): ?>
                <div class="field">
                    <div class="label">Employment Sector</div>
                    <div class="value"><?= ucfirst(str_replace('_', ' ', $tracer['employment_sector'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($tracer['degree_relevance'])): ?>
                <div class="field">
                    <div class="label">Degree Relevance</div>
                    <div class="value"><span class="badge badge-green"><?= relLabel($tracer['degree_relevance']) ?></span></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2><i class="fas fa-graduation-cap"></i> Education & Licensure</h2></div>
            <div class="card-body">
                <div class="field">
                    <div class="label">Further Studies</div>
                    <div class="value">
                        <?php if ((int)($tracer['further_studies'] ?? 0)): ?>
                            <span class="badge badge-purple"><i class="fas fa-check"></i> Yes</span>
                        <?php else: ?>
                            <span class="badge badge-gray"><i class="fas fa-times"></i> No</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($tracer['school_further_studies'])): ?>
                <div class="field">
                    <div class="label">School / University</div>
                    <div class="value"><i class="fas fa-university"></i> <?= escape($tracer['school_further_studies']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($tracer['professional_license'])): ?>
                <div class="field">
                    <div class="label">Professional License</div>
                    <div class="value"><i class="fas fa-certificate"></i> <?= escape($tracer['professional_license']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($employment !== null && $employment->num_rows > 0): ?>
    <div class="card">
        <div class="card-header"><h2><i class="fas fa-timeline"></i> Employment History</h2></div>
        <div class="card-body">
            <div class="timeline">
                <?php while ($emp = $employment->fetch_assoc()):
                    $isCurr  = (int)($emp['is_current'] ?? 0) === 1;
                    $endDate = $isCurr ? 'Present' : formatDate($emp['date_ended'] ?? null);
                ?>
                <div class="entry <?= $isCurr ? 'current' : '' ?>">
                    <div class="entry-card <?= $isCurr ? 'current' : '' ?>">
                        <div class="title"><strong><?= escape($emp['job_title']) ?></strong></div>
                        <div class="company"><i class="fas fa-building"></i> <?= escape($emp['company_name']) ?>
                            <?php if (!empty($emp['work_setup'])): ?> · <?= ucfirst($emp['work_setup']) ?><?php endif; ?>
                        </div>
                        <div class="dates"><i class="fas fa-calendar-alt"></i> <?= formatDate($emp['date_started'] ?? null) ?> — <?= $endDate ?></div>
                        <?php if (!empty($emp['description'])): ?>
                            <div class="desc"><i class="fas fa-align-left"></i> <?= nl2br(escape($emp['description'])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($emp['skills'])): ?>
                            <div class="desc"><i class="fas fa-code"></i> <strong>Skills:</strong> <?= escape($emp['skills']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($userId > 0 && !$tracer): ?>
<!-- User not found -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Graduate Tracer</h1>
            <p>View tracer responses from alumni.</p>
        </div>
        <div class="topbar-right">
            <a href="tracer.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to List</a>
        </div>
    </div>
    <div class="card">
        <div class="empty-state"><i class="fas fa-exclamation-triangle" style="font-size:2rem; opacity:0.5;"></i><p>No tracer record found for this user.</p></div>
    </div>

<?php else: ?>
<!-- LIST VIEW -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Graduate Tracer Responses</h1>
            <p>Track and analyze alumni employment outcomes.</p>
        </div>
        <div class="topbar-right">
            <div class="admin-info"><i class="fas fa-user-circle"></i> <strong><?= escape($_SESSION['user_name']) ?></strong></div>
            <div class="topbar-date"><i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats">
        <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-graduation-cap"></i></div><div class="stat-info"><div class="num"><?= $totalAlumni ?></div><div class="label">Total Alumni</div></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fas fa-chart-line"></i></div><div class="stat-info"><div class="num"><?= $tracerCount ?></div><div class="label">Tracer Responses</div></div></div>
        <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-briefcase"></i></div><div class="stat-info"><div class="num"><?= $employedCount ?></div><div class="label">Employed Alumni</div></div></div>
        <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-chart-simple"></i></div><div class="stat-info"><div class="num"><?= $tracerCount > 0 ? round(($employedCount / $tracerCount) * 100) : 0 ?>%</div><div class="label">Employment Rate</div></div></div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div style="font-size:0.75rem; color:var(--text-muted);">Showing <strong><?= $totalRows ?></strong> response<?= $totalRows !== 1 ? 's' : '' ?></div>
        <form method="GET" action="tracer.php" class="search-form">
            <input type="text" name="q" placeholder="Search by name, email, course…" value="<?= escape($search) ?>">
            <button type="submit"><i class="fas fa-search"></i> Search</button>
            <?php if ($search !== ''): ?>
                <a href="tracer.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table Card -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-list"></i> Tracer Responses</h2>
            <span class="badge badge-gray"><?= number_format($totalRows) ?> total</span>
        </div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr><th>Alumni</th><th>Course / Year</th><th>Employment Status</th><th>Date Submitted</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php if ($tracers && $tracers->num_rows > 0): ?>
                        <?php while ($t = $tracers->fetch_assoc()): ?>
                        <tr>
                            <td><div class="user-info"><?= escape($t['first_name'] . ' ' . $t['last_name']) ?></div><div class="user-meta"><i class="fas fa-envelope"></i> <?= escape($t['email']) ?></div><?php if (!empty($t['student_id'])): ?><div class="user-meta"><i class="fas fa-id-card"></i> <?= escape($t['student_id']) ?></div><?php endif; ?></td>
                            <td><div class="user-info"><?= escape($t['course'] ?? 'N/A') ?></div><div class="user-meta"><i class="fas fa-calendar"></i> Grad: <?= escape($t['year_graduated'] ?? 'N/A') ?></div></td>
                            <td><?php $bc = match($t['employment_status'] ?? '') { 'employed' => 'badge-green', 'self_employed' => 'badge-blue', 'unemployed' => 'badge-yellow', 'further_studies' => 'badge-purple', default => 'badge-gray' }; ?><span class="badge <?= $bc ?>"><?= labelType($t['employment_status']) ?></span><?php if (!empty($t['employer_name'])): ?><div class="user-meta"><i class="fas fa-building"></i> <?= escape($t['employer_name']) ?></div><?php endif; ?></td>
                            <td><?= timeAgo($t['date_submitted']) ?></td>
                            <td><a href="tracer.php?user=<?= intval($t['user_id']) ?>" class="btn-primary"><i class="fas fa-eye"></i> View Details</a></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="empty-state"><?= $search ? "No results found for \"" . escape($search) . "\"." : "No tracer responses yet." ?><?php if (!$search): ?><br><span style="font-size:0.75rem;">Alumni who have completed the tracer survey will appear here.</span><?php endif; ?></td></tr>
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
            <?php if ($i === $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= http_build_query(['q' => $search, 'page' => $i]) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(['q' => $search, 'page' => $page + 1]) ?>">Next <i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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
<?php $conn->close(); ?>