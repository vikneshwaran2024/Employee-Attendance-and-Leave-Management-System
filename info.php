<?php
// Display PHP information
echo "<h1>PHP is working!</h1>";
echo "<p>Server path: " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p>Requested URL: " . $_SERVER['REQUEST_URI'] . "</p>";
echo "<p>Script filename: " . $_SERVER['SCRIPT_FILENAME'] . "</p>";
echo "<p>Current directory: " . __DIR__ . "</p>";
echo "<p>PHP version: " . phpversion() . "</p>";

// Test database connection
echo "<h2>Testing Database Connection</h2>";
if (file_exists("config/database.php")) {
    echo "<p>Database config file exists</p>";
    try {
        include_once "config/database.php";
        if (isset($conn) && $conn) {
            echo "<p style='color:green'>Database connection successful!</p>";
        } else {
            echo "<p style='color:red'>Database connection failed - connection variable not set</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color:red'>Database config file not found</p>";
}

// Test file access
echo "<h2>File Access Test</h2>";
$files = [
    "index.php",
    "login.php",
    "dashboard.php",
    "manage_leave.php",
    "config/database.php",
    "includes/header.php",
    "includes/footer.php"
];

foreach ($files as $file) {
    echo "<p>" . $file . ": " . (file_exists($file) ? "Exists" : "Missing") . "</p>";
}
?>