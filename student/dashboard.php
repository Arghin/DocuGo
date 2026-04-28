<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
require_once '../includes/announcement_helper.php';
requireLogin();

$conn   = getConnection();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['user_role'] ?? 'student';

/* ── Security routing ───────────────────────────────── */
if ($role === 'admin' || $role === 'registrar') {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit();
}

/* ── AJAX: mark one notification read ───────────────── */
if (isset($_POST['ajax_mark_read'])) {
    $nid = intval($_POST['notif_id'] ?? 0);
    if ($nid > 0) {
        $s = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
        $s->bind_param("ii", $nid, $userId);
        $s->execute(); $s->close();
    }
    echo json_encode(['ok' => true]); exit();
}

/* ── AJAX: mark all read ────────────────────────────── */
if (isset($_POST['ajax_mark_all_read'])) {
    $s = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?");
    $s->bind_param("i", $userId);
    $s->execute(); $s->close();
    echo json_encode(['ok' => true]); exit();
}

/* ── AJAX: unread count poll ────────────────────────── */
if (isset($_GET['ajax_unread_count'])) {
    $s = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
    $s->bind_param("i", $userId);
    $s->execute();
    $c = (int)$s->get_result()->fetch_assoc()['c'];
    $s->close();
    echo json_encode(['count' => $c]); exit();
}

/* ── AJAX: refresh notification list ───────────────── */
if (isset($_GET['ajax_notif_list'])) {
    $s = $conn->prepare("SELECT id, message, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
    $s->bind_param("i", $userId);
    $s->execute();
    echo json_encode($s->get_result()->fetch_all(MYSQLI_ASSOC));
    $s->close(); exit();
}

/* ── User info ──────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT first_name, last_name, course, student_id, email, contact_number
    FROM users WHERE id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) { session_destroy(); header('Location: ../login.php'); exit(); }

/* ── Request counters ───────────────────────────────── */
$statStmt = $conn->prepare("
    SELECT
        SUM(status='pending')    AS pending,
        SUM(status='processing') AS processing,
        SUM(status='ready')      AS ready,
        SUM(status='released')   AS released
    FROM document_requests WHERE user_id=?
");
$statStmt->bind_param("i", $userId);
$statStmt->execute();
$stats = $statStmt->get_result()->fetch_assoc();
$statStmt->close();

/* ── Latest 5 requests ──────────────────────────────── */
$reqStmt = $conn->prepare("
    SELECT dr.request_code, dr.status, dr.copies,
           dt.name AS doc_type, dt.fee,
           dr.requested_at
    FROM document_requests dr
    JOIN document_types dt ON dr.document_type_id = dt.id
    WHERE dr.user_id = ?
    ORDER BY dr.requested_at DESC
    LIMIT 5
");
$reqStmt->bind_param("i", $userId);
$reqStmt->execute();
$latestReqs = $reqStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$reqStmt->close();

/* ── Notifications (initial load) ───────────────────── */
$nStmt = $conn->prepare("
    SELECT id, message, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$nStmt->bind_param("i", $userId);
$nStmt->execute();
$notifications = $nStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$nStmt->close();

$unreadCount = 0;
foreach ($notifications as $n) { if (!$n['is_read']) $unreadCount++; }

/* ── User Announcements ───────────────────────────────── */
$announcements = getAnnouncements($conn, $userId, 3);

$conn->close();

/* ── Helpers ────────────────────────────────────────── */
function e($v) { return htmlspecialchars($v ?? ''); }

function timeAgo($dt) {
    $d = time() - strtotime($dt);
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return floor($d/60)   . ' min ago';
    if ($d < 86400)  return floor($d/3600) . ' hr ago';
    if ($d < 604800) return floor($d/86400). ' day ago';
    return date('M d, Y', strtotime($dt));
}

function notifMeta($msg) {
    $lm = strtolower($msg);
    if (str_contains($lm,'ready'))   return ['📦','type-ready',   'Document Ready'];
    if (str_contains($lm,'approv'))  return ['✅','type-approved','Request Approved'];
    if (str_contains($lm,'process')) return ['⚙️','type-process', 'Being Processed'];
    if (str_contains($lm,'cancel'))  return ['❌','type-cancel',  'Request Cancelled'];
    if (str_contains($lm,'releas'))  return ['📬','type-ready',   'Document Released'];
    if (str_contains($lm,'paid'))    return ['💳','type-approved','Payment Confirmed'];
    if (str_contains($lm,'welcom'))  return ['👋','type-info',    'Welcome!'];
    return ['🔔','type-info','Notification'];
}

$roleLabel = ucfirst($role);
$initial   = strtoupper(substr($user['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $roleLabel ?> Dashboard — DocuGo</title>
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

        /* Unread pill inside sidebar link */
        .menu-badge {
            margin-left: auto;
            background: #dc2626;
            color: #fff;
            font-size: 0.62rem;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
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

        .topbar h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #111827;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-shrink: 0;
        }

        /* ─── Notification Bell ───────────────────────── */
        .notif-wrap { position: relative; }

        .notif-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.1rem;
            transition: background 0.15s, border-color 0.2s, transform 0.15s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.07);
            outline: none;
            padding: 0;
        }

        .notif-btn:hover {
            background: #f0f4ff;
            border-color: #1a56db;
            transform: scale(1.06);
        }

        .notif-btn.has-unread {
            border-color: #1a56db;
            animation: bellShake 0.9s ease-in-out 0.4s;
        }

        @keyframes bellShake {
            0%,100% { transform: rotate(0); }
            20%     { transform: rotate(-14deg); }
            40%     { transform: rotate(14deg); }
            60%     { transform: rotate(-9deg); }
            80%     { transform: rotate(9deg); }
        }

        .notif-badge {
            position: absolute;
            top: -4px; right: -4px;
            background: #dc2626;
            color: #fff;
            font-size: 0.6rem;
            font-weight: 800;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #f0f4f8;
            padding: 0 3px;
            line-height: 1;
            transition: opacity 0.25s, transform 0.25s;
        }

        .notif-badge.hidden {
            opacity: 0;
            transform: scale(0);
            pointer-events: none;
        }

        /* ─── Notification Dropdown ───────────────────── */
        .notif-panel {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 340px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.13), 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
            z-index: 500;
            overflow: hidden;
            /* Hidden by default */
            opacity: 0;
            transform: translateY(-6px) scale(0.98);
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
        }

        .notif-panel.open {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: all;
        }

        /* Caret arrow pointing up to bell */
        .notif-panel::before {
            content: '';
            position: absolute;
            top: -7px; right: 13px;
            width: 13px; height: 13px;
            background: #fff;
            border-left: 1px solid #e5e7eb;
            border-top: 1px solid #e5e7eb;
            transform: rotate(45deg);
        }

        .notif-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.9rem 1.1rem 0.8rem;
            border-bottom: 1px solid #f3f4f6;
            gap: 0.5rem;
        }

        .notif-panel-title {
            font-size: 0.92rem;
            font-weight: 800;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }

        .notif-count-pill {
            background: #1a56db;
            color: #fff;
            font-size: 0.62rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 10px;
            transition: opacity 0.2s;
        }

        .mark-all-btn {
            font-size: 0.75rem;
            color: #1a56db;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-family: inherit;
            padding: 4px 8px;
            border-radius: 6px;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
        }

        .mark-all-btn:hover { background: #eff6ff; color: #1447c0; }

        /* ─── Notification List ───────────────────────── */
        .notif-list {
            max-height: 320px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #e2e8f0 transparent;
        }

        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }

        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            padding: 0.8rem 1.1rem;
            border-bottom: 1px solid #f9fafb;
            cursor: pointer;
            transition: background 0.12s;
            position: relative;
        }

        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #f8faff; }
        .notif-item.unread { background: #f0f7ff; }
        .notif-item.unread:hover { background: #e0edff; }

        .notif-item-icon {
            width: 34px; height: 34px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .type-ready    { background: #d1fae5; }
        .type-approved { background: #dbeafe; }
        .type-process  { background: #e0e7ff; }
        .type-cancel   { background: #fee2e2; }
        .type-info     { background: #f3f4f6; }

        .notif-item-body { flex: 1; min-width: 0; }

        .notif-item-title {
            font-size: 0.815rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .notif-item.unread .notif-item-title {
            font-weight: 800;
            color: #111827;
        }

        .notif-item-msg {
            font-size: 0.765rem;
            color: #6b7280;
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .notif-item.unread .notif-item-msg { color: #374151; }

        .notif-item-time {
            font-size: 0.695rem;
            color: #9ca3af;
            margin-top: 3px;
            font-weight: 500;
        }

        .notif-unread-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #1a56db;
            flex-shrink: 0;
            margin-top: 7px;
        }

        /* Empty state */
        .notif-empty {
            padding: 2.5rem 1rem;
            text-align: center;
        }

        .notif-empty .empty-emoji { font-size: 2rem; margin-bottom: 0.5rem; }
        .notif-empty p { font-size: 0.82rem; color: #9ca3af; font-weight: 500; }

        /* Panel footer */
        .notif-panel-footer {
            padding: 0.65rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            text-align: center;
            background: #fafafa;
        }

        .notif-panel-footer a {
            font-size: 0.8rem;
            color: #1a56db;
            text-decoration: none;
            font-weight: 700;
            transition: color 0.15s;
        }

        .notif-panel-footer a:hover { color: #1447c0; text-decoration: underline; }

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
            cursor: default;
            white-space: nowrap;
        }

        .chip-avatar {
            width: 26px; height: 26px;
            border-radius: 50%;
            background: #1a56db;
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
            flex-shrink: 0;
        }

        .user-chip strong { color: #111827; font-weight: 700; }

        /* ─── Topbar Logout ────────────────────────────── */
        .logout-btn-top {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: #fee2e2;
            color: #dc2626;
            display: flex; align-items: center; justify-content: center;
            text-decoration: none;
            font-size: 1rem;
            transition: all 0.15s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            margin-right: 0.5rem;
        }
        .logout-btn-top:hover {
            background: #fecaca;
            transform: scale(1.08);
            color: #991b1b;
        }

        /* ─── Welcome Banner ──────────────────────────── */
        .welcome-banner {
            background: linear-gradient(135deg, #1a56db 0%, #1447c0 100%);
            color: #fff;
            padding: 1.4rem 1.6rem;
            border-radius: 12px;
            margin-bottom: 1.2rem;
            box-shadow: 0 4px 14px rgba(26,86,219,0.22);
        }

        .welcome-banner h2 {
            font-size: 1.15rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }

        .welcome-banner p {
            font-size: 0.82rem;
            opacity: 0.85;
        }

        /* ─── Stats Row ───────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.2rem;
        }

        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 1rem 1.1rem;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .stat-icon {
            font-size: 1.4rem;
            width: 42px; height: 42px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon.yellow  { background: #fef3c7; }
        .stat-icon.blue    { background: #dbeafe; }
        .stat-icon.green   { background: #d1fae5; }
        .stat-icon.gray    { background: #f3f4f6; }

        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.1;
        }

        /* ─── Card ────────────────────────────────────── */
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
            align-items: center;
            justify-content: space-between;
        }

        .card-header h2 {
            font-size: 1rem;
            font-weight: 700;
            color: #111827;
        }

        .card-header a {
            font-size: 0.8rem;
            color: #1a56db;
            text-decoration: none;
            font-weight: 600;
        }

        .card-header a:hover { text-decoration: underline; }

        /* ─── Table ───────────────────────────────────── */
        table { width: 100%; border-collapse: collapse; font-size: 0.845rem; }

        thead tr { background: #f9fafb; }

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
            color: #374151;
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafafa; }

        .empty-td {
            text-align: center;
            padding: 3rem 1rem;
            color: #9ca3af;
            font-size: 0.875rem;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 10px;
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .status-badge::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
        }

        .s-pending    { background:#fef3c7; color:#92400e; }
        .s-pending::before    { background:#d97706; }
        .s-approved   { background:#dbeafe; color:#1e40af; }
        .s-approved::before   { background:#2563eb; }
        .s-processing { background:#e0e7ff; color:#3730a3; }
        .s-processing::before { background:#4f46e5; }
        .s-ready      { background:#d1fae5; color:#065f46; }
        .s-ready::before      { background:#059669; }
        .s-paid       { background:#f0fdf4; color:#14532d; }
        .s-paid::before       { background:#16a34a; }
        .s-released   { background:#f3f4f6; color:#374151; }
        .s-released::before   { background:#9ca3af; }
        .s-cancelled  { background:#fee2e2; color:#991b1b; }
        .s-cancelled::before  { background:#dc2626; }

        /* ─── Responsive ──────────────────────────────── */
        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(2,1fr); } }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .notif-panel { width: 300px; }
            table { display: block; overflow-x: auto; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .notif-panel { width: 280px; right: -50px; }
            .notif-panel::before { right: 64px; }
        }
    </style>
</head>
<body>

<!-- ─── Sidebar ─────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        DocuGo
        <small><?= $roleLabel ?> Portal</small>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="dashboard.php" class="menu-item active">
            <span class="icon">🏠</span> Dashboard
        </a>
        <a href="request_form.php" class="menu-item">
            <span class="icon">📄</span> Request Document
        </a>
        <a href="my_requests.php" class="menu-item">
            <span class="icon">📋</span> My Requests
        </a>

        <?php if ($role === 'alumni'): ?>
        <div class="menu-label">Alumni</div>
        <a href="graduate_tracer.php" class="menu-item">
            <span class="icon">📊</span> Graduate Tracer
        </a>
        <a href="employment_profile.php" class="menu-item">
            <span class="icon">💼</span> Employment Profile
        </a>
        <a href="alumni_documents.php" class="menu-item">
            <span class="icon">🎓</span> Alumni Documents
        </a>
        <?php endif; ?>

        <div class="menu-label">Account</div>
        <a href="profile.php" class="menu-item">
            <span class="icon">👤</span> Profile
        </a>
    </nav>

        <div class="sidebar-footer">
            <a href="../logout.php">🚪 Logout</a>
        </div>
    </nav>
</aside>

<!-- ─── Main ────────────────────────────────────────────── -->
<main class="main">

    <!-- ══ Topbar ══════════════════════════════════════════ -->
    <div class="topbar">
        <h1><?= $roleLabel ?> Dashboard</h1>

        <div class="topbar-right">

            <!-- Logout button -->
            <a href="../logout.php" class="logout-btn-top" title="Logout">🚪</a>

            <!-- 🔔 Notification Bell -->
            <div class="notif-wrap" id="notifWrap">

                <button class="notif-btn <?= $unreadCount > 0 ? 'has-unread' : '' ?>"
                        id="notifBtn"
                        onclick="togglePanel(event)"
                        title="Notifications"
                        aria-label="Notifications, <?= $unreadCount ?> unread">
                    🔔
                    <span class="notif-badge <?= $unreadCount === 0 ? 'hidden' : '' ?>"
                          id="notifBadge">
                        <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                    </span>
                </button>

                <!-- Dropdown Panel -->
                <div class="notif-panel" id="notifPanel">

                    <div class="notif-panel-header">
                        <div class="notif-panel-title">
                            🔔 Notifications
                            <span class="notif-count-pill" id="countPill"
                                  style="<?= $unreadCount===0 ? 'opacity:0' : '' ?>">
                                <?= $unreadCount ?> new
                            </span>
                        </div>
                        <button class="mark-all-btn" onclick="markAllRead()">
                            ✓ Mark all read
                        </button>
                    </div>

                    <div class="notif-list" id="notifList">
                        <?php if (empty($notifications)): ?>
                            <div class="notif-empty">
                                <div class="empty-emoji">🎉</div>
                                <p>You're all caught up!<br>No notifications yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n):
                                [$emoji, $cls, $title] = notifMeta($n['message']);
                                $isNew = !$n['is_read'];
                                $ago   = timeAgo($n['created_at']);
                            ?>
                                <div class="notif-item <?= $isNew ? 'unread' : '' ?>"
                                     id="ni-<?= $n['id'] ?>"
                                     onclick="markRead(<?= $n['id'] ?>, this)">

                                    <div class="notif-item-icon <?= $cls ?>"><?= $emoji ?></div>

                                    <div class="notif-item-body">
                                        <div class="notif-item-title"><?= e($title) ?></div>
                                        <div class="notif-item-msg"><?= e($n['message']) ?></div>
                                        <div class="notif-item-time">🕐 <?= $ago ?></div>
                                    </div>

                                    <?php if ($isNew): ?>
                                        <div class="notif-unread-dot" id="dot-<?= $n['id'] ?>"></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="notif-panel-footer">
                        <a href="notifications.php">View all notifications →</a>
                    </div>
                </div>
                <!-- end notif-panel -->

            </div>
            <!-- end notif-wrap -->

            <!-- User chip -->
            <div class="user-chip">
                <div class="chip-avatar"><?= $initial ?></div>
                <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong>
            </div>

        </div>
    </div>
    <!-- end topbar -->

    <!-- ══ Welcome Banner ══════════════════════════════════ -->
    <div class="welcome-banner">
        <h2>Welcome, <?= e($user['first_name']) ?>! 👋</h2>
        <p>
            <?= !empty($user['course'])     ? e($user['course'])     . ' | ' : '' ?>
            <?= !empty($user['student_id']) ? 'ID: ' . e($user['student_id']) : '' ?>
        </p>
    </div>

    <!-- ══ Stats ═══════════════════════════════════════════ -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon yellow">⏳</div>
            <div>
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= intval($stats['pending']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue">⚙️</div>
            <div>
                <div class="stat-label">Processing</div>
                <div class="stat-value"><?= intval($stats['processing']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">✅</div>
            <div>
                <div class="stat-label">Ready</div>
                <div class="stat-value"><?= intval($stats['ready']) ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gray">📬</div>
            <div>
                <div class="stat-label">Released</div>
                <div class="stat-value"><?= intval($stats['released']) ?></div>
            </div>
        </div>
    </div>

    <!-- ══ Announcements ════════════════════════════════════ -->
    <?php if (!empty($announcements)): ?>
    <div class="card" style="margin-bottom:1.2rem;">
        <div class="card-header">
            <h2>📢 Announcements</h2>
        </div>
        <div style="padding:1.2rem;display:flex;flex-direction:column;gap:0.9rem;">
            <?php foreach ($announcements as $ann): ?>
            <div style="
                padding: 1rem 1.2rem;
                background: <?= $ann['target_type'] === 'all' ? '#eff6ff' : '#fffbeb' ?>;
                border-left: 4px solid <?= $ann['target_type'] === 'all' ? '#1a56db' : '#d97706' ?>;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            ">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.75rem;margin-bottom:0.4rem;">
                    <div style="font-weight:700;color:#111827;font-size:0.95rem;">
                        <?= e($ann['title']) ?>
                        <?php if ($ann['target_type'] === 'user'): ?>
                            <span style="font-weight:600;color:#d97706;font-size:0.8rem;margin-left:0.5rem;">(Personal)</span>
                        <?php else: ?>
                            <span style="font-weight:600;color:#1a56db;font-size:0.8rem;margin-left:0.5rem;">(System)</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.72rem;color:#9ca3af;white-space:nowrap;">
                        <?= timeAgo($ann['created_at']) ?>
                    </div>
                </div>
                <div style="color:#374151;font-size:0.875rem;line-height:1.6;">
                    <?= nl2br(e($ann['message'])) ?>
                </div>
                <?php if ($ann['first_name']): ?>
                <div style="font-size:0.72rem;color:#6b7280;margin-top:0.5rem;padding-top:0.5rem;border-top:1px solid rgba(0,0,0,0.05);">
                    — <?= e($ann['first_name'] . ' ' . $ann['last_name']) ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Recent Requests ═════════════════════════════════ -->
    <div class="card">
        <div class="card-header">
            <h2>Recent Requests</h2>
            <a href="my_requests.php">View all →</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Document</th>
                    <th>Copies</th>
                    <th>Fee</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($latestReqs)): ?>
                    <tr>
                        <td colspan="5" class="empty-td">
                            No requests yet.
                            <a href="request_form.php" style="color:#1a56db;font-weight:600;margin-left:4px;">
                                Request a document →
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($latestReqs as $r): ?>
                        <tr>
                            <td style="font-weight:700;color:#111827;font-size:0.8rem;">
                                <?= e($r['request_code']) ?>
                            </td>
                            <td><?= e($r['doc_type']) ?></td>
                            <td style="text-align:center;"><?= intval($r['copies']) ?></td>
                            <td style="font-weight:600;">
                                ₱<?= number_format($r['fee'] * $r['copies'], 2) ?>
                            </td>
                            <td>
                                <span class="status-badge s-<?= e($r['status']) ?>">
                                    <?= ucfirst(e($r['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>

<!-- ─── Notification JS ──────────────────────────────────── -->
<script>
let panelOpen    = false;
let unreadCount  = <?= $unreadCount ?>;
const PAGE_URL   = window.location.pathname;

/* Toggle open/close */
function togglePanel(e) {
    e.stopPropagation();
    panelOpen = !panelOpen;
    document.getElementById('notifPanel').classList.toggle('open', panelOpen);
}

/* Close when clicking outside */
document.addEventListener('click', function(e) {
    if (!document.getElementById('notifWrap').contains(e.target) && panelOpen) {
        document.getElementById('notifPanel').classList.remove('open');
        panelOpen = false;
    }
});

/* Escape key */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && panelOpen) {
        document.getElementById('notifPanel').classList.remove('open');
        panelOpen = false;
    }
});

/* Mark single read */
function markRead(id, el) {
    if (!el.classList.contains('unread')) return;
    fetch(PAGE_URL, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_mark_read=1&notif_id=' + id
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) return;
        el.classList.remove('unread');
        const dot = document.getElementById('dot-' + id);
        if (dot) dot.remove();
        unreadCount = Math.max(0, unreadCount - 1);
        syncBadge();
    });
}

/* Mark all read */
function markAllRead() {
    fetch(PAGE_URL, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_mark_all_read=1'
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) return;
        document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
        document.querySelectorAll('.notif-unread-dot').forEach(el => el.remove());
        unreadCount = 0;
        syncBadge();
    });
}

/* Sync badge counter + pill */
function syncBadge() {
    const badge = document.getElementById('notifBadge');
    const pill  = document.getElementById('countPill');
    const btn   = document.getElementById('notifBtn');

    if (unreadCount > 0) {
        badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
        badge.classList.remove('hidden');
        pill.textContent = unreadCount + ' new';
        pill.style.opacity = '1';
        btn.classList.add('has-unread');
    } else {
        badge.classList.add('hidden');
        pill.style.opacity = '0';
        btn.classList.remove('has-unread');
    }
}

/* Poll every 60s for new notifications */
function pollCount() {
    fetch(PAGE_URL + '?ajax_unread_count=1')
        .then(r => r.json())
        .then(d => {
            if (typeof d.count === 'number' && d.count !== unreadCount) {
                unreadCount = d.count;
                syncBadge();
                if (panelOpen) refreshList();
            }
        })
        .catch(() => {});
}

/* Refresh list HTML when polling detects change */
function refreshList() {
    fetch(PAGE_URL + '?ajax_notif_list=1')
        .then(r => r.json())
        .then(items => {
            const list = document.getElementById('notifList');
            if (!items.length) {
                list.innerHTML = `<div class="notif-empty"><div class="empty-emoji">🎉</div><p>You're all caught up!</p></div>`;
                return;
            }

            const iconMap = [
                ['ready',   '📦','type-ready',   'Document Ready'],
                ['approv',  '✅','type-approved','Request Approved'],
                ['process', '⚙️','type-process', 'Being Processed'],
                ['cancel',  '❌','type-cancel',  'Request Cancelled'],
                ['releas',  '📬','type-ready',   'Document Released'],
                ['paid',    '💳','type-approved','Payment Confirmed'],
                ['welcom',  '👋','type-info',    'Welcome!'],
            ];

            list.innerHTML = items.map(n => {
                const lm  = n.message.toLowerCase();
                let emoji = '🔔', cls = 'type-info', title = 'Notification';
                for (const [k,e,c,t] of iconMap) {
                    if (lm.includes(k)) { emoji=e; cls=c; title=t; break; }
                }
                const isNew = n.is_read == 0;
                const ago   = jsTimeAgo(n.created_at);
                return `
                <div class="notif-item ${isNew?'unread':''}" id="ni-${n.id}" onclick="markRead(${n.id},this)">
                    <div class="notif-item-icon ${cls}">${emoji}</div>
                    <div class="notif-item-body">
                        <div class="notif-item-title">${esc(title)}</div>
                        <div class="notif-item-msg">${esc(n.message)}</div>
                        <div class="notif-item-time">🕐 ${ago}</div>
                    </div>
                    ${isNew ? `<div class="notif-unread-dot" id="dot-${n.id}"></div>` : ''}
                </div>`;
            }).join('');
        })
        .catch(() => {});
}

function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function jsTimeAgo(ds) {
    const d = Math.floor((Date.now() - new Date(ds).getTime()) / 1000);
    if (d < 60)     return 'just now';
    if (d < 3600)   return Math.floor(d/60)    + ' min ago';
    if (d < 86400)  return Math.floor(d/3600)  + ' hr ago';
    if (d < 604800) return Math.floor(d/86400) + ' day ago';
    return new Date(ds).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
}

setInterval(pollCount, 3000);
</script>

</body>
</html>