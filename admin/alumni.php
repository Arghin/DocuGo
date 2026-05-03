<?php
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

$conn->query("
    CREATE TABLE IF NOT EXISTS alumni_employment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        company_name VARCHAR(200),
        job_title VARCHAR(150),
        industry VARCHAR(100),
        employment_type ENUM('full_time','part_time','contract','freelance','internship') DEFAULT 'full_time',
        work_setup ENUM('onsite','remote','hybrid') DEFAULT 'onsite',
        date_started DATE,
        date_ended DATE DEFAULT NULL,
        is_current TINYINT(1) DEFAULT 1,
        salary_range VARCHAR(50),
        work_location VARCHAR(200),
        description TEXT,
        skills TEXT,
        linkedin_url VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$search = trim($_GET['q'] ?? '');
$page   = intval($_GET['page'] ?? 1);
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// Query alumni with tracer and employment counts
$sql = "
    SELECT 
        u.id, u.first_name, u.last_name, u.email, u.student_id, u.course, u.year_graduated, u.created_at,
        gt.date_submitted AS tracer_date,
        COUNT(ae.id) AS employment_count
    FROM users u
    LEFT JOIN graduate_tracer gt ON u.id = gt.user_id
    LEFT JOIN alumni_employment ae ON u.id = ae.user_id
    WHERE u.role = 'alumni'
";
$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ? OR u.course LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sssss';
}

$sql .= " GROUP BY u.id ORDER BY u.last_name ASC, u.first_name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$alumni = $stmt->get_result();

// Get total count
$countSql = "
    SELECT COUNT(DISTINCT u.id) AS total
    FROM users u
    WHERE u.role = 'alumni'
";
if ($search !== '') {
    $countSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ? OR u.course LIKE ?)";
}
$countStmt = $conn->prepare($countSql);
if ($search !== '') {
    $countStmt->bind_param('sssss', $like, $like, $like, $like, $like);
}
$countStmt->execute();
$totalRows = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $perPage);
$countStmt->close();

// Get stats
$totalAlumni = $totalRows;
$tracerCount = $conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM graduate_tracer")->fetch_assoc()['c'];
$empCount = $conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM alumni_employment")->fetch_assoc()['c'];
$avgEmp = $conn->query("
    SELECT AVG(employment_count) AS avg
    FROM (
        SELECT COUNT(ae.id) AS employment_count
        FROM users u
        LEFT JOIN alumni_employment ae ON u.id = ae.user_id
        WHERE u.role = 'alumni'
        GROUP BY u.id
    ) AS sub
")->fetch_assoc()['avg'];

// Get pending accounts count for sidebar badge
$pendingAccs = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 'pending'")->fetch_assoc()['c'];
$pendingReqs = $conn->query("SELECT COUNT(*) as c FROM document_requests WHERE status = 'pending'")->fetch_assoc()['c'];

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
    <title>Alumni Records — DocuGo Admin</title>
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
        .search-form button {
            padding: 0.45rem 1rem;
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
        }

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
        .count-badge {
            background: var(--blue-lt);
            color: var(--blue);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
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

        .user-info { font-weight: 600; color: var(--text); font-size: 0.845rem; }
        .user-meta { font-size: 0.7rem; color: var(--text-4); margin-top: 1px; }

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
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-yellow { background: #fef3c7; color: #92400e; }

        /* Buttons */
        .btn {
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.12s;
            display: inline-block;
        }
        .btn-primary {
            background: var(--blue);
            color: #fff;
        }
        .btn-primary:hover {
            background: var(--blue-dk);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 2.5rem;
            color: var(--text-4);
        }

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
        .pagination .current {
            background: var(--blue);
            border-color: var(--blue);
            color: #fff;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
            .stats { grid-template-columns: repeat(2, 1fr); }
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
        <a href="alumni.php" class="menu-item active">
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

<!-- Main Content -->
<main class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <h1>🎓 Alumni Records</h1>
            <p>View and manage alumni information, tracer responses, and employment data.</p>
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
                <div class="num"><?= $empCount ?></div>
                <div class="label">Employment Profiles</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">📈</div>
            <div class="stat-info">
                <div class="num"><?= number_format($avgEmp ?? 0, 1) ?></div>
                <div class="label">Avg Employment Entries</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div></div>
        <form method="GET" class="search-form">
            <input type="text" name="q" placeholder="Search by name, email, course…" value="<?= e($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if ($search): ?>
                <a href="alumni.php" class="btn btn-primary" style="background: var(--text-4);">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table Card -->
    <div class="card">
        <div class="card-header">
            <h2>📋 Alumni Directory</h2>
            <span class="count-badge"><?= number_format($totalRows) ?> total</span>
        </div>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Course & Year</th>
                        <th>Contact</th>
                        <th>Tracer</th>
                        <th>Employment</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($alumni->num_rows > 0): ?>
                        <?php while ($a = $alumni->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="user-info"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></div>
                                    <div class="user-meta">
                                        <?php if (!empty($a['student_id'])): ?>ID: <?= e($a['student_id']) ?><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="user-info"><?= e($a['course'] ?? 'N/A') ?></div>
                                    <div class="user-meta">
                                        Graduated: <?= e($a['year_graduated'] ?? 'N/A') ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="user-info"><?= e($a['email']) ?></div>
                                </td>
                                <td>
                                    <?php if ($a['tracer_date']): ?>
                                        <span class="badge badge-green">✓ Completed</span>
                                        <div class="user-meta">
                                            <?= date('M d, Y', strtotime($a['tracer_date'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-yellow">⏳ Not Submitted</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="user-info"><?= (int)$a['employment_count'] ?> entries</div>
                                    <?php if ((int)$a['employment_count'] > 0): ?>
                                        <div class="user-meta">✓ Profile complete</div>
                                    <?php else: ?>
                                        <div class="user-meta">No data yet</div>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--text-3); font-size:0.75rem;"><?= ago($a['created_at']) ?></td>
                                <td>
                                    <a href="tracer.php?user=<?= $a['id'] ?>" class="btn btn-primary">View Details</a>
                                 </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                <div style="padding: 2rem; text-align: center; color: var(--text-4);">
                                    🎓 No alumni found matching your search.
                                </div>
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
                <a href="?<?= http_build_query(['q' => $search, 'page' => $i]) ?>"
                   class="<?= $i === $page ? 'current' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(['q' => $search, 'page' => $page + 1]) ?>">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

</body>
</html>
<?php
$stmt->close();
$conn->close();
?>
```