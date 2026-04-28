<?php
// Announcement helper functions

function getAnnouncements($conn, $userId = null, $limit = 10) {
    $sql = "SELECT a.*, u.first_name, u.last_name
            FROM announcements a
            LEFT JOIN users u ON a.created_by = u.id
            WHERE 1=1";

    $params = [];
    $types = '';

    if ($userId) {
        $sql .= " AND (a.target_type = 'all' OR (a.target_type = 'user' AND a.target_user_id = ?))";
        $params[] = $userId;
        $types .= 'i';
    }

    $sql .= " ORDER BY a.created_at DESC LIMIT ?";
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $result;
}

function createAnnouncement($conn, $title, $message, $targetType, $targetUserId = null, $createdBy) {
    $stmt = $conn->prepare("
        INSERT INTO announcements (title, message, target_type, target_user_id, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('sssii', $title, $message, $targetType, $targetUserId, $createdBy);
    $success = $stmt->execute();
    $announcementId = $conn->insert_id;
    $stmt->close();

    return ['success' => $success, 'id' => $announcementId];
}

function updateAnnouncement($conn, $id, $title, $message, $targetType, $targetUserId = null) {
    $stmt = $conn->prepare("
        UPDATE announcements
        SET title = ?, message = ?, target_type = ?, target_user_id = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param('sssii', $title, $message, $targetType, $targetUserId, $id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function deleteAnnouncement($conn, $id) {
    $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->bind_param('i', $id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function getAnnouncementById($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $announcement = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $announcement;
}

function searchUsers($conn, $query, $role = null) {
    $sql = "SELECT id, first_name, last_name, email, role FROM users WHERE 1=1";
    $params = [];
    $types = '';

    if ($query) {
        $like = "%$query%";
        $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    if ($role) {
        $sql .= " AND role = ?";
        $params[] = $role;
        $types .= 's';
    }

    $sql .= " ORDER BY first_name, last_name LIMIT 10";

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $users;
}
