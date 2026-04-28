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
    SELECT id, name, description, fee, processing_days
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
        $dtStmt = $conn->prepare("SELECT id, fee FROM document_types WHERE id=? AND is_active=1");
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

        $deliveryAddr = $formData['release_mode'] === 'delivery' ? $formData['delivery_address'] : null;
        $prefDate     = !empty($formData['preferred_release_date']) ? $formData['preferred_release_date'] : null;

        $insStmt = $conn->prepare("
            INSERT INTO document_requests
                (request_code, user_id, document_type_id, purpose, copies,
                 preferred_release_date, release_mode, delivery_address,
                 payment_status, status)
            VALUES (?,?,?,?,?,?,?,?,'unpaid','pending')
        ");
        $insStmt->bind_param("siisissa",
            $requestCode, $userId, $formData['document_type_id'],
            $formData['purpose'], $formData['copies'],
            $prefDate, $formData['release_mode'], $deliveryAddr
        );

        if ($insStmt->execute()) {
            $newId = $conn->insert_id;
            $insStmt->close();
            logRequestAction($conn, $newId, $userId, null, 'pending', 'Request submitted by user.');

            $admins = $conn->query("SELECT id FROM users WHERE role IN ('admin','registrar') AND status='active' LIMIT 5");
            while ($a = $admins->fetch_assoc()) {
                sendNotification($conn, $a['id'],
                    "New document request {$requestCode} submitted by {$user['first_name']} {$user['last_name']}."
                );
            }
            $success  = $requestCode;
            $formData = [];
        } else {
            $insStmt->close();
            $errors[] = 'Failed to submit request. Please try again.';
        }
    }
}

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }

$isAlumni  = ($user['role'] === 'alumni');
$roleLabel = ucfirst($user['role']);
$initial   = strtoupper(substr($user['first_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Document — DocuGo</title>
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
            cursor: pointer;
            font-size: 1.1rem;
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
            position: absolute;
            top: -4px; right: -4px;
            background: #dc2626; color: #fff;
            font-size: 0.6rem; font-weight: 800;
            min-width: 18px; height: 18px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #f0f4f8;
            padding: 0 3px; line-height: 1;
            transition: opacity 0.25s, transform 0.25s;
        }
        .notif-badge.hidden { opacity: 0; transform: scale(0); pointer-events: none; }

        /* ─── Notification Panel ──────────────────────── */
        .notif-panel {
            position: absolute;
            top: calc(100% + 10px); right: 0;
            width: 340px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.13), 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e5e7eb;
            z-index: 500;
            overflow: hidden;
            opacity: 0;
            transform: translateY(-6px) scale(0.98);
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .notif-panel.open { opacity: 1; transform: translateY(0) scale(1); pointer-events: all; }
        .notif-panel::before {
            content: '';
            position: absolute;
            top: -7px; right: 13px;
            width: 13px; height: 13px;
            background: #fff;
            border-left: 1px solid #e5e7eb;
            border-top: 1px solid #e5e7eb;
            transform: rotate(45deg);
        }
        .notif-panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.9rem 1.1rem 0.8rem;
            border-bottom: 1px solid #f3f4f6; gap: 0.5rem;
        }
        .notif-panel-title {
            font-size: 0.92rem; font-weight: 800; color: #111827;
            display: flex; align-items: center; gap: 0.45rem;
        }
        .notif-count-pill {
            background: #1a56db; color: #fff;
            font-size: 0.62rem; font-weight: 800;
            padding: 2px 7px; border-radius: 10px;
            transition: opacity 0.2s;
        }
        .mark-all-btn {
            font-size: 0.75rem; color: #1a56db;
            background: none; border: none; cursor: pointer;
            font-weight: 600; font-family: inherit;
            padding: 4px 8px; border-radius: 6px;
            transition: background 0.15s; white-space: nowrap;
        }
        .mark-all-btn:hover { background: #eff6ff; }
        .notif-list {
            max-height: 300px; overflow-y: auto;
            scrollbar-width: thin; scrollbar-color: #e2e8f0 transparent;
        }
        .notif-list::-webkit-scrollbar { width: 4px; }
        .notif-list::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
        .notif-item {
            display: flex; align-items: flex-start; gap: 0.7rem;
            padding: 0.8rem 1.1rem;
            border-bottom: 1px solid #f9fafb;
            cursor: pointer; transition: background 0.12s;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #f8faff; }
        .notif-item.unread { background: #f0f7ff; }
        .notif-item.unread:hover { background: #e0edff; }
        .notif-item-icon {
            width: 34px; height: 34px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; flex-shrink: 0; margin-top: 1px;
        }
        .type-ready    { background: #d1fae5; }
        .type-approved { background: #dbeafe; }
        .type-process  { background: #e0e7ff; }
        .type-cancel   { background: #fee2e2; }
        .type-info     { background: #f3f4f6; }
        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title {
            font-size: 0.815rem; font-weight: 600; color: #374151;
            margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .notif-item.unread .notif-item-title { font-weight: 800; color: #111827; }
        .notif-item-msg {
            font-size: 0.765rem; color: #6b7280; line-height: 1.45;
            display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
        }
        .notif-item.unread .notif-item-msg { color: #374151; }
        .notif-item-time { font-size: 0.695rem; color: #9ca3af; margin-top: 3px; font-weight: 500; }
        .notif-unread-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #1a56db; flex-shrink: 0; margin-top: 7px;
        }
        .notif-empty { padding: 2rem 1rem; text-align: center; }
        .notif-empty .empty-emoji { font-size: 1.8rem; margin-bottom: 0.4rem; }
        .notif-empty p { font-size: 0.8rem; color: #9ca3af; font-weight: 500; }
        .notif-panel-footer {
            padding: 0.65rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            text-align: center; background: #fafafa;
        }
        .notif-panel-footer a {
            font-size: 0.8rem; color: #1a56db;
            text-decoration: none; font-weight: 700;
        }
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

        /* ─── Alerts ──────────────────────────────────── */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.2rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #059669; }
        .alert ul { padding-left: 1.2rem; }
        .alert ul li { margin-bottom: 2px; font-weight: 400; }

        /* ─── Notice Banner ───────────────────────────── */
        .notice-banner {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 0.85rem 1rem;
            margin-bottom: 1.2rem;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
            font-size: 0.845rem;
            color: #92400e;
        }
        .notice-banner strong { font-weight: 700; display: block; margin-bottom: 2px; }

        /* ─── Section Card ────────────────────────────── */
        .section-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1.1rem;
            overflow: hidden;
        }
        .section-header {
            padding: 0.85rem 1.2rem;
            border-bottom: 1px solid #f3f4f6;
            display: flex; align-items: center; gap: 0.6rem;
        }
        .section-header h3 { font-size: 0.95rem; font-weight: 700; color: #111827; }
        .section-header .step-badge {
            width: 22px; height: 22px; border-radius: 50%;
            background: #1a56db; color: #fff;
            font-size: 0.7rem; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .section-body { padding: 1.2rem; }

        /* ─── Document Type Grid ──────────────────────── */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 0.75rem;
        }
        .doc-card {
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s, box-shadow 0.15s;
            position: relative;
        }
        .doc-card:hover   { border-color: #93c5fd; background: #f0f9ff; box-shadow: 0 2px 8px rgba(26,86,219,0.08); }
        .doc-card.selected { border-color: #1a56db; background: #eff6ff; box-shadow: 0 2px 8px rgba(26,86,219,0.12); }
        .doc-card input[type="radio"] { position: absolute; opacity: 0; }
        .doc-card .doc-name  { font-size: 0.855rem; font-weight: 700; color: #111827; margin-bottom: 4px; }
        .doc-card .doc-fee   { font-size: 1.05rem; font-weight: 800; color: #1a56db; }
        .doc-card .doc-days  { font-size: 0.71rem; color: #9ca3af; margin-top: 3px; }
        .doc-card .doc-desc  { font-size: 0.71rem; color: #6b7280; margin-top: 5px; border-top: 1px solid #f3f4f6; padding-top: 5px; }
        .doc-card.selected .doc-name { color: #1447c0; }

        /* Selected checkmark */
        .doc-card.selected::after {
            content: '✓';
            position: absolute;
            top: 8px; right: 10px;
            font-size: 0.75rem;
            font-weight: 800;
            color: #1a56db;
        }

        /* ─── Form Fields ─────────────────────────────── */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .form-grid .full { grid-column: 1 / -1; }
        .form-group { display: flex; flex-direction: column; gap: 0.3rem; }

        .form-label {
            font-size: 0.78rem; font-weight: 700; color: #374151;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .form-label .req { color: #e11d48; }
        .form-label .opt { color: #9ca3af; font-weight: 400; font-size: 0.72rem; text-transform: none; letter-spacing: 0; }

        .form-input, .form-select, .form-textarea {
            padding: 0.55rem 0.8rem;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font-size: 0.875rem;
            color: #111827;
            background: #fff;
            font-family: inherit;
            transition: border-color 0.15s, box-shadow 0.15s;
            width: 100%;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none; border-color: #1a56db;
            box-shadow: 0 0 0 3px rgba(26,86,219,0.1);
        }
        .form-input[readonly] { background: #f9fafb; color: #6b7280; cursor: not-allowed; }
        .form-textarea { resize: vertical; min-height: 90px; }

        /* ─── Release Mode Tabs ───────────────────────── */
        .mode-tabs { display: flex; gap: 0.75rem; }
        .mode-tab {
            flex: 1; border: 2px solid #e5e7eb;
            border-radius: 10px; padding: 1rem;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
            text-align: center; position: relative;
        }
        .mode-tab:hover  { border-color: #93c5fd; background: #f0f9ff; }
        .mode-tab.selected { border-color: #1a56db; background: #eff6ff; }
        .mode-tab input[type="radio"] { position: absolute; opacity: 0; }
        .mt-icon  { font-size: 1.5rem; margin-bottom: 4px; }
        .mt-title { font-size: 0.875rem; font-weight: 700; color: #111827; }
        .mt-sub   { font-size: 0.72rem; color: #6b7280; margin-top: 2px; }
        .mode-tab.selected .mt-title { color: #1447c0; }
        .delivery-fields { margin-top: 1rem; }

        /* ─── Fee Summary Card ────────────────────────── */
        .fee-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1.1rem;
            overflow: hidden;
        }
        .fee-card-header {
            background: linear-gradient(135deg, #1a56db, #1447c0);
            color: #fff;
            padding: 0.75rem 1.2rem;
            font-size: 0.82rem;
            font-weight: 700;
        }
        .fee-card-body { padding: 1rem 1.2rem; }
        .fee-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.45rem 0;
            font-size: 0.855rem;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
        }
        .fee-row:last-child { border-bottom: none; }
        .fee-row.total {
            padding-top: 0.8rem;
            font-size: 1rem; font-weight: 700; color: #111827;
        }
        .fee-row.total span:last-child { color: #1a56db; font-size: 1.1rem; font-weight: 800; }
        .fee-label { color: #9ca3af; font-size: 0.78rem; }

        /* ─── Submit Button ───────────────────────────── */
        .btn-submit {
            width: 100%;
            padding: 0.85rem;
            background: #1a56db; color: #fff;
            border: none; border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem; font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit:hover { background: #1447c0; }

        /* ─── Success Card ────────────────────────────── */
        .success-wrap {
            max-width: 560px;
            margin: 2rem auto;
        }
        .success-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .success-card .sc-icon { font-size: 3.5rem; margin-bottom: 1rem; }
        .success-card h2 { font-size: 1.2rem; font-weight: 800; color: #111827; margin-bottom: 0.5rem; }
        .success-card p  { font-size: 0.875rem; color: #6b7280; line-height: 1.65; margin-bottom: 1.4rem; }

        .ref-box {
            background: #eff6ff;
            border: 2px dashed #93c5fd;
            border-radius: 10px;
            padding: 1rem; margin-bottom: 1.3rem;
        }
        .ref-label { font-size: 0.75rem; color: #6b7280; margin-bottom: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        .ref-code  {
            font-family: 'Courier New', monospace;
            font-size: 1.45rem; font-weight: 800;
            color: #1a56db; letter-spacing: 2px;
        }

        .steps-flow {
            display: flex; align-items: center; justify-content: center;
            flex-wrap: wrap; gap: 0.2rem;
            background: #f9fafb; border-radius: 8px;
            padding: 0.75rem 1rem; margin-bottom: 1.3rem;
            font-size: 0.75rem; color: #6b7280;
        }
        .steps-flow .step  { font-weight: 700; color: #374151; }
        .steps-flow .arrow { opacity: 0.35; margin: 0 2px; }

        .btn-row { display: flex; gap: 0.6rem; justify-content: center; flex-wrap: wrap; }
        .btn-primary {
            display: inline-block; padding: 0.65rem 1.4rem;
            background: #1a56db; color: #fff;
            border-radius: 8px; text-decoration: none;
            font-size: 0.875rem; font-weight: 600;
            transition: background 0.2s;
        }
        .btn-primary:hover { background: #1447c0; }
        .btn-secondary {
            display: inline-block; padding: 0.65rem 1.4rem;
            background: #f1f5f9; color: #374151;
            border-radius: 8px; text-decoration: none;
            font-size: 0.875rem; font-weight: 600;
            border: 1px solid #e2e8f0;
            transition: background 0.2s;
        }
        .btn-secondary:hover { background: #e2e8f0; }

        /* ─── Responsive ──────────────────────────────── */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .form-grid { grid-template-columns: 1fr; }
            .form-grid .full { grid-column: 1; }
            .mode-tabs { flex-direction: column; }
            .doc-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 500px) {
            .doc-grid { grid-template-columns: 1fr; }
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
        <a href="request_form.php" class="menu-item active"><span class="icon">📄</span> Request Document</a>
        <a href="my_requests.php"  class="menu-item"><span class="icon">📋</span> My Requests</a>

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
        <h1>📄 Request Document</h1>
        <div class="topbar-right">

            <!-- Logout button -->
            <a href="../logout.php" class="logout-btn-top" title="Logout">🚪</a>

            <!-- 🔔 Notification Bell -->
            <div class="notif-wrap" id="notifWrap">
                <button class="notif-btn <?= $unreadCount > 0 ? 'has-unread' : '' ?>"
                        id="notifBtn"
                        onclick="togglePanel(event)"
                        title="Notifications">
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
                            <p>Open dashboard to see<br>your notifications.</p>
                        </div>
                    </div>
                    <div class="notif-panel-footer">
                        <a href="notifications.php">View all notifications →</a>
                    </div>
                </div>
            </div>

            <!-- User chip -->
            <div class="user-chip">
                <div class="chip-avatar"><?= $initial ?></div>
                <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong>
            </div>
        </div>
    </div>

    <?php if (!empty($success)): ?>
    <!-- ══ SUCCESS ══════════════════════════════════════════ -->
    <div class="success-wrap">
        <div class="success-card">
            <div class="sc-icon">🎉</div>
            <h2>Request Submitted!</h2>
            <p>Your request is now pending review by the Registrar's Office.<br>Use your reference number to track its progress.</p>

            <div class="ref-box">
                <div class="ref-label">Your Reference Number</div>
                <div class="ref-code"><?= e($success) ?></div>
            </div>

            <div class="steps-flow">
                <span class="step">✓ Submitted</span><span class="arrow">→</span>
                <span class="step">Admin Review</span><span class="arrow">→</span>
                <span class="step">Processing</span><span class="arrow">→</span>
                <span class="step">Ready</span><span class="arrow">→</span>
                <span class="step">Pay at Cashier</span><span class="arrow">→</span>
                <span class="step">Released</span>
            </div>

            <div class="notice-banner" style="text-align:left;margin-bottom:1.4rem;">
                <span>💰</span>
                <div>
                    <strong>Payment is made upon claiming</strong>
                    Once your document is ready you'll be notified. Go to the Registrar's Office,
                    present your reference number, pay in cash, and claim your document.
                </div>
            </div>

            <div class="btn-row">
                <a href="my_requests.php"  class="btn-primary">📋 Track My Request</a>
                <a href="request_form.php" class="btn-secondary">➕ New Request</a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ══ FORM ══════════════════════════════════════════════ -->

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul><?php foreach ($errors as $e_msg): ?><li><?= e($e_msg) ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <div class="notice-banner">
            <span>💰</span>
            <div>
                <strong>Pay Upon Claiming — No Online Payment Required</strong>
                Submit your request now. Once approved and ready, proceed to the Registrar's Office,
                present your reference number, pay in cash, and claim your document.
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
                        <?php $docTypes->data_seek(0); while ($dt = $docTypes->fetch_assoc()):
                            $sel = ($formData['document_type_id'] ?? 0) == $dt['id'] ? 'selected' : '';
                        ?>
                        <div class="doc-card <?= $sel ?>"
                             onclick="selectDoc(<?= $dt['id'] ?>, <?= $dt['fee'] ?>, '<?= e($dt['name']) ?>')"
                             id="doc-<?= $dt['id'] ?>">
                            <input type="radio" name="document_type_id"
                                   value="<?= $dt['id'] ?>" <?= $sel ? 'checked' : '' ?>>
                            <div class="doc-name"><?= e($dt['name']) ?></div>
                            <div class="doc-fee">₱<?= number_format($dt['fee'], 2) ?></div>
                            <div class="doc-days">⏱ ~<?= $dt['processing_days'] ?> day<?= $dt['processing_days'] != 1 ? 's' : '' ?></div>
                            <?php if ($dt['description']): ?>
                                <div class="doc-desc"><?= e($dt['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endwhile; ?>
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
                            <input type="text" class="form-input" readonly
                                   value="<?= e($user['first_name'] . ' ' . $user['last_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Student / Alumni ID</label>
                            <input type="text" class="form-input" readonly
                                   value="<?= e($user['student_id'] ?? 'Not provided') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="copies">
                                Number of Copies <span class="req">*</span>
                            </label>
                            <input type="number" id="copies" name="copies"
                                   class="form-input"
                                   min="1" max="20"
                                   value="<?= e($formData['copies'] ?? 1) ?>"
                                   oninput="updateFee()" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="pref_date">
                                Preferred Release Date <span class="opt">(optional)</span>
                            </label>
                            <input type="date" id="pref_date" name="preferred_release_date"
                                   class="form-input"
                                   value="<?= e($formData['preferred_release_date'] ?? '') ?>"
                                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                        </div>
                        <div class="form-group full">
                            <label class="form-label" for="purpose">
                                Purpose of Request <span class="req">*</span>
                            </label>
                            <textarea id="purpose" name="purpose" class="form-textarea"
                                      placeholder="e.g. Employment, scholarship application, board exam, transfer of school…"
                                      required><?= e($formData['purpose'] ?? '') ?></textarea>
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
                        <div class="mode-tab <?= ($formData['release_mode'] ?? 'pickup') === 'pickup' ? 'selected' : '' ?>"
                             id="mode-pickup" onclick="setMode('pickup')">
                            <input type="radio" name="release_mode" value="pickup"
                                   <?= ($formData['release_mode'] ?? 'pickup') === 'pickup' ? 'checked' : '' ?>>
                            <div class="mt-icon">🏫</div>
                            <div class="mt-title">Pickup</div>
                            <div class="mt-sub">Claim at the Registrar's Office</div>
                        </div>
                        <div class="mode-tab <?= ($formData['release_mode'] ?? '') === 'delivery' ? 'selected' : '' ?>"
                             id="mode-delivery" onclick="setMode('delivery')">
                            <input type="radio" name="release_mode" value="delivery"
                                   <?= ($formData['release_mode'] ?? '') === 'delivery' ? 'checked' : '' ?>>
                            <div class="mt-icon">🚚</div>
                            <div class="mt-title">Delivery</div>
                            <div class="mt-sub">Have it sent to your address</div>
                        </div>
                    </div>

                    <div class="delivery-fields" id="delivery-fields"
                         style="display:<?= ($formData['release_mode'] ?? '') === 'delivery' ? 'block' : 'none' ?>">
                        <div class="form-group" style="margin-top:0.8rem;">
                            <label class="form-label">Delivery Address <span class="req">*</span></label>
                            <textarea name="delivery_address" class="form-textarea"
                                      placeholder="Complete address for delivery…"><?= e($formData['delivery_address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fee Summary -->
            <div class="fee-card">
                <div class="fee-card-header">💰 Fee Summary — Pay at Cashier upon claiming</div>
                <div class="fee-card-body">
                    <div class="fee-row">
                        <span class="fee-label">Document Type</span>
                        <span id="feeName" style="font-weight:500;color:#374151;">—</span>
                    </div>
                    <div class="fee-row">
                        <span class="fee-label">Unit Fee</span>
                        <span id="feeUnit">—</span>
                    </div>
                    <div class="fee-row">
                        <span class="fee-label">Copies</span>
                        <span id="feeCopies">—</span>
                    </div>
                    <div class="fee-row total">
                        <span>Total Amount Due</span>
                        <span id="feeTotal">Select a document type</span>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit">📤 Submit Request</button>

        </form>

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
        .then(r => r.json())
        .then(renderList)
        .catch(() => {});
}

function markRead(id, el) {
    if (!el.classList.contains('unread')) return;
    fetch(PAGE_URL, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_mark_read=1&notif_id='+id
    }).then(r=>r.json()).then(d=>{
        if(!d.ok) return;
        el.classList.remove('unread');
        const dot = document.getElementById('dot-'+id);
        if(dot) dot.remove();
        unreadCount = Math.max(0, unreadCount-1);
        syncBadge();
    });
}

function markAllRead() {
    fetch(PAGE_URL, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'ajax_mark_all_read=1'
    }).then(r=>r.json()).then(d=>{
        if(!d.ok) return;
        document.querySelectorAll('.notif-item.unread').forEach(el=>el.classList.remove('unread'));
        document.querySelectorAll('.notif-unread-dot').forEach(el=>el.remove());
        unreadCount = 0;
        syncBadge();
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
        list.innerHTML = `<div class="notif-empty"><div class="empty-emoji">🎉</div><p>All caught up!</p></div>`;
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
        let [emoji,cls,title] = ['🔔','type-info','Notification'];
        for(const [k,e,c,t] of iconMap){ if(lm.includes(k)){emoji=e;cls=c;title=t;break;} }
        const isNew = n.is_read==0;
        return `
        <div class="notif-item ${isNew?'unread':''}" id="ni-${n.id}" onclick="markRead(${n.id},this)">
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
function jsAgo(ds){
    const d=Math.floor((Date.now()-new Date(ds).getTime())/1000);
    if(d<60)return 'just now';
    if(d<3600)return Math.floor(d/60)+' min ago';
    if(d<86400)return Math.floor(d/3600)+' hr ago';
    if(d<604800)return Math.floor(d/86400)+' day ago';
    return new Date(ds).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}

setInterval(()=>{
    fetch('dashboard.php?ajax_unread_count=1').then(r=>r.json()).then(d=>{
        if(typeof d.count==='number'&&d.count!==unreadCount){
            unreadCount=d.count; syncBadge();
        }
    }).catch(()=>{});
}, 60000);

/* ── Request form logic ─────────────────────────────── */
let selectedFee  = 0;
let selectedName = '';

function selectDoc(id, fee, name) {
    document.querySelectorAll('.doc-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('doc-' + id).classList.add('selected');
    document.querySelector('input[name="document_type_id"][value="' + id + '"]').checked = true;
    selectedFee  = fee;
    selectedName = name;
    updateFee();
}

function updateFee() {
    const copies = parseInt(document.getElementById('copies').value) || 1;
    if (!selectedFee) {
        ['feeName','feeUnit','feeCopies'].forEach(id => document.getElementById(id).textContent = '—');
        document.getElementById('feeTotal').textContent = 'Select a document type';
        return;
    }
    const total = (selectedFee * copies).toFixed(2);
    document.getElementById('feeName').textContent   = selectedName;
    document.getElementById('feeUnit').textContent   = '₱' + parseFloat(selectedFee).toFixed(2);
    document.getElementById('feeCopies').textContent = copies + (copies > 1 ? ' copies' : ' copy');
    document.getElementById('feeTotal').textContent  = '₱' + parseFloat(total).toLocaleString('en-PH',{minimumFractionDigits:2});
}

function setMode(mode) {
    document.querySelectorAll('.mode-tab').forEach(t => t.classList.remove('selected'));
    document.getElementById('mode-' + mode).classList.add('selected');
    document.querySelector('input[name="release_mode"][value="' + mode + '"]').checked = true;
    document.getElementById('delivery-fields').style.display = mode === 'delivery' ? 'block' : 'none';
}

// Restore fee on validation error
window.addEventListener('DOMContentLoaded', function () {
    <?php if (!empty($formData['document_type_id'])):
        $docTypes->data_seek(0);
        while ($dt = $docTypes->fetch_assoc()):
            if ($dt['id'] == ($formData['document_type_id'] ?? 0)): ?>
    selectedFee  = <?= $dt['fee'] ?>;
    selectedName = '<?= e($dt['name']) ?>';
    updateFee();
            <?php break; endif;
        endwhile;
    endif; ?>
});
</script>

</body>
</html>