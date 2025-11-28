<?php
// Define base path for better handling of errors and redirects
$base_url = 'http://' . $_SERVER['HTTP_HOST'] . '/DBMS_project/';

// Check if login.php exists before redirecting
if (file_exists(__DIR__ . '/login.php')) {
    // Redirect to login page
    header("Location: {$base_url}login.php");
    exit;
} else {
    // If login.php doesn't exist, show diagnostic info
    echo "<h1>DBMS Project Setup</h1>";
    echo "<p>The login page could not be found. Please check your installation.</p>";
    echo "<p>Current directory: " . __DIR__ . "</p>";
    
    // Show files in the current directory
    echo "<h2>Available files:</h2>";
    echo "<ul>";
    foreach (scandir(__DIR__) as $file) {
        if ($file != "." && $file != "..") {
            echo "<li>$file</li>";
        }
    }
    echo "</ul>";
    
    echo "<p>Please visit <a href='{$base_url}info.php'>Diagnostic Page</a> for more information.</p>";
}
?>