<?php
/**
 * Reports Page
 * Attendance statistics and reports for managers and admins
 */
$pageTitle = 'Reports';
require_once __DIR__ . '/includes/header.php';
requireRole(['manager', 'admin']);

$reportType = $_GET['type'] ?? 'attendance';
$month = $_GET['month'] ?? date('Y-m');
$departmentId = isset($_GET['department']) ? (int) $_GET['department'] : 0;
$year = substr($month, 0, 4);
$monthNum = substr($month, 5, 2);

// Get departments for filter
$departments = getDepartments();

// Build query conditions
$conditions = [];
$params = [];

if ($departmentId && hasRole('admin')) {
    $conditions[] = "u.department_id = ?";
    $params[] = $departmentId;
} elseif (hasRole('manager') && !hasRole('admin')) {
    $conditions[] = "u.department_id = ?";
    $params[] = $_SESSION['department_id'];
}

$whereClause = count($conditions) > 0 ? "AND " . implode(" AND ", $conditions) : "";

// Get attendance summary for the month
$attendanceSummary = executeQuery(
    "SELECT 
        u.id, u.first_name, u.last_name, u.employee_id, d.name as department_name,
        COUNT(CASE WHEN a.status = 'present' THEN 1 END) as present_days,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as absent_days,
        COUNT(CASE WHEN a.status = 'late' THEN 1 END) as late_days,
        COUNT(CASE WHEN a.status = 'on_leave' THEN 1 END) as leave_days,
        COALESCE(SUM(a.work_hours), 0) as total_hours,
        COALESCE(SUM(a.overtime_hours), 0) as overtime_hours,
        COALESCE(AVG(a.work_hours), 0) as avg_hours
     FROM users u
     LEFT JOIN departments d ON u.department_id = d.id
     LEFT JOIN attendance a ON u.id = a.user_id AND DATE_FORMAT(a.date, '%Y-%m') = ?
     WHERE u.status = 'active' AND u.role = 'employee' $whereClause
     GROUP BY u.id
     ORDER BY u.first_name, u.last_name",
    array_merge([$month], $params)
);

// Get leave summary
$leaveSummary = executeQuery(
    "SELECT 
        lt.name as leave_type,
        COUNT(CASE WHEN lr.status = 'approved' THEN 1 END) as approved_count,
        COUNT(CASE WHEN lr.status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN lr.status = 'rejected' THEN 1 END) as rejected_count,
        COALESCE(SUM(CASE WHEN lr.status = 'approved' THEN lr.days_count END), 0) as total_days
     FROM leave_types lt
     LEFT JOIN leave_requests lr ON lt.id = lr.leave_type_id 
        AND DATE_FORMAT(lr.start_date, '%Y-%m') = ?
     GROUP BY lt.id
     ORDER BY lt.name",
    [$month]
);

// Get overall stats
$overallStats = executeQuery(
    "SELECT 
        COUNT(DISTINCT a.user_id) as total_employees,
        COUNT(CASE WHEN a.status = 'present' OR a.status = 'late' THEN 1 END) as total_present,
        COUNT(CASE WHEN a.status = 'absent' THEN 1 END) as total_absent,
        COALESCE(SUM(a.work_hours), 0) as total_hours,
        COALESCE(SUM(a.overtime_hours), 0) as total_overtime
     FROM attendance a
     JOIN users u ON a.user_id = u.id
     WHERE DATE_FORMAT(a.date, '%Y-%m') = ? AND u.status = 'active' $whereClause",
    array_merge([$month], $params)
);
$stats = $overallStats && count($overallStats) > 0 ? $overallStats[0] : [];

// Previous/Next month navigation
$prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
$nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));
$currentMonth = date('Y-m');
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Reports</h1>
        <p class="text-muted mb-0">Attendance and leave statistics</p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="printPage()" class="btn btn-outline-secondary">
            <i class="fas fa-print me-2"></i>Print
        </button>
        <button onclick="exportTableToCSV('report-table', 'report-<?php echo $month; ?>.csv')" class="btn btn-outline-success">
            <i class="fas fa-file-csv me-2"></i>Export
        </button>
    </div>
</div>

<!-- Filters -->
<div class="card dashboard-card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Month</label>
                <input type="month" class="form-control" name="month" value="<?php echo $month; ?>">
            </div>
            
            <?php if (hasRole('admin')): ?>
            <div class="col-md-4">
                <label class="form-label">Department</label>
                <select class="form-select" name="department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>" <?php echo $departmentId == $dept['id'] ? 'selected' : ''; ?>>
                        <?php echo sanitize($dept['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter me-2"></i>Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Month Navigation -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="?month=<?php echo $prevMonth; ?><?php echo $departmentId ? '&department=' . $departmentId : ''; ?>" class="btn btn-outline-primary">
        <i class="fas fa-chevron-left me-2"></i>Previous
    </a>
    <h4 class="mb-0"><?php echo date('F Y', strtotime($month . '-01')); ?></h4>
    <?php if ($nextMonth <= $currentMonth): ?>
    <a href="?month=<?php echo $nextMonth; ?><?php echo $departmentId ? '&department=' . $departmentId : ''; ?>" class="btn btn-outline-primary">
        Next<i class="fas fa-chevron-right ms-2"></i>
    </a>
    <?php else: ?>
    <button class="btn btn-outline-secondary" disabled>
        Next<i class="fas fa-chevron-right ms-2"></i>
    </button>
    <?php endif; ?>
</div>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card dashboard-card card-stat primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-primary">Total Employees</div>
                    <div class="stat-value"><?php echo $stats['total_employees'] ?? 0; ?></div>
                </div>
                <i class="fas fa-users fa-2x text-primary opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card dashboard-card card-stat success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-success">Present Count</div>
                    <div class="stat-value"><?php echo $stats['total_present'] ?? 0; ?></div>
                </div>
                <i class="fas fa-check-circle fa-2x text-success opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card dashboard-card card-stat info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-info">Total Hours</div>
                    <div class="stat-value"><?php echo number_format($stats['total_hours'] ?? 0, 0); ?></div>
                </div>
                <i class="fas fa-clock fa-2x text-info opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card dashboard-card card-stat warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label text-warning">Overtime Hours</div>
                    <div class="stat-value"><?php echo number_format($stats['total_overtime'] ?? 0, 0); ?></div>
                </div>
                <i class="fas fa-hourglass-half fa-2x text-warning opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Report Table -->
<div class="card dashboard-card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-table me-2"></i>Employee Attendance Summary</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="report-table">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th class="text-center">Present</th>
                        <th class="text-center">Absent</th>
                        <th class="text-center">Late</th>
                        <th class="text-center">Leave</th>
                        <th class="text-center">Work Hours</th>
                        <th class="text-center">Overtime</th>
                        <th class="text-center">Avg/Day</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($attendanceSummary && count($attendanceSummary) > 0): ?>
                        <?php foreach ($attendanceSummary as $emp): ?>
                        <tr>
                            <td><?php echo sanitize($emp['employee_id']); ?></td>
                            <td><strong><?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?></strong></td>
                            <td><?php echo sanitize($emp['department_name'] ?? 'N/A'); ?></td>
                            <td class="text-center">
                                <span class="badge bg-success"><?php echo $emp['present_days']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-danger"><?php echo $emp['absent_days']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning"><?php echo $emp['late_days']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?php echo $emp['leave_days']; ?></span>
                            </td>
                            <td class="text-center"><?php echo number_format($emp['total_hours'], 1); ?></td>
                            <td class="text-center"><?php echo number_format($emp['overtime_hours'], 1); ?></td>
                            <td class="text-center"><?php echo number_format($emp['avg_hours'], 1); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No data available for this period
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Leave Summary -->
<div class="card dashboard-card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-plane-departure me-2"></i>Leave Summary</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Leave Type</th>
                        <th class="text-center">Approved</th>
                        <th class="text-center">Pending</th>
                        <th class="text-center">Rejected</th>
                        <th class="text-center">Total Days Taken</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($leaveSummary && count($leaveSummary) > 0): ?>
                        <?php foreach ($leaveSummary as $leave): ?>
                        <tr>
                            <td><strong><?php echo sanitize($leave['leave_type']); ?></strong></td>
                            <td class="text-center">
                                <span class="badge bg-success"><?php echo $leave['approved_count']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning"><?php echo $leave['pending_count']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-danger"><?php echo $leave['rejected_count']; ?></span>
                            </td>
                            <td class="text-center"><?php echo $leave['total_days']; ?> days</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                No leave data available
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
