<?php
/**
 * Attendance API
 * Handles check-in and check-out operations
 */

// Set proper headers
header('Content-Type: application/json');

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = getCurrentUserId();
$today = date('Y-m-d');
$currentTime = date('H:i:s');

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Get work settings
$workStartTime = getSetting('work_start_time', '09:00:00');
$standardHours = (float) getSetting('standard_work_hours', '8');
$lateThreshold = (int) getSetting('late_threshold_minutes', '15');

switch ($action) {
    case 'check_in':
        // Check if already checked in today
        $existing = executeQuery(
            "SELECT * FROM attendance WHERE user_id = ? AND date = ?",
            [$userId, $today]
        );
        
        if ($existing && count($existing) > 0) {
            if ($existing[0]['check_in']) {
                echo json_encode(['success' => false, 'message' => 'Already checked in today']);
                exit;
            }
        }
        
        // Determine if late
        $isLate = strtotime($currentTime) > strtotime($workStartTime) + ($lateThreshold * 60);
        $status = $isLate ? 'late' : 'present';
        
        // Check if today is a holiday
        if (isHoliday($today)) {
            $status = 'holiday';
        }
        
        $ipAddress = getClientIP();
        
        if ($existing && count($existing) > 0) {
            // Update existing record
            $result = executeUpdate(
                "UPDATE attendance SET check_in = ?, status = ?, ip_address = ? WHERE user_id = ? AND date = ?",
                [$currentTime, $status, $ipAddress, $userId, $today]
            );
        } else {
            // Create new record
            $result = executeInsert(
                "INSERT INTO attendance (user_id, date, check_in, status, ip_address) VALUES (?, ?, ?, ?, ?)",
                [$userId, $today, $currentTime, $status, $ipAddress]
            );
        }
        
        if ($result !== false) {
            logActivity('Check In', 'Checked in at ' . $currentTime);
            echo json_encode([
                'success' => true, 
                'message' => 'Checked in successfully',
                'time' => formatTime($currentTime),
                'status' => $status
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to check in']);
        }
        break;
        
    case 'check_out':
        // Get today's record
        $existing = executeQuery(
            "SELECT * FROM attendance WHERE user_id = ? AND date = ?",
            [$userId, $today]
        );
        
        if (!$existing || count($existing) === 0 || !$existing[0]['check_in']) {
            echo json_encode(['success' => false, 'message' => 'Please check in first']);
            exit;
        }
        
        if ($existing[0]['check_out']) {
            echo json_encode(['success' => false, 'message' => 'Already checked out today']);
            exit;
        }
        
        $checkInTime = $existing[0]['check_in'];
        
        // Calculate work hours and overtime
        $workData = calculateWorkHours($checkInTime, $currentTime, $standardHours);
        $workHours = $workData['total_hours'];
        $overtimeHours = $workData['overtime_hours'];
        
        // Determine status based on work hours
        $status = $existing[0]['status'];
        if ($workHours < 4) {
            $status = 'half_day';
        }
        
        $result = executeUpdate(
            "UPDATE attendance SET check_out = ?, work_hours = ?, overtime_hours = ?, status = ? WHERE user_id = ? AND date = ?",
            [$currentTime, $workHours, $overtimeHours, $status, $userId, $today]
        );
        
        if ($result !== false) {
            logActivity('Check Out', 'Checked out at ' . $currentTime . '. Work hours: ' . $workHours);
            echo json_encode([
                'success' => true,
                'message' => 'Checked out successfully',
                'time' => formatTime($currentTime),
                'work_hours' => number_format($workHours, 2),
                'overtime_hours' => number_format($overtimeHours, 2)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to check out']);
        }
        break;
        
    case 'get_status':
        $record = executeQuery(
            "SELECT * FROM attendance WHERE user_id = ? AND date = ?",
            [$userId, $today]
        );
        
        if ($record && count($record) > 0) {
            echo json_encode([
                'success' => true,
                'checked_in' => !empty($record[0]['check_in']),
                'checked_out' => !empty($record[0]['check_out']),
                'check_in_time' => $record[0]['check_in'] ? formatTime($record[0]['check_in']) : null,
                'check_out_time' => $record[0]['check_out'] ? formatTime($record[0]['check_out']) : null,
                'work_hours' => $record[0]['work_hours'],
                'status' => $record[0]['status']
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'checked_in' => false,
                'checked_out' => false
            ]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
