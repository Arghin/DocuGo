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
    <link rel="stylesheet" href="../assets/css/adboard.css">
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