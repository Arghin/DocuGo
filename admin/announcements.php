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

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements — Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
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
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            font-size: 14px;
            line-height: 1.5;
        }

        /* ── Sidebar ──────────────────────────────────── */
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
            padding: 1.4rem 1.2rem;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .brand-icon {
            width: 34px; height: 34px;
            background: #fff;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: var(--blue);
        }

        .brand-name {
            font-size: 1.2rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.4px;
        }

        .brand-sub {
            font-size: 0.66rem;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            margin-top: 3px;
        }

        .sidebar-menu { padding: 0.85rem 0; flex: 1; overflow-y: auto; }

        .menu-section {
            padding: 0.75rem 1rem 0.3rem;
            font-size: 0.61rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255,255,255,0.35);
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.55rem 1rem;
            margin: 1px 0.5rem;
            border-radius: 8px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 0.845rem;
            font-weight: 500;
            transition: background 0.15s, color 0.15s;
            position: relative;
        }

        .menu-item:hover  { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.95); }
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

        .menu-icon { font-size: 1rem; width: 20px; text-align: center; flex-shrink: 0; }

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

        /* ── Main ─────────────────────────────────────── */
        .main { margin-left: var(--sidebar); flex: 1; padding: 1.5rem 2rem; min-width: 0; }

        /* ── Topbar ───────────────────────────────────── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            gap: 1rem;
        }

        .topbar-left h1 {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .topbar-info {
            font-size: 0.8rem;
            color: var(--text-3);
            margin-right: 0.5rem;
        }

        .logout-btn-top {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: #fee2e2;
            color: #dc2626;
            display: flex; align-items: center; justify-content: center;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.15s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .logout-btn-top:hover {
            background: #fecaca;
            transform: scale(1.08);
            color: #991b1b;
        }

        /* ── Alerts ───────────────────────────────────── */
        .alert {
            padding: 0.8rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.845rem;
            font-weight: 500;
        }
        .alert-success { background: var(--green-lt); border: 1px solid #bbf7d0; color: #15803d; }
        .alert-error   { background: var(--red-lt); border: 1px solid #fecaca; color: #b91c1c; }

        /* ── Stats Row ────────────────────────────────── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--card);
            border-radius: 12px;
            padding: 1.2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }
        .stat-icon.blue   { background: var(--blue-lt); color: var(--blue); }
        .stat-icon.green  { background: var(--green-lt); color: var(--green); }
        .stat-icon.yellow { background: var(--yellow-lt); color: var(--yellow); }

        .stat-info {
            flex: 1;
        }
        .stat-label {
            font-size: 0.75rem;
            color: var(--text-3);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
            margin-top: 4px;
        }

        /* ── Compose Card ────────────────────────────── */
        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            overflow: hidden;
            margin-bottom: 1.2rem;
        }

        .card-header {
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--border-lt);
            background: #fafafa;
        }

        .card-header h2 {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.4rem;
        }

        /* ── Form ────────────────────────────────────── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .form-group.full { grid-column: 1 / -1; }

        .form-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-label .required { color: var(--red); }

        .form-control {
            padding: 0.65rem 0.9rem;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.875rem;
            font-family: inherit;
            color: var(--text);
            background: #fff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(26,86,219,0.1);
        }
        .form-control::placeholder { color: var(--text-4); }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .help-text {
            font-size: 0.72rem;
            color: var(--text-4);
        }

        .target-selector {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        .radio-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-2);
            cursor: pointer;
        }

        .radio-label input[type="radio"] {
            accent-color: var(--blue);
            width: 16px; height: 16px;
        }

        .user-search-box {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .user-search-box.open { max-height: 400px; }

        .search-input-wrapper {
            position: relative;
            margin-top: 0.5rem;
        }

        .search-input-wrapper input {
            width: 100%;
            padding: 0.65rem 0.9rem 0.65rem 2.2rem;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.875rem;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-4);
        }

        .user-results {
            margin-top: 0.5rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            max-height: 250px;
            overflow-y: auto;
        }

        .user-result-item {
            padding: 0.65rem 0.9rem;
            cursor: pointer;
            border-bottom: 1px solid var(--border-lt);
            font-size: 0.855rem;
            transition: background 0.15s;
        }
        .user-result-item:last-child { border-bottom: none; }
        .user-result-item:hover { background: var(--blue-lt); }
        .user-result-item .name { font-weight: 600; color: var(--text); }
        .user-result-item .meta { font-size: 0.72rem; color: var(--text-4); margin-top: 2px; }
        .user-result-item.selected {
            background: var(--blue-lt);
            border-left: 3px solid var(--blue);
        }

        .selected-user {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.75rem;
            background: var(--blue-lt);
            color: var(--blue);
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }
        .selected-user .remove {
            cursor: pointer;
            color: var(--text-4);
            font-size: 1rem;
            line-height: 1;
        }
        .selected-user .remove:hover { color: var(--red); }

        .btn {
            padding: 0.65rem 1.2rem;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary { background: var(--blue); color: #fff; }
        .btn-primary:hover { background: var(--blue-dk); transform: translateY(-1px); }
        .btn-secondary { background: var(--border-lt); color: var(--text-2); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--border); }
        .btn-danger { background: var(--red-lt); color: #991b1b; }
        .btn-danger:hover { background: #fecaca; }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.78rem;
            font-weight: 600;
        }

        /* ── Announcements List ──────────────────────── */
        .announcement-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .announcement-item {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            overflow: hidden;
        }

        .announcement-header {
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--border-lt);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .announcement-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.25rem;
        }

        .announcement-meta {
            font-size: 0.75rem;
            color: var(--text-4);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .announcement-meta span {
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge {
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-all { background: var(--blue-lt); color: var(--blue); }
        .badge-user { background: var(--yellow-lt); color: var(--yellow); }

        .announcement-body {
            padding: 1rem 1.4rem;
            color: var(--text-2);
            line-height: 1.6;
        }

        .announcement-actions {
            padding: 0.75rem 1.4rem;
            border-top: 1px solid var(--border-lt);
            display: flex;
            gap: 0.5rem;
            background: #fafafa;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: var(--card);
            border-radius: 12px;
            color: var(--text-4);
        }
        .empty-state h3 { font-size: 1rem; color: var(--text-3); margin-bottom: 0.5rem; }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .form-grid { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon">📢</div>
            <div>
                <div class="brand-name">Announcements</div>
                <div class="brand-sub">Admin Panel</div>
            </div>
        </div>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-section">Main</div>
        <a href="dashboard.php" class="menu-item">
            <span class="menu-icon">🏠</span> Dashboard
        </a>
        <a href="requests.php" class="menu-item">
            <span class="menu-icon">📄</span> Document Requests
        </a>
        <a href="accounts.php" class="menu-item">
            <span class="menu-icon">👥</span> User Accounts
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

        <div class="menu-section">Settings</div>
        <a href="document_types.php" class="menu-item">
            <span class="menu-icon">⚙️</span> Document Types
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<main class="main">
    <div class="topbar">
        <div class="topbar-left">
            <h1>📢 Announcements</h1>
        </div>
        <div class="topbar-right">
            <div class="topbar-info">
                Logged in as <strong><?= e($_SESSION['user_name']) ?></strong>
            </div>
            <a href="../logout.php" class="logout-btn-top" title="Logout">🚪</a>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= e($message) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon blue">📢</div>
            <div class="stat-info">
                <div class="stat-label">Total Announcements</div>
                <div class="stat-value"><?= count($announcements) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">👥</div>
            <div class="stat-info">
                <div class="stat-label">Targeted to User</div>
                <div class="stat-value">
                    <?= count(array_filter($announcements, fn($a) => $a['target_type'] === 'user')) ?>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon yellow">🌍</div>
            <div class="stat-info">
                <div class="stat-label">System-wide</div>
                <div class="stat-value">
                    <?= count(array_filter($announcements, fn($a) => $a['target_type'] === 'all')) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Compose Announcement -->
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

                <div style="display:flex;gap:0.75rem;">
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
                    // Fetch user info
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
