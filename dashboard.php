<?php
// Include session management
require_once "includes/session.php";

// Require user to be logged in
requireLogin();

// Include database configuration
require_once "config/database.php";

// Get user data
$user_id = $_SESSION["id"];

// Get today's attendance status
$attendance_status = "Not checked in";
$check_in_time = null;
$check_out_time = null;
$can_check_in = true;
$can_check_out = false;

// Query for today's attendance
$today = date('Y-m-d');
$attendance_sql = "SELECT check_in, check_out FROM attendance WHERE user_id = ? AND DATE(check_in) = ?";
if($stmt = mysqli_prepare($conn, $attendance_sql)){
    mysqli_stmt_bind_param($stmt, "is", $user_id, $today);
    if(mysqli_stmt_execute($stmt)){
        mysqli_stmt_store_result($stmt);
        if(mysqli_stmt_num_rows($stmt) > 0){
            mysqli_stmt_bind_result($stmt, $check_in, $check_out);
            mysqli_stmt_fetch($stmt);
            
            $check_in_time = $check_in;
            $check_out_time = $check_out;
            $can_check_in = false;
            $can_check_out = empty($check_out);
            
            if(empty($check_out)){
                $attendance_status = "Checked in";
            } else {
                $attendance_status = "Checked out";
            }
        }
    }
    mysqli_stmt_close($stmt);
}

// Get pending leave requests count
$pending_leaves_count = 0;
if(isManager()){
    $leave_sql = "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'";
    if($leave_stmt = mysqli_prepare($conn, $leave_sql)){
        if(mysqli_stmt_execute($leave_stmt)){
            mysqli_stmt_bind_result($leave_stmt, $pending_leaves_count);
            mysqli_stmt_fetch($leave_stmt);
        }
        mysqli_stmt_close($leave_stmt);
    }
}

// Get user's leave balance
$leave_balance = array();
$balance_sql = "SELECT lt.name, lb.allocated_days, lb.used_days 
                FROM leave_balances lb 
                JOIN leave_types lt ON lb.leave_type_id = lt.id 
                WHERE lb.user_id = ? AND lb.year = ?";
                
if($balance_stmt = mysqli_prepare($conn, $balance_sql)){
    $current_year = date('Y');
    mysqli_stmt_bind_param($balance_stmt, "ii", $user_id, $current_year);
    
    if(mysqli_stmt_execute($balance_stmt)){
        $result = mysqli_stmt_get_result($balance_stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $leave_balance[] = $row;
        }
    }
    mysqli_stmt_close($balance_stmt);
}

// Get recent attendance records
$recent_attendance = array();
$recent_sql = "SELECT DATE(check_in) as date, 
                      TIME_FORMAT(check_in, '%H:%i:%s') as check_in_time, 
                      TIME_FORMAT(check_out, '%H:%i:%s') as check_out_time,
                      work_hours, status 
               FROM attendance 
               WHERE user_id = ? 
               ORDER BY check_in DESC LIMIT 5";

if($recent_stmt = mysqli_prepare($conn, $recent_sql)){
    mysqli_stmt_bind_param($recent_stmt, "i", $user_id);
    
    if(mysqli_stmt_execute($recent_stmt)){
        $result = mysqli_stmt_get_result($recent_stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $recent_attendance[] = $row;
        }
    }
    mysqli_stmt_close($recent_stmt);
}

// Include header
include_once "includes/header.php";

// Check if user is logged in
requireLogin();

// Get user info
$userId = $_SESSION["id"];
$userName = $_SESSION["name"];
$userRole = $_SESSION["role"];

// Get current date
$currentDate = date("Y-m-d");
$currentMonth = date("m");
$currentYear = date("Y");

// Check if already checked in today
$attendanceToday = null;
$sql = "SELECT id, check_in, check_out, status FROM attendance WHERE user_id = ? AND DATE(check_in) = ?";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "is", $userId, $currentDate);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)) {
            $attendanceToday = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get attendance statistics for current month
$attendanceStats = [
    'present' => 0,
    'late' => 0,
    'absent' => 0,
    'leave' => 0,
    'working_hours' => 0,
    'overtime_hours' => 0
];

// Start and end date of current month
$startDate = date('Y-m-01');
$endDate = date('Y-m-t');

// Get present and late days
$sql = "SELECT status, COUNT(*) as count, SUM(work_hours) as total_hours, SUM(overtime_hours) as total_overtime
        FROM attendance 
        WHERE user_id = ? AND DATE(check_in) BETWEEN ? AND ?
        GROUP BY status";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "iss", $userId, $startDate, $endDate);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)) {
            if($row['status'] === 'present') {
                $attendanceStats['present'] = $row['count'];
            } else if($row['status'] === 'late') {
                $attendanceStats['late'] = $row['count'];
            }
            
            $attendanceStats['working_hours'] += ($row['total_hours'] ?? 0);
            $attendanceStats['overtime_hours'] += ($row['total_overtime'] ?? 0);
        }
    }
    mysqli_stmt_close($stmt);
}

// Get leave days
$sql = "SELECT COUNT(*) as count
        FROM leave_requests 
        WHERE user_id = ? AND status = 'approved' AND 
              ((start_date BETWEEN ? AND ?) OR 
               (end_date BETWEEN ? AND ?) OR
               (start_date <= ? AND end_date >= ?))";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "issssss", $userId, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)) {
            $attendanceStats['leave'] = $row['count'];
        }
    }
    mysqli_stmt_close($stmt);
}

// Calculate business days in current month to determine absent days
function getBusinessDaysCount($startDate, $endDate) {
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $days = 0;
    
    // Include the end date
    $end->modify('+1 day');
    
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    foreach($period as $date) {
        if($date > new DateTime()) {
            // Don't count future days
            continue;
        }
        
        $dayOfWeek = $date->format('N');
        if($dayOfWeek < 6) { // 1-5 = Monday-Friday
            $days++;
        }
    }
    
    return $days;
}

$businessDays = getBusinessDaysCount($startDate, min($endDate, $currentDate));
$attendanceStats['absent'] = $businessDays - $attendanceStats['present'] - $attendanceStats['late'] - $attendanceStats['leave'];
$attendanceStats['absent'] = max(0, $attendanceStats['absent']); // Ensure it's not negative

// Get leave balances
$leaveBalances = [];
$sql = "SELECT lt.name, lb.allocated_days, lb.used_days
        FROM leave_balances lb
        JOIN leave_types lt ON lb.leave_type_id = lt.id
        WHERE lb.user_id = ? AND lb.year = ?";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $userId, $currentYear);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)) {
            $leaveBalances[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get upcoming leaves (next 30 days)
$upcomingLeaves = [];
$nextMonth = date('Y-m-d', strtotime('+30 days'));
$sql = "SELECT lr.start_date, lr.end_date, lr.half_day, lt.name as leave_type
        FROM leave_requests lr
        JOIN leave_types lt ON lr.leave_type_id = lt.id
        WHERE lr.user_id = ? AND lr.status = 'approved' AND 
              ((lr.start_date BETWEEN ? AND ?) OR 
               (lr.end_date BETWEEN ? AND ?))
        ORDER BY lr.start_date ASC";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "issss", $userId, $currentDate, $nextMonth, $currentDate, $nextMonth);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)) {
            $upcomingLeaves[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get upcoming holidays (next 30 days)
$upcomingHolidays = [];
$sql = "SELECT name, date, description
        FROM holidays
        WHERE date BETWEEN ? AND ?
        ORDER BY date ASC";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "ss", $currentDate, $nextMonth);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)) {
            $upcomingHolidays[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get pending leave requests for manager/admin
$pendingLeaves = [];
if($userRole === 'admin' || $userRole === 'manager') {
    $sql = "SELECT lr.id, CONCAT(u.first_name, ' ', u.last_name) as employee_name, u.employee_id, u.department,
                 lt.name as leave_type, lr.start_date, lr.end_date, lr.created_at
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            JOIN leave_types lt ON lr.leave_type_id = lt.id
            WHERE lr.status = 'pending'
            ORDER BY lr.created_at ASC
            LIMIT 5";
    $result = mysqli_query($conn, $sql);
    if($result) {
        while($row = mysqli_fetch_assoc($result)) {
            $pendingLeaves[] = $row;
        }
    }
}

// Get recent attendance records
$recentAttendance = [];
$sql = "SELECT DATE(check_in) as date, TIME(check_in) as check_in_time, 
               TIME(check_out) as check_out_time, status, work_hours
        FROM attendance
        WHERE user_id = ?
        ORDER BY check_in DESC
        LIMIT 5";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)) {
            $recentAttendance[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}
?>

<h1 class="mb-4">Dashboard</h1>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <!-- Attendance Check In/Out -->
                        <?php if($attendanceToday && !empty($attendanceToday['check_out'])): ?>
                            <a class="btn btn-secondary btn-lg w-100" disabled>
                                <i class="fas fa-check-circle me-2"></i> Checked Out
                            </a>
                        <?php elseif($attendanceToday): ?>
                            <a href="attendance.php" class="btn btn-danger btn-lg w-100">
                                <i class="fas fa-sign-out-alt me-2"></i> Check Out
                            </a>
                        <?php else: ?>
                            <a href="attendance.php" class="btn btn-success btn-lg w-100">
                                <i class="fas fa-sign-in-alt me-2"></i> Check In
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <a href="leave_requests.php" class="btn btn-info btn-lg w-100">
                            <i class="fas fa-calendar-plus me-2"></i> Apply for Leave
                        </a>
                    </div>
                    <div class="col-md-3 mb-3">
                        <a href="attendance.php" class="btn btn-warning btn-lg w-100">
                            <i class="fas fa-history me-2"></i> View Attendance
                        </a>
                    </div>
                    <?php if($userRole === 'admin' || $userRole === 'manager'): ?>
                        <div class="col-md-3 mb-3">
                            <a href="manage_leave.php" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-tasks me-2"></i> 
                                Manage Leaves 
                                <?php if(count($pendingLeaves) > 0): ?>
                                    <span class="badge bg-danger ms-1"><?php echo count($pendingLeaves); ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="col-md-3 mb-3">
                            <a href="#" class="btn btn-primary btn-lg w-100" data-bs-toggle="modal" data-bs-target="#holidaysModal">
                                <i class="fas fa-calendar-alt me-2"></i> View Holidays
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Stats and Clock -->
<div class="row mb-4">
    <!-- Live Clock -->
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Current Time</h5>
            </div>
            <div class="card-body text-center py-5">
                <h2 class="display-4 mb-3" id="live-clock"><?php echo date('h:i:s A'); ?></h2>
                <h4 class="mb-4"><?php echo date('l, F j, Y'); ?></h4>
                
                <?php if($attendanceToday): ?>
                    <?php if(empty($attendanceToday['check_out'])): ?>
                        <div class="alert alert-success mb-0">
                            <strong>Checked in at:</strong> <?php echo date('h:i A', strtotime($attendanceToday['check_in'])); ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">
                            <strong>Today's Hours:</strong> 
                            <?php echo date('h:i A', strtotime($attendanceToday['check_in'])); ?> - 
                            <?php echo date('h:i A', strtotime($attendanceToday['check_out'])); ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        You have not checked in today
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Monthly Attendance Summary -->
    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><?php echo date('F Y'); ?> Attendance Summary</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-sm-6 col-md-3 mb-3 mb-md-0">
                        <div class="card bg-light border-0">
                            <div class="card-body text-center">
                                <div class="display-5 text-success mb-2"><?php echo $attendanceStats['present']; ?></div>
                                <div>Present</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3 mb-3 mb-md-0">
                        <div class="card bg-light border-0">
                            <div class="card-body text-center">
                                <div class="display-5 text-warning mb-2"><?php echo $attendanceStats['late']; ?></div>
                                <div>Late</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3 mb-3 mb-md-0">
                        <div class="card bg-light border-0">
                            <div class="card-body text-center">
                                <div class="display-5 text-primary mb-2"><?php echo $attendanceStats['leave']; ?></div>
                                <div>Leave</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-3 mb-3 mb-md-0">
                        <div class="card bg-light border-0">
                            <div class="card-body text-center">
                                <div class="display-5 text-danger mb-2"><?php echo $attendanceStats['absent']; ?></div>
                                <div>Absent</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-6 d-flex">
                        <div class="card flex-grow-1">
                            <div class="card-body text-center">
                                <h5 class="card-title">Working Hours</h5>
                                <h2><?php echo number_format($attendanceStats['working_hours'], 1); ?></h2>
                                <p class="text-muted mb-0">hours this month</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 d-flex">
                        <div class="card flex-grow-1">
                            <div class="card-body text-center">
                                <h5 class="card-title">Overtime</h5>
                                <h2><?php echo number_format($attendanceStats['overtime_hours'], 1); ?></h2>
                                <p class="text-muted mb-0">hours this month</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <!-- Leave Balance -->
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Leave Balance</h5>
            </div>
            <div class="card-body">
                <?php if(!empty($leaveBalances)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Leave Type</th>
                                    <th>Used</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($leaveBalances as $balance): ?>
                                    <tr>
                                        <td><?php echo $balance['name']; ?></td>
                                        <td><?php echo $balance['used_days']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo ($balance['allocated_days'] - $balance['used_days'] > 0) ? 'success' : 'danger'; ?>">
                                                <?php echo $balance['allocated_days'] - $balance['used_days']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center py-3">No leave balances available.</p>
                <?php endif; ?>
                
                <div class="text-center mt-3">
                    <a href="leave_requests.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-calendar-plus me-2"></i> Apply for Leave
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Attendance -->
    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Attendance</h5>
                <a href="attendance.php" class="btn btn-sm btn-light">View All</a>
            </div>
            <div class="card-body">
                <?php if(!empty($recentAttendance)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Hours</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentAttendance as $attendance): ?>
                                    <tr>
                                        <td><?php echo date('D, M d', strtotime($attendance['date'])); ?></td>
                                        <td><?php echo date('h:i A', strtotime($attendance['check_in_time'])); ?></td>
                                        <td>
                                            <?php 
                                            echo !empty($attendance['check_out_time']) 
                                                ? date('h:i A', strtotime($attendance['check_out_time'])) 
                                                : '<span class="text-muted">Not checked out</span>'; 
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            echo !empty($attendance['work_hours']) 
                                                ? number_format($attendance['work_hours'], 1) 
                                                : '-'; 
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $statusClass = '';
                                            $status = $attendance['status'];
                                            
                                            if($status === 'present') {
                                                $statusClass = 'bg-success';
                                            } else if($status === 'late') {
                                                $statusClass = 'bg-warning text-dark';
                                            } else if($status === 'half-day') {
                                                $statusClass = 'bg-info';
                                            } else {
                                                $statusClass = 'bg-danger';
                                            }
                                            ?>
                                            <span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($status); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center py-3">No recent attendance records found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Upcoming Events (Leaves & Holidays) -->
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Upcoming Events</h5>
            </div>
            <div class="card-body">
                <ul class="list-group">
                    <?php 
                    $events = [];
                    
                    // Add leaves
                    foreach($upcomingLeaves as $leave) {
                        $events[] = [
                            'date' => $leave['start_date'],
                            'endDate' => $leave['end_date'],
                            'title' => 'Leave: ' . $leave['leave_type'],
                            'type' => 'leave',
                            'halfDay' => $leave['half_day']
                        ];
                    }
                    
                    // Add holidays
                    foreach($upcomingHolidays as $holiday) {
                        $events[] = [
                            'date' => $holiday['date'],
                            'endDate' => $holiday['date'],
                            'title' => 'Holiday: ' . $holiday['name'],
                            'description' => $holiday['description'],
                            'type' => 'holiday',
                        ];
                    }
                    
                    // Sort by date
                    usort($events, function($a, $b) {
                        return strtotime($a['date']) - strtotime($b['date']);
                    });
                    
                    if(count($events) > 0):
                        foreach($events as $event):
                            $startDate = new DateTime($event['date']);
                            $endDate = new DateTime($event['endDate']);
                            
                            $badgeClass = $event['type'] === 'holiday' ? 'bg-success' : 'bg-info';
                            $iconClass = $event['type'] === 'holiday' ? 'calendar-star' : 'calendar-day';
                    ?>
                        <li class="list-group-item">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">
                                    <i class="fas fa-<?php echo $iconClass; ?> me-2"></i>
                                    <?php echo $event['title']; ?>
                                    <?php if(isset($event['halfDay']) && $event['halfDay']): ?>
                                        <span class="badge bg-warning text-dark ms-1">Half Day</span>
                                    <?php endif; ?>
                                </h6>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php 
                                        if($startDate->format('Y-m-d') === $endDate->format('Y-m-d')) {
                                            echo $startDate->format('M d');
                                        } else {
                                            echo $startDate->format('M d') . ' - ' . $endDate->format('M d');
                                        }
                                    ?>
                                </span>
                            </div>
                            <?php if(isset($event['description']) && !empty($event['description'])): ?>
                                <small class="text-muted"><?php echo $event['description']; ?></small>
                            <?php endif; ?>
                        </li>
                    <?php 
                        endforeach;
                    else:
                    ?>
                        <li class="list-group-item text-center">No upcoming events found.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Pending Leave Requests (For Managers/Admins) -->
    <?php if($userRole === 'admin' || $userRole === 'manager'): ?>
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Pending Leave Requests</h5>
                    <a href="manage_leave.php" class="btn btn-sm btn-light">View All</a>
                </div>
                <div class="card-body">
                    <?php if(!empty($pendingLeaves)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Department</th>
                                        <th>Leave Type</th>
                                        <th>Period</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($pendingLeaves as $leave): ?>
                                        <tr>
                                            <td><?php echo $leave['employee_name']; ?></td>
                                            <td><?php echo $leave['department']; ?></td>
                                            <td><?php echo $leave['leave_type']; ?></td>
                                            <td>
                                                <?php 
                                                    echo date('M d', strtotime($leave['start_date']));
                                                    if($leave['start_date'] != $leave['end_date']) {
                                                        echo ' - ' . date('M d', strtotime($leave['end_date']));
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <a href="manage_leave.php?action=view&id=<?php echo $leave['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i> Review
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center py-3">No pending leave requests.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- User's Leave Requests -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">My Leave Requests</h5>
                    <a href="leave_requests.php" class="btn btn-sm btn-light">View All</a>
                </div>
                <div class="card-body">
                    <?php
                    // Get user's leave requests
                    $userLeaves = [];
                    $sql = "SELECT lr.id, lr.start_date, lr.end_date, lr.status, lr.half_day, 
                                  lt.name as leave_type
                           FROM leave_requests lr
                           JOIN leave_types lt ON lr.leave_type_id = lt.id
                           WHERE lr.user_id = ?
                           ORDER BY lr.created_at DESC
                           LIMIT 5";
                    if($stmt = mysqli_prepare($conn, $sql)) {
                        mysqli_stmt_bind_param($stmt, "i", $userId);
                        if(mysqli_stmt_execute($stmt)) {
                            $result = mysqli_stmt_get_result($stmt);
                            while($row = mysqli_fetch_assoc($result)) {
                                $userLeaves[] = $row;
                            }
                        }
                        mysqli_stmt_close($stmt);
                    }
                    
                    if(!empty($userLeaves)): 
                    ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Leave Type</th>
                                        <th>Period</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($userLeaves as $leave): ?>
                                        <tr>
                                            <td>
                                                <?php echo $leave['leave_type']; ?>
                                                <?php if($leave['half_day']): ?>
                                                    <span class="badge bg-info">Half Day</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    echo date('M d', strtotime($leave['start_date']));
                                                    if($leave['start_date'] != $leave['end_date']) {
                                                        echo ' - ' . date('M d', strtotime($leave['end_date']));
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo ($leave['status'] === 'approved') ? 'success' : 
                                                        (($leave['status'] === 'rejected') ? 'danger' : 'warning'); 
                                                ?>">
                                                    <?php echo ucfirst($leave['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center py-3">No leave requests found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Holidays Modal -->
<div class="modal fade" id="holidaysModal" tabindex="-1" aria-labelledby="holidaysModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="holidaysModalLabel">Upcoming Holidays</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if(!empty($upcomingHolidays)): ?>
                    <div class="list-group">
                        <?php foreach($upcomingHolidays as $holiday): ?>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1"><?php echo $holiday['name']; ?></h5>
                                    <small><?php echo date('l, F j, Y', strtotime($holiday['date'])); ?></small>
                                </div>
                                <?php if(!empty($holiday['description'])): ?>
                                    <p class="mb-1"><?php echo $holiday['description']; ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-center">No upcoming holidays found.</p>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function updateClock() {
    const now = new Date();
    const hours = now.getHours() % 12 || 12;
    const minutes = now.getMinutes().toString().padStart(2, '0');
    const seconds = now.getSeconds().toString().padStart(2, '0');
    const ampm = now.getHours() >= 12 ? 'PM' : 'AM';
    
    document.getElementById('live-clock').textContent = `${hours}:${minutes}:${seconds} ${ampm}`;
    setTimeout(updateClock, 1000);
}

document.addEventListener('DOMContentLoaded', function() {
    updateClock();
});
</script>

<?php
// Include footer
include_once "includes/footer.php";
?>