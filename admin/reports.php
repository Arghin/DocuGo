<?php
require_once '../includes/config.php';
requireAdmin();

$conn = getConnection();

// Document Requests Stats
$reqStats = $conn->query("
    SELECT status, COUNT(*) AS count
    FROM document_requests
    GROUP BY status
");
$requestsByStatus = [];
while ($r = $reqStats->fetch_assoc()) {
    $requestsByStatus[$r['status']] = (int)$r['count'];
}
$totalRequests = array_sum($requestsByStatus);

// User Stats
$userStats = $conn->query("
    SELECT role, status, COUNT(*) AS count
    FROM users
    GROUP BY role, status
");
$usersByRoleStatus = [];
while ($u = $userStats->fetch_assoc()) {
    $usersByRoleStatus[$u['role']][$u['status']] = (int)$u['count'];
}

// Document Types Popularity
$docStats = $conn->query("
    SELECT dt.name, COUNT(dr.id) AS requests
    FROM document_types dt
    LEFT JOIN document_requests dr ON dt.id = dr.document_type_id
    GROUP BY dt.id
    ORDER BY requests DESC
");
$docs = [];
while ($d = $docStats->fetch_assoc()) {
    $docs[] = $d;
}

// Monthly Requests (last 12 months)
$monthlyReqs = $conn->query("
    SELECT DATE_FORMAT(requested_at, '%Y-%m') AS month,
           COUNT(*) AS count
    FROM document_requests
    WHERE requested_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month
");
$monthlyData = [];
while ($m = $monthlyReqs->fetch_assoc()) {
    $monthlyData[$m['month']] = (int)$m['count'];
}

// Tracer Stats
$tracerCount = (int)$conn->query("SELECT COUNT(*) AS c FROM graduate_tracer")->fetch_assoc()['c'];
$alumniCount = (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'alumni'")->fetch_assoc()['c'];
$tracerRate = $alumniCount > 0 ? round(($tracerCount / $alumniCount) * 100, 1) : 0;

// Employment Stats — check if alumni_employment table exists first
$empTableExists = $conn->query("SHOW TABLES LIKE 'alumni_employment'")->num_rows > 0;

if ($empTableExists) {
    $empCount = (int)$conn->query("SELECT COUNT(DISTINCT user_id) AS c FROM alumni_employment")->fetch_assoc()['c'];
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
    $avgEmp = round($avgEmp ?? 0, 1);
} else {
    $empCount = 0;
    $avgEmp   = 0;
}

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
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

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.07);
            padding: 1.5rem;
        }

        .card h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .stat {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .stat:last-child { border-bottom: none; }
        .stat .label { color: #374151; font-weight: 500; }
        .stat .value { font-weight: 700; color: #111827; }

        .progress-bar {
            background: #e5e7eb;
            border-radius: 4px;
            height: 8px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #1a56db;
            transition: width 0.3s;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-top: 1rem;
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
        }

        .badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }

        .monthly-chart {
            display: flex;
            align-items: end;
            gap: 0.5rem;
            height: 150px;
            margin-top: 1rem;
        }
        .month-bar {
            flex: 1;
            background: #1a56db;
            border-radius: 4px 4px 0 0;
            position: relative;
            min-height: 10px;
        }
        .month-bar .count {
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.7rem;
            color: #374151;
            font-weight: 600;
        }
        .month-label {
            font-size: 0.7rem;
            color: #6b7280;
            text-align: center;
            margin-top: 0.5rem;
        }

        @media(max-width:768px) {
            .main { margin-left: 0; padding: 1rem; }
            .sidebar { display: none; }
            .grid { grid-template-columns: 1fr; }
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
            <a href="alumni.php" class="menu-item">
                <span class="icon">🎓</span> Alumni / Graduates
            </a>
            <a href="tracer.php" class="menu-item">
                <span class="icon">📊</span> Graduate Tracer
            </a>
            <a href="reports.php" class="menu-item active">
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
            <h1>Reports & Analytics</h1>
            <div class="admin-info">
                Logged in as <strong><?= e($_SESSION['user_name']) ?></strong>
            </div>
        </div>

        <div class="grid">
            <!-- Document Requests Overview -->
            <div class="card">
                <h3>📄 Document Requests</h3>
                <div class="stat">
                    <span class="label">Total Requests</span>
                    <span class="value"><?= $totalRequests ?></span>
                </div>
                <?php foreach (['pending' => 'Pending', 'processing' => 'Processing', 'ready' => 'Ready', 'released' => 'Released', 'cancelled' => 'Cancelled'] as $status => $label): ?>
                    <div class="stat">
                        <span class="label"><?= $label ?></span>
                        <div style="display:flex;gap:0.5rem;align-items:center;">
                            <span class="value"><?= $requestsByStatus[$status] ?? 0 ?></span>
                            <div class="progress-bar" style="flex:1;max-width:100px;">
                                <div class="progress-fill" style="width:<?= $totalRequests > 0 ? (($requestsByStatus[$status] ?? 0) / $totalRequests * 100) : 0 ?>%"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- User Statistics -->
            <div class="card">
                <h3>👥 User Accounts</h3>
                <?php foreach (['student', 'alumni', 'registrar', 'admin'] as $role): ?>
                    <div class="stat">
                        <span class="label"><?= ucfirst($role) ?>s</span>
                        <span class="value">
                            <?= array_sum($usersByRoleStatus[$role] ?? []) ?>
                            <small style="font-weight:400;color:#6b7280;">
                                (<?= $usersByRoleStatus[$role]['active'] ?? 0 ?> active)
                            </small>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Alumni Engagement -->
            <div class="card">
                <h3>🎓 Alumni Engagement</h3>
                <div class="stat">
                    <span class="label">Total Alumni</span>
                    <span class="value"><?= $alumniCount ?></span>
                </div>
                <div class="stat">
                    <span class="label">Tracer Responses</span>
                    <span class="value">
                        <?= $tracerCount ?>
                        <small style="font-weight:400;color:#6b7280;">
                            (<?= $tracerRate ?>% response rate)
                        </small>
                    </span>
                </div>
                <div class="stat">
                    <span class="label">Employment Profiles</span>
                    <span class="value">
                        <?= $empCount ?>
                        <small style="font-weight:400;color:#6b7280;">
                            (avg <?= $avgEmp ?> entries per alumni)
                        </small>
                    </span>
                </div>
            </div>

            <!-- Monthly Requests Chart -->
            <div class="card">
                <h3>📅 Monthly Requests (Last 12 Months)</h3>
                <div class="monthly-chart">
                    <?php
                    $max = !empty($monthlyData) ? max($monthlyData) : 1;
                    $max = $max > 0 ? $max : 1;
                    for ($i = 11; $i >= 0; $i--) {
                        $date = date('Y-m', strtotime("-$i months"));
                        $count = $monthlyData[$date] ?? 0;
                        $height = $max > 0 ? ($count / $max * 100) : 0;
                    ?>
                        <div class="month-bar" style="height:<?= max(10, $height) ?>%;">
                            <div class="count"><?= $count ?></div>
                        </div>
                    <?php } ?>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:0.5rem;">
                    <?php for ($i = 11; $i >= 0; $i--): ?>
                        <div class="month-label"><?= date('M', strtotime("-$i months")) ?></div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Popular Documents -->
        <div class="card">
            <h3>📋 Document Popularity</h3>
            <table>
                <thead>
                    <tr>
                        <th>Document Type</th>
                        <th>Total Requests</th>
                        <th>Popularity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $doc): ?>
                        <tr>
                            <td><?= e($doc['name']) ?></td>
                            <td><?= $doc['requests'] ?></td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width:<?= $totalRequests > 0 ? ($doc['requests'] / $totalRequests * 100) : 0 ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

</body>
</html>