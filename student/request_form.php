<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
require_once '../includes/signature_workflow.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit();
}

$conn   = getConnection();
$userId = $_SESSION['user_id'];

/* ── User info ──────────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT first_name, last_name, student_id, course, email, contact_number, role
    FROM users WHERE id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* ── Active document types ──────────────────────────────── */
$docTypes = $conn->query("
    SELECT id, name, description, fee, processing_days, requires_signature
    FROM document_types
    WHERE is_active = 1
    ORDER BY name ASC
");

/* ── Unread notification count ──────────────────────────── */
$nStmt = $conn->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
$nStmt->bind_param("i", $userId);
$nStmt->execute();
$unreadCount = (int)$nStmt->get_result()->fetch_assoc()['c'];
$nStmt->close();

/* ── AJAX: mark read ────────────────────────────────────── */
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

/* ── Form handling ──────────────────────────────────────── */
$errors   = [];
$success  = '';
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_mark_read']) && !isset($_POST['ajax_mark_all_read'])) {
    $formData = [
        'document_type_id'       => intval($_POST['document_type_id'] ?? 0),
        'copies'                 => intval($_POST['copies'] ?? 1),
        'purpose'                => trim($_POST['purpose'] ?? ''),
        'release_mode'           => $_POST['release_mode'] ?? 'pickup',
        'delivery_address'       => trim($_POST['delivery_address'] ?? ''),
        'preferred_release_date' => trim($_POST['preferred_release_date'] ?? ''),
    ];

    if ($formData['document_type_id'] <= 0)                          $errors[] = 'Please select a document type.';
    if ($formData['copies'] < 1 || $formData['copies'] > 20)         $errors[] = 'Copies must be between 1 and 20.';
    if (empty($formData['purpose']))                                  $errors[] = 'Please state the purpose of your request.';
    if (!in_array($formData['release_mode'], ['pickup','delivery']))  $errors[] = 'Invalid release mode.';
    if ($formData['release_mode'] === 'delivery' && empty($formData['delivery_address'])) {
        $errors[] = 'Please provide a delivery address.';
    }

    if (empty($errors)) {
        $dtStmt = $conn->prepare("SELECT id, fee, requires_signature FROM document_types WHERE id=? AND is_active=1");
        $dtStmt->bind_param("i", $formData['document_type_id']);
        $dtStmt->execute();
        $docType = $dtStmt->get_result()->fetch_assoc();
        $dtStmt->close();
        if (!$docType) $errors[] = 'Selected document type is not available.';
    }

    if (empty($errors)) {
        $requestCode = 'DOC-' . date('Y') . '-' . strtoupper(substr(md5(uniqid($userId, true)), 0, 7));
        $checkStmt = $conn->prepare("SELECT id FROM document_requests WHERE request_code=?");
        $checkStmt->bind_param("s", $requestCode);
        $checkStmt->execute(); $checkStmt->store_result();
        while ($checkStmt->num_rows > 0) {
            $requestCode = 'DOC-' . date('Y') . '-' . strtoupper(substr(md5(uniqid($userId, true)), 0, 7));
            $checkStmt->bind_param("s", $requestCode); $checkStmt->execute(); $checkStmt->store_result();
        }
        $checkStmt->close();

        $deliveryAddr = $formData['release_mode'] === 'delivery' ? $formData['delivery_address'] : '';
        $prefDate     = !empty($formData['preferred_release_date']) ? $formData['preferred_release_date'] : '';

        $insStmt = $conn->prepare("
            INSERT INTO document_requests
                (request_code, user_id, document_type_id, purpose, copies,
                 preferred_release_date, release_mode, delivery_address,
                 payment_status, status)
            VALUES (?,?,?,?,?,?,?,?,'unpaid','pending')
        ");
        if (!$insStmt) {
            $errors[] = 'Database error: ' . $conn->error;
        } else {
            $insStmt->bind_param("siisisss",
                $requestCode, $userId, $formData['document_type_id'],
                $formData['purpose'], $formData['copies'],
                $prefDate, $formData['release_mode'], $deliveryAddr
            );

            if ($insStmt->execute()) {
                $requestId = $conn->insert_id;
                $insStmt->close();

                logRequestAction($conn, $requestId, $userId, null, 'pending', 'Request submitted by user.');

                // Get document type requirements
                $checkStmt = $conn->prepare("
                    SELECT requires_signature
                    FROM document_types
                    WHERE id = ?
                ");

                $checkStmt->bind_param("i", $formData['document_type_id']);
                $checkStmt->execute();
                $docTypeInfo = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                if ($docTypeInfo && $docTypeInfo['requires_signature'] == 1) {
                    // Update status to for_signature
                    $upd = $conn->prepare("
                        UPDATE document_requests
                        SET status = 'for_signature'
                        WHERE id = ?
                    ");
                    $upd->bind_param("i", $requestId);
                    $upd->execute();
                    $upd->close();

                    // Create signature workflow using spawnSignatureRows from signature_workflow.php
                    if (function_exists('spawnSignatureRows')) {
                        spawnSignatureRows($conn, $requestId, $formData['document_type_id']);
                    } else {
                        error_log("spawnSignatureRows function not found - check signature_workflow.php");
                    }

                    // Notify signatory offices
                    $officeStaff = $conn->query("
                        SELECT DISTINCT u.id, u.first_name, u.last_name, u.signatory_role
                        FROM users u
                        WHERE u.role = 'signatory'
                        AND u.status = 'active'
                    ");

                    $signLink = SITE_URL . "/signatory/dashboard.php";
                    while ($staff = $officeStaff->fetch_assoc()) {
                        sendNotification($conn, $staff['id'],
                            "✍️ Signature required for document request {$requestCode} from {$user['first_name']} {$user['last_name']}.\n\n" .
                            "📋 Click here to sign: {$signLink}"
                        );
                    }
                } else {
                    // Notify admins/registrar for approval
                    $admins = $conn->query("SELECT id FROM users WHERE role IN ('admin','registrar') AND status='active' LIMIT 5");
                    $adminLink = SITE_URL . "/admin/dashboard.php";
                    while ($a = $admins->fetch_assoc()) {
                        sendNotification($conn, $a['id'],
                            "📄 New document request {$requestCode} submitted by {$user['first_name']} {$user['last_name']}.\n\n" .
                            "📋 Click here to review: {$adminLink}"
                        );
                    }
                }

                $success  = $requestCode;
                $formData = [];
            } else {
                $insStmt->close();
                $errors[] = 'Failed to submit request. Please try again.';
            }
        }
    }
}

// Store document types for later use (after possible connection close)
$docTypesArray = [];
$docTypes->data_seek(0);
while ($dt = $docTypes->fetch_assoc()) {
    $docTypesArray[] = $dt;
}

$conn->close();

function escape($v) { return htmlspecialchars($v ?? ''); }

$isAlumni  = ($user['role'] === 'alumni');
$roleLabel = ucfirst($user['role']);
$initial   = strtoupper(substr($user['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Document — ADFC DocuGo</title>
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
            position: absolute;
            top: -4px; right: -4px;
            background: var(--red);
            color: #fff;
            font-size: 0.6rem; font-weight: 800;
            min-width: 18px; height: 18px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 4px;
        }

        .notif-badge.hidden { display: none; }

        /* Notification Panel */
        .notif-panel {
            position: absolute;
            top: calc(100% + 10px); right: 0;
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
            top: -7px; right: 13px;
            width: 13px; height: 13px;
            background: var(--surface);
            border-left: 1px solid var(--border);
            border-top: 1px solid var(--border);
            transform: rotate(45deg);
        }

        .notif-panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.9rem 1.1rem;
            border-bottom: 1px solid var(--border);
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

        .notif-list {
            max-height: 300px; overflow-y: auto;
        }

        .notif-item {
            display: flex; align-items: flex-start; gap: 0.7rem;
            padding: 0.8rem 1.1rem;
            border-bottom: 1px solid var(--border);
            cursor: pointer; transition: background 0.12s;
        }

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
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
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
        .alert {
            padding: 0.85rem 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.2rem;
            font-size: 0.85rem;
        }
        .alert-error   { background: rgba(248,113,113,0.15); color: var(--red); border-left: 4px solid var(--red); }
        .alert-success { background: rgba(76,217,138,0.15); color: var(--green); border-left: 4px solid var(--green); }
        .alert ul { padding-left: 1.2rem; }

        /* Notice Banner - IMPROVED VISIBILITY */
        .notice-banner {
            background: rgba(255,215,0,0.12);
            border: 1px solid var(--gold);
            border-radius: var(--radius-md);
            padding: 1rem 1.2rem;
            margin-bottom: 1.2rem;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            font-size: 0.85rem;
            backdrop-filter: blur(4px);
        }
        
        body.light .notice-banner {
            background: #fef9e6;
            border: 1px solid #f5b042;
            color: #8a5c00;
        }
        
        .notice-banner span {
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        
        .notice-banner strong {
            font-weight: 700;
            display: block;
            margin-bottom: 4px;
            font-size: 0.9rem;
        }
        
        .notice-banner .notice-content {
            flex: 1;
            color: inherit;
            line-height: 1.5;
        }
        
        body.light .notice-banner .notice-content {
            color: #5a3c00;
        }
        
        .notice-banner .notice-content p {
            margin-bottom: 0;
        }

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
            display: flex; align-items: center; gap: 0.6rem;
        }

        .section-header h3 { font-size: 0.9rem; font-weight: 700; color: var(--text); }
        .section-header .step-badge {
            width: 24px; height: 24px; border-radius: 50%;
            background: var(--accent); color: #fff;
            font-size: 0.7rem; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .section-body { padding: 1.2rem; }

        /* Document Grid */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 0.75rem;
        }

        .doc-card {
            border: 2px solid var(--border);
            border-radius: var(--radius-md);
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .doc-card:hover   { border-color: var(--accent); background: var(--surface-hv); }
        .doc-card.selected { border-color: var(--accent); background: rgba(59,107,255,0.08); }

        .doc-card .doc-name  { font-size: 0.85rem; font-weight: 700; color: var(--text); margin-bottom: 4px; }
        .doc-card .doc-fee   { font-size: 1rem; font-weight: 800; color: var(--accent); }
        .doc-card .doc-days  { font-size: 0.7rem; color: var(--text-dim); margin-top: 3px; }
        .doc-card .doc-desc  { font-size: 0.7rem; color: var(--text-muted); margin-top: 5px; padding-top: 5px; border-top: 1px solid var(--border); }
        .doc-card.selected::after {
            content: '✓';
            position: absolute;
            top: 8px; right: 10px;
            font-size: 0.8rem;
            font-weight: 800;
            color: var(--accent);
        }

        /* Form */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }

        .form-label {
            font-size: 0.7rem; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .form-label .req { color: var(--red); }
        .form-label .opt { color: var(--text-dim); font-weight: 400; font-size: 0.65rem; }

        .form-input, .form-select, .form-textarea {
            padding: 0.6rem 0.8rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            color: var(--text);
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
        .form-textarea { resize: vertical; min-height: 90px; }

        /* Release Mode Tabs */
        .mode-tabs { display: flex; gap: 0.75rem; }
        .mode-tab {
            flex: 1; border: 2px solid var(--border);
            border-radius: var(--radius-md); padding: 0.8rem;
            cursor: pointer; text-align: center; position: relative;
            transition: all 0.2s;
        }
        .mode-tab:hover  { border-color: var(--accent); background: var(--surface-hv); }
        .mode-tab.selected { border-color: var(--accent); background: rgba(59,107,255,0.08); }
        .mt-icon  { font-size: 1.3rem; margin-bottom: 4px; }
        .mt-title { font-size: 0.8rem; font-weight: 700; color: var(--text); }
        .mt-sub   { font-size: 0.65rem; color: var(--text-dim); margin-top: 2px; }

        .delivery-fields { margin-top: 1rem; }

        /* Fee Card */
        .fee-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            margin-bottom: 1.1rem;
            overflow: hidden;
        }

        .fee-card-header {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            padding: 0.75rem 1.2rem;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .fee-card-body { padding: 1rem 1.2rem; }
        .fee-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.5rem 0;
            font-size: 0.8rem;
            border-bottom: 1px solid var(--border);
            color: var(--text-muted);
        }
        .fee-row:last-child { border-bottom: none; }
        .fee-row.total {
            padding-top: 0.8rem;
            font-size: 0.9rem; font-weight: 700; color: var(--text);
        }
        .fee-row.total span:last-child { color: var(--accent); font-size: 1rem; }

        .btn-submit {
            width: 100%;
            padding: 0.85rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.9rem; font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .btn-submit:hover { transform: translateY(-1px); opacity: 0.95; }

        /* Success Screen */
        .success-wrap { max-width: 560px; margin: 2rem auto; }
        .success-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 2rem;
            text-align: center;
        }
        .success-card .sc-icon { font-size: 3rem; margin-bottom: 1rem; }
        .success-card h2 { font-size: 1.1rem; font-weight: 800; color: var(--text); margin-bottom: 0.5rem; }
        .success-card p  { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1.4rem; }

        .ref-box {
            background: rgba(59,107,255,0.1);
            border: 2px dashed var(--accent);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .ref-label { font-size: 0.7rem; color: var(--text-dim); margin-bottom: 4px; }
        .ref-code {
            font-family: monospace;
            font-size: 1.3rem; font-weight: 800;
            color: var(--accent); letter-spacing: 2px;
        }

        .steps-flow {
            display: flex; align-items: center; justify-content: center;
            flex-wrap: wrap; gap: 0.2rem;
            background: var(--bg2);
            border-radius: var(--radius-md);
            padding: 0.6rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.7rem; color: var(--text-muted);
        }

        .btn-row { display: flex; gap: 0.6rem; justify-content: center; flex-wrap: wrap; }
        .btn-primary {
            display: inline-block; padding: 0.6rem 1.2rem;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: #fff;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.8rem; font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-primary:hover { transform: translateY(-1px); }
        .btn-secondary {
            display: inline-block; padding: 0.6rem 1.2rem;
            background: var(--surface);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 0.8rem; font-weight: 600;
            transition: all 0.2s;
        }
        .btn-secondary:hover { background: var(--surface-hv); border-color: var(--accent); }

        @media (max-width: 1024px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 1rem; }
            .mode-tabs { flex-direction: column; }
            .doc-grid { grid-template-columns: 1fr 1fr; }
            .notif-panel { width: 320px; right: -50px; }
        }
        @media (max-width: 500px) {
            .doc-grid { grid-template-columns: 1fr; }
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
        <a href="request_form.php" class="menu-item active"><span class="menu-icon">📄</span> Request Document</a>
        <a href="my_requests.php" class="menu-item"><span class="menu-icon">📋</span> My Requests</a>
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
        <h1>📄 Request Document</h1>
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
                        <div class="notif-empty"><div class="empty-emoji">🔔</div><p>Open dashboard to see notifications.</p></div>
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

    <?php if (!empty($success)): ?>
    <!-- Success Screen -->
    <div class="success-wrap">
        <div class="success-card">
            <div class="sc-icon">🎉</div>
            <h2>Request Submitted!</h2>
            <p>Your request is now pending review by the Registrar's Office.<br>Use your reference number to track its progress.</p>

            <div class="ref-box">
                <div class="ref-label">Your Reference Number</div>
                <div class="ref-code"><?= escape($success) ?></div>
            </div>

            <?php
            $requiresSignature = false;
            $newConn = getConnection();
            $checkDocSig = $newConn->prepare("SELECT dt.requires_signature FROM document_types dt JOIN document_requests dr ON dr.document_type_id = dt.id WHERE dr.request_code = ?");
            $checkDocSig->bind_param("s", $success);
            $checkDocSig->execute();
            $docSigResult = $checkDocSig->get_result()->fetch_assoc();
            $requiresSignature = $docSigResult && $docSigResult['requires_signature'] == 1;
            $checkDocSig->close();
            $newConn->close();
            ?>

            <div class="steps-flow">
                <span class="step">✓ Submitted</span><span class="arrow">→</span>
                <?php if ($requiresSignature): ?>
                    <span class="step">📝 Signatures</span><span class="arrow">→</span>
                <?php endif; ?>
                <span class="step">Processing</span><span class="arrow">→</span>
                <span class="step">Ready</span><span class="arrow">→</span>
                <span class="step">Pay & Claim</span>
            </div>

            <div class="notice-banner">
                <span>💰</span>
                <div class="notice-content">
                    <strong>Payment is made upon claiming</strong>
                    <p>Once your document is ready you'll be notified. Go to the Registrar's Office,
                    present your reference number, pay in cash, and claim your document.</p>
                </div>
            </div>

            <div class="btn-row">
                <a href="my_requests.php" class="btn-primary">📋 Track My Request</a>
                <a href="request_form.php" class="btn-secondary">➕ New Request</a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Request Form -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul><?php foreach ($errors as $e_msg): ?><li><?= escape($e_msg) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <div class="notice-banner">
        <span>💰</span>
        <div class="notice-content">
            <strong>Pay Upon Claiming — No Online Payment Required</strong>
            <p>Submit your request now. Once approved and ready, proceed to the Registrar's Office,
            present your reference number, pay in cash, and claim your document.</p>
        </div>
    </div>

    <form method="POST" action="request_form.php" id="requestForm">

        <!-- Step 1: Document Type -->
        <div class="section-card">
            <div class="section-header">
                <div class="step-badge">1</div>
                <h3>Select Document Type</h3>
            </div>
            <div class="section-body">
                <div class="doc-grid" id="docGrid">
                    <?php foreach ($docTypesArray as $dt): 
                        $sel = ($formData['document_type_id'] ?? 0) == $dt['id'] ? 'selected' : '';
                    ?>
                    <div class="doc-card <?= $sel ?>" onclick="selectDoc(<?= $dt['id'] ?>, <?= $dt['fee'] ?>, '<?= escape($dt['name']) ?>', <?= $dt['requires_signature'] ?>)" id="doc-<?= $dt['id'] ?>">
                        <div class="doc-name"><?= escape($dt['name']) ?></div>
                        <div class="doc-fee">₱<?= number_format($dt['fee'], 2) ?></div>
                        <div class="doc-days">⏱ ~<?= $dt['processing_days'] ?> day<?= $dt['processing_days'] != 1 ? 's' : '' ?></div>
                        <?php if ($dt['requires_signature'] == 1): ?>
                            <div class="doc-days" style="color:var(--gold);">✍️ Requires Signatures</div>
                        <?php endif; ?>
                        <?php if ($dt['description']): ?>
                            <div class="doc-desc"><?= escape($dt['description']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Step 2: Request Details -->
        <div class="section-card">
            <div class="section-header">
                <div class="step-badge">2</div>
                <h3>Request Details</h3>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Your Name</label>
                        <input type="text" class="form-input" readonly value="<?= escape($user['first_name'] . ' ' . $user['last_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Student / Alumni ID</label>
                        <input type="text" class="form-input" readonly value="<?= escape($user['student_id'] ?? 'Not provided') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="copies">Number of Copies <span class="req">*</span></label>
                        <input type="number" id="copies" name="copies" class="form-input" min="1" max="20" value="<?= escape($formData['copies'] ?? 1) ?>" oninput="updateFee()" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="pref_date">Preferred Release Date <span class="opt">(optional)</span></label>
                        <input type="date" id="pref_date" name="preferred_release_date" class="form-input" value="<?= escape($formData['preferred_release_date'] ?? '') ?>" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>
                    <div class="form-group full">
                        <label class="form-label" for="purpose">Purpose of Request <span class="req">*</span></label>
                        <textarea id="purpose" name="purpose" class="form-textarea" placeholder="e.g. Employment, scholarship application, board exam, transfer of school…" required><?= escape($formData['purpose'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Release Mode -->
        <div class="section-card">
            <div class="section-header">
                <div class="step-badge">3</div>
                <h3>Release Mode</h3>
            </div>
            <div class="section-body">
                <div class="mode-tabs">
                    <div class="mode-tab <?= ($formData['release_mode'] ?? 'pickup') === 'pickup' ? 'selected' : '' ?>" id="mode-pickup" onclick="setMode('pickup')">
                        <div class="mt-icon">🏫</div>
                        <div class="mt-title">Pickup</div>
                        <div class="mt-sub">Claim at the Registrar's Office</div>
                    </div>
                    <div class="mode-tab <?= ($formData['release_mode'] ?? '') === 'delivery' ? 'selected' : '' ?>" id="mode-delivery" onclick="setMode('delivery')">
                        <div class="mt-icon">🚚</div>
                        <div class="mt-title">Delivery</div>
                        <div class="mt-sub">Have it sent to your address</div>
                    </div>
                </div>

                <div class="delivery-fields" id="delivery-fields" style="display:<?= ($formData['release_mode'] ?? '') === 'delivery' ? 'block' : 'none' ?>">
                    <div class="form-group" style="margin-top:0.8rem;">
                        <label class="form-label">Delivery Address <span class="req">*</span></label>
                        <textarea name="delivery_address" class="form-textarea" placeholder="Complete address for delivery…"><?= escape($formData['delivery_address'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Summary -->
        <div class="fee-card">
            <div class="fee-card-header">💰 Fee Summary — Pay at Cashier upon claiming</div>
            <div class="fee-card-body">
                <div class="fee-row"><span>Document Type</span><span id="feeName" style="font-weight:500;">—</span></div>
                <div class="fee-row"><span>Unit Fee</span><span id="feeUnit">—</span></div>
                <div class="fee-row"><span>Copies</span><span id="feeCopies">—</span></div>
                <div class="fee-row total"><span>Total Amount Due</span><span id="feeTotal">Select a document type</span></div>
            </div>
        </div>

        <button type="submit" class="btn-submit">📤 Submit Request</button>
    </form>
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

// Request form functions
let selectedFee = 0;
let selectedName = '';

function selectDoc(id, fee, name, requiresSig) {
    document.querySelectorAll('.doc-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('doc-' + id).classList.add('selected');
    selectedFee = fee;
    selectedName = name;
    updateFee();
}

function updateFee() {
    const copies = parseInt(document.getElementById('copies').value) || 1;
    if (!selectedFee) {
        document.getElementById('feeName').textContent = '—';
        document.getElementById('feeUnit').textContent = '—';
        document.getElementById('feeCopies').textContent = '—';
        document.getElementById('feeTotal').textContent = 'Select a document type';
        return;
    }
    const total = (selectedFee * copies).toFixed(2);
    document.getElementById('feeName').textContent = selectedName;
    document.getElementById('feeUnit').textContent = '₱' + parseFloat(selectedFee).toFixed(2);
    document.getElementById('feeCopies').textContent = copies + (copies > 1 ? ' copies' : ' copy');
    document.getElementById('feeTotal').textContent = '₱' + parseFloat(total).toLocaleString('en-PH', {minimumFractionDigits: 2});
}

function setMode(mode) {
    document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('selected'));
    document.getElementById('mode-' + mode).classList.add('selected');
    document.getElementById('delivery-fields').style.display = mode === 'delivery' ? 'block' : 'none';
}

// Restore fee on validation error
window.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($formData['document_type_id'])):
        foreach ($docTypesArray as $dt):
            if ($dt['id'] == ($formData['document_type_id'] ?? 0)): ?>
    selectedFee = <?= $dt['fee'] ?>;
    selectedName = '<?= escape($dt['name']) ?>';
    updateFee();
            <?php break; endif;
        endforeach;
    endif; ?>
});
</script>

</body>
</html>