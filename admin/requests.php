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

    $actionMap = [
        'approve'      => 'approved',
        'process'      => 'processing',
        'ready'        => 'ready',
        'for_sign'     => 'for_signature',
        'cancel'       => 'cancelled',
    ];

    if (isset($actionMap[$action]) && $requestId > 0) {
        $newStatus = $actionMap[$action];
        $result    = updateRequestStatus($conn, $requestId, $newStatus, $userId);

        // Generate claim stub when status becomes ready
        if ($result['success'] && $newStatus === 'ready') {
            $reqInfo = $conn->query("
                SELECT dr.user_id, (dt.fee * dr.copies) AS total
                FROM document_requests dr
                JOIN document_types dt ON dr.document_type_id = dt.id
                WHERE dr.id = $requestId
            ")->fetch_assoc();

            if ($reqInfo) {
                generateClaimStub($conn, $requestId, $reqInfo['user_id'], $reqInfo['total']);
            }
        }

        $msg     = $result['message'];
        $msgType = $result['success'] ? 'success' : 'error';
    }

    header("Location: requests.php?" . http_build_query([
        'status'  => $status,
        'q'       => $search,
        'page'    => $page,
        'msg'     => $msg ?? '',
        'msgtype' => $msgType ?? 'error',
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

// ── Status counts for tabs ────────────────────────────────────
$tabCounts = [];
$tabResult = $conn->query("SELECT status, COUNT(*) AS c FROM document_requests GROUP BY status");
while ($t = $tabResult->fetch_assoc()) {
    $tabCounts[$t['status']] = $t['c'];
}
$tabCounts['all'] = array_sum($tabCounts);

$conn->close();

function e($v) { return htmlspecialchars($v ?? ''); }
function fd($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Requests — Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            min-height: 100vh;
            display: flex;
        }
        .sidebar {
            width: 220px; background: #1a56db; color: #fff;
            min-height: 100vh; flex-shrink: 0; display: flex;
            flex-direction: column; position: fixed; top: 0; left: 0; height: 100%;
        }
        .sidebar-brand {
            padding: 1.4rem 1.2rem; font-size: 1.5rem; font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,0.15); letter-spacing: -0.5px;
        }
        .sidebar-brand small {
            display: block; font-size: 0.7rem; font-weight: 400; opacity: 0.75; margin-top: 2px;
        }
        .sidebar-menu { padding: 1rem 0; flex: 1; }
        .menu-label {
            font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.08em; opacity: 0.55; padding: 0.6rem 1.2rem 0.3rem;
        }
        .menu-item {
            display: flex; align-items: center; gap: 0.7rem;
            padding: 0.65rem 1.2rem; color: rgba(255,255,255,0.85);
            text-decoration: none; font-size: 0.875rem; font-weight: 500;
            transition: background 0.15s; border-left: 3px solid transparent;
        }
        .menu-item:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .menu-item.active {
            background: rgba(255,255,255,0.15); color: #fff;
            border-left-color: #fff; font-weight: 600;
        }
        .menu-item .icon { font-size: 1rem; width: 20px; text-align: center; }
        .sidebar-footer {
            padding: 1rem 1.2rem; border-top: 1px solid rgba(255,255,255,0.15); font-size: 0.8rem;
        }
        .sidebar-footer a {
            color: #fff; text-decoration: none; display: flex;
            align-items: center; gap: 0.5rem; opacity: 0.85;
        }
        .sidebar-footer a:hover { opacity: 1; }
        .main { margin-left: 220px; flex: 1; padding: 2rem; }
        .topbar {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 1.5rem;
        }
        .topbar h1 { font-size: 1.4rem; font-weight: 700; color: #111827; }

        /* Alert */
        .alert {
            padding: 0.85rem 1.2rem; border-radius: 8px;
            margin-bottom: 1rem; font-size: 0.875rem; font-weight: 500;
        }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

        /* Tabs */
        .tabs {
            display: flex; gap: 0.25rem; margin-bottom: 1rem;
            background: #fff; padding: 0.4rem;
            border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.07);
            flex-wrap: wrap;
        }
        .tab {
            padding: 0.45rem 0.9rem; border-radius: 7px;
            text-decoration: none; font-size: 0.82rem; font-weight: 500;
            color: #6b7280; transition: all 0.15s; white-space: nowrap;
        }
        .tab:hover { background: #f3f4f6; color: #111827; }
        .tab.active { background: #1a56db; color: #fff; font-weight: 600; }
        .tab .cnt {
            background: rgba(0,0,0,0.1); border-radius: 10px;
            padding: 1px 6px; font-size: 0.72rem; margin-left: 4px;
        }
        .tab.active .cnt { background: rgba(255,255,255,0.25); }

        /* Filters */
        .filters {
            background: #fff; padding: 0.85rem 1.2rem;
            border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.07);
            margin-bottom: 1rem; display: flex;
            justify-content: space-between; align-items: center; gap: 1rem;
        }
        .search-form { display: flex; gap: 0.5rem; }
        .search-form input {
            padding: 0.5rem 0.85rem; border: 1px solid #d1d5db;
            border-radius: 7px; font-size: 0.85rem; width: 280px; outline: none;
        }
        .search-form input:focus { border-color: #1a56db; }
        .search-form button {
            padding: 0.5rem 1rem; background: #1a56db; color: #fff;
            border: none; border-radius: 7px; font-size: 0.85rem;
            font-weight: 500; cursor: pointer;
        }
        .search-form button:hover { background: #1447c0; }

        /* Table */
        .card {
            background: #fff; border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07); overflow: hidden;
        }
        .card-header {
            padding: 0.9rem 1.2rem; border-bottom: 1px solid #f3f4f6;
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-header h2 { font-size: 0.95rem; font-weight: 700; color: #111827; }
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th {
            text-align: left; padding: 0.65rem 1rem; background: #f9fafb;
            color: #6b7280; font-weight: 600; font-size: 0.72rem;
            text-transform: uppercase; letter-spacing: 0.04em;
            border-bottom: 1px solid #f3f4f6;
        }
        td { padding: 0.75rem 1rem; border-top: 1px solid #f3f4f6; vertical-align: middle; }
        tr:hover td { background: #fafafa; }
        .user-name { font-weight: 600; color: #111827; }
        .user-meta { font-size: 0.75rem; color: #6b7280; margin-top: 1px; }
        .code { font-family: monospace; font-size: 0.78rem; color: #374151; }
        .empty-state { text-align: center; padding: 3rem; color: #9ca3af; }

        /* Action buttons */
        .actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
        .btn {
            padding: 4px 10px; border: none; border-radius: 6px;
            font-size: 0.72rem; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-block;
            transition: opacity 0.15s; white-space: nowrap;
        }
        .btn:hover { opacity: 0.85; }
        .btn-view     { background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
        .btn-approve  { background: #dbeafe; color: #1e40af; }
        .btn-process  { background: #e0f2fe; color: #0369a1; }
        .btn-ready    { background: #fef9c3; color: #854d0e; }
        .btn-release  { background: #1a56db; color: #fff; padding: 5px 12px; font-size: 0.78rem; }
        .btn-cancel   { background: #fee2e2; color: #991b1b; }
        .btn-stub     { background: #ede9fe; color: #4c1d95; }

        /* Pagination */
        .pagination {
            display: flex; justify-content: center;
            gap: 0.4rem; margin-top: 1rem;
        }
        .pagination a, .pagination span {
            padding: 0.45rem 0.75rem; border-radius: 6px;
            text-decoration: none; font-size: 0.82rem; color: #374151;
            background: #fff; border: 1px solid #e5e7eb;
        }
        .pagination a:hover { background: #f3f4f6; }
        .pagination .active { background: #1a56db; color: #fff; border-color: #1a56db; }

        /* Modal */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff; border-radius: 14px; padding: 2rem;
            width: 100%; max-width: 440px; box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal h2 { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.5rem; color: #111827; }
        .modal p  { font-size: 0.875rem; color: #6b7280; margin-bottom: 1.2rem; }
        .modal .field { margin-bottom: 1rem; }
        .modal label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 0.3rem; }
        .modal input, .modal textarea {
            width: 100%; padding: 0.6rem 0.85rem;
            border: 1.5px solid #d1d5db; border-radius: 7px;
            font-size: 0.875rem; outline: none; font-family: inherit;
        }
        .modal input:focus, .modal textarea:focus { border-color: #1a56db; }
        .modal textarea { min-height: 70px; resize: vertical; }
        .modal-actions { display: flex; gap: 0.75rem; margin-top: 1.2rem; }
        .modal-actions button {
            flex: 1; padding: 0.7rem; border: none; border-radius: 8px;
            font-size: 0.9rem; font-weight: 600; cursor: pointer;
        }
        .modal-confirm { background: #1a56db; color: #fff; }
        .modal-confirm:hover { background: #1447c0; }
        .modal-cancel  { background: #f3f4f6; color: #374151; }
        .modal-cancel:hover  { background: #e5e7eb; }

        /* Fee highlight */
        .fee { font-weight: 700; color: #059669; }

        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">DocuGo <small>Admin Panel</small></div>
    <nav class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="dashboard.php" class="menu-item"><span class="icon">🏠</span> Dashboard</a>
        <a href="requests.php"  class="menu-item active"><span class="icon">📄</span> Document Requests</a>
        <a href="accounts.php"  class="menu-item"><span class="icon">👥</span> User Accounts</a>
        <div class="menu-label">Records</div>
        <a href="alumni.php"    class="menu-item"><span class="icon">🎓</span> Alumni / Graduates</a>
        <a href="tracer.php"    class="menu-item"><span class="icon">📊</span> Graduate Tracer</a>
        <a href="reports.php"   class="menu-item"><span class="icon">📈</span> Reports</a>

        <div class="menu-label">Communication</div>
        <a href="announcements.php" class="menu-item"><span class="icon">📢</span> Announcements</a>

        <div class="menu-label">Settings</div>
        <a href="document_types.php" class="menu-item"><span class="icon">⚙️</span> Document Types</a>
    </nav>
    <div class="sidebar-footer"><a href="../logout.php">🚪 Logout</a></div>
</aside>

<main class="main">
    <div class="topbar">
        <h1>Document Requests</h1>
        <div style="font-size:0.85rem;color:#6b7280;">
            Logged in as <strong><?= e($_SESSION['user_name']) ?></strong>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= e($_GET['msgtype'] ?? 'success') ?>">
        <?= e($_GET['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- Status Tabs -->
    <div class="tabs">
        <?php
        $tabs = [
            ''           => 'All',
            'pending'    => 'Pending',
            'approved'   => 'Approved',
            'processing' => 'Processing',
            'ready'      => 'Ready (Unpaid)',
            'paid'       => 'Paid',
            'released'   => 'Released',
            'cancelled'  => 'Cancelled',
        ];
        foreach ($tabs as $val => $label):
            $cnt   = $val === '' ? ($tabCounts['all'] ?? 0) : ($tabCounts[$val] ?? 0);
            $active = ($status === $val) ? 'active' : '';
            $url   = '?' . http_build_query(['status' => $val, 'q' => $search]);
        ?>
            <a href="<?= $url ?>" class="tab <?= $active ?>">
                <?= $label ?><span class="cnt"><?= $cnt ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Search -->
    <div class="filters">
        <div style="font-size:0.85rem;color:#6b7280;">
            Showing <strong><?= $totalRows ?></strong> request<?= $totalRows != 1 ? 's' : '' ?>
        </div>
        <form method="GET" class="search-form">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="text" name="q" placeholder="Search name, code, email…" value="<?= e($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if ($search): ?>
                <a href="?status=<?= e($status) ?>" class="btn btn-view">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-header">
            <h2>Requests</h2>
        </div>
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
                    <td><?= paymentBadge($r['payment_status']) ?></td>
                    <td>
                        <?= fd($r['requested_at']) ?>
                        <?php if ($r['payment_date']): ?>
                            <div class="user-meta">Paid: <?= fd($r['payment_date']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="actions">
                            <!-- View -->
                            <a href="request_detail.php?id=<?= $r['id'] ?>" class="btn btn-view">View</a>

                            <!-- Approve (pending only) -->
                            <?php if ($r['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-approve" onclick="return confirm('Approve this request?')">✓ Approve</button>
                                </form>
                            <?php endif; ?>

                            <!-- Mark Processing (approved only) -->
                            <?php if ($r['status'] === 'approved'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <input type="hidden" name="action" value="process">
                                    <button class="btn btn-process" onclick="return confirm('Mark as Processing?')">⚙ Process</button>
                                </form>
                            <?php endif; ?>

                            <!-- Mark Ready (processing only) -->
                            <?php if ($r['status'] === 'processing'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <input type="hidden" name="action" value="ready">
                                    <button class="btn btn-ready" onclick="return confirm('Mark as Ready for pickup?')">📋 Ready</button>
                                </form>
                            <?php endif; ?>

                            <!-- PAY & RELEASE (ready only) -->
                            <?php if ($r['status'] === 'ready'): ?>
                                <button class="btn btn-release"
                                    onclick="openPayModal(<?= $r['id'] ?>, '<?= e($r['request_code']) ?>', <?= $r['total_fee'] ?>, '<?= e($r['first_name'] . ' ' . $r['last_name']) ?>')">
                                    💳 Pay & Release
                                </button>
                            <?php endif; ?>

                            <!-- View stub -->
                            <?php if ($r['stub_code']): ?>
                                <a href="../student/claim_stub.php?code=<?= e($r['stub_code']) ?>&admin=1" target="_blank" class="btn btn-stub">🖨 Stub</a>
                            <?php endif; ?>

                            <!-- Cancel -->
                            <?php if (!in_array($r['status'], ['released', 'cancelled'])): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <button class="btn btn-cancel" onclick="return confirm('Cancel this request?')">✕</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8" class="empty-state">No requests found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
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

<!-- PAY & RELEASE Modal -->
<div class="modal-overlay" id="payModal">
    <div class="modal">
        <h2>💳 Pay & Release Document</h2>
        <p id="modalDesc">Record payment and release the document to the requester.</p>

        <form method="POST" action="pay_release.php">
            <input type="hidden" name="request_id" id="modalRequestId">

            <div class="field">
                <label>Official Receipt Number <span style="color:#e11d48;">*</span></label>
                <input type="text" name="receipt_number" id="modalReceipt"
                       placeholder="e.g. OR-2024-00123" required>
            </div>

            <div class="field">
                <label>Amount to Collect</label>
                <input type="text" id="modalAmount" readonly
                       style="background:#f9fafb;font-weight:700;color:#059669;">
            </div>

            <div class="field">
                <label>Notes <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
                <textarea name="notes" placeholder="Any additional notes…"></textarea>
            </div>

            <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:0.75rem;margin-bottom:0.5rem;font-size:0.82rem;color:#92400e;">
                ⚠️ This action will record the payment and immediately release the document. This cannot be undone.
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
    document.getElementById('modalDesc').textContent =
        `Request ${code} — ${name}`;
    document.getElementById('modalAmount').value =
        '₱' + parseFloat(fee).toFixed(2);
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