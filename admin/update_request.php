<?php
// ============================================================
// admin/update_request.php
// Handles viewing + updating a single document request.
// Enforces the full 7-step status flow including signature.
// ============================================================
require_once '../includes/config.php';
require_once '../includes/signature_helper.php';
requireLogin();

if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'registrar'])) {
    header('Location: ' . SITE_URL . '/login.php');
    exit();
}

$conn    = getConnection();
$adminId = $_SESSION['user_id'];
$role    = $_SESSION['user_role'];

$requestId = intval($_GET['id'] ?? 0);
if ($requestId <= 0) {
    header('Location: requests.php');
    exit();
}

$error   = '';
$success = '';

// ── Handle POST (status update) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = $_POST['new_status'] ?? '';
    $notes     = trim($_POST['notes'] ?? '');

    if (empty($newStatus)) {
        $error = 'Please select a status.';
    } else {
        $result = updateRequestStatus($conn, $requestId, $newStatus, $adminId, $notes);
        if ($result['success']) {
            $success = 'Status updated to "' . statusLabel($newStatus) . '" successfully.';
        } else {
            $error = $result['error'];
        }
    }
}

// ── Fetch request ─────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT dr.*,
           dt.name          AS doc_type,
           dt.fee           AS unit_fee,
           dt.processing_days,
           dt.requires_signature,
           (dt.fee * dr.copies) AS total_fee,
           CONCAT(u.first_name,' ',u.last_name) AS student_name,
           u.email          AS student_email,
           u.student_id,
           u.course,
           u.contact_number,
           u.role           AS student_role,
           cs.stub_code,
           pr.official_receipt_number,
           pr.payment_date,
           pr.amount        AS paid_amount
    FROM   document_requests dr
    JOIN   document_types    dt ON dr.document_type_id = dt.id
    JOIN   users             u  ON dr.user_id          = u.id
    LEFT JOIN claim_stubs    cs ON dr.id               = cs.request_id
    LEFT JOIN payment_records pr ON dr.id              = pr.request_id
    WHERE  dr.id = ?
    LIMIT  1
");
$stmt->bind_param("i", $requestId);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$req) {
    header('Location: requests.php');
    exit();
}

// ── Fetch request logs ────────────────────────────────────
$logStmt = $conn->prepare("
    SELECT rl.old_status, rl.new_status, rl.notes, rl.changed_at,
           CONCAT(u.first_name,' ',u.last_name) AS changed_by_name
    FROM   request_logs rl
    JOIN   users u ON rl.changed_by = u.id
    WHERE  rl.request_id = ?
    ORDER  BY rl.changed_at DESC
");
$logStmt->bind_param("i", $requestId);
$logStmt->execute();
$logs = $logStmt->get_result();
$logStmt->close();

$conn->close();

// ── Compute allowed transitions ───────────────────────────
$requiresSig  = (bool)$req['requires_signature'];
$sigObtained  = !empty($req['signature_obtained_at']);
$transitions  = getAllowedTransitions($req['status'], $requiresSig, $sigObtained);
$allowedNext  = $transitions['allowed'];

function e($v) { return htmlspecialchars($v ?? ''); }
function fd($d){ return $d ? date('M d, Y', strtotime($d)) : '—'; }
function fdt($d){ return $d ? date('M d, Y g:i A', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Request — DocuGo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; display: flex; }

        /* ── Sidebar ── */
        .sidebar { width: 220px; background: #1a56db; color: #fff; height: 100vh; position: fixed; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 1.4rem; font-size: 1.5rem; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .sidebar-brand small { display: block; font-size: 0.7rem; opacity: 0.8; }
        .sidebar-menu { flex: 1; padding: 1rem 0; }
        .menu-label { font-size: 0.7rem; padding: 0.5rem 1.2rem; opacity: 0.6; text-transform: uppercase; }
        .menu-item { display: block; padding: 0.7rem 1.2rem; color: #fff; text-decoration: none; opacity: 0.85; font-size: 0.875rem; }
        .menu-item:hover { background: rgba(255,255,255,0.1); opacity: 1; }
        .menu-item.active { background: rgba(255,255,255,0.15); opacity: 1; border-left: 3px solid #fff; font-weight: 600; }
        .sidebar-footer { padding: 1rem; border-top: 1px solid rgba(255,255,255,0.2); }
        .sidebar-footer a { color: white; text-decoration: none; font-size: 0.875rem; }

        /* ── Main ── */
        .main { margin-left: 220px; padding: 2rem; width: 100%; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .topbar h1 { font-size: 1.5rem; font-weight: 700; color: #111827; }
        .back-link { font-size: 0.85rem; color: #1a56db; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        .back-link:hover { text-decoration: underline; }

        /* ── Alerts ── */
        .alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.2rem; font-size: 0.875rem; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }

        /* ── Grid layout ── */
        .layout { display: grid; grid-template-columns: 1fr 340px; gap: 1.2rem; }

        /* ── Cards ── */
        .card { background: white; padding: 1.2rem; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); margin-bottom: 1.2rem; }
        .card h3 { font-size: 0.95rem; font-weight: 700; color: #111827; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #f3f4f6; }

        /* ── Info rows ── */
        .info-row { display: flex; gap: 0.5rem; padding: 0.45rem 0; border-bottom: 1px solid #f9fafb; font-size: 0.85rem; }
        .info-row:last-child { border-bottom: none; }
        .info-label { min-width: 150px; color: #6b7280; font-weight: 500; }
        .info-value { color: #111827; flex: 1; }

        /* ── Badges ── */
        .badge { padding: 3px 9px; border-radius: 10px; font-size: 0.72rem; font-weight: 700; display: inline-block; }
        .badge-pending    { background: #fef3c7; color: #92400e; }
        .badge-for_signature { background: #fce7f3; color: #9d174d; }
        .badge-approved   { background: #dbeafe; color: #1e40af; }
        .badge-processing { background: #e0f2fe; color: #075985; }
        .badge-ready      { background: #fef9c3; color: #713f12; border: 1.5px solid #fde68a; }
        .badge-paid       { background: #d1fae5; color: #065f46; }
        .badge-released   { background: #ede9fe; color: #4c1d95; }
        .badge-cancelled  { background: #fee2e2; color: #991b1b; }
        .badge-signature  { background: #fce7f3; color: #9d174d; }

        /* ── Signature notice box ── */
        .sig-notice {
            background: #fdf4ff;
            border: 1.5px solid #e879f9;
            border-radius: 10px;
            padding: 1rem 1.1rem;
            margin-bottom: 1rem;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }
        .sig-notice .sig-icon { font-size: 1.4rem; flex-shrink: 0; }
        .sig-notice .sig-text h4 { font-size: 0.875rem; font-weight: 700; color: #86198f; margin-bottom: 3px; }
        .sig-notice .sig-text p  { font-size: 0.8rem; color: #86198f; line-height: 1.5; }

        /* ── Status tracker (7 steps) ── */
        .tracker-wrap { margin: 1rem 0 0.5rem; }
        .tracker { display: flex; align-items: flex-start; position: relative; }
        .tracker-line-bg   { position: absolute; left: 0; right: 0; top: 13px; height: 3px; background: #e5e7eb; z-index: 0; border-radius: 3px; }
        .tracker-line-fill { position: absolute; left: 0; top: 13px; height: 3px; background: #1a56db; z-index: 1; border-radius: 3px; transition: width .4s; }
        .tracker-steps     { display: flex; justify-content: space-between; width: 100%; position: relative; z-index: 2; }
        .tracker-step      { display: flex; flex-direction: column; align-items: center; gap: 5px; }
        .step-dot {
            width: 26px; height: 26px; border-radius: 50%;
            background: #fff; border: 3px solid #e5e7eb;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.62rem; font-weight: 700; color: #9ca3af;
        }
        .step-dot.done      { background: #1a56db; border-color: #1a56db; color: #fff; }
        .step-dot.current   { background: #fff; border-color: #1a56db; color: #1a56db; box-shadow: 0 0 0 4px rgba(26,86,219,0.12); }
        .step-dot.sig-step  { background: #fff; border-color: #e879f9; color: #9d174d; }
        .step-dot.sig-done  { background: #e879f9; border-color: #e879f9; color: #fff; }
        .step-label { font-size: 0.62rem; color: #9ca3af; text-align: center; white-space: nowrap; font-weight: 500; }
        .step-label.done    { color: #1a56db; font-weight: 600; }
        .step-label.current { color: #111827; font-weight: 700; }
        .step-label.sig     { color: #9d174d; }

        /* ── Status update form ── */
        .form-group { margin-bottom: 0.9rem; }
        .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: #374151; margin-bottom: 0.3rem; }
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.6rem 0.85rem;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.875rem;
            color: #111827;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group select:focus,
        .form-group textarea:focus { border-color: #1a56db; box-shadow: 0 0 0 3px rgba(26,86,219,0.08); }
        .form-group textarea { resize: vertical; min-height: 80px; }

        /* ── Status option styling inside select ── */
        option.opt-sig { color: #9d174d; font-weight: 600; }
        option.opt-cancel { color: #991b1b; }

        /* ── Submit button ── */
        .btn-update {
            width: 100%;
            padding: 0.72rem;
            background: #1a56db;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-update:hover { background: #1447c0; }
        .btn-update:disabled { background: #93c5fd; cursor: not-allowed; }

        /* ── Quick action button ── */
        .btn-quick {
            display: block;
            width: 100%;
            padding: 0.65rem;
            border-radius: 8px;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            text-align: center;
            transition: opacity 0.2s;
            margin-bottom: 0.5rem;
            text-decoration: none;
        }
        .btn-quick:hover { opacity: 0.85; }
        .btn-sig-confirm  { background: #fdf4ff; color: #86198f; border: 1.5px solid #e879f9; }
        .btn-stub         { background: #ede9fe; color: #4c1d95; }
        .btn-payment      { background: #d1fae5; color: #065f46; }

        /* ── Log timeline ── */
        .log-item { display: flex; gap: 0.75rem; padding: 0.6rem 0; border-bottom: 1px solid #f3f4f6; font-size: 0.82rem; }
        .log-item:last-child { border-bottom: none; }
        .log-dot  { width: 8px; height: 8px; border-radius: 50%; background: #d1d5db; flex-shrink: 0; margin-top: 5px; }
        .log-dot.sig { background: #e879f9; }
        .log-meta { color: #6b7280; font-size: 0.75rem; margin-top: 2px; }
        .log-notes { font-style: italic; color: #9ca3af; font-size: 0.75rem; margin-top: 2px; }

        /* ── Estimated release ── */
        .release-bar {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 0.65rem 0.9rem;
            font-size: 0.82rem;
            color: #1e40af;
            margin-bottom: 0.8rem;
        }

        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1rem; }
        }
    </style>
</head>
<body>

<!-- ── Sidebar ── -->
<aside class="sidebar">
    <div class="sidebar-brand">
        DocuGo
        <small>Admin Panel</small>
    </div>
    <div class="sidebar-menu">
        <div class="menu-label">Dashboard</div>
        <a href="dashboard.php"  class="menu-item">🏠 Dashboard</a>
        <a href="requests.php"   class="menu-item active">📄 Document Requests</a>
        <a href="users.php"      class="menu-item">👥 User Accounts</a>
        <div class="menu-label">Records</div>
        <a href="alumni.php"     class="menu-item">🎓 Alumni / Graduates</a>
        <a href="tracer.php"     class="menu-item">📊 Graduate Tracer</a>
        <a href="reports.php"    class="menu-item">📈 Reports</a>
        <div class="menu-label">Settings</div>
        <a href="document_types.php" class="menu-item">📋 Document Types</a>
    </div>
    <div class="sidebar-footer">
        <a href="../logout.php">🚪 Logout</a>
    </div>
</aside>

<!-- ── Main ── -->
<main class="main">

    <div class="topbar">
        <div>
            <a href="requests.php" class="back-link">← Back to Requests</a>
            <h1 style="margin-top:4px">Request: <?= e($req['request_code']) ?></h1>
        </div>
        <div style="font-size:0.85rem;color:#6b7280">
            <?= statusBadge($req['status']) ?>
        </div>
    </div>

    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

    <!-- ── Signature notice ── -->
    <?php if ($requiresSig && !$sigObtained && $req['status'] !== 'cancelled'): ?>
    <div class="sig-notice">
        <div class="sig-icon">✍️</div>
        <div class="sig-text">
            <h4>Signature Required</h4>
            <p>
                This document type (<strong><?= e($req['doc_type']) ?></strong>) requires an authorized signature
                before it can be processed. Move the request to <strong>For Signature</strong>, then confirm
                the signature below once it has been physically obtained.
            </p>
        </div>
    </div>
    <?php elseif ($requiresSig && $sigObtained): ?>
    <div class="sig-notice" style="background:#f0fdf4;border-color:#86efac">
        <div class="sig-icon">✅</div>
        <div class="sig-text">
            <h4 style="color:#15803d">Signature Obtained</h4>
            <p style="color:#15803d">Signature was confirmed on <?= fdt($req['signature_obtained_at']) ?>.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Estimated release date ── -->
    <?php if (!empty($req['estimated_release_date'])): ?>
    <div class="release-bar">
        📅 Estimated release date: <strong><?= fd($req['estimated_release_date']) ?></strong>
        <?php if ($req['preferred_release_date']): ?>
            &nbsp;·&nbsp; Preferred by student: <?= fd($req['preferred_release_date']) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Status tracker ── -->
    <div class="card">
        <h3>Request Progress</h3>
        <?php
        // Build the step list — conditionally include for_signature
        if ($requiresSig) {
            $steps = [
                ['key' => 'pending',       'label' => 'Submitted'],
                ['key' => 'for_signature', 'label' => 'For Signature', 'sig' => true],
                ['key' => 'approved',      'label' => 'Approved'],
                ['key' => 'processing',    'label' => 'Processing'],
                ['key' => 'ready',         'label' => 'Ready'],
                ['key' => 'paid',          'label' => 'Paid'],
                ['key' => 'released',      'label' => 'Released'],
            ];
        } else {
            $steps = [
                ['key' => 'pending',    'label' => 'Submitted'],
                ['key' => 'approved',   'label' => 'Approved'],
                ['key' => 'processing', 'label' => 'Processing'],
                ['key' => 'ready',      'label' => 'Ready'],
                ['key' => 'paid',       'label' => 'Paid'],
                ['key' => 'released',   'label' => 'Released'],
            ];
        }

        $statusOrder = array_column($steps, 'key');
        $currentIdx  = array_search($req['status'], $statusOrder);
        if ($currentIdx === false) $currentIdx = 0;
        $fillPct     = $req['status'] === 'cancelled' ? 0 : round(($currentIdx / (count($steps) - 1)) * 100);
        ?>
        <div class="tracker-wrap">
            <div class="tracker">
                <div class="tracker-line-bg"></div>
                <div class="tracker-line-fill" style="width:<?= $fillPct ?>%"></div>
                <div class="tracker-steps">
                    <?php foreach ($steps as $i => $step):
                        $done    = $i < $currentIdx;
                        $current = $i === $currentIdx;
                        $isSig   = !empty($step['sig']);
                        $dotCls  = $done
                            ? ($isSig ? 'sig-done' : 'done')
                            : ($current ? ($isSig ? 'sig-step current' : 'current') : '');
                        $lblCls  = $done ? 'done' : ($current ? 'current' : ($isSig ? 'sig' : ''));
                    ?>
                    <div class="tracker-step">
                        <div class="step-dot <?= $dotCls ?>">
                            <?= $done ? '✓' : ($i + 1) ?>
                        </div>
                        <div class="step-label <?= $lblCls ?>"><?= $step['label'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="layout">

        <!-- ── Left column: request details + update form ── -->
        <div>

            <!-- Request details -->
            <div class="card">
                <h3>Request Details</h3>
                <div class="info-row"><span class="info-label">Reference #</span>     <span class="info-value"><code><?= e($req['request_code']) ?></code></span></div>
                <div class="info-row"><span class="info-label">Document Type</span>   <span class="info-value"><?= e($req['doc_type']) ?><?= $requiresSig ? ' <span class="badge badge-signature" style="font-size:10px">Requires Signature</span>' : '' ?></span></div>
                <div class="info-row"><span class="info-label">Copies</span>           <span class="info-value"><?= $req['copies'] ?></span></div>
                <div class="info-row"><span class="info-label">Total Fee</span>        <span class="info-value"><strong>₱<?= number_format($req['total_fee'], 2) ?></strong></span></div>
                <div class="info-row"><span class="info-label">Purpose</span>          <span class="info-value"><?= e($req['purpose']) ?></span></div>
                <div class="info-row"><span class="info-label">Release Mode</span>     <span class="info-value"><?= ucfirst($req['release_mode']) ?></span></div>
                <?php if ($req['delivery_address']): ?>
                <div class="info-row"><span class="info-label">Delivery Address</span><span class="info-value"><?= e($req['delivery_address']) ?></span></div>
                <?php endif; ?>
                <div class="info-row"><span class="info-label">Submitted</span>        <span class="info-value"><?= fdt($req['requested_at']) ?></span></div>
                <div class="info-row"><span class="info-label">Last Updated</span>     <span class="info-value"><?= fdt($req['updated_at']) ?></span></div>
                <?php if ($req['remarks']): ?>
                <div class="info-row"><span class="info-label">Admin Remarks</span>   <span class="info-value"><?= e($req['remarks']) ?></span></div>
                <?php endif; ?>
            </div>

            <!-- Student info -->
            <div class="card">
                <h3>Student / Requester</h3>
                <div class="info-row"><span class="info-label">Name</span>          <span class="info-value"><?= e($req['student_name']) ?></span></div>
                <div class="info-row"><span class="info-label">ID Number</span>     <span class="info-value"><?= e($req['student_id'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Course</span>        <span class="info-value"><?= e($req['course'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Email</span>         <span class="info-value"><?= e($req['student_email']) ?></span></div>
                <div class="info-row"><span class="info-label">Contact</span>       <span class="info-value"><?= e($req['contact_number'] ?? '—') ?></span></div>
                <div class="info-row"><span class="info-label">Role</span>          <span class="info-value"><?= ucfirst($req['student_role']) ?></span></div>
            </div>

            <!-- Status update form -->
            <?php if (!empty($allowedNext) && $req['status'] !== 'cancelled' && $req['status'] !== 'released'): ?>
            <div class="card">
                <h3>Update Status</h3>
                <form method="POST" action="update_request.php?id=<?= $requestId ?>">

                    <div class="form-group">
                        <label for="new_status">Move to Status</label>
                        <select name="new_status" id="new_status" required onchange="handleStatusChange(this.value)">
                            <option value="">— Select next status —</option>
                            <?php foreach ($allowedNext as $ns): ?>
                                <?php
                                $optCls = '';
                                if ($ns === 'for_signature') $optCls = 'opt-sig';
                                if ($ns === 'cancelled')     $optCls = 'opt-cancel';
                                ?>
                                <option value="<?= e($ns) ?>" class="<?= $optCls ?>">
                                    <?= statusLabel($ns) ?>
                                    <?php if ($ns === 'for_signature'): ?> — Sends email to student<?php endif; ?>
                                    <?php if ($ns === 'approved' && $req['status'] === 'for_signature'): ?> — Confirms signature obtained<?php endif; ?>
                                    <?php if ($ns === 'ready'): ?> — Notifies student to pay<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Signature confirmation checkbox (shown only when moving to approved from for_signature) -->
                    <div id="sig-confirm-wrap" style="display:none;background:#fdf4ff;border:1.5px solid #e879f9;border-radius:8px;padding:.75rem 1rem;margin-bottom:.9rem">
                        <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:0.85rem;color:#86198f">
                            <input type="checkbox" name="signature_confirmed" id="sigConfirm" style="margin-top:2px;width:16px;height:16px" required>
                            <span>I confirm that the physical signature has been obtained and verified for this request.</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="notes">Admin Notes / Remarks <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
                        <textarea name="notes" id="notes"
                                  placeholder="e.g. Sent for Dean's signature, approved after review…"></textarea>
                    </div>

                    <button type="submit" class="btn-update" id="submitBtn">
                        Update Status
                    </button>
                </form>
            </div>
            <?php elseif ($req['status'] === 'released'): ?>
            <div class="card" style="text-align:center;padding:1.5rem">
                <div style="font-size:2rem;margin-bottom:.5rem">✅</div>
                <div style="font-weight:700;color:#065f46;font-size:1rem">Document Released</div>
                <div style="font-size:0.82rem;color:#6b7280;margin-top:.3rem">This request has been completed.</div>
            </div>
            <?php elseif ($req['status'] === 'cancelled'): ?>
            <div class="card" style="text-align:center;padding:1.5rem">
                <div style="font-size:2rem;margin-bottom:.5rem">✕</div>
                <div style="font-weight:700;color:#991b1b;font-size:1rem">Request Cancelled</div>
            </div>
            <?php endif; ?>

        </div>

        <!-- ── Right column: quick actions + log ── -->
        <div>

            <!-- Quick actions -->
            <div class="card">
                <h3>Quick Actions</h3>

                <?php if ($req['status'] === 'for_signature'): ?>
                <form method="POST" action="update_request.php?id=<?= $requestId ?>">
                    <input type="hidden" name="new_status" value="approved">
                    <input type="hidden" name="notes" value="Signature obtained and verified by registrar.">
                    <button class="btn-quick btn-sig-confirm" type="submit"
                            onclick="return confirm('Confirm that the physical signature has been obtained?')">
                        ✍️ Confirm Signature Obtained
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($req['status'] === 'paid' && $req['stub_code']): ?>
                <a href="claim_stub.php?code=<?= e($req['stub_code']) ?>"
                   target="_blank" class="btn-quick btn-stub">
                    🖨 View / Print Claim Stub
                </a>
                <?php endif; ?>

                <?php if ($req['status'] === 'ready' && !$req['stub_code']): ?>
                <a href="process_payment.php?id=<?= $requestId ?>" class="btn-quick btn-payment">
                    💳 Record Payment
                </a>
                <?php endif; ?>

                <a href="mailto:<?= e($req['student_email']) ?>"
                   class="btn-quick" style="background:#f3f4f6;color:#374151;border:1.5px solid #e5e7eb">
                    ✉️ Email Student
                </a>
            </div>

            <!-- Payment info (if paid) -->
            <?php if ($req['official_receipt_number']): ?>
            <div class="card">
                <h3>Payment Record</h3>
                <div class="info-row"><span class="info-label">OR Number</span>   <span class="info-value"><code><?= e($req['official_receipt_number']) ?></code></span></div>
                <div class="info-row"><span class="info-label">Amount Paid</span> <span class="info-value">₱<?= number_format($req['paid_amount'], 2) ?></span></div>
                <div class="info-row"><span class="info-label">Payment Date</span><span class="info-value"><?= fdt($req['payment_date']) ?></span></div>
            </div>
            <?php endif; ?>

            <!-- Activity log -->
            <div class="card">
                <h3>Activity Log</h3>
                <?php if ($logs->num_rows > 0): ?>
                    <?php while ($log = $logs->fetch_assoc()):
                        $isSigLog = ($log['new_status'] === 'for_signature' || $log['new_status'] === 'approved');
                    ?>
                    <div class="log-item">
                        <div class="log-dot <?= $isSigLog ? 'sig' : '' ?>"></div>
                        <div>
                            <div>
                                <?= statusBadge($log['old_status'] ?? 'pending') ?>
                                <span style="font-size:0.72rem;color:#9ca3af;margin:0 4px">→</span>
                                <?= statusBadge($log['new_status']) ?>
                            </div>
                            <div class="log-meta">
                                By <?= e($log['changed_by_name']) ?> · <?= fdt($log['changed_at']) ?>
                            </div>
                            <?php if ($log['notes']): ?>
                            <div class="log-notes"><?= e($log['notes']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="font-size:0.82rem;color:#9ca3af;text-align:center;padding:.5rem 0">No activity yet.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>

</main>

<script>
function handleStatusChange(val) {
    var sigWrap  = document.getElementById('sig-confirm-wrap');
    var sigCheck = document.getElementById('sigConfirm');
    var submitBtn = document.getElementById('submitBtn');

    // Show signature confirmation when moving from for_signature → approved
    var currentStatus = '<?= e($req['status']) ?>';
    if (val === 'approved' && currentStatus === 'for_signature') {
        sigWrap.style.display = 'block';
        sigCheck.required = true;
    } else {
        sigWrap.style.display = 'none';
        sigCheck.required = false;
        sigCheck.checked = false;
    }

    // Update submit button text
    var labels = {
        'for_signature' : '✍️ Send for Signature',
        'approved'      : '✅ Mark as Approved',
        'processing'    : '🔄 Mark as Processing',
        'ready'         : '📦 Mark as Ready',
        'paid'          : '💳 Mark as Paid',
        'released'      : '✅ Mark as Released',
        'cancelled'     : '✕ Cancel Request',
    };
    submitBtn.textContent = labels[val] || 'Update Status';
    submitBtn.style.background = val === 'cancelled' ? '#ef4444' : '';
}
</script>

</body>
</html>