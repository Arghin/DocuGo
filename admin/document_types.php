<?php
// ============================================================
// admin/document_types.php
// Manage document types. Includes requires_signature toggle.
// ============================================================
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

// Get counts for sidebar badges
$pendingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$pendingAccs = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];

$error = $success = '';

// ── Handle POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $docId       = intval($_POST['doc_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $fee         = floatval($_POST['fee'] ?? 0);
        $days        = intval($_POST['processing_days'] ?? 3);
        $reqSig      = isset($_POST['requires_signature']) ? 1 : 0;
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name)) {
            $error = 'Document name is required.';
        } elseif ($fee < 0) {
            $error = 'Fee cannot be negative.';
        } elseif ($days < 1) {
            $error = 'Processing days must be at least 1.';
        } else {
            if ($action === 'add') {
                // Check duplicate name
                $chk = $conn->prepare("SELECT id FROM document_types WHERE name = ?");
                $chk->bind_param("s", $name);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $error = "A document type with that name already exists.";
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO document_types (name, description, fee, processing_days, requires_signature, is_active)
                        VALUES (?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->bind_param("ssdii", $name, $description, $fee, $days, $reqSig);
                    if ($stmt->execute()) {
                        $success = "Document type \"{$name}\" added successfully.";
                    } else {
                        $error = 'Failed to add document type.';
                    }
                    $stmt->close();
                }
                $chk->close();
            } else {
                // Check duplicate name (excluding self)
                $chk = $conn->prepare("SELECT id FROM document_types WHERE name = ? AND id != ?");
                $chk->bind_param("si", $name, $docId);
                $chk->execute();
                $chk->store_result();
                if ($chk->num_rows > 0) {
                    $error = "Another document type with that name already exists.";
                } else {
                    $stmt = $conn->prepare("
                        UPDATE document_types
                        SET name = ?, description = ?, fee = ?, processing_days = ?,
                            requires_signature = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->bind_param("ssdiiii", $name, $description, $fee, $days, $reqSig, $isActive, $docId);
                    if ($stmt->execute()) {
                        $success = "Document type updated successfully.";
                    } else {
                        $error = 'Update failed.';
                    }
                    $stmt->close();
                }
                $chk->close();
            }
        }
    }

    if ($action === 'delete') {
        $docId = intval($_POST['doc_id'] ?? 0);
        // Safety: prevent delete if requests use this type
        $check = $conn->prepare("SELECT COUNT(*) AS c FROM document_requests WHERE document_type_id = ?");
        $check->bind_param("i", $docId);
        $check->execute();
        $cnt = $check->get_result()->fetch_assoc()['c'];
        $check->close();

        if ($cnt > 0) {
            $error = "Cannot delete: {$cnt} request(s) reference this document type. Deactivate it instead.";
        } else {
            $del = $conn->prepare("DELETE FROM document_types WHERE id = ?");
            $del->bind_param("i", $docId);
            $del->execute();
            $del->close();
            $success = 'Document type deleted.';
        }
    }

    if ($action === 'toggle_active') {
        $docId    = intval($_POST['doc_id'] ?? 0);
        $newState = intval($_POST['new_state'] ?? 0);
        $stmt = $conn->prepare("UPDATE document_types SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newState, $docId);
        $stmt->execute();
        $stmt->close();
        $success = $newState ? 'Document type activated.' : 'Document type deactivated.';
    }
}

// ── Filter by status ──────────────────────────────────────
$filterActive = $_GET['filter'] ?? 'all';
$allowedFilters = ['all', 'active', 'inactive'];
if (!in_array($filterActive, $allowedFilters)) $filterActive = 'all';

// ── Fetch all document types ──────────────────────────────
$sql = "
    SELECT dt.*,
           (SELECT COUNT(*) FROM document_requests dr WHERE dr.document_type_id = dt.id) AS total_requests
    FROM document_types dt
";
if ($filterActive === 'active') $sql .= " WHERE dt.is_active = 1";
if ($filterActive === 'inactive') $sql .= " WHERE dt.is_active = 0";
$sql .= " ORDER BY dt.is_active DESC, dt.name ASC";

$types = $conn->query($sql);

// Count stats
$countResult = $conn->query("SELECT COUNT(*) AS total, SUM(is_active) AS active_count FROM document_types");
$counts = $countResult->fetch_assoc();
$totalCount = intval($counts['total']);
$activeCount = intval($counts['active_count']);
$inactiveCount = $totalCount - $activeCount;

// Fetch single type for edit (GET ?edit=id)
$editDoc = null;
if (isset($_GET['edit'])) {
    $editId  = intval($_GET['edit']);
    $editStmt = $conn->prepare("SELECT * FROM document_types WHERE id = ?");
    $editStmt->bind_param("i", $editId);
    $editStmt->execute();
    $editDoc = $editStmt->get_result()->fetch_assoc();
    $editStmt->close();
}

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }
function ago($datetime) {
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Types — DocuGo Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        /* ── Reset & Base ─────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue:      #1a56db;
            --blue-dk:   #1447c0;
            --blue-lt:   #eff6ff;
            --green:     #059669;
            --green-lt:  #f0fdf4;
            --yellow:    #d97706;
            --yellow-lt: #fffbeb;
            --purple:    #7c3aed;
            --purple-lt: #faf5ff;
            --red:       #dc2626;
            --red-lt:    #fef2f2;
            --bg:        #f0f4f8;
            --card:      #ffffff;
            --border:    #e5e7eb;
            --border-lt: #f3f4f6;
            --text:      #111827;
            --text-2:    #374151;
            --text-3:    #6b7280;
            --text-4:    #9ca3af;
            --sidebar:   220px;
            --shadow:    0 1px 4px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            font-size: 14px;
            line-height: 1.5;
        }

        /* ── Sidebar (matching dashboard) ───────────────── */
        .sidebar {
            width: var(--sidebar);
            background: var(--blue);
            color: #fff;
            min-height: 100vh;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; height: 100%;
            z-index: 100;
            border-right: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand {
            padding: 1.4rem 1.2rem 1.2rem;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin-bottom: 0.2rem;
        }

        .brand-icon {
            width: 34px; height: 34px;
            background: var(--blue);
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(26,86,219,0.4);
        }

        .brand-name {
            font-size: 1.2rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.4px;
        }

        .brand-sub {
            font-size: 0.67rem;
            color: rgba(255,255,255,0.4);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            padding-left: 2.9rem;
        }

        .sidebar-menu { padding: 0.85rem 0; flex: 1; overflow-y: auto; }

        .sidebar-footer {
            padding: 0.9rem 1rem;
            border-top: 1px solid rgba(255,255,255,0.15);
            font-size: 0.8rem;
        }

        .sidebar-footer a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: color 0.15s;
        }

        .sidebar-footer a:hover { color: #fff; }

        .menu-section {
            padding: 0.8rem 1rem 0.2rem;
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255,255,255,0.3);
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.58rem 1rem;
            margin: 1px 0.6rem;
            border-radius: 8px;
            color: rgba(255,255,255,0.6);
            text-decoration: none;
            font-size: 0.845rem;
            font-weight: 500;
            transition: background 0.15s, color 0.15s;
            position: relative;
        }

        .menu-item:hover  { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.9); }
        .menu-item.active { background: rgba(255,255,255,0.15); color: #fff; font-weight: 600; }
        .menu-item.active::before {
            content: '';
            position: absolute;
            left: -0.6rem; top: 50%;
            transform: translateY(-50%);
            width: 3px; height: 20px;
            background: #fff;
            border-radius: 0 3px 3px 0;
        }

        .menu-icon { font-size: 0.95rem; width: 18px; text-align: center; flex-shrink: 0; }
        .menu-badge {
            margin-left: auto;
            background: var(--red);
            color: #fff;
            font-size: 0.6rem;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 8px;
            min-width: 18px;
            text-align: center;
        }
        .menu-badge.yellow { background: var(--yellow); }

        /* ── Main content ──────────────────────────────── */
        .main { margin-left: var(--sidebar); flex: 1; padding: 1.8rem 2rem; min-width: 0; }

        /* ── Topbar ───────────────────────────────────── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.6rem;
            gap: 1rem;
        }
        .topbar-left h1 {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -0.3px;
        }
        .topbar-left p {
            font-size: 0.82rem;
            color: var(--text-3);
            margin-top: 1px;
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .admin-info {
            font-size: 0.85rem;
            background: var(--card);
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            border: 1px solid var(--border);
        }
        .topbar-date {
            font-size: 0.78rem;
            color: var(--text-3);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.4rem 0.85rem;
        }

        /* ── Alert ────────────────────────────────────── */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 10px;
            margin-bottom: 1.2rem;
            font-size: 0.85rem;
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }

        /* ── Stats Cards ──────────────────────────────── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.4rem;
        }
        .stat-card {
            background: var(--card);
            border-radius: 12px;
            padding: 1rem 1.1rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .stat-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }
        .stat-icon.blue   { background: var(--blue-lt); }
        .stat-icon.green  { background: var(--green-lt); }
        .stat-icon.red    { background: var(--red-lt); }
        .stat-info .stat-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
        }
        .stat-info .stat-label {
            font-size: 0.7rem;
            color: var(--text-4);
            font-weight: 500;
            letter-spacing: 0.04em;
        }

        /* ── Layout Grid ──────────────────────────────── */
        .layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 1.2rem;
        }

        /* ── Cards ────────────────────────────────────── */
        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            overflow: hidden;
        }
        .card-padded { padding: 1.2rem; }
        .card-header {
            padding: 0.9rem 1.2rem;
            border-bottom: 1px solid var(--border-lt);
            background: #fafafa;
        }
        .card-header h3 {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
            margin: 0;
        }
        .card-body { padding: 1.2rem; }

        /* ── Form Styles ──────────────────────────────── */
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-2);
            margin-bottom: 0.3rem;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea {
            width: 100%;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.85rem;
            color: var(--text);
            outline: none;
            transition: border-color 0.15s;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(26,86,219,.08);
        }
        .form-group textarea { resize: vertical; min-height: 70px; }

        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        /* Checkbox Group */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .checkbox-group input {
            width: 16px;
            height: 16px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .checkbox-group .cb-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-2);
        }
        .checkbox-group .cb-sub {
            font-size: 0.7rem;
            color: var(--text-4);
            margin-top: 1px;
        }
        .sig-checkbox {
            border-color: #e879f9;
            background: #fdf4ff;
        }
        .sig-checkbox .cb-label { color: #86198f; }
        .sig-checkbox .cb-sub { color: #a21caf; }

        /* Buttons */
        .btn-submit {
            width: 100%;
            padding: 0.7rem;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
            margin-top: 0.5rem;
        }
        .btn-submit:hover { background: var(--blue-dk); }
        .btn-reset {
            width: 100%;
            padding: 0.6rem;
            background: var(--bg);
            color: var(--text-2);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: block;
            margin-top: 0.5rem;
        }
        .btn-reset:hover {
            background: var(--blue-lt);
            border-color: var(--blue);
            color: var(--blue);
        }

        /* Table */
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th {
            text-align: left;
            padding: 0.6rem 1rem;
            background: #fafafa;
            color: var(--text-4);
            font-weight: 700;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1px solid var(--border-lt);
        }
        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-lt);
            color: var(--text-2);
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafbff; }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
        }
        .badge-active   { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .badge-sig      { background: #fce7f3; color: #9d174d; }
        .badge-nosig    { background: #f3f4f6; color: #6b7280; }

        /* Action Buttons */
        .act-btn {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all 0.12s;
            margin-right: 0.3rem;
        }
        .act-btn:hover { filter: brightness(0.95); transform: translateY(-1px); }
        .act-edit     { background: #dbeafe; color: #1e40af; }
        .act-toggle   { background: #fef3c7; color: #92400e; }
        .act-activate { background: #d1fae5; color: #065f46; }
        .act-delete   { background: #fee2e2; color: #991b1b; }

        /* Toolbar */
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1.2rem;
        }
        .tab-group {
            display: flex;
            gap: 0.25rem;
            background: var(--card);
            padding: 0.5rem;
            border-radius: 14px;
            border: 1px solid var(--border-lt);
        }
        .tab {
            padding: 0.5rem 1rem;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-3);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s;
        }
        .tab:hover { background: var(--bg); color: var(--blue); }
        .tab.active { background: var(--blue); color: #fff; }

        .empty-row td { text-align: center; padding: 2rem; color: var(--text-4); }

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .layout { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 700px) {
            .stats-row { grid-template-columns: 1fr; }
            .row-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- Sidebar (identical to dashboard) -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon">📄</div>
            <div class="brand-name">DocuGo</div>
        </div>
        <div class="brand-sub">Admin Panel</div>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-section">Main</div>
        <a href="dashboard.php" class="menu-item">
            <span class="menu-icon">🏠</span> Dashboard
        </a>
        <a href="requests.php" class="menu-item">
            <span class="menu-icon">📄</span> Document Requests
            <?php if ($pendingReqs > 0): ?>
                <span class="menu-badge yellow"><?= $pendingReqs ?></span>
            <?php endif; ?>
        </a>
        <a href="accounts.php" class="menu-item">
            <span class="menu-icon">👥</span> User Accounts
            <?php if ($pendingAccs > 0): ?>
                <span class="menu-badge"><?= $pendingAccs ?></span>
            <?php endif; ?>
        </a>
        <div class="menu-section">Records</div>
        <a href="alumni.php" class="menu-item">
            <span class="menu-icon">🎓</span> Alumni
        </a>
        <a href="tracer.php" class="menu-item">
            <span class="menu-icon">📊</span> Graduate Tracer
        </a>
        <a href="reports.php" class="menu-item">
            <span class="menu-icon">📈</span> Reports
        </a>
        <div class="menu-section">Communication</div>
        <a href="announcements.php" class="menu-item">
            <span class="menu-icon">📢</span> Announcements
        </a>
        <div class="menu-section">Settings</div>
        <a href="document_types.php" class="menu-item active">
            <span class="menu-icon">⚙️</span> Document Types
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- Main Content -->
<main class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>⚙️ Document Types</h1>
            <p>Manage document types, fees, processing times, and signature requirements.</p>
        </div>
        <div class="topbar-right">
            <div class="admin-info">
                <strong><?= e($_SESSION['user_name']) ?></strong>
            </div>
            <div class="topbar-date">
                📅 <?= date('l, F j, Y') ?>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= $success ?></div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon blue">📄</div>
            <div class="stat-info">
                <div class="stat-value"><?= $totalCount ?></div>
                <div class="stat-label">Total Types</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">✅</div>
            <div class="stat-info">
                <div class="stat-value"><?= $activeCount ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red">🔴</div>
            <div class="stat-info">
                <div class="stat-value"><?= $inactiveCount ?></div>
                <div class="stat-label">Inactive</div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="toolbar">
        <div class="tab-group">
            <?php
            $filters = ['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'];
            foreach ($filters as $k => $v):
                $qs = http_build_query(['filter' => $k]);
            ?>
                <a href="?<?= $qs ?>" class="tab <?= $filterActive === $k ? 'active' : '' ?>"><?= $v ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Layout: Form + Table -->
    <div class="layout">
        <!-- Add / Edit Form Card -->
        <div class="card">
            <div class="card-header">
                <h3><?= $editDoc ? '✏️ Edit Document Type' : '➕ Add New Document Type' ?></h3>
            </div>
            <div class="card-body">
                <form method="POST" action="<?= $editDoc ? "document_types.php?edit={$editDoc['id']}" : 'document_types.php' ?>">
                    <input type="hidden" name="action" value="<?= $editDoc ? 'edit' : 'add' ?>">
                    <?php if ($editDoc): ?>
                        <input type="hidden" name="doc_id" value="<?= $editDoc['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label>Document Name <span style="color:#e11d48">*</span></label>
                        <input type="text" name="name"
                               placeholder="e.g. Transcript of Records"
                               value="<?= e($editDoc['name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description"
                                  placeholder="Brief description of this document…"><?= e($editDoc['description'] ?? '') ?></textarea>
                    </div>

                    <div class="row-2">
                        <div class="form-group">
                            <label>Processing Fee (₱) <span style="color:#e11d48">*</span></label>
                            <input type="number" name="fee" step="0.01" min="0"
                                   value="<?= e($editDoc['fee'] ?? '0.00') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Processing Days <span style="color:#e11d48">*</span></label>
                            <input type="number" name="processing_days" min="1" max="365"
                                   value="<?= e($editDoc['processing_days'] ?? '3') ?>" required>
                        </div>
                    </div>

                    <!-- Requires Signature toggle -->
                    <div class="form-group">
                        <label>Signature Requirement</label>
                        <label class="checkbox-group sig-checkbox">
                            <input type="checkbox" name="requires_signature" value="1"
                                   <?= ($editDoc['requires_signature'] ?? 0) ? 'checked' : '' ?>>
                            <div>
                                <div class="cb-label">✍️ Requires Signature</div>
                                <div class="cb-sub">Request must go through "For Signature" status before processing</div>
                            </div>
                        </label>
                    </div>

                    <?php if ($editDoc): ?>
                        <div class="form-group">
                            <label>Status</label>
                            <label class="checkbox-group">
                                <input type="checkbox" name="is_active" value="1"
                                       <?= $editDoc['is_active'] ? 'checked' : '' ?>>
                                <div>
                                    <div class="cb-label">Active</div>
                                    <div class="cb-sub">Inactive types won't appear in the request form</div>
                                </div>
                            </label>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-submit">
                        <?= $editDoc ? '💾 Save Changes' : '+ Add Document Type' ?>
                    </button>

                    <?php if ($editDoc): ?>
                        <a href="document_types.php" class="btn-reset">
                            ✕ Cancel Edit
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Document Types Table -->
        <div class="card">
            <div class="card-header">
                <h3>📋 All Document Types</h3>
                <span style="font-size:0.75rem; color:var(--text-4);"><?= $types->num_rows ?> shown</span>
            </div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Fee</th>
                            <th>Days</th>
                            <th>Signature</th>
                            <th>Status</th>
                            <th>Requests</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($types->num_rows === 0): ?>
                            <tr class="empty-row">
                                <td colspan="7">No document types found. Add one to get started.</td>
                            </tr>
                        <?php else: ?>
                            <?php while ($dt = $types->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;color:var(--text)"><?= e($dt['name']) ?></div>
                                        <?php if ($dt['description']): ?>
                                            <div style="font-size:0.7rem;color:var(--text-4);margin-top:2px">
                                                <?= e(mb_strimwidth($dt['description'], 0, 50, '…')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>₱<?= number_format($dt['fee'], 2) ?></strong></td>
                                    <td><?= $dt['processing_days'] ?> day<?= $dt['processing_days'] > 1 ? 's' : '' ?></td>
                                    <td>
                                        <?php if ($dt['requires_signature']): ?>
                                            <span class="badge badge-sig">✍️ Required</span>
                                        <?php else: ?>
                                            <span class="badge badge-nosig">Not required</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $dt['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= $dt['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= number_format($dt['total_requests']) ?></td>
                                    <td style="white-space:nowrap">
                                        <a href="document_types.php?edit=<?= $dt['id'] ?>" class="act-btn act-edit">Edit</a>

                                        <form method="POST" action="document_types.php" style="display:inline">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="doc_id" value="<?= $dt['id'] ?>">
                                            <input type="hidden" name="new_state" value="<?= $dt['is_active'] ? 0 : 1 ?>">
                                            <button class="act-btn <?= $dt['is_active'] ? 'act-toggle' : 'act-activate' ?>">
                                                <?= $dt['is_active'] ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>

                                        <?php if ($dt['total_requests'] == 0): ?>
                                            <form method="POST" action="document_types.php" style="display:inline"
                                                  onsubmit="return confirm('Delete this document type permanently?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="doc_id" value="<?= $dt['id'] ?>">
                                                <button class="act-btn act-delete">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

</body>
</html>