<?php
/**
 * Leave Approval Page
 * For managers and admins to approve/reject leave requests
 */
$pageTitle = 'Leave Approvals';
require_once __DIR__ . '/includes/header.php';
requireRole(['manager', 'admin']);

$userId = getCurrentUserId();
$userRole = getCurrentUserRole();
$error = '';
$success = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        setFlashMessage('danger', 'Invalid security token. Please try again.');
    } else {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        
        if (!$requestId || !in_array($action, ['approve', 'reject'])) {
            setFlashMessage('danger', 'Invalid request.');
        } else {
            // Get the leave request
            $request = executeQuery(
                "SELECT lr.*, u.first_name, u.last_name, u.email, lt.name as leave_type_name
                 FROM leave_requests lr
                 JOIN users u ON lr.user_id = u.id
                 JOIN leave_types lt ON lr.leave_type_id = lt.id
                 WHERE lr.id = ? AND lr.status = 'pending'",
                [$requestId]
            );
            
            if (!$request || count($request) === 0) {
                setFlashMessage('danger', 'Leave request not found or already processed.');
            } else {
                $leaveRequest = $request[0];
                
                if ($action === 'approve') {
                    // Approve the request
                    $result = executeUpdate(
                        "UPDATE leave_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?",
                        [$userId, $requestId]
                    );
                    
                    if ($result !== false) {
                        // Update leave balance
                        $year = date('Y', strtotime($leaveRequest['start_date']));
                        $balance = executeQuery(
                            "SELECT * FROM leave_balance WHERE user_id = ? AND leave_type_id = ? AND year = ?",
                            [$leaveRequest['user_id'], $leaveRequest['leave_type_id'], $year]
                        );
                        
                        if ($balance && count($balance) > 0) {
                            executeUpdate(
                                "UPDATE leave_balance SET used_days = used_days + ?, remaining_days = remaining_days - ? WHERE id = ?",
                                [$leaveRequest['days_count'], $leaveRequest['days_count'], $balance[0]['id']]
                            );
                        } else {
                            // Get leave type default
                            $leaveType = executeQuery("SELECT days_allowed FROM leave_types WHERE id = ?", [$leaveRequest['leave_type_id']]);
                            $daysAllowed = $leaveType ? $leaveType[0]['days_allowed'] : 0;
                            
                            executeInsert(
                                "INSERT INTO leave_balance (user_id, leave_type_id, year, total_days, used_days, remaining_days) VALUES (?, ?, ?, ?, ?, ?)",
                                [$leaveRequest['user_id'], $leaveRequest['leave_type_id'], $year, $daysAllowed, $leaveRequest['days_count'], $daysAllowed - $leaveRequest['days_count']]
                            );
                        }
                        
                        // Mark attendance as on_leave for the leave period
                        $start = new DateTime($leaveRequest['start_date']);
                        $end = new DateTime($leaveRequest['end_date']);
                        $end->modify('+1 day');
                        $interval = new DateInterval('P1D');
                        $period = new DatePeriod($start, $interval, $end);
                        
                        foreach ($period as $date) {
                            $dateStr = $date->format('Y-m-d');
                            if (!isWeekend($dateStr) && !isHoliday($dateStr)) {
                                // Check if attendance record exists
                                $existing = executeQuery(
                                    "SELECT id FROM attendance WHERE user_id = ? AND date = ?",
                                    [$leaveRequest['user_id'], $dateStr]
                                );
                                
                                if ($existing && count($existing) > 0) {
                                    executeUpdate(
                                        "UPDATE attendance SET status = 'on_leave' WHERE user_id = ? AND date = ?",
                                        [$leaveRequest['user_id'], $dateStr]
                                    );
                                } else {
                                    executeInsert(
                                        "INSERT INTO attendance (user_id, date, status) VALUES (?, ?, 'on_leave')",
                                        [$leaveRequest['user_id'], $dateStr]
                                    );
                                }
                            }
                        }
                        
                        // Notify employee
                        createNotification(
                            $leaveRequest['user_id'],
                            'Leave Approved',
                            'Your ' . $leaveRequest['leave_type_name'] . ' request from ' . formatDate($leaveRequest['start_date']) . ' to ' . formatDate($leaveRequest['end_date']) . ' has been approved.',
                            'success'
                        );
                        
                        logActivity('Leave Approved', 'Approved leave request #' . $requestId);
                        setFlashMessage('success', 'Leave request approved successfully.');
                    } else {
                        setFlashMessage('danger', 'Failed to approve leave request.');
                    }
                } else {
                    // Reject the request
                    if (empty($rejectionReason)) {
                        setFlashMessage('danger', 'Please provide a reason for rejection.');
                    } else {
                        $result = executeUpdate(
                            "UPDATE leave_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?",
                            [$userId, $rejectionReason, $requestId]
                        );
                        
                        if ($result !== false) {
                            // Notify employee
                            createNotification(
                                $leaveRequest['user_id'],
                                'Leave Rejected',
                                'Your ' . $leaveRequest['leave_type_name'] . ' request from ' . formatDate($leaveRequest['start_date']) . ' to ' . formatDate($leaveRequest['end_date']) . ' has been rejected. Reason: ' . $rejectionReason,
                                'error'
                            );
                            
                            logActivity('Leave Rejected', 'Rejected leave request #' . $requestId);
                            setFlashMessage('success', 'Leave request rejected.');
                        } else {
                            setFlashMessage('danger', 'Failed to reject leave request.');
                        }
                    }
                }
            }
        }
    }
    header('Location: /leave-approval.php');
    exit;
}

// Get pending leave requests
if (hasRole('admin')) {
    $pendingRequests = executeQuery(
        "SELECT lr.*, u.first_name, u.last_name, u.employee_id, u.email, 
                lt.name as leave_type_name, d.name as department_name
         FROM leave_requests lr
         JOIN users u ON lr.user_id = u.id
         JOIN leave_types lt ON lr.leave_type_id = lt.id
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE lr.status = 'pending'
         ORDER BY lr.created_at ASC"
    );
} else {
    // Managers see only their department
    $pendingRequests = executeQuery(
        "SELECT lr.*, u.first_name, u.last_name, u.employee_id, u.email,
                lt.name as leave_type_name, d.name as department_name
         FROM leave_requests lr
         JOIN users u ON lr.user_id = u.id
         JOIN leave_types lt ON lr.leave_type_id = lt.id
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE lr.status = 'pending' AND u.department_id = ?
         ORDER BY lr.created_at ASC",
        [$_SESSION['department_id']]
    );
}

// Get recent processed requests
if (hasRole('admin')) {
    $processedRequests = executeQuery(
        "SELECT lr.*, u.first_name, u.last_name, u.employee_id,
                lt.name as leave_type_name,
                CONCAT(a.first_name, ' ', a.last_name) as approved_by_name
         FROM leave_requests lr
         JOIN users u ON lr.user_id = u.id
         JOIN leave_types lt ON lr.leave_type_id = lt.id
         LEFT JOIN users a ON lr.approved_by = a.id
         WHERE lr.status IN ('approved', 'rejected')
         ORDER BY lr.approved_at DESC
         LIMIT 20"
    );
} else {
    $processedRequests = executeQuery(
        "SELECT lr.*, u.first_name, u.last_name, u.employee_id,
                lt.name as leave_type_name,
                CONCAT(a.first_name, ' ', a.last_name) as approved_by_name
         FROM leave_requests lr
         JOIN users u ON lr.user_id = u.id
         JOIN leave_types lt ON lr.leave_type_id = lt.id
         LEFT JOIN users a ON lr.approved_by = a.id
         WHERE lr.status IN ('approved', 'rejected') AND u.department_id = ?
         ORDER BY lr.approved_at DESC
         LIMIT 20",
        [$_SESSION['department_id']]
    );
}

$csrfToken = generateCSRFToken();
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Leave Approvals</h1>
        <p class="text-muted mb-0">Review and manage leave requests</p>
    </div>
    <div>
        <span class="badge bg-warning fs-6">
            <?php echo count($pendingRequests ?? []); ?> Pending
        </span>
    </div>
</div>

<!-- Pending Requests -->
<div class="card dashboard-card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-clock text-warning me-2"></i>Pending Requests</h5>
    </div>
    <div class="card-body">
        <?php if ($pendingRequests && count($pendingRequests) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Leave Type</th>
                        <th>Duration</th>
                        <th>Days</th>
                        <th>Applied On</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRequests as $request): ?>
                    <tr>
                        <td>
                            <strong><?php echo sanitize($request['first_name'] . ' ' . $request['last_name']); ?></strong>
                            <br>
                            <small class="text-muted"><?php echo sanitize($request['employee_id']); ?></small>
                        </td>
                        <td><?php echo sanitize($request['department_name'] ?? 'N/A'); ?></td>
                        <td><?php echo sanitize($request['leave_type_name']); ?></td>
                        <td>
                            <?php echo formatDate($request['start_date']); ?>
                            <br>to <?php echo formatDate($request['end_date']); ?>
                        </td>
                        <td><span class="badge bg-primary"><?php echo $request['days_count']; ?></span></td>
                        <td><?php echo formatDate($request['created_at']); ?></td>
                        <td>
                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" 
                                    data-bs-target="#approveModal<?php echo $request['id']; ?>">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" 
                                    data-bs-target="#rejectModal<?php echo $request['id']; ?>">
                                <i class="fas fa-times"></i> Reject
                            </button>
                            <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" 
                                    data-bs-target="#viewModal<?php echo $request['id']; ?>">
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    
                    <!-- Approve Modal -->
                    <div class="modal fade" id="approveModal<?php echo $request['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    
                                    <div class="modal-header bg-success text-white">
                                        <h5 class="modal-title">Approve Leave Request</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Are you sure you want to approve this leave request?</p>
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Employee:</th>
                                                <td><?php echo sanitize($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                            </tr>
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
                                                <td><?php echo $request['days_count']; ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-check me-2"></i>Approve
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Reject Modal -->
                    <div class="modal fade" id="rejectModal<?php echo $request['id']; ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST" action="">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title">Reject Leave Request</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <table class="table table-sm mb-3">
                                            <tr>
                                                <th>Employee:</th>
                                                <td><?php echo sanitize($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Leave Type:</th>
                                                <td><?php echo sanitize($request['leave_type_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Duration:</th>
                                                <td><?php echo formatDate($request['start_date']); ?> - <?php echo formatDate($request['end_date']); ?></td>
                                            </tr>
                                        </table>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                                            <textarea name="rejection_reason" class="form-control" rows="3" 
                                                      placeholder="Please provide a reason for rejection" required></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-times me-2"></i>Reject
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
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
                                            <th>Employee:</th>
                                            <td><?php echo sanitize($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Employee ID:</th>
                                            <td><?php echo sanitize($request['employee_id']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Department:</th>
                                            <td><?php echo sanitize($request['department_name'] ?? 'N/A'); ?></td>
                                        </tr>
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
                                            <td><?php echo $request['days_count']; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Reason:</th>
                                            <td><?php echo nl2br(sanitize($request['reason'])); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Applied On:</th>
                                            <td><?php echo formatDate($request['created_at']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4">
            <i class="fas fa-check-circle fa-3x mb-3 text-success opacity-50"></i>
            <p>No pending leave requests</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Processed Requests -->
<div class="card dashboard-card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recently Processed</h5>
    </div>
    <div class="card-body">
        <?php if ($processedRequests && count($processedRequests) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Duration</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Processed By</th>
                        <th>Processed On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($processedRequests as $request): ?>
                    <tr>
                        <td>
                            <?php echo sanitize($request['first_name'] . ' ' . $request['last_name']); ?>
                            <br>
                            <small class="text-muted"><?php echo sanitize($request['employee_id']); ?></small>
                        </td>
                        <td><?php echo sanitize($request['leave_type_name']); ?></td>
                        <td>
                            <?php echo formatDate($request['start_date']); ?> - <?php echo formatDate($request['end_date']); ?>
                        </td>
                        <td><?php echo $request['days_count']; ?></td>
                        <td><?php echo formatStatusBadge($request['status']); ?></td>
                        <td><?php echo sanitize($request['approved_by_name'] ?? 'N/A'); ?></td>
                        <td><?php echo formatDate($request['approved_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4">
            <i class="fas fa-folder-open fa-3x mb-3 opacity-50"></i>
            <p>No processed requests</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
