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
    // NOTE: alumni_employment table must exist — skip gracefully if not
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
    $stmt->close(); // ✅ closed once here only

    $countStmt->execute();
    $totalRows  = $countStmt->get_result()->fetch_assoc()['total'];
    $totalPages = max(1, ceil($totalRows / $perPage));
    $countStmt->close();
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graduate Tracer</title>
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
            outline: none;
        }
        .search-form input:focus { border-color: #1a56db; }
        .search-form button {
            padding: 0.5rem 0.9rem;
            border: none;
            border-radius: 6px;
            background: #1a56db;
            color: white;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .search-form button:hover { background: #1447c0; }
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.07);
            overflow: hidden;
            margin-bottom: 1rem;
        }
        .card-header {
            padding: 1rem 1.2rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h2 { font-size: 1.1rem; font-weight: 600; color: #111827; margin: 0; }
        .card-body { padding: 1.2rem; }
        .profile-header {
            background: linear-gradient(135deg, #1a56db, #3563e9);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }
        .profile-header .avatar {
            width: 70px; height: 70px;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .profile-header .info h2 { font-size: 1.2rem; margin-bottom: 0.2rem; }
        .profile-header .info .meta { font-size: 0.85rem; opacity: 0.9; margin-top: 2px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
        .field {
            background: #f8fafc;
            padding: 0.85rem 1rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 0.6rem;
        }
        .field .label { font-size: 0.72rem; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .field .value { font-size: 0.9rem; color: #111827; margin-top: 0.3rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th {
            background: #f9fafb;
            text-align: left;
            padding: 0.75rem 1rem;
            font-weight: 600;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            color: #374151;
        }
        tr:hover td { background: #fafafa; }
        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 10px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge-blue   { background: #dbeafe; color: #1e40af; }
        .badge-green  { background: #d1fae5; color: #065f46; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-gray   { background: #f3f4f6; color: #6b7280; }
        .user-info  { font-weight: 600; color: #111827; }
        .user-meta  { font-size: 0.75rem; color: #6b7280; margin-top: 2px; }
        .btn {
            padding: 5px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.78rem;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        .btn-primary { background: #1a56db; color: white; }
        .btn-primary:hover { background: #1447c0; }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.4rem;
            margin-top: 1rem;
        }
        .pagination a, .pagination span {
            padding: 0.45rem 0.75rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            color: #374151;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
        }
        .pagination a:hover { background: #f3f4f6; }
        .pagination .current { background: #1a56db; color: white; border-color: #1a56db; }
        .empty-state { text-align: center; padding: 3rem; color: #9ca3af; font-size: 0.9rem; }
        /* Timeline */
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
        @media(max-width:768px) {
            .main { margin-left: 0; padding: 1rem; }
            .sidebar { display: none; }
            .grid { grid-template-columns: 1fr; }
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
        <a href="dashboard.php" class="menu-item"><span class="icon">🏠</span> Dashboard</a>
        <a href="requests.php"  class="menu-item"><span class="icon">📄</span> Document Requests</a>
        <a href="accounts.php"  class="menu-item"><span class="icon">👥</span> User Accounts</a>
        <div class="menu-label">Records</div>
        <a href="alumni.php"    class="menu-item"><span class="icon">🎓</span> Alumni / Graduates</a>
        <a href="tracer.php"    class="menu-item active"><span class="icon">📊</span> Graduate Tracer</a>
        <a href="reports.php"   class="menu-item"><span class="icon">📈</span> Reports</a>

        <div class="menu-label">Communication</div>
        <a href="announcements.php" class="menu-item"><span class="icon">📢</span> Announcements</a>

        <div class="menu-label">Settings</div>
        <a href="document_types.php" class="menu-item"><span class="icon">⚙️</span> Document Types</a>
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
        <h1>Tracer: <?= e($tracer['first_name'] . ' ' . $tracer['last_name']) ?></h1>
        <div class="admin-info">
            <a href="tracer.php" class="btn btn-primary">← Back to List</a>
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
    <div class="grid">
        <div class="card">
            <div class="card-header"><h2>Employment Information</h2></div>
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
            <div class="card-header"><h2>Education & Licensure</h2></div>
            <div class="card-body">
                <div class="field">
                    <div class="label">Further Studies</div>
                    <div class="value">
                        <?php if ((int)$tracer['further_studies']): ?>
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

    <!-- Employment timeline (only if table exists and has data) -->
    <?php if ($employment !== null && $employment->num_rows > 0): ?>
    <div class="card">
        <div class="card-header"><h2>📋 Employment History</h2></div>
        <div class="card-body">
            <div class="timeline">
                <?php while ($emp = $employment->fetch_assoc()):
                    $isCurr  = (int)$emp['is_current'] === 1;
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
        <h1>Graduate Tracer</h1>
        <a href="tracer.php" class="btn btn-primary">← Back</a>
    </div>
    <div class="card">
        <div class="empty-state">⚠️ No tracer record found for this user.</div>
    </div>

<?php else: ?>
<!-- ════════════════════════════════════════════
     LIST VIEW
     ════════════════════════════════════════════ -->
    <div class="topbar">
        <h1>Graduate Tracer Responses</h1>
        <div class="admin-info">
            Logged in as <strong><?= e($_SESSION['user_name']) ?></strong>
        </div>
    </div>

    <div class="filters">
        <div style="font-size:0.85rem;color:#6b7280;">
            Showing <strong><?= $totalRows ?></strong> response<?= $totalRows !== 1 ? 's' : '' ?>
        </div>
        <form method="GET" action="tracer.php" class="search-form">
            <input type="text" name="q"
                   placeholder="Search by name, email, course…"
                   value="<?= e($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if ($search !== ''): ?>
                <a href="tracer.php" class="btn" style="background:#f3f4f6;color:#374151;padding:5px 12px;">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Responses (<?= $totalRows ?>)</h2>
        </div>
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
                        <td><?= fd($t['date_submitted']) ?></td>
                        <td>
                            <a href="tracer.php?user=<?= intval($t['user_id']) ?>" class="btn btn-primary">
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