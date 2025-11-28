<?php
/**
 * Attendance Page
 * View and manage attendance records
 */
$pageTitle = 'Attendance';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$userId = getCurrentUserId();
$month = $_GET['month'] ?? date('Y-m');
$year = substr($month, 0, 4);
$monthNum = substr($month, 5, 2);

// Get attendance records for the selected month
$attendanceRecords = executeQuery(
    "SELECT date, check_in, check_out, work_hours, overtime_hours, status, notes 
     FROM attendance 
     WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
     ORDER BY date DESC",
    [$userId, $month]
);

// Calculate monthly summary
$summary = executeQuery(
    "SELECT 
        COUNT(CASE WHEN status = 'present' THEN 1 END) as present,
        COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent,
        COUNT(CASE WHEN status = 'late' THEN 1 END) as late,
        COUNT(CASE WHEN status = 'half_day' THEN 1 END) as half_day,
        COUNT(CASE WHEN status = 'on_leave' THEN 1 END) as on_leave,
        COALESCE(SUM(work_hours), 0) as total_hours,
        COALESCE(SUM(overtime_hours), 0) as overtime,
        COALESCE(AVG(work_hours), 0) as avg_hours
     FROM attendance 
     WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?",
    [$userId, $month]
);
$stats = $summary && count($summary) > 0 ? $summary[0] : [];

// Calculate previous and next months
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
$currentMonth = date('Y-m');

// Get holidays for this month
$holidays = executeQuery(
    "SELECT date, name FROM holidays WHERE DATE_FORMAT(date, '%Y-%m') = ?",
    [$month]
);
$holidayDates = [];
if ($holidays) {
    foreach ($holidays as $h) {
        $holidayDates[$h['date']] = $h['name'];
    }
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Attendance</h1>
        <p class="text-muted mb-0">Track your attendance records</p>
    </div>
    <div>
        <button onclick="printPage()" class="btn btn-outline-secondary">
            <i class="fas fa-print me-2"></i>Print
        </button>
        <button onclick="exportTableToCSV('attendance-table', 'attendance-<?php echo $month; ?>.csv')" class="btn btn-outline-success">
            <i class="fas fa-file-csv me-2"></i>Export
        </button>
    </div>
</div>

<!-- Month Navigation -->
<div class="card dashboard-card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <a href="?month=<?php echo $prevMonth; ?>" class="btn btn-outline-primary">
                <i class="fas fa-chevron-left me-2"></i>Previous
            </a>
            <h4 class="mb-0"><?php echo date('F Y', strtotime($month . '-01')); ?></h4>
            <?php if ($nextMonth <= $currentMonth): ?>
            <a href="?month=<?php echo $nextMonth; ?>" class="btn btn-outline-primary">
                Next<i class="fas fa-chevron-right ms-2"></i>
            </a>
            <?php else: ?>
            <button class="btn btn-outline-secondary" disabled>
                Next<i class="fas fa-chevron-right ms-2"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Monthly Summary -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card dashboard-card card-stat success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-success">Present Days</div>
                    <div class="stat-value"><?php echo $stats['present'] ?? 0; ?></div>
                </div>
                <i class="fas fa-check-circle fa-2x text-success opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card dashboard-card card-stat danger">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-danger">Absent Days</div>
                    <div class="stat-value"><?php echo $stats['absent'] ?? 0; ?></div>
                </div>
                <i class="fas fa-times-circle fa-2x text-danger opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card dashboard-card card-stat warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-warning">Late Days</div>
                    <div class="stat-value"><?php echo $stats['late'] ?? 0; ?></div>
                </div>
                <i class="fas fa-exclamation-circle fa-2x text-warning opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card dashboard-card card-stat info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-info">Total Hours</div>
                    <div class="stat-value"><?php echo number_format($stats['total_hours'] ?? 0, 1); ?></div>
                </div>
                <i class="fas fa-clock fa-2x text-info opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Table -->
<div class="card dashboard-card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Attendance Records</h5>
        <div>
            <span class="badge bg-success me-1">Present: <?php echo $stats['present'] ?? 0; ?></span>
            <span class="badge bg-warning me-1">Late: <?php echo $stats['late'] ?? 0; ?></span>
            <span class="badge bg-secondary">Leave: <?php echo $stats['on_leave'] ?? 0; ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="attendance-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Work Hours</th>
                        <th>Overtime</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($attendanceRecords && count($attendanceRecords) > 0): ?>
                        <?php foreach ($attendanceRecords as $record): ?>
                        <tr>
                            <td><?php echo formatDate($record['date']); ?></td>
                            <td><?php echo date('l', strtotime($record['date'])); ?></td>
                            <td>
                                <?php if ($record['check_in']): ?>
                                    <span class="text-success"><i class="fas fa-sign-in-alt me-1"></i><?php echo formatTime($record['check_in']); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($record['check_out']): ?>
                                    <span class="text-danger"><i class="fas fa-sign-out-alt me-1"></i><?php echo formatTime($record['check_out']); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($record['work_hours'], 2); ?> hrs</td>
                            <td>
                                <?php if ($record['overtime_hours'] > 0): ?>
                                    <span class="text-warning"><?php echo number_format($record['overtime_hours'], 2); ?> hrs</span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo formatStatusBadge($record['status']); ?></td>
                            <td><?php echo $record['notes'] ? sanitize($record['notes']) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="fas fa-calendar-times fa-3x mb-3 d-block opacity-50"></i>
                                No attendance records found for <?php echo date('F Y', strtotime($month . '-01')); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        <div class="row text-center">
            <div class="col-md-4">
                <strong>Average Hours/Day:</strong> <?php echo number_format($stats['avg_hours'] ?? 0, 2); ?>
            </div>
            <div class="col-md-4">
                <strong>Total Work Hours:</strong> <?php echo number_format($stats['total_hours'] ?? 0, 2); ?>
            </div>
            <div class="col-md-4">
                <strong>Total Overtime:</strong> <?php echo number_format($stats['overtime'] ?? 0, 2); ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
