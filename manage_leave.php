<?php
// Include session management
require_once "includes/session.php";

// Require user to be logged in
requireLogin();

// Include database configuration
require_once "config/database.php";

// Get user data
$user_id = $_SESSION["id"];
$user_role = $_SESSION["role"];

// Process leave application form submission
$success_message = '';
$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["apply_leave"])) {
    // Get form data
    $leave_type_id = mysqli_real_escape_string($conn, $_POST["leave_type"]);
    $start_date = mysqli_real_escape_string($conn, $_POST["start_date"]);
    $end_date = mysqli_real_escape_string($conn, $_POST["end_date"]);
    $reason = mysqli_real_escape_string($conn, $_POST["reason"]);
    $half_day = isset($_POST["half_day"]) ? 1 : 0;
    $contact_details = mysqli_real_escape_string($conn, $_POST["contact_details"]);
    $status = "pending";
    
    // Calculate working days between start and end date (excluding weekends)
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($start, $interval, $end->modify('+1 day'));
    
    $working_days = 0;
    foreach ($date_range as $date) {
        $day_of_week = $date->format('N'); // 1 (Monday) to 7 (Sunday)
        if ($day_of_week < 6) { // Skip weekends (6=Saturday, 7=Sunday)
            $working_days++;
        }
    }
    
    // Adjust for half-day if applicable
    if ($half_day && $start_date == $end_date) {
        $working_days = 0.5;
    }
    
    // Check if user has enough leave balance
    $balance_sql = "SELECT lt.name, lb.balance 
                   FROM leave_balances lb 
                   JOIN leave_types lt ON lb.leave_type_id = lt.id 
                   WHERE lb.user_id = ? AND lb.leave_type_id = ?";
    
    if ($balance_stmt = mysqli_prepare($conn, $balance_sql)) {
        mysqli_stmt_bind_param($balance_stmt, "ii", $user_id, $leave_type_id);
        
        if (mysqli_stmt_execute($balance_stmt)) {
            $result = mysqli_stmt_get_result($balance_stmt);
            
            if ($row = mysqli_fetch_assoc($result)) {
                $leave_type_name = $row['name'];
                $leave_balance = $row['balance'];
                
                if ($working_days > $leave_balance && $leave_type_name != 'Unpaid Leave') {
                    $error_message = "Insufficient leave balance. You have $leave_balance days of $leave_type_name remaining, but you're requesting $working_days days.";
                } else {
                    // Check for overlapping leave requests
                    $overlap_sql = "SELECT id FROM leave_requests 
                                  WHERE user_id = ? 
                                  AND status != 'rejected'
                                  AND (
                                      (start_date <= ? AND end_date >= ?) OR 
                                      (start_date <= ? AND end_date >= ?) OR
                                      (start_date >= ? AND end_date <= ?)
                                  )";
                    
                    if ($overlap_stmt = mysqli_prepare($conn, $overlap_sql)) {
                        mysqli_stmt_bind_param($overlap_stmt, "issssss", $user_id, $start_date, $start_date, $end_date, $end_date, $start_date, $end_date);
                        
                        if (mysqli_stmt_execute($overlap_stmt)) {
                            $overlap_result = mysqli_stmt_get_result($overlap_stmt);
                            
                            if (mysqli_num_rows($overlap_result) > 0) {
                                $error_message = "You already have a leave request for this date range. Please check your leave history.";
                            } else {
                                // Insert leave request
                                $insert_sql = "INSERT INTO leave_requests (user_id, leave_type_id, start_date, end_date, reason, half_day, contact_details, status, working_days, request_date) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                                
                                if ($insert_stmt = mysqli_prepare($conn, $insert_sql)) {
                                    mysqli_stmt_bind_param($insert_stmt, "iisssissd", $user_id, $leave_type_id, $start_date, $end_date, $reason, $half_day, $contact_details, $status, $working_days);
                                    
                                    if (mysqli_stmt_execute($insert_stmt)) {
                                        $success_message = "Leave request submitted successfully. It will be reviewed by your manager.";
                                        
                                        // Create notification for managers
                                        $notification_sql = "INSERT INTO notifications (user_id, message, type, related_id, is_read, created_at) 
                                                           SELECT id, CONCAT('New leave request from ', ?, ' for ', ?) as message, 'leave_request', LAST_INSERT_ID(), 0, NOW()
                                                           FROM users WHERE role = 'manager' OR role = 'admin'";
                                        
                                        if ($notification_stmt = mysqli_prepare($conn, $notification_sql)) {
                                            $full_name = $_SESSION["name"];
                                            $date_range = date('M d, Y', strtotime($start_date));
                                            if ($start_date != $end_date) {
                                                $date_range .= ' to ' . date('M d, Y', strtotime($end_date));
                                            }
                                            
                                            mysqli_stmt_bind_param($notification_stmt, "ss", $full_name, $date_range);
                                            mysqli_stmt_execute($notification_stmt);
                                            mysqli_stmt_close($notification_stmt);
                                        }
                                    } else {
                                        $error_message = "Failed to submit leave request: " . mysqli_error($conn);
                                    }
                                    
                                    mysqli_stmt_close($insert_stmt);
                                } else {
                                    $error_message = "Failed to prepare statement: " . mysqli_error($conn);
                                }
                            }
                        } else {
                            $error_message = "Failed to check for overlapping leaves: " . mysqli_error($conn);
                        }
                        
                        mysqli_stmt_close($overlap_stmt);
                    } else {
                        $error_message = "Failed to prepare statement: " . mysqli_error($conn);
                    }
                }
            } else {
                $error_message = "Leave type not found or you don't have a balance for this leave type.";
            }
        } else {
            $error_message = "Failed to check leave balance: " . mysqli_error($conn);
        }
        
        mysqli_stmt_close($balance_stmt);
    } else {
        $error_message = "Failed to prepare statement: " . mysqli_error($conn);
    }
}

// Get leave types and balances for the current user
$leave_types = [];
$leave_balances_sql = "SELECT lt.id, lt.name, IFNULL(lb.allocated_days - lb.used_days, 0) as balance 
                      FROM leave_types lt 
                      LEFT JOIN leave_balances lb ON lt.id = lb.leave_type_id AND lb.user_id = ?
                      ORDER BY lt.name";

if ($types_stmt = mysqli_prepare($conn, $leave_balances_sql)) {
    mysqli_stmt_bind_param($types_stmt, "i", $user_id);
    
    if (mysqli_stmt_execute($types_stmt)) {
        $result = mysqli_stmt_get_result($types_stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $leave_types[] = $row;
        }
    }
    
    mysqli_stmt_close($types_stmt);
}

// Get leave history for the current user
$leave_history = [];
$history_sql = "SELECT lr.id, lr.start_date, lr.end_date, lr.reason, lr.half_day, 
                lr.status, lr.created_at, 
                lt.name as leave_type
                FROM leave_requests lr
                JOIN leave_types lt ON lr.leave_type_id = lt.id
                WHERE lr.user_id = ?
                ORDER BY lr.created_at DESC";

if ($history_stmt = mysqli_prepare($conn, $history_sql)) {
    mysqli_stmt_bind_param($history_stmt, "i", $user_id);
    
    if (mysqli_stmt_execute($history_stmt)) {
        $result = mysqli_stmt_get_result($history_stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $leave_history[] = $row;
        }
    }
    
    mysqli_stmt_close($history_stmt);
}

// Include header
include_once "includes/header.php";
?>

<div class="row">
    <div class="col-md-12">
        <h1 class="mb-4">Leave Management</h1>
    </div>
</div>

<!-- Leave Stats -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Leave Balances</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($leave_types as $leave_type): ?>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="card leave-balance-card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title"><?php echo htmlspecialchars($leave_type['name']); ?></h5>
                                    <p class="card-text h2 mb-0"><?php echo isset($leave_type['balance']) ? $leave_type['balance'] : 'N/A'; ?></p>
                                    <p class="card-text text-muted">days remaining</p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leave Application Form -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Apply for Leave</h5>
            </div>
            <div class="card-body">
                <?php if ($success_message): ?>
                    <div class="alert alert-success" role="alert">
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="leave-type" class="form-label">Leave Type</label>
                            <select class="form-select" id="leave-type" name="leave_type" required>
                                <option value="">Select Leave Type</option>
                                <?php foreach ($leave_types as $leave_type): ?>
                                    <option value="<?php echo $leave_type['id']; ?>">
                                        <?php echo htmlspecialchars($leave_type['name']); ?> 
                                        <?php if (isset($leave_type['balance'])): ?>
                                            (<?php echo $leave_type['balance']; ?> days available)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="leave-start-date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="leave-start-date" name="start_date" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="leave-end-date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="leave-end-date" name="end_date" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="half_day" name="half_day">
                                <label class="form-check-label" for="half_day">
                                    Half Day Leave (only applicable for single day)
                                </label>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <p class="form-text text-muted mb-0">
                                Duration: <span id="days-count">0 day(s)</span>
                                <span id="date-error-msg" class="text-danger" style="display: none;"></span>
                            </p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="reason" class="form-label">Reason for Leave</label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" required></textarea>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="contact-details" class="form-label">Contact Details During Leave</label>
                            <textarea class="form-control" id="contact-details" name="contact_details" rows="3"></textarea>
                            <div class="form-text">Optional: Provide phone number or email where you can be reached during your leave if needed.</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <button type="submit" id="leave-submit-btn" name="apply_leave" class="btn btn-primary">Submit Leave Request</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Leave History -->
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Leave History</h5>
                <div>
                    <input type="text" id="search-input" class="form-control form-control-sm" placeholder="Search...">
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped searchable-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Applied On</th>
                                <th>Reviewed By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leave_history as $leave): ?>
                                <tr class="<?php echo $leave['status'] == 'rejected' ? 'table-danger' : ($leave['status'] == 'approved' ? 'table-success' : 'table-warning'); ?>">
                                    <td><?php echo htmlspecialchars($leave['leave_type']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($leave['start_date'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($leave['end_date'])); ?></td>
                                    <td>
                                        <?php 
                                        echo $leave['working_days']; 
                                        if ($leave['half_day']) {
                                            echo ' (Half Day)';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                        echo $leave['status'] == 'approved' ? 'success' : 
                                            ($leave['status'] == 'rejected' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($leave['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($leave['created_at'])); ?></td>
                                    <td>-</td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info view-leave-details" data-bs-toggle="modal" data-bs-target="#leaveDetailsModal" 
                                                data-id="<?php echo $leave['id']; ?>"
                                                data-type="<?php echo htmlspecialchars($leave['leave_type']); ?>"
                                                data-start="<?php echo date('M d, Y', strtotime($leave['start_date'])); ?>"
                                                data-end="<?php echo date('M d, Y', strtotime($leave['end_date'])); ?>"
                                                data-days="<?php echo $leave['working_days'] . ($leave['half_day'] ? ' (Half Day)' : ''); ?>"
                                                data-reason="<?php echo htmlspecialchars($leave['reason']); ?>"
                                                data-status="<?php echo ucfirst($leave['status']); ?>"
                                                data-applied="<?php echo date('M d, Y', strtotime($leave['created_at'])); ?>"
                                                data-reviewer="<?php echo $leave['reviewed_by'] ? htmlspecialchars($leave['reviewer_name']) : 'Not reviewed yet'; ?>"
                                                data-review-date="<?php echo $leave['review_date'] ? date('M d, Y', strtotime($leave['review_date'])) : 'N/A'; ?>"
                                                data-comments="<?php echo htmlspecialchars($leave['review_comments'] ?? 'No comments'); ?>"
                                                >
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        
                                        <?php if ($leave['status'] == 'pending'): ?>
                                            <a href="cancel_leave.php?id=<?php echo $leave['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to cancel this leave request?');">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($leave_history)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">No leave requests found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leave Details Modal -->
<div class="modal fade" id="leaveDetailsModal" tabindex="-1" aria-labelledby="leaveDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="leaveDetailsModalLabel">Leave Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <p><strong>Leave Type:</strong> <span id="modal-leave-type"></span></p>
                        <p><strong>Status:</strong> <span id="modal-status"></span></p>
                        <p><strong>Duration:</strong> <span id="modal-days"></span></p>
                        <p><strong>From:</strong> <span id="modal-start"></span></p>
                        <p><strong>To:</strong> <span id="modal-end"></span></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Applied On:</strong> <span id="modal-applied"></span></p>
                        <p><strong>Reviewed By:</strong> <span id="modal-reviewer"></span></p>
                        <p><strong>Reviewed On:</strong> <span id="modal-review-date"></span></p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">Reason for Leave</h6>
                            </div>
                            <div class="card-body">
                                <p id="modal-reason"></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Reviewer Comments</h6>
                            </div>
                            <div class="card-body">
                                <p id="modal-comments"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.leave-balance-card {
    border-left: 4px solid #0d6efd;
    transition: transform 0.3s;
}

.leave-balance-card:hover {
    transform: translateY(-5px);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle modal data
    document.querySelectorAll('.view-leave-details').forEach(function(button) {
        button.addEventListener('click', function() {
            document.getElementById('modal-leave-type').textContent = this.dataset.type;
            document.getElementById('modal-status').textContent = this.dataset.status;
            document.getElementById('modal-days').textContent = this.dataset.days;
            document.getElementById('modal-start').textContent = this.dataset.start;
            document.getElementById('modal-end').textContent = this.dataset.end;
            document.getElementById('modal-applied').textContent = this.dataset.applied;
            document.getElementById('modal-reviewer').textContent = this.dataset.reviewer;
            document.getElementById('modal-review-date').textContent = this.dataset.reviewDate;
            document.getElementById('modal-reason').textContent = this.dataset.reason;
            document.getElementById('modal-comments').textContent = this.dataset.comments;
            
            // Set status badge color
            const statusSpan = document.getElementById('modal-status');
            statusSpan.className = '';
            statusSpan.classList.add('badge');
            
            if (this.dataset.status === 'Approved') {
                statusSpan.classList.add('bg-success');
            } else if (this.dataset.status === 'Rejected') {
                statusSpan.classList.add('bg-danger');
            } else {
                statusSpan.classList.add('bg-warning');
            }
        });
    });
});
</script>

<?php
// Include footer
include_once "includes/footer.php";
?>

<?php
// Include header
include_once "includes/header.php";

// Check if user has manager or admin rights
requireManager();

// Define variables
$success = $error = "";

// Get leave request details if viewing a specific request
$leaveRequest = null;
if(isset($_GET['action']) && $_GET['action'] == 'view' && isset($_GET['id'])) {
    $leaveId = $_GET['id'];
    $sql = "SELECT lr.*, u.employee_id, CONCAT(u.first_name, ' ', u.last_name) AS employee_name, 
                u.email, u.department, u.position, lt.name as leave_type_name,
                (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = lr.approved_by) AS approver_name
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            JOIN leave_types lt ON lr.leave_type_id = lt.id
            WHERE lr.id = ?";
            
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $leaveId);
        if(mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)) {
                $leaveRequest = $row;
            } else {
                $error = "Leave request not found.";
            }
        }
        mysqli_stmt_close($stmt);
    }
    
    // Get employee leave balances
    if($leaveRequest) {
        $userId = $leaveRequest['user_id'];
        $leaveTypeId = $leaveRequest['leave_type_id'];
        $currentYear = date('Y');
        
        $sql = "SELECT allocated_days, used_days 
                FROM leave_balances 
                WHERE user_id = ? AND leave_type_id = ? AND year = ?";
                
        if($stmt = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "iii", $userId, $leaveTypeId, $currentYear);
            if(mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                if($row = mysqli_fetch_assoc($result)) {
                    $leaveRequest['allocated_days'] = $row['allocated_days'];
                    $leaveRequest['used_days'] = $row['used_days'];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// Process leave request approval/rejection
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['leave_action'])) {
    $leaveId = $_POST['leave_id'];
    $action = $_POST['leave_action'];
    $notes = $_POST['notes'] ?? "";
    
    // Get leave request details
    $sql = "SELECT lr.*, lt.name AS leave_type_name
            FROM leave_requests lr
            JOIN leave_types lt ON lr.leave_type_id = lt.id
            WHERE lr.id = ?";
            
    if($stmt = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $leaveId);
        if(mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if($row = mysqli_fetch_assoc($result)) {
                $leaveRequest = $row;
            } else {
                $error = "Leave request not found.";
            }
        }
        mysqli_stmt_close($stmt);
    }
    
    if($leaveRequest) {
        $userId = $leaveRequest['user_id'];
        $leaveTypeId = $leaveRequest['leave_type_id'];
        $startDate = $leaveRequest['start_date'];
        $endDate = $leaveRequest['end_date'];
        $halfDay = $leaveRequest['half_day'];
        
        // Calculate number of working days in leave period
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day'); // Include the end date
        $interval = new DateInterval('P1D');
        $dateRange = new DatePeriod($start, $interval, $end);
        
        $workDays = 0;
        foreach($dateRange as $date) {
            $dayOfWeek = $date->format('N');
            if($dayOfWeek <= 5) { // 1-5 = Monday-Friday
                $workDays++;
            }
        }
        
        // Apply half day adjustment
        if($halfDay) {
            $workDays -= 0.5;
        }
        
        if($action === 'approve') {
            // Update leave request status to approved
            $sql = "UPDATE leave_requests 
                    SET status = 'approved', approved_by = ?, updated_at = NOW() 
                    WHERE id = ?";
                    
            if($stmt = mysqli_prepare($conn, $sql)) {
                $approver = $_SESSION['id'];
                mysqli_stmt_bind_param($stmt, "ii", $approver, $leaveId);
                if(mysqli_stmt_execute($stmt)) {
                    // Update employee's leave balance
                    $currentYear = date('Y');
                    $sql = "UPDATE leave_balances 
                            SET used_days = used_days + ?, updated_at = NOW() 
                            WHERE user_id = ? AND leave_type_id = ? AND year = ?";
                            
                    if($stmt = mysqli_prepare($conn, $sql)) {
                        mysqli_stmt_bind_param($stmt, "diis", $workDays, $userId, $leaveTypeId, $currentYear);
                        if(mysqli_stmt_execute($stmt)) {
                            $success = "Leave request approved successfully.";
                            
                            // Create notification for the employee
                            $sql = "INSERT INTO notifications (user_id, message, type, related_id) 
                                    VALUES (?, ?, ?, ?)";
                                    
                            if($stmt = mysqli_prepare($conn, $sql)) {
                                $message = "Your leave request ({$leaveRequest['leave_type_name']}) from " . date('M d', strtotime($startDate)) . " to " . date('M d', strtotime($endDate)) . " has been approved.";
                                $type = "leave_approved";
                                
                                mysqli_stmt_bind_param($stmt, "issi", $userId, $message, $type, $leaveId);
                                mysqli_stmt_execute($stmt);
                                mysqli_stmt_close($stmt);
                            }
                        } else {
                            $error = "Error updating leave balance.";
                        }
                    }
                } else {
                    $error = "Error approving leave request.";
                }
            }
        } 
        elseif($action === 'reject') {
            // Update leave request status to rejected
            $sql = "UPDATE leave_requests 
                    SET status = 'rejected', approved_by = ?, rejection_reason = ?, updated_at = NOW() 
                    WHERE id = ?";
                    
            if($stmt = mysqli_prepare($conn, $sql)) {
                $approver = $_SESSION['id'];
                mysqli_stmt_bind_param($stmt, "isi", $approver, $notes, $leaveId);
                if(mysqli_stmt_execute($stmt)) {
                    $success = "Leave request rejected.";
                    
                    // Create notification for the employee
                    $sql = "INSERT INTO notifications (user_id, message, type, related_id) 
                            VALUES (?, ?, ?, ?)";
                            
                    if($stmt = mysqli_prepare($conn, $sql)) {
                        $message = "Your leave request ({$leaveRequest['leave_type_name']}) from " . date('M d', strtotime($startDate)) . " to " . date('M d', strtotime($endDate)) . " has been rejected.";
                        $type = "leave_rejected";
                        
                        mysqli_stmt_bind_param($stmt, "issi", $userId, $message, $type, $leaveId);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                } else {
                    $error = "Error rejecting leave request.";
                }
            }
        }
        
        // If successful, redirect to the management page
        if(!empty($success)) {
            header("location: manage_leave.php?success=" . urlencode($success));
            exit;
        }
    }
}

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$department = isset($_GET['department']) ? $_GET['department'] : '';
$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Get leave requests
$leaveRequests = [];
$sql = "SELECT lr.*, u.employee_id, CONCAT(u.first_name, ' ', u.last_name) AS employee_name, 
           u.department, lt.name as leave_type_name
        FROM leave_requests lr
        JOIN users u ON lr.user_id = u.id
        JOIN leave_types lt ON lr.leave_type_id = lt.id
        WHERE 1=1";

// Apply filters
if(!empty($status) && $status != 'all') {
    $sql .= " AND lr.status = ?";
}
if(!empty($department)) {
    $sql .= " AND u.department = ?";
}
if(!empty($month) && !empty($year)) {
    $sql .= " AND (
                (YEAR(lr.start_date) = ? AND MONTH(lr.start_date) = ?) OR
                (YEAR(lr.end_date) = ? AND MONTH(lr.end_date) = ?) OR
                (lr.start_date <= LAST_DAY(?) AND lr.end_date >= ?)
            )";
}

$sql .= " ORDER BY lr.created_at DESC";

if($stmt = mysqli_prepare($conn, $sql)) {
    // Bind parameters based on filters
    $bindTypes = "";
    $bindParams = [];
    
    if(!empty($status) && $status != 'all') {
        $bindTypes .= "s";
        $bindParams[] = $status;
    }
    if(!empty($department)) {
        $bindTypes .= "s";
        $bindParams[] = $department;
    }
    if(!empty($month) && !empty($year)) {
        $dateStart = sprintf('%04d-%02d-01', $year, $month);
        $bindTypes .= "iiisss";
        $bindParams[] = $year;
        $bindParams[] = $month;
        $bindParams[] = $year;
        $bindParams[] = $month;
        $bindParams[] = $dateStart;
        $bindParams[] = $dateStart;
    }
    
    // Only bind parameters if there are any
    if(!empty($bindTypes)) {
        // Create reference array for bind_param
        $bindParamsRef = [];
        $bindParamsRef[] = &$bindTypes;
        for($i = 0; $i < count($bindParams); $i++) {
            $bindParamsRef[] = &$bindParams[$i];
        }
        
        // Call bind_param with dynamic arguments
        call_user_func_array([$stmt, 'bind_param'], $bindParamsRef);
    }
    
    if(mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)) {
            $leaveRequests[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get all departments for filter
$departments = [];
$sql = "SELECT DISTINCT department FROM users WHERE department != '' ORDER BY department";
$result = mysqli_query($conn, $sql);
if($result) {
    while($row = mysqli_fetch_assoc($result)) {
        $departments[] = $row['department'];
    }
}

// Success message from redirect
if(isset($_GET['success'])) {
    $success = $_GET['success'];
}
?>

<?php if($leaveRequest): // Viewing a specific leave request ?>
    <div class="container-fluid">
        <h1 class="mb-4">Review Leave Request</h1>
        
        <?php if(!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Leave Request Details</h5>
                        <span class="badge <?php 
                            switch($leaveRequest['status']) {
                                case 'pending': echo 'bg-warning text-dark'; break;
                                case 'approved': echo 'bg-success'; break;
                                case 'rejected': echo 'bg-danger'; break;
                            }
                        ?>">
                            <?php echo ucfirst($leaveRequest['status']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-sm-3"><strong>Leave Type:</strong></div>
                            <div class="col-sm-9">
                                <?php echo $leaveRequest['leave_type_name']; ?>
                                <?php if($leaveRequest['half_day']): ?>
                                    <span class="badge bg-info ms-2">Half Day</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-sm-3"><strong>Period:</strong></div>
                            <div class="col-sm-9">
                                <?php 
                                    echo date('l, M d, Y', strtotime($leaveRequest['start_date'])); 
                                    if($leaveRequest['start_date'] != $leaveRequest['end_date']) {
                                        echo ' to ' . date('l, M d, Y', strtotime($leaveRequest['end_date']));
                                    }
                                ?>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-sm-3"><strong>Duration:</strong></div>
                            <div class="col-sm-9">
                                <?php
                                    // Calculate working days
                                    $start = new DateTime($leaveRequest['start_date']);
                                    $end = new DateTime($leaveRequest['end_date']);
                                    $end->modify('+1 day'); // Include end date
                                    $interval = new DateInterval('P1D');
                                    $dateRange = new DatePeriod($start, $interval, $end);
                                    
                                    $workDays = 0;
                                    foreach($dateRange as $date) {
                                        $dayOfWeek = $date->format('N');
                                        if($dayOfWeek <= 5) { // 1-5 = Monday-Friday
                                            $workDays++;
                                        }
                                    }
                                    
                                    // Apply half day adjustment
                                    if($leaveRequest['half_day']) {
                                        $workDays -= 0.5;
                                    }
                                    
                                    echo $workDays . ' working day(s)';
                                ?>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-sm-3"><strong>Balance:</strong></div>
                            <div class="col-sm-9">
                                <?php
                                    if(isset($leaveRequest['allocated_days']) && isset($leaveRequest['used_days'])) {
                                        $balance = $leaveRequest['allocated_days'] - $leaveRequest['used_days'];
                                        echo $balance . ' days left out of ' . $leaveRequest['allocated_days'];
                                        
                                        // Show warning if insufficient balance
                                        if($balance < $workDays) {
                                            echo ' <span class="text-danger">(Insufficient balance)</span>';
                                        }
                                    } else {
                                        echo 'Not available';
                                    }
                                ?>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-sm-3"><strong>Reason:</strong></div>
                            <div class="col-sm-9">
                                <p class="p-2 bg-light rounded"><?php echo nl2br(htmlspecialchars($leaveRequest['reason'])); ?></p>
                            </div>
                        </div>
                        
                        <?php if($leaveRequest['status'] !== 'pending'): ?>
                            <div class="row mb-3">
                                <div class="col-sm-3"><strong><?php echo $leaveRequest['status'] === 'approved' ? 'Approved' : 'Rejected'; ?> By:</strong></div>
                                <div class="col-sm-9"><?php echo $leaveRequest['approver_name'] ?? 'N/A'; ?></div>
                            </div>
                            
                            <?php if($leaveRequest['status'] === 'rejected' && !empty($leaveRequest['rejection_reason'])): ?>
                                <div class="row mb-3">
                                    <div class="col-sm-3"><strong>Rejection Reason:</strong></div>
                                    <div class="col-sm-9">
                                        <p class="p-2 bg-light rounded"><?php echo nl2br(htmlspecialchars($leaveRequest['rejection_reason'])); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <div class="row mb-3">
                            <div class="col-sm-3"><strong>Applied On:</strong></div>
                            <div class="col-sm-9"><?php echo date('M d, Y h:i A', strtotime($leaveRequest['created_at'])); ?></div>
                        </div>
                        
                        <?php if($leaveRequest['status'] === 'pending'): ?>
                            <hr>
                            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                <input type="hidden" name="leave_id" value="<?php echo $leaveRequest['id']; ?>">
                                
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notes (visible to employee)</label>
                                    <textarea class="form-control" name="notes" id="notes" rows="3"></textarea>
                                </div>
                                
                                <div class="d-flex">
                                    <a href="manage_leave.php" class="btn btn-secondary me-2">Back to List</a>
                                    <button type="submit" name="leave_action" value="reject" class="btn btn-danger me-2">Reject</button>
                                    <button type="submit" name="leave_action" value="approve" class="btn btn-success ms-auto">Approve</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="text-center mt-4">
                                <a href="manage_leave.php" class="btn btn-primary">Back to List</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Employee Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-sm-5"><strong>Name:</strong></div>
                            <div class="col-sm-7"><?php echo $leaveRequest['employee_name']; ?></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-5"><strong>ID:</strong></div>
                            <div class="col-sm-7"><?php echo $leaveRequest['employee_id']; ?></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-5"><strong>Department:</strong></div>
                            <div class="col-sm-7"><?php echo $leaveRequest['department']; ?></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-5"><strong>Position:</strong></div>
                            <div class="col-sm-7"><?php echo $leaveRequest['position']; ?></div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-sm-5"><strong>Email:</strong></div>
                            <div class="col-sm-7"><?php echo $leaveRequest['email']; ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Leave History</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Get employee's recent leave history
                        $leaves = [];
                        $sql = "SELECT lr.id, lr.start_date, lr.end_date, lr.status, lr.half_day, lt.name as leave_type_name
                                FROM leave_requests lr
                                JOIN leave_types lt ON lr.leave_type_id = lt.id
                                WHERE lr.user_id = ? AND lr.id != ?
                                ORDER BY lr.start_date DESC
                                LIMIT 5";
                                
                        if($stmt = mysqli_prepare($conn, $sql)) {
                            mysqli_stmt_bind_param($stmt, "ii", $leaveRequest['user_id'], $leaveRequest['id']);
                            if(mysqli_stmt_execute($stmt)) {
                                $result = mysqli_stmt_get_result($stmt);
                                while($row = mysqli_fetch_assoc($result)) {
                                    $leaves[] = $row;
                                }
                            }
                            mysqli_stmt_close($stmt);
                        }
                        
                        if(!empty($leaves)):
                        ?>
                            <div class="list-group">
                                <?php foreach($leaves as $leave): ?>
                                    <a href="manage_leave.php?action=view&id=<?php echo $leave['id']; ?>" class="list-group-item list-group-item-action">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo $leave['leave_type_name']; ?>
                                                <?php if($leave['half_day']): ?>
                                                    <span class="badge bg-info">Half Day</span>
                                                <?php endif; ?>
                                            </h6>
                                            <span class="badge <?php 
                                                switch($leave['status']) {
                                                    case 'pending': echo 'bg-warning text-dark'; break;
                                                    case 'approved': echo 'bg-success'; break;
                                                    case 'rejected': echo 'bg-danger'; break;
                                                }
                                            ?>"><?php echo ucfirst($leave['status']); ?></span>
                                        </div>
                                        <small>
                                            <?php
                                                echo date('M d, Y', strtotime($leave['start_date']));
                                                if($leave['start_date'] != $leave['end_date']) {
                                                    echo ' to ' . date('M d, Y', strtotime($leave['end_date']));
                                                }
                                            ?>
                                        </small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center">No previous leave requests found.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: // List of leave requests ?>
    <div class="container-fluid">
        <h1 class="mb-4">Manage Leave Requests</h1>
        
        <?php if(!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Filters</h5>
            </div>
            <div class="card-body">
                <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="row g-3">
                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="department" class="form-label">Department</label>
                        <select class="form-select" id="department" name="department">
                            <option value="">All Departments</option>
                            <?php foreach($departments as $dept): ?>
                                <option value="<?php echo $dept; ?>" <?php echo $department === $dept ? 'selected' : ''; ?>>
                                    <?php echo $dept; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="month" class="form-label">Month</label>
                        <select class="form-select" id="month" name="month">
                            <?php for($i = 1; $i <= 12; $i++): ?>
                                <option value="<?php echo sprintf('%02d', $i); ?>" <?php echo $month == sprintf('%02d', $i) ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="year" class="form-label">Year</label>
                        <select class="form-select" id="year" name="year">
                            <?php 
                            $currentYear = date('Y');
                            for($i = $currentYear - 1; $i <= $currentYear + 1; $i++): 
                            ?>
                                <option value="<?php echo $i; ?>" <?php echo $year == $i ? 'selected' : ''; ?>>
                                    <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Leave Requests</h5>
                <span class="badge bg-light text-dark"><?php echo count($leaveRequests); ?> request(s) found</span>
            </div>
            <div class="card-body">
                <?php if(!empty($leaveRequests)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Leave Type</th>
                                    <th>Dates</th>
                                    <th>Days</th>
                                    <th>Applied On</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($leaveRequests as $leave): ?>
                                    <tr>
                                        <td><?php echo $leave['employee_name']; ?><br>
                                            <small class="text-muted"><?php echo $leave['employee_id']; ?></small>
                                        </td>
                                        <td><?php echo $leave['department']; ?></td>
                                        <td>
                                            <?php echo $leave['leave_type_name']; ?>
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
                                                echo '<br><small class="text-muted">' . date('Y', strtotime($leave['start_date'])) . '</small>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            // Calculate working days
                                            $start = new DateTime($leave['start_date']);
                                            $end = new DateTime($leave['end_date']);
                                            $end->modify('+1 day'); // Include end date
                                            $interval = new DateInterval('P1D');
                                            $dateRange = new DatePeriod($start, $interval, $end);
                                            
                                            $workDays = 0;
                                            foreach($dateRange as $date) {
                                                $dayOfWeek = $date->format('N');
                                                if($dayOfWeek <= 5) { // 1-5 = Monday-Friday
                                                    $workDays++;
                                                }
                                            }
                                            
                                            // Apply half day adjustment
                                            if($leave['half_day']) {
                                                $workDays -= 0.5;
                                            }
                                            
                                            echo $workDays;
                                            if ($leave['half_day']) {
                                                echo ' (Half Day)';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($leave['created_at'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                        echo $leave['status'] == 'approved' ? 'success' : 
                                            ($leave['status'] == 'rejected' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($leave['status']); ?>
                                        </span>
                                        </td>
                                        <td>
                                            <a href="manage_leave.php?action=view&id=<?php echo $leave['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">No leave requests found matching the selected filters.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
// Include footer
include_once "includes/footer.php";
?>