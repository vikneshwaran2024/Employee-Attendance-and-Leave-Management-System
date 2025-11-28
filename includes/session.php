<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection 
require_once __DIR__ . "/../config/database.php";

/**
 * Check if user is logged in
 * 
 * @return bool True if user is logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true;
}

/**
 * Require user to be logged in, redirect to login page if not
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

/**
 * Check if user has admin role
 * 
 * @return bool True if user is an admin, false otherwise
 */
function isAdmin() {
    return isLoggedIn() && isset($_SESSION["role"]) && $_SESSION["role"] === "admin";
}

/**
 * Check if user has manager role (or is admin)
 * 
 * @return bool True if user is a manager or admin, false otherwise
 */
function isManager() {
    return isLoggedIn() && isset($_SESSION["role"]) && ($_SESSION["role"] === "manager" || $_SESSION["role"] === "admin");
}

/**
 * Require user to have admin role, redirect to dashboard if not
 */
function requireAdmin() {
    requireLogin();
    
    if (!isAdmin()) {
        $_SESSION['error'] = "You do not have permission to access this page.";
        header("Location: dashboard.php");
        exit;
    }
}

/**
 * Require user to have manager role (or admin), redirect to dashboard if not
 */
function requireManager() {
    requireLogin();
    
    if (!isManager()) {
        $_SESSION['error'] = "You do not have permission to access this page.";
        header("Location: dashboard.php");
        exit;
    }
}

/**
 * Log an activity for auditing purposes
 * 
 * @param string $action The action being performed
 * @param string $details Additional details about the action
 * @param int $userId ID of the user performing the action (defaults to current user)
 */
function logActivity($action, $details = '', $userId = null) {
    global $conn;
    
    if ($userId === null && isLoggedIn()) {
        $userId = $_SESSION['id'];
    }
    
    $sql = "INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)";
    
    if ($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "isss", $userId, $action, $details, $_SERVER['REMOTE_ADDR']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

// Set last activity time for session timeout
if(isLoggedIn()) {
    $_SESSION["last_activity"] = time();
}

// Check for session timeout (30 minutes of inactivity)
if(isset($_SESSION["last_activity"]) && (time() - $_SESSION["last_activity"] > 1800)) {
    // Last request was more than 30 minutes ago
    session_unset();     // Unset $_SESSION variables
    session_destroy();   // Destroy the session
    
    header("location: login.php?timeout=1");
    exit;
}
?>