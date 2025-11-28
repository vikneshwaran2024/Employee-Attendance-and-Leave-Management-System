<?php
// Include session management
require_once "includes/session.php";

// Require user to be logged in
requireLogin();

// Include database configuration
require_once "config/database.php";

// Get user data
$user_id = $_SESSION["id"];

// Define variables for leave application form
$leave_type_id = $start_date = $end_date = $reason = "";
$leave_type_id_err = $start_date_err = $end_date_err = $reason_err = "";
$form_error = $form_success = "";
$is_half_day = false;

// Get available leave types
$leave_types = array();
$leave_types_sql = "SELECT id, name, description, default_days, color_code FROM leave_types ORDER BY name";
if($stmt = mysqli_prepare($conn, $leave_types_sql)){
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $leave_types[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}

// Get user's leave balances for current year
$leave_balances = array();
$balance_sql = "SELECT lt.id, lt.name, lb.allocated_days, lb.used_days 
                FROM leave_types lt
                LEFT JOIN leave_balances lb ON lt.id = lb.leave_type_id AND lb.user_id = ? AND lb.year = ?
                ORDER BY lt.name";
                
if($balance_stmt = mysqli_prepare($conn, $balance_sql)){
    $current_year = date('Y');
    mysqli_stmt_bind_param($balance_stmt, "ii", $user_id, $current_year);
    
    if(mysqli_stmt_execute($balance_stmt)){
        $result = mysqli_stmt_get_result($balance_stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            // If no balance record found, initialize with default values from leave_types
            if($row['allocated_days'] === NULL){
                // Find the leave type to get default days
                foreach($leave_types as $leave_type){
                    if($leave_type['id'] == $row['id']){
                        $row['allocated_days'] = $leave_type['default_days'];
                        break;
                    }
                }
                $row['used_days'] = 0;
            }
            
            $leave_balances[$row['id']] = $row;
        }
    }
    mysqli_stmt_close($balance_stmt);
}

// Get upcoming holidays for next 30 days
$holidays = array();
$today = date('Y-m-d');
$next_month = date('Y-m-d', strtotime('+30 days'));

$holiday_sql = "SELECT name, date, description FROM holidays WHERE date BETWEEN ? AND ? ORDER BY date";
if($holiday_stmt = mysqli_prepare($conn, $holiday_sql)){
    mysqli_stmt_bind_param($holiday_stmt, "ss", $today, $next_month);
    
    if(mysqli_stmt_execute($holiday_stmt)){
        $result = mysqli_stmt_get_result($holiday_stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $holidays[] = $row;
        }
    }
    mysqli_stmt_close($holiday_stmt);
}

// Process leave application form submission
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["apply_leave"])){
    // Validate leave type
    if(empty(trim($_POST["leave_type_id"]))){
        $leave_type_id_err = "Please select a leave type";
    } else{
        $leave_type_id = trim($_POST["leave_type_id"]);
    }
    
    // Validate start date
    if(empty(trim($_POST["start_date"]))){
        $start_date_err = "Please select a start date";
    } else{
        $start_date = trim($_POST["start_date"]);
        
        // Check if start date is in the past
        if(strtotime($start_date) < strtotime(date('Y-m-d'))){
            $start_date_err = "Start date cannot be in the past";
        }
        
        // Check if start date is a holiday
        foreach($holidays as $holiday){
            if($start_date == $holiday['date']){
                $start_date_err = "Start date is a holiday (" . $holiday['name'] . ")";
                break;
            }
        }
    }
    
    // Validate end date
    if(empty(trim($_POST["end_date"]))){
        $end_date_err = "Please select an end date";
    } else{
        $end_date = trim($_POST["end_date"]);
        
        // Check if end date is before or equal to start date
        if(strtotime($end_date) < strtotime($start_date)){
            $end_date_err = "End date must be after start date";
        }
        
        // Check if any day in the range is a holiday
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = new DateInterval('P1D');
        $daterange = new DatePeriod($start, $interval, $end);
        
        foreach($daterange as $date){
            $date_str = $date->format('Y-m-d');
            foreach($holidays as $holiday){
                if($date_str == $holiday['date']){
                    $end_date_err = "Date range includes a holiday (" . $holiday['name'] . " on " . date('M d, Y', strtotime($holiday['date'])) . ")";
                    break 2;
                }
            }
        }
    }
    
    // Check if half-day option is selected
    $is_half_day = isset($_POST["half_day"]) && $_POST["half_day"] == "1";
    
    // Validate reason
    if(empty(trim($_POST["reason"]))){
        $reason_err = "Please enter a reason for your leave";
    } elseif(strlen(trim($_POST["reason"])) < 5){
        $reason_err = "Please provide a detailed reason for your leave";
    } else{
        $reason = trim($_POST["reason"]);
    }
    
    // Check if user has sufficient leave balance
    if(empty($leave_type_id_err) && empty($start_date_err) && empty($end_date_err)){
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Include the end date in the count
        $interval = new DateInterval('P1D');
        $daterange = new DatePeriod($start, $interval, $end);
        
        $requested_days = 0;
        foreach($daterange as $date){
            if($date->format('N') < 6){ // Exclude weekends (6=Saturday, 7=Sunday)
                $requested_days++;
            }
        }
        
        // Adjust for half-day
        if($is_half_day && $requested_days > 0){
            $requested_days -= 0.5;
        }
        
        // Check if user has enough balance
        if(isset($leave_balances[$leave_type_id])){
            $available_days = $leave_balances[$leave_type_id]['allocated_days'] - $leave_balances[$leave_type_id]['used_days'];
            
            if($requested_days > $available_days){
                $form_error = "Insufficient leave balance. You requested " . $requested_days . " days but have only " . $available_days . " days available.";
            }
        } else {
            $form_error = "Selected leave type is not available for you.";
        }
    }
    
    // If there are no errors, insert the leave request
    if(empty($leave_type_id_err) && empty($start_date_err) && empty($end_date_err) && empty($reason_err) && empty($form_error)){
        // Insert leave request
        $insert_sql = "INSERT INTO leave_requests (user_id, leave_type_id, start_date, end_date, reason, half_day) 
                      VALUES (?, ?, ?, ?, ?, ?)";
        
        if($insert_stmt = mysqli_prepare($conn, $insert_sql)){
            mysqli_stmt_bind_param($insert_stmt, "iisssi", $user_id, $leave_type_id, $start_date, $end_date, $reason, $is_half_day);
            
            if(mysqli_stmt_execute($insert_stmt)){
                $form_success = "Your leave request has been submitted successfully and is pending approval.";
                
                // Clear form data
                $leave_type_id = $start_date = $end_date = $reason = "";
                $is_half_day = false;
            } else{
                $form_error = "Something went wrong. Please try again later.";
            }
            
            mysqli_stmt_close($insert_stmt);
        }
    }
}

// Get user's leave requests
$leave_requests = array();
$requests_sql = "SELECT lr.id, lt.name as leave_type, lr.start_date, lr.end_date, 
                      lr.reason, lr.half_day, lr.status, lr.created_at,
                      CONCAT(u.first_name, ' ', u.last_name) as approved_by,
                      lr.rejection_reason
               FROM leave_requests lr
               JOIN leave_types lt ON lr.leave_type_id = lt.id
               LEFT JOIN users u ON lr.approved_by = u.id
               WHERE lr.user_id = ?
               ORDER BY lr.created_at DESC";

if($requests_stmt = mysqli_prepare($conn, $requests_sql)){
    mysqli_stmt_bind_param($requests_stmt, "i", $user_id);
    
    if(mysqli_stmt_execute($requests_stmt)){
        $result = mysqli_stmt_get_result($requests_stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $leave_requests[] = $row;
        }
    }
    
    mysqli_stmt_close($requests_stmt);
}

// Include header
include_once "includes/header.php";
?>

<div class="row">
    <div class="col-md-12">
        <h1 class="mb-4">Leave Management</h1>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-4 col-md-5">
        <div class="card dashboard-card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Apply for Leave</h5>
            </div>
            <div class="card-body">
                <?php if(!empty($form_error)): ?>
                    <div class="alert alert-danger"><?php echo $form_error; ?></div>
                <?php endif; ?>
                <?php if(!empty($form_success)): ?>
                    <div class="alert alert-success"><?php echo $form_success; ?></div>
                <?php endif; ?>
                
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="mb-3">
                        <label for="leave_type_id" class="form-label">Leave Type</label>
                        <select class="form-select <?php echo (!empty($leave_type_id_err)) ? 'is-invalid' : ''; ?>" id="leave_type_id" name="leave_type_id">
                            <option value="">Select Leave Type</option>
                            <?php foreach($leave_types as $type): ?>
                                <?php 
                                    $available = 0;
                                    if(isset($leave_balances[$type['id']])){
                                        $available = $leave_balances[$type['id']]['allocated_days'] - $leave_balances[$type['id']]['used_days'];
                                    }
                                ?>
                                <option value="<?php echo $type['id']; ?>" 
                                        <?php echo ($leave_type_id == $type['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?> 
                                    (Available: <?php echo $available; ?> days)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="invalid-feedback"><?php echo $leave_type_id_err; ?></span>
                    </div>
                    
                    <div class="mb-3">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control <?php echo (!empty($start_date_err)) ? 'is-invalid' : ''; ?>" 
                               id="leave-start-date" name="start_date" value="<?php echo $start_date; ?>" min="<?php echo date('Y-m-d'); ?>">
                        <span class="invalid-feedback"><?php echo $start_date_err; ?></span>
                    </div>
                    
                    <div class="mb-3">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control <?php echo (!empty($end_date_err)) ? 'is-invalid' : ''; ?>" 
                               id="leave-end-date" name="end_date" value="<?php echo $end_date; ?>" min="<?php echo date('Y-m-d'); ?>">
                        <span class="invalid-feedback"><?php echo $end_date_err; ?></span>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="half_day" name="half_day" value="1" <?php echo $is_half_day ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="half_day">
                                Half Day (for single day leave only)
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <p>Number of days: <span id="days-count">0 day(s)</span></p>
                        <p id="date-error-msg" class="text-danger" style="display: none;"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reason" class="form-label">Reason for Leave</label>
                        <textarea class="form-control <?php echo (!empty($reason_err)) ? 'is-invalid' : ''; ?>" 
                                 id="reason" name="reason" rows="3"><?php echo $reason; ?></textarea>
                        <span class="invalid-feedback"><?php echo $reason_err; ?></span>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" id="leave-submit-btn" name="apply_leave" class="btn btn-primary">Apply for Leave</button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if(count($holidays) > 0): ?>
        <div class="card dashboard-card">
            <div class="card-header">
                <h5 class="mb-0">Upcoming Holidays</h5>
            </div>
            <div class="card-body">
                <ul class="list-group">
                    <?php foreach($holidays as $holiday): ?>
                        <li class="list-group-item">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($holiday['name']); ?></h6>
                                <small><?php echo date('D, M d', strtotime($holiday['date'])); ?></small>
                            </div>
                            <?php if(!empty($holiday['description'])): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($holiday['description']); ?></small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-8 col-md-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">My Leave Requests</h5>
                <div>
                    <input type="text" id="search-input" class="form-control form-control-sm" placeholder="Search requests...">
                </div>
            </div>
            <div class="card-body">
                <?php if(count($leave_requests) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped searchable-table">
                            <thead>
                                <tr>
                                    <th>Leave Type</th>
                                    <th>Duration</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                    <th>Applied On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($leave_requests as $request): ?>
                                    <tr>
                                        <td>
                                            <span class="leave-type-badge">
                                                <?php echo htmlspecialchars($request['leave_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M d', strtotime($request['start_date'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($request['end_date'])); ?>
                                            <?php if($request['half_day']): ?>
                                                <span class="badge bg-info">Half Day</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $start = new DateTime($request['start_date']);
                                                $end = new DateTime($request['end_date']);
                                                $end->modify('+1 day');
                                                $interval = $start->diff($end);
                                                $days = $interval->days;
                                                
                                                // Adjust for weekends
                                                $period = new DatePeriod($start, new DateInterval('P1D'), $end);
                                                $weekendDays = 0;
                                                
                                                foreach($period as $day) {
                                                    $dayOfWeek = $day->format('N');
                                                    if($dayOfWeek >= 6) { // 6 is Saturday, 7 is Sunday
                                                        $weekendDays++;
                                                    }
                                                }
                                                
                                                $workDays = $days - $weekendDays;
                                                
                                                // Adjust for half-day
                                                if($request['half_day'] && $workDays > 0) {
                                                    $workDays -= 0.5;
                                                }
                                                
                                                echo $workDays;
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge 
                                                <?php 
                                                    switch($request['status']){
                                                        case 'pending': echo 'leave-status-pending'; break;
                                                        case 'approved': echo 'leave-status-approved'; break;
                                                        case 'rejected': echo 'leave-status-rejected'; break;
                                                    }
                                                ?>">
                                                <?php echo ucfirst($request['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-info view-leave-btn" data-bs-toggle="modal" data-bs-target="#leaveDetailsModal" 
                                                data-id="<?php echo $request['id']; ?>"
                                                data-type="<?php echo htmlspecialchars($request['leave_type']); ?>"
                                                data-start="<?php echo date('M d, Y', strtotime($request['start_date'])); ?>"
                                                data-end="<?php echo date('M d, Y', strtotime($request['end_date'])); ?>"
                                                data-reason="<?php echo htmlspecialchars($request['reason']); ?>"
                                                data-status="<?php echo ucfirst($request['status']); ?>"
                                                data-approved-by="<?php echo htmlspecialchars($request['approved_by'] ?? 'N/A'); ?>"
                                                data-rejection-reason="<?php echo htmlspecialchars($request['rejection_reason'] ?? 'N/A'); ?>"
                                                data-half-day="<?php echo $request['half_day'] ? 'Yes' : 'No'; ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <?php if($request['status'] == 'pending'): ?>
                                            <a href="cancel_leave.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to cancel this leave request?');">
                                                <i class="fas fa-times"></i>
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No leave requests found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Leave Details Modal -->
<div class="modal fade" id="leaveDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Leave Request Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-borderless">
                    <tr>
                        <th width="35%">Leave Type:</th>
                        <td id="modalLeaveType"></td>
                    </tr>
                    <tr>
                        <th>Start Date:</th>
                        <td id="modalStartDate"></td>
                    </tr>
                    <tr>
                        <th>End Date:</th>
                        <td id="modalEndDate"></td>
                    </tr>
                    <tr>
                        <th>Half Day:</th>
                        <td id="modalHalfDay"></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td id="modalStatus"></td>
                    </tr>
                    <tr>
                        <th>Approved/Rejected By:</th>
                        <td id="modalApprovedBy"></td>
                    </tr>
                    <tr id="rejectionRow" style="display:none;">
                        <th>Rejection Reason:</th>
                        <td id="modalRejectionReason"></td>
                    </tr>
                    <tr>
                        <th>Reason for Leave:</th>
                        <td id="modalReason"></td>
                    </tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal detail view
    document.querySelectorAll('.view-leave-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const type = this.getAttribute('data-type');
            const start = this.getAttribute('data-start');
            const end = this.getAttribute('data-end');
            const reason = this.getAttribute('data-reason');
            const status = this.getAttribute('data-status');
            const approvedBy = this.getAttribute('data-approved-by');
            const rejectionReason = this.getAttribute('data-rejection-reason');
            const halfDay = this.getAttribute('data-half-day');
            
            document.getElementById('modalLeaveType').textContent = type;
            document.getElementById('modalStartDate').textContent = start;
            document.getElementById('modalEndDate').textContent = end;
            document.getElementById('modalHalfDay').textContent = halfDay;
            document.getElementById('modalStatus').textContent = status;
            document.getElementById('modalApprovedBy').textContent = approvedBy;
            document.getElementById('modalReason').textContent = reason;
            
            if (status === 'Rejected' && rejectionReason !== 'N/A') {
                document.getElementById('rejectionRow').style.display = '';
                document.getElementById('modalRejectionReason').textContent = rejectionReason;
            } else {
                document.getElementById('rejectionRow').style.display = 'none';
            }
        });
    });
});
</script>

<?php
// Include footer
include_once "includes/footer.php";
?>