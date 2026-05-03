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

function e($v) { return htmlspecialchars($v ?? ''); }
function badgeClass($s) {
    return ['active' => 'active', 'inactive' => 'inactive', 'pending' => 'pending'][$s] ?? 'gray';
}
function roleIcon($r) {
    return ['student' => '🎓', 'alumni' => '👨‍🎓', 'registrar' => '📋', 'admin' => '🛡️'][$r] ?? '👤';
}
function ago($datetime) {
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
    <title>User Accounts — DocuGo Admin</title>
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

        /* ── Alert ────────────────────────────────────── */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 10px;
            margin-bottom: 1.2rem;
            font-size: 0.85rem;
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }

        /* ── Stats mini cards ─────────────────────────── */
        .stats-mini {
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

        /* ── Filters & Tabs ───────────────────────────── */
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
            background: var(--card);
            padding: 0.5rem;
            border-radius: 14px;
            border: 1px solid var(--border-lt);
        }
        .tab {
            padding: 0.5rem 1rem;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-3);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s;
        }
        .tab:hover { background: var(--bg); color: var(--blue); }
        .tab.active { background: var(--blue); color: #fff; }
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
            padding: 0.45rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.8rem;
            width: 240px;
        }
        .search-form button {
            padding: 0.45rem 1rem;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
        }

        /* ── Card & Table ─────────────────────────────── */
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
        .count-badge {
            background: var(--blue-lt);
            color: var(--blue);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
        }

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

        .user-name { font-weight: 600; color: var(--text); font-size: 0.845rem; }
        .user-id { font-size: 0.7rem; color: var(--text-4); margin-top: 1px; }

        .role-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            background: var(--bg);
            color: var(--text-2);
        }

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
        .badge::before {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
        }
        .badge-active   { background: #d1fae5; color: #065f46; } .badge-active::before   { background: #10b981; }
        .badge-inactive { background: #fee2e2; color: #991b1b; } .badge-inactive::before { background: #ef4444; }
        .badge-pending  { background: #fef3c7; color: #92400e; } .badge-pending::before  { background: #d97706; }

        /* Buttons */
        .btn {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.12s;
        }
        .btn-secondary {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text-2);
        }
        .btn-secondary:hover {
            background: var(--blue-lt);
            border-color: var(--blue);
            color: var(--blue);
        }
        .btn-primary {
            background: var(--blue);
            color: #fff;
        }
        .btn-primary:hover {
            background: var(--blue-dk);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 2.5rem;
            color: var(--text-4);
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
            color: var(--text-3);
        }
        .pagination {
            display: flex;
            gap: 0.3rem;
        }
        .pagination a, .pagination .current {
            padding: 0.4rem 0.8rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-2);
            font-size: 0.8rem;
        }
        .pagination .current {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }

        /* Modal */
        .modal {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(2px);
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
            background: var(--card);
            border-radius: 20px;
            width: 90%;
            max-width: 420px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }
        .modal-header {
            padding: 1rem 1.5rem;
            background: var(--blue);
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
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
            background: var(--blue-lt);
            border-bottom: 1px solid var(--border);
        }
        .modal-user-info strong {
            display: block;
            font-size: 0.95rem;
            color: var(--text);
        }
        .modal-user-info span {
            font-size: 0.75rem;
            color: var(--text-3);
        }
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--text-2);
        }
        .form-select {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
            font-family: inherit;
        }
        .modal-buttons {
            padding: 1rem 1.5rem;
            display: flex;
            gap: 0.8rem;
            justify-content: flex-end;
            border-top: 1px solid var(--border-lt);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .stats-mini { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 700px) {
            .stats-mini { grid-template-columns: 1fr 1fr; }
            .filters { flex-direction: column; align-items: stretch; }
            .search-form { justify-content: stretch; }
            .search-form input { flex: 1; }
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
        </a>
        <a href="accounts.php" class="menu-item active">
            <span class="menu-icon">👥</span> User Accounts
            <?php if ($pendingAccs > 0): ?>
                <span class="menu-badge"><?= $pendingAccs ?></span>
            <?php endif; ?>
        </a>
        <div class="menu-section">Records</div>
        <a href="alumni.php" class="menu-item"><span class="menu-icon">🎓</span> Alumni</a>
        <a href="tracer.php" class="menu-item"><span class="menu-icon">📊</span> Graduate Tracer</a>
        <a href="reports.php" class="menu-item"><span class="menu-icon">📈</span> Reports</a>
        <div class="menu-section">Communication</div>
        <a href="announcements.php" class="menu-item"><span class="menu-icon">📢</span> Announcements</a>
        <div class="menu-section">Settings</div>
        <a href="document_types.php" class="menu-item"><span class="menu-icon">⚙️</span> Document Types</a>
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
            <h1>👥 User Accounts</h1>
            <p>Manage user accounts, roles, and account statuses.</p>
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

    <!-- Alerts -->
    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= e($error) ?></div>
    <?php endif; ?>

    <!-- Quick Stats -->
    <?php
    // Get counts for stats cards
    $totalActive = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'active'")->fetch_assoc()['c'];
    $totalPending = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];
    $totalInactive = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'inactive'")->fetch_assoc()['c'];
    $totalStudents = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'student'")->fetch_assoc()['c'];
    ?>
    <div class="stats-mini">
        <div class="stat-card">
            <div class="stat-num"><?= $totalActive ?></div>
            <div class="stat-label">Active Users</div>
            <div class="stat-sub">Can log in</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $totalPending ?></div>
            <div class="stat-label">Pending Approval</div>
            <div class="stat-sub">Awaiting activation</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $totalInactive ?></div>
            <div class="stat-label">Inactive</div>
            <div class="stat-sub">Disabled accounts</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $totalStudents ?></div>
            <div class="stat-label">Students</div>
            <div class="stat-sub">+ alumni & staff</div>
        </div>
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
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <input type="hidden" name="role"   value="<?= e($roleFilter) ?>">
            <input type="text"   name="q"      placeholder="Search name, email, or ID…" value="<?= e($search) ?>">
            <button type="submit">🔍 Search</button>
        </form>
    </div>

    <!-- Table Card -->
    <div class="card">
        <div class="card-header">
            <h2>📋 Accounts</h2>
            <span class="count-badge"><?= number_format($totalRows) ?> total</span>
        </div>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users->num_rows > 0): ?>
                        <?php while ($u = $users->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="user-name"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                    <?php if (!empty($u['student_id'])): ?>
                                        <div class="user-id">ID: <?= e($u['student_id']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($u['email']) ?></td>
                                <td>
                                    <span class="role-chip"><?= roleIcon($u['role']) ?> <?= ucfirst(e($u['role'])) ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= badgeClass($u['status']) ?>">
                                        <?= ucfirst($u['status']) ?>
                                    </span>
                                </td>
                                <td style="color:var(--text-3); font-size:0.75rem; white-space:nowrap;">
                                    <?= ago($u['created_at']) ?>
                                </td>
                                <td>
                                    <button class="btn btn-secondary"
                                        onclick="openModal(<?= $u['id'] ?>, '<?= e($u['status']) ?>', '<?= e($u['first_name'] . ' ' . $u['last_name']) ?>', '<?= e($u['email']) ?>')">
                                        ✏️ Update
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="empty-icon">👤</div>
                                    <p>No accounts found matching your filters.</p>
                                </div>
                            </td>
                        </tr>
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
                    <a href="?<?= http_build_query(['status' => $statusFilter, 'role' => $roleFilter, 'q' => $search, 'page' => $page - 1]) ?>">‹ Prev</a>
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
                    <a href="?<?= http_build_query(['status' => $statusFilter, 'role' => $roleFilter, 'q' => $search, 'page' => $page + 1]) ?>">Next ›</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</main>

<!-- Update Status Modal -->
<div id="updateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Update Account Status</h3>
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
                <button type="submit" name="update_status" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
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
<?php
$stmt->close();
$conn->close();
?>
```