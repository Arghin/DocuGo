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
if (isset($_GET['ajax_notif_list'])) {
    $s = $conn->prepare("SELECT id, message, is_read, created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
    $s->bind_param("i", $userId);
    $s->execute();
    echo json_encode($s->get_result()->fetch_all(MYSQLI_ASSOC));
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

function escape($v) { return htmlspecialchars($v ?? ''); }
function getVal($arr, $k) { return htmlspecialchars($arr[$k] ?? ''); }

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
    <title>Graduate Tracer — ADFC DocuGo</title>
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

        /* Intro Banner */
        .intro-banner {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            padding: 1.4rem 1.6rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.2rem;
            display: flex; align-items: flex-start; gap: 1rem; flex-wrap: wrap;
        }
        .intro-icon { font-size: 2rem; flex-shrink: 0; }
        .intro-text h2 { font-family: 'Sora', sans-serif; font-size: 1rem; font-weight: 800; margin-bottom: 0.3rem; }
        .intro-text p  { font-size: 0.8rem; opacity: 0.88; line-height: 1.6; }
        .intro-pill {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: 0.65rem;
            background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3);
            padding: 3px 10px; border-radius: 20px;
            font-size: 0.7rem; font-weight: 700;
        }

        /* Progress Card */
        .progress-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1rem 1.2rem;
            margin-bottom: 1.1rem;
            display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
        }
        .progress-label { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; white-space: nowrap; }
        .progress-bar-wrap { flex: 1; min-width: 120px; background: var(--border); border-radius: 10px; height: 8px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: linear-gradient(90deg, var(--accent), var(--accent2)); border-radius: 10px; transition: width 0.5s ease; }
        .progress-pct { font-size: 0.8rem; font-weight: 800; color: var(--accent); white-space: nowrap; }

        /* Section Card */
        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            margin-bottom: 1.1rem;
            overflow: hidden;
        }
        .section-header {
            padding: 0.85rem 1.2rem;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 0.65rem;
        }
        .section-header h3 { font-family: 'Sora', sans-serif; font-size: 0.9rem; font-weight: 700; color: var(--text); }
        .section-icon { font-size: 1rem; }
        .section-body { padding: 1.2rem; }

        /* Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .form-label {
            font-size: 0.7rem; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .form-input, .form-select {
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.85rem; color: var(--text);
            background: var(--bg2);
            font-family: inherit;
            transition: border-color 0.15s;
            width: 100%;
        }
        .form-input:focus, .form-select:focus {
            outline: none; border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59,107,255,0.1);
        }
        .form-input[readonly] { background: var(--surface); color: var(--text-dim); cursor: not-allowed; }
        .form-hint { font-size: 0.7rem; color: var(--text-dim); margin-top: 0.25rem; }
        .form-hint a { color: var(--accent2); font-weight: 600; text-decoration: none; }
        .form-hint a:hover { text-decoration: underline; }

        /* Status Grid */
        .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.7rem; }
        .status-opt {
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1rem 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            position: relative;
        }
        .status-opt:hover { border-color: var(--accent); background: var(--surface-hv); }
        .status-opt.selected { border-color: var(--accent); background: rgba(59,107,255,0.08); }
        .status-opt.selected::after {
            content: '✓'; position: absolute; top: 7px; right: 9px;
            font-size: 0.7rem; font-weight: 800; color: var(--accent);
        }
        .status-opt input { display: none; }
        .status-opt .s-emoji { font-size: 1.5rem; margin-bottom: 0.4rem; }
        .status-opt .s-title { font-size: 0.8rem; font-weight: 700; color: var(--text); }
        .status-opt.selected .s-title { color: var(--accent); }

        /* Checkbox Row */
        .check-row {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.75rem 0.9rem;
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
        }
        .check-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--accent); cursor: pointer; flex-shrink: 0; margin: 0; }
        .check-row label { font-size: 0.8rem; color: var(--text); font-weight: 500; cursor: pointer; margin: 0; }

        /* Conditional section */
        .cond { display: none; margin-top: 1rem; }
        .cond.active { display: block; }

        /* Submit Button */
        .btn-submit {
            width: 100%; padding: 0.85rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border: none; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 0.9rem; font-weight: 700;
            cursor: pointer; transition: transform 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
        }
        .btn-submit:hover { transform: translateY(-1px); opacity: 0.95; }

        @media (max-width: 1024px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .status-grid { grid-template-columns: 1fr 1fr; }
            .notif-panel { width: 320px; right: -50px; }
        }
        @media (max-width: 500px) {
            .status-grid { grid-template-columns: 1fr; }
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
        <a href="graduate_tracer.php" class="menu-item active"><span class="menu-icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item"><span class="menu-icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item"><span class="menu-icon">🎓</span> Alumni Documents</a>

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
        <h1>📊 Graduate Tracer Survey</h1>
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

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= escape($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif; ?>

    <!-- Intro Banner -->
    <div class="intro-banner">
        <div class="intro-icon">🎓</div>
        <div class="intro-text">
            <h2>Help us track our alumni</h2>
            <p>This short survey helps the school understand what graduates are doing after graduation.
               Your responses are confidential and help us improve our programs.</p>
            <?php if ($tracer): ?>
                <div class="intro-pill"><i class="fas fa-check-circle"></i> Last submitted: <?= date('M d, Y g:i A', strtotime($tracer['date_submitted'])) ?></div>
            <?php else: ?>
                <div class="intro-pill"><i class="fas fa-pencil-alt"></i> Not yet submitted</div>
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

    <!-- Form -->
    <form method="POST" id="tracerForm">

        <!-- Graduate Information (read-only) -->
        <div class="section-card">
            <div class="section-header">
                <span class="section-icon"><i class="fas fa-user-graduate"></i></span>
                <h3>Graduate Information</h3>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-input" readonly value="<?= escape($user['first_name'] . ' ' . $user['last_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Student ID</label>
                        <input type="text" class="form-input" readonly value="<?= escape($user['student_id'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Course / Program</label>
                        <input type="text" class="form-input" readonly value="<?= escape($user['course'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Year Graduated</label>
                        <input type="text" class="form-input" readonly value="<?= escape($user['year_graduated'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-hint"><i class="fas fa-info-circle"></i> To update your graduate info, go to <a href="profile.php">Profile</a>.</div>
            </div>
        </div>

        <!-- Employment Status -->
        <div class="section-card">
            <div class="section-header">
                <span class="section-icon"><i class="fas fa-briefcase"></i></span>
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
                        <input type="radio" name="employment_status" value="<?= $key ?>" <?= $sel ? 'checked' : '' ?> required>
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
                <span class="section-icon"><i class="fas fa-building"></i></span>
                <h3>Employment Details</h3>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="employer_name">Employer / Company Name</label>
                        <input type="text" id="employer_name" name="employer_name" class="form-input" value="<?= getVal($val, 'employer_name') ?>" placeholder="e.g. ABC Corporation">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="job_title">Job Title / Position</label>
                        <input type="text" id="job_title" name="job_title" class="form-input" value="<?= getVal($val, 'job_title') ?>" placeholder="e.g. Software Engineer">
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
                <span class="section-icon"><i class="fas fa-graduation-cap"></i></span>
                <h3>Further Studies</h3>
            </div>
            <div class="section-body">
                <div class="check-row" onclick="document.getElementById('chkFurther').click()">
                    <input type="checkbox" name="further_studies" id="chkFurther" value="1" <?= $val['further_studies'] ? 'checked' : '' ?> onclick="event.stopPropagation()">
                    <label for="chkFurther"><i class="fas fa-book"></i> I am currently pursuing or have completed further studies</label>
                </div>
                <div class="cond <?= $val['further_studies'] ? 'active' : '' ?>" id="section-further">
                    <div class="form-group">
                        <label class="form-label" for="school_further_studies">School / Institution</label>
                        <input type="text" id="school_further_studies" name="school_further_studies" class="form-input" value="<?= getVal($val, 'school_further_studies') ?>" placeholder="e.g. University of the Philippines (MA Education)">
                    </div>
                </div>
            </div>
        </div>

        <!-- Professional License -->
        <div class="section-card">
            <div class="section-header">
                <span class="section-icon"><i class="fas fa-certificate"></i></span>
                <h3>Professional License / Certification</h3>
            </div>
            <div class="section-body">
                <div class="form-group">
                    <label class="form-label" for="professional_license">License or Certification</label>
                    <input type="text" id="professional_license" name="professional_license" class="form-input" value="<?= getVal($val, 'professional_license') ?>" placeholder="e.g. PRC Licensed Teacher, CPA, AWS Certified Developer">
                    <div class="form-hint"><i class="fas fa-info-circle"></i> Leave blank if not applicable.</div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-submit">
            <?= $tracer ? '<i class="fas fa-save"></i> Update My Response' : '<i class="fas fa-paper-plane"></i> Submit Tracer Survey' ?>
        </button>

    </form>

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

// Employment status card toggling
const statusGrid = document.getElementById('statusGrid');
const employmentBox = document.getElementById('section-employment');

function refreshEmployment() {
    const sel = statusGrid.querySelector('input[name="employment_status"]:checked');
    const v = sel ? sel.value : '';
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

// Further studies toggle
const chkFurther = document.getElementById('chkFurther');
const furtherBox = document.getElementById('section-further');
chkFurther.addEventListener('change', () => {
    furtherBox.classList.toggle('active', chkFurther.checked);
    updateProgress();
});

// Live progress bar
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
    document.getElementById('progressPct').textContent = pct + '%';
}

['employer_name','job_title','employment_sector','degree_relevance','professional_license']
    .forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateProgress);
    });

refreshEmployment();
</script>

</body>
</html>