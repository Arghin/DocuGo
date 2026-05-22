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
$stmt = $conn->prepare("
    SELECT first_name, last_name, student_id, course, year_graduated
    FROM users WHERE id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ── Unread notifications ───────────────────────────────── */
$nStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
$nStmt->bind_param("i", $userId); $nStmt->execute();
$unreadCount = (int)$nStmt->get_result()->fetch_assoc()['c'];
$nStmt->close();

/* ── Check if alumni_employment table exists ────────────── */
$tableExists = $conn->query("SHOW TABLES LIKE 'alumni_employment'")->num_rows > 0;

/* ── Load existing employment records ───────────────────── */
$employments = [];
if ($tableExists) {
    $stmt = $conn->prepare("
        SELECT * FROM alumni_employment
        WHERE user_id = ?
        ORDER BY is_current DESC, date_started DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employments[] = $row;
    }
    $stmt->close();
}

/* ── Handle form actions ────────────────────────────────── */
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['ajax_mark_read'])
    && !isset($_POST['ajax_mark_all_read'])) {

    $action = $_POST['action'] ?? '';

    /* ── Create table if not exists ─────────────────────── */
    if (!$tableExists) {
        $conn->query("
            CREATE TABLE alumni_employment (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT NOT NULL,
                company_name VARCHAR(200) NOT NULL,
                job_title    VARCHAR(150) NOT NULL,
                work_setup   ENUM('onsite','remote','hybrid') DEFAULT 'onsite',
                employment_type ENUM('full_time','part_time','contract','freelance','internship') DEFAULT 'full_time',
                industry     VARCHAR(150),
                date_started DATE,
                date_ended   DATE,
                is_current   TINYINT(1) DEFAULT 0,
                description  TEXT,
                skills       VARCHAR(300),
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        $tableExists = true;
    }

    /* ── Add / Edit employment ───────────────────────────── */
    if ($action === 'save_employment') {
        $editId      = intval($_POST['edit_id'] ?? 0);
        $company     = trim($_POST['company_name']      ?? '');
        $jobTitle    = trim($_POST['job_title']          ?? '');
        $workSetup   = $_POST['work_setup']              ?? 'onsite';
        $empType     = $_POST['employment_type']         ?? 'full_time';
        $industry    = trim($_POST['industry']           ?? '');
        $dateStarted = $_POST['date_started']            ?? '';
        $dateEnded   = $_POST['date_ended']              ?? '';
        $isCurrent   = isset($_POST['is_current']) ? 1   : 0;
        $description = trim($_POST['description']        ?? '');
        $skills      = trim($_POST['skills']             ?? '');

        if (empty($company))          $error = 'Company name is required.';
        elseif (empty($jobTitle))     $error = 'Job title is required.';
        elseif (empty($dateStarted))  $error = 'Start date is required.';
        elseif (!$isCurrent && empty($dateEnded)) $error = 'End date is required if not current job.';

        if (empty($error)) {
            $endVal      = $isCurrent    ? null : $dateEnded;
            $industryVal = $industry     === '' ? null : $industry;
            $descVal     = $description  === '' ? null : $description;
            $skillsVal   = $skills       === '' ? null : $skills;

            if ($isCurrent) {
                $conn->query("UPDATE alumni_employment SET is_current=0 WHERE user_id=$userId");
            }

            if ($editId > 0) {
                $stmt = $conn->prepare("
                    UPDATE alumni_employment SET
                        company_name=?, job_title=?, work_setup=?, employment_type=?,
                        industry=?, date_started=?, date_ended=?, is_current=?,
                        description=?, skills=?, updated_at=NOW()
                    WHERE id=? AND user_id=?
                ");
                $stmt->bind_param(
                    "sssssssiisii",
                    $company, $jobTitle, $workSetup, $empType,
                    $industryVal, $dateStarted, $endVal, $isCurrent,
                    $descVal, $skillsVal, $editId, $userId
                );
                $msg = 'Employment record updated successfully.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO alumni_employment
                        (user_id, company_name, job_title, work_setup, employment_type,
                         industry, date_started, date_ended, is_current, description, skills)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param(
                    "isssssssiss",
                    $userId, $company, $jobTitle, $workSetup, $empType,
                    $industryVal, $dateStarted, $endVal, $isCurrent,
                    $descVal, $skillsVal
                );
                $msg = 'Employment record added successfully.';
            }

            if ($stmt->execute()) {
                $success = $msg;
            } else {
                $error = 'Failed to save record: ' . $stmt->error;
            }
            $stmt->close();

            // Refresh list
            $stmt = $conn->prepare("
                SELECT * FROM alumni_employment
                WHERE user_id=?
                ORDER BY is_current DESC, date_started DESC
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $employments = [];
            while ($row = $result->fetch_assoc()) $employments[] = $row;
            $stmt->close();
        }
    }

    /* ── Delete employment ───────────────────────────────── */
    if ($action === 'delete_employment') {
        $delId = intval($_POST['delete_id'] ?? 0);
        if ($delId > 0) {
            $stmt = $conn->prepare("DELETE FROM alumni_employment WHERE id=? AND user_id=?");
            $stmt->bind_param("ii", $delId, $userId);
            if ($stmt->execute()) {
                $success = 'Employment record deleted.';
            } else {
                $error = 'Failed to delete record.';
            }
            $stmt->close();

            // Refresh list
            $stmt = $conn->prepare("
                SELECT * FROM alumni_employment
                WHERE user_id=?
                ORDER BY is_current DESC, date_started DESC
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $employments = [];
            while ($row = $result->fetch_assoc()) $employments[] = $row;
            $stmt->close();
        }
    }
}

$conn->close();

function escape($v)  { return htmlspecialchars($v ?? ''); }
function formatDate($d) { return $d ? date('M Y', strtotime($d)) : '—'; }

$initial  = strtoupper(substr($user['first_name'], 0, 1));
$fullName = escape($user['first_name'] . ' ' . $user['last_name']);

$workSetupLabels = [
    'onsite' => ['🏢', 'On-site'],
    'remote' => ['🏠', 'Remote'],
    'hybrid' => ['🔀', 'Hybrid'],
];
$empTypeLabels = [
    'full_time'  => ['💼', 'Full-time'],
    'part_time'  => ['⏰', 'Part-time'],
    'contract'   => ['📋', 'Contract'],
    'freelance'  => ['🧑‍💻', 'Freelance'],
    'internship' => ['🎓', 'Internship'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employment Profile — ADFC DocuGo</title>
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

        /* Stats Row */
        .stats-row {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 0.85rem; margin-bottom: 1.1rem;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1rem 1.2rem;
            display: flex; align-items: center; gap: 0.85rem;
        }
        .stat-icon {
            width: 42px; height: 42px; border-radius: var(--radius-md);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .stat-icon.blue   { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .stat-icon.green  { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .stat-icon.purple { background: rgba(167,139,250,0.15); color: #a78bfa; }
        .stat-num   { font-family: 'Sora', sans-serif; font-size: 1.5rem; font-weight: 800; color: var(--text); line-height: 1; }
        .stat-label { font-size: 0.7rem; color: var(--text-muted); margin-top: 2px; }

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
            display: flex; align-items: center; justify-content: space-between; gap: 0.65rem;
        }
        .section-header-left { display: flex; align-items: center; gap: 0.65rem; }
        .section-header h3 { font-family: 'Sora', sans-serif; font-size: 0.9rem; font-weight: 700; color: var(--text); }
        .section-icon { font-size: 1rem; }
        .section-body { padding: 1.2rem; }

        /* Button Add */
        .btn-add {
            display: flex; align-items: center; gap: 0.4rem;
            padding: 0.4rem 0.9rem; background: var(--accent); color: #fff;
            border: none; border-radius: var(--radius-sm); font-size: 0.75rem;
            font-weight: 600; cursor: pointer; font-family: inherit;
            transition: transform 0.2s;
        }
        .btn-add:hover { transform: translateY(-1px); background: var(--primary-dk); }

        /* Timeline */
        .timeline { position: relative; padding-left: 1.6rem; }
        .timeline::before {
            content: ''; position: absolute;
            left: 8px; top: 10px; bottom: 10px;
            width: 2px; background: var(--border);
        }
        .tl-entry { position: relative; margin-bottom: 1.1rem; }
        .tl-entry:last-child { margin-bottom: 0; }
        .tl-dot {
            position: absolute; left: -1.6rem; top: 12px;
            width: 16px; height: 16px; border-radius: 50%;
            background: var(--border); border: 3px solid var(--bg2);
        }
        .tl-dot.current { background: var(--green); }

        .entry-card {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1rem 1.1rem;
            transition: all 0.2s;
        }
        .entry-card:hover { border-color: var(--accent); }
        .entry-card.current-job { border-left: 4px solid var(--green); }

        .entry-top {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;
        }
        .entry-title { font-size: 0.9rem; font-weight: 700; color: var(--text); margin-bottom: 2px; }
        .entry-company { font-size: 0.8rem; color: var(--text-muted); font-weight: 600; }
        .entry-dates { font-size: 0.7rem; color: var(--text-dim); margin-top: 2px; }

        .entry-badges { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .badge {
            display: inline-flex; align-items: center; gap: 3px;
            padding: 2px 8px; border-radius: 20px;
            font-size: 0.65rem; font-weight: 600;
        }
        .badge-blue   { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .badge-green  { background: rgba(76,217,138,0.15); color: #4cd98a; }
        .badge-purple { background: rgba(167,139,250,0.15); color: #a78bfa; }
        .badge-yellow { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-gray   { background: rgba(255,255,255,0.1); color: var(--text-muted); }
        .badge-current{ background: rgba(76,217,138,0.15); color: #4cd98a; }

        .entry-desc { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.65rem; line-height: 1.55; }
        .entry-skills { margin-top: 0.5rem; font-size: 0.7rem; color: var(--text-dim); }
        .entry-skills strong { color: var(--text-muted); }

        .entry-actions { display: flex; gap: 0.4rem; flex-shrink: 0; }
        .btn-sm {
            padding: 4px 10px; border: none; border-radius: var(--radius-sm);
            font-size: 0.68rem; font-weight: 600; cursor: pointer;
            font-family: inherit; transition: all 0.15s;
            display: inline-flex; align-items: center; gap: 3px;
        }
        .btn-sm:hover { transform: translateY(-1px); opacity: 0.9; }
        .btn-edit   { background: rgba(96,165,250,0.15); color: #60a5fa; }
        .btn-delete { background: rgba(248,113,113,0.15); color: #f87171; }

        .empty-state { text-align: center; padding: 3rem 1.5rem; }
        .empty-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .empty-state h4 { font-family: 'Sora', sans-serif; font-size: 0.9rem; font-weight: 700; color: var(--text); margin-bottom: 0.3rem; }
        .empty-state p  { font-size: 0.8rem; color: var(--text-dim); margin-bottom: 1rem; }

        /* Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .form-label {
            font-size: 0.7rem; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .form-label .opt { font-weight: 400; text-transform: none; color: var(--text-dim); font-size: 0.65rem; }
        .form-input, .form-select, .form-textarea {
            padding: 0.55rem 0.8rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.85rem; color: var(--text);
            background: var(--bg2);
            font-family: inherit;
            transition: border-color 0.15s;
            width: 100%;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59,107,255,0.1);
        }
        .form-textarea { resize: vertical; min-height: 80px; }
        .form-hint { font-size: 0.65rem; color: var(--text-dim); margin-top: 0.25rem; }

        .check-row {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.75rem 0.9rem;
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            cursor: pointer;
        }
        .check-row input[type="checkbox"] {
            width: 16px; height: 16px; accent-color: var(--accent);
            cursor: pointer; flex-shrink: 0; margin: 0;
        }
        .check-row label { font-size: 0.8rem; color: var(--text); font-weight: 500; cursor: pointer; margin: 0; }

        .cond { display: none; }
        .cond.active { display: grid; }

        .btn-row { display: flex; gap: 0.75rem; margin-top: 1rem; }
        .btn-save {
            flex: 1; padding: 0.75rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border: none; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 0.85rem; font-weight: 700;
            cursor: pointer; transition: transform 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 0.4rem;
        }
        .btn-save:hover { transform: translateY(-1px); opacity: 0.95; }
        .btn-cancel-form {
            padding: 0.75rem 1.4rem;
            background: var(--surface);
            color: var(--text-muted);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.85rem; font-weight: 600;
            cursor: pointer; transition: all 0.15s;
        }
        .btn-cancel-form:hover { background: var(--surface-hv); border-color: var(--accent); }

        @media (max-width: 1024px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .notif-panel { width: 320px; right: -50px; }
        }
        @media (max-width: 500px) {
            .stats-row { grid-template-columns: 1fr; }
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
        <a href="employment_profile.php" class="menu-item active"><span class="menu-icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item"><span class="menu-icon">🎓</span> Alumni Documents</a>

        <div class="menu-section">ACCOUNT</div>
        <a href="profile.php" class="menu-item"><span class="menu-icon">👤</span> Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<main class="main">

    <div class="topbar">
        <h1>💼 Employment Profile</h1>
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
                <strong><?= $fullName ?></strong>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= escape($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= escape($error) ?></div>
    <?php endif; ?>

    <div class="intro-banner">
        <div class="intro-icon">💼</div>
        <div class="intro-text">
            <h2>Your Employment History</h2>
            <p>Add and manage your work experience here. This information helps the school track
               graduate outcomes and supports your professional record. Your data is confidential
               and used only for institutional purposes.</p>
        </div>
    </div>

    <?php
    $totalJobs  = count($employments);
    $currentJob = array_filter($employments, fn($e) => (int)$e['is_current'] === 1);
    $totalYears = 0;
    if (!empty($employments)) {
        $oldestStart = null;
        foreach ($employments as $e) {
            if ($e['date_started'] && (!$oldestStart || $e['date_started'] < $oldestStart)) {
                $oldestStart = $e['date_started'];
            }
        }
        if ($oldestStart) {
            $totalYears = (int)((time() - strtotime($oldestStart)) / (365.25 * 86400));
        }
    }
    ?>
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-briefcase"></i></div>
            <div><div class="stat-num"><?= $totalJobs ?></div><div class="stat-label">Total Jobs</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div><div class="stat-num"><?= count($currentJob) ?></div><div class="stat-label">Current Position</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-calendar-alt"></i></div>
            <div><div class="stat-num"><?= $totalYears ?></div><div class="stat-label">Years of Experience</div></div>
        </div>
    </div>

    <div class="section-card">
        <div class="section-header">
            <div class="section-header-left">
                <span class="section-icon"><i class="fas fa-building"></i></span>
                <h3>Work Experience</h3>
            </div>
            <button class="btn-add" onclick="openForm()"><i class="fas fa-plus"></i> Add Experience</button>
        </div>
        <div class="section-body">

            <?php if (empty($employments)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-briefcase"></i></div>
                <h4>No employment records yet</h4>
                <p>Add your work experience to build your employment profile.</p>
                <button class="btn-add" onclick="openForm()" style="margin:0 auto;">
                    <i class="fas fa-plus"></i> Add Your First Job
                </button>
            </div>

            <?php else: ?>
            <div class="timeline">
                <?php foreach ($employments as $emp):
                    $isCurr  = (int)$emp['is_current'] === 1;
                    $wsLabel = $workSetupLabels[$emp['work_setup']] ?? ['🏢', 'On-site'];
                    $etLabel = $empTypeLabels[$emp['employment_type']] ?? ['💼', 'Full-time'];
                    $endDisp = $isCurr ? 'Present' : formatDate($emp['date_ended']);
                ?>
                <div class="tl-entry">
                    <div class="tl-dot <?= $isCurr ? 'current' : '' ?>"></div>
                    <div class="entry-card <?= $isCurr ? 'current-job' : '' ?>">
                        <div class="entry-top">
                            <div>
                                <div class="entry-title"><?= escape($emp['job_title']) ?></div>
                                <div class="entry-company"><i class="fas fa-building"></i> <?= escape($emp['company_name']) ?></div>
                                <div class="entry-dates"><i class="fas fa-calendar-alt"></i> <?= formatDate($emp['date_started']) ?> — <?= $endDisp ?></div>
                                <div class="entry-badges">
                                    <?php if ($isCurr): ?>
                                        <span class="badge badge-current"><i class="fas fa-star"></i> Current Job</span>
                                    <?php endif; ?>
                                    <span class="badge badge-blue"><?= $wsLabel[0] ?> <?= $wsLabel[1] ?></span>
                                    <span class="badge badge-purple"><?= $etLabel[0] ?> <?= $etLabel[1] ?></span>
                                    <?php if ($emp['industry']): ?>
                                        <span class="badge badge-gray"><i class="fas fa-industry"></i> <?= escape($emp['industry']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="entry-actions">
                                <button class="btn-sm btn-edit" onclick='editRecord(<?= htmlspecialchars(json_encode($emp)) ?>)'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                                    <input type="hidden" name="action" value="delete_employment">
                                    <input type="hidden" name="delete_id" value="<?= $emp['id'] ?>">
                                    <button type="submit" class="btn-sm btn-delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php if ($emp['description']): ?>
                            <div class="entry-desc"><?= nl2br(escape($emp['description'])) ?></div>
                        <?php endif; ?>
                        <?php if ($emp['skills']): ?>
                            <div class="entry-skills"><strong><i class="fas fa-code"></i> Skills:</strong> <?= escape($emp['skills']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Add / Edit Form -->
    <div class="section-card" id="formSection" style="display:none;">
        <div class="section-header">
            <div class="section-header-left">
                <span class="section-icon"><i class="fas fa-pen"></i></span>
                <h3 id="formTitle">Add Work Experience</h3>
            </div>
        </div>
        <div class="section-body">
            <form method="POST" id="empForm">
                <input type="hidden" name="action" value="save_employment">
                <input type="hidden" name="edit_id" id="editId" value="0">

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Company / Employer Name <span style="color:var(--red);">*</span></label>
                        <input type="text" name="company_name" id="f_company" class="form-input" placeholder="e.g. ABC Corporation" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Job Title / Position <span style="color:var(--red);">*</span></label>
                        <input type="text" name="job_title" id="f_job_title" class="form-input" placeholder="e.g. Software Engineer" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Work Setup</label>
                        <select name="work_setup" id="f_work_setup" class="form-select">
                            <option value="onsite">🏢 On-site</option>
                            <option value="remote">🏠 Remote</option>
                            <option value="hybrid">🔀 Hybrid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Employment Type</label>
                        <select name="employment_type" id="f_employment_type" class="form-select">
                            <option value="full_time">💼 Full-time</option>
                            <option value="part_time">⏰ Part-time</option>
                            <option value="contract">📋 Contract</option>
                            <option value="freelance">🧑‍💻 Freelance</option>
                            <option value="internship">🎓 Internship</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Industry <span class="opt">(optional)</span></label>
                        <input type="text" name="industry" id="f_industry" class="form-input" placeholder="e.g. Information Technology, Education">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Start Date <span style="color:var(--red);">*</span></label>
                        <input type="date" name="date_started" id="f_date_started" class="form-input" required>
                    </div>
                </div>

                <div class="check-row" style="margin: 0.75rem 0;" onclick="document.getElementById('f_is_current').click()">
                    <input type="checkbox" name="is_current" id="f_is_current" value="1" onclick="event.stopPropagation()" onchange="toggleEndDate()">
                    <label for="f_is_current"><i class="fas fa-check-circle"></i> I currently work here</label>
                </div>

                <div class="form-grid cond active" id="endDateRow" style="margin-bottom:0.75rem;">
                    <div class="form-group">
                        <label class="form-label">End Date <span style="color:var(--red);">*</span></label>
                        <input type="date" name="date_ended" id="f_date_ended" class="form-input">
                    </div>
                    <div></div>
                </div>

                <div class="form-grid" style="margin-top:0;">
                    <div class="form-group full">
                        <label class="form-label">Job Description <span class="opt">(optional)</span></label>
                        <textarea name="description" id="f_description" class="form-textarea" placeholder="Briefly describe your responsibilities and achievements…"></textarea>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Skills Used <span class="opt">(optional)</span></label>
                        <input type="text" name="skills" id="f_skills" class="form-input" placeholder="e.g. PHP, MySQL, Project Management, Customer Service">
                        <div class="form-hint"><i class="fas fa-info-circle"></i> Separate skills with commas.</div>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="button" class="btn-cancel-form" onclick="closeForm()"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Experience</button>
                </div>
            </form>
        </div>
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

// Employment Form functions
function openForm() {
    document.getElementById('formTitle').textContent = 'Add Work Experience';
    document.getElementById('empForm').reset();
    document.getElementById('editId').value = '0';
    document.getElementById('endDateRow').classList.add('active');
    document.getElementById('formSection').style.display = '';
    document.getElementById('formSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function closeForm() {
    document.getElementById('formSection').style.display = 'none';
}
function editRecord(data) {
    document.getElementById('formTitle').textContent = 'Edit Work Experience';
    document.getElementById('editId').value = data.id;
    document.getElementById('f_company').value = data.company_name || '';
    document.getElementById('f_job_title').value = data.job_title || '';
    document.getElementById('f_work_setup').value = data.work_setup || 'onsite';
    document.getElementById('f_employment_type').value = data.employment_type || 'full_time';
    document.getElementById('f_industry').value = data.industry || '';
    document.getElementById('f_date_started').value = data.date_started || '';
    document.getElementById('f_date_ended').value = data.date_ended || '';
    document.getElementById('f_is_current').checked = data.is_current == 1;
    document.getElementById('f_description').value = data.description || '';
    document.getElementById('f_skills').value = data.skills || '';
    toggleEndDate();
    document.getElementById('formSection').style.display = '';
    document.getElementById('formSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function toggleEndDate() {
    const isCurrent = document.getElementById('f_is_current').checked;
    const row = document.getElementById('endDateRow');
    row.classList.toggle('active', !isCurrent);
    document.getElementById('f_date_ended').required = !isCurrent;
}
</script>

</body>
</html>