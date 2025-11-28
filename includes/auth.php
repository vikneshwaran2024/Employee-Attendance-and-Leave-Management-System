<?php
/**
 * Session and Authentication Functions
 * Handles secure user authentication and session management
 */

session_start();

require_once __DIR__ . '/../config/database.php';

/**
 * Authenticate user with email and password
 * @param string $email
 * @param string $password
 * @return array|false User data or false on failure
 */
function authenticateUser($email, $password) {
    $sql = "SELECT id, employee_id, first_name, last_name, email, password, role, department_id, status 
            FROM users WHERE email = ? AND status = 'active'";
    $users = executeQuery($sql, [$email]);
    
    if ($users && count($users) > 0) {
        $user = $users[0];
        if (password_verify($password, $user['password'])) {
            unset($user['password']); // Remove password from session data
            return $user;
        }
    }
    return false;
}

/**
 * Create user session after successful login
 * @param array $user User data
 */
function createSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['employee_id'] = $user['employee_id'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['department_id'] = $user['department_id'];
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    
    // Regenerate session ID for security
    session_regenerate_id(true);
}

/**
 * Destroy user session (logout)
 */
function destroySession() {
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Check if user has specific role
 * @param string|array $roles
 * @return bool
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    return in_array($_SESSION['role'], $roles);
}

/**
 * Require login - redirect to login page if not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require specific role - redirect if unauthorized
 * @param string|array $roles
 */
function requireRole($roles) {
    requireLogin();
    
    if (!hasRole($roles)) {
        header('Location: /unauthorized.php');
        exit;
    }
}

/**
 * Get current user ID
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Get current user's full name
 * @return string
 */
function getCurrentUserName() {
    if (isLoggedIn()) {
        return $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
    }
    return '';
}

/**
 * Log user activity
 * @param string $action
 * @param string $description
 */
function logActivity($action, $description = '') {
    $userId = getCurrentUserId();
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    
    $sql = "INSERT INTO activity_logs (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)";
    executeInsert($sql, [$userId, $action, $description, $ipAddress]);
}

/**
 * Create notification for user
 * @param int $userId
 * @param string $title
 * @param string $message
 * @param string $type
 */
function createNotification($userId, $title, $message, $type = 'info') {
    $sql = "INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)";
    executeInsert($sql, [$userId, $title, $message, $type]);
}

/**
 * Get unread notifications count for current user
 * @return int
 */
function getUnreadNotificationsCount() {
    if (!isLoggedIn()) {
        return 0;
    }
    
    $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE";
    $result = executeQuery($sql, [getCurrentUserId()]);
    return $result ? $result[0]['count'] : 0;
}

/**
 * Hash password securely
 * @param string $password
 * @return string
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 * @param string $token
 * @return bool
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
