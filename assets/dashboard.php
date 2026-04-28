<?php
require_once 'includes/config.php';

// Require login
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['user_email'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'student';

$conn = getConnection();
$stmt = $conn->prepare("
    SELECT first_name, last_name, email, role, status, created_at
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
$conn->close();

if ($user) {
    $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $userEmail = $user['email'] ?? $userEmail;
    $userRole = $user['role'] ?? $userRole;
    $userStatus = $user['status'] ?? 'inactive';
    $createdAt = $user['created_at'] ?? null;
} else {
    session_destroy();
    header('Location: login.php');
    exit();
}

$roleLabel = ucfirst($userRole);
$statusLabel = ucfirst($userStatus);

$statusClass = 'status-inactive';
if ($userStatus === 'active') $statusClass = 'status-active';
if ($userStatus === 'pending') $statusClass = 'status-pending';

$firstName = $user['first_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — DocuGo</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --royal: #1a3fb0;
            --royal-dark: #122e8a;
            --royal-light: #2a52d4;
            --royal-xlight: #e8edfb;
            --royal-mid: #3563e9;
            --accent: #4f7dff;
            --accent2: #6c9cff;
            --sidebar-w: 210px;
            --bg: #f0f3fb;
            --card: #ffffff;
            --text: #0f1d3a;
            --muted: #6b7a99;
            --border: #dce3f5;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--royal);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.5rem 0 1rem;
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 100;
        }

        .sidebar-logo {
            width: 56px; height: 56px;
            background: rgba(255,255,255,0.15);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 2rem;
            font-size: 1.6rem;
        }

        .sidebar nav {
            width: 100%;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
            padding: 0 0.75rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.6rem 0.85rem;
            border-radius: 10px;
            color: rgba(255,255,255,0.65);
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
            cursor: pointer;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        .nav-item.active {
            background: rgba(255,255,255,0.18);
            color: #fff;
            font-weight: 700;
        }

        .nav-icon {
            width: 20px; height: 20px;
            opacity: 0.9;
            flex-shrink: 0;
        }

        .nav-logout {
            margin-top: auto;
            padding: 0 0.75rem;
            width: 100%;
            margin-bottom: 0.5rem;
        }

        .nav-logout a {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.6rem 0.85rem;
            border-radius: 10px;
            color: rgba(255,255,255,0.55);
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }

        .nav-logout a:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        /* ── MAIN ── */
        .main {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            padding: 1.5rem 1.75rem;
            gap: 1.25rem;
        }

        /* ── TOPBAR ── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .search-box {
            flex: 1;
            max-width: 340px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 0.55rem 1rem 0.55rem 2.4rem;
            border-radius: 10px;
            border: 1.5px solid var(--border);
            background: #fff;
            font-family: inherit;
            font-size: 0.85rem;
            color: var(--text);
            outline: none;
            transition: border 0.15s;
        }

        .search-box input:focus {
            border-color: var(--accent);
        }

        .search-box svg {
            position: absolute;
            left: 0.7rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
        }

        .user-pill {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: #fff;
            border-radius: 40px;
            padding: 0.35rem 0.85rem 0.35rem 0.35rem;
            border: 1.5px solid var(--border);
            box-shadow: 0 2px 8px rgba(26,63,176,0.07);
        }

        .user-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: var(--royal-light);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            overflow: hidden;
        }

        .user-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .user-info .name {
            font-weight: 700;
            font-size: 0.85rem;
            color: var(--text);
            line-height: 1.2;
        }

        .user-info .sub {
            font-size: 0.74rem;
            color: var(--muted);
        }

        /* ── NOTIFICATION BELL ── */
        .notif-btn {
            position: relative;
            width: 40px; height: 40px;
            border-radius: 50%;
            background: #fff;
            border: 1.5px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(26,63,176,0.07);
            transition: background 0.15s, border-color 0.15s;
            flex-shrink: 0;
        }

        .notif-btn:hover {
            background: var(--royal-xlight);
            border-color: var(--accent);
        }

        .notif-btn svg {
            color: var(--text);
        }

        .notif-dot {
            position: absolute;
            top: 7px; right: 7px;
            width: 9px; height: 9px;
            background: #ef4444;
            border-radius: 50%;
            border: 2px solid #fff;
        }

        /* ── HERO BANNER ── */
        .hero {
            background: linear-gradient(120deg, var(--royal-dark) 0%, var(--royal-mid) 60%, var(--accent2) 100%);
            border-radius: 18px;
            padding: 2rem 2rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            min-height: 150px;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 80% 50%, rgba(99,153,255,0.25) 0%, transparent 70%);
        }

        .hero-dots {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .dot {
            position: absolute;
            border-radius: 50%;
            opacity: 0.5;
        }

        .dot1 { width:18px;height:18px;background:#f97316;top:18px;right:260px; }
        .dot2 { width:12px;height:12px;background:#a78bfa;bottom:28px;right:300px; }
        .dot3 { width:10px;height:10px;background:#34d399;top:40px;right:200px; }

        .hero-text {
            position: relative;
            z-index: 2;
        }

        .hero-date {
            font-size: 0.78rem;
            color: rgba(255,255,255,0.65);
            margin-bottom: 0.5rem;
        }

        .hero-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: #fff;
            line-height: 1.2;
            margin-bottom: 0.35rem;
        }

        .hero-sub {
            font-size: 0.88rem;
            color: rgba(255,255,255,0.75);
        }

        .hero-illustration {
            position: relative;
            z-index: 2;
            font-size: 5rem;
            line-height: 1;
            user-select: none;
            filter: drop-shadow(0 8px 24px rgba(0,0,0,0.25));
        }

        /* ── CONTENT GRID ── */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 260px;
            gap: 1.25rem;
        }

        /* ── SECTION HEADER ── */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.9rem;
        }

        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
        }

        .see-all {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--royal-mid);
            text-decoration: none;
        }

        .see-all:hover { text-decoration: underline; }

        /* ── ACCOUNT OVERVIEW CARDS ── */
        .overview-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.85rem;
        }

        .ov-card {
            background: #fff;
            border-radius: 14px;
            padding: 1.1rem 1rem;
            border: 1.5px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            transition: box-shadow 0.15s, transform 0.15s;
            cursor: default;
        }

        .ov-card:hover {
            box-shadow: 0 6px 20px rgba(26,63,176,0.1);
            transform: translateY(-2px);
        }

        .ov-card.active-card {
            background: linear-gradient(140deg, var(--royal) 0%, var(--royal-mid) 100%);
            border-color: transparent;
        }

        .ov-icon {
            width: 48px; height: 48px;
            border-radius: 12px;
            background: var(--royal-xlight);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
        }

        .ov-card.active-card .ov-icon {
            background: rgba(255,255,255,0.2);
        }

        .ov-value {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--text);
            text-align: center;
        }

        .ov-card.active-card .ov-value {
            color: #fff;
        }

        .ov-label {
            font-size: 0.74rem;
            color: var(--muted);
            text-align: center;
        }

        .ov-card.active-card .ov-label {
            color: rgba(255,255,255,0.7);
        }

        /* ── QUICK ACTIONS ── */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.85rem;
            margin-top: 0;
        }

        .action-card {
            background: var(--royal-xlight);
            border-radius: 14px;
            padding: 1rem;
            border: 1.5px solid #d0d9f5;
            transition: box-shadow 0.15s, transform 0.15s;
        }

        .action-card:hover {
            box-shadow: 0 6px 20px rgba(26,63,176,0.1);
            transform: translateY(-2px);
        }

        .action-card h4 {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--royal-dark);
            margin-bottom: 0.3rem;
        }

        .action-card p {
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 0.6rem;
            line-height: 1.4;
        }

        .action-card a {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--royal-mid);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: #fff;
            padding: 0.35rem 0.75rem;
            border-radius: 7px;
            border: 1.5px solid var(--border);
            transition: background 0.15s, color 0.15s;
        }

        .action-card a:hover {
            background: var(--royal-mid);
            color: #fff;
            border-color: var(--royal-mid);
        }

        /* ── RIGHT PANEL ── */
        .right-panel {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .card-box {
            background: #fff;
            border-radius: 14px;
            border: 1.5px solid var(--border);
            padding: 1rem;
        }

        /* notices */
        .notice-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .notice-item {
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
        }

        .notice-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .notice-title {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.2rem;
        }

        .notice-body {
            font-size: 0.75rem;
            color: var(--muted);
            line-height: 1.4;
            margin-bottom: 0.3rem;
        }

        .notice-link {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--royal-mid);
            text-decoration: none;
        }

        /* status */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.22rem 0.6rem;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
        }

        .status-active  { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-inactive{ background: #fee2e2; color: #991b1b; }

        /* profile card */
        .profile-mini {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.85rem;
        }

        .profile-avatar {
            width: 44px; height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--royal-light), var(--accent2));
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .profile-name {
            font-weight: 700;
            font-size: 0.9rem;
        }

        .profile-role {
            font-size: 0.76rem;
            color: var(--muted);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.45rem 0;
            border-bottom: 1px solid var(--border);
            font-size: 0.8rem;
        }

        .info-row:last-child { border-bottom: none; }

        .info-row .lbl { color: var(--muted); }
        .info-row .val { font-weight: 600; color: var(--text); }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            .content-grid { grid-template-columns: 1fr; }
            .right-panel { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
        }

        @media (max-width: 680px) {
            :root { --sidebar-w: 0px; }
            .sidebar { display: none; }
            .overview-cards { grid-template-columns: 1fr 1fr; }
            .right-panel { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-logo">🎓</div>

    <nav>
        <a class="nav-item active" href="#">
            <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <a class="nav-item" href="profile.php">
            <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            My Profile
        </a>
        <a class="nav-item" href="documents.php">
            <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Documents
        </a>
        <?php if ($userRole === 'alumni'): ?>
        <a class="nav-item" href="tracer.php">
            <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            Tracer Survey
        </a>
        <?php endif; ?>
        <?php if (in_array($userRole, ['admin', 'registrar'])): ?>
        <a class="nav-item" href="admin/dashboard.php">
            <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Admin Panel
        </a>
        <?php endif; ?>
    </nav>

    <div class="nav-logout">
        <a href="logout.php">
            <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- MAIN -->
<main class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="search-box">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" placeholder="Search...">
        </div>
        <button class="notif-btn" title="Notifications">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <span class="notif-dot"></span>
        </button>
        <div class="user-pill">
            <div class="user-avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($userName ?: 'User') ?></div>
                <div class="sub"><?= htmlspecialchars($roleLabel) ?></div>
            </div>
        </div>
    </div>

    <!-- HERO BANNER -->
    <div class="hero">
        <div class="hero-dots">
            <div class="dot dot1"></div>
            <div class="dot dot2"></div>
            <div class="dot dot3"></div>
        </div>
        <div class="hero-text">
            <div class="hero-date"><?= date('F j, Y') ?></div>
            <div class="hero-title">Welcome back, <?= htmlspecialchars($firstName) ?>! 👋</div>
            <div class="hero-sub">Here's your account overview — stay on top of your documents.</div>
        </div>
        <div class="hero-illustration">🎓</div>
    </div>

    <!-- CONTENT GRID -->
    <div class="content-grid">

        <!-- LEFT COLUMN -->
        <div>
            <!-- Account Info -->
            <div class="section-header">
                <div class="section-title">Account Overview</div>
            </div>
            <div class="overview-cards">
                <div class="ov-card">
                    <div class="ov-icon">✉️</div>
                    <div class="ov-value" style="font-size:0.82rem; word-break:break-all;"><?= htmlspecialchars($userEmail) ?></div>
                    <div class="ov-label">Email</div>
                </div>
                <div class="ov-card active-card">
                    <div class="ov-icon">🎭</div>
                    <div class="ov-value"><?= htmlspecialchars($roleLabel) ?></div>
                    <div class="ov-label">Role</div>
                </div>
                <div class="ov-card">
                    <div class="ov-icon">🟢</div>
                    <div class="ov-value">
                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                    </div>
                    <div class="ov-label">Status</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="section-header" style="margin-top:1.4rem;">
                <div class="section-title">Quick Actions</div>
                <a class="see-all" href="#">See all</a>
            </div>
            <div class="actions-grid">
                <div class="action-card">
                    <h4>My Profile</h4>
                    <p>Update your personal and contact information.</p>
                    <a href="profile.php">Go to Profile →</a>
                </div>
                <div class="action-card">
                    <h4>My Documents</h4>
                    <p>Request, track, and manage your documents.</p>
                    <a href="documents.php">Open Documents →</a>
                </div>
                <?php if ($userRole === 'alumni'): ?>
                <div class="action-card">
                    <h4>Tracer Survey</h4>
                    <p>Complete your Graduate Tracer Survey.</p>
                    <a href="tracer.php">Open Survey →</a>
                </div>
                <?php endif; ?>
                <?php if (in_array($userRole, ['admin', 'registrar'])): ?>
                <div class="action-card">
                    <h4>Admin Panel</h4>
                    <p>Manage users, approvals, and records.</p>
                    <a href="admin/dashboard.php">Go to Admin →</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="right-panel">

            <!-- Profile Mini Card -->
            <div class="card-box">
                <div class="profile-mini">
                    <div class="profile-avatar"><?= strtoupper(substr($firstName, 0, 1)) ?></div>
                    <div>
                        <div class="profile-name"><?= htmlspecialchars($userName ?: 'User') ?></div>
                        <div class="profile-role"><?= htmlspecialchars($roleLabel) ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <span class="lbl">Email</span>
                    <span class="val" style="font-size:0.75rem;max-width:130px;text-align:right;word-break:break-all;"><?= htmlspecialchars($userEmail) ?></span>
                </div>
                <div class="info-row">
                    <span class="lbl">Status</span>
                    <span class="val"><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span></span>
                </div>
                <?php if ($createdAt): ?>
                <div class="info-row">
                    <span class="lbl">Joined</span>
                    <span class="val"><?= date('M Y', strtotime($createdAt)) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Daily Notices -->
            <div class="card-box">
                <div class="section-header">
                    <div class="section-title">Daily Notice</div>
                    <a class="see-all" href="#">See all</a>
                </div>
                <div class="notice-list">
                    <div class="notice-item">
                        <div class="notice-title">Document Submission</div>
                        <div class="notice-body">Make sure all required documents are submitted before the deadline.</div>
                        <a class="notice-link" href="#">See more</a>
                    </div>
                    <div class="notice-item">
                        <div class="notice-title">System Maintenance</div>
                        <div class="notice-body">Scheduled maintenance on Sunday 12:00 AM – 3:00 AM. Services may be unavailable.</div>
                        <a class="notice-link" href="#">See more</a>
                    </div>
                    <div class="notice-item">
                        <div class="notice-title">New Feature: Track Requests</div>
                        <div class="notice-body">You can now track your document requests in real time from My Documents.</div>
                        <a class="notice-link" href="#">See more</a>
                    </div>
                </div>
            </div>

        </div>
    </div>

</main>

</body>
</html>