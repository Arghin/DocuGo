<?php
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

$userId  = intval($_GET['user'] ?? 0);
$search  = trim($_GET['q'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$tracer     = null;
$employment = null;
$tracers    = null;
$totalRows  = 0;
$totalPages = 1;

// Get counts for sidebar badges
$pendingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$pendingAccs = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];

// ── View specific user ───────────────────────────────────────
if ($userId > 0) {
    $stmt = $conn->prepare("
        SELECT gt.*, u.first_name, u.last_name, u.email,
               u.student_id, u.course, u.year_graduated
        FROM graduate_tracer gt
        JOIN users u ON gt.user_id = u.id
        WHERE gt.user_id = ?
        ORDER BY gt.date_submitted DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $tracer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Only query employment if tracer exists
    if ($tracer) {
        if ($conn->query("SHOW TABLES LIKE 'alumni_employment'")->num_rows > 0) {
            $empStmt = $conn->prepare("
                SELECT * FROM alumni_employment
                WHERE user_id = ?
                ORDER BY is_current DESC, date_started DESC
            ");
            $empStmt->bind_param("i", $userId);
            $empStmt->execute();
            $employment = $empStmt->get_result();
            $empStmt->close();
        }
    }

// ── List all tracer submissions ──────────────────────────────
} else {
    $like = "%$search%";

    if ($search !== '') {
        $stmt = $conn->prepare("
            SELECT gt.*, u.first_name, u.last_name, u.email,
                   u.student_id, u.course, u.year_graduated
            FROM graduate_tracer gt
            JOIN users u ON gt.user_id = u.id
            WHERE u.first_name LIKE ? OR u.last_name LIKE ?
               OR u.email LIKE ? OR u.course LIKE ?
            ORDER BY gt.date_submitted DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("ssssii", $like, $like, $like, $like, $perPage, $offset);

        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM graduate_tracer gt
            JOIN users u ON gt.user_id = u.id
            WHERE u.first_name LIKE ? OR u.last_name LIKE ?
               OR u.email LIKE ? OR u.course LIKE ?
        ");
        $countStmt->bind_param("ssss", $like, $like, $like, $like);
    } else {
        $stmt = $conn->prepare("
            SELECT gt.*, u.first_name, u.last_name, u.email,
                   u.student_id, u.course, u.year_graduated
            FROM graduate_tracer gt
            JOIN users u ON gt.user_id = u.id
            ORDER BY gt.date_submitted DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("ii", $perPage, $offset);

        $countStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM graduate_tracer gt
            JOIN users u ON gt.user_id = u.id
        ");
    }

    $stmt->execute();
    $tracers = $stmt->get_result();
    $stmt->close();

    $countStmt->execute();
    $totalRows  = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = max(1, ceil($totalRows / $perPage));
    $countStmt->close();
}

// Get stats for dashboard cards
$totalAlumni = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'alumni'")->fetch_assoc()['c'];
$tracerCount = $conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM graduate_tracer")->fetch_assoc()['c'];
$employedCount = $conn->query("SELECT COUNT(*) as c FROM graduate_tracer WHERE employment_status IN ('employed', 'self_employed')")->fetch_assoc()['c'];

// ── Helpers ──────────────────────────────────────────────────
function e($v)  { return htmlspecialchars($v ?? ''); }
function fd($d) { return $d ? date('M d, Y', strtotime($d)) : 'N/A'; }

function labelType($t) {
    $labels = [
        'employed'        => 'Employed',
        'unemployed'      => 'Unemployed',
        'self_employed'   => 'Self-Employed',
        'further_studies' => 'Further Studies',
        'not_looking'     => 'Not Looking',
    ];
    return $labels[$t] ?? ucfirst(str_replace('_', ' ', $t ?? ''));
}

function relLabel($r) {
    $rels = [
        'very_relevant'     => 'Very Relevant',
        'relevant'          => 'Relevant',
        'somewhat_relevant' => 'Somewhat Relevant',
        'not_relevant'      => 'Not Relevant',
    ];
    return $rels[$r] ?? 'N/A';
}

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
    <title>Graduate Tracer — DocuGo Admin</title>
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
            flex-wrap: wrap;
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

        /* ── Stats Cards (dashboard style) ─────────────── */
        .stats {
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
        .stat-icon.purple { background: var(--purple-lt); }
        .stat-icon.orange { background: #fffbeb; }
        .stat-info .num {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
        }
        .stat-info .label {
            font-size: 0.7rem;
            color: var(--text-4);
            font-weight: 500;
            letter-spacing: 0.04em;
        }

        /* ── Back button ─────────────────────────────── */
        .btn-back {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text-2);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.12s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-back:hover {
            background: var(--blue-lt);
            border-color: var(--blue);
            color: var(--blue);
        }

        /* ── Profile Header ───────────────────────────── */
        .profile-header {
            background: linear-gradient(135deg, #1a56db, #3563e9);
            color: white;
            padding: 1.5rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.2rem;
            flex-wrap: wrap;
        }
        .profile-header .avatar {
            width: 70px; height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .profile-header .info h2 { font-size: 1.3rem; margin-bottom: 0.2rem; }
        .profile-header .info .meta { font-size: 0.85rem; opacity: 0.9; margin-top: 2px; }

        /* ── Cards ───────────────────────────────────── */
        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            overflow: hidden;
            margin-bottom: 1.2rem;
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
        .card-body { padding: 1.2rem; }

        /* ── Grid ───────────────────────────────────── */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem;
            margin-bottom: 1.2rem;
        }

        /* ── Fields ──────────────────────────────────── */
        .field {
            background: #f8fafc;
            padding: 0.85rem 1rem;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 0.75rem;
        }
        .field .label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.04em; }
        .field .value { font-size: 0.9rem; color: #111827; margin-top: 0.3rem; font-weight: 500; }

        /* ── Badges ──────────────────────────────────── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .badge-green  { background: #d1fae5; color: #065f46; }
        .badge-blue   { background: #dbeafe; color: #1e40af; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-gray   { background: #f3f4f6; color: #6b7280; }

        /* ── Table ───────────────────────────────────── */
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

        .user-info { font-weight: 600; color: var(--text); font-size: 0.845rem; }
        .user-meta { font-size: 0.7rem; color: var(--text-4); margin-top: 1px; }

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
            width: 260px;
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

        /* ── Button ──────────────────────────────────── */
        .btn-primary {
            background: var(--blue);
            color: #fff;
            padding: 5px 14px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: background 0.12s;
        }
        .btn-primary:hover { background: var(--blue-dk); }

        /* ── Timeline ────────────────────────────────── */
        .timeline { position: relative; padding-left: 1.8rem; padding-top: 0.5rem; }
        .timeline::before {
            content: '';
            position: absolute;
            left: 8px; top: 12px; bottom: 12px;
            width: 2px;
            background: #e2e8f0;
        }
        .entry { position: relative; padding-bottom: 1.2rem; }
        .entry::before {
            content: '';
            position: absolute;
            left: -1.8rem; top: 10px;
            width: 16px; height: 16px;
            border-radius: 50%;
            background: #cbd5e1;
            border: 3px solid #f0f4f8;
        }
        .entry.current::before { background: #10b981; }
        .entry-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem 1.2rem;
        }
        .entry-card.current { border-left: 4px solid #10b981; background: #f0fdf4; }
        .entry-card .title   { font-size: 1rem; font-weight: 700; color: #1e293b; }
        .entry-card .company { font-size: 0.88rem; color: #475569; font-weight: 600; margin-top: 2px; }
        .entry-card .dates   { font-size: 0.78rem; color: #64748b; margin-top: 2px; }
        .entry-card .desc    { margin-top: 0.6rem; font-size: 0.85rem; color: #334155; line-height: 1.5; }
        .no-employment { padding: 2rem; text-align: center; color: #9ca3af; font-size: 0.875rem; }

        /* ── Pagination ──────────────────────────────── */
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
        .pagination .current {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }
        .empty-state {
            text-align: center;
            padding: 2.5rem;
            color: var(--text-4);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .stats { grid-template-columns: repeat(2, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 700px) {
            .stats { grid-template-columns: 1fr 1fr; }
            .filters { flex-direction: column; align-items: stretch; }
            .search-form { justify-content: stretch; }
            .search-form input { flex: 1; }
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
        <a href="tracer.php" class="menu-item active">
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
        <a href="document_types.php" class="menu-item">
            <span class="menu-icon">⚙️</span> Document Types
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<main class="main">

<?php if ($userId > 0 && $tracer): ?>
<!-- ════════════════════════════════════════════
     DETAIL VIEW
     ════════════════════════════════════════════ -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Graduate Tracer Details</h1>
            <p>Viewing tracer response and employment history.</p>
        </div>
        <div class="topbar-right">
            <a href="tracer.php" class="btn-back">← Back to List</a>
            <div class="admin-info">
                <strong><?= e($_SESSION['user_name']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Profile header -->
    <div class="profile-header">
        <div class="avatar">
            <?= strtoupper(substr($tracer['first_name'], 0, 1)) ?>
        </div>
        <div class="info">
            <h2><?= e($tracer['first_name'] . ' ' . $tracer['last_name']) ?></h2>
            <div class="meta">📧 <?= e($tracer['email']) ?></div>
            <div class="meta">🎓 <?= e($tracer['course'] ?? 'N/A') ?>
                <?php if (!empty($tracer['year_graduated'])): ?>
                    &nbsp;·&nbsp; Class of <?= e($tracer['year_graduated']) ?>
                <?php endif; ?>
            </div>
            <div class="meta">📅 Submitted: <?= fd($tracer['date_submitted']) ?></div>
        </div>
    </div>

    <!-- Employment + Education grid -->
    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h2>💼 Employment Information</h2></div>
            <div class="card-body">
                <div class="field">
                    <div class="label">Employment Status</div>
                    <div class="value">
                        <?php
                        $badgeClass = match($tracer['employment_status'] ?? '') {
                            'employed'        => 'badge-green',
                            'self_employed'   => 'badge-blue',
                            'unemployed'      => 'badge-yellow',
                            'further_studies' => 'badge-purple',
                            default           => 'badge-gray'
                        };
                        ?>
                        <span class="badge <?= $badgeClass ?>">
                            <?= labelType($tracer['employment_status']) ?>
                        </span>
                    </div>
                </div>
                <?php if (!empty($tracer['employer_name'])): ?>
                <div class="field">
                    <div class="label">Employer / Company</div>
                    <div class="value"><?= e($tracer['employer_name']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($tracer['job_title'])): ?>
                <div class="field">
                    <div class="label">Job Title / Position</div>
                    <div class="value"><?= e($tracer['job_title']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($tracer['employment_sector'])): ?>
                <div class="field">
                    <div class="label">Employment Sector</div>
                    <div class="value"><?= ucfirst(str_replace('_', ' ', $tracer['employment_sector'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($tracer['degree_relevance'])): ?>
                <div class="field">
                    <div class="label">Degree Relevance</div>
                    <div class="value">
                        <span class="badge badge-green"><?= relLabel($tracer['degree_relevance']) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>🎓 Education & Licensure</h2></div>
            <div class="card-body">
                <div class="field">
                    <div class="label">Further Studies</div>
                    <div class="value">
                        <?php if ((int)($tracer['further_studies'] ?? 0)): ?>
                            <span class="badge badge-purple">Yes</span>
                        <?php else: ?>
                            <span class="badge badge-gray">No</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($tracer['school_further_studies'])): ?>
                <div class="field">
                    <div class="label">School / University</div>
                    <div class="value"><?= e($tracer['school_further_studies']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($tracer['professional_license'])): ?>
                <div class="field">
                    <div class="label">Professional License</div>
                    <div class="value"><?= e($tracer['professional_license']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Employment timeline -->
    <?php if ($employment !== null && $employment->num_rows > 0): ?>
    <div class="card">
        <div class="card-header"><h2>📋 Employment History</h2></div>
        <div class="card-body">
            <div class="timeline">
                <?php while ($emp = $employment->fetch_assoc()):
                    $isCurr  = (int)($emp['is_current'] ?? 0) === 1;
                    $endDate = $isCurr ? 'Present' : fd($emp['date_ended'] ?? null);
                ?>
                <div class="entry <?= $isCurr ? 'current' : '' ?>">
                    <div class="entry-card <?= $isCurr ? 'current' : '' ?>">
                        <div class="title"><?= e($emp['job_title']) ?></div>
                        <div class="company">🏢 <?= e($emp['company_name']) ?>
                            <?php if (!empty($emp['work_setup'])): ?>
                                &nbsp;·&nbsp; <?= ucfirst($emp['work_setup']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="dates">📅 <?= fd($emp['date_started'] ?? null) ?> — <?= $endDate ?></div>
                        <?php if (!empty($emp['description'])): ?>
                            <div class="desc"><?= nl2br(e($emp['description'])) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($emp['skills'])): ?>
                            <div class="desc">🛠️ <strong>Skills:</strong> <?= e($emp['skills']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php elseif ($userId > 0 && !$tracer): ?>
<!-- ── User not found ── -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Graduate Tracer</h1>
            <p>View tracer responses from alumni.</p>
        </div>
        <div class="topbar-right">
            <a href="tracer.php" class="btn-back">← Back to List</a>
        </div>
    </div>
    <div class="card">
        <div class="empty-state">⚠️ No tracer record found for this user.</div>
    </div>

<?php else: ?>
<!-- ════════════════════════════════════════════
     LIST VIEW
     ════════════════════════════════════════════ -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Graduate Tracer Responses</h1>
            <p>Track and analyze alumni employment outcomes.</p>
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

    <!-- Stats Cards -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-icon blue">🎓</div>
            <div class="stat-info">
                <div class="num"><?= $totalAlumni ?></div>
                <div class="label">Total Alumni</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">📊</div>
            <div class="stat-info">
                <div class="num"><?= $tracerCount ?></div>
                <div class="label">Tracer Responses</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">💼</div>
            <div class="stat-info">
                <div class="num"><?= $employedCount ?></div>
                <div class="label">Employed Alumni</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">📈</div>
            <div class="stat-info">
                <div class="num"><?= $tracerCount > 0 ? round(($employedCount / $tracerCount) * 100) : 0 ?>%</div>
                <div class="label">Employment Rate</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div style="font-size:0.8rem; color:var(--text-3);">
            Showing <strong><?= $totalRows ?></strong> response<?= $totalRows !== 1 ? 's' : '' ?>
        </div>
        <form method="GET" action="tracer.php" class="search-form">
            <input type="text" name="q"
                   placeholder="Search by name, email, course…"
                   value="<?= e($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if ($search !== ''): ?>
                <a href="tracer.php" class="btn-clear">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table Card -->
    <div class="card">
        <div class="card-header">
            <h2>📋 Tracer Responses</h2>
            <span class="badge badge-gray"><?= number_format($totalRows) ?> total</span>
        </div>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Alumni</th>
                        <th>Course / Year</th>
                        <th>Employment Status</th>
                        <th>Date Submitted</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($tracers && $tracers->num_rows > 0): ?>
                        <?php while ($t = $tracers->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div class="user-info"><?= e($t['first_name'] . ' ' . $t['last_name']) ?></div>
                                <div class="user-meta">📧 <?= e($t['email']) ?></div>
                                <?php if (!empty($t['student_id'])): ?>
                                    <div class="user-meta">🪪 <?= e($t['student_id']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="user-info"><?= e($t['course'] ?? 'N/A') ?></div>
                                <div class="user-meta">Grad: <?= e($t['year_graduated'] ?? 'N/A') ?></div>
                            </td>
                            <td>
                                <?php
                                $bc = match($t['employment_status'] ?? '') {
                                    'employed'        => 'badge-green',
                                    'self_employed'   => 'badge-blue',
                                    'unemployed'      => 'badge-yellow',
                                    'further_studies' => 'badge-purple',
                                    default           => 'badge-gray'
                                };
                                ?>
                                <span class="badge <?= $bc ?>"><?= labelType($t['employment_status']) ?></span>
                                <?php if (!empty($t['employer_name'])): ?>
                                    <div class="user-meta">🏢 <?= e($t['employer_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= ago($t['date_submitted']) ?></td>
                            <td>
                                <a href="tracer.php?user=<?= intval($t['user_id']) ?>" class="btn-primary">
                                    View Details
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-state">
                                <?= $search ? "No results found for \"" . e($search) . "\"." : "No tracer responses yet." ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(['q' => $search, 'page' => $page - 1]) ?>">← Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="current"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= http_build_query(['q' => $search, 'page' => $i]) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(['q' => $search, 'page' => $page + 1]) ?>">Next →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

</main>
</body>
</html>
<?php $conn->close(); ?>
```