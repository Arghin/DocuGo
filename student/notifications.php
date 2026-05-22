<?php
// notifications.php
// View all notifications for the logged-in user

require_once '../includes/config.php';
require_once '../includes/request_helper.php';
requireLogin();

$conn = getConnection();
$userId = $_SESSION['user_id'];

// Get user info for sidebar
$stmt = $conn->prepare("SELECT first_name, last_name, role FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle mark as read (single)
if (isset($_POST['mark_read']) && isset($_POST['notif_id'])) {
    $notifId = intval($_POST['notif_id']);
    $updateStmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $updateStmt->bind_param("ii", $notifId, $userId);
    $updateStmt->execute();
    $updateStmt->close();
    header('Location: notifications.php');
    exit();
}

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    $updateStmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $updateStmt->bind_param("i", $userId);
    $updateStmt->execute();
    $updateStmt->close();
    header('Location: notifications.php');
    exit();
}

// Handle delete notification
if (isset($_POST['delete']) && isset($_POST['notif_id'])) {
    $notifId = intval($_POST['delete']);
    $deleteStmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    $deleteStmt->bind_param("ii", $notifId, $userId);
    $deleteStmt->execute();
    $deleteStmt->close();
    header('Location: notifications.php');
    exit();
}

// Handle delete all read
if (isset($_POST['delete_all_read'])) {
    $deleteStmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
    $deleteStmt->bind_param("i", $userId);
    $deleteStmt->execute();
    $deleteStmt->close();
    header('Location: notifications.php');
    exit();
}

// AJAX handlers for real-time updates
if (isset($_GET['ajax_unread_count'])) {
    $s = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
    $s->bind_param("i", $userId); $s->execute();
    echo json_encode(['count' => (int)$s->get_result()->fetch_assoc()['c']]);
    $s->close(); exit();
}
if (isset($_GET['ajax_notif_list'])) {
    $s = $conn->prepare("SELECT id, message, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
    $s->bind_param("i", $userId);
    $s->execute();
    echo json_encode($s->get_result()->fetch_all(MYSQLI_ASSOC));
    $s->close(); exit();
}

// Pagination settings
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filter by read status
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$filterCondition = '';

switch ($filter) {
    case 'unread':
        $filterCondition = "AND is_read = 0";
        break;
    case 'read':
        $filterCondition = "AND is_read = 1";
        break;
    default:
        $filterCondition = "";
        break;
}

// Get total count for pagination
$countStmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM notifications 
    WHERE user_id = ? $filterCondition
");
$countStmt->bind_param("i", $userId);
$countStmt->execute();
$totalResult = $countStmt->get_result()->fetch_assoc();
$totalNotifications = $totalResult['total'];
$totalPages = ceil($totalNotifications / $perPage);
$countStmt->close();

// Get notifications
$query = "
    SELECT id, message, is_read, created_at 
    FROM notifications 
    WHERE user_id = ? $filterCondition 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
";

$notifStmt = $conn->prepare($query);
$notifStmt->bind_param("iii", $userId, $perPage, $offset);
$notifStmt->execute();
$notifications = $notifStmt->get_result();
$notifStmt->close();

// Get unread count for badge
$unreadStmt = $conn->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->bind_param("i", $userId);
$unreadStmt->execute();
$unreadCount = $unreadStmt->get_result()->fetch_assoc()['unread'];
$unreadStmt->close();

$conn->close();

// Helper function for time ago
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins != 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}

// Helper function to get icon and color based on message content
function getNotificationStyle($message) {
    $msgLower = strtolower($message);
    
    if (strpos($msgLower, 'ready') !== false) {
        return ['icon' => '📦', 'bg' => '#d1fae5', 'color' => '#065f46'];
    } elseif (strpos($msgLower, 'approved') !== false) {
        return ['icon' => '✅', 'bg' => '#dbeafe', 'color' => '#1e40af'];
    } elseif (strpos($msgLower, 'signature') !== false) {
        return ['icon' => '✍️', 'bg' => '#fffbeb', 'color' => '#d97706'];
    } elseif (strpos($msgLower, 'processing') !== false) {
        return ['icon' => '⚙️', 'bg' => '#e0f2fe', 'color' => '#0369a1'];
    } elseif (strpos($msgLower, 'released') !== false) {
        return ['icon' => '📬', 'bg' => '#ede9fe', 'color' => '#4c1d95'];
    } elseif (strpos($msgLower, 'paid') !== false) {
        return ['icon' => '💰', 'bg' => '#dcfce7', 'color' => '#166534'];
    } elseif (strpos($msgLower, 'cancelled') !== false) {
        return ['icon' => '❌', 'bg' => '#fee2e2', 'color' => '#991b1b'];
    } else {
        return ['icon' => '🔔', 'bg' => '#f3f4f6', 'color' => '#374151'];
    }
}

$isAlumni = ($user['role'] === 'alumni');
$initial = strtoupper(substr($user['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — ADFC DocuGo</title>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
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
            --surface:    rgba(255,255,255,0.05);
            --surface-hv: rgba(255,255,255,0.08);
            --border:     rgba(255,255,255,0.08);
            --border-hv:  rgba(59,107,255,0.25);
            --text:       #dce6f8;
            --text-muted: #7a96c4;
            --text-dim:   #4a6190;
            --green:      #4cd98a;
            --gold:       #ffd700;
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

        .menu-badge.yellow { background: var(--gold); color: #1a1a2e; }

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
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.6rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .topbar h1 {
            font-family: 'Sora', sans-serif;
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--text);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-shrink: 0;
        }

        /* User Chip */
        .user-chip {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 0.35rem 0.85rem 0.35rem 0.45rem;
            font-size: 0.8rem;
            color: var(--text);
        }
        .chip-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
        }

        /* Logout Button */
        .logout-btn-top {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(248,113,113,0.15);
            color: var(--red);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1rem;
            transition: transform 0.2s;
        }
        .logout-btn-top:hover { transform: scale(1.05); background: rgba(248,113,113,0.25); }

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

        /* Filter Bar */
        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 0.75rem 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.15s;
            background: var(--bg2);
            color: var(--text-muted);
        }
        .filter-btn:hover {
            background: var(--surface-hv);
            color: var(--text);
        }
        .filter-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
        }
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        .action-btn {
            padding: 0.4rem 1rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.7rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            background: var(--bg2);
            color: var(--text-muted);
        }
        .action-btn:hover {
            background: var(--surface-hv);
            color: var(--text);
        }
        .action-btn-danger {
            background: rgba(248,113,113,0.15);
            color: var(--red);
        }
        .action-btn-danger:hover {
            background: rgba(248,113,113,0.25);
            color: var(--red);
        }

        /* Notifications Container */
        .notifications-container {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            transition: background 0.15s;
            position: relative;
        }
        .notif-item:last-child {
            border-bottom: none;
        }
        .notif-item.unread {
            background: rgba(59,107,255,0.08);
        }
        .notif-item.unread:hover {
            background: rgba(59,107,255,0.12);
        }
        .notif-item:hover {
            background: var(--surface-hv);
        }
        .notif-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .notif-content {
            flex: 1;
            min-width: 0;
        }
        .notif-message {
            font-size: 0.85rem;
            color: var(--text);
            line-height: 1.5;
            margin-bottom: 0.25rem;
        }
        .notif-item.unread .notif-message {
            font-weight: 600;
        }
        .notif-time {
            font-size: 0.65rem;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        .notif-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-shrink: 0;
        }
        .mark-read-btn, .delete-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0.3rem;
            border-radius: var(--radius-sm);
            transition: background 0.15s;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
        }
        .mark-read-btn:hover {
            background: var(--surface-hv);
            color: var(--green);
        }
        .delete-btn:hover {
            background: rgba(248,113,113,0.15);
            color: var(--red);
        }
        .unread-dot {
            width: 8px;
            height: 8px;
            background: var(--accent);
            border-radius: 50%;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        .empty-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
            color: var(--text-dim);
        }
        .empty-state h3 {
            font-family: 'Sora', sans-serif;
            font-size: 1rem;
            color: var(--text);
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            font-size: 0.8rem;
            color: var(--text-dim);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.3rem;
            padding: 1rem;
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .page-link {
            padding: 0.4rem 0.8rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 500;
            transition: all 0.15s;
            color: var(--text-muted);
            background: var(--surface);
            border: 1px solid var(--border);
        }
        .page-link:hover {
            background: var(--surface-hv);
            border-color: var(--accent);
        }
        .page-link.active {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border-color: transparent;
        }
        .page-link.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .notif-item { flex-direction: column; }
            .notif-actions { align-self: flex-end; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .action-buttons { justify-content: flex-end; }
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
                <div class="brand-sub"><?= $isAlumni ? 'Alumni Portal' : 'Student Portal' ?></div>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-section">MAIN</div>
        <a href="dashboard.php" class="menu-item"><span class="menu-icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="menu-icon">📄</span> Request Document</a>
        <a href="my_requests.php" class="menu-item"><span class="menu-icon">📋</span> My Requests</a>
        <a href="notifications.php" class="menu-item active"><span class="menu-icon">🔔</span> Notifications</a>
        
        <?php if ($isAlumni): ?>
        <div class="menu-section">ALUMNI</div>
        <a href="graduate_tracer.php" class="menu-item"><span class="menu-icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item"><span class="menu-icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item"><span class="menu-icon">🎓</span> Alumni Documents</a>
        <?php endif; ?>
        
        <div class="menu-section">ACCOUNT</div>
        <a href="profile.php" class="menu-item"><span class="menu-icon">👤</span> Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">
    <div class="topbar">
        <h1><i class="fas fa-bell" style="color: var(--gold);"></i> Notifications</h1>
        <div class="topbar-right">
            <div class="user-chip">
                <div class="chip-avatar"><?= $initial ?></div>
                <strong><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="filter-tabs">
            <a href="?filter=all" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">
                All (<?= $totalNotifications ?>)
            </a>
            <a href="?filter=unread" class="filter-btn <?= $filter === 'unread' ? 'active' : '' ?>">
                Unread (<?= $unreadCount ?>)
            </a>
            <a href="?filter=read" class="filter-btn <?= $filter === 'read' ? 'active' : '' ?>">
                Read (<?= $totalNotifications - $unreadCount ?>)
            </a>
        </div>
        <div class="action-buttons">
            <form method="POST" style="display: inline;" onsubmit="return confirm('Mark all notifications as read?')">
                <button type="submit" name="mark_all_read" class="action-btn"><i class="fas fa-check-double"></i> Mark all read</button>
            </form>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete all read notifications? This action cannot be undone.')">
                <button type="submit" name="delete_all_read" class="action-btn action-btn-danger"><i class="fas fa-trash"></i> Delete all read</button>
            </form>
        </div>
    </div>

    <!-- Notifications List -->
    <div class="notifications-container">
        <?php if ($notifications->num_rows === 0): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-bell-slash"></i></div>
                <h3>No notifications yet</h3>
                <p>When you receive notifications, they'll appear here.</p>
            </div>
        <?php else: ?>
            <?php while ($notif = $notifications->fetch_assoc()): 
                $style = getNotificationStyle($notif['message']);
                $isUnread = $notif['is_read'] == 0;
            ?>
                <div class="notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-<?= $notif['id'] ?>">
                    <?php if ($isUnread): ?>
                        <div class="unread-dot"></div>
                    <?php endif; ?>
                    
                    <div class="notif-icon" style="background: <?= $style['bg'] ?>;">
                        <?= $style['icon'] ?>
                    </div>
                    
                    <div class="notif-content">
                        <div class="notif-message">
                            <?= htmlspecialchars($notif['message']) ?>
                        </div>
                        <div class="notif-time">
                            <i class="far fa-clock"></i> <?= timeAgo($notif['created_at']) ?>
                        </div>
                    </div>
                    
                    <div class="notif-actions">
                        <?php if ($isUnread): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
                                <button type="submit" name="mark_read" class="mark-read-btn" title="Mark as read">
                                    <i class="fas fa-check"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this notification?')">
                            <input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
                            <button type="submit" name="delete" value="<?= $notif['id'] ?>" class="delete-btn" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="?page=<?= max(1, $page - 1) ?>&filter=<?= $filter ?>" 
               class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1): ?>
                <a href="?page=1&filter=<?= $filter ?>" class="page-link">1</a>
                <?php if ($startPage > 2): ?>
                    <span class="page-link disabled">...</span>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <a href="?page=<?= $i ?>&filter=<?= $filter ?>" 
                   class="page-link <?= $i === $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <span class="page-link disabled">...</span>
                <?php endif; ?>
                <a href="?page=<?= $totalPages ?>&filter=<?= $filter ?>" class="page-link">
                    <?= $totalPages ?>
                </a>
            <?php endif; ?>
            
            <a href="?page=<?= min($totalPages, $page + 1) ?>&filter=<?= $filter ?>" 
               class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
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