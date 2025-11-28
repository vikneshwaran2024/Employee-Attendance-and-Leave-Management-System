<?php
/**
 * Leave Request Page
 * Submit and view leave requests
 */
$pageTitle = 'Leave Requests';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$userId = getCurrentUserId();
$action = $_GET['action'] ?? 'list';
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $leaveTypeId = (int) ($_POST['leave_type_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        
        // Validation
        if (!$leaveTypeId || !$startDate || !$endDate || !$reason) {
            $error = 'Please fill in all required fields.';
        } elseif (strtotime($startDate) > strtotime($endDate)) {
            $error = 'End date cannot be before start date.';
        } elseif (strtotime($startDate) < strtotime(date('Y-m-d'))) {
            $error = 'Start date cannot be in the past.';
        } elseif (strlen($reason) < 10) {
            $error = 'Please provide a detailed reason (at least 10 characters).';
        } else {
            // Calculate days count
            $daysCount = calculateWorkingDays($startDate, $endDate);
            
            // Check leave balance
            $balance = getLeaveBalance($userId, $leaveTypeId);
            
            if ($daysCount > $balance['remaining_days']) {
                $error = 'Insufficient leave balance. You have ' . $balance['remaining_days'] . ' days remaining.';
            } else {
                // Insert leave request
                $result = executeInsert(
                    "INSERT INTO leave_requests (user_id, leave_type_id, start_date, end_date, days_count, reason) VALUES (?, ?, ?, ?, ?, ?)",
                    [$userId, $leaveTypeId, $startDate, $endDate, $daysCount, $reason]
                );
                
                if ($result !== false) {
                    logActivity('Leave Request', 'Submitted leave request from ' . $startDate . ' to ' . $endDate);
                    
                    // Notify managers
                    $managers = executeQuery(
                        "SELECT id FROM users WHERE role IN ('manager', 'admin') AND status = 'active'"
                    );
                    if ($managers) {
                        foreach ($managers as $manager) {
                            createNotification(
                                $manager['id'],
                                'New Leave Request',
                                getCurrentUserName() . ' has submitted a leave request.',
                                'info'
                            );
                        }
                    }
                    
                    setFlashMessage('success', 'Leave request submitted successfully!');
                    header('Location: /leave.php');
                    exit;
                } else {
                    $error = 'Failed to submit leave request. Please try again.';
                }
            }
        }
    }
}

// Handle cancel action
if (isset($_GET['cancel']) && is_numeric($_GET['cancel'])) {
    $requestId = (int) $_GET['cancel'];
    
    // Verify ownership and status
    $request = executeQuery(
        "SELECT * FROM leave_requests WHERE id = ? AND user_id = ? AND status = 'pending'",
        [$requestId, $userId]
    );
    
    if ($request && count($request) > 0) {
        $result = executeUpdate(
            "UPDATE leave_requests SET status = 'cancelled' WHERE id = ?",
            [$requestId]
        );
        
        if ($result !== false) {
            setFlashMessage('success', 'Leave request cancelled successfully.');
        } else {
            setFlashMessage('danger', 'Failed to cancel leave request.');
        }
    } else {
        setFlashMessage('danger', 'Invalid leave request.');
    }
    header('Location: /leave.php');
    exit;
}

// Get leave types
$leaveTypes = getLeaveTypes();

// Get user's leave requests
$leaveRequests = executeQuery(
    "SELECT lr.*, lt.name as leave_type_name,
            CONCAT(u.first_name, ' ', u.last_name) as approved_by_name
     FROM leave_requests lr
     JOIN leave_types lt ON lr.leave_type_id = lt.id
     LEFT JOIN users u ON lr.approved_by = u.id
     WHERE lr.user_id = ?
     ORDER BY lr.created_at DESC",
    [$userId]
);

// Get leave balance
$leaveBalances = [];
foreach ($leaveTypes as $type) {
    $balance = getLeaveBalance($userId, $type['id']);
    $leaveBalances[$type['id']] = $balance;
}

$csrfToken = generateCSRFToken();
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Leave Requests</h1>
        <p class="text-muted mb-0">Manage your leave applications</p>
    </div>
    <?php if ($action !== 'new'): ?>
    <a href="?action=new" class="btn btn-primary">
        <i class="fas fa-plus me-2"></i>New Request
    </a>
    <?php endif; ?>
</div>

<?php if ($action === 'new'): ?>
<!-- New Leave Request Form -->
<div class="card dashboard-card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Submit Leave Request</h5>
        <a href="/leave.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-2"></i>Back
        </a>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo sanitize($error); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>" onsubmit="return validateLeaveForm()">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="leave_type_id" class="form-label">Leave Type <span class="text-danger">*</span></label>
                    <select class="form-select" id="leave_type_id" name="leave_type_id" required>
                        <option value="">Select Leave Type</option>
                        <?php foreach ($leaveTypes as $type): ?>
                        <option value="<?php echo $type['id']; ?>" 
                                data-balance="<?php echo $leaveBalances[$type['id']]['remaining_days']; ?>">
                            <?php echo sanitize($type['name']); ?> 
                            (<?php echo $leaveBalances[$type['id']]['remaining_days']; ?> days remaining)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="start_date" name="start_date" 
                           min="<?php echo date('Y-m-d'); ?>" required onchange="calculateLeaveDays()">
                </div>
                
                <div class="col-md-3 mb-3">
                    <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="end_date" name="end_date" 
                           min="<?php echo date('Y-m-d'); ?>" required onchange="calculateLeaveDays()">
                </div>
            </div>
            
            <div class="mb-3">
                <label for="days_count" class="form-label">Number of Days</label>
                <input type="number" class="form-control" id="days_count" readonly 
                       placeholder="Will be calculated automatically">
                <small class="text-muted">Weekends are excluded from the count</small>
            </div>
            
            <div class="mb-3">
                <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea class="form-control" id="reason" name="reason" rows="4" 
                          placeholder="Please provide a detailed reason for your leave request" required></textarea>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-paper-plane me-2"></i>Submit Request
                </button>
                <a href="/leave.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Leave Balance Cards -->
<div class="row mb-4">
    <?php foreach ($leaveTypes as $type): ?>
    <div class="col-md-4 col-sm-6 mb-3">
        <div class="card dashboard-card">
            <div class="card-body">
                <h6 class="text-muted"><?php echo sanitize($type['name']); ?></h6>
                <div class="d-flex justify-content-between align-items-end">
                    <div>
                        <span class="h3 mb-0"><?php echo $leaveBalances[$type['id']]['remaining_days']; ?></span>
                        <span class="text-muted">/ <?php echo $leaveBalances[$type['id']]['total_days']; ?> days</span>
                    </div>
                    <div class="text-end">
                        <small class="text-muted">Used: <?php echo $leaveBalances[$type['id']]['used_days']; ?></small>
                    </div>
                </div>
                <div class="progress progress-sm mt-2">
                    <?php 
                    $total = $leaveBalances[$type['id']]['total_days'];
                    $used = $leaveBalances[$type['id']]['used_days'];
                    $percentage = $total > 0 ? ($used / $total) * 100 : 0;
                    ?>
                    <div class="progress-bar bg-primary" style="width: <?php echo $percentage; ?>%"></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Leave Requests Table -->
<div class="card dashboard-card">
    <div class="card-header bg-white">
        <h5 class="mb-0">My Leave Requests</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Leave Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Applied On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($leaveRequests && count($leaveRequests) > 0): ?>
                        <?php foreach ($leaveRequests as $request): ?>
                        <tr>
                            <td><?php echo sanitize($request['leave_type_name']); ?></td>
                            <td><?php echo formatDate($request['start_date']); ?></td>
                            <td><?php echo formatDate($request['end_date']); ?></td>
                            <td><?php echo $request['days_count']; ?></td>
                            <td><?php echo formatStatusBadge($request['status']); ?></td>
                            <td><?php echo formatDate($request['created_at']); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" 
                                        data-bs-target="#viewModal<?php echo $request['id']; ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($request['status'] === 'pending'): ?>
                                <a href="?cancel=<?php echo $request['id']; ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Are you sure you want to cancel this request?')">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- View Modal -->
                        <div class="modal fade" id="viewModal<?php echo $request['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Leave Request Details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <table class="table table-borderless">
                                            <tr>
                                                <th>Leave Type:</th>
                                                <td><?php echo sanitize($request['leave_type_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Duration:</th>
                                                <td><?php echo formatDate($request['start_date']); ?> - <?php echo formatDate($request['end_date']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Days:</th>
                                                <td><?php echo $request['days_count']; ?> day(s)</td>
                                            </tr>
                                            <tr>
                                                <th>Reason:</th>
                                                <td><?php echo nl2br(sanitize($request['reason'])); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Status:</th>
                                                <td><?php echo formatStatusBadge($request['status']); ?></td>
                                            </tr>
                                            <?php if ($request['status'] === 'approved' && $request['approved_by_name']): ?>
                                            <tr>
                                                <th>Approved By:</th>
                                                <td><?php echo sanitize($request['approved_by_name']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ($request['status'] === 'rejected' && $request['rejection_reason']): ?>
                                            <tr>
                                                <th>Rejection Reason:</th>
                                                <td class="text-danger"><?php echo sanitize($request['rejection_reason']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="fas fa-plane-departure fa-3x mb-3 d-block opacity-50"></i>
                                No leave requests found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
