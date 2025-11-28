<?php
require_once "config/database.php";
require_once "includes/session.php";

// Get current page for active nav item
$current_page = basename($_SERVER['PHP_SELF']);

// Get unread notifications count
$notifications_count = 0;
if(isLoggedIn()) {
    $userId = $_SESSION["id"];
    $notif_sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    
    if($notif_stmt = mysqli_prepare($conn, $notif_sql)) {
        mysqli_stmt_bind_param($notif_stmt, "i", $userId);
        if(mysqli_stmt_execute($notif_stmt)) {
            $result = mysqli_stmt_get_result($notif_stmt);
            if($row = mysqli_fetch_assoc($result)) {
                $notifications_count = $row['count'];
            }
        }
        mysqli_stmt_close($notif_stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Attendance & Leave Management System</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="css/style.css" rel="stylesheet">
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="favicon.ico">
</head>
<body>
    <?php if(isLoggedIn()): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">EmpManage</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'attendance.php' ? 'active' : ''; ?>" href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page == 'leave_requests.php' ? 'active' : ''; ?>" href="leave_requests.php"><i class="fas fa-calendar-alt"></i> Leave Requests</a>
                    </li>
                    <?php if(isManager() || isAdmin()): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="managementDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-tasks"></i> Management
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="manage_employees.php">Employees</a></li>
                            <li><a class="dropdown-item" href="manage_attendance.php">Attendance Records</a></li>
                            <li><a class="dropdown-item" href="manage_leave.php">Leave Requests</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    <?php if(isAdmin()): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cog"></i> Admin
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin_users.php">User Management</a></li>
                            <li><a class="dropdown-item" href="admin_departments.php">Departments</a></li>
                            <li><a class="dropdown-item" href="admin_holidays.php">Holidays</a></li>
                            <li><a class="dropdown-item" href="admin_leave_types.php">Leave Types</a></li>
                            <li><a class="dropdown-item" href="admin_reports.php">Reports</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link position-relative" href="notifications.php" id="notificationsLink">
                            <i class="fas fa-bell"></i>
                            <?php if($notifications_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                    <?php echo $notifications_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION["name"] ?? "User"); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php">My Profile</a></li>
                            <li><a class="dropdown-item" href="change_password.php">Change Password</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php endif; ?>
    <div class="container mt-4">
        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php 
                $error = htmlspecialchars($_GET['error']);
                switch($error) {
                    case 'unauthorized':
                        echo "You don't have permission to access this page.";
                        break;
                    default:
                        echo "An error occurred: " . $error;
                }
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_GET['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>