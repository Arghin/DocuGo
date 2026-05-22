<?php
require_once '../includes/config.php';
requireLogin();

$conn = getConnection();

$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'student';

if ($role !== 'alumni') {
    header('Location: dashboard.php');
    exit();
}

/* ── AJAX handlers for notifications ──────────────────────── */
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
$stmt = $conn->prepare("SELECT first_name, last_name, course, year_graduated, student_id FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ── Unread notifications ───────────────────────────────── */
$nStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
$nStmt->bind_param("i", $userId); $nStmt->execute();
$unreadCount = (int)$nStmt->get_result()->fetch_assoc()['c'];
$nStmt->close();

/* ── Document types for alumni ───────────────────────────── */
$alumniDocTypes = $conn->query("
    SELECT id, name, description, fee, processing_days 
    FROM document_types 
    WHERE is_active = 1 
    AND name NOT LIKE '%Enrollment%'
    ORDER BY fee ASC
");

/* ── Handle form submission ──────────────────────────────── */
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_doc'])) {
    $docTypeId = intval($_POST['document_type_id'] ?? 0);
    $copies = intval($_POST['copies'] ?? 1);
    $purpose = trim($_POST['purpose'] ?? '');
    $releaseMode = $_POST['release_mode'] ?? 'pickup';
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $preferredDate = $_POST['preferred_release_date'] ?? null;

    if ($docTypeId <= 0) {
        $error = "Please select a document.";
    } elseif ($copies < 1 || $copies > 20) {
        $error = "Copies must be between 1 and 20.";
    } elseif ($purpose === '') {
        $error = "Please state the purpose.";
    } elseif ($releaseMode === 'delivery' && $deliveryAddress === '') {
        $error = "Please provide delivery address.";
    } else {
        $requestCode = 'DOC-' . date('Y') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        
        $stmt = $conn->prepare("
            INSERT INTO document_requests 
            (user_id, document_type_id, request_code, copies, purpose, 
             release_mode, delivery_address, preferred_release_date, status, requested_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("iisissss", $userId, $docTypeId, $requestCode, $copies, $purpose, $releaseMode, $deliveryAddress, $preferredDate);
        
        if ($stmt->execute()) {
            $success = "Request submitted! Code: <strong>$requestCode</strong>";
        } else {
            $error = "Failed to submit request.";
        }
        $stmt->close();
    }
}

function escape($v) { return htmlspecialchars($v ?? ''); }

$initial = strtoupper(substr($user['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Alumni Documents — ADFC DocuGo</title>
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

        .topbar h2 {
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

        /* Notification Bell - Golden */
        .notif-wrap { position: relative; }

        .notif-btn {
            position: relative;
            width: 40px; height: 40px;
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

        /* Alert */
        .alert { padding: 0.85rem 1rem; border-radius: var(--radius-md); margin-bottom: 1.2rem; font-size: 0.85rem; }
        .alert-success { background: rgba(76,217,138,0.15); color: var(--green); border-left: 4px solid var(--green); }
        .alert-error   { background: rgba(248,113,113,0.15); color: var(--red); border-left: 4px solid var(--red); }
        .alert-success a { color: var(--green); font-weight: 700; text-decoration: none; }
        .alert-success a:hover { text-decoration: underline; }

        /* Card */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.2rem;
        }
        .card h3 {
            font-family: 'Sora', sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 1rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px solid var(--border);
        }

        /* Document Grid */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .doc-card {
            background: var(--bg2);
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .doc-card:hover, .doc-card.selected {
            border-color: var(--accent);
            background: rgba(59,107,255,0.08);
        }
        .doc-card .name { font-weight: 700; font-size: 0.9rem; color: var(--text); margin-bottom: 0.3rem; }
        .doc-card .desc { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.6rem; }
        .doc-card .meta { font-size: 0.75rem; color: var(--accent); font-weight: 600; }
        .doc-card input { float: right; margin-top: 0.3rem; accent-color: var(--accent); }

        /* Form */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }
        label {
            display: block;
            margin-top: 0.8rem;
            margin-bottom: 0.3rem;
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        input, textarea, select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            font-size: 0.85rem;
            font-family: inherit;
            background: var(--bg2);
            color: var(--text);
            transition: all 0.2s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59,107,255,0.1);
        }
        .hint { font-size: 0.7rem; color: var(--text-dim); margin-top: 0.3rem; }

        /* Mode Group */
        .mode-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem;
            margin-top: 0.3rem;
        }
        .mode-opt {
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            padding: 0.8rem;
            cursor: pointer;
            transition: all 0.15s;
        }
        .mode-opt:hover { border-color: var(--accent); }
        .mode-opt.selected { border-color: var(--accent); background: rgba(59,107,255,0.08); }
        .mode-opt input { display: none; }
        .mode-opt .title { font-weight: 600; font-size: 0.85rem; color: var(--text); margin-bottom: 0.2rem; }
        .mode-opt .desc { font-size: 0.7rem; color: var(--text-dim); }

        .delivery-box { display: none; margin-top: 0.8rem; }

        /* Button */
        .btn {
            padding: 0.8rem 1.4rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            margin-top: 1rem;
            width: 100%;
            transition: transform 0.2s;
        }
        .btn:hover { transform: translateY(-1px); opacity: 0.95; }

        /* Badges */
        .badge {
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-pending { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-processing { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .badge-ready { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .badge-released { background: rgba(167,139,250,0.15); color: #a78bfa; }

        /* History Table */
        .history-table {
            width: 100%;
            border-collapse: collapse;
        }
        .history-table th, .history-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.8rem;
            text-align: left;
            color: var(--text-muted);
        }
        .history-table th {
            background: var(--bg2);
            color: var(--text-dim);
            font-weight: 700;
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .form-row { grid-template-columns: 1fr; }
            .doc-grid { grid-template-columns: 1fr; }
            .notif-panel { width: 320px; right: -50px; }
        }
        @media (max-width: 500px) {
            .notif-panel { width: 280px; right: -50px; }
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
                <div class="brand-sub">Alumni Portal</div>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-section">MAIN</div>
        <a href="dashboard.php" class="menu-item"><span class="menu-icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="menu-icon">📄</span> Request Document</a>
        <a href="my_requests.php" class="menu-item"><span class="menu-icon">📋</span> My Requests</a>
        <a href="notifications.php" class="menu-item"><span class="menu-icon">🔔</span> Notifications</a>

        <div class="menu-section">ALUMNI</div>
        <a href="graduate_tracer.php" class="menu-item"><span class="menu-icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item"><span class="menu-icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item active"><span class="menu-icon">🎓</span> Alumni Documents</a>

        <div class="menu-section">ACCOUNT</div>
        <a href="profile.php" class="menu-item"><span class="menu-icon">👤</span> Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<main class="main">
    <div class="topbar">
        <h2><i class="fas fa-graduation-cap"></i> Alumni Documents</h2>
        <div class="topbar-right">

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

    <?php if($success):?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?> <a href="my_requests.php"><i class="fas fa-arrow-right"></i> View My Requests</a></div>
    <?php endif;?>
    <?php if($error):?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif;?>

    <div class="card">
        <h3><i class="fas fa-file-alt"></i> Select Document</h3>
        <form method="POST" id="requestForm">
            <div class="doc-grid">
            <?php while($doc=$alumniDocTypes->fetch_assoc()):?>
            <label class="doc-card" id="card-<?= $doc['id'] ?>">
                <input type="radio" name="document_type_id" value="<?= $doc['id'] ?>" data-fee="<?= $doc['fee'] ?>" data-days="<?= $doc['processing_days'] ?>" required>
                <div class="name"><?= escape($doc['name']) ?></div>
                <div class="desc"><?= escape($doc['description'] ?? 'Official document') ?></div>
                <div class="meta"><i class="fas fa-tag"></i> ₱<?= number_format($doc['fee'],2) ?> · <i class="fas fa-clock"></i> <?= $doc['processing_days'] ?> day<?= $doc['processing_days']>1?'s':'' ?></div>
            </label>
            <?php endwhile;?>
            </div>

            <h3><i class="fas fa-clipboard-list"></i> Request Details</h3>
            <div class="form-row">
                <div><label><i class="fas fa-copy"></i> Number of Copies *</label><input type="number" name="copies" value="1" min="1" max="20" required></div>
                <div><label><i class="fas fa-calendar-alt"></i> Preferred Release Date</label><input type="date" name="preferred_release_date" min="<?= date('Y-m-d') ?>"></div>
            </div>
            <label><i class="fas fa-quote-left"></i> Purpose *</label>
            <textarea name="purpose" placeholder="e.g., Job application, scholarship, further studies..." required></textarea>

            <label><i class="fas fa-truck"></i> Release Mode *</label>
            <div class="mode-group">
                <label class="mode-opt selected" id="opt-pickup">
                    <input type="radio" name="release_mode" value="pickup" checked>
                    <div class="title"><i class="fas fa-building"></i> Pickup</div>
                    <div class="desc">Claim at Registrar's Office</div>
                </label>
                <label class="mode-opt" id="opt-delivery">
                    <input type="radio" name="release_mode" value="delivery">
                    <div class="title"><i class="fas fa-shipping-fast"></i> Delivery</div>
                    <div class="desc">Ship to your address</div>
                </label>
            </div>
            <div class="delivery-box" id="deliveryBox">
                <label><i class="fas fa-map-marker-alt"></i> Delivery Address *</label>
                <textarea name="delivery_address" placeholder="House No., Street, Barangay, City, Province, ZIP"></textarea>
            </div>

            <button type="submit" name="request_doc" class="btn"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </form>
    </div>

    <?php
    $recent = $conn->prepare("
        SELECT dr.request_code, dr.status, dr.requested_at, dr.copies, dt.name AS doc_name, dt.fee
        FROM document_requests dr
        JOIN document_types dt ON dr.document_type_id = dt.id
        WHERE dr.user_id = ?
        ORDER BY dr.requested_at DESC LIMIT 5
    ");
    $recent->bind_param("i", $userId);
    $recent->execute();
    $recentReqs = $recent->get_result();
    if ($recentReqs->num_rows > 0):
    ?>
    <div class="card">
        <h3><i class="fas fa-history"></i> Recent Requests</h3>
        <table class="history-table">
        <thead><tr><th>Code</th><th>Document</th><th>Copies</th><th>Total</th><th>Date</th><th>Status</th></tr></thead>
        <tbody>
        <?php while($r=$recentReqs->fetch_assoc()):?>
        <tr>
            <td><strong><?= escape($r['request_code']) ?></strong></td>
            <td><?= escape($r['doc_name']) ?></td>
            <td><?= $r['copies'] ?></td>
            <td><i class="fas fa-tag"></i> ₱<?= number_format($r['fee']*$r['copies'],2) ?></td>
            <td><i class="fas fa-calendar"></i> <?= date('M d, Y',strtotime($r['requested_at'])) ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
        </tr>
        <?php endwhile;?>
        </tbody>
        </table>
    </div>
    <?php endif; $recent->close(); ?>

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

// Form interaction scripts
const cards = document.querySelectorAll('.doc-card');
cards.forEach(c => {
    c.addEventListener('click', () => {
        cards.forEach(x => x.classList.remove('selected'));
        c.classList.add('selected');
        c.querySelector('input').checked = true;
    });
});
const optPickup = document.getElementById('opt-pickup'), optDelivery = document.getElementById('opt-delivery'), deliveryBox = document.getElementById('deliveryBox');
optPickup.addEventListener('click', () => { optPickup.classList.add('selected'); optDelivery.classList.remove('selected'); deliveryBox.style.display = 'none'; });
optDelivery.addEventListener('click', () => { optDelivery.classList.add('selected'); optPickup.classList.remove('selected'); deliveryBox.style.display = 'block'; });
</script>

</body>
</html>
<?php $conn->close(); ?>