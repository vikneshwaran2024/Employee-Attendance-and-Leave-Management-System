<?php
/**
 * Helper Functions
 * Common utility functions used throughout the application
 */

/**
 * Sanitize input data
 * @param string $data
 * @return string
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Format date for display
 * @param string $date
 * @param string $format
 * @return string
 */
function formatDate($date, $format = 'M d, Y') {
    return date($format, strtotime($date));
}

/**
 * Format time for display
 * @param string $time
 * @param string $format
 * @return string
 */
function formatTime($time, $format = 'h:i A') {
    return date($format, strtotime($time));
}

/**
 * Calculate time difference in hours
 * @param string $startTime
 * @param string $endTime
 * @return float
 */
function calculateTimeDiff($startTime, $endTime) {
    $start = strtotime($startTime);
    $end = strtotime($endTime);
    $diff = $end - $start;
    return round($diff / 3600, 2);
}

/**
 * Calculate work hours and overtime
 * @param string $checkIn
 * @param string $checkOut
 * @param float $standardHours
 * @return array
 */
function calculateWorkHours($checkIn, $checkOut, $standardHours = 8) {
    $totalHours = calculateTimeDiff($checkIn, $checkOut);
    $overtime = max(0, $totalHours - $standardHours);
    $regularHours = min($totalHours, $standardHours);
    
    return [
        'total_hours' => $totalHours,
        'regular_hours' => $regularHours,
        'overtime_hours' => $overtime
    ];
}

/**
 * Check if a date is a holiday
 * @param string $date
 * @return bool
 */
function isHoliday($date) {
    require_once __DIR__ . '/../config/database.php';
    
    $sql = "SELECT id FROM holidays WHERE date = ?";
    $result = executeQuery($sql, [$date]);
    return $result && count($result) > 0;
}

/**
 * Check if a date is a weekend
 * @param string $date
 * @return bool
 */
function isWeekend($date) {
    $dayOfWeek = date('N', strtotime($date));
    return $dayOfWeek >= 6; // Saturday = 6, Sunday = 7
}

/**
 * Calculate working days between two dates
 * @param string $startDate
 * @param string $endDate
 * @param bool $excludeHolidays
 * @return int
 */
function calculateWorkingDays($startDate, $endDate, $excludeHolidays = true) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $end->modify('+1 day'); // Include end date
    
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    $workingDays = 0;
    foreach ($period as $date) {
        $dateStr = $date->format('Y-m-d');
        if (!isWeekend($dateStr)) {
            if (!$excludeHolidays || !isHoliday($dateStr)) {
                $workingDays++;
            }
        }
    }
    
    return $workingDays;
}

/**
 * Get setting value from database
 * @param string $key
 * @param string $default
 * @return string
 */
function getSetting($key, $default = '') {
    require_once __DIR__ . '/../config/database.php';
    
    $sql = "SELECT setting_value FROM settings WHERE setting_key = ?";
    $result = executeQuery($sql, [$key]);
    return $result && count($result) > 0 ? $result[0]['setting_value'] : $default;
}

/**
 * Update setting value
 * @param string $key
 * @param string $value
 * @return bool
 */
function updateSetting($key, $value) {
    require_once __DIR__ . '/../config/database.php';
    
    $sql = "UPDATE settings SET setting_value = ? WHERE setting_key = ?";
    return executeUpdate($sql, [$value, $key]) !== false;
}

/**
 * Get all departments
 * @return array
 */
function getDepartments() {
    require_once __DIR__ . '/../config/database.php';
    
    $sql = "SELECT id, name, description FROM departments ORDER BY name";
    return executeQuery($sql, []) ?: [];
}

/**
 * Get all leave types
 * @return array
 */
function getLeaveTypes() {
    require_once __DIR__ . '/../config/database.php';
    
    $sql = "SELECT id, name, description, days_allowed, is_paid FROM leave_types ORDER BY name";
    return executeQuery($sql, []) ?: [];
}

/**
 * Get user's leave balance for a specific leave type
 * @param int $userId
 * @param int $leaveTypeId
 * @param int $year
 * @return array
 */
function getLeaveBalance($userId, $leaveTypeId, $year = null) {
    require_once __DIR__ . '/../config/database.php';
    
    if ($year === null) {
        $year = date('Y');
    }
    
    $sql = "SELECT * FROM leave_balance WHERE user_id = ? AND leave_type_id = ? AND year = ?";
    $result = executeQuery($sql, [$userId, $leaveTypeId, $year]);
    
    if ($result && count($result) > 0) {
        return $result[0];
    }
    
    // If no balance exists, get default from leave type
    $sql = "SELECT days_allowed FROM leave_types WHERE id = ?";
    $type = executeQuery($sql, [$leaveTypeId]);
    $daysAllowed = $type && count($type) > 0 ? $type[0]['days_allowed'] : 0;
    
    return [
        'total_days' => $daysAllowed,
        'used_days' => 0,
        'remaining_days' => $daysAllowed
    ];
}

/**
 * Format status badge HTML
 * @param string $status
 * @return string
 */
function formatStatusBadge($status) {
    $badges = [
        'present' => 'success',
        'absent' => 'danger',
        'late' => 'warning',
        'half_day' => 'info',
        'on_leave' => 'secondary',
        'holiday' => 'primary',
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'secondary',
        'active' => 'success',
        'inactive' => 'danger'
    ];
    
    $badgeClass = $badges[$status] ?? 'secondary';
    return '<span class="badge bg-' . $badgeClass . '">' . ucfirst($status) . '</span>';
}

/**
 * Get client IP address
 * Handles proxies, load balancers, and IPv6 addresses
 * @return string
 */
function getClientIP() {
    $ipAddress = '';
    
    // Check for various proxy headers in order of reliability
    $headers = [
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            // X-Forwarded-For may contain multiple IPs; take the first one
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]);
            
            // Validate IPv4 or IPv6
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
                $ipAddress = $ip;
                break;
            }
        }
    }
    
    return $ipAddress;
}

/**
 * Set flash message
 * @param string $type
 * @param string $message
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 * @return array|null
 */
function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Display flash message HTML
 */
function displayFlashMessage() {
    $flash = getFlashMessage();
    if ($flash) {
        echo '<div class="alert alert-' . $flash['type'] . ' alert-dismissible fade show" role="alert">';
        echo sanitize($flash['message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
    }
}

/**
 * Redirect with message
 * @param string $url
 * @param string $type
 * @param string $message
 */
function redirectWithMessage($url, $type, $message) {
    setFlashMessage($type, $message);
    header('Location: ' . $url);
    exit;
}
