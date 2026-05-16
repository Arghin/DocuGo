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
    <title>Notifications — DocuGo</title>
    <style>
        /* ─── Reset & Base ────────────────────────────── */
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

        /* ─── Sidebar ─────────────────────────────────── */
        .sidebar {
            width: 220px;
            background: #1a56db;
            color: #fff;
            min-height: 100vh;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; height: 100%;
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
        .menu-item:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .menu-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left-color: #fff;
            font-weight: 600;
        }
        .menu-item .icon { font-size: 1rem; width: 20px; text-align: center; flex-shrink: 0; }
        .sidebar-footer {
            padding: 1rem 1.2rem;
            border-top: 1px solid rgba(255,255,255,0.15);
            font-size: 0.8rem;
        }
        .sidebar-footer a {
            color: rgba(255,255,255,0.82);
            text-decoration: none;
            display: flex; align-items: center; gap: 0.5rem;
            transition: color 0.15s;
        }
        .sidebar-footer a:hover { color: #fff; }

        /* ─── Main ────────────────────────────────────── */
        .main { margin-left: 220px; flex: 1; padding: 2rem; min-width: 0; }

        /* ─── Topbar ──────────────────────────────────── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.6rem;
            gap: 1rem;
        }
        .topbar h1 { font-size: 1.35rem; font-weight: 800; color: #111827; }
        .topbar-right { display: flex; align-items: center; gap: 0.65rem; flex-shrink: 0; }

        /* ─── User Chip ───────────────────────────────── */
        .user-chip {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 0.38rem 0.85rem 0.38rem 0.45rem;
            font-size: 0.82rem;
            color: #374151;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            white-space: nowrap;
        }
        .chip-avatar {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #1a56db;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .user-chip strong { color: #111827; font-weight: 700; }

        /* ─── Filter Bar ──────────────────────────────── */
        .filter-bar {
            background: #fff;
            border-radius: 12px;
            padding: 0.75rem 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
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
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.15s;
            background: #f3f4f6;
            color: #374151;
        }
        .filter-btn:hover {
            background: #e5e7eb;
        }
        .filter-btn.active {
            background: #1a56db;
            color: #fff;
        }
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        .action-btn {
            padding: 0.4rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.75rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
            background: #f3f4f6;
            color: #374151;
        }
        .action-btn:hover {
            background: #e5e7eb;
        }
        .action-btn-danger {
            background: #fee2e2;
            color: #dc2626;
        }
        .action-btn-danger:hover {
            background: #fecaca;
        }

        /* ─── Notifications List ──────────────────────── */
        .notifications-container {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.15s;
            position: relative;
        }
        .notif-item.unread {
            background: #f0f7ff;
        }
        .notif-item.unread:hover {
            background: #e0edff;
        }
        .notif-item:hover {
            background: #f9fafb;
        }
        .notif-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
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
            font-size: 0.9rem;
            color: #1f2937;
            line-height: 1.5;
            margin-bottom: 0.25rem;
        }
        .notif-item.unread .notif-message {
            font-weight: 600;
        }
        .notif-time {
            font-size: 0.7rem;
            color: #9ca3af;
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
            font-size: 1rem;
            padding: 0.3rem;
            border-radius: 6px;
            transition: background 0.15s;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .mark-read-btn:hover {
            background: #e5e7eb;
        }
        .delete-btn:hover {
            background: #fee2e2;
        }
        .unread-dot {
            width: 8px;
            height: 8px;
            background: #1a56db;
            border-radius: 50%;
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* ─── Empty State ─────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        .empty-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        .empty-state h3 {
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .empty-state p {
            color: #9ca3af;
        }

        /* ─── Pagination ──────────────────────────────── */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.3rem;
            padding: 1.5rem;
            border-top: 1px solid #f3f4f6;
            flex-wrap: wrap;
        }
        .page-link {
            padding: 0.5rem 0.9rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.15s;
            color: #374151;
            background: #f3f4f6;
        }
        .page-link:hover {
            background: #e5e7eb;
        }
        .page-link.active {
            background: #1a56db;
            color: #fff;
        }
        .page-link.disabled {
            opacity: 0.4;
            pointer-events: none;
        }

        /* ─── Responsive ──────────────────────────────── */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .notif-item { flex-direction: column; }
            .notif-actions { align-self: flex-end; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .action-buttons { justify-content: flex-end; }
        }
    </style>
</head>
<body>

<!-- ─── Sidebar ─────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        DocuGo
        <small><?= $isAlumni ? 'Alumni Portal' : 'Student Portal' ?></small>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="dashboard.php" class="menu-item"><span class="icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="icon">📄</span> Request Document</a>
        <a href="my_requests.php" class="menu-item"><span class="icon">📋</span> My Requests</a>
        <a href="notifications.php" class="menu-item active"><span class="icon">🔔</span> Notifications</a>
        
        <?php if ($isAlumni): ?>
        <div class="menu-label">Alumni</div>
        <a href="graduate_tracer.php" class="menu-item"><span class="icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item"><span class="icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item"><span class="icon">🎓</span> Alumni Documents</a>
        <?php endif; ?>
        
        <div class="menu-label">Account</div>
        <a href="profile.php" class="menu-item"><span class="icon">👤</span> Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- ─── Main ────────────────────────────────────────────── -->
<main class="main">
    <div class="topbar">
        <h1>🔔 Notifications</h1>
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
                <button type="submit" name="mark_all_read" class="action-btn">✓ Mark all read</button>
            </form>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete all read notifications? This action cannot be undone.')">
                <button type="submit" name="delete_all_read" class="action-btn action-btn-danger">🗑 Delete all read</button>
            </form>
        </div>
    </div>

    <!-- Notifications List -->
    <div class="notifications-container">
        <?php if ($notifications->num_rows === 0): ?>
            <div class="empty-state">
                <div class="empty-icon">🔔</div>
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
                            🕐 <?= timeAgo($notif['created_at']) ?>
                        </div>
                    </div>
                    
                    <div class="notif-actions">
                        <?php if ($isUnread): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
                                <button type="submit" name="mark_read" class="mark-read-btn" title="Mark as read">
                                    ✓
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this notification?')">
                            <input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
                            <button type="submit" name="delete" value="<?= $notif['id'] ?>" class="delete-btn" title="Delete">
                                🗑
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
                ← Previous
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
                Next →
            </a>
        </div>
    <?php endif; ?>
</main>

</body>
</html>