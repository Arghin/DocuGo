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

function e($v)  { return htmlspecialchars($v ?? ''); }
function fd($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function fdt($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }

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
    <title>My Requests — DocuGo</title>
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

        /* ─── Notification Bell ───────────────────────── */
        .notif-wrap { position: relative; }
        .notif-btn {
            position: relative;
            width: 40px; height: 40px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid #e5e7eb;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 1.1rem;
            transition: background 0.15s, border-color 0.2s, transform 0.15s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.07);
            outline: none; padding: 0;
        }
        .notif-btn:hover { background: #f0f4ff; border-color: #1a56db; transform: scale(1.06); }
        .notif-btn.has-unread { border-color: #1a56db; animation: bellShake 0.9s ease-in-out 0.4s; }
        @keyframes bellShake {
            0%,100%{transform:rotate(0)} 20%{transform:rotate(-14deg)}
            40%{transform:rotate(14deg)} 60%{transform:rotate(-9deg)} 80%{transform:rotate(9deg)}
        }
        .notif-badge {
            position: absolute; top: -4px; right: -4px;
            background: #dc2626; color: #fff;
            font-size: 0.6rem; font-weight: 800;
            min-width: 18px; height: 18px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #f0f4f8; padding: 0 3px; line-height: 1;
            transition: opacity 0.25s, transform 0.25s;
        }
        .notif-badge.hidden { opacity: 0; transform: scale(0); pointer-events: none; }

        /* Notification panel */
        .notif-panel {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 340px; background: #fff;
            border-radius: 14px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.13), 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb; z-index: 500; overflow: hidden;
            opacity: 0; transform: translateY(-6px) scale(0.98);
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .notif-panel.open { opacity: 1; transform: translateY(0) scale(1); pointer-events: all; }
        .notif-panel::before {
            content: ''; position: absolute; top: -7px; right: 13px;
            width: 13px; height: 13px; background: #fff;
            border-left: 1px solid #e5e7eb; border-top: 1px solid #e5e7eb;
            transform: rotate(45deg);
        }
        .notif-panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.9rem 1.1rem 0.8rem; border-bottom: 1px solid #f3f4f6; gap: 0.5rem;
        }
        .notif-panel-title { font-size: 0.92rem; font-weight: 800; color: #111827; display: flex; align-items: center; gap: 0.45rem; }
        .notif-count-pill { background: #1a56db; color: #fff; font-size: 0.62rem; font-weight: 800; padding: 2px 7px; border-radius: 10px; transition: opacity 0.2s; }
        .mark-all-btn { font-size: 0.75rem; color: #1a56db; background: none; border: none; cursor: pointer; font-weight: 600; font-family: inherit; padding: 4px 8px; border-radius: 6px; transition: background 0.15s; white-space: nowrap; }
        .mark-all-btn:hover { background: #eff6ff; }
        .notif-list { max-height: 300px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #e2e8f0 transparent; }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
        .notif-item { display: flex; align-items: flex-start; gap: 0.7rem; padding: 0.8rem 1.1rem; border-bottom: 1px solid #f9fafb; cursor: pointer; transition: background 0.12s; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #f8faff; }
        .notif-item.unread { background: #f0f7ff; }
        .notif-item.unread:hover { background: #e0edff; }
        .notif-item-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
        .type-ready    { background: #d1fae5; }
        .type-approved { background: #dbeafe; }
        .type-process  { background: #e0e7ff; }
        .type-cancel   { background: #fee2e2; }
        .type-info     { background: #f3f4f6; }
        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title { font-size: 0.815rem; font-weight: 600; color: #374151; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-item.unread .notif-item-title { font-weight: 800; color: #111827; }
        .notif-item-msg { font-size: 0.765rem; color: #6b7280; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .notif-item.unread .notif-item-msg { color: #374151; }
        .notif-item-time { font-size: 0.695rem; color: #9ca3af; margin-top: 3px; font-weight: 500; }
        .notif-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: #1a56db; flex-shrink: 0; margin-top: 7px; }
        .notif-empty { padding: 2rem 1rem; text-align: center; }
        .notif-empty .empty-emoji { font-size: 1.8rem; margin-bottom: 0.4rem; }
        .notif-empty p { font-size: 0.8rem; color: #9ca3af; font-weight: 500; }
        .notif-panel-footer { padding: 0.65rem 1.1rem; border-top: 1px solid #f3f4f6; text-align: center; background: #fafafa; }
        .notif-panel-footer a { font-size: 0.8rem; color: #1a56db; text-decoration: none; font-weight: 700; }
        .notif-panel-footer a:hover { text-decoration: underline; }

        /* ─── User Chip ───────────────────────────────── */
        .user-chip {
            display: flex; align-items: center; gap: 0.5rem;
            background: #fff; border: 1px solid #e5e7eb;
            border-radius: 20px;
            padding: 0.38rem 0.85rem 0.38rem 0.45rem;
            font-size: 0.82rem; color: #374151;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            white-space: nowrap;
        }
        .chip-avatar {
            width: 26px; height: 26px; border-radius: 50%;
            background: #1a56db; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 800; flex-shrink: 0;
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
        }
        .logout-btn-top:hover {
            background: #fecaca;
            transform: scale(1.08);
            color: #991b1b;
        }

        /* ─── New Request Button ──────────────────────── */
        .btn-new {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.48rem 1rem;
            background: #1a56db; color: #fff;
            border: none; border-radius: 8px;
            font-family: inherit; font-size: 0.835rem; font-weight: 600;
            text-decoration: none; cursor: pointer;
            transition: background 0.15s; white-space: nowrap;
        }
        .btn-new:hover { background: #1447c0; }

        /* ─── Tabs ────────────────────────────────────── */
        .tabs {
            display: flex; gap: 0.25rem; flex-wrap: wrap;
            background: #fff;
            padding: 0.4rem;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1rem;
        }
        .tab {
            padding: 0.38rem 0.8rem;
            border-radius: 7px;
            text-decoration: none;
            font-size: 0.79rem; font-weight: 500;
            color: #6b7280;
            transition: all 0.15s; white-space: nowrap;
        }
        .tab:hover { background: #f3f4f6; color: #111827; }
        .tab.active { background: #1a56db; color: #fff; font-weight: 700; }
        .tab .cnt {
            background: rgba(0,0,0,0.09); border-radius: 10px;
            padding: 1px 5px; font-size: 0.68rem; margin-left: 3px;
        }
        .tab.active .cnt { background: rgba(255,255,255,0.25); }

        /* ─── Filters Bar ─────────────────────────────── */
        .filters-bar {
            background: #fff;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1rem;
            display: flex; justify-content: space-between; align-items: center; gap: 1rem;
            flex-wrap: wrap;
        }
        .filters-count { font-size: 0.845rem; color: #6b7280; }
        .filters-count strong { color: #111827; }
        .search-form { display: flex; gap: 0.4rem; align-items: center; }
        .search-input {
            padding: 0.48rem 0.8rem;
            border: 1px solid #d1d5db; border-radius: 7px;
            font-size: 0.835rem; font-family: inherit; color: #111827;
            background: #f9fafb; width: 220px;
            transition: border-color 0.15s, background 0.15s;
        }
        .search-input:focus { outline: none; border-color: #1a56db; background: #fff; }
        .search-btn {
            padding: 0.48rem 0.85rem;
            background: #1a56db; color: #fff;
            border: none; border-radius: 7px;
            font-family: inherit; font-size: 0.835rem; font-weight: 600;
            cursor: pointer; transition: background 0.15s;
        }
        .search-btn:hover { background: #1447c0; }
        .clear-btn {
            padding: 0.48rem 0.75rem;
            background: #f1f5f9; color: #374151;
            border: 1px solid #e2e8f0; border-radius: 7px;
            font-size: 0.79rem; font-weight: 600;
            text-decoration: none; transition: background 0.15s;
        }
        .clear-btn:hover { background: #e2e8f0; }

        /* ─── Request Card ────────────────────────────── */
        .request-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1rem;
            overflow: hidden;
            border-left: 4px solid #e5e7eb;
            transition: box-shadow 0.2s, transform 0.15s;
        }
        .request-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.09); transform: translateY(-1px); }
        .request-card.pending    { border-left-color: #f59e0b; }
        .request-card.approved   { border-left-color: #3b82f6; }
        .request-card.processing { border-left-color: #6366f1; }
        .request-card.ready      { border-left-color: #f59e0b; border-left-width: 5px; }
        .request-card.paid       { border-left-color: #10b981; }
        .request-card.released   { border-left-color: #8b5cf6; }
        .request-card.cancelled  { border-left-color: #ef4444; opacity: 0.75; }

        /* Card header */
        .rc-header {
            padding: 1rem 1.1rem;
            display: flex; align-items: flex-start;
            justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
        }
        .rc-code {
            font-family: 'Courier New', monospace;
            font-size: 0.75rem; color: #9ca3af; margin-bottom: 3px;
            letter-spacing: 0.5px;
        }
        .rc-doctype { font-size: 0.95rem; font-weight: 700; color: #111827; }
        .rc-meta { font-size: 0.79rem; color: #9ca3af; margin-top: 3px; }
        .rc-meta span { margin-right: 0.5rem; }

        .rc-header-right {
            display: flex; flex-direction: column;
            align-items: flex-end; gap: 5px; flex-shrink: 0;
        }
        .rc-fee { font-size: 1rem; font-weight: 800; color: #059669; }
        .rc-date { font-size: 0.75rem; color: #9ca3af; }

        /* Status badge */
        .status-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 10px;
            font-size: 0.72rem; font-weight: 700; white-space: nowrap;
        }
        .status-badge::before { content:''; width:6px; height:6px; border-radius:50%; }
        .s-pending    { background:#fef3c7; color:#92400e; } .s-pending::before    { background:#d97706; }
        .s-approved   { background:#dbeafe; color:#1e40af; } .s-approved::before   { background:#2563eb; }
        .s-processing { background:#e0e7ff; color:#3730a3; } .s-processing::before { background:#4f46e5; }
        .s-ready      { background:#fef3c7; color:#92400e; } .s-ready::before      { background:#d97706; }
        .s-paid       { background:#d1fae5; color:#065f46; } .s-paid::before       { background:#059669; }
        .s-released   { background:#ede9fe; color:#4c1d95; } .s-released::before   { background:#7c3aed; }
        .s-cancelled  { background:#fee2e2; color:#991b1b; } .s-cancelled::before  { background:#dc2626; }

        /* ─── Status Tracker ──────────────────────────── */
        .tracker-wrap {
            padding: 0.9rem 1.1rem 0.75rem;
            border-top: 1px solid #f3f4f6;
        }
        .tracker { display: flex; align-items: center; position: relative; }
        .tracker-line-bg {
            position: absolute; left: 0; right: 0; top: 50%;
            transform: translateY(-50%); height: 4px;
            background: #e5e7eb; z-index: 0; border-radius: 4px;
        }
        .tracker-line-fill {
            position: absolute; left: 0; top: 50%;
            transform: translateY(-50%); height: 4px;
            background: linear-gradient(90deg, #1a56db, #3b82f6);
            z-index: 1; border-radius: 4px; transition: width 0.5s ease;
        }
        .tracker-steps {
            display: flex; justify-content: space-between;
            width: 100%; position: relative; z-index: 2;
        }
        .tracker-step { display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .step-dot {
            width: 28px; height: 28px; border-radius: 50%;
            background: #fff; border: 3px solid #e5e7eb;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.68rem; font-weight: 700; color: #9ca3af;
            flex-shrink: 0; transition: all 0.3s;
        }
        .step-dot.done    { background: #1a56db; border-color: #1a56db; color: #fff; }
        .step-dot.current {
            background: #fff; border-color: #1a56db; color: #1a56db;
            box-shadow: 0 0 0 4px rgba(26,86,219,0.12);
        }
        .step-label {
            font-size: 0.67rem; color: #9ca3af; text-align: center;
            font-weight: 500; white-space: nowrap;
        }
        .step-label.done    { color: #1a56db; font-weight: 600; }
        .step-label.current { color: #111827; font-weight: 800; }

        /* ─── Alert Banners ───────────────────────────── */
        .ready-alert {
            background: #fffbeb; border-top: 1px solid #fcd34d;
            padding: 0.8rem 1.1rem;
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
        }
        .ready-alert-text { font-size: 0.845rem; color: #92400e; font-weight: 500; }
        .ready-alert-text strong { display: block; font-size: 0.875rem; margin-bottom: 1px; }

        .released-info {
            background: #faf5ff; border-top: 1px solid #e9d5ff;
            padding: 0.65rem 1.1rem;
            font-size: 0.845rem; color: #4c1d95;
        }
        .cancelled-banner {
            background: #fef2f2; border-top: 1px solid #fecaca;
            padding: 0.65rem 1.1rem;
            font-size: 0.845rem; color: #991b1b; font-weight: 600;
        }

        /* ─── Card Footer ─────────────────────────────── */
        .rc-footer {
            padding: 0.6rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            display: flex; align-items: center;
            justify-content: space-between;
            flex-wrap: wrap; gap: 0.5rem;
            background: #fafafa;
        }
        .rc-footer-left { font-size: 0.79rem; color: #9ca3af; }
        .rc-footer-right { display: flex; gap: 0.4rem; align-items: center; }

        /* Action buttons */
        .btn-stub {
            padding: 4px 10px; border-radius: 6px;
            font-size: 0.77rem; font-weight: 600;
            background: #ede9fe; color: #4c1d95;
            text-decoration: none; border: none; cursor: pointer;
            font-family: inherit; transition: background 0.15s;
        }
        .btn-stub:hover { background: #ddd6fe; }
        .btn-cancel {
            padding: 4px 10px; border-radius: 6px;
            font-size: 0.77rem; font-weight: 600;
            background: #fee2e2; color: #991b1b;
            border: none; cursor: pointer; font-family: inherit;
            transition: background 0.15s;
        }
        .btn-cancel:hover { background: #fecaca; }

        /* ─── Empty State ─────────────────────────────── */
        .empty-state {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            text-align: center;
            padding: 4rem 2rem;
        }
        .empty-state .es-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .empty-state h3 { font-size: 1rem; font-weight: 700; color: #374151; margin-bottom: 0.35rem; }
        .empty-state p  { font-size: 0.875rem; color: #9ca3af; }

        /* ─── Pagination ──────────────────────────────── */
        .pagination-wrap {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 1rem; font-size: 0.82rem; color: #6b7280;
            flex-wrap: wrap; gap: 0.5rem;
        }
        .pagination { display: flex; gap: 0.3rem; }
        .pagination a, .pagination span {
            padding: 0.45rem 0.75rem; border-radius: 6px;
            text-decoration: none; font-size: 0.82rem; font-weight: 600;
        }
        .pagination a { background: #fff; color: #374151; border: 1px solid #e5e7eb; transition: background 0.15s; }
        .pagination a:hover { background: #f3f4f6; }
        .pagination .current { background: #1a56db; color: #fff; border: 1px solid #1a56db; }

        /* ─── Responsive ──────────────────────────────── */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .step-label { display: none; }
            .search-input { width: 150px; }
        }
        @media (max-width: 500px) {
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
        <small><?= $isAlumni ? 'Alumni Portal' : 'Student Portal' ?></small>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="dashboard.php"    class="menu-item"><span class="icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="icon">📄</span> Request Document</a>
        <a href="my_requests.php"  class="menu-item active"><span class="icon">📋</span> My Requests</a>

        <?php if ($isAlumni): ?>
        <div class="menu-label">Alumni</div>
        <a href="graduate_tracer.php"      class="menu-item"><span class="icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item"><span class="icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php"   class="menu-item"><span class="icon">🎓</span> Alumni Documents</a>
        <?php endif; ?>

        <div class="menu-label">Account</div>
        <a href="profile.php" class="menu-item"><span class="icon">👤</span> Profile</a>
    </nav>
        <div class="sidebar-footer">
            <a href="../logout.php">🚪 Logout</a>
        </div>
    </nav>
</aside>

<!-- ─── Main ────────────────────────────────────────────── -->
<main class="main">

    <!-- Topbar -->
    <div class="topbar">
        <h1>📋 My Requests</h1>
        <div class="topbar-right">
            <a href="request_form.php" class="btn-new">➕ New Request</a>

            <!-- Logout button -->
            <a href="../logout.php" class="logout-btn-top" title="Logout">🚪</a>

            <!-- 🔔 Notification Bell -->
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-btn <?= $unreadCount > 0 ? 'has-unread' : '' ?>"
                        id="notifBtn" onclick="togglePanel(event)" title="Notifications">
                    🔔
                    <span class="notif-badge <?= $unreadCount === 0 ? 'hidden' : '' ?>" id="notifBadge">
                        <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                    </span>
                </button>
                <div class="notif-panel" id="notifPanel">
                    <div class="notif-panel-header">
                        <div class="notif-panel-title">
                            🔔 Notifications
                            <span class="notif-count-pill" id="countPill"
                                  style="<?= $unreadCount===0 ? 'opacity:0' : '' ?>">
                                <?= $unreadCount ?> new
                            </span>
                        </div>
                        <button class="mark-all-btn" onclick="markAllRead()">✓ Mark all read</button>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="notif-empty">
                            <div class="empty-emoji">🔔</div>
                            <p>Loading notifications…</p>
                        </div>
                    </div>
                    <div class="notif-panel-footer">
                        <a href="notifications.php">View all notifications →</a>
                    </div>
                </div>
            </div>

            <div class="user-chip">
                <div class="chip-avatar"><?= $initial ?></div>
                <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong>
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
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="text" name="q" class="search-input"
                   placeholder="Search code or document…"
                   value="<?= e($search) ?>">
            <button type="submit" class="search-btn">🔍 Search</button>
            <?php if ($search): ?>
                <a href="?status=<?= e($status) ?>" class="clear-btn">✕ Clear</a>
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
        <div class="request-card <?= e($r['status']) ?>">

            <!-- Header -->
            <div class="rc-header">
                <div>
                    <div class="rc-code"><?= e($r['request_code']) ?></div>
                    <div class="rc-doctype"><?= e($r['doc_type']) ?></div>
                    <div class="rc-meta">
                        <span><?= $r['copies'] ?> cop<?= $r['copies'] > 1 ? 'ies' : 'y' ?></span>
                        <span>·</span>
                        <span><?= ucfirst(e($r['release_mode'])) ?></span>
                        <?php if ($r['preferred_release_date']): ?>
                            <span>·</span>
                            <span>Preferred: <?= fd($r['preferred_release_date']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rc-header-right">
                    <div class="rc-fee">₱<?= number_format($r['total_fee'], 2) ?></div>
                    <div class="rc-date">Submitted <?= fd($r['requested_at']) ?></div>
                    <span class="status-badge s-<?= e($r['status']) ?>">
                        <?= ucfirst(e($r['status'])) ?>
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
                            <div class="step-dot <?= $cls ?>"><?= $done ? '✓' : ($i + 1) ?></div>
                            <div class="step-label <?= $cls ?>"><?= $step['label'] ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="cancelled-banner">✕ This request has been cancelled.</div>
            <?php endif; ?>

            <!-- Ready alert -->
            <?php if ($r['status'] === 'ready'): ?>
            <div class="ready-alert">
                <div class="ready-alert-text">
                    <strong>⚠️ Action Required — Payment Needed</strong>
                    Your document is ready. Go to the Registrar's Office, present
                    <strong><?= e($r['request_code']) ?></strong>,
                    and pay <strong>₱<?= number_format($r['total_fee'], 2) ?></strong> to claim it.
                </div>
                <?php if ($r['stub_code']): ?>
                    <a href="claim_stub.php?code=<?= e($r['stub_code']) ?>"
                       target="_blank" class="btn-stub">🖨 View Claim Stub</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Released info -->
            <?php if ($r['status'] === 'released'): ?>
            <div class="released-info">
                ✅ Document released.
                <?php if ($r['official_receipt_number']): ?>
                    &nbsp;·&nbsp; OR#: <strong><?= e($r['official_receipt_number']) ?></strong>
                <?php endif; ?>
                <?php if ($r['payment_date']): ?>
                    &nbsp;·&nbsp; Paid on <?= fdt($r['payment_date']) ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="rc-footer">
                <div class="rc-footer-left">
                    <?php if ($r['stub_code']): ?>
                        Stub: <code style="font-size:0.77rem;color:#6b7280;"><?= e($r['stub_code']) ?></code>
                    <?php else: ?>
                        Payment:
                        <span class="status-badge s-<?= $r['payment_status'] === 'paid' ? 'paid' : 'cancelled' ?>"
                              style="font-size:0.68rem;">
                            <?= ucfirst($r['payment_status'] ?? 'unpaid') ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="rc-footer-right">
                    <?php if ($r['stub_code']): ?>
                        <a href="claim_stub.php?code=<?= e($r['stub_code']) ?>"
                           target="_blank" class="btn-stub">🖨 Claim Stub</a>
                    <?php endif; ?>
                    <?php if (!in_array($r['status'], ['released','cancelled'])): ?>
                        <form method="POST" action="cancel_request.php" style="display:inline;">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <button class="btn-cancel"
                                    onclick="return confirm('Cancel this request?\nThis cannot be undone.')">
                                ✕ Cancel
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="empty-state">
            <div class="es-icon">📭</div>
            <h3>No requests found</h3>
            <p>
                <?= $search
                    ? 'No results for "' . e($search) . '".'
                    : "You haven't submitted any document requests yet." ?>
            </p>
            <?php if (!$search): ?>
                <a href="request_form.php" class="btn-new"
                   style="display:inline-flex;margin-top:1.2rem;">
                    ➕ Submit Your First Request
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
                <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$page-1]) ?>">‹ Prev</a>
            <?php endif; ?>
            <?php
            $range = 2;
            for ($i = max(1,$page-$range); $i <= min($totalPages,$page+$range); $i++):
            ?>
                <?php if ($i === $page): ?>
                    <span class="current"><?= $i ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$i]) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$page+1]) ?>">Next ›</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<!-- ─── Notification JS ──────────────────────────────────── -->
<script>
let panelOpen   = false;
let unreadCount = <?= $unreadCount ?>;
const PAGE_URL  = window.location.pathname;

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
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && panelOpen) {
        document.getElementById('notifPanel').classList.remove('open');
        panelOpen = false;
    }
});
function loadNotifList() {
    fetch('dashboard.php?ajax_notif_list=1')
        .then(r => r.json()).then(renderList).catch(() => {});
}
function markRead(id, el) {
    if (!el.classList.contains('unread')) return;
    fetch(PAGE_URL, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_mark_read=1&notif_id='+id })
        .then(r=>r.json()).then(d=>{
            if (!d.ok) return;
            el.classList.remove('unread');
            const dot = document.getElementById('dot-'+id); if(dot) dot.remove();
            unreadCount = Math.max(0, unreadCount-1); syncBadge();
        });
}
function markAllRead() {
    fetch(PAGE_URL, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'ajax_mark_all_read=1' })
        .then(r=>r.json()).then(d=>{
            if (!d.ok) return;
            document.querySelectorAll('.notif-item.unread').forEach(el=>el.classList.remove('unread'));
            document.querySelectorAll('.notif-unread-dot').forEach(el=>el.remove());
            unreadCount = 0; syncBadge();
        });
}
function syncBadge() {
    const badge=document.getElementById('notifBadge'), pill=document.getElementById('countPill'), btn=document.getElementById('notifBtn');
    if (unreadCount > 0) {
        badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
        badge.classList.remove('hidden'); pill.textContent = unreadCount+' new'; pill.style.opacity='1'; btn.classList.add('has-unread');
    } else {
        badge.classList.add('hidden'); pill.style.opacity='0'; btn.classList.remove('has-unread');
    }
}
function renderList(items) {
    const list = document.getElementById('notifList');
    if (!items.length) { list.innerHTML=`<div class="notif-empty"><div class="empty-emoji">🎉</div><p>All caught up!</p></div>`; return; }
    const iconMap=[['ready','📦','type-ready','Document Ready'],['approv','✅','type-approved','Request Approved'],['process','⚙️','type-process','Being Processed'],['cancel','❌','type-cancel','Request Cancelled'],['releas','📬','type-ready','Document Released'],['paid','💳','type-approved','Payment Confirmed'],['welcom','👋','type-info','Welcome!']];
    list.innerHTML = items.map(n => {
        const lm=n.message.toLowerCase(); let [emoji,cls,title]=['🔔','type-info','Notification'];
        for(const [k,e,c,t] of iconMap){ if(lm.includes(k)){emoji=e;cls=c;title=t;break;} }
        const isNew=n.is_read==0;
        return `<div class="notif-item ${isNew?'unread':''}" id="ni-${n.id}" onclick="markRead(${n.id},this)">
            <div class="notif-item-icon ${cls}">${emoji}</div>
            <div class="notif-item-body">
                <div class="notif-item-title">${esc(title)}</div>
                <div class="notif-item-msg">${esc(n.message)}</div>
                <div class="notif-item-time">🕐 ${jsAgo(n.created_at)}</div>
            </div>
            ${isNew?`<div class="notif-unread-dot" id="dot-${n.id}"></div>`:''}
        </div>`;
    }).join('');
}
function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function jsAgo(ds){ const d=Math.floor((Date.now()-new Date(ds).getTime())/1000); if(d<60)return 'just now'; if(d<3600)return Math.floor(d/60)+' min ago'; if(d<86400)return Math.floor(d/3600)+' hr ago'; if(d<604800)return Math.floor(d/86400)+' day ago'; return new Date(ds).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
setInterval(()=>{ fetch('dashboard.php?ajax_unread_count=1').then(r=>r.json()).then(d=>{ if(typeof d.count==='number'&&d.count!==unreadCount){unreadCount=d.count;syncBadge();} }).catch(()=>{}); }, 3000);
</script>

</body>
</html>