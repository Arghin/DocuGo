<?php
// ================================================================
// signatory/dashboard.php
// The Signature Panel — each office (Library, Comp Lab, Adviser,
// Admin, Cashier) logs in and sees requests needing their signature.
// ================================================================
require_once '../includes/config.php';
require_once '../includes/signature_workflow.php';
requireLogin();

if (($_SESSION['user_role'] ?? '') !== 'signatory') {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$conn        = getConnection();
$userId      = $_SESSION['user_id'];

// Load the signatory user + their office
$uStmt = $conn->prepare("
    SELECT u.id, u.first_name, u.last_name, u.signatory_role,
           u.signature_office_id,
           so.office_name, so.office_code
    FROM   users u
    LEFT JOIN signature_offices so ON so.id = u.signature_office_id
    WHERE  u.id = ? LIMIT 1
");
$uStmt->bind_param("i", $userId);
$uStmt->execute();
$signer = $uStmt->get_result()->fetch_assoc();
$uStmt->close();

if (!$signer || !$signer['signature_office_id']) {
    session_destroy();
    header('Location: ' . SITE_URL . '/login.php?err=no_office');
    exit();
}

$officeId   = (int) $signer['signature_office_id'];
$officeName = $signer['office_name'];
$officeCode = $signer['office_code'];

// ── Handle POST (sign / reject) ────────────────────────────
$flashSuccess = $flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']       ?? '';
    $sigRowId = intval($_POST['sig_id'] ?? 0);
    $remarks  = trim($_POST['remarks']  ?? '');

    if ($action === 'sign') {
        $result = signRequestRow($conn, $sigRowId, $userId, $remarks);
        if ($result['success']) {
            $flashSuccess = $result['auto_approved']
                ? '✅ Signed! All offices have signed — request auto-approved and student notified.'
                : '✅ Signature recorded successfully.';
        } else {
            $flashError = $result['error'];
        }
    } elseif ($action === 'reject') {
        if (empty($remarks)) {
            $flashError = 'A reason is required to reject.';
        } else {
            $result = rejectSignatureRow($conn, $sigRowId, $userId, $remarks);
            $flashSuccess = $result['success'] ? '✅ Request rejected. Student has been notified.' : '';
            $flashError   = $result['error']   ?? '';
        }
    }
}

// ── Stats ──────────────────────────────────────────────────
$stats = getSignatoryStats($conn, $officeId);

// ── Tab filter ─────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'pending';

$whereExtra = match($tab) {
    'signed'   => "AND rs.status = 'signed'",
    'rejected' => "AND rs.status = 'rejected'",
    'all'      => '',
    default    => "AND rs.status = 'pending' AND dr.status = 'for_signature'",
};

// ── Fetch requests assigned to this office ─────────────────
$stmt = $conn->prepare("
    SELECT
        rs.id           AS sig_id,
        rs.status       AS sig_status,
        rs.signed_at,
        rs.remarks      AS sig_remarks,
        dr.id           AS req_id,
        dr.request_code,
        dr.status       AS req_status,
        dr.purpose,
        dr.copies,
        dr.requested_at,
        dr.estimated_release_date,
        dt.name         AS doc_type,
        dt.fee,
        (dt.fee * dr.copies) AS total_fee,
        CONCAT(u.first_name,' ',u.last_name) AS student_name,
        u.student_id,
        u.course,
        u.email         AS student_email,
        (SELECT COUNT(*) FROM request_signatures WHERE request_id = dr.id) AS total_sigs,
        (SELECT COUNT(*) FROM request_signatures WHERE request_id = dr.id AND status = 'signed') AS signed_count
    FROM   request_signatures rs
    JOIN   document_requests  dr ON rs.request_id = dr.id
    JOIN   document_types     dt ON dr.document_type_id = dt.id
    JOIN   users              u  ON dr.user_id = u.id
    WHERE  rs.office_id = ?
    {$whereExtra}
    ORDER  BY dr.requested_at DESC
");
$stmt->bind_param("i", $officeId);
$stmt->execute();
$rows = $stmt->get_result();
$stmt->close();

$conn->close();

function e($v)  { return htmlspecialchars($v ?? ''); }
function fd($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function fdt($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($officeName) ?> — DocuGo Signature Panel</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; min-height: 100vh; }

        /* ── Top bar ── */
        .topbar {
            background: #1a56db;
            color: #fff;
            padding: 0.8rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(26,86,219,0.28);
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .topbar-left  { display: flex; align-items: center; gap: 0.75rem; }
        .topbar-brand { font-size: 1.2rem; font-weight: 800; letter-spacing: -0.4px; }
        .topbar-office {
            background: rgba(255,255,255,0.18);
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .topbar-right { display: flex; align-items: center; gap: 0.75rem; font-size: 0.85rem; }
        .topbar-user  { opacity: 0.88; }
        .logout-link  {
            background: rgba(255,255,255,0.16);
            padding: 4px 12px;
            border-radius: 7px;
            color: #fff;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            transition: background 0.15s;
        }
        .logout-link:hover { background: rgba(255,255,255,0.28); }

        /* ── Content ── */
        .main { max-width: 960px; margin: 0 auto; padding: 1.5rem 1.25rem; }

        /* ── Page title ── */
        .page-title { margin-bottom: 1.25rem; }
        .page-title h1 { font-size: 1.35rem; font-weight: 700; color: #111827; }
        .page-title p  { font-size: 0.875rem; color: #6b7280; margin-top: 3px; line-height: 1.5; }

        /* ── Stat cards ── */
        .stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 1rem;
            box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            text-align: center;
        }
        .stat-card.urgent { background: #fffbeb; border: 1.5px solid #fde68a; }
        .stat-n { font-size: 1.9rem; font-weight: 800; color: #111827; }
        .stat-l { font-size: 0.78rem; color: #6b7280; margin-top: 2px; }

        /* ── Alerts ── */
        .alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.875rem; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

        /* ── Tabs ── */
        .tabs {
            display: flex; gap: 0.25rem; flex-wrap: nowrap; overflow-x: auto;
            background: #fff; padding: 0.35rem;
            border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            margin-bottom: 1rem; scrollbar-width: none;
        }
        .tabs::-webkit-scrollbar { display: none; }
        .tab {
            padding: 0.4rem 0.85rem; border-radius: 7px; text-decoration: none;
            font-size: 0.8rem; font-weight: 500; color: #6b7280;
            white-space: nowrap; transition: all 0.15s;
        }
        .tab:hover  { background: #f3f4f6; color: #111827; }
        .tab.active { background: #1a56db; color: #fff; font-weight: 600; }
        .tab .cnt   { background: rgba(0,0,0,0.1); border-radius: 10px; padding: 1px 6px; font-size: 0.7rem; margin-left: 3px; }
        .tab.active .cnt { background: rgba(255,255,255,0.25); }

        /* ── Request cards ── */
        .req-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            margin-bottom: 1rem;
            overflow: hidden;
            border-left: 4px solid #fbbf24;
            transition: box-shadow 0.2s;
        }
        .req-card:hover    { box-shadow: 0 4px 16px rgba(0,0,0,0.10); }
        .req-card.signed   { border-left-color: #10b981; }
        .req-card.rejected { border-left-color: #ef4444; opacity: 0.82; }

        /* Card header */
        .card-head {
            padding: 1rem 1.1rem 0.75rem;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .card-code    { font-family: monospace; font-size: 0.78rem; color: #6b7280; margin-bottom: 3px; }
        .card-doc     { font-size: 1rem; font-weight: 700; color: #111827; }
        .card-meta    { font-size: 0.78rem; color: #9ca3af; margin-top: 3px; line-height: 1.6; }
        .card-right   { text-align: right; flex-shrink: 0; }
        .card-fee     { font-size: 1rem; font-weight: 800; color: #059669; }
        .card-date    { font-size: 0.75rem; color: #9ca3af; margin-top: 2px; }

        /* Signature progress */
        .sig-progress {
            padding: 0.6rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            background: #fafafa;
        }
        .sig-progress-top {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .progress-track { height: 6px; background: #e5e7eb; border-radius: 4px; overflow: hidden; }
        .progress-fill  { height: 100%; border-radius: 4px; background: #1a56db; transition: width 0.4s; }

        /* All-offices breakdown */
        .offices-row {
            padding: 0.65rem 1.1rem;
            border-top: 1px solid #f3f4f6;
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            align-items: center;
        }
        .office-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 600;
            border: 1px solid transparent;
        }
        .chip-pending  { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .chip-signed   { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
        .chip-rejected { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
        .chip-mine     { outline: 2px solid #1a56db; outline-offset: 1px; }

        /* Action area */
        .card-action {
            padding: 0.9rem 1.1rem;
            border-top: 1px solid #f3f4f6;
        }
        .action-inner { display: flex; gap: 0.75rem; align-items: flex-start; flex-wrap: wrap; }
        .action-form  { flex: 1; min-width: 200px; }
        .remarks-input {
            width: 100%;
            padding: 0.55rem 0.8rem;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.82rem;
            resize: vertical;
            min-height: 56px;
            outline: none;
            transition: border-color 0.2s;
            margin-bottom: 0.5rem;
        }
        .remarks-input:focus { border-color: #1a56db; box-shadow: 0 0 0 3px rgba(26,86,219,0.08); }
        .btn-row     { display: flex; gap: 0.5rem; }
        .btn-sign    {
            flex: 1;
            padding: 0.62rem 1rem;
            background: #1a56db; color: #fff;
            border: none; border-radius: 8px;
            font-family: inherit; font-size: 0.875rem; font-weight: 700;
            cursor: pointer; transition: background 0.2s;
        }
        .btn-sign:hover { background: #1447c0; }
        .btn-reject  {
            padding: 0.62rem 0.9rem;
            background: #fee2e2; color: #991b1b;
            border: 1.5px solid #fca5a5; border-radius: 8px;
            font-family: inherit; font-size: 0.875rem; font-weight: 600;
            cursor: pointer; transition: opacity 0.15s;
        }
        .btn-reject:hover { opacity: 0.8; }

        /* Signed / rejected state banners */
        .state-banner { padding: 0.7rem 1.1rem; border-top: 1px solid #f3f4f6; font-size: 0.85rem; }
        .state-signed   { background: #f0fdf4; color: #065f46; }
        .state-rejected { background: #fef2f2; color: #991b1b; }

        /* Badges */
        .badge { padding: 2px 8px; border-radius: 10px; font-size: 0.7rem; font-weight: 700; display: inline-block; }
        .badge-pending  { background: #fef3c7; color: #92400e; }
        .badge-signed   { background: #d1fae5; color: #065f46; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }

        /* Empty state */
        .empty { text-align: center; padding: 3rem 1rem; background: #fff; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); }
        .empty-icon { font-size: 2.5rem; margin-bottom: 0.75rem; }
        .empty h3 { font-size: 1rem; color: #374151; margin-bottom: 0.3rem; }
        .empty p  { font-size: 0.875rem; color: #9ca3af; }

        @media (max-width: 600px) {
            .stats { grid-template-columns: 1fr 1fr; }
            .card-head { flex-direction: column; gap: 0.5rem; }
            .card-right { text-align: left; }
            .btn-row { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-left">
        <span class="topbar-brand">DocuGo</span>
        <span class="topbar-office">✍️ <?= e($officeName) ?></span>
    </div>
    <div class="topbar-right">
        <span class="topbar-user">👤 <?= e($signer['first_name'] . ' ' . $signer['last_name']) ?></span>
        <a href="../logout.php" class="logout-link">Logout</a>
    </div>
</div>

<div class="main">

    <div class="page-title">
        <h1>Signature Panel</h1>
        <p>
            Showing document requests that require your signature as
            <strong><?= e($officeName) ?></strong>.
            Once all offices have signed, the request is automatically approved and the student is notified.
        </p>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card urgent">
            <div class="stat-n"><?= $stats['pending'] ?></div>
            <div class="stat-l">Awaiting My Signature</div>
        </div>
        <div class="stat-card">
            <div class="stat-n"><?= $stats['signed'] ?></div>
            <div class="stat-l">Signed by Me</div>
        </div>
        <div class="stat-card">
            <div class="stat-n"><?= $stats['pending'] + $stats['signed'] + $stats['rejected'] ?></div>
            <div class="stat-l">Total Assigned</div>
        </div>
    </div>

    <!-- Flash messages -->
    <?php if ($flashSuccess): ?><div class="alert alert-success"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError):   ?><div class="alert alert-error"><?= e($flashError) ?></div><?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?tab=pending"  class="tab <?= $tab==='pending'  ?'active':'' ?>">
            ⏳ Needs Signature <span class="cnt"><?= $stats['pending'] ?></span>
        </a>
        <a href="?tab=signed"   class="tab <?= $tab==='signed'   ?'active':'' ?>">
            ✅ Signed <span class="cnt"><?= $stats['signed'] ?></span>
        </a>
        <a href="?tab=rejected" class="tab <?= $tab==='rejected' ?'active':'' ?>">
            ✕ Rejected <span class="cnt"><?= $stats['rejected'] ?></span>
        </a>
        <a href="?tab=all"      class="tab <?= $tab==='all'      ?'active':'' ?>">📋 All</a>
    </div>

    <!-- Request cards -->
    <?php if ($rows->num_rows === 0): ?>
    <div class="empty">
        <div class="empty-icon"><?= $tab==='pending' ? '🎉' : '📭' ?></div>
        <h3><?= $tab==='pending' ? 'No pending signatures' : 'Nothing here' ?></h3>
        <p><?= $tab==='pending' ? 'All requests assigned to your office have been handled.' : 'No records match this filter.' ?></p>
    </div>
    <?php else: ?>

    <?php while ($r = $rows->fetch_assoc()):
        $sigPct  = $r['total_sigs'] > 0 ? round(($r['signed_count'] / $r['total_sigs']) * 100) : 0;
        $cls     = match($r['sig_status']) { 'signed' => 'signed', 'rejected' => 'rejected', default => '' };
    ?>
    <div class="req-card <?= $cls ?>">

        <!-- Header -->
        <div class="card-head">
            <div>
                <div class="card-code"><?= e($r['request_code']) ?></div>
                <div class="card-doc"><?= e($r['doc_type']) ?></div>
                <div class="card-meta">
                    <strong><?= e($r['student_name']) ?></strong>
                    <?php if ($r['student_id']): ?>&nbsp;·&nbsp; ID: <?= e($r['student_id']) ?><?php endif; ?>
                    <?php if ($r['course']): ?>&nbsp;·&nbsp; <?= e($r['course']) ?><?php endif; ?>
                    <br>
                    <?= $r['copies'] ?> cop<?= $r['copies']>1?'ies':'y' ?> &nbsp;·&nbsp;
                    Submitted: <?= fd($r['requested_at']) ?>
                    <?php if ($r['estimated_release_date']): ?>
                        &nbsp;·&nbsp; Est. release: <strong><?= fd($r['estimated_release_date']) ?></strong>
                    <?php endif; ?>
                </div>
                <?php if ($r['purpose']): ?>
                <div class="card-meta" style="margin-top:4px">Purpose: <?= e($r['purpose']) ?></div>
                <?php endif; ?>
            </div>
            <div class="card-right">
                <div class="card-fee">₱<?= number_format($r['total_fee'],2) ?></div>
                <div class="card-date"><?= fd($r['requested_at']) ?></div>
                <div style="margin-top:5px">
                <?= match($r['sig_status']) {
                    'signed'   => "<span class='badge badge-signed'>✓ You Signed</span>",
                    'rejected' => "<span class='badge badge-rejected'>✕ You Rejected</span>",
                    default    => "<span class='badge badge-pending'>⏳ Needs Your Signature</span>",
                } ?>
                </div>
            </div>
        </div>

        <!-- Signature progress bar -->
        <div class="sig-progress">
            <div class="sig-progress-top">
                <span>Office signatures: <?= $r['signed_count'] ?> / <?= $r['total_sigs'] ?> completed</span>
                <span><?= $sigPct ?>%</span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" style="width:<?= $sigPct ?>%"></div>
            </div>
        </div>

        <!-- All-offices breakdown (live query per card) -->
        <?php
        $tmpConn = getConnection();
        $allSigs = getSignatureRows($tmpConn, (int)$r['req_id']);
        $tmpConn->close();
        ?>
        <div class="offices-row">
            <?php foreach ($allSigs as $s):
                $isMe    = ($s['office_id'] == $officeId);
                $chipCls = 'chip-' . $s['status'] . ($isMe ? ' chip-mine' : '');
                $icon    = match($s['status']) { 'signed' => '✓', 'rejected' => '✕', default => '⏳' };
                $label   = $s['role_label'] ?: $s['office_name'];
            ?>
            <span class="office-chip <?= $chipCls ?>"
                  title="<?= $isMe ? 'Your office' : '' ?><?= $s['signed_by_name'] ? ' — signed by '.$s['signed_by_name'] : '' ?>">
                <?= $icon ?> <?= e($label) ?>
                <?php if ($s['status']==='signed' && $s['signed_at']): ?>
                    <span style="font-weight:400;opacity:.7">· <?= date('M d', strtotime($s['signed_at'])) ?></span>
                <?php endif; ?>
            </span>
            <?php endforeach; ?>
        </div>

        <!-- Sign / Reject actions (only when pending) -->
        <?php if ($r['sig_status']==='pending' && $r['req_status']==='for_signature'): ?>
        <div class="card-action">
            <form method="POST" action="dashboard.php?tab=<?= e($tab) ?>">
                <input type="hidden" name="sig_id" value="<?= $r['sig_id'] ?>">
                <div class="action-inner">
                    <div class="action-form">
                        <textarea name="remarks" class="remarks-input"
                                  placeholder="Optional remarks (e.g. 'Clearance verified', 'Library balance cleared')"></textarea>
                        <div class="btn-row">
                            <button type="submit" name="action" value="sign" class="btn-sign">
                                ✍️ Sign this Request
                            </button>
                            <button type="submit" name="action" value="reject" class="btn-reject"
                                    onclick="return confirmReject(this.form)">
                                ✕ Reject
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <?php elseif ($r['sig_status']==='signed'): ?>
        <div class="state-banner state-signed">
            ✅ You signed this on <?= fdt($r['signed_at']) ?>
            <?php if ($r['sig_remarks']): ?>&nbsp;·&nbsp; "<?= e($r['sig_remarks']) ?>"<?php endif; ?>
        </div>

        <?php elseif ($r['sig_status']==='rejected'): ?>
        <div class="state-banner state-rejected">
            ✕ You rejected this on <?= fdt($r['signed_at']) ?>
            <?php if ($r['sig_remarks']): ?>&nbsp;·&nbsp; "<?= e($r['sig_remarks']) ?>"<?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endwhile; ?>

    <?php endif; ?>

</div>

<script>
function confirmReject(form) {
    const remarks = form.querySelector('textarea[name="remarks"]').value.trim();
    if (!remarks) {
        alert('Please enter a reason for rejection before clicking Reject.');
        return false;
    }
    return confirm('Are you sure you want to reject this request?\n\nThe student will be notified and the request will be cancelled.');
}
</script>

</body>
</html>