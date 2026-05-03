<?php
require_once '../includes/config.php';
require_once '../includes/announcement_helper.php';
requireAdmin();

$conn = getConnection();

// Ensure announcements table exists
$conn->query("
    CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        target_type ENUM('all', 'user') DEFAULT 'all',
        target_user_id INT DEFAULT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Get counts for sidebar badges
$pendingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$pendingAccs = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];

// ── AJAX handlers (must be before any HTML output) ──────────────
if (isset($_GET['ajax_search_users'])) {
    $users = searchUsers($conn, $_GET['q'] ?? '');
    header('Content-Type: application/json');
    echo json_encode($users);
    exit();
}
if (isset($_GET['ajax_get_announcement'])) {
    $ann = getAnnouncementById($conn, intval($_GET['id']));
    header('Content-Type: application/json');
    echo json_encode($ann ?: []);
    exit();
}
if (isset($_GET['ajax_get_user'])) {
    $id = intval($_GET['id']);
    $user = $conn->query("SELECT id, first_name, last_name, email, role FROM users WHERE id = $id")->fetch_assoc();
    header('Content-Type: application/json');
    echo json_encode($user ?: []);
    exit();
}

// ── Handle CRUD operations ───────────────────────────────────
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $messageText = trim($_POST['message'] ?? '');
        $targetType = $_POST['target_type'] ?? 'all';
        $targetUserId = !empty($_POST['target_user_id']) ? intval($_POST['target_user_id']) : null;

        if (empty($title) || empty($messageText)) {
            $message = 'Title and message are required.';
            $messageType = 'error';
        } else {
            if ($action === 'create') {
                $result = createAnnouncement($conn, $title, $messageText, $targetType, $targetUserId, $_SESSION['user_id']);
                if ($result['success']) {
                    $message = 'Announcement created successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to create announcement.';
                    $messageType = 'error';
                }
            } else {
                $id = intval($_POST['announcement_id'] ?? 0);
                $result = updateAnnouncement($conn, $id, $title, $messageText, $targetType, $targetUserId);
                if ($result) {
                    $message = 'Announcement updated successfully.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update announcement.';
                    $messageType = 'error';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['announcement_id'] ?? 0);
        if (deleteAnnouncement($conn, $id)) {
            $message = 'Announcement deleted successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to delete announcement.';
            $messageType = 'error';
        }
    }

    // Redirect to avoid form resubmission
    header("Location: announcements.php?msg=" . urlencode($message) . "&msgtype=" . $messageType);
    exit();
}

// Get flash message
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['msgtype'] ?? 'success';
}

// Fetch all announcements
$announcements = $conn->query("
    SELECT a.*, u.first_name, u.last_name, u.email as creator_email
    FROM announcements a
    LEFT JOIN users u ON a.created_by = u.id
    ORDER BY a.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Get stats
$totalAnnouncements = count($announcements);
$targetedAnnouncements = count(array_filter($announcements, fn($a) => $a['target_type'] === 'user'));
$systemWideAnnouncements = count(array_filter($announcements, fn($a) => $a['target_type'] === 'all'));

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }

function timeAgo($datetime) {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . ' minutes ago';
    if ($diff < 86400) return floor($diff/3600) . ' hours ago';
    if ($diff < 604800) return floor($diff/86400) . ' days ago';
    return date('M d, Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements — DocuGo Admin</title>
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

        /* ── Stats Cards ──────────────────────────────── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.4rem;
        }
        .stat-card {
            background: var(--card);
            border-radius: 12px;
            padding: 1rem 1.1rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }
        .stat-icon.blue   { background: var(--blue-lt); }
        .stat-icon.green  { background: var(--green-lt); }
        .stat-icon.yellow { background: var(--yellow-lt); }
        .stat-info .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
        }
        .stat-info .stat-label {
            font-size: 0.7rem;
            color: var(--text-4);
            font-weight: 500;
            letter-spacing: 0.04em;
        }

        /* ── Card ─────────────────────────────────────── */
        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card-header {
            padding: 0.9rem 1.2rem;
            border-bottom: 1px solid var(--border-lt);
            background: #fafafa;
        }
        .card-header h2 {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
        }
        .card-body { padding: 1.2rem; }

        /* ── Form ─────────────────────────────────────── */
        .form-grid {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .form-group.full { width: 100%; }
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
            color: var(--text-2);
        }
        .required { color: #e11d48; }
        .form-control {
            width: 100%;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
            font-family: inherit;
            transition: border-color 0.15s;
        }
        .form-control:focus { outline: none; border-color: var(--blue); }
        textarea.form-control { min-height: 100px; resize: vertical; }

        .target-selector {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .radio-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .radio-label input { cursor: pointer; }

        .user-search-box {
            display: none;
        }
        .user-search-box.open {
            display: block;
        }
        .search-input-wrapper {
            position: relative;
        }
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.85rem;
            opacity: 0.6;
        }
        .search-input-wrapper input {
            padding-left: 32px;
        }
        .user-results {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-top: 0.5rem;
            max-height: 200px;
            overflow-y: auto;
            z-index: 10;
        }
        .user-result-item {
            padding: 0.6rem 0.85rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-lt);
            transition: background 0.12s;
        }
        .user-result-item:hover { background: var(--blue-lt); }
        .user-result-item .name { font-weight: 600; font-size: 0.85rem; }
        .user-result-item .meta { font-size: 0.7rem; color: var(--text-4); margin-top: 2px; }
        .selected-user {
            background: var(--blue-lt);
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            margin-top: 0.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
        }
        .selected-user .remove {
            cursor: pointer;
            color: var(--red);
            font-weight: 700;
            margin-left: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.55rem 1.2rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.12s;
            font-family: inherit;
        }
        .btn-primary {
            background: var(--blue);
            color: #fff;
        }
        .btn-primary:hover { background: var(--blue-dk); }
        .btn-secondary {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text-2);
        }
        .btn-secondary:hover { background: var(--blue-lt); border-color: var(--blue); color: var(--blue); }
        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        .btn-danger:hover { background: #fecaca; }
        .btn-sm { padding: 0.3rem 0.8rem; font-size: 0.7rem; }

        /* Announcement List */
        .announcement-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .announcement-item {
            background: var(--card);
            border-radius: 12px;
            border: 1px solid var(--border-lt);
            overflow: hidden;
            transition: box-shadow 0.15s;
        }
        .announcement-item:hover { box-shadow: var(--shadow-md); }
        .announcement-header {
            padding: 1rem 1.2rem;
            background: #fafafa;
            border-bottom: 1px solid var(--border-lt);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .announcement-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.3rem;
        }
        .announcement-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.7rem;
            color: var(--text-4);
        }
        .announcement-body {
            padding: 1.2rem;
            font-size: 0.85rem;
            color: var(--text-2);
            line-height: 1.6;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
        }
        .badge-all { background: var(--blue-lt); color: var(--blue); }
        .badge-user { background: var(--purple-lt); color: var(--purple); }

        .empty-state {
            text-align: center;
            padding: 3rem;
            background: var(--card);
            border-radius: 12px;
            border: 1px solid var(--border-lt);
        }
        .empty-state h3 { font-size: 1rem; margin-bottom: 0.25rem; color: var(--text-2); }
        .empty-state p { font-size: 0.8rem; color: var(--text-4); }

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 700px) {
            .stats-row { grid-template-columns: 1fr; }
            .announcement-header { flex-direction: column; }
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
        <a href="reports.php" class="menu-item">
            <span class="menu-icon">📈</span> Reports
        </a>
        <div class="menu-section">Communication</div>
        <a href="announcements.php" class="menu-item active">
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
            <h1>📢 Announcements</h1>
            <p>Create and manage system announcements for users.</p>
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

    <!-- Flash Messages -->
    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= e($message) ?>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon blue">📢</div>
            <div class="stat-info">
                <div class="stat-value"><?= $totalAnnouncements ?></div>
                <div class="stat-label">Total Announcements</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">👥</div>
            <div class="stat-info">
                <div class="stat-value"><?= $targetedAnnouncements ?></div>
                <div class="stat-label">Targeted to User</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon yellow">🌍</div>
            <div class="stat-info">
                <div class="stat-value"><?= $systemWideAnnouncements ?></div>
                <div class="stat-label">System-wide</div>
            </div>
        </div>
    </div>

    <!-- Compose Announcement Card -->
    <div class="card">
        <div class="card-header">
            <h2>✍️ Compose New Announcement</h2>
        </div>
        <div class="card-body">
            <form method="POST" id="announcementForm">
                <input type="hidden" name="action" value="create" id="formAction">
                <input type="hidden" name="announcement_id" id="announcementId">

                <div class="form-grid">
                    <div class="form-group full">
                        <label class="form-label">Title <span class="required">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="Enter announcement title…" required>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Message <span class="required">*</span></label>
                        <textarea name="message" class="form-control" placeholder="Enter announcement message…" required></textarea>
                    </div>

                    <div class="form-group full">
                        <label class="form-label">Target Audience</label>
                        <div class="target-selector">
                            <label class="radio-label">
                                <input type="radio" name="target_type" value="all" checked onchange="toggleUserSearch()">
                                🌍 All Users (System-wide)
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="target_type" value="user" onchange="toggleUserSearch()">
                                👤 Specific User
                            </label>
                        </div>
                    </div>

                    <div class="form-group full user-search-box" id="userSearchBox">
                        <label class="form-label">Search User</label>
                        <div class="search-input-wrapper">
                            <span class="search-icon">🔍</span>
                            <input type="text" id="userSearchInput" class="form-control" placeholder="Search by name or email…">
                        </div>
                        <div class="user-results" id="userResults" style="display:none;"></div>
                        <div id="selectedUserContainer"></div>
                        <input type="hidden" name="target_user_id" id="targetUserId">
                    </div>
                </div>

                <div style="display:flex;gap:0.75rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">📤 Send Announcement</button>
                    <button type="button" class="btn btn-secondary" id="cancelEditBtn" style="display:none;" onclick="cancelEdit()">✕ Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Announcements List -->
    <div class="announcement-list">
        <?php if (empty($announcements)): ?>
            <div class="empty-state">
                <div style="font-size:2.5rem;margin-bottom:0.75rem;">📭</div>
                <h3>No announcements yet</h3>
                <p>Create your first announcement above.</p>
            </div>
        <?php else: ?>
            <?php foreach ($announcements as $ann): ?>
            <div class="announcement-item">
                <div class="announcement-header">
                    <div>
                        <div class="announcement-title"><?= e($ann['title']) ?></div>
                        <div class="announcement-meta">
                            <span>👤 <?= e($ann['first_name'] . ' ' . $ann['last_name']) ?></span>
                            <span>🕐 <?= timeAgo($ann['created_at']) ?></span>
                            <span class="badge <?= $ann['target_type'] === 'all' ? 'badge-all' : 'badge-user' ?>">
                                <?= $ann['target_type'] === 'all' ? '🌍 All Users' : '👤 Specific User' ?>
                            </span>
                            <?php if ($ann['target_type'] === 'user' && $ann['target_user_id']): ?>
                                <span>→ User ID: <?= $ann['target_user_id'] ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:0.5rem;">
                        <button class="btn btn-secondary btn-sm" onclick="editAnnouncement(<?= $ann['id'] ?>)">✏️ Edit</button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this announcement?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑 Delete</button>
                        </form>
                    </div>
                </div>
                <div class="announcement-body">
                    <?= nl2br(e($ann['message'])) ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script>
function toggleUserSearch() {
    const targetUser = document.querySelector('input[name="target_type"][value="user"]');
    const userSearchBox = document.getElementById('userSearchBox');
    if (targetUser.checked) {
        userSearchBox.classList.add('open');
    } else {
        userSearchBox.classList.remove('open');
        clearSelectedUser();
    }
}

// User search
const searchInput = document.getElementById('userSearchInput');
const userResults = document.getElementById('userResults');
let selectedUserId = null;

searchInput.addEventListener('input', function() {
    const query = this.value.trim();
    if (query.length < 2) {
        userResults.style.display = 'none';
        return;
    }

    fetch('?ajax_search_users=1&q=' + encodeURIComponent(query))
        .then(r => r.json())
        .then(users => {
            if (users.length === 0) {
                userResults.innerHTML = '<div class="user-result-item" style="color:#9ca3af;">No users found</div>';
                userResults.style.display = 'block';
                return;
            }
            userResults.innerHTML = users.map(u => `
                <div class="user-result-item" onclick="selectUser(${u.id}, '${escapeJS(u.first_name + ' ' + u.last_name)}', '${escapeJS(u.email)}', '${escapeJS(u.role)}')">
                    <div class="name">${escapeHTML(u.first_name)} ${escapeHTML(u.last_name)}</div>
                    <div class="meta">${escapeHTML(u.email)} · ${escapeHTML(u.role)}</div>
                </div>
            `).join('');
            userResults.style.display = 'block';
        })
        .catch(err => {
            console.error('Search error:', err);
        });
});

function selectUser(id, name, email, role) {
    selectedUserId = id;
    document.getElementById('targetUserId').value = id;
    document.getElementById('userResults').style.display = 'none';
    searchInput.value = name;

    const container = document.getElementById('selectedUserContainer');
    container.innerHTML = `
        <div class="selected-user">
            <span>👤 ${escapeHTML(name)}</span>
            <span class="remove" onclick="clearSelectedUser()">✕</span>
        </div>
    `;
}

function clearSelectedUser() {
    selectedUserId = null;
    document.getElementById('targetUserId').value = '';
    document.getElementById('selectedUserContainer').innerHTML = '';
    searchInput.value = '';
    userResults.style.display = 'none';
}

function escapeHTML(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeJS(str) {
    return str.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

// Edit announcement
function editAnnouncement(id) {
    fetch('?ajax_get_announcement=1&id=' + id)
        .then(r => r.json())
        .then(data => {
            document.getElementById('formAction').value = 'update';
            document.getElementById('announcementId').value = data.id;
            document.querySelector('input[name="title"]').value = data.title;
            document.querySelector('textarea[name="message"]').value = data.message;

            const targetAll = document.querySelector('input[name="target_type"][value="all"]');
            const targetUser = document.querySelector('input[name="target_type"][value="user"]');

            if (data.target_type === 'all') {
                targetAll.checked = true;
                toggleUserSearch();
            } else {
                targetUser.checked = true;
                toggleUserSearch();
                if (data.target_user_id) {
                    fetch('?ajax_get_user=1&id=' + data.target_user_id)
                        .then(r => r.json())
                        .then(user => {
                            selectUser(user.id, user.first_name + ' ' + user.last_name, user.email, user.role);
                        });
                }
            }

            document.getElementById('cancelEditBtn').style.display = 'inline-flex';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
}

function cancelEdit() {
    document.getElementById('announcementForm').reset();
    document.getElementById('formAction').value = 'create';
    document.getElementById('announcementId').value = '';
    document.getElementById('cancelEditBtn').style.display = 'none';
    clearSelectedUser();
    toggleUserSearch();
}
</script>
</body>
</html>