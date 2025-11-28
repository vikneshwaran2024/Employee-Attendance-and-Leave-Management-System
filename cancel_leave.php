<?php
// Include session management and database connection
require_once "includes/session.php";
require_once "config/database.php";

// Check if user is logged in
requireLogin();

// Get user ID from session
$userId = $_SESSION["id"];

// Check if leave ID is provided
if(!isset($_GET["id"]) || empty($_GET["id"])) {
    header("location: leave_requests.php?error=invalid_request");
    exit;
}

$leaveId = $_GET["id"];
$error = "";

// Verify that the leave request exists and belongs to the current user
$sql = "SELECT * FROM leave_requests WHERE id = ? AND user_id = ?";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $leaveId, $userId);
    
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        
        if(mysqli_num_rows($result) != 1) {
            // No matching leave request found
            header("location: leave_requests.php?error=not_found");
            exit;
        }
        
        $leaveRequest = mysqli_fetch_assoc($result);
        
        // Check if leave request is in pending status
        if($leaveRequest["status"] !== "pending") {
            header("location: leave_requests.php?error=cannot_cancel");
            exit;
        }
    } else {
        $error = "Oops! Something went wrong. Please try again later.";
    }
    
    mysqli_stmt_close($stmt);
}

// If no errors, proceed with cancellation
if(empty($error)) {
    // Delete the leave request
    $sql = "DELETE FROM leave_requests WHERE id = ?";
    
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $leaveId);
        
        if(mysqli_stmt_execute($stmt)) {
            // Success - redirect back with success message
            header("location: leave_requests.php?success=cancelled");
            exit;
        } else {
            $error = "Error cancelling leave request. Please try again.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// If there was an error, redirect with error message
if(!empty($error)) {
    header("location: leave_requests.php?error=" . urlencode($error));
    exit;
}

// Close connection
mysqli_close($conn);
?>