<?php
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

$statusFilter = $_GET['status'] ?? 'all';
$roleFilter   = $_GET['role'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$page         = intval($_GET['page'] ?? 1);
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$allowedStatuses = ['all','active','inactive','pending'];
$allowedRoles    = ['all','student','alumni','registrar','admin'];
if (!in_array($statusFilter, $allowedStatuses)) $statusFilter = 'all';
if (!in_array($roleFilter, $allowedRoles))       $roleFilter = 'all';

// Build query
$sql = "SELECT id, student_id, first_name, last_name, email, role, status, created_at FROM users";
$where = [];
$params = [];
$types = '';

if ($statusFilter !== 'all') {
    $where[] = "status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($roleFilter !== 'all') {
    $where[] = "role = ?";
    $params[] = $roleFilter;
    $types .= 's';
}
if ($search !== '') {
    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR student_id LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result();

// Get total count
$countSql = "SELECT COUNT(*) AS total FROM users";
$countWhere = $where;
if (!empty($countWhere)) {
    $countSql .= " WHERE " . implode(' AND ', $countWhere);
}
$countStmt = $conn->prepare($countSql);
$countParams = $params;
array_pop($countParams); // remove OFFSET
array_pop($countParams); // remove LIMIT
$countTypes = substr($types, 0, -2);
if (!empty($countTypes)) {
    $countStmt->bind_param($countTypes, ...$countParams);
}
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);
$countStmt->close();

// Get pending accounts count for sidebar badge
$pendingAccs = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];

// Get counts for stats cards
$totalActive = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'active'")->fetch_assoc()['c'];
$totalPending = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];
$totalInactive = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'inactive'")->fetch_assoc()['c'];
$totalStudents = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'student'")->fetch_assoc()['c'];

// Handle status updates
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $userId = intval($_POST['user_id']);
    $newStatus = $_POST['new_status'] ?? '';
    $allowedNewStatuses = ['active','inactive','pending'];

    if (!in_array($newStatus, $allowedNewStatuses)) {
        $error = "Invalid status.";
    } elseif ($userId === $_SESSION['user_id']) {
        $error = "You cannot change your own account status.";
    } else {
        $upStmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $upStmt->bind_param("si", $newStatus, $userId);
        if ($upStmt->execute()) {
            $success = "Account status updated successfully.";
        } else {
            $error = "Failed to update status.";
        }
        $upStmt->close();
        header("Location: accounts.php?status=$statusFilter&role=$roleFilter&q=" . urlencode($search) . "&page=$page");
        exit();
    }
}

// Helper functions
function escape($v) { return htmlspecialchars($v ?? ''); }
function timeAgo($datetime) {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}
function statusBadge($status) {
    $classes = ['active' => 'badge-active', 'inactive' => 'badge-inactive', 'pending' => 'badge-pending'];
    $class = $classes[$status] ?? 'badge-pending';
    $label = ucfirst($status);
    return "<span class='badge $class'>$label</span>";
}
function roleIcon($role) {
    $icons = ['student' => '🎓', 'alumni' => '👨‍🎓', 'registrar' => '📋', 'admin' => '🛡️'];
    return $icons[$role] ?? '👤';
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Accounts — ADFC DocuGo</title>
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
        .alert-error   { background: rgba(248,113,113,0.15); color: var(--red); border-left: 4px solid var(--red); }

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

        /* Filters & Tabs */
        .filters {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1.2rem;
        }
        .tab-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            background: var(--surface);
            padding: 0.5rem;
            border-radius: var(--radius-md);
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
        }
        .tab:hover { background: var(--bg2); color: var(--accent); }
        .tab.active { background: var(--accent); color: #fff; }
        .tab-divider {
            width: 1px;
            background: var(--border);
            margin: 0 0.25rem;
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

        .user-name { font-weight: 600; color: var(--text); font-size: 0.8rem; }
        .user-id { font-size: 0.65rem; color: var(--text-dim); margin-top: 2px; }

        .role-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--bg2);
            color: var(--text-muted);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
        }
        .badge-active   { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .badge-inactive { background: rgba(248,113,113,0.15); color: #f87171; }
        .badge-pending  { background: rgba(251,191,36,0.15); color: #fbbf24; }

        /* Buttons */
        .btn {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.12s;
            font-family: inherit;
        }
        .btn-secondary {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-muted);
        }
        .btn-secondary:hover {
            background: var(--surface-hv);
            border-color: var(--accent);
            color: var(--accent);
        }
        .btn-primary {
            background: var(--accent);
            color: #fff;
        }
        .btn-primary:hover { background: var(--primary-dk); }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 2.5rem;
            color: var(--text-dim);
        }
        .empty-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }

        /* Pagination */
        .pagination-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.2rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .pagination {
            display: flex;
            gap: 0.3rem;
        }
        .pagination a, .pagination .current {
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

        /* Modal */
        .modal {
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
        .modal.show {
            visibility: visible;
            opacity: 1;
        }
        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 420px;
            overflow: hidden;
        }
        .modal-header {
            padding: 1rem 1.5rem;
            background: var(--accent);
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            font-weight: 700;
        }
        .modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.8;
        }
        .modal-close:hover { opacity: 1; }
        .modal-user-info {
            padding: 1rem 1.5rem;
            background: var(--bg2);
            border-bottom: 1px solid var(--border);
        }
        .modal-user-info strong {
            display: block;
            font-size: 0.9rem;
            color: var(--text);
        }
        .modal-user-info span {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--text-muted);
        }
        .form-select {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            background: var(--bg2);
            color: var(--text);
            font-family: inherit;
        }
        .modal-buttons {
            padding: 1rem 1.5rem;
            display: flex;
            gap: 0.8rem;
            justify-content: flex-end;
            border-top: 1px solid var(--border);
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
            .stats-mini { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .stats-mini { grid-template-columns: 1fr; }
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
        </a>
        <a href="accounts.php" class="menu-item active">
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
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>👥 User Accounts</h1>
            <p>Manage user accounts, roles, and account statuses.</p>
        </div>
        <div class="topbar-right">
            <div class="admin-info"><i class="fas fa-user-circle"></i> <strong><?= escape($_SESSION['user_name']) ?></strong></div>
            <div class="topbar-date"><i class="fas fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= escape($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <div class="stats-mini">
        <div class="stat-card"><div class="stat-num"><?= $totalActive ?></div><div class="stat-label">Active Users</div><div class="stat-sub">Can log in</div></div>
        <div class="stat-card"><div class="stat-num"><?= $totalPending ?></div><div class="stat-label">Pending Approval</div><div class="stat-sub">Awaiting activation</div></div>
        <div class="stat-card"><div class="stat-num"><?= $totalInactive ?></div><div class="stat-label">Inactive</div><div class="stat-sub">Disabled accounts</div></div>
        <div class="stat-card"><div class="stat-num"><?= $totalStudents ?></div><div class="stat-label">Students</div><div class="stat-sub">+ alumni & staff</div></div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div class="tab-group">
            <?php
            $statusTabs = ['all' => 'All Status', 'active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Pending'];
            foreach ($statusTabs as $k => $v):
                $qs = http_build_query(['status' => $k, 'role' => $roleFilter, 'q' => $search, 'page' => 1]);
            ?>
                <a href="?<?= $qs ?>" class="tab <?= $statusFilter === $k ? 'active' : '' ?>"><?= $v ?></a>
            <?php endforeach; ?>

            <span class="tab-divider"></span>

            <?php
            $roleTabs = ['all' => 'All Roles', 'student' => 'Students', 'alumni' => 'Alumni', 'registrar' => 'Registrar', 'admin' => 'Admins'];
            foreach ($roleTabs as $k => $v):
                $qs = http_build_query(['status' => $statusFilter, 'role' => $k, 'q' => $search, 'page' => 1]);
            ?>
                <a href="?<?= $qs ?>" class="tab <?= $roleFilter === $k ? 'active' : '' ?>"><?= $v ?></a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="search-form">
            <input type="hidden" name="status" value="<?= escape($statusFilter) ?>">
            <input type="hidden" name="role"   value="<?= escape($roleFilter) ?>">
            <input type="text"   name="q"      placeholder="Search name, email, or ID…" value="<?= escape($search) ?>">
            <button type="submit"><i class="fas fa-search"></i> Search</button>
        </form>
    </div>

    <!-- Table Card -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-list"></i> Accounts</h2>
            <span class="count-badge"><?= number_format($totalRows) ?> total</span>
        </div>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($users->num_rows > 0): ?>
                        <?php while ($u = $users->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="user-name"><?= escape($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                    <?php if (!empty($u['student_id'])): ?>
                                        <div class="user-id">ID: <?= escape($u['student_id']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= escape($u['email']) ?></td>
                                <td><span class="role-chip"><?= roleIcon($u['role']) ?> <?= ucfirst(escape($u['role'])) ?></span></td>
                                <td><?= statusBadge($u['status']) ?></td>
                                <td style="font-size:0.75rem;"><?= timeAgo($u['created_at']) ?></td>
                                <td>
                                    <button class="btn btn-secondary"
                                        onclick="openModal(<?= $u['id'] ?>, '<?= escape($u['status']) ?>', '<?= escape($u['first_name'] . ' ' . $u['last_name']) ?>', '<?= escape($u['email']) ?>')">
                                        <i class="fas fa-edit"></i> Update
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6"><div class="empty-state"><div class="empty-icon">👤</div><p>No accounts found matching your filters.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination-wrap">
            <span>Showing page <?= $page ?> of <?= $totalPages ?></span>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(['status' => $statusFilter, 'role' => $roleFilter, 'q' => $search, 'page' => $page - 1]) ?>"><i class="fas fa-chevron-left"></i> Prev</a>
                <?php endif; ?>

                <?php
                $range = 2;
                for ($i = max(1, $page - $range); $i <= min($totalPages, $page + $range); $i++):
                    $qs = http_build_query(['status' => $statusFilter, 'role' => $roleFilter, 'q' => $search, 'page' => $i]);
                ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= $qs ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(['status' => $statusFilter, 'role' => $roleFilter, 'q' => $search, 'page' => $page + 1]) ?>">Next <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</main>

<!-- Update Status Modal -->
<div id="updateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit"></i> Update Account Status</h3>
            <button class="modal-close" onclick="closeModal()" title="Close">✕</button>
        </div>

        <div class="modal-user-info">
            <strong id="modalUserName">—</strong>
            <span id="modalUserEmail">—</span>
        </div>

        <form method="POST">
            <input type="hidden" name="user_id" id="modalUserId">
            <div style="padding: 1.2rem 1.5rem;">
                <label class="form-label" for="modalStatus">New Status</label>
                <select name="new_status" id="modalStatus" class="form-select">
                    <option value="active">✅ Active</option>
                    <option value="inactive">🔴 Inactive</option>
                    <option value="pending">🟡 Pending</option>
                </select>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" name="update_status" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
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
function openModal(id, status, name, email) {
    document.getElementById('modalUserId').value    = id;
    document.getElementById('modalStatus').value   = status;
    document.getElementById('modalUserName').textContent  = name;
    document.getElementById('modalUserEmail').textContent = email;
    document.getElementById('updateModal').classList.add('show');
}

function closeModal() {
    document.getElementById('updateModal').classList.remove('show');
}

// Close on backdrop click
document.getElementById('updateModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

</body>
</html>