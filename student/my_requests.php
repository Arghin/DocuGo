<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit();
}

$conn   = getConnection();
$userId = $_SESSION['user_id'];

/* ── AJAX handlers ──────────────────────────────────────── */
if (isset($_POST['ajax_mark_read'])) {
    $nid = intval($_POST['notif_id'] ?? 0);
    if ($nid > 0) {
        $s = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
        $s->bind_param("ii", $nid, $userId); $s->execute(); $s->close();
    }
    echo json_encode(['ok' => true]); exit();
}
if (isset($_POST['ajax_mark_all_read'])) {
    $s = $conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?");
    $s->bind_param("i", $userId); $s->execute(); $s->close();
    echo json_encode(['ok' => true]); exit();
}
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

/* ── User info ──────────────────────────────────────────── */
$uStmt = $conn->prepare("SELECT first_name, last_name, role FROM users WHERE id=?");
$uStmt->bind_param("i", $userId); $uStmt->execute();
$user = $uStmt->get_result()->fetch_assoc();
$uStmt->close();

/* ── Unread notifications ───────────────────────────────── */
$nStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
$nStmt->bind_param("i", $userId); $nStmt->execute();
$unreadCount = (int)$nStmt->get_result()->fetch_assoc()['c'];
$nStmt->close();

/* ── Filters ────────────────────────────────────────────── */
$status  = $_GET['status'] ?? '';
$search  = trim($_GET['q'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

/* ── Tab counts ─────────────────────────────────────────── */
$tabCounts = ['all' => 0];
$tcResult  = $conn->query("SELECT status, COUNT(*) AS c FROM document_requests WHERE user_id=$userId GROUP BY status");
while ($t = $tcResult->fetch_assoc()) {
    $tabCounts[$t['status']] = (int)$t['c'];
    $tabCounts['all'] += (int)$t['c'];
}

/* ── Build query ────────────────────────────────────────── */
$where  = ["dr.user_id = $userId"];
$params = [];
$types  = '';

if ($status !== '') {
    $where[] = "dr.status = ?"; $params[] = $status; $types .= 's';
}
if ($search !== '') {
    $like = "%$search%";
    $where[] = "(dr.request_code LIKE ? OR dt.name LIKE ?)";
    $params[] = $like; $params[] = $like; $types .= 'ss';
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM document_requests dr JOIN document_types dt ON dr.document_type_id=dt.id $whereSQL");
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows  = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $perPage));
$countStmt->close();

$params[] = $perPage; $params[] = $offset; $types .= 'ii';

$stmt = $conn->prepare("
    SELECT dr.*, dt.name AS doc_type, dt.fee,
           (dt.fee * dr.copies) AS total_fee,
           cs.stub_code,
           pr.official_receipt_number, pr.payment_date
    FROM document_requests dr
    JOIN document_types dt ON dr.document_type_id = dt.id
    LEFT JOIN claim_stubs cs ON dr.id = cs.request_id
    LEFT JOIN payment_records pr ON dr.id = pr.request_id
    $whereSQL
    ORDER BY dr.requested_at DESC
    LIMIT ? OFFSET ?
");
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

function escape($v)  { return htmlspecialchars($v ?? ''); }
function formatDate($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function formatDateTime($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }

$statusSteps = [
    ['key' => 'pending',    'label' => 'Submitted'],
    ['key' => 'approved',   'label' => 'Approved'],
    ['key' => 'processing', 'label' => 'Processing'],
    ['key' => 'ready',      'label' => 'Ready'],
    ['key' => 'paid',       'label' => 'Paid'],
    ['key' => 'released',   'label' => 'Released'],
];

function getStepIndex($s) {
    return ['pending'=>0,'approved'=>1,'processing'=>2,'ready'=>3,'paid'=>4,'released'=>5,'cancelled'=>-1][$s] ?? 0;
}

$isAlumni  = ($user['role'] === 'alumni');
$roleLabel = ucfirst($user['role']);
$initial   = strtoupper(substr($user['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Requests — ADFC DocuGo</title>
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

        /* New Request Button */
        .btn-new {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border: none; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 0.8rem; font-weight: 600;
            text-decoration: none; cursor: pointer;
            transition: transform 0.2s;
        }
        .btn-new:hover { transform: translateY(-1px); opacity: 0.95; }

        /* Notification Bell - Golden */
        .notif-wrap { position: relative; }

        .notif-btn {
            position: relative;
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 1.2rem;
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
            0%,100%{transform:rotate(0)} 20%{transform:rotate(-14deg)}
            40%{transform:rotate(14deg)} 60%{transform:rotate(-9deg)} 80%{transform:rotate(9deg)}
        }

        .notif-badge {
            position: absolute; top: -4px; right: -4px;
            background: var(--red); color: #fff;
            font-size: 0.6rem; font-weight: 800;
            min-width: 18px; height: 18px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }

        .notif-badge.hidden { display: none; }

        /* Notification panel */
        .notif-panel {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 360px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
            z-index: 500; overflow: hidden;
            opacity: 0; transform: translateY(-6px);
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
            backdrop-filter: blur(10px);
        }

        .notif-panel.open {
            opacity: 1; transform: translateY(0);
            pointer-events: all;
        }

        .notif-panel::before {
            content: ''; position: absolute; top: -7px; right: 13px;
            width: 13px; height: 13px; background: var(--surface);
            border-left: 1px solid var(--border); border-top: 1px solid var(--border);
            transform: rotate(45deg);
        }

        .notif-panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--border);
        }

        .notif-panel-title {
            font-family: 'Sora', sans-serif;
            font-size: 0.9rem; font-weight: 700; color: var(--text);
            display: flex; align-items: center; gap: 0.45rem;
        }

        .notif-count-pill {
            background: var(--gold); color: #1a1a2e;
            font-size: 0.62rem; font-weight: 800;
            padding: 2px 7px; border-radius: 10px;
        }

        .mark-all-btn {
            font-size: 0.7rem; color: var(--accent2);
            background: none; border: none; cursor: pointer;
            font-weight: 600; padding: 4px 8px;
            border-radius: var(--radius-sm);
            transition: background 0.15s;
        }
        .mark-all-btn:hover { background: var(--surface-hv); }

        .notif-list { max-height: 300px; overflow-y: auto; }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

        .notif-item {
            display: flex; align-items: flex-start; gap: 0.7rem;
            padding: 0.8rem 1.1rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer; transition: background 0.12s;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: var(--surface-hv); }
        .notif-item.unread { background: rgba(255,215,0,0.08); }

        .notif-item-icon {
            width: 34px; height: 34px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0;
        }
        .type-ready    { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .type-approved { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .type-process  { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .type-cancel   { background: rgba(248,113,113,0.15); color: #f87171; }
        .type-info     { background: rgba(255,215,0,0.15); color: #ffd700; }

        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title { font-size: 0.8rem; font-weight: 700; color: var(--text); margin-bottom: 2px; }
        .notif-item-msg { font-size: 0.7rem; color: var(--text-muted); line-height: 1.4; }
        .notif-item-time { font-size: 0.65rem; color: var(--text-dim); margin-top: 3px; }
        .notif-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--gold); flex-shrink: 0; margin-top: 7px; }

        .notif-empty { padding: 2rem 1rem; text-align: center; }
        .notif-empty .empty-emoji { font-size: 1.8rem; margin-bottom: 0.4rem; }
        .notif-empty p { font-size: 0.8rem; color: var(--text-dim); }

        .notif-panel-footer {
            padding: 0.65rem 1.1rem;
            border-top: 1px solid var(--border);
            text-align: center;
        }
        .notif-panel-footer a {
            font-size: 0.75rem; color: var(--accent2);
            text-decoration: none; font-weight: 600;
        }
        .notif-panel-footer a:hover { text-decoration: underline; }

        /* User Chip */
        .user-chip {
            display: flex; align-items: center; gap: 0.5rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 0.35rem 0.85rem 0.35rem 0.45rem;
            font-size: 0.8rem; color: var(--text);
        }
        .chip-avatar {
            width: 28px; height: 28px; border-radius: 50%;
            background: var(--accent); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 800;
        }

        /* Logout Button */
        .logout-btn-top {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: rgba(248,113,113,0.15);
            color: var(--red);
            display: flex; align-items: center; justify-content: center;
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

        /* Tabs */
        .tabs {
            display: flex; gap: 0.25rem; flex-wrap: wrap;
            background: var(--surface);
            padding: 0.4rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            margin-bottom: 1rem;
        }
        .tab {
            padding: 0.4rem 0.8rem;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.75rem; font-weight: 500;
            color: var(--text-muted);
            transition: all 0.15s;
        }
        .tab:hover { background: var(--bg2); color: var(--text); }
        .tab.active { background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; font-weight: 700; }
        .tab .cnt {
            background: rgba(0,0,0,0.1); border-radius: 10px;
            padding: 1px 5px; font-size: 0.65rem; margin-left: 3px;
        }
        .tab.active .cnt { background: rgba(255,255,255,0.25); }

        /* Filters Bar */
        .filters-bar {
            background: var(--surface);
            padding: 0.75rem 1rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            margin-bottom: 1rem;
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            flex-wrap: wrap;
        }
        .filters-count { font-size: 0.8rem; color: var(--text-muted); }
        .filters-count strong { color: var(--text); }
        .search-form { display: flex; gap: 0.4rem; align-items: center; }
        .search-input {
            padding: 0.5rem 0.8rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.8rem; font-family: inherit;
            background: var(--bg2);
            color: var(--text);
            width: 220px;
            transition: border-color 0.15s;
        }
        .search-input:focus { outline: none; border-color: var(--accent); }
        .search-btn {
            padding: 0.5rem 0.85rem;
            background: var(--accent); color: #fff;
            border: none; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 0.75rem; font-weight: 600;
            cursor: pointer; transition: background 0.15s;
        }
        .search-btn:hover { background: var(--primary-dk); }
        .clear-btn {
            padding: 0.5rem 0.75rem;
            background: var(--surface); color: var(--text-muted);
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            font-size: 0.75rem; font-weight: 600;
            text-decoration: none; transition: all 0.15s;
        }
        .clear-btn:hover { background: var(--surface-hv); border-color: var(--accent); }

        /* Request Card */
        .request-card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            margin-bottom: 1rem;
            overflow: hidden;
            border-left: 4px solid var(--border);
            transition: transform 0.2s;
        }
        .request-card:hover { transform: translateY(-2px); border-color: var(--border-hv); }
        .request-card.pending    { border-left-color: var(--gold); }
        .request-card.approved   { border-left-color: var(--blue); }
        .request-card.processing { border-left-color: var(--accent); }
        .request-card.ready      { border-left-color: var(--gold); border-left-width: 5px; }
        .request-card.paid       { border-left-color: var(--green); }
        .request-card.released   { border-left-color: var(--purple); }
        .request-card.cancelled  { border-left-color: var(--red); opacity: 0.75; }

        /* Card header */
        .rc-header {
            padding: 1rem 1.2rem;
            display: flex; align-items: flex-start;
            justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
        }
        .rc-code {
            font-family: monospace;
            font-size: 0.7rem; color: var(--text-dim); margin-bottom: 4px;
            letter-spacing: 0.5px;
        }
        .rc-doctype { font-size: 0.9rem; font-weight: 700; color: var(--text); }
        .rc-meta { font-size: 0.7rem; color: var(--text-dim); margin-top: 4px; }
        .rc-meta span { margin-right: 0.5rem; }

        .rc-header-right {
            display: flex; flex-direction: column;
            align-items: flex-end; gap: 5px; flex-shrink: 0;
        }
        .rc-fee { font-size: 0.9rem; font-weight: 800; color: var(--green); }
        .rc-date { font-size: 0.7rem; color: var(--text-dim); }

        .status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 20px;
            font-size: 0.68rem; font-weight: 700;
        }
        .status-badge::before { content:''; width:6px; height:6px; border-radius:50%; }
        .s-pending    { background: rgba(255,215,0,0.15); color: #ffd700; } .s-pending::before    { background: #ffd700; }
        .s-approved   { background: rgba(96,165,250,0.15); color: #60a5fa; } .s-approved::before   { background: #60a5fa; }
        .s-processing { background: rgba(59,107,255,0.15); color: #3b6bff; } .s-processing::before { background: #3b6bff; }
        .s-ready      { background: rgba(255,215,0,0.15); color: #ffd700; } .s-ready::before      { background: #ffd700; }
        .s-paid       { background: rgba(76,217,138,0.15); color: #4cd98a; } .s-paid::before       { background: #4cd98a; }
        .s-released   { background: rgba(167,139,250,0.15); color: #a78bfa; } .s-released::before   { background: #a78bfa; }
        .s-cancelled  { background: rgba(248,113,113,0.15); color: #f87171; } .s-cancelled::before  { background: #f87171; }

        /* Tracker */
        .tracker-wrap {
            padding: 0.8rem 1.2rem 0.6rem;
            border-top: 1px solid var(--border);
        }
        .tracker { display: flex; align-items: center; position: relative; }
        .tracker-line-bg {
            position: absolute; left: 0; right: 0; top: 50%;
            transform: translateY(-50%); height: 4px;
            background: var(--border); z-index: 0; border-radius: 4px;
        }
        .tracker-line-fill {
            position: absolute; left: 0; top: 50%;
            transform: translateY(-50%); height: 4px;
            background: linear-gradient(90deg, var(--accent), var(--accent2));
            z-index: 1; border-radius: 4px; transition: width 0.5s ease;
        }
        .tracker-steps {
            display: flex; justify-content: space-between;
            width: 100%; position: relative; z-index: 2;
        }
        .tracker-step { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .step-dot {
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--surface); border: 2px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.65rem; font-weight: 700; color: var(--text-dim);
            transition: all 0.3s;
        }
        .step-dot.done    { background: var(--accent); border-color: var(--accent); color: #fff; }
        .step-dot.current { border-color: var(--accent); color: var(--accent); box-shadow: 0 0 0 3px rgba(59,107,255,0.2); }
        .step-label {
            font-size: 0.6rem; color: var(--text-dim); text-align: center;
            font-weight: 500; white-space: nowrap;
        }
        .step-label.done    { color: var(--accent); font-weight: 600; }
        .step-label.current { color: var(--text); font-weight: 700; }

        /* Alert Banners */
        .ready-alert {
            background: rgba(255,215,0,0.08);
            border-top: 1px solid rgba(255,215,0,0.3);
            padding: 0.8rem 1.2rem;
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
        }
        .ready-alert-text { font-size: 0.8rem; color: var(--gold); font-weight: 500; }
        .ready-alert-text strong { display: block; font-size: 0.85rem; margin-bottom: 2px; }

        .released-info {
            background: rgba(167,139,250,0.08);
            border-top: 1px solid rgba(167,139,250,0.3);
            padding: 0.65rem 1.2rem;
            font-size: 0.8rem; color: var(--purple);
        }
        .cancelled-banner {
            background: rgba(248,113,113,0.08);
            border-top: 1px solid rgba(248,113,113,0.3);
            padding: 0.65rem 1.2rem;
            font-size: 0.8rem; color: var(--red); font-weight: 600;
        }

        /* Card Footer */
        .rc-footer {
            padding: 0.6rem 1.2rem;
            border-top: 1px solid var(--border);
            display: flex; align-items: center;
            justify-content: space-between;
            flex-wrap: wrap; gap: 0.5rem;
            background: var(--bg2);
        }
        .rc-footer-left { font-size: 0.75rem; color: var(--text-dim); }
        .rc-footer-right { display: flex; gap: 0.4rem; align-items: center; }

        .btn-stub {
            padding: 4px 10px; border-radius: 6px;
            font-size: 0.7rem; font-weight: 600;
            background: rgba(167,139,250,0.15); color: #a78bfa;
            text-decoration: none; border: none; cursor: pointer;
            font-family: inherit; transition: all 0.15s;
        }
        .btn-stub:hover { background: rgba(167,139,250,0.25); transform: translateY(-1px); }
        .btn-cancel {
            padding: 4px 10px; border-radius: 6px;
            font-size: 0.7rem; font-weight: 600;
            background: rgba(248,113,113,0.15); color: #f87171;
            border: none; cursor: pointer; font-family: inherit;
            transition: all 0.15s;
        }
        .btn-cancel:hover { background: rgba(248,113,113,0.25); transform: translateY(-1px); }

        /* Empty State */
        .empty-state {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            text-align: center;
            padding: 3rem 2rem;
        }
        .empty-state .es-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .empty-state h3 { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 700; color: var(--text); margin-bottom: 0.35rem; }
        .empty-state p  { font-size: 0.8rem; color: var(--text-muted); }

        /* Pagination */
        .pagination-wrap {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 1rem; font-size: 0.8rem; color: var(--text-muted);
            flex-wrap: wrap; gap: 0.5rem;
        }
        .pagination { display: flex; gap: 0.3rem; }
        .pagination a, .pagination span {
            padding: 0.4rem 0.7rem; border-radius: 6px;
            text-decoration: none; font-size: 0.75rem; font-weight: 600;
        }
        .pagination a { background: var(--surface); color: var(--text-muted); border: 1px solid var(--border); transition: all 0.15s; }
        .pagination a:hover { background: var(--surface-hv); border-color: var(--accent); }
        .pagination .current { background: var(--accent); color: #fff; border: 1px solid var(--accent); }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .step-label { display: none; }
            .search-input { width: 150px; }
            .notif-panel { width: 320px; right: -50px; }
        }
        @media (max-width: 500px) {
            .notif-panel { width: 280px; right: -50px; }
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
                <div class="brand-sub"><?= $isAlumni ? 'Alumni Portal' : 'Student Portal' ?></div>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-section">MAIN</div>
        <a href="dashboard.php" class="menu-item"><span class="menu-icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="menu-icon">📄</span> Request Document</a>
        <a href="my_requests.php" class="menu-item active"><span class="menu-icon">📋</span> My Requests</a>
        <a href="notifications.php" class="menu-item"><span class="menu-icon">🔔</span> Notifications</a>

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
        <h1>📋 My Requests</h1>
        <div class="topbar-right">
            <a href="request_form.php" class="btn-new"><i class="fas fa-plus"></i> New Request</a>

            <!-- Notification Bell -->
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-btn <?= $unreadCount > 0 ? 'has-unread' : '' ?>" id="notifBtn" onclick="togglePanel(event)">
                    <i class="fas fa-bell"></i>
                    <span class="notif-badge <?= $unreadCount === 0 ? 'hidden' : '' ?>" id="notifBadge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                </button>

                <div class="notif-panel" id="notifPanel">
                    <div class="notif-panel-header">
                        <div class="notif-panel-title">
                            <i class="fas fa-bell" style="color: var(--gold);"></i> Notifications
                            <span class="notif-count-pill" id="countPill"><?= $unreadCount ?> new</span>
                        </div>
                        <button class="mark-all-btn" onclick="markAllRead()"><i class="fas fa-check-double"></i> Read all</button>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="notif-empty"><div class="empty-emoji">🔔</div><p>Loading notifications…</p></div>
                    </div>
                    <div class="notif-panel-footer">
                        <a href="notifications.php">View all →</a>
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

    <!-- Status Tabs -->
    <div class="tabs">
        <?php
        $tabs = [
            ''           => 'All',
            'pending'    => 'Pending',
            'approved'   => 'Approved',
            'processing' => 'Processing',
            'ready'      => 'Ready',
            'paid'       => 'Paid',
            'released'   => 'Released',
            'cancelled'  => 'Cancelled',
        ];
        foreach ($tabs as $val => $label):
            $cnt    = $val === '' ? ($tabCounts['all'] ?? 0) : ($tabCounts[$val] ?? 0);
            $active = $status === $val ? 'active' : '';
            $url    = '?' . http_build_query(['status' => $val, 'q' => $search]);
        ?>
            <a href="<?= $url ?>" class="tab <?= $active ?>">
                <?= $label ?><span class="cnt"><?= $cnt ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <div class="filters-count">
            <strong><?= $totalRows ?></strong> request<?= $totalRows != 1 ? 's' : '' ?> found
        </div>
        <form method="GET" class="search-form">
            <input type="hidden" name="status" value="<?= escape($status) ?>">
            <input type="text" name="q" class="search-input" placeholder="Search code or document…" value="<?= escape($search) ?>">
            <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
            <?php if ($search): ?>
                <a href="?status=<?= escape($status) ?>" class="clear-btn"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Request Cards -->
    <?php if (!empty($requests)): ?>
        <?php foreach ($requests as $r):
            $stepIdx     = getStepIndex($r['status']);
            $isCancelled = $r['status'] === 'cancelled';
            $fillPct     = $isCancelled ? 0 : ($stepIdx / (count($statusSteps) - 1)) * 100;
        ?>
        <div class="request-card <?= escape($r['status']) ?>">

            <!-- Header -->
            <div class="rc-header">
                <div>
                    <div class="rc-code"><i class="fas fa-hashtag"></i> <?= escape($r['request_code']) ?></div>
                    <div class="rc-doctype"><?= escape($r['doc_type']) ?></div>
                    <div class="rc-meta">
                        <span><i class="fas fa-copy"></i> <?= $r['copies'] ?> copy</span>
                        <span>·</span>
                        <span><i class="fas fa-truck"></i> <?= ucfirst(escape($r['release_mode'])) ?></span>
                        <?php if ($r['preferred_release_date']): ?>
                            <span>·</span>
                            <span><i class="fas fa-calendar"></i> Preferred: <?= formatDate($r['preferred_release_date']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rc-header-right">
                    <div class="rc-fee"><i class="fas fa-tag"></i> ₱<?= number_format($r['total_fee'], 2) ?></div>
                    <div class="rc-date"><i class="fas fa-clock"></i> <?= formatDate($r['requested_at']) ?></div>
                    <span class="status-badge s-<?= escape($r['status']) ?>">
                        <?= ucfirst(escape($r['status'])) ?>
                    </span>
                </div>
            </div>

            <!-- Tracker -->
            <?php if (!$isCancelled): ?>
            <div class="tracker-wrap">
                <div class="tracker">
                    <div class="tracker-line-bg"></div>
                    <div class="tracker-line-fill" style="width:<?= $fillPct ?>%;"></div>
                    <div class="tracker-steps">
                        <?php foreach ($statusSteps as $i => $step):
                            $done    = $i < $stepIdx;
                            $current = $i === $stepIdx;
                            $cls     = $done ? 'done' : ($current ? 'current' : '');
                        ?>
                        <div class="tracker-step">
                            <div class="step-dot <?= $cls ?>"><?= $done ? '<i class="fas fa-check"></i>' : ($i + 1) ?></div>
                            <div class="step-label <?= $cls ?>"><?= $step['label'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="cancelled-banner"><i class="fas fa-ban"></i> This request has been cancelled.</div>
            <?php endif; ?>

            <!-- Ready alert -->
            <?php if ($r['status'] === 'ready'): ?>
            <div class="ready-alert">
                <div class="ready-alert-text">
                    <strong><i class="fas fa-exclamation-triangle"></i> Action Required — Payment Needed</strong>
                    <span>Your document is ready. Go to the Registrar's Office, present <strong><?= escape($r['request_code']) ?></strong>, and pay <strong>₱<?= number_format($r['total_fee'], 2) ?></strong> to claim it.</span>
                </div>
                <?php if ($r['stub_code']): ?>
                    <a href="claim_stub.php?code=<?= escape($r['stub_code']) ?>" target="_blank" class="btn-stub"><i class="fas fa-receipt"></i> View Claim Stub</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Released info -->
            <?php if ($r['status'] === 'released'): ?>
            <div class="released-info">
                <i class="fas fa-check-circle"></i> Document released.
                <?php if ($r['official_receipt_number']): ?>
                    &nbsp;·&nbsp; OR#: <strong><?= escape($r['official_receipt_number']) ?></strong>
                <?php endif; ?>
                <?php if ($r['payment_date']): ?>
                    &nbsp;·&nbsp; Paid on <?= formatDateTime($r['payment_date']) ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="rc-footer">
                <div class="rc-footer-left">
                    <?php if ($r['stub_code']): ?>
                        <i class="fas fa-stub"></i> Stub: <code><?= escape($r['stub_code']) ?></code>
                    <?php else: ?>
                        <i class="fas fa-credit-card"></i> Payment: <span class="status-badge s-<?= $r['payment_status'] === 'paid' ? 'paid' : 'pending' ?>" style="font-size:0.65rem;">
                            <?= ucfirst($r['payment_status'] ?? 'unpaid') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="rc-footer-right">
                    <?php if ($r['stub_code']): ?>
                        <a href="claim_stub.php?code=<?= escape($r['stub_code']) ?>" target="_blank" class="btn-stub"><i class="fas fa-print"></i> Claim Stub</a>
                    <?php endif; ?>
                    <?php if (!in_array($r['status'], ['released','cancelled'])): ?>
                        <form method="POST" action="cancel_request.php" style="display:inline;" onsubmit="return confirm('Cancel this request?\nThis cannot be undone.')">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <button class="btn-cancel"><i class="fas fa-times"></i> Cancel</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="empty-state">
            <div class="es-icon"><i class="fas fa-inbox"></i></div>
            <h3>No requests found</h3>
            <p>
                <?= $search ? 'No results for "' . escape($search) . '".' : "You haven't submitted any document requests yet." ?>
            </p>
            <?php if (!$search): ?>
                <a href="request_form.php" class="btn-new" style="display:inline-flex;margin-top:1.2rem;">
                    <i class="fas fa-plus"></i> Submit Your First Request
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-wrap">
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$page-1]) ?>"><i class="fas fa-chevron-left"></i> Prev</a>
            <?php endif; ?>
            <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$i]) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$page+1]) ?>">Next <i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
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

// Notification functions
let panelOpen = false;
let unreadCount = <?= $unreadCount ?>;
const PAGE_URL = window.location.pathname;

function togglePanel(e) {
    e.stopPropagation();
    panelOpen = !panelOpen;
    document.getElementById('notifPanel').classList.toggle('open', panelOpen);
    if (panelOpen) loadNotifList();
}

document.addEventListener('click', function(e) {
    if (!document.getElementById('notifWrap').contains(e.target) && panelOpen) {
        document.getElementById('notifPanel').classList.remove('open');
        panelOpen = false;
    }
});

function loadNotifList() {
    fetch('dashboard.php?ajax_notif_list=1')
        .then(r => r.json())
        .then(renderNotifList)
        .catch(() => {});
}

function renderNotifList(items) {
    const list = document.getElementById('notifList');
    if (!items.length) {
        list.innerHTML = `<div class="notif-empty"><div class="empty-emoji">🎉</div><p>All caught up!</p></div>`;
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
        return `<div class="notif-item ${isNew ? 'unread' : ''}" id="ni-${n.id}" onclick="markRead(${n.id}, this)">
            <div class="notif-item-icon ${cls}">${emoji}</div>
            <div class="notif-item-body">
                <div class="notif-item-title">${escapeHTML(title)}</div>
                <div class="notif-item-msg">${escapeHTML(n.message)}</div>
                <div class="notif-item-time"><i class="far fa-clock"></i> ${ago}</div>
            </div>
            ${isNew ? `<div class="notif-unread-dot" id="dot-${n.id}"></div>` : ''}
        </div>`;
    }).join('');
}

function markRead(id, el) {
    if (!el.classList.contains('unread')) return;
    fetch(PAGE_URL, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_mark_read=1&notif_id=' + id
    }).then(r => r.json()).then(d => {
        if (!d.ok) return;
        el.classList.remove('unread');
        const dot = document.getElementById('dot-' + id);
        if (dot) dot.remove();
        unreadCount = Math.max(0, unreadCount - 1);
        syncBadge();
    });
}

function markAllRead() {
    fetch(PAGE_URL, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax_mark_all_read=1'
    }).then(r => r.json()).then(d => {
        if (!d.ok) return;
        document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
        document.querySelectorAll('.notif-unread-dot').forEach(el => el.remove());
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

setInterval(() => {
    fetch('dashboard.php?ajax_unread_count=1').then(r => r.json()).then(d => {
        if (typeof d.count === 'number' && d.count !== unreadCount) {
            unreadCount = d.count;
            syncBadge();
        }
    }).catch(() => {});
}, 30000);
</script>

</body>
</html>