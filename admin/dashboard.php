<?php
require_once '../includes/config.php';
require_once '../includes/announcement_helper.php';
requireAdmin();

$conn = getConnection();

// ── Stats ────────────────────────────────────────────────
$totalUsers     = $conn->query("SELECT COUNT(*) as c FROM users WHERE role IN ('student','alumni')")->fetch_assoc()['c'];
$totalStudents  = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'student'")->fetch_assoc()['c'];
$totalAlumni    = $conn->query("SELECT COUNT(*) as c FROM users WHERE role = 'alumni'")->fetch_assoc()['c'];
$pendingReqs    = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];
$approvedReqs   = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'approved'")->fetch_assoc()['c'];
$processingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'processing'")->fetch_assoc()['c'];
$readyReqs      = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'ready'")->fetch_assoc()['c'];
$releasedReqs   = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'released'")->fetch_assoc()['c'];
$totalReqs      = $conn->query("SELECT COUNT(*) as c FROM document_requests")->fetch_assoc()['c'];
$pendingAccs    = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];
$totalRevenue   = $conn->query("SELECT COALESCE(SUM(amount),0) as s FROM payment_records WHERE status='paid'")->fetch_assoc()['s'];

// ── Latest requests ──────────────────────────────────────
$latestReqs = $conn->query("
    SELECT dr.request_code, dr.status, dr.requested_at, dr.payment_status,
           u.first_name, u.last_name, u.role as user_role,
           dt.name as doc_type, dt.fee, dr.copies
    FROM document_requests dr
    JOIN users u ON dr.user_id = u.id
    JOIN document_types dt ON dr.document_type_id = dt.id
    ORDER BY dr.requested_at DESC
    LIMIT 6
");

// ── Pending accounts ─────────────────────────────────────
$pendingAccounts = $conn->query("
    SELECT id, first_name, last_name, email, role, created_at
    FROM users WHERE status = 'pending'
    ORDER BY created_at DESC
    LIMIT 5
");

// ── Recent activity (request logs) ──────────────────────
$recentActivity = $conn->query("
    SELECT rl.new_status, rl.changed_at, rl.notes,
           dr.request_code,
           u.first_name as req_fn, u.last_name as req_ln,
           staff.first_name as staff_fn
    FROM request_logs rl
    JOIN document_requests dr ON rl.request_id = dr.id
    JOIN users u ON dr.user_id = u.id
    LEFT JOIN users staff ON rl.changed_by = staff.id
    ORDER BY rl.changed_at DESC
    LIMIT 5
");

// ── Recent Announcements ───────────────────────────────────
$recentAnnouncements = getAnnouncements($conn, null, 3);

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }
function td($d){ return $d ? date('M d, g:i A', strtotime($d)) : '—'; }
function ago($d){
    $diff = time() - strtotime($d);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60).'m ago';
    if ($diff < 86400)  return floor($diff/3600).'h ago';
    return floor($diff/86400).'d ago';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — DocuGo</title>
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

        /* ── Sidebar ──────────────────────────────────── */
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

        /* ── Main ─────────────────────────────────────── */
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

        .topbar-date {
            font-size: 0.78rem;
            color: var(--text-3);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.4rem 0.85rem;
        }

        .logout-btn-top {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: #fee2e2;
            color: #dc2626;
            display: flex; align-items: center; justify-content: center;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.15s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .logout-btn-top:hover {
            background: #fecaca;
            transform: scale(1.08);
            color: #991b1b;
        }

        /* ── Stat Cards ───────────────────────────────── */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.4rem;
        }

        .stat-card {
            background: var(--card);
            border-radius: 12px;
            padding: 1.2rem 1.1rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            position: relative;
            overflow: hidden;
            transition: box-shadow 0.2s, transform 0.2s;
        }

        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 60px; height: 60px;
            border-radius: 0 12px 0 60px;
            opacity: 0.06;
        }

        .stat-card.blue::after   { background: var(--blue); }
        .stat-card.yellow::after { background: var(--yellow); }
        .stat-card.green::after  { background: var(--green); }
        .stat-card.purple::after { background: var(--purple); }

        .stat-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 0.85rem;
        }

        .stat-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
        }

        .stat-icon.blue   { background: var(--blue-lt);   }
        .stat-icon.yellow { background: var(--yellow-lt);  }
        .stat-icon.green  { background: var(--green-lt);   }
        .stat-icon.purple { background: var(--purple-lt);  }

        .stat-change {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
        }

        .stat-change.up   { background: var(--green-lt);  color: var(--green); }
        .stat-change.warn { background: var(--yellow-lt); color: var(--yellow); }

        .stat-num {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
            letter-spacing: -1px;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .stat-label {
            font-size: 0.78rem;
            color: var(--text-3);
            font-weight: 500;
            margin-top: 3px;
        }

        .stat-sub {
            font-size: 0.7rem;
            color: var(--text-4);
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid var(--border-lt);
        }

        /* ── Status mini bar ──────────────────────────── */
        .status-bar-wrap {
            background: var(--card);
            border-radius: 12px;
            padding: 1rem 1.2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-lt);
            margin-bottom: 1.4rem;
        }

        .status-bar-title {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.75rem;
        }

        .status-bar {
            display: flex;
            height: 10px;
            border-radius: 10px;
            overflow: hidden;
            gap: 2px;
            margin-bottom: 0.6rem;
        }

        .status-bar-seg { border-radius: 10px; transition: width 0.5s ease; }

        .status-legend {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            color: var(--text-3);
        }

        .legend-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* ── Grid layout ──────────────────────────────── */
        .grid-2 { display: grid; grid-template-columns: 1.6fr 1fr; gap: 1.2rem; }

        /* ── Cards ────────────────────────────────────── */
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

        .card-header-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h2 {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text);
        }

        .card-header a {
            font-size: 0.75rem;
            color: var(--blue);
            text-decoration: none;
            font-weight: 600;
        }

        .card-header a:hover { text-decoration: underline; }

        /* ── Table ────────────────────────────────────── */
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

        .td-name { font-weight: 600; color: var(--text); font-size: 0.845rem; }
        .td-sub  { font-size: 0.72rem; color: var(--text-4); margin-top: 1px; }
        .td-code { font-family: 'JetBrains Mono', monospace; font-size: 0.78rem; color: var(--text-3); }

        .empty-row td { text-align: center; color: var(--text-4); padding: 2.5rem; font-size: 0.855rem; }

        /* ── Badges ───────────────────────────────────── */
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
        .badge-student    { background: #dbeafe; color: #1e40af; }
        .badge-alumni     { background: #d1fae5; color: #065f46; }

        /* ── Buttons ──────────────────────────────────── */
        .btn-sm {
            padding: 4px 12px;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-family: inherit;
            transition: background 0.15s;
        }
        .btn-sm:hover { background: var(--blue-dk); }

        .btn-sm.outline {
            background: transparent;
            color: var(--blue);
            border: 1px solid var(--blue);
        }
        .btn-sm.outline:hover { background: var(--blue-lt); }

        /* ── Activity feed ────────────────────────────── */
        .activity-feed { padding: 0.5rem 0; }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem 1.2rem;
            border-bottom: 1px solid var(--border-lt);
            transition: background 0.12s;
        }

        .activity-item:last-child { border-bottom: none; }
        .activity-item:hover { background: #fafbff; }

        .activity-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 5px;
        }

         .activity-dot.pending    { background: var(--yellow); }
         .activity-dot.approved   { background: var(--blue); }
         .activity-dot.processing { background: #0ea5e9; }
         .activity-dot.ready      { background: var(--yellow); }
         .activity-dot.paid       { background: var(--green); }
         .activity-dot.released   { background: var(--purple); }
         .activity-dot.cancelled  { background: var(--red); }

        .activity-body { flex: 1; min-width: 0; }
        .activity-title { font-size: 0.82rem; color: var(--text); font-weight: 500; }
        .activity-title strong { font-weight: 700; }
        .activity-time { font-size: 0.7rem; color: var(--text-4); margin-top: 2px; }

        /* ── Quick actions ────────────────────────────── */
        .quick-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.6rem;
            padding: 1rem;
        }

        .qa-btn {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.65rem 0.85rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 9px;
            text-decoration: none;
            color: var(--text-2);
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.15s;
        }

        .qa-btn:hover {
            background: var(--blue-lt);
            border-color: var(--blue);
            color: var(--blue);
        }

        .qa-btn-icon {
            width: 28px; height: 28px;
            border-radius: 7px;
            background: var(--card);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
            box-shadow: var(--shadow);
        }

        /* ── Revenue card ─────────────────────────────── */
        .revenue-card {
            background: linear-gradient(135deg, #1a56db 0%, #1a56db 100%);
            border-radius: 12px;
            padding: 1.2rem 1.2rem 1rem;
            color: #fff;
            margin-bottom: 1.2rem;
            position: relative;
            overflow: hidden;
        }

        .revenue-card::before {
            content: '';
            position: absolute;
            top: -30px; right: -30px;
            width: 120px; height: 120px;
            border-radius: 50%;
            background: rgba(26,86,219,0.15);
        }

        .revenue-card::after {
            content: '';
            position: absolute;
            bottom: -20px; left: 20%;
            width: 80px; height: 80px;
            border-radius: 50%;
            background: rgba(26,86,219,0.08);
        }

        .revenue-label { font-size: 0.72rem; opacity: 0.6; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 6px; }
        .revenue-amount { font-size: 1.8rem; font-weight: 800; letter-spacing: -1px; margin-bottom: 4px; }
        .revenue-sub { font-size: 0.72rem; opacity: 0.55; }

        /* ── Pending users alert ──────────────────────── */
        .pending-alert {
            background: linear-gradient(135deg, #fffbeb, #fef3c7);
            border: 1px solid #fcd34d;
            border-radius: 10px;
            padding: 0.85rem 1rem;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .pa-text { font-size: 0.82rem; color: #92400e; font-weight: 600; }
        .pa-text span { font-size: 1rem; font-weight: 800; }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 1100px) {
            .stats { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .grid-2 { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 500px) {
            .stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<!-- ── Sidebar ────────────────────────────────────────────── -->
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
        <a href="dashboard.php" class="menu-item active">
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
        <a href="document_types.php" class="menu-item">
            <span class="menu-icon">⚙️</span> Document Types
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- ── Main ───────────────────────────────────────────────── -->
<main class="main">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>Dashboard</h1>
            <p>Welcome back, here's what's happening today.</p>
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

    <!-- Pending accounts alert -->
    <?php if ($pendingAccs > 0): ?>
    <div class="pending-alert">
        <div class="pa-text">
            ⚠️ <span><?= $pendingAccs ?></span> account<?= $pendingAccs > 1 ? 's' : '' ?>
            pending activation — users cannot login until approved.
        </div>
        <a href="accounts.php?status=pending" class="btn-sm">Review Now</a>
    </div>
    <?php endif; ?>

    <!-- Stats grid -->
    <div class="stats">
        <div class="stat-card blue">
            <div class="stat-top">
                <div class="stat-icon blue">👥</div>
                <span class="stat-change up">+<?= $totalUsers ?></span>
            </div>
            <div class="stat-num"><?= $totalUsers ?></div>
            <div class="stat-label">Total Users</div>
            <div class="stat-sub">
                <?= $totalStudents ?> students · <?= $totalAlumni ?> alumni
            </div>
        </div>

        <div class="stat-card yellow">
            <div class="stat-top">
                <div class="stat-icon yellow">⏳</div>
                <?php if ($pendingReqs > 0): ?>
                    <span class="stat-change warn"><?= $pendingReqs ?> new</span>
                <?php endif; ?>
            </div>
            <div class="stat-num"><?= $pendingReqs + $approvedReqs ?></div>
            <div class="stat-label">Awaiting Action</div>
            <div class="stat-sub">
                <?= $pendingReqs ?> pending · <?= $approvedReqs ?> approved
            </div>
        </div>

        <div class="stat-card green">
            <div class="stat-top">
                <div class="stat-icon green">✅</div>
                <span class="stat-change up"><?= $readyReqs ?> ready</span>
            </div>
            <div class="stat-num"><?= $processingReqs + $readyReqs ?></div>
            <div class="stat-label">In Progress</div>
            <div class="stat-sub">
                <?= $processingReqs ?> processing · <?= $readyReqs ?> ready for pickup
            </div>
        </div>

        <div class="stat-card purple">
            <div class="stat-top">
                <div class="stat-icon purple">📦</div>
                <span class="stat-change up"><?= $releasedReqs ?> done</span>
            </div>
            <div class="stat-num"><?= $totalReqs ?></div>
            <div class="stat-label">Total Requests</div>
            <div class="stat-sub">
                <?= $releasedReqs ?> released · <?= $totalReqs - $releasedReqs ?> active
            </div>
        </div>
    </div>

    <!-- Request status distribution bar -->
    <?php if ($totalReqs > 0):
        $pctPending    = round(($pendingReqs    / $totalReqs) * 100);
        $pctApproved   = round(($approvedReqs   / $totalReqs) * 100);
        $pctProcessing = round(($processingReqs / $totalReqs) * 100);
        $pctReady      = round(($readyReqs      / $totalReqs) * 100);
        $pctReleased   = round(($releasedReqs   / $totalReqs) * 100);
    ?>
    <div class="status-bar-wrap">
        <div class="status-bar-title">Request Status Distribution</div>
         <div class="status-bar">
             <div class="status-bar-seg" style="width:<?= $pctPending ?>%;background:var(--yellow);"></div>
             <div class="status-bar-seg" style="width:<?= $pctApproved ?>%;background:var(--blue);"></div>
             <div class="status-bar-seg" style="width:<?= $pctProcessing ?>%;background:#0ea5e9;"></div>
             <div class="status-bar-seg" style="width:<?= $pctReady ?>%;background:var(--yellow);"></div>
             <div class="status-bar-seg" style="width:<?= $pctReleased ?>%;background:var(--purple);"></div>
         </div>
         <div class="status-legend">
             <div class="legend-item"><div class="legend-dot" style="background:var(--yellow);"></div> Pending (<?= $pendingReqs ?>)</div>
             <div class="legend-item"><div class="legend-dot" style="background:var(--blue);"></div> Approved (<?= $approvedReqs ?>)</div>
             <div class="legend-item"><div class="legend-dot" style="background:#0ea5e9;"></div> Processing (<?= $processingReqs ?>)</div>
             <div class="legend-item"><div class="legend-dot" style="background:var(--yellow);"></div> Ready (<?= $readyReqs ?>)</div>
             <div class="legend-item"><div class="legend-dot" style="background:var(--purple);"></div> Released (<?= $releasedReqs ?>)</div>
         </div>
    </div>
    <?php endif; ?>

    <!-- Main grid -->
    <div class="grid-2">

        <!-- Left column -->
        <div style="display:flex;flex-direction:column;gap:1.2rem;">

            <!-- Recent Announcements -->
            <?php if (!empty($recentAnnouncements)): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <h2>📢 Recent Announcements</h2>
                    </div>
                    <a href="announcements.php">View all →</a>
                </div>
                <div style="padding:1rem 1.2rem;display:flex;flex-direction:column;gap:0.8rem;">
                    <?php foreach ($recentAnnouncements as $ann): ?>
                    <div style="
                        padding: 0.85rem 1rem;
                        background: <?= $ann['target_type'] === 'all' ? '#eff6ff' : '#fffbeb' ?>;
                        border-left: 4px solid <?= $ann['target_type'] === 'all' ? '#1a56db' : '#d97706' ?>;
                        border-radius: 6px;
                    ">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;margin-bottom:0.3rem;">
                            <div style="font-weight:700;color:#111827;font-size:0.9rem;">
                                <?= e($ann['title']) ?>
                            </div>
                            <div style="font-size:0.68rem;color:#9ca3af;">
                                <?= ago($ann['created_at']) ?>
                            </div>
                        </div>
                        <div style="color:#374151;font-size:0.82rem;line-height:1.5;">
                            <?= nl2br(e($ann['message'])) ?>
                        </div>
                        <?php if ($ann['target_type'] === 'user'): ?>
                        <div style="font-size:0.68rem;color:#d97706;margin-top:0.4rem;">
                            👤 Targeted to specific user
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Latest Requests -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <h2>📄 Latest Requests</h2>
                    </div>
                    <a href="requests.php">View all →</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Requester</th>
                            <th>Document</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($latestReqs && $latestReqs->num_rows > 0): ?>
                            <?php while ($r = $latestReqs->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="td-name"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></div>
                                    <div class="td-sub td-code"><?= e($r['request_code']) ?></div>
                                </td>
                                <td><?= e($r['doc_type']) ?></td>
                                <td style="font-weight:700;color:#059669;">
                                    ₱<?= number_format($r['fee'] * $r['copies'], 2) ?>
                                </td>
                                <td><span class="badge badge-<?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
                                <td style="color:var(--text-4);font-size:0.75rem;"><?= ago($r['requested_at']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-row"><td colspan="5">No requests yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pending Account Approvals -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <h2>👥 Pending Approvals</h2>
                        <?php if ($pendingAccs > 0): ?>
                            <span class="badge badge-pending"><?= $pendingAccs ?> waiting</span>
                        <?php endif; ?>
                    </div>
                    <a href="accounts.php">View all →</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Registered</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pendingAccounts && $pendingAccounts->num_rows > 0): ?>
                            <?php while ($a = $pendingAccounts->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="td-name"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></div>
                                    <div class="td-sub"><?= e($a['email']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-<?= e($a['role']) ?>"><?= ucfirst(e($a['role'])) ?></span>
                                </td>
                                <td style="color:var(--text-4);font-size:0.75rem;"><?= ago($a['created_at']) ?></td>
                                <td>
                                    <a href="approve_account.php?id=<?= $a['id'] ?>" class="btn-sm">Approve</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="empty-row"><td colspan="4">No pending accounts. 🎉</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div><!-- end left col -->

        <!-- Right column -->
        <div style="display:flex;flex-direction:column;gap:1.2rem;">

            <!-- Revenue card -->
            <div class="revenue-card">
                <div class="revenue-label">Total Revenue Collected</div>
                <div class="revenue-amount">₱<?= number_format($totalRevenue, 2) ?></div>
                <div class="revenue-sub">From <?= $releasedReqs ?> released document<?= $releasedReqs != 1 ? 's' : '' ?></div>
            </div>

            <!-- Quick Actions -->
            <div class="card">
                <div class="card-header">
                    <h2>⚡ Quick Actions</h2>
                </div>
                <div class="quick-actions">
                    <a href="requests.php?status=pending" class="qa-btn">
                        <div class="qa-btn-icon">⏳</div>
                        Pending Requests
                    </a>
                    <a href="requests.php?status=ready" class="qa-btn">
                        <div class="qa-btn-icon">📋</div>
                        Ready for Pickup
                    </a>
                    <a href="accounts.php?status=pending" class="qa-btn">
                        <div class="qa-btn-icon">👤</div>
                        Approve Accounts
                    </a>
                    <a href="document_types.php" class="qa-btn">
                        <div class="qa-btn-icon">⚙️</div>
                        Document Types
                    </a>
                    <a href="tracer.php" class="qa-btn">
                        <div class="qa-btn-icon">📊</div>
                        Graduate Tracer
                    </a>
                    <a href="reports.php" class="qa-btn">
                        <div class="qa-btn-icon">📈</div>
                        View Reports
                    </a>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="card">
                <div class="card-header">
                    <h2>🕐 Recent Activity</h2>
                </div>
                <div class="activity-feed">
                    <?php if ($recentActivity && $recentActivity->num_rows > 0): ?>
                        <?php while ($act = $recentActivity->fetch_assoc()): ?>
                        <div class="activity-item">
                            <div class="activity-dot <?= e($act['new_status']) ?>"></div>
                            <div class="activity-body">
                                <div class="activity-title">
                                    <strong><?= e($act['req_fn'] . ' ' . $act['req_ln']) ?></strong>'s
                                    request <span style="font-family:'JetBrains Mono',monospace;font-size:0.75rem;"><?= e($act['request_code']) ?></span>
                                    marked as <strong><?= ucfirst(e($act['new_status'])) ?></strong>
                                    <?php if ($act['staff_fn']): ?>
                                        by <?= e($act['staff_fn']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-time">🕐 <?= ago($act['changed_at']) ?></div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="padding:2rem;text-align:center;color:var(--text-4);font-size:0.845rem;">
                            No recent activity yet.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- end right col -->

    </div><!-- end grid-2 -->
</main>

</body>
</html>