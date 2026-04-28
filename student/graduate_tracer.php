<?php
require_once '../includes/config.php';
requireLogin();

$conn   = getConnection();
$userId = $_SESSION['user_id'];
$role   = $_SESSION['user_role'] ?? 'student';

/* ── Access control ─────────────────────────────────────── */
if ($role !== 'alumni') {
    header('Location: dashboard.php');
    exit();
}

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
$stmt = $conn->prepare("SELECT first_name, last_name, student_id, course, year_graduated FROM users WHERE id=?");
$stmt->bind_param("i", $userId); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ── Unread notifications ───────────────────────────────── */
$nStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
$nStmt->bind_param("i", $userId); $nStmt->execute();
$unreadCount = (int)$nStmt->get_result()->fetch_assoc()['c'];
$nStmt->close();

/* ── Load existing tracer record ────────────────────────── */
$stmt = $conn->prepare("SELECT * FROM graduate_tracer WHERE user_id=? ORDER BY date_submitted DESC LIMIT 1");
$stmt->bind_param("i", $userId); $stmt->execute();
$tracer = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ── Handle form submission ─────────────────────────────── */
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['ajax_mark_read'])
    && !isset($_POST['ajax_mark_all_read'])) {

    $employment    = $_POST['employment_status']       ?? '';
    $employer      = trim($_POST['employer_name']      ?? '');
    $jobTitle      = trim($_POST['job_title']          ?? '');
    $sector        = $_POST['employment_sector']       ?? '';
    $relevance     = $_POST['degree_relevance']        ?? '';
    $further       = isset($_POST['further_studies'])  ? 1 : 0;
    $furtherSchool = trim($_POST['school_further_studies'] ?? '');
    $license       = trim($_POST['professional_license']   ?? '');

    $allowedEmp = ['employed','unemployed','self_employed','further_studies','not_looking'];
    $allowedSec = ['','government','private','ngo','self','other'];
    $allowedRel = ['','very_relevant','relevant','somewhat_relevant','not_relevant'];

    if (!in_array($employment, $allowedEmp)) {
        $error = "Please select a valid employment status.";
    } elseif (!in_array($sector, $allowedSec)) {
        $error = "Please select a valid employment sector.";
    } elseif (!in_array($relevance, $allowedRel)) {
        $error = "Please select a valid degree relevance option.";
    } else {
        $sectorVal    = $sector    === '' ? null : $sector;
        $relevanceVal = $relevance === '' ? null : $relevance;
        $employerVal  = $employer  === '' ? null : $employer;
        $jobVal       = $jobTitle  === '' ? null : $jobTitle;
        $schoolVal    = $furtherSchool === '' ? null : $furtherSchool;
        $licenseVal   = $license   === '' ? null : $license;

        if ($tracer) {
            $stmt = $conn->prepare("UPDATE graduate_tracer SET employment_status=?,employer_name=?,job_title=?,employment_sector=?,degree_relevance=?,further_studies=?,school_further_studies=?,professional_license=?,date_submitted=NOW() WHERE user_id=?");
            $stmt->bind_param("sssssissi", $employment,$employerVal,$jobVal,$sectorVal,$relevanceVal,$further,$schoolVal,$licenseVal,$userId);
        } else {
            $stmt = $conn->prepare("INSERT INTO graduate_tracer (user_id,employment_status,employer_name,job_title,employment_sector,degree_relevance,further_studies,school_further_studies,professional_license,date_submitted) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->bind_param("isssssiss", $userId,$employment,$employerVal,$jobVal,$sectorVal,$relevanceVal,$further,$schoolVal,$licenseVal);
        }

        if ($stmt->execute()) {
            $success = $tracer ? "Your tracer survey has been updated. Thank you!" : "Tracer survey submitted successfully. Thank you!";
            $stmt->close();
            $stmt = $conn->prepare("SELECT * FROM graduate_tracer WHERE user_id=? ORDER BY date_submitted DESC LIMIT 1");
            $stmt->bind_param("i", $userId); $stmt->execute();
            $tracer = $stmt->get_result()->fetch_assoc();
        } else {
            $error = "Failed to save your submission. Please try again.";
        }
        $stmt->close();
    }
}

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }
function v($arr, $k) { return htmlspecialchars($arr[$k] ?? ''); }

$val = [
    'employment_status'      => $tracer['employment_status']      ?? '',
    'employer_name'          => $tracer['employer_name']          ?? '',
    'job_title'              => $tracer['job_title']              ?? '',
    'employment_sector'      => $tracer['employment_sector']      ?? '',
    'degree_relevance'       => $tracer['degree_relevance']       ?? '',
    'further_studies'        => (int)($tracer['further_studies']  ?? 0),
    'school_further_studies' => $tracer['school_further_studies'] ?? '',
    'professional_license'   => $tracer['professional_license']   ?? '',
];

$initial = strtoupper(substr($user['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graduate Tracer — DocuGo</title>
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
            width: 220px; background: #1a56db; color: #fff;
            min-height: 100vh; flex-shrink: 0;
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; height: 100%; z-index: 100;
        }
        .sidebar-brand {
            padding: 1.4rem 1.2rem; font-size: 1.5rem; font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            letter-spacing: -0.5px; line-height: 1.2;
        }
        .sidebar-brand small {
            display: block; font-size: 0.68rem; font-weight: 400;
            opacity: 0.7; margin-top: 3px; text-transform: uppercase; letter-spacing: 0.06em;
        }
        .sidebar-menu { padding: 1rem 0; flex: 1; overflow-y: auto; }
        .menu-label {
            font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.1em; opacity: 0.5; padding: 0.8rem 1.2rem 0.3rem;
        }
        .menu-item {
            display: flex; align-items: center; gap: 0.65rem;
            padding: 0.6rem 1.2rem;
            color: rgba(255,255,255,0.82); text-decoration: none;
            font-size: 0.855rem; font-weight: 500;
            transition: background 0.15s, color 0.15s;
            border-left: 3px solid transparent;
        }
        .menu-item:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .menu-item.active { background: rgba(255,255,255,0.15); color: #fff; border-left-color: #fff; font-weight: 600; }
        .menu-item .icon { font-size: 1rem; width: 20px; text-align: center; flex-shrink: 0; }
        .sidebar-footer {
            padding: 1rem 1.2rem; border-top: 1px solid rgba(255,255,255,0.15); font-size: 0.8rem;
        }
        .sidebar-footer a {
            color: rgba(255,255,255,0.82); text-decoration: none;
            display: flex; align-items: center; gap: 0.5rem; transition: color 0.15s;
        }
        .sidebar-footer a:hover { color: #fff; }

        /* ─── Main ────────────────────────────────────── */
        .main { margin-left: 220px; flex: 1; padding: 2rem; min-width: 0; }

        /* ─── Topbar ──────────────────────────────────── */
        .topbar {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1.6rem; gap: 1rem;
        }
        .topbar h1 { font-size: 1.35rem; font-weight: 800; color: #111827; }
        .topbar-right { display: flex; align-items: center; gap: 0.65rem; flex-shrink: 0; }

        /* ─── Notification Bell ───────────────────────── */
        .notif-wrap { position: relative; }
        .notif-btn {
            position: relative; width: 40px; height: 40px; border-radius: 50%;
            background: #fff; border: 2px solid #e5e7eb;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 1.1rem;
            transition: background 0.15s, border-color 0.2s, transform 0.15s;
            box-shadow: 0 2px 6px rgba(0,0,0,0.07); outline: none; padding: 0;
        }
        .notif-btn:hover { background: #f0f4ff; border-color: #1a56db; transform: scale(1.06); }
        .notif-btn.has-unread { border-color: #1a56db; animation: bellShake 0.9s ease-in-out 0.4s; }
        @keyframes bellShake {
            0%,100%{transform:rotate(0)} 20%{transform:rotate(-14deg)}
            40%{transform:rotate(14deg)} 60%{transform:rotate(-9deg)} 80%{transform:rotate(9deg)}
        }
        .notif-badge {
            position: absolute; top: -4px; right: -4px;
            background: #dc2626; color: #fff; font-size: 0.6rem; font-weight: 800;
            min-width: 18px; height: 18px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #f0f4f8; padding: 0 3px; line-height: 1;
            transition: opacity 0.25s, transform 0.25s;
        }
        .notif-badge.hidden { opacity: 0; transform: scale(0); pointer-events: none; }
        .notif-panel {
            position: absolute; top: calc(100% + 10px); right: 0; width: 340px;
            background: #fff; border-radius: 14px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.13), 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb; z-index: 500; overflow: hidden;
            opacity: 0; transform: translateY(-6px) scale(0.98);
            pointer-events: none; transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .notif-panel.open { opacity: 1; transform: translateY(0) scale(1); pointer-events: all; }
        .notif-panel::before {
            content: ''; position: absolute; top: -7px; right: 13px;
            width: 13px; height: 13px; background: #fff;
            border-left: 1px solid #e5e7eb; border-top: 1px solid #e5e7eb; transform: rotate(45deg);
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
        .type-ready{background:#d1fae5} .type-approved{background:#dbeafe} .type-process{background:#e0e7ff} .type-cancel{background:#fee2e2} .type-info{background:#f3f4f6}
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
            background: #fff; border: 1px solid #e5e7eb; border-radius: 20px;
            padding: 0.38rem 0.85rem 0.38rem 0.45rem;
            font-size: 0.82rem; color: #374151;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06); white-space: nowrap;
        }
        .chip-avatar {
            width: 26px; height: 26px; border-radius: 50%;
            background: #1a56db; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 800; flex-shrink: 0;
        }
        .user-chip strong { color: #111827; font-weight: 700; }

        /* ─── Alerts ──────────────────────────────────── */
        .alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.2rem; font-size: 0.875rem; font-weight: 500; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #059669; }
        .alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

        /* ─── Intro Banner ────────────────────────────── */
        .intro-banner {
            background: linear-gradient(135deg, #1a56db 0%, #1447c0 100%);
            color: #fff; padding: 1.4rem 1.6rem;
            border-radius: 12px; margin-bottom: 1.2rem;
            box-shadow: 0 4px 14px rgba(26,86,219,0.22);
            display: flex; align-items: flex-start; gap: 1rem; flex-wrap: wrap;
        }
        .intro-icon { font-size: 2.2rem; flex-shrink: 0; }
        .intro-text h2 { font-size: 1.1rem; font-weight: 800; margin-bottom: 0.3rem; }
        .intro-text p  { font-size: 0.82rem; opacity: 0.88; line-height: 1.6; }
        .intro-pill {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: 0.65rem;
            background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3);
            padding: 3px 10px; border-radius: 20px;
            font-size: 0.72rem; font-weight: 700;
        }

        /* ─── Progress Bar ────────────────────────────── */
        .progress-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            padding: 1rem 1.2rem; margin-bottom: 1.1rem;
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        }
        .progress-label { font-size: 0.82rem; color: #6b7280; font-weight: 600; white-space: nowrap; }
        .progress-bar-wrap { flex: 1; min-width: 120px; background: #f3f4f6; border-radius: 10px; height: 8px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: linear-gradient(90deg, #1a56db, #3b82f6); border-radius: 10px; transition: width 0.5s ease; }
        .progress-pct { font-size: 0.82rem; font-weight: 800; color: #1a56db; white-space: nowrap; }

        /* ─── Section Card ────────────────────────────── */
        .section-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1.1rem; overflow: hidden;
        }
        .section-header {
            padding: 0.85rem 1.2rem; border-bottom: 1px solid #f3f4f6;
            display: flex; align-items: center; gap: 0.65rem;
        }
        .section-header h3 { font-size: 0.95rem; font-weight: 700; color: #111827; }
        .section-icon { font-size: 1.1rem; }
        .section-body { padding: 1.2rem; }

        /* ─── Form ────────────────────────────────────── */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .form-label {
            font-size: 0.78rem; font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .form-input, .form-select {
            padding: 0.55rem 0.8rem; border: 1px solid #d1d5db;
            border-radius: 7px; font-size: 0.875rem; color: #111827;
            background: #fff; font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s; width: 100%;
        }
        .form-input:focus, .form-select:focus {
            outline: none; border-color: #1a56db; box-shadow: 0 0 0 3px rgba(26,86,219,0.1);
        }
        .form-input[readonly] { background: #f9fafb; color: #6b7280; cursor: not-allowed; }
        .form-hint { font-size: 0.73rem; color: #9ca3af; }
        .form-hint a { color: #1a56db; font-weight: 600; text-decoration: none; }
        .form-hint a:hover { text-decoration: underline; }

        /* ─── Employment Status Cards ─────────────────── */
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.7rem;
        }
        .status-opt {
            border: 2px solid #e5e7eb; border-radius: 10px;
            padding: 1rem 0.75rem; cursor: pointer;
            transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
            text-align: center; position: relative;
        }
        .status-opt:hover { border-color: #93c5fd; background: #f0f9ff; }
        .status-opt.selected {
            border-color: #1a56db; background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(26,86,219,0.08);
        }
        .status-opt.selected::after {
            content: '✓'; position: absolute; top: 7px; right: 9px;
            font-size: 0.7rem; font-weight: 800; color: #1a56db;
        }
        .status-opt input { display: none; }
        .status-opt .s-emoji { font-size: 1.7rem; margin-bottom: 0.4rem; }
        .status-opt .s-title { font-size: 0.835rem; font-weight: 700; color: #111827; }
        .status-opt.selected .s-title { color: #1447c0; }

        /* ─── Checkbox Row ────────────────────────────── */
        .check-row {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.75rem 0.9rem;
            background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;
            cursor: pointer;
        }
        .check-row input[type="checkbox"] {
            width: 16px; height: 16px; accent-color: #1a56db;
            cursor: pointer; flex-shrink: 0; margin: 0;
        }
        .check-row label {
            font-size: 0.855rem; color: #374151;
            font-weight: 500; cursor: pointer; margin: 0;
        }

        /* Conditional section */
        .cond { display: none; margin-top: 1rem; }
        .cond.active { display: block; }

        /* ─── Submit Button ───────────────────────────── */
        .btn-submit {
            width: 100%; padding: 0.85rem;
            background: #1a56db; color: #fff;
            border: none; border-radius: 8px;
            font-family: inherit; font-size: 0.95rem; font-weight: 700;
            cursor: pointer; transition: background 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
        }
        .btn-submit:hover { background: #1447c0; }

        /* ─── Responsive ──────────────────────────────── */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
        }
        @media (max-width: 500px) {
            .notif-panel { width: 280px; right: -50px; }
            .notif-panel::before { right: 64px; }
            .status-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<!-- ─── Sidebar ─────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        DocuGo
        <small>Alumni Portal</small>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="dashboard.php"    class="menu-item"><span class="icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="icon">📄</span> Request Document</a>
        <a href="my_requests.php"  class="menu-item"><span class="icon">📋</span> My Requests</a>
        <div class="menu-label">Alumni</div>
        <a href="graduate_tracer.php"      class="menu-item active"><span class="icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item"><span class="icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php"   class="menu-item"><span class="icon">🎓</span> Alumni Documents</a>
        <div class="menu-label">Account</div>
        <a href="profile.php" class="menu-item"><span class="icon">👤</span> Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- ─── Main ────────────────────────────────────────────── -->
<main class="main">

    <!-- Topbar -->
    <div class="topbar">
        <h1>📊 Graduate Tracer Survey</h1>
        <div class="topbar-right">

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

    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= e($error) ?></div>
    <?php endif; ?>

    <!-- Intro Banner -->
    <div class="intro-banner">
        <div class="intro-icon">🎓</div>
        <div class="intro-text">
            <h2>Help us track our alumni</h2>
            <p>This short survey helps the school understand what graduates are doing after graduation.
               Your responses are confidential and help us improve our programs.</p>
            <?php if ($tracer): ?>
                <div class="intro-pill">
                    ✅ Last submitted: <?= date('M d, Y g:i A', strtotime($tracer['date_submitted'])) ?>
                </div>
            <?php else: ?>
                <div class="intro-pill">📝 Not yet submitted</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Progress indicator -->
    <?php
    $fields = ['employment_status','employer_name','job_title','employment_sector','degree_relevance','professional_license'];
    $filled = 0;
    foreach ($fields as $f) { if (!empty($val[$f])) $filled++; }
    $pct = $tracer ? round(($filled / count($fields)) * 100) : 0;
    ?>
    <div class="progress-card">
        <div class="progress-label">Survey Completion</div>
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill" id="progressFill" style="width:<?= $pct ?>%;"></div>
        </div>
        <div class="progress-pct" id="progressPct"><?= $pct ?>%</div>
    </div>

    <!-- ══ FORM ══════════════════════════════════════════════ -->
    <form method="POST" id="tracerForm">

        <!-- Graduate Information (read-only) -->
        <div class="section-card">
            <div class="section-header">
                <span class="section-icon">🎓</span>
                <h3>Graduate Information</h3>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-input" readonly
                               value="<?= e($user['first_name'] . ' ' . $user['last_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Student ID</label>
                        <input type="text" class="form-input" readonly
                               value="<?= e($user['student_id'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Course / Program</label>
                        <input type="text" class="form-input" readonly
                               value="<?= e($user['course'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Year Graduated</label>
                        <input type="text" class="form-input" readonly
                               value="<?= e($user['year_graduated'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-hint" style="margin-top:0.6rem;">
                    To update your graduate info, go to <a href="profile.php">Profile</a>.
                </div>
            </div>
        </div>

        <!-- Employment Status -->
        <div class="section-card">
            <div class="section-header">
                <span class="section-icon">💼</span>
                <h3>Current Employment Status</h3>
            </div>
            <div class="section-body">
                <div class="status-grid" id="statusGrid">
                    <?php
                    $statuses = [
                        'employed'        => ['⚙️', 'Employed'],
                        'self_employed'   => ['🏪', 'Self-Employed'],
                        'unemployed'      => ['🔎', 'Unemployed'],
                        'further_studies' => ['📚', 'Further Studies'],
                        'not_looking'     => ['⏸️', 'Not Looking'],
                    ];
                    foreach ($statuses as $key => [$emoji, $label]):
                        $sel = $val['employment_status'] === $key ? 'selected' : '';
                    ?>
                    <label class="status-opt <?= $sel ?>" data-value="<?= $key ?>">
                        <input type="radio" name="employment_status"
                               value="<?= $key ?>" <?= $sel ? 'checked' : '' ?> required>
                        <div class="s-emoji"><?= $emoji ?></div>
                        <div class="s-title"><?= $label ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Employment Details (conditional) -->
        <div class="section-card cond" id="section-employment">
            <div class="section-header">
                <span class="section-icon">🏢</span>
                <h3>Employment Details</h3>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="employer_name">Employer / Company Name</label>
                        <input type="text" id="employer_name" name="employer_name"
                               class="form-input"
                               value="<?= v($val, 'employer_name') ?>"
                               placeholder="e.g. ABC Corporation">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="job_title">Job Title / Position</label>
                        <input type="text" id="job_title" name="job_title"
                               class="form-input"
                               value="<?= v($val, 'job_title') ?>"
                               placeholder="e.g. Software Engineer">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="employment_sector">Employment Sector</label>
                        <select id="employment_sector" name="employment_sector" class="form-select">
                            <option value="">— Select Sector —</option>
                            <?php foreach (['government'=>'Government','private'=>'Private Sector','ngo'=>'NGO / Non-Profit','self'=>'Self-Employed','other'=>'Other'] as $k=>$lbl): ?>
                                <option value="<?= $k ?>" <?= $val['employment_sector']===$k ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="degree_relevance">Is your job related to your course?</label>
                        <select id="degree_relevance" name="degree_relevance" class="form-select">
                            <option value="">— Select Relevance —</option>
                            <?php foreach (['very_relevant'=>'Very Relevant','relevant'=>'Relevant','somewhat_relevant'=>'Somewhat Relevant','not_relevant'=>'Not Relevant'] as $k=>$lbl): ?>
                                <option value="<?= $k ?>" <?= $val['degree_relevance']===$k ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Further Studies -->
        <div class="section-card">
            <div class="section-header">
                <span class="section-icon">📚</span>
                <h3>Further Studies</h3>
            </div>
            <div class="section-body">
                <div class="check-row" onclick="document.getElementById('chkFurther').click()">
                    <input type="checkbox" name="further_studies" id="chkFurther" value="1"
                           <?= $val['further_studies'] ? 'checked' : '' ?>
                           onclick="event.stopPropagation()">
                    <label for="chkFurther">I am currently pursuing or have completed further studies</label>
                </div>
                <div class="cond <?= $val['further_studies'] ? 'active' : '' ?>" id="section-further">
                    <div class="form-group">
                        <label class="form-label" for="school_further_studies">School / Institution</label>
                        <input type="text" id="school_further_studies" name="school_further_studies"
                               class="form-input"
                               value="<?= v($val, 'school_further_studies') ?>"
                               placeholder="e.g. University of the Philippines (MA Education)">
                    </div>
                </div>
            </div>
        </div>

        <!-- Professional License -->
        <div class="section-card">
            <div class="section-header">
                <span class="section-icon">🎖️</span>
                <h3>Professional License / Certification</h3>
            </div>
            <div class="section-body">
                <div class="form-group">
                    <label class="form-label" for="professional_license">License or Certification</label>
                    <input type="text" id="professional_license" name="professional_license"
                           class="form-input"
                           value="<?= v($val, 'professional_license') ?>"
                           placeholder="e.g. PRC Licensed Teacher, CPA, AWS Certified Developer">
                    <span class="form-hint">Leave blank if not applicable.</span>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <?= $tracer ? '💾 Update My Response' : '📨 Submit Tracer Survey' ?>
        </button>

    </form>

</main>

<!-- ─── JS ──────────────────────────────────────────────── -->
<script>
/* ── Notification Bell ──────────────────────────────────── */
let panelOpen   = false;
let unreadCount = <?= $unreadCount ?>;
const PAGE_URL  = window.location.pathname;

function togglePanel(e) {
    e.stopPropagation(); panelOpen = !panelOpen;
    document.getElementById('notifPanel').classList.toggle('open', panelOpen);
    if (panelOpen) loadNotifList();
}
document.addEventListener('click', function(e) {
    if (!document.getElementById('notifWrap').contains(e.target) && panelOpen) {
        document.getElementById('notifPanel').classList.remove('open'); panelOpen = false;
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && panelOpen) { document.getElementById('notifPanel').classList.remove('open'); panelOpen = false; }
});
function loadNotifList() {
    fetch('dashboard.php?ajax_notif_list=1').then(r=>r.json()).then(renderList).catch(()=>{});
}
function markRead(id, el) {
    if (!el.classList.contains('unread')) return;
    fetch(PAGE_URL,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_mark_read=1&notif_id='+id})
    .then(r=>r.json()).then(d=>{ if(!d.ok)return; el.classList.remove('unread'); const dot=document.getElementById('dot-'+id); if(dot)dot.remove(); unreadCount=Math.max(0,unreadCount-1); syncBadge(); });
}
function markAllRead() {
    fetch(PAGE_URL,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'ajax_mark_all_read=1'})
    .then(r=>r.json()).then(d=>{ if(!d.ok)return; document.querySelectorAll('.notif-item.unread').forEach(el=>el.classList.remove('unread')); document.querySelectorAll('.notif-unread-dot').forEach(el=>el.remove()); unreadCount=0; syncBadge(); });
}
function syncBadge() {
    const badge=document.getElementById('notifBadge'),pill=document.getElementById('countPill'),btn=document.getElementById('notifBtn');
    if(unreadCount>0){badge.textContent=unreadCount>99?'99+':unreadCount;badge.classList.remove('hidden');pill.textContent=unreadCount+' new';pill.style.opacity='1';btn.classList.add('has-unread');}
    else{badge.classList.add('hidden');pill.style.opacity='0';btn.classList.remove('has-unread');}
}
function renderList(items) {
    const list=document.getElementById('notifList');
    if(!items.length){list.innerHTML='<div class="notif-empty"><div class="empty-emoji">🎉</div><p>All caught up!</p></div>';return;}
    const iconMap=[['ready','📦','type-ready','Document Ready'],['approv','✅','type-approved','Request Approved'],['process','⚙️','type-process','Being Processed'],['cancel','❌','type-cancel','Request Cancelled'],['releas','📬','type-ready','Document Released'],['paid','💳','type-approved','Payment Confirmed'],['welcom','👋','type-info','Welcome!']];
    list.innerHTML=items.map(n=>{const lm=n.message.toLowerCase();let[emoji,cls,title]=['🔔','type-info','Notification'];for(const[k,e,c,t]of iconMap){if(lm.includes(k)){emoji=e;cls=c;title=t;break;}}const isNew=n.is_read==0;return`<div class="notif-item ${isNew?'unread':''}" id="ni-${n.id}" onclick="markRead(${n.id},this)"><div class="notif-item-icon ${cls}">${emoji}</div><div class="notif-item-body"><div class="notif-item-title">${esc(title)}</div><div class="notif-item-msg">${esc(n.message)}</div><div class="notif-item-time">🕐 ${jsAgo(n.created_at)}</div></div>${isNew?`<div class="notif-unread-dot" id="dot-${n.id}"></div>`:''}</div>`;}).join('');
}
function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function jsAgo(ds){const d=Math.floor((Date.now()-new Date(ds).getTime())/1000);if(d<60)return'just now';if(d<3600)return Math.floor(d/60)+' min ago';if(d<86400)return Math.floor(d/3600)+' hr ago';if(d<604800)return Math.floor(d/86400)+' day ago';return new Date(ds).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});}
setInterval(()=>{fetch('dashboard.php?ajax_unread_count=1').then(r=>r.json()).then(d=>{if(typeof d.count==='number'&&d.count!==unreadCount){unreadCount=d.count;syncBadge();}}).catch(()=>{});},3000);

/* ── Employment status card toggling ─────────────────────── */
const statusGrid    = document.getElementById('statusGrid');
const employmentBox = document.getElementById('section-employment');

function refreshEmployment() {
    const sel = statusGrid.querySelector('input[name="employment_status"]:checked');
    const v   = sel ? sel.value : '';
    const show = (v === 'employed' || v === 'self_employed');
    employmentBox.classList.toggle('active', show);
    employmentBox.style.display = show ? '' : 'none';
    updateProgress();
}

statusGrid.querySelectorAll('.status-opt').forEach(opt => {
    opt.addEventListener('click', () => {
        statusGrid.querySelectorAll('.status-opt').forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
        opt.querySelector('input').checked = true;
        refreshEmployment();
    });
});

/* ── Further studies toggle ──────────────────────────────── */
const chkFurther  = document.getElementById('chkFurther');
const furtherBox  = document.getElementById('section-further');
chkFurther.addEventListener('change', () => {
    furtherBox.classList.toggle('active', chkFurther.checked);
    updateProgress();
});

/* ── Live progress bar ───────────────────────────────────── */
function updateProgress() {
    const fields = [
        document.querySelector('input[name="employment_status"]:checked'),
        document.getElementById('employer_name'),
        document.getElementById('job_title'),
        document.getElementById('employment_sector'),
        document.getElementById('degree_relevance'),
        document.getElementById('professional_license'),
    ];
    let filled = 0;
    fields.forEach(f => { if (f && f.value && f.value.trim() !== '') filled++; });
    const pct = Math.round((filled / fields.length) * 100);
    document.getElementById('progressFill').style.width = pct + '%';
    document.getElementById('progressPct').textContent  = pct + '%';
}

/* Attach live update listeners */
['employer_name','job_title','employment_sector','degree_relevance','professional_license']
    .forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateProgress);
    });

/* Init */
refreshEmployment();
</script>

</body>
</html>