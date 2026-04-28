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

function e($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alumni Records — Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            display: flex;
        }

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
        }

        .sidebar-brand {
            padding: 1.4rem 1.2rem;
            font-size: 1.5rem;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            letter-spacing: -0.5px;
        }

        .sidebar-brand small {
            display: block;
            font-size: 0.7rem;
            font-weight: 400;
            opacity: 0.75;
            margin-top: 2px;
        }

        .sidebar-menu { padding: 1rem 0; flex: 1; }

        .menu-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            opacity: 0.55;
            padding: 0.6rem 1.2rem 0.3rem;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.65rem 1.2rem;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.15s;
            border-left: 3px solid transparent;
        }

        .menu-item:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .menu-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left-color: #fff;
            font-weight: 600;
        }

        .menu-item .icon { font-size: 1rem; width: 20px; text-align: center; }

        .sidebar-footer {
            padding: 1rem 1.2rem;
            border-top: 1px solid rgba(255,255,255,0.15);
            font-size: 0.8rem;
        }

        .sidebar-footer a {
            color: #fff;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            opacity: 0.85;
        }

        .sidebar-footer a:hover { opacity: 1; text-decoration: underline; }

        .main { margin-left: 220px; flex: 1; padding: 2rem; }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.8rem;
        }

        .topbar h1 { font-size: 1.4rem; font-weight: 700; color: #111827; }
        .topbar .admin-info { font-size: 0.85rem; color: #6b7280; }
        .topbar .admin-info strong { color: #111827; }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 1.3rem 1.2rem;
            box-shadow: 0 1px 6px rgba(0,0,0,0.07);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            font-size: 1.8rem;
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon.blue   { background: #eff6ff; color: #1a56db; }
        .stat-icon.yellow { background: #fffbeb; color: #f59e0b; }
        .stat-icon.green  { background: #f0fdf4; color: #10b981; }
        .stat-icon.purple { background: #faf5ff; color: #8b5cf6; }

        .stat-info .num {
            font-size: 1.8rem;
            font-weight: 800;
            color: #111827;
            line-height: 1;
        }

        .stat-info .label {
            font-size: 0.85rem;
            color: #6b7280;
        }

        .filters {
            background: #fff;
            padding: 1rem 1.2rem;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.07);
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .search-form { display: flex; gap: 0.5rem; }
        .search-form input {
            padding: 0.5rem 0.8rem;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.85rem;
            width: 300px;
        }
        .search-form button {
            padding: 0.5rem 0.9rem;
            border: none;
            border-radius: 6px;
            background: #1a56db;
            color: white;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.07);
            overflow: hidden;
        }

        .card-header {
            padding: 1rem 1.2rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 { font-size: 1.1rem; font-weight: 600; color: #111827; margin: 0; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th {
            background: #f9fafb;
            text-align: left;
            padding: 0.8rem 1rem;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }

        td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }

        .badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-yellow { background: #fef3c7; color: #92400e; }

        .user-info {
            font-weight: 600;
            color: #111827;
        }
        .user-meta {
            font-size: 0.75rem;
            color: #6b7280;
            margin-top: 2px;
        }

        .btn {
            padding: 5px 10px;
            border: none;
            border-radius: 6px;
            font-size: 0.75rem;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #1a56db; color: white; }
        .btn-primary:hover { background: #1447c0; }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        .pagination a, .pagination span {
            padding: 0.5rem 0.8rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            color: #374151;
        }
        .pagination a { background: #f9fafb; border: 1px solid #e5e7eb; }
        .pagination a:hover { background: #f3f4f6; }
        .pagination .current { background: #1a56db; color: white; }

        @media(max-width:768px) {
            .main { margin-left: 0; padding: 1rem; }
            .sidebar { display: none; }
            .filters { flex-direction: column; align-items: stretch; }
            .search-form { width: 100%; }
            .search-form input { width: 100%; }
        }
    </style>
</head>
<body>
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
            <a href="alumni.php" class="menu-item active">
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
            <a href="document_types.php" class="menu-item">
                <span class="icon">⚙️</span> Document Types
            </a>
        </nav>
        <div class="sidebar-footer">
            <a href="../logout.php">🚪 Logout</a>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <h1>Alumni Records</h1>
            <div class="admin-info">
                Logged in as <strong><?= e($_SESSION['user_name']) ?></strong>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-icon blue">🎓</div>
                <div class="stat-info">
                    <div class="num"><?= $totalRows ?></div>
                    <div class="label">Total Alumni</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">📊</div>
                <div class="stat-info">
                    <div class="num">
                        <?php
                        $tracerCount = $conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM graduate_tracer")->fetch_assoc()['c'];
                        echo $tracerCount;
                        ?>
                    </div>
                    <div class="label">Tracer Responses</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon.purple">💼</div>
                <div class="stat-info">
                    <div class="num">
                        <?php
                        $empCount = $conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM alumni_employment")->fetch_assoc()['c'];
                        echo $empCount;
                        ?>
                    </div>
                    <div class="label">Employment Profiles</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon.yellow">📈</div>
                <div class="stat-info">
                    <div class="num">
                        <?php
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
                        echo number_format($avgEmp ?? 0, 1);
                        ?>
                    </div>
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
            </form>
        </div>

        <!-- Table -->
        <div class="card">
            <div class="card-header">
                <h2>Alumni Directory (<?= $totalRows ?>)</h2>
            </div>
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
                                        <span class="badge badge-green">Completed</span>
                                        <div class="user-meta">
                                            <?= date('M d, Y', strtotime($a['tracer_date'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-yellow">Not Submitted</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="user-info"><?= (int)$a['employment_count'] ?> entries</div>
                                    <?php if ((int)$a['employment_count'] > 0): ?>
                                        <div class="user-meta">Profile complete</div>
                                    <?php else: ?>
                                        <div class="user-meta">No data</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
                                <td>
                                    <a href="tracer.php?user=<?= $a['id'] ?>" class="btn btn-primary">View Details</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align:center;padding:3rem;color:#6b7280;">No alumni found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= http_build_query(['q' => $search, 'page' => $i]) ?>"
                       class="<?= $i === $page ? 'current' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </main>

</body>
</html>
<?php
$stmt->close();
$conn->close();
?>