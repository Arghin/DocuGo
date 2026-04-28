<?php
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

$success = '';
$error   = '';

// ─── Handle POST actions ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ADD new document type
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $fee         = floatval($_POST['fee'] ?? 0);
        $processing  = intval($_POST['processing_days'] ?? 1);

        if ($name === '') {
            $error = "Document name is required.";
        } elseif ($fee < 0) {
            $error = "Fee cannot be negative.";
        } elseif ($processing < 1) {
            $error = "Processing days must be at least 1.";
        } else {
            // Check duplicate name
            $chk = $conn->prepare("SELECT id FROM document_types WHERE name = ?");
            $chk->bind_param("s", $name);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $error = "A document type with that name already exists.";
            } else {
$stmt = $conn->prepare("INSERT INTO document_types (name, description, fee, processing_days, requires_signature, is_active) VALUES (?, ?, ?, ?, 0, 1)");
$stmt->bind_param("ssdi", $name, $description, $fee, $processing);
                if ($stmt->execute()) {
                    $success = "Document type \"" . htmlspecialchars($name) . "\" added successfully.";
                } else {
                    $error = "Failed to add document type.";
                }
                $stmt->close();
            }
            $chk->close();
        }
    }

    // EDIT existing document type
    elseif (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $id          = intval($_POST['doc_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $fee         = floatval($_POST['fee'] ?? 0);
        $processing  = intval($_POST['processing_days'] ?? 1);
        $requiresSignature = isset($_POST['requires_signature']) ? 1 : 0;

        if ($id <= 0 || $name === '') {
            $error = "Invalid data submitted.";
        } elseif ($fee < 0) {
            $error = "Fee cannot be negative.";
        } elseif ($processing < 1) {
            $error = "Processing days must be at least 1.";
        } else {
            // Check duplicate name (excluding self)
            $chk = $conn->prepare("SELECT id FROM document_types WHERE name = ? AND id != ?");
            $chk->bind_param("si", $name, $id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $error = "Another document type with that name already exists.";
            } else {
                $stmt = $conn->prepare("UPDATE document_types SET name=?, description=?, fee=?, processing_days=?, requires_signature=? WHERE id=?");
                $stmt->bind_param("ssdiii", $name, $description, $fee, $processing, $requiresSignature, $id);
                if ($stmt->execute()) {
                    $success = "Document type updated successfully.";
                } else {
                    $error = "Failed to update document type.";
                }
                $stmt->close();
            }
            $chk->close();
        }
    }
    
    // TOGGLE active/inactive
    elseif (isset($_POST['action']) && $_POST['action'] === 'toggle') {
        $id = intval($_POST['doc_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE document_types SET is_active = NOT is_active WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $success = "Document type status updated.";
            } else {
                $error = "Failed to update status.";
            }
            $stmt->close();
        }
    }

    // Redirect to avoid resubmit
    $qs = '';
    if ($success) $qs = '?msg=' . urlencode($success);
    if ($error)   $qs = '?err=' . urlencode($error);
    header("Location: document_types.php$qs");
    exit();
}

// Flash messages from redirect
if (!empty($_GET['msg'])) $success = htmlspecialchars($_GET['msg']);
if (!empty($_GET['err'])) $error   = htmlspecialchars($_GET['err']);

// ─── Fetch all document types ────────────────────────────
$filterActive = $_GET['filter'] ?? 'all';
$allowedFilters = ['all', 'active', 'inactive'];
if (!in_array($filterActive, $allowedFilters)) $filterActive = 'all';

$sql = "SELECT id, name, description, fee, processing_days, is_active, created_at FROM document_types";
if ($filterActive === 'active')   $sql .= " WHERE is_active = 1";
if ($filterActive === 'inactive') $sql .= " WHERE is_active = 0";
$sql .= " ORDER BY is_active DESC, name ASC";

$result = $conn->query($sql);

// Count stats
$countResult = $conn->query("SELECT COUNT(*) AS total, SUM(is_active) AS active_count FROM document_types");
$counts = $countResult->fetch_assoc();
$totalCount  = intval($counts['total']);
$activeCount = intval($counts['active_count']);
$inactiveCount = $totalCount - $activeCount;

function e($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Types — DocuGo Admin</title>
    <style>
        /* ─── Reset & Base ─────────────────────────────── */
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

        /* ─── Sidebar ───────────────────────────────────── */
        .sidebar {
            width: 220px;
            background: #1a56db;
            color: #fff;
            min-height: 100vh;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0;
            height: 100%;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: color 0.15s;
        }

        .sidebar-footer a:hover { color: #fff; }

        /* ─── Main ──────────────────────────────────────── */
        .main { margin-left: 220px; flex: 1; padding: 2rem; min-width: 0; }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.6rem;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .topbar h1 { font-size: 1.35rem; font-weight: 700; color: #111827; }

        .topbar .admin-info { font-size: 0.82rem; color: #6b7280; white-space: nowrap; }
        .topbar .admin-info strong { color: #111827; font-weight: 600; }

        /* ─── Alerts ────────────────────────────────────── */
        .alert {
            padding: 0.85rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #059669; }
        .alert-error   { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }

        /* ─── Stats Row ─────────────────────────────────── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.2rem;
        }

        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 1rem 1.2rem;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 0.9rem;
        }

        .stat-icon {
            font-size: 1.6rem;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            flex-shrink: 0;
        }

        .stat-icon.blue   { background: #eff6ff; }
        .stat-icon.green  { background: #f0fdf4; }
        .stat-icon.red    { background: #fef2f2; }

        .stat-label { font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-value { font-size: 1.5rem; font-weight: 800; color: #111827; line-height: 1.1; }

        /* ─── Toolbar ───────────────────────────────────── */
        .toolbar {
            background: #fff;
            padding: 0.9rem 1.1rem;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .tab-group { display: flex; gap: 0.35rem; flex-wrap: wrap; }

        .tab {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            text-decoration: none;
            background: #f1f5f9;
            color: #475569;
            font-size: 0.78rem;
            font-weight: 600;
            transition: background 0.15s, color 0.15s;
            white-space: nowrap;
        }

        .tab:hover { background: #e2e8f0; color: #334155; }
        .tab.active { background: #1a56db; color: #fff; }

        /* ─── Card + Table ──────────────────────────────── */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .card-header {
            padding: 0.9rem 1.1rem;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .card-header h2 { font-size: 1rem; font-weight: 700; color: #111827; }

        table { width: 100%; border-collapse: collapse; font-size: 0.845rem; }

        thead tr { background: #f9fafb; }

        th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            color: #374151;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafafa; }
        tbody tr.inactive-row { opacity: 0.6; }

        .doc-name { font-weight: 600; color: #111827; font-size: 0.875rem; }
        .doc-desc { font-size: 0.775rem; color: #9ca3af; margin-top: 2px; }

        .fee-value { font-weight: 700; color: #111827; font-size: 0.875rem; }
        .fee-free  { color: #059669; }

        .processing-days {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.82rem;
            color: #6b7280;
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .badge-green  { background: #d1fae5; color: #065f46; }
        .badge-red    { background: #fee2e2; color: #991b1b; }

        .badge-green::before { content:''; width:6px; height:6px; border-radius:50%; background:#059669; }
        .badge-red::before   { content:''; width:6px; height:6px; border-radius:50%; background:#dc2626; }

        /* Buttons */
        .btn {
            padding: 5px 11px;
            border: none;
            border-radius: 6px;
            font-size: 0.78rem;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            font-family: inherit;
            transition: background 0.15s;
            white-space: nowrap;
        }

        .btn-primary   { background: #1a56db; color: #fff; }
        .btn-primary:hover { background: #1447c0; }
        .btn-secondary { background: #f1f5f9; color: #374151; border: 1px solid #e2e8f0; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-success   { background: #d1fae5; color: #065f46; }
        .btn-success:hover { background: #a7f3d0; }
        .btn-danger    { background: #fee2e2; color: #991b1b; }
        .btn-danger:hover { background: #fecaca; }
        .btn-add       { background: #1a56db; color: #fff; font-size: 0.835rem; padding: 0.5rem 1rem; }
        .btn-add:hover { background: #1447c0; }

        .action-group { display: flex; gap: 0.4rem; flex-wrap: wrap; }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3.5rem 1rem;
            color: #9ca3af;
        }
        .empty-state .empty-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .empty-state p { font-size: 0.9rem; font-weight: 500; }

        /* ─── Modal ─────────────────────────────────────── */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal.show { display: flex; }

        .modal-content {
            background: #fff;
            padding: 1.5rem;
            border-radius: 12px;
            width: 100%;
            max-width: 460px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.3rem;
        }

        .modal-header h3 { font-size: 1.05rem; font-weight: 700; color: #111827; }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.1rem;
            cursor: pointer;
            color: #9ca3af;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background 0.15s;
            font-family: inherit;
        }
        .modal-close:hover { background: #f3f4f6; color: #374151; }

        .form-group { margin-bottom: 1rem; }

        .form-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.35rem;
        }

        .form-label .optional {
            font-weight: 400;
            text-transform: none;
            letter-spacing: 0;
            color: #9ca3af;
            font-size: 0.75rem;
        }

        .form-input,
        .form-textarea {
            width: 100%;
            padding: 0.55rem 0.8rem;
            border: 1px solid #d1d5db;
            border-radius: 7px;
            font-size: 0.875rem;
            color: #111827;
            background: #fff;
            font-family: inherit;
            transition: border-color 0.15s;
        }

        .form-input:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #1a56db;
        }

        .form-textarea { resize: vertical; min-height: 70px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }

        .form-hint { font-size: 0.75rem; color: #9ca3af; margin-top: 0.3rem; }

        .modal-buttons {
            margin-top: 1.3rem;
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }

        /* Confirm modal */
        .confirm-modal-content {
            max-width: 360px;
        }

        .confirm-body {
            margin-bottom: 1.2rem;
            font-size: 0.875rem;
            color: #374151;
            line-height: 1.6;
        }

        .confirm-body strong { color: #111827; }

        /* ─── Responsive ────────────────────────────────── */
        @media (max-width: 900px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 768px) {
            .main { margin-left: 0; padding: 1rem; }
            .sidebar { display: none; }
            .stats-row { grid-template-columns: 1fr; }
            .toolbar { flex-direction: column; align-items: stretch; }
            table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        }
    </style>
</head>
<body>

<!-- ─── Sidebar ─────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        DocuGo
        <small>Admin Panel</small>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Dashboard</div>
        <a href="dashboard.php" class="menu-item">
            <span class="icon">🏠</span> Dashboard
        </a>
        <a href="requests.php" class="menu-item">
            <span class="icon">📄</span> Document Requests
        </a>
        <a href="accounts.php" class="menu-item">
            <span class="icon">👥</span> User Accounts
        </a>
        <div class="menu-label">Records</div>
        <a href="alumni.php" class="menu-item">
            <span class="icon">🎓</span> Alumni / Graduates
        </a>
        <a href="tracer.php" class="menu-item">
            <span class="icon">📊</span> Graduate Tracer
        </a>
        <a href="reports.php" class="menu-item">
            <span class="icon">📈</span> Reports
        </a>

        <div class="menu-label">Communication</div>
        <a href="announcements.php" class="menu-item">
            <span class="icon">📢</span> Announcements
        </a>

        <div class="menu-label">Settings</div>
        <a href="document_types.php" class="menu-item active">
            <span class="icon">⚙️</span> Document Types
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- ─── Main ────────────────────────────────────────────── -->
<main class="main">

    <div class="topbar">
        <h1>⚙️ Document Types</h1>
        <div class="admin-info">
            Logged in as <strong><?= e($_SESSION['user_name']) ?></strong>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon blue">📄</div>
            <div>
                <div class="stat-label">Total Types</div>
                <div class="stat-value"><?= $totalCount ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">✅</div>
            <div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?= $activeCount ?></div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red">🔴</div>
            <div>
                <div class="stat-label">Inactive</div>
                <div class="stat-value"><?= $inactiveCount ?></div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
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
        <button class="btn btn-add" onclick="openAddModal()">➕ Add Document Type</button>
    </div>

    <!-- Table Card -->
    <div class="card">
        <div class="card-header">
            <h2>Document Types</h2>
            <span style="font-size:0.8rem;color:#6b7280;"><?= $result->num_rows ?> shown</span>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Document Name</th>
                    <th>Fee</th>
                    <th>Processing</th>
                    <th>Status</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($doc = $result->fetch_assoc()): ?>
                        <tr class="<?= !$doc['is_active'] ? 'inactive-row' : '' ?>">
                            <td>
                                <div class="doc-name"><?= e($doc['name']) ?></div>
                                <?php if (!empty($doc['description'])): ?>
                                    <div class="doc-desc"><?= e($doc['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($doc['fee'] > 0): ?>
                                    <span class="fee-value">₱<?= number_format($doc['fee'], 2) ?></span>
                                <?php else: ?>
                                    <span class="fee-value fee-free">Free</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="processing-days">
                                    🕐 <?= $doc['processing_days'] ?> day<?= $doc['processing_days'] != 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $doc['is_active'] ? 'badge-green' : 'badge-red' ?>">
                                    <?= $doc['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td style="color:#6b7280;font-size:0.82rem;white-space:nowrap;">
                                <?= date('M d, Y', strtotime($doc['created_at'])) ?>
                            </td>
                            <td>
                                <div class="action-group">
                                    <button class="btn btn-secondary"
                                        onclick="openEditModal(<?= $doc['id'] ?>, <?= htmlspecialchars(json_encode($doc['name'])) ?>, <?= htmlspecialchars(json_encode($doc['description'] ?? '')) ?>, <?= $doc['fee'] ?>, <?= $doc['processing_days'] ?>)">
                                        ✏️ Edit
                                    </button>
                                    <button class="btn <?= $doc['is_active'] ? 'btn-danger' : 'btn-success' ?>"
                                        onclick="openToggleModal(<?= $doc['id'] ?>, <?= $doc['is_active'] ?>, <?= htmlspecialchars(json_encode($doc['name'])) ?>)">
                                        <?= $doc['is_active'] ? '🔴 Deactivate' : '✅ Activate' ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <div class="empty-icon">📄</div>
                                <p>No document types found. Add one to get started.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>

<!-- ─── Add Modal ────────────────────────────────────────── -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>➕ Add Document Type</h3>
            <button class="modal-close" onclick="closeModal('addModal')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">

            <div class="form-group">
                <label class="form-label" for="add_name">Document Name</label>
                <input type="text" id="add_name" name="name" class="form-input"
                       placeholder="e.g. Transcript of Records" required maxlength="150">
            </div>

            <div class="form-group">
                <label class="form-label" for="add_desc">
                    Description <span class="optional">(optional)</span>
                </label>
                <textarea id="add_desc" name="description" class="form-textarea"
                          placeholder="Brief description of this document type…" maxlength="500"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="add_fee">Fee (₱)</label>
                    <input type="number" id="add_fee" name="fee" class="form-input"
                           value="0" min="0" step="0.01" required>
                    <div class="form-hint">Enter 0 for free documents</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_days">Processing Days</label>
                    <input type="number" id="add_days" name="processing_days" class="form-input"
                           value="3" min="1" max="90" required>
                    <div class="form-hint">Working days to process</div>
                </div>
            </div>

            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">➕ Add Document Type</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Edit Modal ───────────────────────────────────────── -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>✏️ Edit Document Type</h3>
            <button class="modal-close" onclick="closeModal('editModal')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="doc_id" id="edit_id">

            <div class="form-group">
                <label class="form-label" for="edit_name">Document Name</label>
                <input type="text" id="edit_name" name="name" class="form-input" required maxlength="150">
            </div>

            <div class="form-group">
                <label class="form-label" for="edit_desc">
                    Description <span class="optional">(optional)</span>
                </label>
                <textarea id="edit_desc" name="description" class="form-textarea" maxlength="500"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="edit_fee">Fee (₱)</label>
                    <input type="number" id="edit_fee" name="fee" class="form-input" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="edit_days">Processing Days</label>
                    <input type="number" id="edit_days" name="processing_days" class="form-input" min="1" max="90" required>
                </div>
            </div>

            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">💾 Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ─── Toggle Confirm Modal ─────────────────────────────── -->
<div id="toggleModal" class="modal">
    <div class="modal-content confirm-modal-content">
        <div class="modal-header">
            <h3 id="toggleModalTitle">Confirm Action</h3>
            <button class="modal-close" onclick="closeModal('toggleModal')">✕</button>
        </div>
        <div class="confirm-body" id="toggleModalBody"></div>
        <form method="POST">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="doc_id" id="toggle_id">
            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="closeModal('toggleModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="toggleConfirmBtn">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAddModal() {
        document.getElementById('addModal').classList.add('show');
    }

    function openEditModal(id, name, description, fee, days) {
        document.getElementById('edit_id').value          = id;
        document.getElementById('edit_name').value        = name;
        document.getElementById('edit_desc').value        = description;
        document.getElementById('edit_fee').value         = fee;
        document.getElementById('edit_days').value        = days;
        document.getElementById('editModal').classList.add('show');
    }

    function openToggleModal(id, isActive, name) {
        document.getElementById('toggle_id').value = id;
        if (isActive) {
            document.getElementById('toggleModalTitle').textContent = '🔴 Deactivate Document Type';
            document.getElementById('toggleModalBody').innerHTML =
                'Are you sure you want to <strong>deactivate</strong> <strong>"' + name + '"</strong>?<br><br>' +
                'Students will no longer be able to request this document type.';
            document.getElementById('toggleConfirmBtn').className = 'btn btn-danger';
            document.getElementById('toggleConfirmBtn').textContent = 'Deactivate';
        } else {
            document.getElementById('toggleModalTitle').textContent = '✅ Activate Document Type';
            document.getElementById('toggleModalBody').innerHTML =
                'Are you sure you want to <strong>activate</strong> <strong>"' + name + '"</strong>?<br><br>' +
                'Students will be able to request this document type again.';
            document.getElementById('toggleConfirmBtn').className = 'btn btn-success';
            document.getElementById('toggleConfirmBtn').textContent = 'Activate';
        }
        document.getElementById('toggleModal').classList.add('show');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    // Backdrop click closes any modal
    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });

    // Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(function(m) {
                m.classList.remove('show');
            });
        }
    });
</script>

</body>
</html>
<?php $conn->close(); ?>