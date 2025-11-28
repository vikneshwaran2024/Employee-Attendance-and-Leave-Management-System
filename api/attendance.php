<?php
// API for handling attendance operations
header('Content-Type: application/json');

// Initialize the session
session_start();

// Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    echo json_encode([
        'success' => false,
        'message' => 'User not logged in'
    ]);
    exit;
}

// Include database configuration
require_once "../config/database.php";

// Process POST requests (check-in, check-out)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON data
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    $action = $data['action'] ?? '';
    $timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
    
    $user_id = $_SESSION['id'];
    $today = date('Y-m-d');
    
    if ($action === 'check-in') {
        // Check if already checked in today
        $check_sql = "SELECT id FROM attendance WHERE user_id = ? AND DATE(check_in) = ?";
        
        if($check_stmt = mysqli_prepare($conn, $check_sql)){
            mysqli_stmt_bind_param($check_stmt, "is", $user_id, $today);
            
            if(mysqli_stmt_execute($check_stmt)){
                mysqli_stmt_store_result($check_stmt);
                
                if(mysqli_stmt_num_rows($check_stmt) > 0){
                    echo json_encode([
                        'success' => false,
                        'message' => 'You have already checked in today'
                    ]);
                    exit;
                }
            }
            mysqli_stmt_close($check_stmt);
        }
        
        // Insert check-in record
        $status = 'present';
        $current_time = date('H:i:s');
        
        // Check if late (after 9:00 AM)
        $late_threshold = '09:00:00';
        if ($current_time > $late_threshold) {
            $status = 'late';
        }
        
        $insert_sql = "INSERT INTO attendance (user_id, check_in, status, ip_address) VALUES (?, NOW(), ?, ?)";
        
        if($insert_stmt = mysqli_prepare($conn, $insert_sql)){
            mysqli_stmt_bind_param($insert_stmt, "iss", $user_id, $status, $_SERVER['REMOTE_ADDR']);
            
            if(mysqli_stmt_execute($insert_stmt)){
                echo json_encode([
                    'success' => true,
                    'message' => 'Checked in successfully at ' . date('h:i A'),
                    'status' => 'Checked in',
                    'time' => date('h:i A')
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error recording check-in'
                ]);
            }
            
            mysqli_stmt_close($insert_stmt);
        }
    } 
    elseif ($action === 'check-out') {
        // Find today's check-in record
        $check_sql = "SELECT id, check_in FROM attendance WHERE user_id = ? AND DATE(check_in) = ? AND check_out IS NULL";
        
        if($check_stmt = mysqli_prepare($conn, $check_sql)){
            mysqli_stmt_bind_param($check_stmt, "is", $user_id, $today);
            
            if(mysqli_stmt_execute($check_stmt)){
                $result = mysqli_stmt_get_result($check_stmt);
                
                if(mysqli_num_rows($result) > 0){
                    $row = mysqli_fetch_assoc($result);
                    $attendance_id = $row['id'];
                    $check_in_time = new DateTime($row['check_in']);
                    $check_out_time = new DateTime();
                    
                    // Calculate work hours
                    $interval = $check_in_time->diff($check_out_time);
                    $work_hours = $interval->h + ($interval->i / 60);
                    
                    // Calculate overtime (after 8 hours)
                    $overtime_hours = max(0, $work_hours - 8);
                    
                    // Update record with check-out time
                    $update_sql = "UPDATE attendance SET check_out = NOW(), work_hours = ?, overtime_hours = ? WHERE id = ?";
                    
                    if($update_stmt = mysqli_prepare($conn, $update_sql)){
                        mysqli_stmt_bind_param($update_stmt, "ddi", $work_hours, $overtime_hours, $attendance_id);
                        
                        if(mysqli_stmt_execute($update_stmt)){
                            echo json_encode([
                                'success' => true,
                                'message' => 'Checked out successfully at ' . date('h:i A'),
                                'status' => 'Checked out',
                                'time' => date('h:i A'),
                                'work_hours' => number_format($work_hours, 2),
                                'overtime_hours' => number_format($overtime_hours, 2)
                            ]);
                        } else {
                            echo json_encode([
                                'success' => false,
                                'message' => 'Error recording check-out'
                            ]);
                        }
                        
                        mysqli_stmt_close($update_stmt);
                    }
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'No check-in record found for today'
                    ]);
                }
            }
            
            mysqli_stmt_close($check_stmt);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
    }
}
// Process GET requests (attendance history)
elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $user_id = $_SESSION['id'];
    
    if ($action === 'history') {
        // Get attendance history for the current user
        $history_sql = "SELECT 
                           DATE(check_in) as date, 
                           TIME_FORMAT(check_in, '%h:%i %p') as check_in,
                           TIME_FORMAT(check_out, '%h:%i %p') as check_out,
                           work_hours,
                           status
                       FROM attendance 
                       WHERE user_id = ? 
                       ORDER BY check_in DESC 
                       LIMIT 10";
        
        if($history_stmt = mysqli_prepare($conn, $history_sql)){
            mysqli_stmt_bind_param($history_stmt, "i", $user_id);
            
            if(mysqli_stmt_execute($history_stmt)){
                $result = mysqli_stmt_get_result($history_stmt);
                
                $history = [];
                while($row = mysqli_fetch_assoc($result)){
                    $history[] = $row;
                }
                
                echo json_encode([
                    'success' => true,
                    'history' => $history
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error fetching attendance history'
                ]);
            }
            
            mysqli_stmt_close($history_stmt);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid action'
        ]);
    }
}
else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}

// Close database connection
mysqli_close($conn);
?>