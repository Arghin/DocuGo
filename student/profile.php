<?php
require_once '../includes/config.php';
requireLogin();

$conn   = getConnection();
$userId = $_SESSION['user_id'];

$success = '';
$error   = '';

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

/* ── Detect optional columns ────────────────────────────── */
function columnExists($conn, $table, $col) {
    $s = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->bind_param("ss", $table, $col); $s->execute();
    $r = (int)$s->get_result()->fetch_assoc()['c']; $s->close(); return $r > 0;
}
$hasProfilePic = columnExists($conn, 'users', 'profile_picture');

/* ── Fetch user ─────────────────────────────────────────── */
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i", $userId); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$user) { session_destroy(); header("Location: ../login.php"); exit(); }

$isAlumni = $user['role'] === 'alumni';
$role     = $user['role'];

/* ── Unread notifications ───────────────────────────────── */
$nStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
$nStmt->bind_param("i", $userId); $nStmt->execute();
$unreadCount = (int)$nStmt->get_result()->fetch_assoc()['c']; $nStmt->close();

/* ── Handle POST ────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !isset($_POST['ajax_mark_read'])
    && !isset($_POST['ajax_mark_all_read'])) {

    $action = $_POST['action'] ?? '';

    /* ── Upload profile picture ── */
    if ($action === 'upload_picture' && $hasProfilePic) {
        if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
            $error = "No file uploaded or upload failed.";
        } else {
            $file    = $_FILES['profile_picture'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime    = mime_content_type($file['tmp_name']);

            if (!isset($allowed[$mime])) {
                $error = "Only JPG, PNG, or WEBP images are allowed.";
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $error = "Image must be under 2 MB.";
            } else {
                $dir = __DIR__ . '/../uploads/profile/';
                if (!is_dir($dir)) @mkdir($dir, 0777, true);

                $fn  = 'user_' . $userId . '_' . time() . '.' . $allowed[$mime];
                if (move_uploaded_file($file['tmp_name'], $dir . $fn)) {
                    // Delete old picture
                    if (!empty($user['profile_picture'])) {
                        $old = $dir . basename($user['profile_picture']);
                        if (is_file($old)) @unlink($old);
                    }
                    $rel = 'uploads/profile/' . $fn;
                    $s = $conn->prepare("UPDATE users SET profile_picture=? WHERE id=?");
                    $s->bind_param("si", $rel, $userId); $s->execute(); $s->close();
                    $user['profile_picture'] = $rel;
                    $success = "Profile picture updated successfully.";
                } else {
                    $error = "Failed to save image. Please try again.";
                }
            }
        }
        $qs = $success ? '?msg=' . urlencode($success) : '?err=' . urlencode($error);
        header("Location: profile.php$qs"); exit();
    }

    /* ── Remove profile picture ── */
    if ($action === 'remove_picture' && $hasProfilePic) {
        if (!empty($user['profile_picture'])) {
            $old = __DIR__ . '/../' . $user['profile_picture'];
            if (is_file($old)) @unlink($old);
        }
        $s = $conn->prepare("UPDATE users SET profile_picture=NULL WHERE id=?");
        $s->bind_param("i", $userId); $s->execute(); $s->close();
        $user['profile_picture'] = null;
        $success = "Profile picture removed.";
        header("Location: profile.php?msg=" . urlencode($success)); exit();
    }

    /* ── Update personal info ── */
    if ($action === 'update_info') {
        $firstName      = trim($_POST['first_name'] ?? '');
        $lastName       = trim($_POST['last_name']  ?? '');
        $phone          = trim($_POST['phone']       ?? '');
        $address        = trim($_POST['address']     ?? '');
        $graduationYear = intval($_POST['graduation_year'] ?? 0);
        $course         = trim($_POST['course']      ?? '');

        if ($firstName === '' || $lastName === '') {
            $error = "First name and last name are required.";
        } else {
            if ($isAlumni) {
                $s = $conn->prepare("UPDATE users SET first_name=?,last_name=?,phone=?,address=?,graduation_year=?,course=? WHERE id=?");
                $s->bind_param("ssssssi", $firstName,$lastName,$phone,$address,$graduationYear,$course,$userId);
            } else {
                $s = $conn->prepare("UPDATE users SET first_name=?,last_name=?,phone=?,address=? WHERE id=?");
                $s->bind_param("ssssi", $firstName,$lastName,$phone,$address,$userId);
            }
            if ($s->execute()) {
                $success = "Profile updated successfully.";
                $_SESSION['user_name'] = $firstName . ' ' . $lastName;
            } else {
                $error = "Failed to update profile.";
            }
            $s->close();
        }
        $qs = $success ? '?msg=' . urlencode($success) : '?err=' . urlencode($error);
        header("Location: profile.php$qs"); exit();
    }

    /* ── Change password ── */
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($current===''||$new===''||$confirm==='') {
            $error = "All password fields are required.";
        } elseif (!password_verify($current, $user['password'])) {
            $error = "Current password is incorrect.";
        } elseif (strlen($new) < 8) {
            $error = "New password must be at least 8 characters.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $s = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $s->bind_param("si", $hash, $userId);
            $success = $s->execute() ? "Password changed successfully." : "Failed to change password.";
            $s->close();
        }
        $qs = $success ? '?msg=' . urlencode($success) : '?err=' . urlencode($error);
        header("Location: profile.php$qs"); exit();
    }
}

/* ── Flash messages ─────────────────────────────────────── */
if (!empty($_GET['msg'])) $success = htmlspecialchars($_GET['msg']);
if (!empty($_GET['err'])) $error   = htmlspecialchars($_GET['err']);

/* ── Re-fetch after updates ─────────────────────────────── */
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i", $userId); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

/* ── Request stats ──────────────────────────────────────── */
$rStmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(status='released') AS released FROM document_requests WHERE user_id=?");
$rStmt->bind_param("i", $userId); $rStmt->execute();
$reqStats = $rStmt->get_result()->fetch_assoc(); $rStmt->close();

/* ── Tracer check ───────────────────────────────────────── */
$tracerDone = false;
if ($isAlumni) {
    $tS = $conn->prepare("SELECT id FROM graduate_tracer WHERE user_id=? LIMIT 1");
    $tS->bind_param("i", $userId); $tS->execute(); $tS->store_result();
    $tracerDone = $tS->num_rows > 0; $tS->close();
}

$conn->close();

function escape($v) { return htmlspecialchars($v ?? ''); }

$roleLabel   = ucfirst($role);
$roleIcon    = ['student'=>'🎓','alumni'=>'👨‍🎓','registrar'=>'📋','admin'=>'🛡️'][$role] ?? '👤';
$currentYear = date('Y');
$joinedDate  = date('M d, Y', strtotime($user['created_at']));
$picUrl      = !empty($user['profile_picture']) ? (SITE_URL . '/' . $user['profile_picture']) : '';
$initial     = strtoupper(substr($user['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — ADFC DocuGo</title>
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
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.75rem; font-weight: 800;
            overflow: hidden;
        }
        .chip-avatar img { width: 100%; height: 100%; object-fit: cover; }

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

        /* Profile Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.2rem;
            align-items: start;
        }

        /* Profile Card */
        .profile-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .profile-card-top {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            padding: 1.8rem 1.2rem 1.4rem;
            text-align: center; color: #fff;
            position: relative;
        }

        /* Avatar */
        .avatar-wrap {
            position: relative;
            width: 88px; height: 88px;
            margin: 0 auto 1rem;
            cursor: pointer;
        }

        .avatar-wrap:hover .avatar-overlay { opacity: 1; }

        .avatar-circle {
            width: 88px; height: 88px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex; align-items: center; justify-content: center;
            font-size: 2.2rem; font-weight: 800;
            border: 3px solid rgba(255,255,255,0.4);
            overflow: hidden; position: relative;
        }

        .avatar-circle img { width: 100%; height: 100%; object-fit: cover; }

        .avatar-overlay {
            position: absolute; inset: 0;
            border-radius: 50%;
            background: rgba(0,0,0,0.45);
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            opacity: 0; transition: opacity 0.2s;
            gap: 2px;
        }

        .avatar-overlay span {
            font-size: 0.65rem; color: #fff; font-weight: 700;
            line-height: 1.2; text-align: center; padding: 0 4px;
        }
        .avatar-overlay .cam-icon { font-size: 1.1rem; }

        #picInput { display: none; }

        .remove-pic-btn {
            display: inline-flex; align-items: center; gap: 3px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.3);
            color: #fff; font-size: 0.68rem; font-weight: 600;
            border-radius: 20px; padding: 3px 9px; cursor: pointer;
            font-family: inherit; transition: background 0.15s;
            margin-top: 0.4rem;
        }
        .remove-pic-btn:hover { background: rgba(255,255,255,0.28); }

        .profile-name { font-size: 1rem; font-weight: 700; margin-bottom: 0.2rem; }
        .profile-email { font-size: 0.75rem; opacity: 0.82; word-break: break-all; }
        .profile-role-chip {
            display: inline-flex; align-items: center; gap: 4px;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            padding: 3px 10px; border-radius: 20px;
            font-size: 0.7rem; font-weight: 700; margin-top: 0.6rem;
        }

        .profile-card-body { padding: 1rem 1.2rem; }

        .info-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.5rem 0; border-bottom: 1px solid var(--border);
            font-size: 0.8rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: var(--text-muted); font-weight: 600; font-size: 0.75rem; }
        .info-value { color: var(--text); font-weight: 500; text-align: right; }

        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px; border-radius: 20px; font-size: 0.68rem; font-weight: 700;
        }
        .badge::before { content:''; width:6px; height:6px; border-radius:50%; }
        .badge-green  { background: rgba(76,217,138,0.15); color: #4cd98a; } .badge-green::before  { background: #4cd98a; }
        .badge-yellow { background: rgba(251,191,36,0.15); color: #fbbf24; } .badge-yellow::before { background: #fbbf24; }
        .badge-red    { background: rgba(248,113,113,0.15); color: #f87171; } .badge-red::before    { background: #f87171; }

        /* Stats */
        .profile-stats {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 0.6rem; margin-top: 0.9rem;
        }
        .profile-stat {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 0.6rem;
            text-align: center;
        }
        .profile-stat-value { font-family: 'Sora', sans-serif; font-size: 1.3rem; font-weight: 800; color: var(--text); line-height: 1; }
        .profile-stat-label { font-size: 0.65rem; color: var(--text-muted); margin-top: 3px; font-weight: 600; }

        /* Tracer Banner */
        .tracer-banner {
            margin-top: 0.9rem;
            background: rgba(59,107,255,0.1);
            border: 1px solid rgba(59,107,255,0.3);
            border-radius: var(--radius-sm);
            padding: 0.6rem 0.8rem;
            font-size: 0.75rem; color: var(--accent2);
            display: flex; align-items: center; gap: 0.5rem;
        }
        .tracer-banner a { color: var(--accent); font-weight: 700; text-decoration: none; margin-left: auto; white-space: nowrap; }
        .tracer-banner a:hover { text-decoration: underline; }
        .tracer-done { background: rgba(76,217,138,0.1); border-color: rgba(76,217,138,0.3); color: var(--green); }

        /* Upload Progress */
        .upload-progress {
            display: none; margin-top: 0.6rem;
            background: rgba(255,255,255,0.2);
            border-radius: 10px; overflow: hidden; height: 4px;
        }
        .upload-progress-bar {
            height: 100%; background: #fff;
            width: 0%; border-radius: 10px;
            transition: width 0.3s ease;
        }
        .upload-status {
            font-size: 0.65rem; color: rgba(255,255,255,0.85);
            margin-top: 4px; font-weight: 600;
        }

        /* Right Panel */
        .right-panel { display: flex; flex-direction: column; gap: 1.2rem; }

        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .section-header {
            padding: 0.9rem 1.2rem;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 0.6rem;
        }
        .section-header h2 { font-family: 'Sora', sans-serif; font-size: 0.9rem; font-weight: 700; color: var(--text); }
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
        .form-input[readonly] { background: var(--surface); color: var(--text-dim); cursor: not-allowed; }
        .form-textarea { resize: vertical; min-height: 75px; }
        .form-hint { font-size: 0.65rem; color: var(--text-dim); margin-top: 0.25rem; }

        .form-footer {
            display: flex; justify-content: flex-end; gap: 0.6rem;
            margin-top: 1rem; padding-top: 0.8rem; border-top: 1px solid var(--border);
        }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem; border: none; border-radius: var(--radius-sm);
            font-size: 0.8rem; cursor: pointer; font-weight: 600; font-family: inherit;
            transition: transform 0.2s;
            display: inline-flex; align-items: center; gap: 0.4rem; white-space: nowrap;
        }
        .btn-primary   { background: linear-gradient(135deg, var(--primary), var(--accent)); color: #fff; }
        .btn-primary:hover   { transform: translateY(-1px); opacity: 0.95; }
        .btn-secondary { background: var(--surface); color: var(--text-muted); border: 1px solid var(--border); }
        .btn-secondary:hover { background: var(--surface-hv); border-color: var(--accent); }
        .btn-danger    { background: rgba(248,113,113,0.15); color: var(--red); border: 1px solid rgba(248,113,113,0.3); }
        .btn-danger:hover    { background: rgba(248,113,113,0.25); }

        /* Password strength */
        .strength-wrap { margin-top: 0.35rem; }
        .strength-bar-bg { height: 4px; border-radius: 4px; background: var(--border); overflow: hidden; }
        .strength-bar    { height: 100%; border-radius: 4px; width: 0%; transition: width 0.3s, background 0.3s; }
        .strength-text   { font-size: 0.7rem; font-weight: 700; margin-top: 3px; color: var(--text-dim); }

        @media (max-width: 1024px) { .profile-grid { grid-template-columns: 260px 1fr; } }
        @media (max-width: 820px) {
            .profile-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
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
                <div class="brand-sub"><?= $roleLabel ?> Portal</div>
            </div>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-section">MAIN</div>
        <a href="dashboard.php" class="menu-item"><span class="menu-icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="menu-icon">📄</span> Request Document</a>
        <a href="my_requests.php" class="menu-item"><span class="menu-icon">📋</span> My Requests</a>
        <a href="notifications.php" class="menu-item"><span class="menu-icon">🔔</span> Notifications</a>

        <?php if ($isAlumni): ?>
        <div class="menu-section">ALUMNI</div>
        <a href="graduate_tracer.php" class="menu-item"><span class="menu-icon">📊</span> Graduate Tracer</a>
        <a href="employment_profile.php" class="menu-item"><span class="menu-icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item"><span class="menu-icon">🎓</span> Alumni Documents</a>
        <?php endif; ?>

        <div class="menu-section">ACCOUNT</div>
        <a href="profile.php" class="menu-item active"><span class="menu-icon">👤</span> My Profile</a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php"><span class="menu-icon">🚪</span> Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">

    <div class="topbar">
        <h1><i class="fas fa-user-circle"></i> My Profile</h1>
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
                <div class="chip-avatar">
                    <?php if ($picUrl): ?>
                        <img src="<?= escape($picUrl) ?>?v=<?= time() ?>" alt="">
                    <?php else: ?>
                        <?= $initial ?>
                    <?php endif; ?>
                </div>
                <strong><?= escape($user['first_name'] . ' ' . $user['last_name']) ?></strong>
            </div>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $success ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= $error ?></div><?php endif; ?>

    <div class="profile-grid">

        <!-- Left: Profile Card -->
        <div>
            <div class="profile-card">
                <div class="profile-card-top">

                    <?php if ($hasProfilePic): ?>
                    <form id="picForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_picture">
                        <input type="file" id="picInput" name="profile_picture" accept="image/jpeg,image/png,image/webp">
                    </form>

                    <div class="avatar-wrap" onclick="document.getElementById('picInput').click()" title="Click to change photo">
                        <div class="avatar-circle" id="avatarCircle">
                            <?php if ($picUrl): ?>
                                <img src="<?= escape($picUrl) ?>?v=<?= time() ?>" alt="Profile" id="avatarImg">
                            <?php else: ?>
                                <span id="avatarInitial"><?= $initial ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="avatar-overlay">
                            <span class="cam-icon"><i class="fas fa-camera"></i></span>
                            <span>Change photo</span>
                        </div>
                    </div>

                    <div class="upload-progress" id="uploadProgress">
                        <div class="upload-progress-bar" id="uploadBar"></div>
                    </div>
                    <div class="upload-status" id="uploadStatus"></div>

                    <?php else: ?>
                    <div style="width:88px;height:88px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;font-size:2.2rem;margin:0 auto 1rem;border:3px solid rgba(255,255,255,0.4);">
                        <?= $initial ?>
                    </div>
                    <?php endif; ?>

                    <div class="profile-name"><?= escape($user['first_name'] . ' ' . $user['last_name']) ?></div>
                    <div class="profile-email"><i class="fas fa-envelope"></i> <?= escape($user['email']) ?></div>
                    <div class="profile-role-chip"><?= $roleIcon ?> <?= $roleLabel ?></div>

                    <?php if ($hasProfilePic && $picUrl): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="remove_picture">
                        <button type="submit" class="remove-pic-btn" onclick="return confirm('Remove your profile picture?')">
                            <i class="fas fa-trash"></i> Remove photo
                        </button>
                    </form>
                    <?php endif; ?>

                </div>

                <div class="profile-card-body">
                    <?php if (!empty($user['student_id'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-id-card"></i> Student ID</span>
                        <span class="info-value"><?= escape($user['student_id']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($isAlumni && !empty($user['graduation_year'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-calendar-alt"></i> Graduated</span>
                        <span class="info-value"><?= escape($user['graduation_year']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($isAlumni && !empty($user['course'])): ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-graduation-cap"></i> Course</span>
                        <span class="info-value"><?= escape($user['course']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-shield-alt"></i> Account Status</span>
                        <span class="info-value">
                            <?php $sc = ['active'=>'badge-green','pending'=>'badge-yellow','inactive'=>'badge-red'][$user['status']] ?? 'badge-gray'; ?>
                            <span class="badge <?= $sc ?>"><?= ucfirst($user['status']) ?></span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><i class="fas fa-calendar"></i> Member Since</span>
                        <span class="info-value"><?= $joinedDate ?></span>
                    </div>

                    <div class="profile-stats">
                        <div class="profile-stat">
                            <div class="profile-stat-value"><?= intval($reqStats['total']) ?></div>
                            <div class="profile-stat-label">Total Requests</div>
                        </div>
                        <div class="profile-stat">
                            <div class="profile-stat-value"><?= intval($reqStats['released']) ?></div>
                            <div class="profile-stat-label">Released</div>
                        </div>
                    </div>

                    <?php if ($isAlumni): ?>
                        <?php if ($tracerDone): ?>
                            <div class="tracer-banner tracer-done"><i class="fas fa-check-circle"></i> Tracer survey completed<a href="graduate_tracer.php"><i class="fas fa-arrow-right"></i> View →</a></div>
                        <?php else: ?>
                            <div class="tracer-banner"><i class="fas fa-chart-line"></i> Complete your tracer survey<a href="graduate_tracer.php"><i class="fas fa-arrow-right"></i> Start →</a></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right: Edit Forms -->
        <div class="right-panel">

            <!-- Personal Information -->
            <div class="section-card">
                <div class="section-header">
                    <span class="section-icon"><i class="fas fa-edit"></i></span>
                    <h2>Personal Information</h2>
                </div>
                <div class="section-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_info">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-input" value="<?= escape($user['first_name']) ?>" required maxlength="80">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-input" value="<?= escape($user['last_name']) ?>" required maxlength="80">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-input" value="<?= escape($user['email']) ?>" readonly>
                                <div class="form-hint"><i class="fas fa-info-circle"></i> Email cannot be changed</div>
                            </div>
                            <?php if (!empty($user['student_id'])): ?>
                            <div class="form-group">
                                <label class="form-label">Student ID</label>
                                <input type="text" class="form-input" value="<?= escape($user['student_id']) ?>" readonly>
                                <div class="form-hint">ID cannot be changed</div>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone Number</label>
                                <input type="text" id="phone" name="phone" class="form-input" value="<?= escape($user['phone'] ?? '') ?>" maxlength="20" placeholder="e.g. 09171234567">
                            </div>
                            <?php if ($isAlumni): ?>
                            <div class="form-group">
                                <label class="form-label" for="graduation_year">Graduation Year</label>
                                <select id="graduation_year" name="graduation_year" class="form-select">
                                    <option value="">— Select Year —</option>
                                    <?php for ($y = $currentYear; $y >= 1990; $y--): ?>
                                        <option value="<?= $y ?>" <?= ($user['graduation_year'] ?? '') == $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group full">
                                <label class="form-label" for="course">Course / Program</label>
                                <input type="text" id="course" name="course" class="form-input" value="<?= escape($user['course'] ?? '') ?>" maxlength="120" placeholder="e.g. Bachelor of Science in Information Technology">
                            </div>
                            <?php endif; ?>
                            <div class="form-group full">
                                <label class="form-label" for="address">Home Address</label>
                                <textarea id="address" name="address" class="form-textarea" placeholder="Enter your complete address…" maxlength="300"><?= escape($user['address'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="section-card">
                <div class="section-header">
                    <span class="section-icon"><i class="fas fa-lock"></i></span>
                    <h2>Change Password</h2>
                </div>
                <div class="section-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-grid">
                            <div class="form-group full">
                                <label class="form-label" for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="form-input" placeholder="Enter your current password" autocomplete="current-password">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="form-input" placeholder="At least 8 characters" autocomplete="new-password" oninput="checkStrength(this.value)">
                                <div class="strength-wrap">
                                    <div class="strength-bar-bg">
                                        <div class="strength-bar" id="strengthBar"></div>
                                    </div>
                                    <div class="strength-text" id="strengthText"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="form-input" placeholder="Re-enter new password" autocomplete="new-password" oninput="checkMatch()">
                                <div class="form-hint" id="matchHint"></div>
                            </div>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Change Password</button>
                        </div>
                    </form>
                </div>
            </div>

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

// Profile picture upload
<?php if ($hasProfilePic): ?>
const picInput = document.getElementById('picInput');
const picForm = document.getElementById('picForm');
const bar = document.getElementById('uploadBar');
const progress = document.getElementById('uploadProgress');
const status = document.getElementById('uploadStatus');
const avatarCircle = document.getElementById('avatarCircle');

picInput.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    const allowed = ['image/jpeg','image/png','image/webp'];
    if (!allowed.includes(file.type)) {
        showStatus('❌ Only JPG, PNG, or WEBP allowed', '#fee2e2');
        picInput.value = ''; return;
    }
    if (file.size > 2 * 1024 * 1024) {
        showStatus('❌ Image must be under 2 MB', '#fee2e2');
        picInput.value = ''; return;
    }

    // Preview instantly
    const reader = new FileReader();
    reader.onload = function(e) {
        avatarCircle.innerHTML = `<img src="${e.target.result}" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
        const chipAvatar = document.querySelector('.chip-avatar');
        if (chipAvatar) chipAvatar.innerHTML = `<img src="${e.target.result}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
    };
    reader.readAsDataURL(file);

    progress.style.display = 'block';
    showStatus('⬆️ Uploading…', 'rgba(255,255,255,0.85)');

    const formData = new FormData(picForm);
    const xhr = new XMLHttpRequest();

    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            bar.style.width = pct + '%';
        }
    });

    xhr.addEventListener('load', function() {
        if (xhr.status === 200 && xhr.responseURL) {
            bar.style.width = '100%';
            showStatus('✅ Uploaded!', 'rgba(255,255,255,0.9)');
            setTimeout(() => { window.location.href = 'profile.php?msg=' + encodeURIComponent('Profile picture updated successfully.'); }, 600);
        }
    });

    xhr.addEventListener('error', function() {
        showStatus('❌ Upload failed', '#fee2e2');
        progress.style.display = 'none';
    });

    xhr.open('POST', 'profile.php');
    xhr.send(formData);
});

function showStatus(msg, color) {
    status.textContent = msg;
    status.style.color = color;
}
<?php endif; ?>

// Password strength
function checkStrength(val) {
    const bar = document.getElementById('strengthBar');
    const text = document.getElementById('strengthText');
    let score = 0;
    if (val.length >= 8) score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const lvls = [
        {w:'0%',  c:'#e5e7eb',t:'',          tc:'#9ca3af'},
        {w:'25%', c:'#dc2626',t:'Weak',       tc:'#dc2626'},
        {w:'50%', c:'#f59e0b',t:'Fair',       tc:'#f59e0b'},
        {w:'75%', c:'#3b82f6',t:'Good',       tc:'#3b82f6'},
        {w:'100%',c:'#059669',t:'Strong ✅',  tc:'#059669'},
    ];
    const l = lvls[Math.min(score,5)];
    bar.style.width = val.length ? l.w : '0%';
    bar.style.background = l.c;
    text.textContent = val.length ? l.t : '';
    text.style.color = l.tc;
    checkMatch();
}

function checkMatch() {
    const np = document.getElementById('new_password').value;
    const cp = document.getElementById('confirm_password').value;
    const hint = document.getElementById('matchHint');
    if (!cp.length) { hint.textContent = ''; return; }
    if (np === cp) { hint.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match'; hint.style.color = '#4cd98a'; }
    else { hint.innerHTML = '<i class="fas fa-times-circle"></i> Passwords do not match'; hint.style.color = '#f87171'; }
}
</script>

</body>
</html>