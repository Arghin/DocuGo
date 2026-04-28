<?php
require_once '../includes/config.php';
requireLogin();

$conn = getConnection();

$userId = $_SESSION['user_id'];
$role = $_SESSION['user_role'] ?? 'student';

if ($role !== 'alumni') {
    header('Location: dashboard.php');
    exit();
}

$stmt = $conn->prepare("SELECT first_name, last_name, course, year_graduated, student_id FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$alumniDocTypes = $conn->query("
    SELECT id, name, description, fee, processing_days 
    FROM document_types 
    WHERE is_active = 1 
    AND name NOT LIKE '%Enrollment%'
    ORDER BY fee ASC
");

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_doc'])) {
    $docTypeId = intval($_POST['document_type_id'] ?? 0);
    $copies = intval($_POST['copies'] ?? 1);
    $purpose = trim($_POST['purpose'] ?? '');
    $releaseMode = $_POST['release_mode'] ?? 'pickup';
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $preferredDate = $_POST['preferred_release_date'] ?? null;

    if ($docTypeId <= 0) {
        $error = "Please select a document.";
    } elseif ($copies < 1 || $copies > 20) {
        $error = "Copies must be between 1 and 20.";
    } elseif ($purpose === '') {
        $error = "Please state the purpose.";
    } elseif ($releaseMode === 'delivery' && $deliveryAddress === '') {
        $error = "Please provide delivery address.";
    } else {
        $requestCode = 'DOC-' . date('Y') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        
        $stmt = $conn->prepare("
            INSERT INTO document_requests 
            (user_id, document_type_id, request_code, copies, purpose, 
             release_mode, delivery_address, preferred_release_date, status, requested_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("iisissss", $userId, $docTypeId, $requestCode, $copies, $purpose, $releaseMode, $deliveryAddress, $preferredDate);
        
        if ($stmt->execute()) {
            $success = "Request submitted! Code: <strong>$requestCode</strong>";
        } else {
            $error = "Failed to submit request.";
        }
        $stmt->close();
    }
}

function e($v) { return htmlspecialchars($v ?? ''); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Alumni Documents</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f4f8;display:flex}
.sidebar{width:220px;background:#1a56db;color:#fff;height:100vh;position:fixed;display:flex;flex-direction:column}
.sidebar-brand{padding:1.4rem;font-size:1.5rem;font-weight:800;border-bottom:1px solid rgba(255,255,255,0.2)}
.sidebar-brand small{display:block;font-size:0.7rem;opacity:0.8}
.sidebar-menu{flex:1;padding:1rem 0}
.menu-label{font-size:0.7rem;padding:0.5rem 1.2rem;opacity:0.6;text-transform:uppercase}
.menu-item{display:block;padding:0.7rem 1.2rem;color:#fff;text-decoration:none;opacity:0.85}
.menu-item:hover,.menu-item.active{background:rgba(255,255,255,0.15);opacity:1}
.sidebar-footer{padding:1rem;border-top:1px solid rgba(255,255,255,0.2)}
.main{margin-left:220px;padding:2rem;width:100%}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem}
.user-chip{background:#fff;padding:0.4rem 0.8rem;border-radius:8px}
.alert{padding:0.9rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:0.9rem}
.alert-success{background:#d1fae5;color:#065f46;border-left:4px solid #059669}
.alert-error{background:#fee2e2;color:#991b1b;border-left:4px solid #dc2626}
.card{background:white;padding:1.5rem;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,0.08);margin-bottom:1rem}
.card h3{color:#1a56db;font-size:1rem;margin-bottom:1rem;padding-bottom:0.6rem;border-bottom:1px solid #e2e8f0}
.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1rem}
.doc-card{background:#f8fafc;border:2px solid #e2e8f0;border-radius:12px;padding:1.2rem;cursor:pointer;transition:all 0.15s}
.doc-card:hover,.doc-card.selected{border-color:#1a56db;background:#eff6ff}
.doc-card .name{font-weight:700;font-size:1rem;color:#1e293b;margin-bottom:0.3rem}
.doc-card .desc{font-size:0.8rem;color:#64748b;margin-bottom:0.6rem}
.doc-card .meta{font-size:0.8rem;color:#1a56db;font-weight:600}
.doc-card input{float:right;margin-top:0.3rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
label{display:block;margin-top:0.8rem;margin-bottom:0.3rem;font-weight:600;font-size:0.82rem;color:#334155}
input,textarea,select{width:100%;padding:0.6rem 0.75rem;border-radius:8px;border:1px solid #cbd5e1;font-size:0.9rem;font-family:inherit;background:#fff}
input:focus,textarea:focus,select:focus{outline:none;border-color:#1a56db;box-shadow:0 0 0 3px rgba(26,86,219,0.15)}
textarea{resize:vertical;min-height:70px}
.hint{font-size:0.75rem;color:#64748b;margin-top:0.3rem}
.mode-group{display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;margin-top:0.3rem}
.mode-opt{border:2px solid #e2e8f0;border-radius:8px;padding:0.8rem;cursor:pointer;transition:all 0.15s}
.mode-opt:hover{border-color:#94a3b8}
.mode-opt input{display:none}
.mode-opt.selected{border-color:#1a56db;background:#eff6ff}
.mode-opt .title{font-weight:600;font-size:0.88rem}
.mode-opt .desc{font-size:0.75rem;color:#64748b}
.delivery-box{display:none;margin-top:0.8rem}
.btn{padding:0.8rem 1.4rem;background:#1a56db;color:white;border:none;border-radius:8px;font-weight:600;font-size:0.95rem;cursor:pointer;margin-top:1rem;width:100%;transition:background 0.15s}
.btn:hover{background:#1447c0}
.badge{padding:3px 8px;border-radius:10px;font-size:0.7rem;font-weight:600}
.badge-pending{background:#fef3c7;color:#92400e}
.badge-processing{background:#dbeafe;color:#1e40af}
.badge-ready{background:#d1fae5;color:#065f46}
.badge-released{background:#ede9fe;color:#5b21b6}
.history-table{width:100%;border-collapse:collapse}
.history-table th,.history-table td{padding:0.7rem;border-bottom:1px solid #eee;font-size:0.85rem;text-align:left}
.history-table th{background:#f8fafc}
@media(max-width:900px){.sidebar{display:none}.main{margin-left:0;padding:1rem}.form-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<aside class="sidebar">
<div class="sidebar-brand">DocuGo<small><?= ucfirst($role) ?> Portal</small></div>
<div class="sidebar-menu">
<div class="menu-label">Main</div>
<a href="dashboard.php" class="menu-item">🏠 Dashboard</a>
<a href="request_form.php" class="menu-item">📄 Request Document</a>
<a href="my_requests.php" class="menu-item">📋 My Requests</a>
<div class="menu-label">Alumni</div>
<a href="graduate_tracer.php" class="menu-item">📊 Graduate Tracer</a>
<a href="employment_profile.php" class="menu-item">💼 Employment Profile</a>
<a href="alumni_documents.php" class="menu-item active">🎓 Alumni Documents</a>
<div class="menu-label">Account</div>
<a href="profile.php" class="menu-item">👤 Profile</a>
</div>
<div class="sidebar-footer"><a href="../logout.php" style="color:white;text-decoration:none;">🚪 Logout</a></div>
</aside>
<main class="main">
<div class="topbar"><h2>🎓 Alumni Documents</h2><div class="user-chip">👤 <?= e($user['first_name'].' '.$user['last_name']) ?></div></div>
<?php if($success):?><div class="alert alert-success">✅ <?= $success ?> <a href="my_requests.php" style="color:#065f46;font-weight:700;">View My Requests</a></div><?php endif;?>
<?php if($error):?><div class="alert alert-error">⚠️ <?= e($error) ?></div><?php endif;?>

<div class="card">
<h3>Select Document</h3>
<form method="POST" id="requestForm">
<div class="doc-grid">
<?php while($doc=$alumniDocTypes->fetch_assoc()):?>
<label class="doc-card" id="card-<?= $doc['id'] ?>">
<input type="radio" name="document_type_id" value="<?= $doc['id'] ?>" data-fee="<?= $doc['fee'] ?>" data-days="<?= $doc['processing_days'] ?>" required>
<div class="name"><?= e($doc['name']) ?></div>
<div class="desc"><?= e($doc['description'] ?? 'Official document') ?></div>
<div class="meta">₱<?= number_format($doc['fee'],2) ?> · <?= $doc['processing_days'] ?> day<?= $doc['processing_days']>1?'s':'' ?></div>
</label>
<?php endwhile;?>
</div>

<h3>Request Details</h3>
<div class="form-row">
<div><label>Number of Copies *</label><input type="number" name="copies" value="1" min="1" max="20" required></div>
<div><label>Preferred Release Date</label><input type="date" name="preferred_release_date" min="<?= date('Y-m-d') ?>"></div>
</div>
<label>Purpose *</label>
<textarea name="purpose" placeholder="e.g., Job application, scholarship, further studies..." required></textarea>

<label>Release Mode *</label>
<div class="mode-group">
<label class="mode-opt selected" id="opt-pickup"><input type="radio" name="release_mode" value="pickup" checked><div class="title">🏫 Pickup</div><div class="desc">Claim at Registrar's Office</div></label>
<label class="mode-opt" id="opt-delivery"><input type="radio" name="release_mode" value="delivery"><div class="title">🚚 Delivery</div><div class="desc">Ship to your address</div></label>
</div>
<div class="delivery-box" id="deliveryBox">
<label>Delivery Address *</label>
<textarea name="delivery_address" placeholder="House No., Street, Barangay, City, Province, ZIP"></textarea>
</div>

<button type="submit" name="request_doc" class="btn">📨 Submit Request</button>
</form>
</div>

<?php
$recent = $conn->prepare("
    SELECT dr.request_code, dr.status, dr.requested_at, dr.copies, dt.name AS doc_name, dt.fee
    FROM document_requests dr
    JOIN document_types dt ON dr.document_type_id = dt.id
    WHERE dr.user_id = ?
    ORDER BY dr.requested_at DESC LIMIT 5
");
$recent->bind_param("i", $userId);
$recent->execute();
$recentReqs = $recent->get_result();
if ($recentReqs->num_rows > 0):
?>
<div class="card">
<h3>Recent Requests</h3>
<table class="history-table">
<tr><th>Code</th><th>Document</th><th>Copies</th><th>Total</th><th>Date</th><th>Status</th></tr>
<?php while($r=$recentReqs->fetch_assoc()):?>
<tr>
<td><strong><?= e($r['request_code']) ?></strong></td>
<td><?= e($r['doc_name']) ?></td>
<td><?= $r['copies'] ?></td>
<td>₱<?= number_format($r['fee']*$r['copies'],2) ?></td>
<td><?= date('M d, Y',strtotime($r['requested_at'])) ?></td>
<td><span class="badge badge-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
</tr>
<?php endwhile;?>
</table>
</div>
<?php endif; $recent->close(); ?>

</main>
<script>
const cards=document.querySelectorAll('.doc-card');
cards.forEach(c=>{
    c.addEventListener('click',()=>{
        cards.forEach(x=>x.classList.remove('selected'));
        c.classList.add('selected');
        c.querySelector('input').checked=true;
    });
});
const optPickup=document.getElementById('opt-pickup'),optDelivery=document.getElementById('opt-delivery'),deliveryBox=document.getElementById('deliveryBox');
optPickup.addEventListener('click',()=>{optPickup.classList.add('selected');optDelivery.classList.remove('selected');deliveryBox.style.display='none';});
optDelivery.addEventListener('click',()=>{optDelivery.classList.add('selected');optPickup.classList.remove('selected');deliveryBox.style.display='block';});
</script>
</body>
</html>
<?php $conn->close(); ?>