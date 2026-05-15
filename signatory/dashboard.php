<?php
// ================================================================
// signatory/dashboard.php
// The Signature Panel — each signatory office (Library, Comp Lab,
// Adviser, Admin, Cashier) logs in and sees ONLY the requests
// assigned to their office for signature.
// ================================================================
require_once '../includes/config.php';
require_once '../includes/signature_workflow.php';
requireLogin();

// Only signatory role users can access this page
if (($_SESSION['user_role'] ?? '') !== 'signatory') {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$conn        = getConnection();
$userId      = $_SESSION['user_id'];

// Fetch the logged-in signatory's info
$uStmt = $conn->prepare("
    SELECT id, first_name, last_name, signatory_role, email
    FROM users WHERE id = ? LIMIT 1
");
$uStmt->bind_param("i", $userId);
$uStmt->execute();
$signer = $uStmt->get_result()->fetch_assoc();
$uStmt->close();

if (!$signer || empty($signer['signatory_role'])) {
    session_destroy();
    header('Location: ' . SITE_URL . '/login.php?err=no_role');
    exit();
}

$myRole = $signer['signatory_role']; // e.g. 'library', 'comp_lab', 'adviser'

// Handle SIGN / REJECT POST
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? '';
    $signatureId = intval($_POST['signature_id'] ?? 0);
    $remarks     = trim($_POST['remarks'] ?? '');

    if ($action === 'sign') {
        $result = signDocument($conn, $signatureId, $userId, $myRole, $remarks);
        if ($result['success']) {
            $flashSuccess = $result['auto_approved']
                ? '✅ Signed! All signatures are now complete — request auto-approved.'
                : '✅ Signed successfully.';
        } else {
            $flashError = $result['error'];
        }
    } elseif ($action === 'reject') {
        if (empty($remarks)) {
            $flashError = 'Please provide a reason for rejection.';
        } else {
            $result = rejectSignature($conn, $signatureId, $userId, $remarks);
            $flashSuccess = $result['success'] ? '✅ Request rejected and student notified.' : '';
            $flashError   = $result['error'] ?? '';
        }
    }
}

// Tab filter
$tab = $_GET['tab'] ?? 'pending'; // pending | signed | all

// Fetch requests assigned to this signatory's role
$statusFilter = match($tab) {
    'signed' => "AND ds.status = 'signed'",
    'all'    => "",
    default  => "AND ds.status = 'pending' AND dr.status = 'for_signature'",
};

$requests = $conn->prepare("
    SELECT
        ds.id           AS sig_id,
        ds.status       AS sig_status,
        ds.signed_at,
        ds.remarks      AS sig_remarks,
        dr.id           AS request_id,
        dr.request_code,
        dr.status       AS req_status,
        dr.purpose,
        dr.copies,
        dr.requested_at,
        dt.name         AS doc_type,
        dt.fee,
        (dt.fee * dr.copies) AS total_fee,
        CONCAT(u.first_name,' ',u.last_name) AS student_name,
        u.student_id,
        u.course,
        u.email         AS student_email,
        -- count of signed vs total sigs on this request
        (SELECT COUNT(*) FROM document_signatures WHERE request_id = dr.id) AS total_sigs,
        (SELECT COUNT(*) FROM document_signatures WHERE request_id = dr.id AND status = 'signed') AS signed_sigs
    FROM   document_signatures ds
    JOIN   signatory_roles     sr ON ds.signatory_role_id = sr.id
    JOIN   document_requests   dr ON ds.request_id = dr.id
    JOIN   document_types      dt ON dr.document_type_id = dt.id
    JOIN   users               u  ON dr.user_id = u.id
    WHERE  sr.role_name = ?
    {$statusFilter}
    ORDER  BY dr.requested_at DESC
");
$requests->bind_param("s", $myRole);
$requests->execute();
$rows = $requests->get_result();
$requests->close();

// Counts for tabs
$countPending = (int)$conn->query("
    SELECT COUNT(*) AS c FROM document_signatures ds
    JOIN signatory_roles sr ON ds.signatory_role_id = sr.id
    JOIN document_requests dr ON ds.request_id = dr.id
    WHERE sr.role_name = '{$myRole}' AND ds.status = 'pending' AND dr.status = 'for_signature'
")->fetch_assoc()['c'];

$countSigned = (int)$conn->query("
    SELECT COUNT(*) AS c FROM document_signatures ds
    JOIN signatory_roles sr ON ds.signatory_role_id = sr.id
    WHERE sr.role_name = '{$myRole}' AND ds.status = 'signed'
")->fetch_assoc()['c'];

$conn->close();

// Role display labels
$roleLabels = [
    'library'  => '📚 Library Office',
    'comp_lab' => '💻 Computer Laboratory',
    'adviser'  => '🎓 Adviser',
    'admin'    => '🏫 Admin Office',
    'cashier'  => '💰 Cashier / Finance',
];
$roleLabel = $roleLabels[$myRole] ?? ucfirst(str_replace('_', ' ', $myRole));

function e($v) { return htmlspecialchars($v ?? ''); }
function fd($d){ return $d ? date('M d, Y', strtotime($d)) : '—'; }
function fdt($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signature Panel — DocuGo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; min-height: 100vh; }

        /* ── Top bar ── */
        .topbar {
            background: #1a56db;
            color: #fff;
            padding: 0.85rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(26,86,219,0.25);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .topbar-left { display: flex; align-items: center; gap: 1rem; }
        .topbar-brand { font-size: 1.25rem; font-weight: 800; letter-spacing: -0.5px; }
        .topbar-role  {
            background: rgba(255,255,255,0.18);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .topbar-right { display: flex; align-items: center; gap: 0.75rem; font-size: 0.85rem; }
        .topbar-user  { opacity: 0.88; }
        .logout-btn   {
            background: rgba(255,255,255,0.16);
            padding: 4px 12px;
            border-radius: 7px;
            text-decoration: none;
            color: #fff;
            font-size: 0.82rem;
            font-weight: 600;
            transition: background 0.15s;
        }
        .logout-btn:hover { background: rgba(255,255,255,0.28); }

        /* ── Main content ── */
        .main { max-width: 1000px; margin: 0 auto; padding: 1.5rem 1.25rem; }

        /* ── Page header ── */
        .page-header {
            margin-bottom: 1.25rem;
        }
        .page-header h1 { font-size: 1.4rem; font-weight: 700; color: #111827; }
        .page-header p  { font-size: 0.875rem; color: #6b7280; margin-top: 3px; }

        /* ── Stats row ── */
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
        .stat-card { background: #fff; border-radius: 10px; padding: 1rem; box-shadow: 0 1px 6px rgba(0,0,0,0.08); text-align: center; }
        .stat-n    { font-size: 1.8rem; font-weight: 800; color: #111827; }
        .stat-l    { font-size: 0.78rem; color: #6b7280; margin-top: 3px; }
        .stat-card.highlight { background: #fffbeb; border: 1.5px solid #fde68a; }
        .stat-card.highlight .stat-n { color: #92400e; }

        /* ── Alerts ── */
        .alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.875rem; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

        /* ── Tabs ── */
        .tabs {
            display: flex; gap: 0.25rem; flex-wrap: nowrap;
            background: #fff; padding: 0.35rem;
            border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            margin-bottom: 1rem; overflow-x: auto;
        }
        .tab {
            padding: 0.4rem 0.9rem; border-radius: 7px; text-decoration: none;
            font-size: 0.82rem; font-weight: 500; color: #6b7280; white-space: nowrap;
            transition: all 0.15s;
        }
        .tab:hover  { background: #f3f4f6; color: #111827; }
        .tab.active { background: #1a56db; color: #fff; font-weight: 600; }
        .tab .cnt   {
            background: rgba(0,0,0,0.1); border-radius: 10px;
            padding: 1px 6px; font-size: 0.7rem; margin-left: 4px;
        }
        .tab.active .cnt { background: rgba(255,255,255,0.25); }

        /* ── Request cards ── */
        .request-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            margin-bottom: 1rem;
            overflow: hidden;
            border-left: 4px solid #fbbf24;
            transition: box-shadow 0.2s;
        }
        .request-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.10); }
        .request-card.signed   { border-left-color: #10b981; opacity: 0.88; }
        .request-card.rejected { border-left-color: #ef4444; opacity: 0.8; }

        /* Card top */
        .card-top {
            padding: 1rem 1.1rem 0.75rem;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .card-code    { font-family: monospace; font-size: 0.78rem; color: #6b7280; margin-bottom: 3px; }
        .card-doctype { font-size: 1rem; font-weight: 700; color: #111827; }
        .card-meta    { font-size: 0.78rem; color: #9ca3af; margin-top: 3px; }
        .card-right   { text-align: right; }
        .card-fee     { font-size: 1rem; font-weight: 800; color: #059669; }
        .card-date    { font-size: 0.75rem; color: #9ca3af; }

        /* Signature progress bar */
        .sig-progress-wrap {
            padding: 0.6rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            background: #fafafa;
        }
        .sig-progress-label { font-size: 0.75rem; color: #6b7280; margin-bottom: 5px; display: flex; justify-content: space-between; }
        .sig-progress-bar   { height: 6px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
        .sig-progress-fill  { height: 100%; border-radius: 4px; background: #1a56db; transition: width 0.4s; }

        /* Signatures breakdown inside card */
        .sig-breakdown {
            padding: 0.65rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            align-items: center;
        }
        .sig-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .sig-chip.pending  { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .sig-chip.signed   { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
        .sig-chip.rejected { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
        .sig-chip.mine     { box-shadow: 0 0 0 2px #1a56db; }

        /* Action section */
        .card-action {
            padding: 0.85rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .action-form { flex: 1; min-width: 220px; }
        .action-form textarea {
            width: 100%;
            padding: 0.55rem 0.8rem;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.82rem;
            resize: vertical;
            min-height: 60px;
            outline: none;
            margin-bottom: 0.5rem;
            transition: border-color 0.2s;
        }
        .action-form textarea:focus { border-color: #1a56db; box-shadow: 0 0 0 3px rgba(26,86,219,0.08); }
        .btn-row    { display: flex; gap: 0.5rem; }
        .btn-sign   {
            flex: 1; padding: 0.6rem 1rem;
            background: #1a56db; color: #fff;
            border: none; border-radius: 8px;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.875rem; font-weight: 700;
            cursor: pointer; transition: background 0.2s;
        }
        .btn-sign:hover { background: #1447c0; }
        .btn-reject {
            padding: 0.6rem 0.9rem;
            background: #fee2e2; color: #991b1b;
            border: 1.5px solid #fca5a5;
            border-radius: 8px;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.875rem; font-weight: 600;
            cursor: pointer; transition: opacity 0.15s;
        }
        .btn-reject:hover { opacity: 0.82; }

        /* Already signed / rejected state */
        .signed-state {
            padding: 0.75rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            font-size: 0.85rem;
        }
        .signed-state.ok  { color: #065f46; background: #f0fdf4; }
        .signed-state.rej { color: #991b1b; background: #fef2f2; }

        /* Empty state */
        .empty { text-align: center; padding: 3rem 1rem; }
        .empty-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .empty h3 { font-size: 1rem; color: #374151; margin-bottom: 0.3rem; }
        .empty p  { font-size: 0.875rem; color: #9ca3af; }

        /* Badges */
        .badge { padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 700; display: inline-block; }
        .badge-pending    { background: #fef3c7; color: #92400e; }
        .badge-signed     { background: #d1fae5; color: #065f46; }
        .badge-rejected   { background: #fee2e2; color: #991b1b; }
        .badge-for_sig    { background: #fce7f3; color: #9d174d; }

        @media (max-width: 600px) {
            .stats { grid-template-columns: 1fr 1fr; }
            .card-top { flex-direction: column; gap: 0.5rem; }
            .card-right { text-align: left; }
            .btn-row { flex-direction: column; }
        }
    </style>
</head>
<body>

<!-- ── Top bar ── -->
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-brand">DocuGo</div>
        <div class="topbar-role"><?= $roleLabel ?></div>
    </div>
    <div class="topbar-right">
        <span class="topbar-user">
            👤 <?= e($signer['first_name'] . ' ' . $signer['last_name']) ?>
        </span>
        <a href="../logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<!-- ── Main ── -->
<div class="main">

    <div class="page-header">
        <h1>✍️ Signature Panel</h1>
        <p>Review and sign document requests assigned to your office. Once all offices have signed, the request is automatically approved.</p>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card highlight">
            <div class="stat-n"><?= $countPending ?></div>
            <div class="stat-l">Needs My Signature</div>
        </div>
        <div class="stat-card">
            <div class="stat-n"><?= $countSigned ?></div>
            <div class="stat-l">Signed by Me</div>
        </div>
        <div class="stat-card">
            <div class="stat-n"><?= $countPending + $countSigned ?></div>
            <div class="stat-l">Total Assigned</div>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if ($flashSuccess): ?><div class="alert alert-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError):   ?><div class="alert alert-error"><?= e($flashError) ?></div><?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?tab=pending" class="tab <?= $tab === 'pending' ? 'active' : '' ?>">
            ⏳ Needs Signature <span class="cnt"><?= $countPending ?></span>
        </a>
        <a href="?tab=signed" class="tab <?= $tab === 'signed' ? 'active' : '' ?>">
            ✅ Signed by Me <span class="cnt"><?= $countSigned ?></span>
        </a>
        <a href="?tab=all" class="tab <?= $tab === 'all' ? 'active' : '' ?>">
            📋 All
        </a>
    </div>

    <!-- Request cards -->
    <?php if ($rows->num_rows === 0): ?>
        <div class="empty">
            <div class="empty-icon"><?= $tab === 'pending' ? '🎉' : '📭' ?></div>
            <h3><?= $tab === 'pending' ? 'No pending signatures' : 'No records found' ?></h3>
            <p><?= $tab === 'pending' ? 'All requests assigned to your office have been signed.' : 'Nothing to show here.' ?></p>
        </div>
    <?php else: ?>
        <?php while ($r = $rows->fetch_assoc()):
            $sigPct = $r['total_sigs'] > 0 ? round(($r['signed_sigs'] / $r['total_sigs']) * 100) : 0;
            $cardCls = match($r['sig_status']) { 'signed' => 'signed', 'rejected' => 'rejected', default => '' };
        ?>
        <div class="request-card <?= $cardCls ?>">

            <!-- Card top -->
            <div class="card-top">
                <div>
                    <div class="card-code"><?= e($r['request_code']) ?></div>
                    <div class="card-doctype"><?= e($r['doc_type']) ?></div>
                    <div class="card-meta">
                        <?= e($r['student_name']) ?>
                        <?php if ($r['student_id']): ?>&nbsp;·&nbsp; ID: <?= e($r['student_id']) ?><?php endif; ?>
                        <?php if ($r['course']): ?>&nbsp;·&nbsp; <?= e($r['course']) ?><?php endif; ?>
                        &nbsp;·&nbsp; <?= $r['copies'] ?> cop<?= $r['copies'] > 1 ? 'ies' : 'y' ?>
                    </div>
                    <?php if ($r['purpose']): ?>
                    <div class="card-meta" style="margin-top:4px">Purpose: <?= e($r['purpose']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="card-right">
                    <div class="card-fee">₱<?= number_format($r['total_fee'], 2) ?></div>
                    <div class="card-date">Submitted <?= fd($r['requested_at']) ?></div>
                    <div style="margin-top:4px"><?= match($r['sig_status']) {
                        'signed'   => "<span class='badge badge-signed'>✓ You Signed</span>",
                        'rejected' => "<span class='badge badge-rejected'>✕ Rejected</span>",
                        default    => "<span class='badge badge-pending'>Pending Your Signature</span>",
                    } ?></div>
                </div>
            </div>

            <!-- Signature progress -->
            <div class="sig-progress-wrap">
                <div class="sig-progress-label">
                    <span>Signatures: <?= $r['signed_sigs'] ?> / <?= $r['total_sigs'] ?> offices signed</span>
                    <span><?= $sigPct ?>%</span>
                </div>
                <div class="sig-progress-bar">
                    <div class="sig-progress-fill" style="width:<?= $sigPct ?>%"></div>
                </div>
            </div>

            <!-- All office signatures (fetched fresh per card) -->
            <?php
            $allSigs = $GLOBALS['conn'] ?? null;
            // Re-fetch all sigs for this request inline
            ?>
            <div class="sig-breakdown" id="sigs-<?= $r['request_id'] ?>">
                <?php
                // Inline query for all sigs on this request
                $tmpConn = getConnection();
                $tmpSigs = $tmpConn->prepare("
                    SELECT ds.status, sr.role_label, sr.role_name,
                           CONCAT(u.first_name,' ',u.last_name) AS signer_name, ds.signed_at
                    FROM document_signatures ds
                    JOIN signatory_roles sr ON ds.signatory_role_id = sr.id
                    LEFT JOIN users u ON ds.signed_by = u.id
                    WHERE ds.request_id = ?
                    ORDER BY sr.order_no ASC
                ");
                $tmpSigs->bind_param("i", $r['request_id']);
                $tmpSigs->execute();
                $allSigRows = $tmpSigs->get_result();
                $tmpSigs->close();
                $tmpConn->close();

                while ($s = $allSigRows->fetch_assoc()):
                    $isMe   = ($s['role_name'] === $myRole);
                    $chipCls= $s['status'] . ($isMe ? ' mine' : '');
                    $icon   = match($s['status']) { 'signed' => '✓', 'rejected' => '✕', default => '⏳' };
                ?>
                <span class="sig-chip <?= $chipCls ?>" title="<?= $isMe ? 'Your office' : '' ?>">
                    <?= $icon ?> <?= e($s['role_label']) ?>
                    <?php if ($s['status'] === 'signed' && $s['signed_at']): ?>
                        <span style="font-weight:400;opacity:.75">· <?= date('M d', strtotime($s['signed_at'])) ?></span>
                    <?php endif; ?>
                </span>
                <?php endwhile; ?>
            </div>

            <!-- Action section -->
            <?php if ($r['sig_status'] === 'pending' && $r['req_status'] === 'for_signature'): ?>
            <div class="card-action">
                <div class="action-form">
                    <form method="POST" action="dashboard.php?tab=<?= e($tab) ?>">
                        <input type="hidden" name="signature_id" value="<?= $r['sig_id'] ?>">
                        <textarea name="remarks"
                                  placeholder="Optional remarks (e.g. 'Verified clearance on file')"></textarea>
                        <div class="btn-row">
                            <button type="submit" name="action" value="sign" class="btn-sign">
                                ✍️ Sign this Request
                            </button>
                            <button type="submit" name="action" value="reject" class="btn-reject"
                                    onclick="return confirmReject(this.form)">
                                ✕ Reject
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php elseif ($r['sig_status'] === 'signed'): ?>
            <div class="signed-state ok">
                ✅ You signed this request on <?= fdt($r['signed_at']) ?>
                <?php if ($r['sig_remarks']): ?> &nbsp;·&nbsp; "<?= e($r['sig_remarks']) ?>"<?php endif; ?>
            </div>

            <?php elseif ($r['sig_status'] === 'rejected'): ?>
            <div class="signed-state rej">
                ✕ You rejected this request on <?= fdt($r['signed_at']) ?>
                <?php if ($r['sig_remarks']): ?> &nbsp;·&nbsp; "<?= e($r['sig_remarks']) ?>"<?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php endwhile; ?>
    <?php endif; ?>

</div>

<script>
function confirmReject(form) {
    var remarks = form.querySelector('textarea[name="remarks"]').value.trim();
    if (!remarks) {
        alert('Please enter a reason for rejection before clicking Reject.');
        return false;
    }
    return confirm('Reject this request? The student will be notified and the request will be cancelled.');
}
</script>

</body>
</html>