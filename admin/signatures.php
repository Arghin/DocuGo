<?php
session_start();
require_once '../includes/db_connect.php';

// Check if user is logged in and has office role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['office_role'])) {
    header("Location: ../index.php");
    exit();
}

$officeRole = $_SESSION['office_role'];
$officeCode = $officeRole; // Assuming office_role matches office_code in signature_offices table

// Get pending signatures for this office
$query = "
    SELECT 
        rs.*, 
        dr.request_code,
        dr.document_type_id,
        dt.document_name,
        u.first_name,
        u.last_name,
        u.student_id,
        so.office_name
    FROM request_signatures rs
    JOIN signature_offices so ON rs.office_id = so.id
    JOIN document_requests dr ON rs.request_id = dr.id
    JOIN document_types dt ON dr.document_type_id = dt.id
    JOIN users u ON dr.user_id = u.id
    WHERE so.office_code = ?
    ORDER BY rs.created_at ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $officeCode);
$stmt->execute();
$signatures = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Signatures - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h2>Document Signatures</h2>
        <h4>Office: <?php echo htmlspecialchars($officeRole); ?></h4>
        
        <table class="table table-bordered mt-3">
            <thead>
                <tr>
                    <th>Request Code</th>
                    <th>Student Name</th>
                    <th>Student ID</th>
                    <th>Document Type</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $signatures->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['request_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                    <td><?php echo htmlspecialchars($row['document_name']); ?></td>
                    <td>
                        <?php if ($row['status'] == 'pending'): ?>
                            <span class="badge bg-warning">Pending</span>
                        <?php elseif ($row['status'] == 'signed'): ?>
                            <span class="badge bg-success">Signed</span>
                        <?php elseif ($row['status'] == 'rejected'): ?>
                            <span class="badge bg-danger">Rejected</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['status'] == 'pending'): ?>
                            <button class="btn btn-sm btn-success" onclick="signDocument(<?php echo $row['id']; ?>)">Sign</button>
                            <button class="btn btn-sm btn-danger" onclick="rejectDocument(<?php echo $row['id']; ?>)">Reject</button>
                        <?php else: ?>
                            <span class="text-muted">Already <?php echo $row['status']; ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function signDocument(id) {
            if (confirm('Are you sure you want to sign this document?')) {
                window.location.href = 'sign_action.php?id=' + id + '&action=sign';
            }
        }
        
        function rejectDocument(id) {
            let remarks = prompt('Please enter rejection reason:');
            if (remarks) {
                window.location.href = 'sign_action.php?id=' + id + '&action=reject&remarks=' + encodeURIComponent(remarks);
            }
        }
    </script>
</body>
</html>