<?php
// Include session management
require_once "includes/session.php";

// Require admin role
requireAdmin();

// Include database configuration
require_once "config/database.php";

// Define variables and initialize with empty values
$name = $date = $description = "";
$name_err = $date_err = $description_err = "";
$form_success = $form_error = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){
    
    // Check if add or edit form was submitted
    if(isset($_POST["add_holiday"]) || isset($_POST["edit_holiday"])){
        
        // Get hidden input value if editing
        $holiday_id = isset($_POST["holiday_id"]) ? $_POST["holiday_id"] : 0;
        
        // Validate name
        if(empty(trim($_POST["name"]))){
            $name_err = "Please enter the holiday name";
        } else{
            $name = trim($_POST["name"]);
        }
        
        // Validate date
        if(empty(trim($_POST["date"]))){
            $date_err = "Please select the holiday date";
        } else{
            $date = trim($_POST["date"]);
            
            // Check if date already exists (except for current holiday when editing)
            $check_sql = "SELECT id FROM holidays WHERE date = ? AND id != ?";
            if($check_stmt = mysqli_prepare($conn, $check_sql)){
                mysqli_stmt_bind_param($check_stmt, "si", $date, $holiday_id);
                
                if(mysqli_stmt_execute($check_stmt)){
                    mysqli_stmt_store_result($check_stmt);
                    
                    if(mysqli_stmt_num_rows($check_stmt) > 0){
                        $date_err = "This date already has a holiday";
                    }
                }
                
                mysqli_stmt_close($check_stmt);
            }
        }
        
        // Validate description (optional)
        $description = trim($_POST["description"]);
        
        // Check input errors before inserting into database
        if(empty($name_err) && empty($date_err)){
            if($holiday_id > 0){
                // Update holiday record
                $sql = "UPDATE holidays SET name = ?, date = ?, description = ? WHERE id = ?";
                
                if($stmt = mysqli_prepare($conn, $sql)){
                    mysqli_stmt_bind_param($stmt, "sssi", $name, $date, $description, $holiday_id);
                    
                    if(mysqli_stmt_execute($stmt)){
                        $form_success = "Holiday updated successfully";
                    } else{
                        $form_error = "Something went wrong. Please try again later.";
                    }
                    
                    mysqli_stmt_close($stmt);
                }
            } else {
                // Insert new holiday record
                $sql = "INSERT INTO holidays (name, date, description) VALUES (?, ?, ?)";
                
                if($stmt = mysqli_prepare($conn, $sql)){
                    mysqli_stmt_bind_param($stmt, "sss", $name, $date, $description);
                    
                    if(mysqli_stmt_execute($stmt)){
                        $form_success = "Holiday added successfully";
                        $name = $date = $description = ""; // Clear form
                    } else{
                        $form_error = "Something went wrong. Please try again later.";
                    }
                    
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
    
    // Process delete holiday
    elseif(isset($_POST["delete_holiday"])){
        $holiday_id = $_POST["holiday_id"] ?? 0;
        
        if($holiday_id > 0){
            // Delete holiday
            $sql = "DELETE FROM holidays WHERE id = ?";
            
            if($stmt = mysqli_prepare($conn, $sql)){
                mysqli_stmt_bind_param($stmt, "i", $holiday_id);
                
                if(mysqli_stmt_execute($stmt)){
                    $form_success = "Holiday deleted successfully";
                } else{
                    $form_error = "Something went wrong. Please try again later.";
                }
                
                mysqli_stmt_close($stmt);
            }
        }
    }
}

// Get holiday for editing (via GET parameter)
if(isset($_GET["edit"]) && !empty(trim($_GET["edit"]))){
    $holiday_id = trim($_GET["edit"]);
    
    $sql = "SELECT id, name, date, description FROM holidays WHERE id = ?";
    
    if($stmt = mysqli_prepare($conn, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $holiday_id);
        
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            
            if(mysqli_num_rows($result) == 1){
                $row = mysqli_fetch_assoc($result);
                
                $holiday_id = $row["id"];
                $name = $row["name"];
                $date = $row["date"];
                $description = $row["description"];
            } else{
                // No valid ID parameter
                header("location: admin_holidays.php");
                exit;
            }
        } else{
            $form_error = "Oops! Something went wrong. Please try again later.";
        }
        
        mysqli_stmt_close($stmt);
    }
}

// Get all holidays ordered by date
$holidays = [];
$current_date = date('Y-m-d');
$current_year = date('Y');
$year_filter = isset($_GET["year"]) ? $_GET["year"] : $current_year;

$sql = "SELECT id, name, date, description FROM holidays";

if($year_filter != 'all'){
    $sql .= " WHERE YEAR(date) = ?";
}

$sql .= " ORDER BY date";

if($stmt = mysqli_prepare($conn, $sql)){
    if($year_filter != 'all'){
        mysqli_stmt_bind_param($stmt, "i", $year_filter);
    }
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        
        while($row = mysqli_fetch_assoc($result)){
            $holidays[] = $row;
        }
    }
    
    mysqli_stmt_close($stmt);
}

// Get unique years for filter
$years = [];
$years_sql = "SELECT DISTINCT YEAR(date) as year FROM holidays ORDER BY year DESC";

if($years_stmt = mysqli_prepare($conn, $years_sql)){
    if(mysqli_stmt_execute($years_stmt)){
        $years_result = mysqli_stmt_get_result($years_stmt);
        
        while($year_row = mysqli_fetch_assoc($years_result)){
            $years[] = $year_row['year'];
        }
    }
    
    mysqli_stmt_close($years_stmt);
}

// Add current year if not in the list
if(!in_array($current_year, $years)){
    $years[] = $current_year;
    sort($years);
}

// Include header
include_once "includes/header.php";
?>

<div class="row">
    <div class="col-md-12">
        <h1 class="mb-4">Holiday Management</h1>
        
        <?php if(!empty($form_success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $form_success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($form_error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $form_error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><?php echo isset($_GET["edit"]) ? "Edit Holiday" : "Add New Holiday"; ?></h5>
            </div>
            <div class="card-body">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <?php if(isset($_GET["edit"])): ?>
                        <input type="hidden" name="holiday_id" value="<?php echo $holiday_id; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Holiday Name</label>
                        <input type="text" name="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $name; ?>">
                        <span class="invalid-feedback"><?php echo $name_err; ?></span>
                    </div>
                    
                    <div class="mb-3">
                        <label for="date" class="form-label">Date</label>
                        <input type="date" name="date" class="form-control <?php echo (!empty($date_err)) ? 'is-invalid' : ''; ?>" value="<?php echo $date; ?>">
                        <span class="invalid-feedback"><?php echo $date_err; ?></span>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description (Optional)</label>
                        <textarea name="description" class="form-control" rows="3"><?php echo $description; ?></textarea>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <?php if(isset($_GET["edit"])): ?>
                            <a href="admin_holidays.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" name="edit_holiday" class="btn btn-primary">Update Holiday</button>
                        <?php else: ?>
                            <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" name="add_holiday" class="btn btn-primary">Add Holiday</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Holidays List</h5>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" id="yearFilterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php echo $year_filter == 'all' ? 'All Years' : $year_filter; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="yearFilterDropdown">
                        <li><a class="dropdown-item <?php echo $year_filter == 'all' ? 'active' : ''; ?>" href="?year=all">All Years</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php foreach($years as $year): ?>
                            <li><a class="dropdown-item <?php echo $year_filter == $year ? 'active' : ''; ?>" href="?year=<?php echo $year; ?>"><?php echo $year; ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <div class="card-body">
                <?php if(count($holidays) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($holidays as $holiday): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($holiday["name"]); ?></td>
                                        <td>
                                            <?php 
                                                echo date('M d, Y', strtotime($holiday["date"]));
                                                if($holiday["date"] == $current_date){
                                                    echo ' <span class="badge bg-primary">Today</span>';
                                                }
                                                if($holiday["date"] < $current_date){
                                                    echo ' <span class="badge bg-secondary">Past</span>';
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($holiday["description"]); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?edit=<?php echo $holiday["id"]; ?>" class="btn btn-outline-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger delete-btn" 
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal"
                                                        data-id="<?php echo $holiday["id"]; ?>"
                                                        data-name="<?php echo htmlspecialchars($holiday["name"]); ?>"
                                                        data-date="<?php echo date('M d, Y', strtotime($holiday["date"])); ?>">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center py-3">No holidays found for the selected year.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Holiday</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this holiday?</p>
                <p><strong>Name:</strong> <span id="delete-name"></span></p>
                <p><strong>Date:</strong> <span id="delete-date"></span></p>
            </div>
            <div class="modal-footer">
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <input type="hidden" name="holiday_id" id="delete-id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_holiday" class="btn btn-danger">Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Set up delete confirmation modal
    document.querySelectorAll('.delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const date = this.getAttribute('data-date');
            
            document.getElementById('delete-id').value = id;
            document.getElementById('delete-name').textContent = name;
            document.getElementById('delete-date').textContent = date;
        });
    });
});
</script>

<?php
// Include footer
include_once "includes/footer.php";
?>