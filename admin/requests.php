<?php
require_once '../includes/config.php';
require_once '../includes/request_helper.php';
requireAdmin();

$conn   = getConnection();
$userId = $_SESSION['user_id'];

// ── Filters ──────────────────────────────────────────────────
$status  = $_GET['status'] ?? '';
$search  = trim($_GET['q'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

// ── Handle quick status actions (POST) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $requestId = intval($_POST['request_id'] ?? 0);
    $msg = '';
    $msgType = 'error';

    if ($requestId > 0) {
        // For approve action, check if document requires signature
        if ($action === 'approve') {
            $checkStmt = $conn->prepare("
                SELECT dt.requires_signature 
                FROM document_requests dr 
                JOIN document_types dt ON dr.document_type_id = dt.id 
                WHERE dr.id = ?
            ");
            $checkStmt->bind_param("i", $requestId);
            $checkStmt->execute();
            $checkRow = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            
            if ($checkRow && $checkRow['requires_signature']) {
                $newStatus = 'for_signature';
            } else {
                $newStatus = 'approved';
            }
        } else {
            $actionMap = [
                'process'      => 'processing',
                'ready'        => 'ready',
                'for_sign'     => 'for_signature',
                'cancel'       => 'cancelled',
            ];
            $newStatus = $actionMap[$action] ?? null;
        }
        
        if ($newStatus) {
            $result = updateRequestStatus($conn, $requestId, $newStatus, $userId);
            $msg = $result['message'];
            $msgType = $result['success'] ? 'success' : 'error';
        }
    }

    header("Location: requests.php?" . http_build_query([
        'status'  => $status,
        'q'       => $search,
        'page'    => $page,
        'msg'     => $msg,
        'msgtype' => $msgType,
    ]));
    exit();
}

// ── Build query ───────────────────────────────────────────────
$where  = [];
$params = [];
$types  = '';

if ($status !== '') {
    $where[]  = "dr.status = ?";
    $params[] = $status;
    $types   .= 's';
}

if ($search !== '') {
    $like     = "%$search%";
    $where[]  = "(u.first_name LIKE ? OR u.last_name LIKE ? OR dr.request_code LIKE ? OR u.email LIKE ?)";
    $params[] = $like; $params[] = $like;
    $params[] = $like; $params[] = $like;
    $types   .= 'ssss';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countSQL  = "SELECT COUNT(*) AS total FROM document_requests dr JOIN users u ON dr.user_id = u.id $whereSQL";
$countStmt = $conn->prepare($countSQL);
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRows  = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $perPage));
$countStmt->close();

// Fetch
$params[] = $perPage;
$params[] = $offset;
$types   .= 'ii';

$sql  = "
    SELECT dr.*, 
           u.first_name, u.last_name, u.email, u.student_id,
           dt.name AS doc_type, dt.fee,
           (dt.fee * dr.copies) AS total_fee,
           pr.official_receipt_number, pr.payment_date,
           cs.stub_code
    FROM document_requests dr
    JOIN users u  ON dr.user_id = u.id
    JOIN document_types dt ON dr.document_type_id = dt.id
    LEFT JOIN payment_records pr ON dr.id = pr.request_id
    LEFT JOIN claim_stubs cs ON dr.id = cs.request_id
    $whereSQL
    ORDER BY dr.requested_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$requests = $stmt->get_result();
$stmt->close();

// ── Status counts for tabs & stats ───────────────────────────
$tabCounts = [];
$tabResult = $conn->query("SELECT status, COUNT(*) AS c FROM document_requests GROUP BY status");
while ($t = $tabResult->fetch_assoc()) {
    $tabCounts[$t['status']] = $t['c'];
}
$tabCounts['all'] = array_sum($tabCounts);

// Additional stats for sidebar badges
$pendingReqs   = $tabCounts['pending'] ?? 0;
$pendingAccs   = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }
function fd($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }

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
    <title>Document Requests — DocuGo Admin</title>
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

        /* ── Stats mini cards (dashboard style) ────────── */
        .stats-mini {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.4rem;
        }
        .stat-card {
            background: var(--card);
            border-radius: 12px;
            padding: 1rem 1.1rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
        .stat-num { font-size: 1.6rem; font-weight: 800; color: var(--text); line-height: 1; }
        .stat-label { font-size: 0.72rem; color: var(--text-4); font-weight: 500; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.04em; }
        .stat-sub { font-size: 0.7rem; color: var(--text-3); margin-top: 6px; padding-top: 6px; border-top: 1px solid var(--border-lt); }

        /* ── Tabs ─────────────────────────────────────── */
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            background: var(--card);
            padding: 0.5rem;
            border-radius: 14px;
            margin-bottom: 1.2rem;
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
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }
        .tab:hover { background: var(--bg); color: var(--blue); }
        .tab.active { background: var(--blue); color: #fff; }
        .cnt {
            background: rgba(0,0,0,0.05);
            padding: 2px 7px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
        }
        .tab.active .cnt { background: rgba(255,255,255,0.2); }

        /* ── Filters ──────────────────────────────────── */
        .filters {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-bottom: 1.2rem;
        }
        .search-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .search-form input {
            padding: 0.45rem 0.85rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.8rem;
            width: 240px;
        }
        .search-form button, .btn-clear {
            padding: 0.45rem 1rem;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-clear {
            background: var(--text-4);
        }
        .btn-clear:hover { background: var(--text-3); }

        /* ── Card & Table ─────────────────────────────── */
        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            overflow: hidden;
        }
        .card-header {
            padding: 0.9rem 1.2rem;
            border-bottom: 1px solid var(--border-lt);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-header h2 {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
        }

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

        .code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.72rem;
            background: #f3f4f6;
            padding: 2px 6px;
            border-radius: 6px;
        }
        .user-name { font-weight: 600; color: var(--text); font-size: 0.845rem; }
        .user-meta { font-size: 0.7rem; color: var(--text-4); margin-top: 1px; }
        .fee { font-weight: 700; color: var(--green); }

        /* Badges (matching dashboard) */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: capitalize;
            white-space: nowrap;
        }
        .badge::before {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
        }
        .badge-pending    { background: #fef3c7; color: #92400e; } .badge-pending::before    { background: #d97706; }
        .badge-approved   { background: #dbeafe; color: #1e40af; } .badge-approved::before   { background: #3b82f6; }
        .badge-processing { background: #e0f2fe; color: #0369a1; } .badge-processing::before { background: #0ea5e9; }
        .badge-ready      { background: #fef9c3; color: #854d0e; } .badge-ready::before      { background: #eab308; }
        .badge-paid       { background: #d1fae5; color: #065f46; } .badge-paid::before       { background: #10b981; }
        .badge-released   { background: #ede9fe; color: #4c1d95; } .badge-released::before   { background: #8b5cf6; }
        .badge-cancelled  { background: #fee2e2; color: #991b1b; } .badge-cancelled::before  { background: #ef4444; }

        /* Action buttons */
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
        }
        .btn {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.12s;
            font-family: inherit;
        }
        .btn-view      { background: #e0f2fe; color: #0369a1; }
        .btn-approve   { background: #d1fae5; color: #065f46; }
        .btn-process   { background: #dbeafe; color: #1e40af; }
        .btn-ready     { background: #fef9c3; color: #854d0e; }
        .btn-release   { background: #d1fae5; color: #065f46; }
        .btn-stub      { background: #f3e8ff; color: #6b21a5; }
        .btn-cancel    { background: #fee2e2; color: #991b1b; }
        .btn:hover { filter: brightness(0.95); transform: translateY(-1px); }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.3rem;
            margin-top: 1.5rem;
        }
        .pagination a, .pagination span {
            padding: 0.4rem 0.8rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-2);
            font-size: 0.8rem;
        }
        .pagination .active {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }
        .empty-state {
            text-align: center;
            padding: 2.5rem;
            color: var(--text-4);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(2px);
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: 0.2s;
            z-index: 1000;
        }
        .modal-overlay.open {
            visibility: visible;
            opacity: 1;
        }
        .modal {
            background: var(--card);
            border-radius: 20px;
            width: 90%;
            max-width: 460px;
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
        }
        .modal h2 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }
        .field {
            margin: 1rem 0;
        }
        .field label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .field input, .field textarea {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
        }
        .modal-actions {
            display: flex;
            gap: 0.8rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }
        .modal-cancel, .modal-confirm {
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }
        .modal-cancel { background: #f3f4f6; color: #374151; }
        .modal-confirm { background: var(--green); color: #fff; }

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .stats-mini { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 700px) {
            .stats-mini { grid-template-columns: 1fr 1fr; }
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
        <a href="requests.php" class="menu-item active">
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
        <a href="alumni.php" class="menu-item"><span class="menu-icon">🎓</span> Alumni</a>
        <a href="tracer.php" class="menu-item"><span class="menu-icon">📊</span> Graduate Tracer</a>
        <a href="reports.php" class="menu-item"><span class="menu-icon">📈</span> Reports</a>
        <div class="menu-section">Communication</div>
        <a href="announcements.php" class="menu-item"><span class="menu-icon">📢</span> Announcements</a>
        <div class="menu-section">Settings</div>
        <a href="document_types.php" class="menu-item"><span class="menu-icon">⚙️</span> Document Types</a>
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
            <h1>Document Requests</h1>
            <p>Manage and process all document requests from students and alumni.</p>
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

    <!-- Alerts -->
    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= e($_GET['msgtype'] ?? 'success') ?>">
        <?= e($_GET['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- Quick Stats (like dashboard) -->
    <div class="stats-mini">
        <div class="stat-card">
            <div class="stat-num"><?= $tabCounts['pending'] ?? 0 ?></div>
            <div class="stat-label">Pending Approval</div>
            <div class="stat-sub">Awaiting review</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= ($tabCounts['approved'] ?? 0) + ($tabCounts['for_signature'] ?? 0) ?></div>
            <div class="stat-label">In Review</div>
            <div class="stat-sub">Approved / For Signature</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= ($tabCounts['processing'] ?? 0) + ($tabCounts['ready'] ?? 0) ?></div>
            <div class="stat-label">Active</div>
            <div class="stat-sub">Processing + Ready</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $tabCounts['released'] ?? 0 ?></div>
            <div class="stat-label">Completed</div>
            <div class="stat-sub">Successfully released</div>
        </div>
    </div>

    <!-- Status Tabs -->
    <div class="tabs">
        <?php
        $tabs = [
            ''           => ['All', $tabCounts['all'] ?? 0],
            'pending'    => ['Pending', $tabCounts['pending'] ?? 0],
            'approved'   => ['Approved', $tabCounts['approved'] ?? 0],
            'for_signature' => ['For Signature', $tabCounts['for_signature'] ?? 0],
            'processing' => ['Processing', $tabCounts['processing'] ?? 0],
            'ready'      => ['Ready', $tabCounts['ready'] ?? 0],
            'paid'       => ['Paid', $tabCounts['paid'] ?? 0],
            'released'   => ['Released', $tabCounts['released'] ?? 0],
            'cancelled'  => ['Cancelled', $tabCounts['cancelled'] ?? 0],
        ];
        foreach ($tabs as $val => $data):
            $label = $data[0];
            $cnt = $data[1];
            $active = ($status === $val) ? 'active' : '';
            $url = '?' . http_build_query(['status' => $val, 'q' => $search]);
        ?>
            <a href="<?= $url ?>" class="tab <?= $active ?>">
                <?= $label ?> <span class="cnt"><?= $cnt ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters & Search -->
    <div class="filters">
        <div style="font-size:0.8rem; color:var(--text-3);">
            Showing <strong><?= $totalRows ?></strong> request<?= $totalRows != 1 ? 's' : '' ?>
        </div>
        <form method="GET" class="search-form">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="text" name="q" placeholder="Search name, code, email…" value="<?= e($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if ($search): ?>
                <a href="?status=<?= e($status) ?>" class="btn-clear">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Requests Table -->
    <div class="card">
        <div class="card-header">
            <h2>📋 Request List</h2>
        </div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Requester</th>
                        <th>Document</th>
                        <th>Fee</th>
                        <th>Status</th>
                        <th>Payment</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($requests->num_rows > 0): ?>
                    <?php while ($r = $requests->fetch_assoc()): ?>
                        <tr>
                            <td><span class="code"><?= e($r['request_code']) ?></span></td>
                            <td>
                                <div class="user-name"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                <div class="user-meta"><?= e($r['email']) ?></div>
                                <?php if ($r['student_id']): ?>
                                    <div class="user-meta">ID: <?= e($r['student_id']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($r['doc_type']) ?>
                                <div class="user-meta"><?= $r['copies'] ?> cop<?= $r['copies'] > 1 ? 'ies' : 'y' ?></div>
                            </td>
                            <td><span class="fee">₱<?= number_format($r['total_fee'], 2) ?></span></td>
                            <td><?= statusBadge($r['status']) ?></td>
                            <td><?= paymentBadge($r['status']) ?></td>
                            <td>
                                <?= fd($r['requested_at']) ?>
                                <?php if ($r['payment_date']): ?>
                                    <div class="user-meta">Paid: <?= fd($r['payment_date']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a href="request_detail.php?id=<?= $r['id'] ?>" class="btn btn-view">View</a>

                                    <?php if ($r['status'] === 'pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button class="btn btn-approve" onclick="return confirm('Approve this request?')">✓ Approve</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (in_array($r['status'], ['for_signature', 'approved'])): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="process">
                                            <button class="btn btn-process" onclick="return confirm('Mark as Processing?')">⚙ Process</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($r['status'] === 'processing'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="ready">
                                            <button class="btn btn-ready" onclick="return confirm('Mark as Ready for pickup?')">📋 Ready</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($r['status'] === 'ready'): ?>
                                        <button class="btn btn-release"
                                            onclick="openPayModal(<?= $r['id'] ?>, '<?= e($r['request_code']) ?>', <?= $r['total_fee'] ?>, '<?= e($r['first_name'] . ' ' . $r['last_name']) ?>')">
                                            💳 Pay & Release
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($r['stub_code']): ?>
                                        <a href="../student/claim_stub.php?code=<?= e($r['stub_code']) ?>&admin=1" target="_blank" class="btn btn-stub">🖨 Stub</a>
                                    <?php endif; ?>

                                    <?php if (!in_array($r['status'], ['released', 'cancelled'])): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <button class="btn btn-cancel" onclick="return confirm('Cancel this request?')">✕ Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr class="empty-state"><td colspan="8">✨ No requests found.<?php if ($search): ?> Try a different search.<?php endif; ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$page-1]) ?>">← Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$i]) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(['status'=>$status,'q'=>$search,'page'=>$page+1]) ?>">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>

<!-- Pay & Release Modal (matching dashboard style) -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <h2>💳 Pay & Release Document</h2>
        <p id="modalDesc" style="color: var(--text-3); margin-bottom: 0.5rem;">Record payment and release the document.</p>
        <form method="POST" action="pay_release.php">
            <input type="hidden" name="request_id" id="modalRequestId">
            <div class="field">
                <label>Official Receipt Number <span style="color:#e11d48;">*</span></label>
                <input type="text" name="receipt_number" id="modalReceipt" placeholder="e.g. OR-2024-00123" required>
            </div>
            <div class="field">
                <label>Amount to Collect</label>
                <input type="text" id="modalAmount" readonly style="background:#f9fafb; font-weight:700; color:#059669;">
            </div>
            <div class="field">
                <label>Notes <span style="color:#9ca3af;">(optional)</span></label>
                <textarea name="notes" placeholder="Any additional notes…" rows="2"></textarea>
            </div>
            <div style="background:#fffbeb; border-left:4px solid #fcd34d; border-radius:8px; padding:0.75rem; margin-bottom:0.5rem; font-size:0.78rem; color:#92400e;">
                ⚠️ This action will record payment and release the document. Cannot be undone.
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-cancel" onclick="closePayModal()">Cancel</button>
                <button type="submit" class="modal-confirm">✓ Confirm Payment & Release</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPayModal(id, code, fee, name) {
    document.getElementById('modalRequestId').value = id;
    document.getElementById('modalDesc').textContent = `Request ${code} — ${name}`;
    document.getElementById('modalAmount').value = '₱' + parseFloat(fee).toFixed(2);
    document.getElementById('payModal').classList.add('open');
}
function closePayModal() {
    document.getElementById('payModal').classList.remove('open');
}
document.getElementById('payModal').addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
});
</script>

</body>
</html>
```