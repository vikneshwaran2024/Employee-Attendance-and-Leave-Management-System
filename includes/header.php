<?php
/**
 * Header Include - Common header for all pages
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Employee Attendance and Leave Management System">
    <title><?php echo isset($pageTitle) ? sanitize($pageTitle) . ' - ' : ''; ?>Attendance System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="/css/style.css" rel="stylesheet">
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner">
        <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <?php if (isLoggedIn()): ?>
    <div class="d-flex">
        <!-- Sidebar -->
        <nav class="sidebar">
            <a href="/dashboard.php" class="sidebar-brand">
                <i class="fas fa-clock"></i>
                <span>AttendanceMS</span>
            </a>
            
            <div class="sidebar-divider"></div>
            
            <div class="sidebar-heading">Core</div>
            
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'attendance.php' ? 'active' : ''; ?>" href="/attendance.php">
                        <i class="fas fa-calendar-check"></i>
                        <span>Attendance</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'leave.php' ? 'active' : ''; ?>" href="/leave.php">
                        <i class="fas fa-plane-departure"></i>
                        <span>Leave Requests</span>
                    </a>
                </li>
            </ul>
            
            <?php if (hasRole(['manager', 'admin'])): ?>
            <div class="sidebar-divider"></div>
            <div class="sidebar-heading">Management</div>
            
            <ul class="nav flex-column">
                <?php if (hasRole('admin')): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'employees.php' ? 'active' : ''; ?>" href="/employees.php">
                        <i class="fas fa-users"></i>
                        <span>Employees</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'active' : ''; ?>" href="/departments.php">
                        <i class="fas fa-building"></i>
                        <span>Departments</span>
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'leave-approval.php' ? 'active' : ''; ?>" href="/leave-approval.php">
                        <i class="fas fa-check-circle"></i>
                        <span>Leave Approvals</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="/reports.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </li>
            </ul>
            <?php endif; ?>
            
            <?php if (hasRole('admin')): ?>
            <div class="sidebar-divider"></div>
            <div class="sidebar-heading">Settings</div>
            
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'holidays.php' ? 'active' : ''; ?>" href="/holidays.php">
                        <i class="fas fa-umbrella-beach"></i>
                        <span>Holidays</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="/settings.php">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </nav>
        
        <!-- Main Content -->
        <div class="main-content flex-grow-1">
            <!-- Top Navbar -->
            <nav class="top-navbar navbar navbar-expand navbar-light">
                <div class="container-fluid">
                    <button class="btn btn-link d-md-none" type="button" id="sidebar-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <ul class="navbar-nav ms-auto">
                        <!-- Notifications -->
                        <li class="nav-item dropdown me-3">
                            <a class="nav-link dropdown-toggle position-relative" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-bell fa-lg"></i>
                                <?php 
                                $notifCount = getUnreadNotificationsCount();
                                if ($notifCount > 0): 
                                ?>
                                <span class="notification-badge"><?php echo $notifCount; ?></span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header">Notifications</h6></li>
                                <li><a class="dropdown-item" href="/notifications.php">View all notifications</a></li>
                            </ul>
                        </li>
                        
                        <!-- User Menu -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                <span class="me-2 d-none d-lg-inline"><?php echo sanitize(getCurrentUserName()); ?></span>
                                <img class="profile-img" src="/assets/default-avatar.png" alt="Profile">
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="/profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>
            
            <!-- Page Content -->
            <div class="container-fluid p-4">
                <div id="alert-container">
                    <?php displayFlashMessage(); ?>
                </div>
    <?php endif; ?>
