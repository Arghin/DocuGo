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
    return ['active' => 'green', 'inactive' => 'red', 'pending' => 'yellow'][$s] ?? 'gray';
}
function roleIcon($r) {
    return ['student' => '🎓', 'alumni' => '👨‍🎓', 'registrar' => '📋', 'admin' => '🛡️'][$r] ?? '👤';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Accounts — DocuGo Admin</title>
    <style>
        /* ─── Reset & Base ─────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            color: #111827;
            min-height: 100vh;
            display: flex;
            font-size: 14px;
            line-height: 1.5;
        }

        /* ─── Sidebar ───────────────────────────────────── */
        .sidebar {
            width: 220px;
            background: #1a56db;
            color: #fff;
            min-height: 100vh;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0;
            height: 100%;
            z-index: 100;
        }

        .sidebar-brand {
            padding: 1.4rem 1.2rem;
            font-size: 1.5rem;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .sidebar-brand small {
            display: block;
            font-size: 0.68rem;
            font-weight: 400;
            opacity: 0.7;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .sidebar-menu { padding: 1rem 0; flex: 1; overflow-y: auto; }

        .menu-label {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            opacity: 0.5;
            padding: 0.8rem 1.2rem 0.3rem;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.6rem 1.2rem;
            color: rgba(255,255,255,0.82);
            text-decoration: none;
            font-size: 0.855rem;
            font-weight: 500;
            transition: background 0.15s, color 0.15s;
            border-left: 3px solid transparent;
        }

        .menu-item:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        .menu-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left-color: #fff;
            font-weight: 600;
        }

        .menu-item .icon {
            font-size: 1rem;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar-footer {
            padding: 1rem 1.2rem;
            border-top: 1px solid rgba(255,255,255,0.15);
            font-size: 0.8rem;
        }

        .sidebar-footer a {
            color: rgba(255,255,255,0.82);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: color 0.15s;
        }

        .sidebar-footer a:hover { color: #fff; }

        /* ─── Main Content ──────────────────────────────── */
        .main {
            margin-left: 220px;
            flex: 1;
            padding: 2rem;
            min-width: 0;
        }

        /* ─── Topbar ────────────────────────────────────── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.6rem;
            gap: 1rem;
        }

        .topbar h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #111827;
        }

        .topbar .admin-info {
            font-size: 0.82rem;
            color: #6b7280;
            white-space: nowrap;
        }

        .topbar .admin-info strong {
            color: #111827;
            font-weight: 600;
        }

        /* ─── Alerts ────────────────────────────────────── */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #059669;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }

        /* ─── Filters Bar ───────────────────────────────── */
        .filters {
            background: #fff;
            padding: 0.9rem 1.1rem;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .tab-group {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.35rem;
        }

        .tab-divider {
            width: 1px;
            height: 18px;
            background: #e5e7eb;
            margin: 0 0.25rem;
        }

        .tab {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            text-decoration: none;
            background: #f1f5f9;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 600;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
        }

        .tab:hover { background: #e2e8f0; color: #334155; }

        .tab.active {
            background: #1a56db;
            color: #fff;
        }

        .search-form {
            display: flex;
            gap: 0.4rem;
            align-items: center;
        }

        .search-form input {
            padding: 0.48rem 0.8rem;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font-size: 0.835rem;
            width: 240px;
            color: #111827;
            background: #f9fafb;
            transition: border-color 0.15s, background 0.15s;
            font-family: inherit;
        }

        .search-form input:focus {
            outline: none;
            border-color: #1a56db;
            background: #fff;
        }

        .search-form button {
            padding: 0.48rem 0.85rem;
            border: none;
            border-radius: 7px;
            background: #1a56db;
            color: #fff;
            cursor: pointer;
            font-size: 0.835rem;
            font-weight: 600;
            font-family: inherit;
            transition: background 0.15s;
            white-space: nowrap;
        }

        .search-form button:hover { background: #1447c0; }

        /* ─── Card ──────────────────────────────────────── */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .card-header {
            padding: 0.9rem 1.1rem;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
        }

        .card-header .count-badge {
            background: #f1f5f9;
            color: #475569;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
        }

        /* ─── Table ─────────────────────────────────────── */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.845rem;
        }

        thead tr {
            background: #f9fafb;
        }

        th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            color: #374151;
        }

        tbody tr:last-child td { border-bottom: none; }

        tbody tr:hover { background: #fafafa; }

        /* ─── User Cell ─────────────────────────────────── */
        .user-name {
            font-weight: 600;
            color: #111827;
            font-size: 0.875rem;
        }

        .user-id {
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 1px;
        }

        /* ─── Badges ────────────────────────────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .badge-green  { background: #d1fae5; color: #065f46; }
        .badge-red    { background: #fee2e2; color: #991b1b; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-gray   { background: #f3f4f6; color: #374151; }

        .badge-green::before  { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #059669; flex-shrink: 0; }
        .badge-red::before    { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #dc2626; flex-shrink: 0; }
        .badge-yellow::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #d97706; flex-shrink: 0; }
        .badge-gray::before   { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #9ca3af; flex-shrink: 0; }

        /* Role chip */
        .role-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #374151;
        }

        /* ─── Buttons ───────────────────────────────────── */
        .btn {
            padding: 5px 11px;
            border: none;
            border-radius: 6px;
            font-size: 0.78rem;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            font-family: inherit;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
        }

        .btn-primary   { background: #1a56db; color: #fff; }
        .btn-primary:hover { background: #1447c0; }
        .btn-secondary { background: #f1f5f9; color: #374151; border: 1px solid #e2e8f0; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger    { background: #fee2e2; color: #991b1b; }
        .btn-danger:hover { background: #fecaca; }

        /* ─── Empty State ───────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 3.5rem 1rem;
            color: #9ca3af;
        }

        .empty-state .empty-icon {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
        }

        .empty-state p {
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* ─── Pagination ────────────────────────────────── */
        .pagination-wrap {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            font-size: 0.82rem;
            color: #6b7280;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .pagination {
            display: flex;
            gap: 0.3rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.45rem 0.75rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            transition: background 0.15s;
        }

        .pagination a {
            background: #fff;
            color: #374151;
            border: 1px solid #e5e7eb;
        }

        .pagination a:hover { background: #f3f4f6; }

        .pagination .current {
            background: #1a56db;
            color: #fff;
            border: 1px solid #1a56db;
        }

        /* ─── Modal ─────────────────────────────────────── */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal.show { display: flex; }

        .modal-content {
            background: #fff;
            padding: 1.5rem;
            border-radius: 12px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.2rem;
        }

        .modal-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.1rem;
            cursor: pointer;
            color: #9ca3af;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background 0.15s;
        }

        .modal-close:hover { background: #f3f4f6; color: #374151; }

        .modal-user-info {
            background: #f9fafb;
            border: 1px solid #f3f4f6;
            border-radius: 8px;
            padding: 0.7rem 0.9rem;
            margin-bottom: 1.1rem;
            font-size: 0.82rem;
            color: #374151;
        }

        .modal-user-info strong {
            display: block;
            font-size: 0.9rem;
            color: #111827;
            margin-bottom: 2px;
        }

        .form-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.35rem;
        }

        .form-select {
            width: 100%;
            padding: 0.55rem 0.8rem;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font-size: 0.875rem;
            color: #111827;
            background: #fff;
            font-family: inherit;
            cursor: pointer;
            transition: border-color 0.15s;
        }

        .form-select:focus {
            outline: none;
            border-color: #1a56db;
        }

        .modal-buttons {
            margin-top: 1.2rem;
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        /* ─── Responsive ────────────────────────────────── */
        @media (max-width: 768px) {
            .main { margin-left: 0; padding: 1rem; }
            .sidebar { display: none; }
            .filters { flex-direction: column; align-items: stretch; }
            .search-form { width: 100%; }
            .search-form input { width: 100%; flex: 1; }
            table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        }
    </style>
</head>
<body>

<!-- ─── Sidebar ─────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        DocuGo
        <small>Admin Panel</small>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Dashboard</div>
        <a href="dashboard.php" class="menu-item">
            <span class="icon">🏠</span> Dashboard
        </a>
        <a href="requests.php" class="menu-item">
            <span class="icon">📄</span> Document Requests
        </a>
        <a href="accounts.php" class="menu-item active">
            <span class="icon">👥</span> User Accounts
        </a>
        <div class="menu-label">Records</div>
        <a href="alumni.php" class="menu-item">
            <span class="icon">🎓</span> Alumni / Graduates
        </a>
        <a href="tracer.php" class="menu-item">
            <span class="icon">📊</span> Graduate Tracer
        </a>
        <a href="reports.php" class="menu-item">
            <span class="icon">📈</span> Reports
        </a>

        <div class="menu-label">Communication</div>
        <a href="announcements.php" class="menu-item">
            <span class="icon">📢</span> Announcements
        </a>

        <div class="menu-label">Settings</div>
        <a href="document_types.php" class="menu-item">
            <span class="icon">⚙️</span> Document Types
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- ─── Main ────────────────────────────────────────────── -->
<main class="main">

    <div class="topbar">
        <h1>👥 User Accounts</h1>
        <div class="admin-info">
            Logged in as <strong><?= e($_SESSION['user_name']) ?></strong>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= e($error) ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="filters">
        <div class="tab-group">
            <?php
            $statusTabs = ['all' => 'All Status', 'active' => 'Active', 'inactive' => 'Inactive', 'pending' => 'Pending'];
            $roleTabs   = ['all' => 'All Roles', 'student' => 'Students', 'alumni' => 'Alumni', 'registrar' => 'Registrar', 'admin' => 'Admins'];

            foreach ($statusTabs as $k => $v):
                $qs = http_build_query(['status' => $k, 'role' => $roleFilter, 'q' => $search, 'page' => 1]);
            ?>
                <a href="?<?= $qs ?>" class="tab <?= $statusFilter === $k ? 'active' : '' ?>"><?= $v ?></a>
            <?php endforeach; ?>

            <div class="tab-divider"></div>

            <?php foreach ($roleTabs as $k => $v):
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
            <h2>Accounts</h2>
            <span class="count-badge"><?= number_format($totalRows) ?> total</span>
        </div>

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
                                <span class="role-chip">
                                    <?= roleIcon($u['role']) ?> <?= ucfirst(e($u['role'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= badgeClass($u['status']) ?>">
                                    <?= ucfirst($u['status']) ?>
                                </span>
                            </td>
                            <td style="color:#6b7280;font-size:0.82rem;white-space:nowrap;">
                                <?= date('M d, Y', strtotime($u['created_at'])) ?>
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

<!-- ─── Update Status Modal ──────────────────────────────── -->
<div id="updateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Update Account Status</h3>
            <button class="modal-close" onclick="closeModal()" title="Close">✕</button>
        </div>

        <div class="modal-user-info" id="modalUserInfo">
            <strong id="modalUserName">—</strong>
            <span id="modalUserEmail">—</span>
        </div>

        <form method="POST">
            <input type="hidden" name="user_id" id="modalUserId">

            <label class="form-label" for="modalStatus">New Status</label>
            <select name="new_status" id="modalStatus" class="form-select">
                <option value="active">✅ Active</option>
                <option value="inactive">🔴 Inactive</option>
                <option value="pending">🟡 Pending</option>
            </select>

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