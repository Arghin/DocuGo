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

/* ── AJAX: dismiss notification (remove from panel) ──── */
if (isset($_POST['ajax_dismiss'])) {
    $nid = intval($_POST['notif_id'] ?? 0);
    // Just return success - the frontend will remove it from the DOM
    // No database deletion, just removes from view
    echo json_encode(['ok' => true]); exit();
}

/* ── AJAX: mark all read ────────────────────────────── */
if (isset($_POST['ajax_mark_all_read'])) {
    $s = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?");
    $s->bind_param("i", $userId);
    $s->execute(); $s->close();
    echo json_encode(['ok' => true]); exit();
}

/* ── AJAX: dismiss all notifications (remove from panel) ── */
if (isset($_POST['ajax_dismiss_all'])) {
    // Just return success - the frontend will remove all from the DOM
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
function escape($v) { return htmlspecialchars($v ?? ''); }

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
    <title><?= $roleLabel ?> Dashboard — ADFC DocuGo</title>
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
            --gold-dark:  #daa520;
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
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            flex-shrink: 0;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            padding: 1.4rem 1.6rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.2rem;
            box-shadow: 0 4px 14px rgba(26,86,219,0.22);
        }

        .welcome-banner h2 {
            font-family: 'Sora', sans-serif;
            font-size: 1.15rem;
            font-weight: 800;
            margin-bottom: 0.25rem;
        }

        .welcome-banner p {
            font-size: 0.82rem;
            opacity: 0.85;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.2rem;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: var(--border-hv);
        }

        .stat-icon {
            font-size: 1.4rem;
            width: 42px;
            height: 42px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon.yellow { background: rgba(255,215,0,0.15); color: #ffd700; }
        .stat-icon.blue { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .stat-icon.green { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .stat-icon.gray { background: rgba(255,255,255,0.1); color: var(--text-muted); }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .stat-value {
            font-family: 'Sora', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1.1;
        }

        /* Card */
        .card {
            background: var(--surface);
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

        .card-header a {
            font-size: 0.75rem;
            color: var(--accent2);
            text-decoration: none;
            font-weight: 600;
        }

        .card-header a:hover { text-decoration: underline; }

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

        .empty-td {
            text-align: center;
            padding: 2rem;
            color: var(--text-dim);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: capitalize;
        }
        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }
        .s-pending { background: rgba(255,215,0,0.15); color: #ffd700; }
        .s-pending::before { background: #ffd700; }
        .s-processing { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .s-processing::before { background: #60a5fa; }
        .s-ready { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .s-ready::before { background: #4cd98a; }
        .s-released { background: rgba(167,139,250,0.15); color: #a78bfa; }
        .s-released::before { background: #a78bfa; }
        .s-approved { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .s-approved::before { background: #4cd98a; }
        .s-cancelled { background: rgba(248,113,113,0.15); color: #f87171; }
        .s-cancelled::before { background: #f87171; }

        /* Notification Bell - Golden Yellow */
        .notif-wrap { position: relative; }

        .notif-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.2rem;
            transition: transform 0.2s, border-color 0.2s;
            color: var(--gold);
        }

        .notif-btn:hover {
            transform: scale(1.05);
            border-color: var(--gold);
        }

        .notif-btn.has-unread {
            border-color: var(--gold);
            animation: bellShake 0.9s ease-in-out 0.4s;
        }

        @keyframes bellShake {
            0%,100% { transform: rotate(0); }
            20% { transform: rotate(-14deg); }
            40% { transform: rotate(14deg); }
            60% { transform: rotate(-9deg); }
            80% { transform: rotate(9deg); }
        }

        .notif-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--red);
            color: #fff;
            font-size: 0.6rem;
            font-weight: 800;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
        }

        .notif-badge.hidden { display: none; }

        /* Notification Panel */
        .notif-panel {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 360px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
            z-index: 500;
            overflow: hidden;
            opacity: 0;
            transform: translateY(-6px);
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
            backdrop-filter: blur(10px);
        }

        .notif-panel.open {
            opacity: 1;
            transform: translateY(0);
            pointer-events: all;
        }

        .notif-panel::before {
            content: '';
            position: absolute;
            top: -7px;
            right: 13px;
            width: 13px;
            height: 13px;
            background: var(--surface);
            border-left: 1px solid var(--border);
            border-top: 1px solid var(--border);
            transform: rotate(45deg);
        }

        .notif-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.9rem 1.1rem;
            border-bottom: 1px solid var(--border);
        }

        .notif-panel-title {
            font-family: 'Sora', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }

        .notif-count-pill {
            background: var(--gold);
            color: #1a1a2e;
            font-size: 0.62rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 10px;
        }

        .mark-all-btn {
            font-size: 0.7rem;
            color: var(--accent2);
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            transition: background 0.15s;
        }

        .mark-all-btn:hover { background: var(--surface-hv); }

        .dismiss-all-btn {
            font-size: 0.7rem;
            color: var(--text-muted);
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: var(--radius-sm);
            transition: background 0.15s;
        }

        .dismiss-all-btn:hover { background: rgba(248,113,113,0.15); color: var(--red); }

        .notif-list {
            max-height: 350px;
            overflow-y: auto;
        }

        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            padding: 0.8rem 1.1rem;
            border-bottom: 1px solid var(--border);
            transition: background 0.12s;
            position: relative;
        }

        .notif-item:hover { background: var(--surface-hv); }
        .notif-item.unread { background: rgba(255,215,0,0.08); }

        .notif-item-icon {
            width: 34px;
            height: 34px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .type-ready { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .type-approved { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .type-process { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .type-cancel { background: rgba(248,113,113,0.15); color: #f87171; }
        .type-info { background: rgba(255,215,0,0.15); color: #ffd700; }

        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title { font-size: 0.8rem; font-weight: 700; color: var(--text); margin-bottom: 2px; }
        .notif-item-msg { font-size: 0.7rem; color: var(--text-muted); line-height: 1.4; }
        .notif-item-time { font-size: 0.65rem; color: var(--text-dim); margin-top: 3px; }
        .notif-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--gold); flex-shrink: 0; margin-top: 7px; }
        
        .notif-dismiss {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.7rem;
            color: var(--text-dim);
            padding: 4px;
            border-radius: var(--radius-sm);
            transition: all 0.15s;
            opacity: 0;
        }
        
        .notif-item:hover .notif-dismiss {
            opacity: 1;
        }
        
        .notif-dismiss:hover {
            background: rgba(248,113,113,0.15);
            color: var(--red);
        }

        .notif-empty { padding: 2rem 1rem; text-align: center; }
        .notif-empty p { font-size: 0.8rem; color: var(--text-dim); }

        .notif-panel-footer {
            padding: 0.65rem 1.1rem;
            border-top: 1px solid var(--border);
            text-align: center;
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .notif-panel-footer a {
            font-size: 0.75rem;
            color: var(--accent2);
            text-decoration: none;
            font-weight: 600;
        }

        .notif-panel-footer a:hover { text-decoration: underline; }

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

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .notif-panel { width: 320px; right: -50px; }
            .notif-panel::before { right: 64px; }
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
                <div class="brand-sub"><?= $roleLabel ?> Portal</div>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-section">MAIN</div>
        <a href="dashboard.php" class="menu-item active">
            <span class="menu-icon">🏠</span> Dashboard
        </a>
        <a href="request_form.php" class="menu-item">
            <span class="menu-icon">📄</span> Request Document
        </a>
        <a href="my_requests.php" class="menu-item">
            <span class="menu-icon">📋</span> My Requests
        </a>
        <a href="notifications.php" class="menu-item"><span class="menu-icon">🔔</span> Notifications</a>

        <?php if ($role === 'alumni'): ?>
        <div class="menu-section">ALUMNI</div>
        <a href="graduate_tracer.php" class="menu-item">
            <span class="menu-icon">📊</span> Graduate Tracer
        </a>
        <a href="employment_profile.php" class="menu-item">
            <span class="menu-icon">💼</span> Employment Profile
        </a>
        <a href="alumni_documents.php" class="menu-item">
            <span class="menu-icon">🎓</span> Alumni Documents
        </a>
        <?php endif; ?>

        <div class="menu-section">ACCOUNT</div>
        <a href="profile.php" class="menu-item">
            <span class="menu-icon">👤</span> Profile
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">

    <div class="topbar">
        <h1><?= $roleLabel ?> Dashboard</h1>

        <div class="topbar-right">
            <!-- Notification Bell - Golden Yellow -->
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-btn <?= $unreadCount > 0 ? 'has-unread' : '' ?>" id="notifBtn" onclick="togglePanel(event)">
                    <i class="fas fa-bell"></i>
                    <span class="notif-badge <?= $unreadCount === 0 ? 'hidden' : '' ?>" id="notifBadge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                </button>

                <div class="notif-panel" id="notifPanel">
                    <div class="notif-panel-header">
                        <div class="notif-panel-title">
                            <i class="fas fa-bell" style="color: var(--gold);"></i> Notifications
                            <span class="notif-count-pill" id="countPill" style="<?= $unreadCount===0 ? 'opacity:0' : '' ?>"><?= $unreadCount ?> new</span>
                        </div>
                        <div style="display: flex; gap: 0.25rem;">
                            <button class="dismiss-all-btn" onclick="dismissAllNotifications()" title="Remove all notifications from panel"><i class="fas fa-trash-alt"></i> Clear all</button>
                            <button class="mark-all-btn" onclick="markAllRead()"><i class="fas fa-check-double"></i> Read all</button>
                        </div>
                    </div>

                    <div class="notif-list" id="notifList">
                        <?php if (empty($notifications)): ?>
                            <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:2rem; opacity:0.5;"></i><p>No notifications yet.</p></div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n):
                                [$emoji, $cls, $title] = notifMeta($n['message']);
                                $isNew = !$n['is_read'];
                                $ago   = timeAgo($n['created_at']);
                            ?>
                                <div class="notif-item <?= $isNew ? 'unread' : '' ?>" id="ni-<?= $n['id'] ?>" data-notif-id="<?= $n['id'] ?>">
                                    <div class="notif-item-icon <?= $cls ?>"><?= $emoji ?></div>
                                    <div class="notif-item-body" onclick="markRead(<?= $n['id'] ?>, document.getElementById('ni-<?= $n['id'] ?>'))">
                                        <div class="notif-item-title"><?= escape($title) ?></div>
                                        <div class="notif-item-msg"><?= escape($n['message']) ?></div>
                                        <div class="notif-item-time"><i class="far fa-clock"></i> <?= $ago ?></div>
                                    </div>
                                    <button class="notif-dismiss" onclick="dismissNotification(<?= $n['id'] ?>, event)" title="Dismiss"><i class="fas fa-times"></i></button>
                                    <?php if ($isNew): ?><div class="notif-unread-dot" id="dot-<?= $n['id'] ?>"></div><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="notif-panel-footer">
                        <a href="notifications.php">View all notifications <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>

            <!-- User chip -->
            <div class="user-chip">
                <div class="chip-avatar"><?= $initial ?></div>
                <strong><?= escape($user['first_name'] . ' ' . $user['last_name']) ?></strong>
            </div>

        </div>
    </div>

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <h2>Welcome, <?= escape($user['first_name']) ?>! 👋</h2>
        <p><?= !empty($user['course']) ? escape($user['course']) . ' | ' : '' ?><?= !empty($user['student_id']) ? 'ID: ' . escape($user['student_id']) : '' ?></p>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon yellow"><i class="fas fa-clock"></i></div><div><div class="stat-label">Pending</div><div class="stat-value"><?= intval($stats['pending']) ?></div></div></div>
        <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-cog"></i></div><div><div class="stat-label">Processing</div><div class="stat-value"><?= intval($stats['processing']) ?></div></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fas fa-check-circle"></i></div><div><div class="stat-label">Ready</div><div class="stat-value"><?= intval($stats['ready']) ?></div></div></div>
        <div class="stat-card"><div class="stat-icon gray"><i class="fas fa-envelope-open-text"></i></div><div><div class="stat-label">Released</div><div class="stat-value"><?= intval($stats['released']) ?></div></div></div>
    </div>

    <!-- Announcements -->
    <?php if (!empty($announcements)): ?>
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-bullhorn"></i> Announcements</h2>
        </div>
        <div style="padding:1rem 1.2rem; display:flex; flex-direction:column; gap:0.8rem;">
            <?php foreach ($announcements as $ann): ?>
            <div style="padding:0.85rem 1rem; background:rgba(59,107,255,0.08); border-left:4px solid var(--accent); border-radius:var(--radius-sm);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:0.5rem; margin-bottom:0.3rem;">
                    <div style="font-weight:700; color:var(--text); font-size:0.85rem;"><?= escape($ann['title']) ?></div>
                    <div style="font-size:0.68rem; color:var(--text-dim);"><?= timeAgo($ann['created_at']) ?></div>
                </div>
                <div style="color:var(--text-muted); font-size:0.8rem; line-height:1.5;"><?= nl2br(escape($ann['message'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Requests -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-history"></i> Recent Requests</h2>
            <a href="my_requests.php">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <table>
            <thead>
                <tr><th>Code</th><th>Document</th><th>Copies</th><th>Fee</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php if (empty($latestReqs)): ?>
                    <tr><td colspan="5" class="empty-td">No requests yet. <a href="request_form.php" style="color:var(--accent2);">Request a document →</a></td></tr>
                <?php else: ?>
                    <?php foreach ($latestReqs as $r): ?>
                        <tr>
                            <td style="font-weight:700; font-family:monospace;"><?= escape($r['request_code']) ?></td>
                            <td><?= escape($r['doc_type']) ?></td>
                            <td><?= intval($r['copies']) ?></td>
                            <td style="font-weight:600; color:var(--green);">₱<?= number_format($r['fee'] * $r['copies'], 2) ?></td>
                            <td><span class="status-badge s-<?= escape($r['status']) ?>"><?= ucfirst(escape($r['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

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

// Notification functions
let panelOpen = false;
let unreadCount = <?= $unreadCount ?>;

function togglePanel(e) {
    e.stopPropagation();
    panelOpen = !panelOpen;
    document.getElementById('notifPanel').classList.toggle('open', panelOpen);
}

document.addEventListener('click', function(e) {
    if (!document.getElementById('notifWrap').contains(e.target) && panelOpen) {
        document.getElementById('notifPanel').classList.remove('open');
        panelOpen = false;
    }
});

function markRead(id, el) {
    if (!el.classList.contains('unread')) return;
    fetch(window.location.pathname, {
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

function dismissNotification(id, event) {
    event.stopPropagation();
    const notifItem = document.getElementById('ni-' + id);
    if (notifItem) {
        // Check if it was unread before removal
        const wasUnread = notifItem.classList.contains('unread');
        notifItem.remove();
        if (wasUnread) {
            unreadCount = Math.max(0, unreadCount - 1);
            syncBadge();
        }
        // Check if list is empty
        const notifList = document.getElementById('notifList');
        if (notifList.children.length === 0) {
            notifList.innerHTML = '<div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:2rem; opacity:0.5;"></i><p>No notifications yet.</p></div>';
        }
        // Send AJAX to server (optional - just to log, no database deletion)
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'ajax_dismiss=1&notif_id=' + id
        }).catch(() => {});
    }
}

function dismissAllNotifications() {
    const notifList = document.getElementById('notifList');
    const items = notifList.querySelectorAll('.notif-item');
    let removedUnread = 0;
    items.forEach(item => {
        if (item.classList.contains('unread')) removedUnread++;
        item.remove();
    });
    if (removedUnread > 0) {
        unreadCount = Math.max(0, unreadCount - removedUnread);
        syncBadge();
    }
    notifList.innerHTML = '<div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:2rem; opacity:0.5;"></i><p>No notifications yet.</p></div>';
    
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_dismiss_all=1'
    }).catch(() => {});
}

function markAllRead() {
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_mark_all_read=1'
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) return;
        document.querySelectorAll('.notif-item.unread').forEach(el => {
            el.classList.remove('unread');
            const dot = el.querySelector('.notif-unread-dot');
            if (dot) dot.remove();
        });
        unreadCount = 0;
        syncBadge();
    });
}

function syncBadge() {
    const badge = document.getElementById('notifBadge');
    const pill = document.getElementById('countPill');
    const btn = document.getElementById('notifBtn');
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

function pollCount() {
    fetch(window.location.pathname + '?ajax_unread_count=1')
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

function refreshList() {
    fetch(window.location.pathname + '?ajax_notif_list=1')
        .then(r => r.json())
        .then(items => {
            const list = document.getElementById('notifList');
            if (!items.length) {
                list.innerHTML = `<div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:2rem; opacity:0.5;"></i><p>No notifications yet.</p></div>`;
                return;
            }
            list.innerHTML = items.map(n => {
                const lm = n.message.toLowerCase();
                let emoji = '🔔', cls = 'type-info', title = 'Notification';
                if (lm.includes('ready')) { emoji = '📦'; cls = 'type-ready'; title = 'Document Ready'; }
                else if (lm.includes('approv')) { emoji = '✅'; cls = 'type-approved'; title = 'Request Approved'; }
                else if (lm.includes('process')) { emoji = '⚙️'; cls = 'type-process'; title = 'Being Processed'; }
                else if (lm.includes('cancel')) { emoji = '❌'; cls = 'type-cancel'; title = 'Request Cancelled'; }
                else if (lm.includes('releas')) { emoji = '📬'; cls = 'type-ready'; title = 'Document Released'; }
                else if (lm.includes('paid')) { emoji = '💳'; cls = 'type-approved'; title = 'Payment Confirmed'; }
                const isNew = n.is_read == 0;
                const ago = jsTimeAgo(n.created_at);
                return `<div class="notif-item ${isNew ? 'unread' : ''}" id="ni-${n.id}" data-notif-id="${n.id}">
                    <div class="notif-item-icon ${cls}">${emoji}</div>
                    <div class="notif-item-body" onclick="markRead(${n.id}, document.getElementById('ni-${n.id}'))">
                        <div class="notif-item-title">${escapeHTML(title)}</div>
                        <div class="notif-item-msg">${escapeHTML(n.message)}</div>
                        <div class="notif-item-time"><i class="far fa-clock"></i> ${ago}</div>
                    </div>
                    <button class="notif-dismiss" onclick="dismissNotification(${n.id}, event)" title="Dismiss"><i class="fas fa-times"></i></button>
                    ${isNew ? `<div class="notif-unread-dot" id="dot-${n.id}"></div>` : ''}
                </div>`;
            }).join('');
        });
}

function escapeHTML(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function jsTimeAgo(ds) {
    const d = Math.floor((Date.now() - new Date(ds).getTime()) / 1000);
    if (d < 60) return 'just now';
    if (d < 3600) return Math.floor(d/60) + ' min ago';
    if (d < 86400) return Math.floor(d/3600) + ' hr ago';
    if (d < 604800) return Math.floor(d/86400) + ' day ago';
    return new Date(ds).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
}

setInterval(pollCount, 3000);
</script>

</body>
</html>