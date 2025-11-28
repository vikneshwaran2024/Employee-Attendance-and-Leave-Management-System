<?php
/**
 * Dashboard Page
 * Main dashboard with role-based content for employees, managers, and admins
 */
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$userId = getCurrentUserId();
$userRole = getCurrentUserRole();
$today = date('Y-m-d');
$currentMonth = date('Y-m');

// Get today's attendance for current user
$todayAttendance = executeQuery(
    "SELECT * FROM attendance WHERE user_id = ? AND date = ?",
    [$userId, $today]
);
$todayRecord = $todayAttendance && count($todayAttendance) > 0 ? $todayAttendance[0] : null;

// Get monthly attendance stats for current user
$monthlyStats = executeQuery(
    "SELECT 
        COUNT(CASE WHEN status = 'present' OR status = 'late' THEN 1 END) as present_days,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
        COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days,
        COALESCE(SUM(work_hours), 0) as total_hours,
        COALESCE(SUM(overtime_hours), 0) as overtime_hours
     FROM attendance 
     WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?",
    [$userId, $currentMonth]
);
$stats = $monthlyStats && count($monthlyStats) > 0 ? $monthlyStats[0] : [
    'present_days' => 0, 'absent_days' => 0, 'late_days' => 0,
    'total_hours' => 0, 'overtime_hours' => 0
];

// Get pending leave requests for current user
$pendingLeaves = executeQuery(
    "SELECT COUNT(*) as count FROM leave_requests WHERE user_id = ? AND status = 'pending'",
    [$userId]
);
$pendingLeaveCount = $pendingLeaves ? $pendingLeaves[0]['count'] : 0;

// Admin/Manager specific data
$totalEmployees = 0;
$todayPresent = 0;
$pendingApprovals = 0;

if (hasRole(['manager', 'admin'])) {
    // Get total active employees
    $employees = executeQuery("SELECT COUNT(*) as count FROM users WHERE status = 'active'");
    $totalEmployees = $employees ? $employees[0]['count'] : 0;
    
    // Get today's present count
    $present = executeQuery(
        "SELECT COUNT(*) as count FROM attendance WHERE date = ? AND (status = 'present' OR status = 'late')",
        [$today]
    );
    $todayPresent = $present ? $present[0]['count'] : 0;
    
    // Get pending leave approvals
    if (hasRole('admin')) {
        $approvals = executeQuery("SELECT COUNT(*) as count FROM leave_requests WHERE status = 'pending'");
    } else {
        // Managers see only their department
        $approvals = executeQuery(
            "SELECT COUNT(*) as count FROM leave_requests lr 
             JOIN users u ON lr.user_id = u.id 
             WHERE lr.status = 'pending' AND u.department_id = ?",
            [$_SESSION['department_id']]
        );
    }
    $pendingApprovals = $approvals ? $approvals[0]['count'] : 0;
}

// Get recent attendance records
$recentAttendance = executeQuery(
    "SELECT date, check_in, check_out, work_hours, overtime_hours, status 
     FROM attendance WHERE user_id = ? ORDER BY date DESC LIMIT 7",
    [$userId]
);

// Get upcoming holidays
$upcomingHolidays = executeQuery(
    "SELECT name, date FROM holidays WHERE date >= ? ORDER BY date LIMIT 3",
    [$today]
);
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Dashboard</h1>
        <p class="text-muted">Welcome back, <?php echo sanitize($_SESSION['first_name']); ?>!</p>
    </div>
    <div class="text-end">
        <div id="current-time" class="current-time">--:--:--</div>
        <div id="current-date" class="current-date">Loading...</div>
    </div>
</div>

<!-- Check In/Out Section -->
<div class="card dashboard-card mb-4">
    <div class="card-body text-center py-4">
        <h5 class="card-title mb-3">Today's Attendance</h5>
        
        <?php if ($todayRecord): ?>
            <?php if ($todayRecord['check_in'] && !$todayRecord['check_out']): ?>
                <p class="mb-3">
                    <span class="status-indicator checked-in"></span>
                    Checked in at <?php echo formatTime($todayRecord['check_in']); ?>
                </p>
                <button onclick="checkOut()" class="btn btn-check-out">
                    <i class="fas fa-sign-out-alt me-2"></i>Check Out
                </button>
            <?php elseif ($todayRecord['check_in'] && $todayRecord['check_out']): ?>
                <div class="text-success mb-3">
                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                    <p class="mb-1">Completed for today</p>
                    <small>
                        Check In: <?php echo formatTime($todayRecord['check_in']); ?> | 
                        Check Out: <?php echo formatTime($todayRecord['check_out']); ?>
                    </small>
                    <p class="mt-2">
                        <strong>Work Hours:</strong> <?php echo number_format($todayRecord['work_hours'], 2); ?>
                        <?php if ($todayRecord['overtime_hours'] > 0): ?>
                            | <strong>Overtime:</strong> <?php echo number_format($todayRecord['overtime_hours'], 2); ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="mb-3 text-muted">You haven't checked in today</p>
            <button onclick="checkIn()" class="btn btn-check-in">
                <i class="fas fa-sign-in-alt me-2"></i>Check In
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card dashboard-card card-stat primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-primary">Present Days (This Month)</div>
                    <div class="stat-value"><?php echo $stats['present_days']; ?></div>
                </div>
                <i class="fas fa-calendar-check fa-2x text-primary opacity-50"></i>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card dashboard-card card-stat success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-success">Total Work Hours</div>
                    <div class="stat-value"><?php echo number_format($stats['total_hours'], 1); ?></div>
                </div>
                <i class="fas fa-clock fa-2x text-success opacity-50"></i>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card dashboard-card card-stat warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-warning">Overtime Hours</div>
                    <div class="stat-value"><?php echo number_format($stats['overtime_hours'], 1); ?></div>
                </div>
                <i class="fas fa-hourglass-half fa-2x text-warning opacity-50"></i>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card dashboard-card card-stat info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-info">Pending Leave Requests</div>
                    <div class="stat-value"><?php echo $pendingLeaveCount; ?></div>
                </div>
                <i class="fas fa-paper-plane fa-2x text-info opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<?php if (hasRole(['manager', 'admin'])): ?>
<!-- Manager/Admin Stats -->
<div class="row mb-4">
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card dashboard-card card-stat primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-primary">Total Employees</div>
                    <div class="stat-value"><?php echo $totalEmployees; ?></div>
                </div>
                <i class="fas fa-users fa-2x text-primary opacity-50"></i>
            </div>
        </div>
    </div>
    
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card dashboard-card card-stat success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-success">Present Today</div>
                    <div class="stat-value"><?php echo $todayPresent; ?></div>
                </div>
                <i class="fas fa-user-check fa-2x text-success opacity-50"></i>
            </div>
        </div>
    </div>
    
    <div class="col-xl-4 col-md-6 mb-4">
        <div class="card dashboard-card card-stat danger">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-danger">Pending Approvals</div>
                    <div class="stat-value"><?php echo $pendingApprovals; ?></div>
                </div>
                <i class="fas fa-exclamation-circle fa-2x text-danger opacity-50"></i>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Activity -->
<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card dashboard-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Attendance</h5>
                <a href="/attendance.php" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Work Hours</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentAttendance && count($recentAttendance) > 0): ?>
                                <?php foreach ($recentAttendance as $record): ?>
                                <tr>
                                    <td><?php echo formatDate($record['date']); ?></td>
                                    <td><?php echo $record['check_in'] ? formatTime($record['check_in']) : '-'; ?></td>
                                    <td><?php echo $record['check_out'] ? formatTime($record['check_out']) : '-'; ?></td>
                                    <td><?php echo number_format($record['work_hours'], 2); ?> hrs</td>
                                    <td><?php echo formatStatusBadge($record['status']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No attendance records found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4 mb-4">
        <div class="card dashboard-card">
            <div class="card-header bg-white">
                <h5 class="mb-0">Upcoming Holidays</h5>
            </div>
            <div class="card-body">
                <?php if ($upcomingHolidays && count($upcomingHolidays) > 0): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($upcomingHolidays as $holiday): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-gift text-danger me-2"></i>
                                <?php echo sanitize($holiday['name']); ?>
                            </div>
                            <span class="badge bg-secondary"><?php echo formatDate($holiday['date']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-center text-muted mb-0">No upcoming holidays</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card dashboard-card mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="/leave.php?action=new" class="btn btn-outline-primary">
                        <i class="fas fa-plus me-2"></i>Request Leave
                    </a>
                    <a href="/attendance.php" class="btn btn-outline-secondary">
                        <i class="fas fa-history me-2"></i>View Attendance History
                    </a>
                    <a href="/profile.php" class="btn btn-outline-info">
                        <i class="fas fa-user-edit me-2"></i>Update Profile
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
