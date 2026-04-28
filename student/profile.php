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


function e($v) { return htmlspecialchars($v ?? ''); }

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
    <title>My Profile — DocuGo</title>
    <style>
        /* ─── Reset & Base ────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8; color: #111827;
            min-height: 100vh; display: flex;
            font-size: 14px; line-height: 1.5;
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
            padding: 0.6rem 1.2rem; color: rgba(255,255,255,0.82);
            text-decoration: none; font-size: 0.855rem; font-weight: 500;
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
            margin-bottom: 1.6rem; gap: 1rem; flex-wrap: wrap;
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
            opacity: 0; transform: translateY(-6px) scale(0.98); pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .notif-panel.open { opacity: 1; transform: translateY(0) scale(1); pointer-events: all; }
        .notif-panel::before {
            content: ''; position: absolute; top: -7px; right: 13px;
            width: 13px; height: 13px; background: #fff;
            border-left: 1px solid #e5e7eb; border-top: 1px solid #e5e7eb; transform: rotate(45deg);
        }
        .notif-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 0.9rem 1.1rem 0.8rem; border-bottom: 1px solid #f3f4f6; gap: 0.5rem; }
        .notif-panel-title { font-size: 0.92rem; font-weight: 800; color: #111827; display: flex; align-items: center; gap: 0.45rem; }
        .notif-count-pill { background: #1a56db; color: #fff; font-size: 0.62rem; font-weight: 800; padding: 2px 7px; border-radius: 10px; }
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
        .notif-item-icon { width: 34px; height: 34px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
        .type-ready{background:#d1fae5} .type-approved{background:#dbeafe} .type-process{background:#e0e7ff} .type-cancel{background:#fee2e2} .type-info{background:#f3f4f6}
        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title { font-size: 0.815rem; font-weight: 600; color: #374151; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-item.unread .notif-item-title { font-weight: 800; color: #111827; }
        .notif-item-msg { font-size: 0.765rem; color: #6b7280; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .notif-item.unread .notif-item-msg { color: #374151; }
        .notif-item-time { font-size: 0.695rem; color: #9ca3af; margin-top: 3px; }
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
            font-size: 0.75rem; font-weight: 800; flex-shrink: 0; overflow: hidden;
        }
        .chip-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-chip strong { color: #111827; font-weight: 700; }

        /* ─── Alerts ──────────────────────────────────── */
        .alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.2rem; font-size: 0.875rem; font-weight: 500; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #059669; }
        .alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

        /* ─── Profile Grid ────────────────────────────── */
        .profile-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 1.2rem;
            align-items: start;
        }

        /* ─── Left Profile Card ───────────────────────── */
        .profile-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden;
        }

        /* Avatar section */
        .profile-card-top {
            background: linear-gradient(135deg, #1a56db 0%, #1447c0 100%);
            padding: 1.8rem 1.2rem 1.4rem;
            text-align: center; color: #fff;
            position: relative;
        }

        /* ─── Avatar with upload overlay ─────────────── */
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

        /* Hidden file input */
        #picInput { display: none; }

        /* Remove button */
        .remove-pic-btn {
            display: inline-flex; align-items: center; gap: 3px;
            background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.3);
            color: #fff; font-size: 0.68rem; font-weight: 600;
            border-radius: 20px; padding: 3px 9px; cursor: pointer;
            font-family: inherit; transition: background 0.15s;
            margin-top: 0.4rem;
        }
        .remove-pic-btn:hover { background: rgba(255,255,255,0.28); }

        .profile-name { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.2rem; }
        .profile-email { font-size: 0.78rem; opacity: 0.82; word-break: break-all; }
        .profile-role-chip {
            display: inline-flex; align-items: center; gap: 4px;
            background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3);
            padding: 3px 10px; border-radius: 20px;
            font-size: 0.72rem; font-weight: 700; margin-top: 0.6rem;
        }

        .profile-card-body { padding: 1.1rem 1.2rem; }

        .info-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.55rem 0; border-bottom: 1px solid #f3f4f6; font-size: 0.835rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #6b7280; font-weight: 600; font-size: 0.78rem; }
        .info-value { color: #111827; font-weight: 500; text-align: right; }

        /* Badges */
        .badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 700;
        }
        .badge::before { content:''; width:6px; height:6px; border-radius:50%; }
        .badge-green  { background:#d1fae5; color:#065f46; } .badge-green::before  { background:#059669; }
        .badge-yellow { background:#fef3c7; color:#92400e; } .badge-yellow::before { background:#d97706; }
        .badge-red    { background:#fee2e2; color:#991b1b; } .badge-red::before    { background:#dc2626; }

        /* Stats */
        .profile-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; margin-top: 0.9rem; }
        .profile-stat { background: #f9fafb; border-radius: 8px; padding: 0.7rem; text-align: center; }
        .profile-stat-value { font-size: 1.4rem; font-weight: 800; color: #111827; line-height: 1; }
        .profile-stat-label { font-size: 0.7rem; color: #6b7280; margin-top: 3px; font-weight: 600; }

        /* Tracer banner */
        .tracer-banner {
            margin-top: 0.9rem; background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 8px; padding: 0.7rem 0.9rem;
            font-size: 0.8rem; color: #1e40af;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .tracer-banner a { color: #1a56db; font-weight: 700; text-decoration: none; margin-left: auto; white-space: nowrap; }
        .tracer-banner a:hover { text-decoration: underline; }
        .tracer-done { background: #f0fdf4; border-color: #bbf7d0; color: #065f46; }

        /* ─── Upload Progress Bar ─────────────────────── */
        .upload-progress {
            display: none;
            margin-top: 0.6rem;
            background: rgba(255,255,255,0.2);
            border-radius: 10px; overflow: hidden; height: 5px;
        }
        .upload-progress-bar {
            height: 100%; background: #fff;
            width: 0%; border-radius: 10px;
            transition: width 0.3s ease;
        }
        .upload-status {
            font-size: 0.7rem; color: rgba(255,255,255,0.85);
            margin-top: 4px; font-weight: 600;
        }

        /* ─── Right Panel ─────────────────────────────── */
        .right-panel { display: flex; flex-direction: column; gap: 1.2rem; }

        .section-card {
            background: #fff; border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden;
        }
        .section-header {
            padding: 0.9rem 1.2rem; border-bottom: 1px solid #f3f4f6;
            display: flex; align-items: center; gap: 0.6rem;
        }
        .section-header h2 { font-size: 1rem; font-weight: 700; color: #111827; }
        .section-icon { font-size: 1.1rem; }
        .section-body { padding: 1.2rem; }

        /* ─── Form ────────────────────────────────────── */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }
        .form-label { font-size: 0.78rem; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.04em; }
        .form-input, .form-select, .form-textarea {
            padding: 0.55rem 0.8rem; border: 1px solid #d1d5db; border-radius: 7px;
            font-size: 0.875rem; color: #111827; background: #fff; font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s; width: 100%;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: #1a56db; box-shadow: 0 0 0 3px rgba(26,86,219,0.1);
        }
        .form-input[readonly] { background: #f9fafb; color: #6b7280; cursor: not-allowed; }
        .form-textarea { resize: vertical; min-height: 75px; }
        .form-hint { font-size: 0.73rem; color: #9ca3af; }

        .form-footer {
            display: flex; justify-content: flex-end; gap: 0.6rem;
            margin-top: 1.2rem; padding-top: 1rem; border-top: 1px solid #f3f4f6;
        }

        /* Buttons */
        .btn {
            padding: 0.55rem 1.1rem; border: none; border-radius: 7px;
            font-size: 0.855rem; cursor: pointer; font-weight: 600; font-family: inherit;
            transition: background 0.15s;
            display: inline-flex; align-items: center; gap: 0.4rem; white-space: nowrap;
        }
        .btn-primary   { background: #1a56db; color: #fff; }
        .btn-primary:hover   { background: #1447c0; }
        .btn-secondary { background: #f1f5f9; color: #374151; border: 1px solid #e2e8f0; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger    { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .btn-danger:hover    { background: #fecaca; }

        /* Password strength */
        .strength-wrap { margin-top: 0.35rem; }
        .strength-bar-bg { height: 4px; border-radius: 4px; background: #f3f4f6; overflow: hidden; }
        .strength-bar    { height: 100%; border-radius: 4px; width: 0%; transition: width 0.3s, background 0.3s; }
        .strength-text   { font-size: 0.73rem; font-weight: 700; margin-top: 3px; color: #9ca3af; }

        /* ─── Responsive ──────────────────────────────── */
        @media (max-width: 1024px) { .profile-grid { grid-template-columns: 260px 1fr; } }
        @media (max-width: 820px) {
            .profile-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
        }
        @media (max-width: 768px) {
            .main { margin-left: 0; padding: 1rem; }
            .sidebar { display: none; }
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
        <small><?= $roleLabel ?> Portal</small>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="dashboard.php"    class="menu-item"><span class="icon">🏠</span> Dashboard</a>
        <a href="request_form.php" class="menu-item"><span class="icon">📄</span> Request Document</a>
        <a href="my_requests.php"  class="menu-item"><span class="icon">📋</span> My Requests</a>
        <?php if ($isAlumni): ?>
        <div class="menu-label">Alumni</div>
        <a href="graduate_tracer.php" class="menu-item">
            <span class="icon">📊</span> Graduate Tracer
        </a>
        <a href="employment_profile.php" class="menu-item"><span class="icon">💼</span> Employment Profile</a>
        <a href="alumni_documents.php" class="menu-item">
            <span class="icon">🎓</span> Alumni Documents
        </a>
        <?php endif; ?>
        <div class="menu-label">Account</div>
        <a href="profile.php" class="menu-item active"><span class="icon">👤</span> My Profile</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- ─── Main ────────────────────────────────────────────── -->
<main class="main">

    <!-- Topbar -->
    <div class="topbar">
        <h1>👤 My Profile</h1>
        <div class="topbar-right">

            <!-- 🔔 Notification Bell -->
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-btn <?= $unreadCount > 0 ? 'has-unread' : '' ?>"
                        id="notifBtn" onclick="togglePanel(event)" title="Notifications">
                    🔔
                    <span class="notif-badge <?= $unreadCount===0 ? 'hidden' : '' ?>" id="notifBadge">
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

            <!-- User chip (shows pic if available) -->
            <div class="user-chip">
                <div class="chip-avatar">
                    <?php if ($picUrl): ?>
                        <img src="<?= e($picUrl) ?>?v=<?= time() ?>" alt="">
                    <?php else: ?>
                        <?= $initial ?>
                    <?php endif; ?>
                </div>
                <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong>
            </div>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= $error ?></div><?php endif; ?>

    <div class="profile-grid">

        <!-- ── Left: Profile Card ──────────────────────── -->
        <div>
            <div class="profile-card">
                <div class="profile-card-top">

                    <!-- ── Avatar with click-to-upload ── -->
                    <?php if ($hasProfilePic): ?>
                    <form id="picForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_picture">
                        <input type="file" id="picInput" name="profile_picture"
                               accept="image/jpeg,image/png,image/webp">
                    </form>

                    <div class="avatar-wrap" onclick="document.getElementById('picInput').click()"
                         title="Click to change photo">
                        <div class="avatar-circle" id="avatarCircle">
                            <?php if ($picUrl): ?>
                                <img src="<?= e($picUrl) ?>?v=<?= time() ?>"
                                     alt="Profile" id="avatarImg">
                            <?php else: ?>
                                <span id="avatarInitial"><?= $initial ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="avatar-overlay">
                            <span class="cam-icon">📷</span>
                            <span>Change photo</span>
                        </div>
                    </div>

                    <!-- Upload progress -->
                    <div class="upload-progress" id="uploadProgress">
                        <div class="upload-progress-bar" id="uploadBar"></div>
                    </div>
                    <div class="upload-status" id="uploadStatus"></div>

                    <?php else: ?>
                    <!-- No profile_picture column — static avatar -->
                    <div style="width:88px;height:88px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;font-size:2.2rem;margin:0 auto 1rem;border:3px solid rgba(255,255,255,0.4);">
                        <?= $initial ?>
                    </div>
                    <?php endif; ?>

                    <div class="profile-name"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></div>
                    <div class="profile-email"><?= e($user['email']) ?></div>
                    <div class="profile-role-chip"><?= $roleIcon ?> <?= $roleLabel ?></div>

                    <!-- Remove picture button -->
                    <?php if ($hasProfilePic && $picUrl): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="remove_picture">
                        <button type="submit" class="remove-pic-btn"
                                onclick="return confirm('Remove your profile picture?')">
                            🗑 Remove photo
                        </button>
                    </form>
                    <?php endif; ?>

                </div><!-- /profile-card-top -->

                <div class="profile-card-body">
                    <?php if (!empty($user['student_id'])): ?>
                    <div class="info-row">
                        <span class="info-label">Student ID</span>
                        <span class="info-value"><?= e($user['student_id']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($isAlumni && !empty($user['graduation_year'])): ?>
                    <div class="info-row">
                        <span class="info-label">Graduated</span>
                        <span class="info-value"><?= e($user['graduation_year']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($isAlumni && !empty($user['course'])): ?>
                    <div class="info-row">
                        <span class="info-label">Course</span>
                        <span class="info-value" style="max-width:140px;text-align:right;"><?= e($user['course']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label">Account Status</span>
                        <span class="info-value">
                            <?php $sc = ['active'=>'badge-green','pending'=>'badge-yellow','inactive'=>'badge-red'][$user['status']] ?? 'badge-gray'; ?>
                            <span class="badge <?= $sc ?>"><?= ucfirst($user['status']) ?></span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Member Since</span>
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
                            <div class="tracer-banner tracer-done">✅ Tracer survey completed<a href="tracer_survey.php">View →</a></div>
                        <?php else: ?>
                            <div class="tracer-banner">📊 Complete your tracer survey<a href="tracer_survey.php">Start →</a></div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Right: Edit Forms ──────────────────────── -->
        <div class="right-panel">

            <!-- Personal Information -->
            <div class="section-card">
                <div class="section-header">
                    <span class="section-icon">📝</span>
                    <h2>Personal Information</h2>
                </div>
                <div class="section-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_info">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="first_name">First Name</label>
                                <input type="text" id="first_name" name="first_name" class="form-input"
                                       value="<?= e($user['first_name']) ?>" required maxlength="80">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="last_name">Last Name</label>
                                <input type="text" id="last_name" name="last_name" class="form-input"
                                       value="<?= e($user['last_name']) ?>" required maxlength="80">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="form-input" value="<?= e($user['email']) ?>" readonly>
                                <span class="form-hint">Email cannot be changed</span>
                            </div>
                            <?php if (!empty($user['student_id'])): ?>
                            <div class="form-group">
                                <label class="form-label">Student ID</label>
                                <input type="text" class="form-input" value="<?= e($user['student_id']) ?>" readonly>
                                <span class="form-hint">ID cannot be changed</span>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone Number</label>
                                <input type="text" id="phone" name="phone" class="form-input"
                                       value="<?= e($user['phone'] ?? '') ?>" maxlength="20"
                                       placeholder="e.g. 09171234567">
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
                                <input type="text" id="course" name="course" class="form-input"
                                       value="<?= e($user['course'] ?? '') ?>" maxlength="120"
                                       placeholder="e.g. Bachelor of Science in Information Technology">
                            </div>
                            <?php endif; ?>
                            <div class="form-group full">
                                <label class="form-label" for="address">Home Address</label>
                                <textarea id="address" name="address" class="form-textarea"
                                          placeholder="Enter your complete address…" maxlength="300"><?= e($user['address'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="section-card">
                <div class="section-header">
                    <span class="section-icon">🔒</span>
                    <h2>Change Password</h2>
                </div>
                <div class="section-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-grid">
                            <div class="form-group full">
                                <label class="form-label" for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password"
                                       class="form-input" placeholder="Enter your current password"
                                       autocomplete="current-password">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password"
                                       class="form-input" placeholder="At least 8 characters"
                                       autocomplete="new-password" oninput="checkStrength(this.value)">
                                <div class="strength-wrap">
                                    <div class="strength-bar-bg">
                                        <div class="strength-bar" id="strengthBar"></div>
                                    </div>
                                    <div class="strength-text" id="strengthText"></div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password"
                                       class="form-input" placeholder="Re-enter new password"
                                       autocomplete="new-password" oninput="checkMatch()">
                                <div class="form-hint" id="matchHint"></div>
                            </div>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary">🔒 Change Password</button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
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
setInterval(()=>{fetch('dashboard.php?ajax_unread_count=1').then(r=>r.json()).then(d=>{if(typeof d.count==='number'&&d.count!==unreadCount){unreadCount=d.count;syncBadge();}}).catch(()=>{});},60000);

/* ── Profile Picture Upload ──────────────────────────────── */
<?php if ($hasProfilePic): ?>
const picInput  = document.getElementById('picInput');
const picForm   = document.getElementById('picForm');
const bar       = document.getElementById('uploadBar');
const progress  = document.getElementById('uploadProgress');
const status    = document.getElementById('uploadStatus');
const avatarCircle = document.getElementById('avatarCircle');

picInput.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;

    // Validate client-side
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
        // Also update topbar chip
        const chipAvatar = document.querySelector('.chip-avatar');
        if (chipAvatar) chipAvatar.innerHTML = `<img src="${e.target.result}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
    };
    reader.readAsDataURL(file);

    // Upload with progress
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
            // Form submitted normally via redirect — just follow it
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

/* ── Password strength ───────────────────────────────────── */
function checkStrength(val) {
    const bar  = document.getElementById('strengthBar');
    const text = document.getElementById('strengthText');
    let score  = 0;
    if (val.length >= 8)          score++;
    if (val.length >= 12)         score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const lvls = [
        {w:'0%',  c:'#e5e7eb',t:'',          tc:'#9ca3af'},
        {w:'25%', c:'#dc2626',t:'Weak',       tc:'#dc2626'},
        {w:'50%', c:'#f59e0b',t:'Fair',       tc:'#f59e0b'},
        {w:'75%', c:'#3b82f6',t:'Good',       tc:'#3b82f6'},
        {w:'100%',c:'#059669',t:'Strong ✅',  tc:'#059669'},
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
    const np   = document.getElementById('new_password').value;
    const cp   = document.getElementById('confirm_password').value;
    const hint = document.getElementById('matchHint');
    if (!cp.length) { hint.textContent = ''; return; }
    if (np === cp) { hint.textContent = '✅ Passwords match'; hint.style.color = '#059669'; }
    else           { hint.textContent = '❌ Passwords do not match'; hint.style.color = '#dc2626'; }
}
</script>

</body>
</html>
<?php if ($conn && $conn->ping()) $conn->close(); ?>