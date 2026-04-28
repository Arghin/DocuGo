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

    // ── Create table if not exists ───────────────────────
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

    // ── Add / Edit employment ────────────────────────────
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

        // Validate
        if (empty($company))     $error = 'Company name is required.';
        elseif (empty($jobTitle))$error = 'Job title is required.';
        elseif (empty($dateStarted)) $error = 'Start date is required.';
        elseif (!$isCurrent && empty($dateEnded)) $error = 'End date is required if not current job.';

        if (empty($error)) {
            $endVal     = $isCurrent ? null : $dateEnded;
            $industryVal= $industry   === '' ? null : $industry;
            $descVal    = $description=== '' ? null : $description;
            $skillsVal  = $skills     === '' ? null : $skills;

            // If marking as current, unset other current jobs
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
                    $company,$jobTitle,$workSetup,$empType,
                    $industryVal,$dateStarted,$endVal,$isCurrent,
                    $descVal,$skillsVal,$editId,$userId
                );
                $msg = 'Employment record updated successfully.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO alumni_employment
                        (user_id,company_name,job_title,work_setup,employment_type,
                         industry,date_started,date_ended,is_current,description,skills)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->bind_param(
                    "isssssssis s",
                    $userId,$company,$jobTitle,$workSetup,$empType,
                    $industryVal,$dateStarted,$endVal,$isCurrent,
                    $descVal,$skillsVal
                );
                $msg = 'Employment record added successfully.';
            }

            if ($stmt->execute()) {
                $success = $msg;
            } else {
                $error = 'Failed to save record: ' . $stmt->error;
            }
            $stmt->close();

            // Refresh
            $stmt = $conn->prepare("SELECT * FROM alumni_employment WHERE user_id=? ORDER BY is_current DESC, date_started DESC");
            $stmt->bind_param("i", $userId); $stmt->execute();
            $result = $stmt->get_result();
            $employments = [];
            while ($row = $result->fetch_assoc()) $employments[] = $row;
            $stmt->close();
        }
    }

    // ── Delete employment ────────────────────────────────
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

            // Refresh
            $stmt = $conn->prepare("SELECT * FROM alumni_employment WHERE user_id=? ORDER BY is_current DESC, date_started DESC");
            $stmt->bind_param("i", $userId); $stmt->execute();
            $result = $stmt->get_result();
            $employments = [];
            while ($row = $result->fetch_assoc()) $employments[] = $row;
            $stmt->close();
        }
    }
}

$conn->close();

function e($v)  { return htmlspecialchars($v ?? ''); }
function fd($d) { return $d ? date('M Y', strtotime($d)) : '—'; }

$initial  = strtoupper(substr($user['first_name'], 0, 1));
$fullName = e($user['first_name'] . ' ' . $user['last_name']);

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
    <title>Employment Profile — DocuGo</title>
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
        .type-ready{background:#d1fae5}.type-approved{background:#dbeafe}.type-process{background:#e0e7ff}.type-cancel{background:#fee2e2}.type-info{background:#f3f4f6}
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

        /* ─── Section Card ────────────────────────────── */
        .section-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1.1rem; overflow: hidden;
        }
        .section-header {
            padding: 0.85rem 1.2rem; border-bottom: 1px solid #f3f4f6;
            display: flex; align-items: center; justify-content: space-between; gap: 0.65rem;
        }
        .section-header-left { display: flex; align-items: center; gap: 0.65rem; }
        .section-header h3 { font-size: 0.95rem; font-weight: 700; color: #111827; }
        .section-icon { font-size: 1.1rem; }
        .section-body { padding: 1.2rem; }

        /* ─── Add Button ──────────────────────────────── */
        .btn-add {
            display: flex; align-items: center; gap: 0.4rem;
            padding: 0.4rem 0.9rem; background: #1a56db; color: #fff;
            border: none; border-radius: 7px; font-size: 0.78rem;
            font-weight: 600; cursor: pointer; font-family: inherit;
            transition: background 0.15s; white-space: nowrap;
        }
        .btn-add:hover { background: #1447c0; }

        /* ─── Timeline ────────────────────────────────── */
        .timeline { position: relative; padding-left: 1.6rem; }
        .timeline::before {
            content: ''; position: absolute;
            left: 8px; top: 10px; bottom: 10px;
            width: 2px; background: #e5e7eb;
        }
        .tl-entry { position: relative; margin-bottom: 1.1rem; }
        .tl-entry:last-child { margin-bottom: 0; }
        .tl-dot {
            position: absolute; left: -1.6rem; top: 12px;
            width: 16px; height: 16px; border-radius: 50%;
            background: #cbd5e1; border: 3px solid #f0f4f8;
            transition: background 0.2s;
        }
        .tl-dot.current { background: #059669; border-color: #f0f4f8; }

        /* ─── Entry Card ──────────────────────────────── */
        .entry-card {
            background: #f9fafb; border: 1px solid #e5e7eb;
            border-radius: 10px; padding: 1rem 1.1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .entry-card:hover { border-color: #cbd5e1; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .entry-card.current-job { border-left: 4px solid #059669; background: #f0fdf4; }

        .entry-top {
            display: flex; align-items: flex-start;
            justify-content: space-between; gap: 0.75rem; flex-wrap: wrap;
        }
        .entry-title { font-size: 0.95rem; font-weight: 700; color: #111827; margin-bottom: 2px; }
        .entry-company { font-size: 0.855rem; color: #374151; font-weight: 600; }
        .entry-dates { font-size: 0.775rem; color: #6b7280; margin-top: 2px; }

        .entry-badges { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.5rem; }
        .badge {
            display: inline-flex; align-items: center; gap: 3px;
            padding: 2px 8px; border-radius: 10px;
            font-size: 0.72rem; font-weight: 600;
        }
        .badge-blue   { background: #dbeafe; color: #1e40af; }
        .badge-green  { background: #d1fae5; color: #065f46; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-gray   { background: #f3f4f6; color: #374151; }
        .badge-current{ background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }

        .entry-desc {
            font-size: 0.825rem; color: #374151;
            margin-top: 0.65rem; line-height: 1.55;
        }
        .entry-skills {
            margin-top: 0.5rem; font-size: 0.775rem; color: #6b7280;
        }
        .entry-skills strong { color: #374151; }

        .entry-actions { display: flex; gap: 0.4rem; flex-shrink: 0; }
        .btn-sm {
            padding: 4px 10px; border: none; border-radius: 6px;
            font-size: 0.72rem; font-weight: 600; cursor: pointer;
            font-family: inherit; transition: opacity 0.15s;
            display: inline-flex; align-items: center; gap: 3px;
        }
        .btn-sm:hover { opacity: 0.82; }
        .btn-edit   { background: #dbeafe; color: #1e40af; }
        .btn-delete { background: #fee2e2; color: #991b1b; }

        /* ─── Empty State ─────────────────────────────── */
        .empty-state {
            text-align: center; padding: 3rem 1.5rem;
        }
        .empty-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .empty-state h4 { font-size: 0.95rem; font-weight: 700; color: #374151; margin-bottom: 0.3rem; }
        .empty-state p  { font-size: 0.82rem; color: #9ca3af; margin-bottom: 1rem; }

        /* ─── Form ────────────────────────────────────── */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .form-label {
            font-size: 0.78rem; font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .form-label .opt { font-weight: 400; text-transform: none; color: #9ca3af; font-size: 0.72rem; letter-spacing: 0; }
        .form-input, .form-select, .form-textarea {
            padding: 0.55rem 0.8rem; border: 1px solid #d1d5db;
            border-radius: 7px; font-size: 0.875rem; color: #111827;
            background: #fff; font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s; width: 100%;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: #1a56db;
            box-shadow: 0 0 0 3px rgba(26,86,219,0.10);
        }
        .form-textarea { resize: vertical; min-height: 80px; }
        .form-hint { font-size: 0.73rem; color: #9ca3af; }

        /* ─── Check Row ───────────────────────────────── */
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
        .check-row label { font-size: 0.855rem; color: #374151; font-weight: 500; cursor: pointer; margin: 0; }

        /* ─── Conditional ─────────────────────────────── */
        .cond { display: none; }
        .cond.active { display: grid; }

        /* ─── Submit ──────────────────────────────────── */
        .btn-row { display: flex; gap: 0.75rem; margin-top: 1rem; }
        .btn-save {
            flex: 1; padding: 0.75rem;
            background: #1a56db; color: #fff;
            border: none; border-radius: 8px; font-family: inherit;
            font-size: 0.9rem; font-weight: 700;
            cursor: pointer; transition: background 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 0.4rem;
        }
        .btn-save:hover { background: #1447c0; }
        .btn-cancel-form {
            padding: 0.75rem 1.4rem;
            background: #f3f4f6; color: #374151;
            border: 1px solid #e5e7eb; border-radius: 8px; font-family: inherit;
            font-size: 0.9rem; font-weight: 600; cursor: pointer; transition: background 0.15s;
        }
        .btn-cancel-form:hover { background: #e5e7eb; }

        /* ─── Stats row ───────────────────────────────── */
        .stats-row {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 0.85rem; margin-bottom: 1.1rem;
        }
        .stat-card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            padding: 1rem 1.2rem;
            display: flex; align-items: center; gap: 0.85rem;
        }
        .stat-icon {
            width: 42px; height: 42px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .stat-icon.blue   { background: #dbeafe; }
        .stat-icon.green  { background: #d1fae5; }
        .stat-icon.purple { background: #ede9fe; }
        .stat-num   { font-size: 1.5rem; font-weight: 800; color: #111827; line-height: 1; }
        .stat-label { font-size: 0.75rem; color: #6b7280; margin-top: 2px; }

        /* ─── Responsive ──────────────────────────────── */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
            .stats-row { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 500px) {
            .notif-panel { width: 280px; right: -50px; }
            .notif-panel::before { right: 64px; }
            .stats-row { grid-template-columns: 1fr; }
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
        <a href="graduate_tracer.php"    class="menu-item"><span class="icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item active"><span class="icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item">
            <span class="icon">🎓</span> Alumni Documents
        </a>
        <div class="menu-label">Account</div>
        <a href="profile.php"       class="menu-item"><span class="icon">👤</span> Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- ─── Main ────────────────────────────────────────────── -->
<main class="main">

    <!-- Topbar -->
    <div class="topbar">
        <h1>💼 Employment Profile</h1>
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
                <strong><?= $fullName ?></strong>
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
        <div class="intro-icon">💼</div>
        <div class="intro-text">
            <h2>Your Employment History</h2>
            <p>
                Add and manage your work experience here. This information helps
                the school track graduate outcomes and supports your professional record.
                Your data is confidential and used only for institutional purposes.
            </p>
        </div>
    </div>

    <!-- Stats -->
    <?php
    $totalJobs   = count($employments);
    $currentJob  = array_filter($employments, fn($e) => (int)$e['is_current'] === 1);
    $hasLicense  = false; // pulled from tracer
    ?>
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon blue">💼</div>
            <div>
                <div class="stat-num"><?= $totalJobs ?></div>
                <div class="stat-label">Total Jobs</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">✅</div>
            <div>
                <div class="stat-num"><?= count($currentJob) ?></div>
                <div class="stat-label">Current Position</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">📅</div>
            <div>
                <div class="stat-num">
                    <?php
                    if (!empty($employments)) {
                        $oldest = min(array_column($employments, 'date_started'));
                        $years  = $oldest ? (int)((time() - strtotime($oldest)) / (365.25 * 86400)) : 0;
                        echo $years;
                    } else { echo 0; }
                    ?>
                </div>
                <div class="stat-label">Years of Experience</div>
            </div>
        </div>
    </div>

    <!-- ── Employment List ──────────────────────────────── -->
    <div class="section-card">
        <div class="section-header">
            <div class="section-header-left">
                <span class="section-icon">🏢</span>
                <h3>Work Experience</h3>
            </div>
            <button class="btn-add" onclick="openForm()">
                + Add Experience
            </button>
        </div>
        <div class="section-body">

            <?php if (empty($employments)): ?>
            <div class="empty-state">
                <div class="empty-icon">💼</div>
                <h4>No employment records yet</h4>
                <p>Add your work experience to build your employment profile.</p>
                <button class="btn-add" onclick="openForm()" style="margin:0 auto;">
                    + Add Your First Job
                </button>
            </div>

            <?php else: ?>
            <div class="timeline">
                <?php foreach ($employments as $emp):
                    $isCurr   = (int)$emp['is_current'] === 1;
                    $wsLabel  = $workSetupLabels[$emp['work_setup']] ?? ['🏢','On-site'];
                    $etLabel  = $empTypeLabels[$emp['employment_type']] ?? ['💼','Full-time'];
                    $endDisp  = $isCurr ? 'Present' : fd($emp['date_ended']);
                ?>
                <div class="tl-entry">
                    <div class="tl-dot <?= $isCurr ? 'current' : '' ?>"></div>
                    <div class="entry-card <?= $isCurr ? 'current-job' : '' ?>">
                        <div class="entry-top">
                            <div>
                                <div class="entry-title"><?= e($emp['job_title']) ?></div>
                                <div class="entry-company">🏢 <?= e($emp['company_name']) ?></div>
                                <div class="entry-dates">
                                    📅 <?= fd($emp['date_started']) ?> — <?= $endDisp ?>
                                </div>
                                <div class="entry-badges">
                                    <?php if ($isCurr): ?>
                                        <span class="badge badge-current">● Current Job</span>
                                    <?php endif; ?>
                                    <span class="badge badge-blue"><?= $wsLabel[0] ?> <?= $wsLabel[1] ?></span>
                                    <span class="badge badge-purple"><?= $etLabel[0] ?> <?= $etLabel[1] ?></span>
                                    <?php if ($emp['industry']): ?>
                                        <span class="badge badge-gray">🏭 <?= e($emp['industry']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="entry-actions">
                                <button class="btn-sm btn-edit"
                                    onclick="editRecord(<?= htmlspecialchars(json_encode($emp)) ?>)">
                                    ✏️ Edit
                                </button>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Delete this record?')">
                                    <input type="hidden" name="action" value="delete_employment">
                                    <input type="hidden" name="delete_id" value="<?= $emp['id'] ?>">
                                    <button type="submit" class="btn-sm btn-delete">🗑</button>
                                </form>
                            </div>
                        </div>
                        <?php if ($emp['description']): ?>
                            <div class="entry-desc"><?= nl2br(e($emp['description'])) ?></div>
                        <?php endif; ?>
                        <?php if ($emp['skills']): ?>
                            <div class="entry-skills">
                                <strong>Skills:</strong> <?= e($emp['skills']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ── Add/Edit Form ────────────────────────────────── -->
    <div class="section-card" id="formSection" style="display:none;">
        <div class="section-header">
            <div class="section-header-left">
                <span class="section-icon">📝</span>
                <h3 id="formTitle">Add Work Experience</h3>
            </div>
        </div>
        <div class="section-body">
            <form method="POST" id="empForm">
                <input type="hidden" name="action" value="save_employment">
                <input type="hidden" name="edit_id" id="editId" value="0">

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Company / Employer Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="company_name" id="f_company"
                               class="form-input" placeholder="e.g. ABC Corporation" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Job Title / Position <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="job_title" id="f_job_title"
                               class="form-input" placeholder="e.g. Software Engineer" required>
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
                        <label class="form-label">Industry <span class="opt">(opt.)</span></label>
                        <input type="text" name="industry" id="f_industry"
                               class="form-input" placeholder="e.g. Information Technology, Education">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Start Date <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="date_started" id="f_date_started"
                               class="form-input" required>
                    </div>
                </div>

                <!-- Current job checkbox -->
                <div class="check-row" style="margin: 0.75rem 0;"
                     onclick="document.getElementById('f_is_current').click()">
                    <input type="checkbox" name="is_current" id="f_is_current" value="1"
                           onclick="event.stopPropagation()" onchange="toggleEndDate()">
                    <label for="f_is_current">I currently work here</label>
                </div>

                <!-- End date (conditional) -->
                <div class="form-grid cond active" id="endDateRow" style="margin-bottom:0.75rem;">
                    <div class="form-group">
                        <label class="form-label">End Date <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="date_ended" id="f_date_ended" class="form-input">
                    </div>
                    <div></div>
                </div>

                <div class="form-grid" style="margin-top:0;">
                    <div class="form-group full">
                        <label class="form-label">Job Description <span class="opt">(opt.)</span></label>
                        <textarea name="description" id="f_description" class="form-textarea"
                                  placeholder="Briefly describe your responsibilities and achievements…"></textarea>
                    </div>
                    <div class="form-group full">
                        <label class="form-label">Skills Used <span class="opt">(opt.)</span></label>
                        <input type="text" name="skills" id="f_skills" class="form-input"
                               placeholder="e.g. PHP, MySQL, Project Management, Customer Service">
                        <span class="form-hint">Separate skills with commas.</span>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="button" class="btn-cancel-form" onclick="closeForm()">Cancel</button>
                    <button type="submit" class="btn-save">
                        💾 Save Experience
                    </button>
                </div>
            </form>
        </div>
    </div>

</main>

<!-- ─── JS ──────────────────────────────────────────────── -->
<script>
/* ── Notification Bell (identical to graduate_tracer.php) ── */
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
    if (e.key === 'Escape' && panelOpen) {
        document.getElementById('notifPanel').classList.remove('open'); panelOpen = false;
    }
});
function loadNotifList() {
    fetch('dashboard.php?ajax_notif_list=1')
        .then(r => r.json()).then(renderList).catch(() => {});
}
function markRead(id, el) {
    if (!el.classList.contains('unread')) return;
    fetch(PAGE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_mark_all_read=1'
    }).then(r => r.json()).then(d => {
        if (!d.ok) return;
        document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
        document.querySelectorAll('.notif-unread-dot').forEach(el => el.remove());
        unreadCount = 0; syncBadge();
    });
}
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
function renderList(items) {
    const list = document.getElementById('notifList');
    if (!items.length) {
        list.innerHTML = '<div class="notif-empty"><div class="empty-emoji">🎉</div><p>All caught up!</p></div>';
        return;
    }
    const iconMap = [
        ['ready','📦','type-ready','Document Ready'],
        ['approv','✅','type-approved','Request Approved'],
        ['process','⚙️','type-process','Being Processed'],
        ['cancel','❌','type-cancel','Request Cancelled'],
        ['releas','📬','type-ready','Document Released'],
        ['paid','💳','type-approved','Payment Confirmed'],
        ['welcom','👋','type-info','Welcome!'],
    ];
    list.innerHTML = items.map(n => {
        const lm = n.message.toLowerCase();
        let [emoji, cls, title] = ['🔔','type-info','Notification'];
        for (const [k,e,c,t] of iconMap) { if (lm.includes(k)) { emoji=e; cls=c; title=t; break; } }
        const isNew = n.is_read == 0;
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
function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function jsAgo(ds) {
    const d = Math.floor((Date.now() - new Date(ds).getTime()) / 1000);
    if (d < 60)     return 'just now';
    if (d < 3600)   return Math.floor(d/60) + ' min ago';
    if (d < 86400)  return Math.floor(d/3600) + ' hr ago';
    if (d < 604800) return Math.floor(d/86400) + ' day ago';
    return new Date(ds).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
}
setInterval(() => {
    fetch('dashboard.php?ajax_unread_count=1')
        .then(r => r.json())
        .then(d => { if (typeof d.count === 'number' && d.count !== unreadCount) { unreadCount = d.count; syncBadge(); } })
        .catch(() => {});
}, 3000);

/* ── Employment Form ────────────────────────────────────── */
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
    document.getElementById('editId').value          = data.id;
    document.getElementById('f_company').value       = data.company_name   || '';
    document.getElementById('f_job_title').value     = data.job_title      || '';
    document.getElementById('f_work_setup').value    = data.work_setup     || 'onsite';
    document.getElementById('f_employment_type').value = data.employment_type || 'full_time';
    document.getElementById('f_industry').value      = data.industry       || '';
    document.getElementById('f_date_started').value  = data.date_started   || '';
    document.getElementById('f_date_ended').value    = data.date_ended     || '';
    document.getElementById('f_is_current').checked  = data.is_current == 1;
    document.getElementById('f_description').value   = data.description    || '';
    document.getElementById('f_skills').value        = data.skills         || '';
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