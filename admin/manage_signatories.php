<?php
// ================================================================
// admin/manage_signatories.php
// Admin page: configure signatory roles per document type,
// and manage signatory user accounts.
// ================================================================
require_once '../includes/config.php';
requireLogin();

if (!in_array($_SESSION['user_role'] ?? '', ['admin'])) {
    header('Location: ' . SITE_URL . '/admin/dashboard.php');
    exit();
}

$conn    = getConnection();
$adminId = $_SESSION['user_id'];
$error   = $success = '';

// ── Handle POST actions ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Add a signatory role to a document type
    if ($action === 'add_role') {
        $dtId      = intval($_POST['document_type_id'] ?? 0);
        $roleName  = trim($_POST['role_name'] ?? '');
        $roleLabel = trim($_POST['role_label'] ?? '');
        $orderNo   = intval($_POST['order_no'] ?? 1);

        if (!$dtId || !$roleName || !$roleLabel) {
            $error = 'Document type, role name, and label are all required.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO signatory_roles (document_type_id, role_name, role_label, order_no)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param("issi", $dtId, $roleName, $roleLabel, $orderNo);
            $stmt->execute() ? $success = 'Signatory role added.' : $error = 'Failed to add role.';
            $stmt->close();
        }
    }

    // Delete a signatory role
    if ($action === 'delete_role') {
        $roleId = intval($_POST['role_id'] ?? 0);
        $stmt   = $conn->prepare("DELETE FROM signatory_roles WHERE id = ?");
        $stmt->bind_param("i", $roleId);
        $stmt->execute();
        $stmt->close();
        $success = 'Signatory role removed.';
    }

    // Toggle signatory role active/inactive
    if ($action === 'toggle_role') {
        $roleId   = intval($_POST['role_id'] ?? 0);
        $newState = intval($_POST['new_state'] ?? 0);
        $stmt     = $conn->prepare("UPDATE signatory_roles SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $newState, $roleId);
        $stmt->execute();
        $stmt->close();
        $success = $newState ? 'Role activated.' : 'Role deactivated.';
    }

    // Toggle requires_signature on a document type
    if ($action === 'toggle_sig') {
        $dtId     = intval($_POST['doc_id'] ?? 0);
        $newState = intval($_POST['new_state'] ?? 0);
        $stmt     = $conn->prepare("UPDATE document_types SET requires_signature = ? WHERE id = ?");
        $stmt->bind_param("ii", $newState, $dtId);
        $stmt->execute();
        $stmt->close();
        $success = $newState ? 'Signature requirement enabled.' : 'Signature requirement disabled.';
    }

    // Create a new signatory user account
    if ($action === 'add_user') {
        $fname    = trim($_POST['first_name'] ?? '');
        $lname    = trim($_POST['last_name']  ?? '');
        $email    = trim($_POST['email']      ?? '');
        $sigRole  = trim($_POST['sig_role']   ?? '');
        $password = $_POST['password'] ?? '';

        if (!$fname || !$lname || !$email || !$sigRole || !$password) {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt   = $conn->prepare("
                INSERT INTO users (first_name, last_name, email, password, role, signatory_role, status)
                VALUES (?, ?, ?, ?, 'signatory', ?, 'active')
            ");
            $stmt->bind_param("sssss", $fname, $lname, $email, $hashed, $sigRole);
            $stmt->execute()
                ? $success = "Signatory account for {$fname} {$lname} created."
                : $error   = 'Failed to create account. Email may already exist.';
            $stmt->close();
        }
    }

    // Delete a signatory user
    if ($action === 'delete_user') {
        $uid  = intval($_POST['user_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'signatory'");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->close();
        $success = 'Signatory account deleted.';
    }
}

// ── Fetch data ────────────────────────────────────────────
$docTypes = $conn->query("
    SELECT id, name, requires_signature FROM document_types WHERE is_active = 1 ORDER BY name
");

$sigRoles = $conn->query("
    SELECT sr.*, dt.name AS doc_name
    FROM signatory_roles sr
    JOIN document_types  dt ON sr.document_type_id = dt.id
    ORDER BY dt.name, sr.order_no
");

$sigUsers = $conn->query("
    SELECT id, first_name, last_name, email, signatory_role, status
    FROM users WHERE role = 'signatory'
    ORDER BY signatory_role, first_name
");

$conn->close();

// Predefined office options
$officeOptions = [
    'library'   => 'Library Office',
    'comp_lab'  => 'Computer Laboratory',
    'adviser'   => 'Adviser',
    'admin'     => 'Admin Office',
    'cashier'   => 'Cashier / Finance',
    'dean'      => 'Dean\'s Office',
    'registrar' => 'Registrar',
];

function e($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Signatories — DocuGo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; display: flex; }
        .sidebar { width: 220px; background: #1a56db; color: #fff; height: 100vh; position: fixed; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 1.4rem; font-size: 1.5rem; font-weight: 800; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .sidebar-brand small { display: block; font-size: 0.7rem; opacity: 0.8; }
        .sidebar-menu { flex: 1; padding: 1rem 0; }
        .menu-label { font-size: 0.7rem; padding: 0.5rem 1.2rem; opacity: 0.6; text-transform: uppercase; }
        .menu-item { display: block; padding: 0.7rem 1.2rem; color: #fff; text-decoration: none; opacity: 0.85; font-size: 0.875rem; }
        .menu-item:hover { background: rgba(255,255,255,0.1); }
        .menu-item.active { background: rgba(255,255,255,0.15); border-left: 3px solid #fff; font-weight: 600; opacity: 1; }
        .sidebar-footer { padding: 1rem; border-top: 1px solid rgba(255,255,255,0.2); }
        .sidebar-footer a { color: white; text-decoration: none; font-size: 0.875rem; }
        .main { margin-left: 220px; padding: 2rem; width: 100%; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .topbar h1 { font-size: 1.5rem; font-weight: 700; color: #111827; }
        .alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.2rem; font-size: 0.875rem; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .card { background: white; padding: 1.2rem; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,0.08); margin-bottom: 1.2rem; }
        .card h3 { font-size: 0.95rem; font-weight: 700; color: #111827; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #f3f4f6; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
        .form-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; }
        .form-group { margin-bottom: 0.75rem; }
        .form-group label { display: block; font-size: 0.78rem; font-weight: 600; color: #374151; margin-bottom: 0.25rem; }
        .form-group input,
        .form-group select {
            width: 100%; padding: 0.55rem 0.8rem; border: 1.5px solid #d1d5db; border-radius: 8px;
            font-family: 'Segoe UI',Arial,sans-serif; font-size: 0.875rem; color: #111827; outline: none;
        }
        .form-group input:focus, .form-group select:focus { border-color: #1a56db; box-shadow: 0 0 0 3px rgba(26,86,219,.08); }
        .btn-add { padding: 0.6rem 1.2rem; background: #1a56db; color: #fff; border: none; border-radius: 8px; font-family: inherit; font-size: 0.875rem; font-weight: 700; cursor: pointer; transition: background .2s; }
        .btn-add:hover { background: #1447c0; }
        table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        th { background: #f9fafb; border-bottom: 1.5px solid #e5e7eb; padding: .6rem .85rem; text-align: left; font-size: .75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
        td { padding: .6rem .85rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        tr:hover td { background: #fafafa; }
        .badge { padding: 2px 8px; border-radius: 10px; font-size: .7rem; font-weight: 700; }
        .badge-yes  { background: #fce7f3; color: #9d174d; }
        .badge-no   { background: #f3f4f6; color: #6b7280; }
        .badge-act  { background: #d1fae5; color: #065f46; }
        .badge-inact{ background: #fee2e2; color: #991b1b; }
        .act-btn { display: inline-block; padding: 2px 9px; border-radius: 6px; font-size: .75rem; font-weight: 600; cursor: pointer; border: none; transition: opacity .15s; }
        .act-btn:hover { opacity: .8; }
        .act-del  { background: #fee2e2; color: #991b1b; }
        .act-tog  { background: #fef3c7; color: #92400e; }
        .act-act  { background: #d1fae5; color: #065f46; }
        .section-tabs { display: flex; gap: .25rem; background: #fff; padding: .35rem; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,.08); margin-bottom: 1.2rem; }
        .stab { padding: .4rem .9rem; border-radius: 7px; font-size: .82rem; font-weight: 500; color: #6b7280; cursor: pointer; transition: all .15s; border: none; background: none; font-family: inherit; }
        .stab.on { background: #1a56db; color: #fff; font-weight: 600; }
        .panel { display: none; }
        .panel.on { display: block; }
        @media (max-width: 900px) { .sidebar { display: none; } .main { margin-left: 0; padding: 1rem; } .form-row, .form-row-3 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">DocuGo<small>Admin Panel</small></div>
    <div class="sidebar-menu">
        <div class="menu-label">Dashboard</div>
        <a href="dashboard.php"    class="menu-item">🏠 Dashboard</a>
        <a href="requests.php"     class="menu-item">📄 Document Requests</a>
        <a href="users.php"        class="menu-item">👥 User Accounts</a>
        <div class="menu-label">Records</div>
        <a href="alumni.php"       class="menu-item">🎓 Alumni</a>
        <a href="reports.php"      class="menu-item">📈 Reports</a>
        <div class="menu-label">Settings</div>
        <a href="document_types.php"     class="menu-item">📋 Document Types</a>
        <a href="manage_signatories.php" class="menu-item active">✍️ Signatories</a>
    </div>
    <div class="sidebar-footer"><a href="../logout.php">🚪 Logout</a></div>
</aside>

<main class="main">
    <div class="topbar">
        <h1>✍️ Manage Signatories</h1>
    </div>

    <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

    <!-- Section tabs -->
    <div class="section-tabs">
        <button class="stab on" onclick="switchPanel('docs',this)">📋 Document Signature Settings</button>
        <button class="stab"    onclick="switchPanel('roles',this)">🔧 Signatory Roles</button>
        <button class="stab"    onclick="switchPanel('users',this)">👤 Signatory Accounts</button>
    </div>

    <!-- ── Panel 1: Document Signature Settings ── -->
    <div class="panel on" id="panel-docs">
        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:1.2rem 1.2rem .5rem"><h3 style="margin-bottom:0">Document Types — Signature Toggle</h3></div>
            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Document Type</th><th>Requires Signature</th><th>Signatory Offices Configured</th><th>Toggle</th></tr></thead>
                    <tbody>
                    <?php
                    $docTypes->data_seek(0);
                    while ($dt = $docTypes->fetch_assoc()):
                        // Count configured roles
                        $tmpConn = getConnection();
                        $cnt = $tmpConn->query("SELECT COUNT(*) AS c FROM signatory_roles WHERE document_type_id = {$dt['id']} AND is_active = 1")->fetch_assoc()['c'];
                        $tmpConn->close();
                    ?>
                    <tr>
                        <td style="font-weight:600"><?= e($dt['name']) ?></td>
                        <td>
                            <?php if ($dt['requires_signature']): ?>
                                <span class="badge badge-yes">✍️ Required</span>
                            <?php else: ?>
                                <span class="badge badge-no">Not required</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $cnt > 0 ? "<span class='badge badge-act'>{$cnt} offices</span>" : "<span class='badge badge-inact'>None set</span>" ?></td>
                        <td>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action"    value="toggle_sig">
                                <input type="hidden" name="doc_id"    value="<?= $dt['id'] ?>">
                                <input type="hidden" name="new_state" value="<?= $dt['requires_signature'] ? 0 : 1 ?>">
                                <button class="act-btn <?= $dt['requires_signature'] ? 'act-tog' : 'act-act' ?>">
                                    <?= $dt['requires_signature'] ? 'Disable Signature' : 'Enable Signature' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Panel 2: Signatory Roles ── -->
    <div class="panel" id="panel-roles">
        <div class="card">
            <h3>Add Signatory Role to a Document Type</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_role">
                <div class="form-row">
                    <div class="form-group">
                        <label>Document Type *</label>
                        <select name="document_type_id" required>
                            <option value="">-- Select --</option>
                            <?php $docTypes->data_seek(0); while ($dt = $docTypes->fetch_assoc()): ?>
                            <option value="<?= $dt['id'] ?>"><?= e($dt['name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Office / Role *</label>
                        <select name="role_name" required onchange="fillLabel(this)">
                            <option value="">-- Select Office --</option>
                            <?php foreach ($officeOptions as $val => $lbl): ?>
                            <option value="<?= $val ?>"><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                            <option value="custom">Custom…</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Display Label * <span style="color:#9ca3af;font-weight:400">(shown to signatories)</span></label>
                        <input type="text" name="role_label" id="roleLabelInput"
                               placeholder="e.g. Library Office" required>
                    </div>
                    <div class="form-group">
                        <label>Signing Order * <span style="color:#9ca3af;font-weight:400">(1 = signs first)</span></label>
                        <input type="number" name="order_no" value="1" min="1" max="20" required>
                    </div>
                </div>
                <button type="submit" class="btn-add">+ Add Signatory Role</button>
            </form>
        </div>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:1.2rem 1.2rem .5rem"><h3 style="margin-bottom:0">All Configured Signatory Roles</h3></div>
            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Document Type</th><th>Office / Role</th><th>Label</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php
                    $sigRoles->data_seek(0);
                    if ($sigRoles->num_rows === 0): ?>
                        <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:1.5rem">No signatory roles configured yet.</td></tr>
                    <?php else:
                        while ($sr = $sigRoles->fetch_assoc()): ?>
                        <tr>
                            <td style="font-weight:600"><?= e($sr['doc_name']) ?></td>
                            <td><code style="font-size:.78rem"><?= e($sr['role_name']) ?></code></td>
                            <td><?= e($sr['role_label']) ?></td>
                            <td>#<?= $sr['order_no'] ?></td>
                            <td><span class="badge <?= $sr['is_active'] ? 'badge-act' : 'badge-inact' ?>"><?= $sr['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                            <td style="white-space:nowrap">
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="action"    value="toggle_role">
                                    <input type="hidden" name="role_id"   value="<?= $sr['id'] ?>">
                                    <input type="hidden" name="new_state" value="<?= $sr['is_active'] ? 0 : 1 ?>">
                                    <button class="act-btn <?= $sr['is_active'] ? 'act-tog' : 'act-act' ?>"><?= $sr['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Remove this signatory role?')">
                                    <input type="hidden" name="action"  value="delete_role">
                                    <input type="hidden" name="role_id" value="<?= $sr['id'] ?>">
                                    <button class="act-btn act-del">Remove</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile;
                    endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Panel 3: Signatory User Accounts ── -->
    <div class="panel" id="panel-users">
        <div class="card">
            <h3>Create Signatory Account</h3>
            <p style="font-size:.82rem;color:#6b7280;margin-bottom:.85rem">
                Each signatory office needs a login account. They use the
                <strong>Signature Panel</strong> at <code>/signatory/dashboard.php</code>.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" placeholder="Library" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" placeholder="Office" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" placeholder="library@adfc.edu.ph" required>
                    </div>
                    <div class="form-group">
                        <label>Password * <span style="color:#9ca3af;font-weight:400">(min 6 chars)</span></label>
                        <input type="password" name="password" placeholder="Set a password" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Assign Office Role * <span style="color:#9ca3af;font-weight:400">(must match a configured signatory_roles.role_name)</span></label>
                    <select name="sig_role" required>
                        <option value="">-- Select Office --</option>
                        <?php foreach ($officeOptions as $val => $lbl): ?>
                        <option value="<?= $val ?>"><?= e($lbl) ?> (<?= $val ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-add">+ Create Account</button>
            </form>
        </div>

        <div class="card" style="padding:0;overflow:hidden">
            <div style="padding:1.2rem 1.2rem .5rem"><h3 style="margin-bottom:0">Signatory Accounts</h3></div>
            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>Name</th><th>Email</th><th>Office / Role</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if ($sigUsers->num_rows === 0): ?>
                        <tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:1.5rem">No signatory accounts yet.</td></tr>
                    <?php else: while ($su = $sigUsers->fetch_assoc()): ?>
                        <tr>
                            <td style="font-weight:600"><?= e($su['first_name'] . ' ' . $su['last_name']) ?></td>
                            <td><?= e($su['email']) ?></td>
                            <td>
                                <?= e($officeOptions[$su['signatory_role']] ?? $su['signatory_role']) ?>
                                <br><code style="font-size:.7rem;color:#9ca3af"><?= e($su['signatory_role']) ?></code>
                            </td>
                            <td><span class="badge <?= $su['status'] === 'active' ? 'badge-act' : 'badge-inact' ?>"><?= ucfirst($su['status']) ?></span></td>
                            <td>
                                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this signatory account?')">
                                    <input type="hidden" name="action"  value="delete_user">
                                    <input type="hidden" name="user_id" value="<?= $su['id'] ?>">
                                    <button class="act-btn act-del">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
// Auto-fill label when selecting a predefined office
const labels = <?= json_encode($officeOptions) ?>;
function fillLabel(sel) {
    const inp = document.getElementById('roleLabelInput');
    if (sel.value && sel.value !== 'custom') {
        inp.value = labels[sel.value] || '';
    } else if (sel.value === 'custom') {
        inp.value = '';
        inp.focus();
    }
}

// Panel tab switching
function switchPanel(id, btn) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('on'));
    document.querySelectorAll('.stab').forEach(b => b.classList.remove('on'));
    document.getElementById('panel-' + id).classList.add('on');
    btn.classList.add('on');
}
</script>
</body>
</html> 