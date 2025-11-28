<?php
// Simple script to execute the SQL query to add the contact_details column

// Include database configuration
require_once "database.php";

echo "Attempting to add contact_details column to leave_requests table...\n";

// SQL query to add the column
$sql = "ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS contact_details TEXT NULL COMMENT 'Contact information provided by employee during leave'";

// Alternative approach for MySQL versions that don't support IF NOT EXISTS for columns
$checkSql = "SELECT COUNT(*) as column_exists 
             FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = 'employee_management'
             AND TABLE_NAME = 'leave_requests' 
             AND COLUMN_NAME = 'contact_details'";

// First check if the column exists
$result = mysqli_query($conn, $checkSql);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    if ($row['column_exists'] == 0) {
        // Column doesn't exist, try to add it
        $alterSql = "ALTER TABLE leave_requests ADD COLUMN contact_details TEXT NULL COMMENT 'Contact information provided by employee during leave'";
        if (mysqli_query($conn, $alterSql)) {
            echo "Success: contact_details column added to leave_requests table.\n";
        } else {
            echo "Error adding column: " . mysqli_error($conn) . "\n";
        }
    } else {
        echo "The contact_details column already exists in the leave_requests table.\n";
    }
} else {
    echo "Error checking if column exists: " . mysqli_error($conn) . "\n";
}

mysqli_close($conn);
?>