<?php
// config.php - Database Configuration

define('DB_HOST', 'localhost');
define('DB_USER', 'root');        // Change to your DB username
define('DB_PASS', '');            // Change to your DB password
define('DB_NAME', 'docugo_db');

define('SITE_NAME', 'DocuGo');
// Email settings — change these to your real email/host
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_PORT',     587);
define('MAIL_USERNAME', 'buddydudzzz@gmail.com');   // Your Gmail
define('MAIL_PASSWORD', 'bjyx rxpo jghf lguu');       // Gmail App Password
define('MAIL_FROM',     'buddydudzzz@gmail.com');
define('MAIL_FROM_NAME', 'ADFC DocuGo');

// Auto-detect base URL — works on localhost and live hosting
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script   = $_SERVER['SCRIPT_NAME'] ?? '';
$folder   = explode('/', trim($script, '/'));
$basePath = '/' . $folder[0];
define('SITE_URL', $protocol . '://' . $host . $basePath);

// Create connection
function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die('Database connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// Check if admin or registrar
function isAdmin() {
    return isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'registrar']);
}

// Protect student pages
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }
}

// Protect admin pages
function requireAdmin() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/login.php');
        exit();
    }
    if (!isAdmin()) {
        header('Location: ' . SITE_URL . '/dashboard.php');
        exit();
    }
}

// Redirect if already logged in (used on login/register pages)
function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        if (isAdmin()) {
            header('Location: ' . SITE_URL . '/admin/dashboard.php');
        } else {
            header('Location: ' . SITE_URL . '/dashboard.php');
        }
        exit();
    }
}

// Sanitize input
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Generate unique request code
function generateRequestCode() {
    return 'REQ-' . strtoupper(substr(md5(uniqid()), 0, 8));
}
?>