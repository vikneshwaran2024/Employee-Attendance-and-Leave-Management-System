<?php
// Include session management
require_once "includes/session.php";

// Require user to be logged in
requireLogin();

// Include database configuration
require_once "config/database.php";

// Get user data
$user_id = $_SESSION["id"];

// Handle month/year filtering
$current_month = date('n'); // Current month (1-12)
$current_year = date('Y'); // Current year

// Get month and year from query parameters or use current month/year
$month = isset($_GET['month']) ? intval($_GET['month']) : $current_month;
$year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

// Validate month and year
if ($month < 1 || $month > 12) {
    $month = $current_month;
}

if ($year < 2020 || $year > 2030) {
    $year = $current_year;
}

// Format month and year for display
$month_name = date('F', mktime(0, 0, 0, $month, 1, $year));
$month_year = $month_name . ' ' . $year;

// Check if user has already checked in today
$checked_in = false;
$can_check_out = false;
$current_status = 'Not checked in';
$check_in_time = null;

$today = date('Y-m-d');
$check_sql = "SELECT id, check_in, check_out FROM attendance 
             WHERE user_id = ? AND DATE(check_in) = ?";

if ($check_stmt = mysqli_prepare($conn, $check_sql)) {
    mysqli_stmt_bind_param($check_stmt, "is", $user_id, $today);
    
    if (mysqli_stmt_execute($check_stmt)) {
        $result = mysqli_stmt_get_result($check_stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            $checked_in = true;
            $check_in_time = $row['check_in'];
            
            if (is_null($row['check_out'])) {
                $can_check_out = true;
                $current_status = 'Checked in';
            } else {
                $current_status = 'Checked out';
            }
        }
    }
    
    mysqli_stmt_close($check_stmt);
}

// Get work hours statistics for the current month
$total_hours = 0;
$total_days = 0;
$avg_hours = 0;

$start_date = date('Y-m-01', strtotime("$year-$month-01"));
$end_date = date('Y-m-t', strtotime("$year-$month-01"));

$stats_sql = "SELECT 
                COUNT(DISTINCT DATE(check_in)) as total_days,
                SUM(TIMESTAMPDIFF(MINUTE, check_in, IFNULL(check_out, NOW())) / 60) as total_hours
              FROM attendance 
              WHERE user_id = ? AND check_in BETWEEN ? AND ?";

if ($stats_stmt = mysqli_prepare($conn, $stats_sql)) {
    mysqli_stmt_bind_param($stats_stmt, "iss", $user_id, $start_date, $end_date);
    
    if (mysqli_stmt_execute($stats_stmt)) {
        $result = mysqli_stmt_get_result($stats_stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            $total_hours = round($row['total_hours'], 1);
            $total_days = $row['total_days'];
            
            if ($total_days > 0) {
                $avg_hours = round($total_hours / $total_days, 1);
            }
        }
    }
    
    mysqli_stmt_close($stats_stmt);
}

// Get attendance history for the selected month
$attendance_history = [];
$history_sql = "SELECT 
                  DATE(check_in) as date,
                  TIME_FORMAT(check_in, '%h:%i %p') as check_in_time,
                  TIME_FORMAT(check_out, '%h:%i %p') as check_out_time,
                  TIMESTAMPDIFF(MINUTE, check_in, IFNULL(check_out, NOW())) as minutes_worked,
                  ip_address
                FROM attendance 
                WHERE user_id = ? AND check_in BETWEEN ? AND ?
                ORDER BY check_in DESC";

if ($history_stmt = mysqli_prepare($conn, $history_sql)) {
    mysqli_stmt_bind_param($history_stmt, "iss", $user_id, $start_date, $end_date);
    
    if (mysqli_stmt_execute($history_stmt)) {
        $result = mysqli_stmt_get_result($history_stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            // Calculate hours and minutes
            $hours = floor($row['minutes_worked'] / 60);
            $minutes = $row['minutes_worked'] % 60;
            $row['hours_worked'] = $hours . 'h ' . $minutes . 'm';
            
            $attendance_history[] = $row;
        }
    }
    
    mysqli_stmt_close($history_stmt);
}

// Get leave days for the month to show in the calendar
$leave_days = [];
$leave_sql = "SELECT start_date, end_date, status, half_day, lt.name as leave_type
              FROM leave_requests lr
              JOIN leave_types lt ON lr.leave_type_id = lt.id
              WHERE lr.user_id = ? AND 
                    lr.status != 'rejected' AND 
                    ((lr.start_date BETWEEN ? AND ?) OR 
                     (lr.end_date BETWEEN ? AND ?) OR 
                     (lr.start_date <= ? AND lr.end_date >= ?))";

if ($leave_stmt = mysqli_prepare($conn, $leave_sql)) {
    mysqli_stmt_bind_param($leave_stmt, "issssss", $user_id, $start_date, $end_date, $start_date, $end_date, $start_date, $end_date);
    
    if (mysqli_stmt_execute($leave_stmt)) {
        $result = mysqli_stmt_get_result($leave_stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $start = max(strtotime($row['start_date']), strtotime($start_date));
            $end = min(strtotime($row['end_date']), strtotime($end_date));
            
            for ($i = $start; $i <= $end; $i = strtotime("+1 day", $i)) {
                $date = date('Y-m-d', $i);
                $day_of_week = date('N', $i);
                
                // Skip weekends (6=Saturday, 7=Sunday)
                if ($day_of_week < 6) {
                    $leave_days[$date] = [
                        'type' => $row['leave_type'],
                        'status' => $row['status'],
                        'half_day' => $row['half_day']
                    ];
                }
            }
        }
    }
    
    mysqli_stmt_close($leave_stmt);
}

// Get holidays for the current month
$holidays = [];
$holiday_sql = "SELECT date, name FROM holidays WHERE date BETWEEN ? AND ?";

if ($holiday_stmt = mysqli_prepare($conn, $holiday_sql)) {
    mysqli_stmt_bind_param($holiday_stmt, "ss", $start_date, $end_date);
    
    if (mysqli_stmt_execute($holiday_stmt)) {
        $result = mysqli_stmt_get_result($holiday_stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $holidays[$row['date']] = $row['name'];
        }
    }
    
    mysqli_stmt_close($holiday_stmt);
}

// Generate calendar data
$first_day_of_month = strtotime("$year-$month-01");
$last_day_of_month = strtotime(date('Y-m-t', $first_day_of_month));
$first_day_of_week = date('N', $first_day_of_month); // 1 (Monday) to 7 (Sunday)
$total_days_in_month = date('t', $first_day_of_month);

// Include header
include_once "includes/header.php";

// Check if user is logged in
requireLogin();

// Get user data
$userId = $_SESSION["id"];
$name = $_SESSION["name"];

// Get current date and time
$currentDate = date("Y-m-d");
$currentTime = date("H:i:s");

// Check if the user has already checked in today
$hasCheckedIn = false;
$hasCheckedOut = false;
$checkInTime = '';
$checkOutTime = '';
$workHours = 0;

$sql = "SELECT id, check_in, check_out, work_hours FROM attendance WHERE user_id = ? AND DATE(check_in) = ? ORDER BY id DESC LIMIT 1";
if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "is", $userId, $currentDate);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        if($row = mysqli_fetch_assoc($result)) {
            $attendanceId = $row['id'];
            $checkInTime = $row['check_in'];
            $checkOutTime = $row['check_out'];
            $workHours = $row['work_hours'];
            $hasCheckedIn = true;
            $hasCheckedOut = !empty($checkOutTime);
        }
    }
    mysqli_stmt_close($stmt);
}

// Process check-in
if(isset($_POST['check_in']) && !$hasCheckedIn) {
    $sql = "INSERT INTO attendance (user_id, check_in, status, ip_address) VALUES (?, NOW(), 'present', ?)";
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "is", $userId, $_SERVER['REMOTE_ADDR']);
        if(mysqli_stmt_execute($stmt)) {
            // Redirect to refresh the page
            header("Location: attendance.php?success=checked_in");
            exit;
        } else {
            $error = "Error marking attendance. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}

// Process check-out
if(isset($_POST['check_out']) && $hasCheckedIn && !$hasCheckedOut) {
    // Calculate working hours
    $checkIn = new DateTime($checkInTime);
    $checkOut = new DateTime(date('Y-m-d H:i:s'));
    $interval = $checkIn->diff($checkOut);
    $hours = $interval->h + ($interval->i / 60) + ($interval->s / 3600);
    $hours = round($hours, 2);
    
    // Determine overtime (assuming 8 hours is standard)
    $overtime = max(0, $hours - 8);
    
    $sql = "UPDATE attendance SET check_out = NOW(), work_hours = ?, overtime_hours = ? WHERE id = ?";
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "ddi", $hours, $overtime, $attendanceId);
        if(mysqli_stmt_execute($stmt)) {
            // Redirect to refresh the page
            header("Location: attendance.php?success=checked_out");
            exit;
        } else {
            $error = "Error updating attendance. Please try again.";
        }
        mysqli_stmt_close($stmt);
    }
}

// Get attendance history
$attendanceHistory = [];
$sql = "SELECT DATE(check_in) as date, TIME(check_in) as check_in_time, TIME(check_out) as check_out_time, work_hours, overtime_hours, status 
        FROM attendance 
        WHERE user_id = ? 
        ORDER BY check_in DESC 
        LIMIT 10";

if($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $userId);
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)) {
            $attendanceHistory[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}
?>

<h1 class="mb-4">Attendance Management</h1>

<?php if(isset($_GET['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php 
        $success = $_GET['success'];
        if($success == 'checked_in') {
            echo "You have successfully checked in at " . date('h:i A') . ".";
        } elseif($success == 'checked_out') {
            echo "You have successfully checked out at " . date('h:i A') . ".";
        }
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if(isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Today's Attendance</h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <h2 class="mb-0"><?php echo date('l, F j, Y'); ?></h2>
                    <div class="fs-4 mt-2" id="live-clock">
                        <?php echo date('h:i:s A'); ?>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-sm-6">
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Check-in Time</h5>
                                <?php if($hasCheckedIn): ?>
                                    <p class="card-text"><?php echo date('h:i:s A', strtotime($checkInTime)); ?></p>
                                <?php else: ?>
                                    <p class="card-text text-muted">Not checked in yet</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="card bg-light mb-3">
                            <div class="card-body">
                                <h5 class="card-title">Check-out Time</h5>
                                <?php if($hasCheckedOut): ?>
                                    <p class="card-text"><?php echo date('h:i:s A', strtotime($checkOutTime)); ?></p>
                                <?php else: ?>
                                    <p class="card-text text-muted">Not checked out yet</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if($hasCheckedOut): ?>
                <div class="alert alert-success text-center" role="alert">
                    <strong>Work Hours Today: <?php echo $workHours; ?> hours</strong>
                </div>
                <?php endif; ?>
                
                <div class="d-grid gap-2 mt-4">
                    <form method="post" id="attendance-form">
                        <?php if(!$hasCheckedIn): ?>
                            <button type="submit" name="check_in" class="btn btn-primary btn-lg" id="check-in-btn">
                                <i class="fas fa-sign-in-alt me-2"></i> Check In
                            </button>
                        <?php elseif(!$hasCheckedOut): ?>
                            <button type="submit" name="check_out" class="btn btn-danger btn-lg" id="check-out-btn">
                                <i class="fas fa-sign-out-alt me-2"></i> Check Out
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary btn-lg" disabled>
                                <i class="fas fa-check-circle me-2"></i> Attendance Completed
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Attendance Statistics</h5>
            </div>
            <div class="card-body p-4">
                <div class="row">
                    <?php
                    // Calculate statistics
                    $present = 0;
                    $late = 0;
                    $halfDay = 0;
                    $absent = 0;
                    
                    foreach($attendanceHistory as $record) {
                        if($record['status'] === 'present') {
                            $present++;
                        } elseif($record['status'] === 'late') {
                            $late++;
                        } elseif($record['status'] === 'half-day') {
                            $halfDay++;
                        } else {
                            $absent++;
                        }
                    }
                    
                    // Calculate total work hours
                    $totalWorkHours = 0;
                    foreach($attendanceHistory as $record) {
                        $totalWorkHours += $record['work_hours'] ?? 0;
                    }
                    ?>
                    
                    <div class="col-6 mb-4">
                        <div class="card bg-light border-0 h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 bg-success-subtle p-3 rounded">
                                        <i class="fas fa-calendar-check text-success fa-2x"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h5 class="mb-0"><?php echo $present; ?></h5>
                                        <div class="text-muted">Present</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 mb-4">
                        <div class="card bg-light border-0 h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 bg-warning-subtle p-3 rounded">
                                        <i class="fas fa-clock text-warning fa-2x"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h5 class="mb-0"><?php echo $late; ?></h5>
                                        <div class="text-muted">Late</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 mb-4">
                        <div class="card bg-light border-0 h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 bg-info-subtle p-3 rounded">
                                        <i class="fas fa-adjust text-info fa-2x"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h5 class="mb-0"><?php echo $halfDay; ?></h5>
                                        <div class="text-muted">Half Day</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-6 mb-4">
                        <div class="card bg-light border-0 h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0 bg-danger-subtle p-3 rounded">
                                        <i class="fas fa-calendar-xmark text-danger fa-2x"></i>
                                    </div>
                                    <div class="ms-3">
                                        <h5 class="mb-0"><?php echo $absent; ?></h5>
                                        <div class="text-muted">Absent</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-2">
                    <div class="card bg-light">
                        <div class="card-body">
                            <h5>Total Work Hours</h5>
                            <div class="display-6"><?php echo number_format($totalWorkHours, 1); ?> hrs</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Attendance History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="attendance-history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Work Hours</th>
                        <th>Overtime</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($attendanceHistory) > 0): ?>
                        <?php foreach($attendanceHistory as $record): ?>
                            <tr>
                                <td><?php echo date('D, M d, Y', strtotime($record['date'])); ?></td>
                                <td><?php echo $record['check_in_time'] ? date('h:i A', strtotime($record['check_in_time'])) : 'N/A'; ?></td>
                                <td><?php echo $record['check_out_time'] ? date('h:i A', strtotime($record['check_out_time'])) : 'N/A'; ?></td>
                                <td><?php echo $record['work_hours'] ? number_format($record['work_hours'], 1) . ' hrs' : 'N/A'; ?></td>
                                <td><?php echo $record['overtime_hours'] ? number_format($record['overtime_hours'], 1) . ' hrs' : '0 hrs'; ?></td>
                                <td>
                                    <?php
                                    $status = $record['status'];
                                    $badgeClass = 'bg-success';
                                    if($status === 'late') {
                                        $badgeClass = 'bg-warning text-dark';
                                    } elseif($status === 'half-day') {
                                        $badgeClass = 'bg-info';
                                    } elseif($status === 'absent') {
                                        $badgeClass = 'bg-danger';
                                    }
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($status); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">No attendance records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if(count($attendanceHistory) > 0): ?>
        <div class="text-end mt-3">
            <a href="attendance_report.php" class="btn btn-primary">
                <i class="fas fa-file-download me-2"></i> Download Full Report
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Update live clock
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

<style>
.attendance-stat-card {
    border-left: 4px solid #0d6efd;
    height: 100%;
}

.calendar-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    text-align: center;
    font-weight: bold;
    margin-bottom: 10px;
}

.calendar-body {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 5px;
}

.calendar-day {
    border: 1px solid #ddd;
    min-height: 80px;
    padding: 5px;
    position: relative;
}

.calendar-day.empty {
    background-color: #f9f9f9;
}

.calendar-day.weekend {
    background-color: #f5f5f5;
}

.calendar-day.today {
    border: 2px solid #0d6efd;
}

.calendar-day.has-attendance {
    background-color: rgba(40, 167, 69, 0.1);
}

.calendar-day.holiday {
    background-color: rgba(255, 193, 7, 0.1);
}

.calendar-day.leave-approved {
    background-color: rgba(13, 110, 253, 0.1);
}

.calendar-day.leave-pending {
    background-color: rgba(108, 117, 125, 0.1);
}

.day-number {
    font-weight: bold;
    margin-bottom: 5px;
}

.day-status {
    font-size: 0.8rem;
}

.time-tag {
    margin-bottom: 3px;
}

.holiday-tag {
    color: #856404;
    font-size: 0.75rem;
    overflow: hidden;
    text-overflow: ellipsis;
}

.leave-tag {
    color: #0d6efd;
    font-size: 0.75rem;
}

.absent-tag {
    color: #dc3545;
    font-size: 0.75rem;
}

.calendar-legend {
    gap: 15px;
}

.legend-item {
    display: flex;
    align-items: center;
    font-size: 0.8rem;
}

.legend-color {
    display: inline-block;
    width: 15px;
    height: 15px;
    margin-right: 5px;
    border: 1px solid #ddd;
}

.legend-color.today {
    border: 2px solid #0d6efd;
}

.legend-color.has-attendance {
    background-color: rgba(40, 167, 69, 0.1);
}

.legend-color.holiday {
    background-color: rgba(255, 193, 7, 0.1);
}

.legend-color.leave-approved {
    background-color: rgba(13, 110, 253, 0.1);
}

.legend-color.leave-pending {
    background-color: rgba(108, 117, 125, 0.1);
}

.legend-color.weekend {
    background-color: #f5f5f5;
}

.legend-color.absent {
    background-color: rgba(220, 53, 69, 0.1);
}
</style>

<?php
// Include footer
include_once "includes/footer.php";
?>