<?php
/**
 * Employee Management Page
 * Admin-only: Add, edit, and manage employees
 */
$pageTitle = 'Employees';
require_once __DIR__ . '/includes/header.php';
requireRole('admin');

$action = $_GET['action'] ?? 'list';
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$error = '';
$success = '';

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];
    
    // Can't delete yourself
    if ($deleteId === getCurrentUserId()) {
        setFlashMessage('danger', 'You cannot delete your own account.');
    } else {
        $result = executeUpdate("UPDATE users SET status = 'inactive' WHERE id = ?", [$deleteId]);
        if ($result !== false) {
            logActivity('Employee Deleted', 'Deactivated employee ID: ' . $deleteId);
            setFlashMessage('success', 'Employee deactivated successfully.');
        } else {
            setFlashMessage('danger', 'Failed to deactivate employee.');
        }
    }
    header('Location: /employees.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $employeeId = trim($_POST['employee_id'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'employee';
        $departmentId = !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null;
        $phone = trim($_POST['phone'] ?? '');
        $hireDate = $_POST['hire_date'] ?? '';
        $status = $_POST['status'] ?? 'active';
        $editingId = (int) ($_POST['editing_id'] ?? 0);
        
        // Validation
        if (!$employeeId || !$firstName || !$lastName || !$email) {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!$editingId && !$password) {
            $error = 'Password is required for new employees.';
        } elseif ($password && strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif (!in_array($role, ['employee', 'manager', 'admin'])) {
            $error = 'Invalid role selected.';
        } else {
            // Check for duplicate email/employee_id
            $checkSql = "SELECT id FROM users WHERE (email = ? OR employee_id = ?) AND id != ?";
            $existing = executeQuery($checkSql, [$email, $employeeId, $editingId]);
            
            if ($existing && count($existing) > 0) {
                $error = 'Email or Employee ID already exists.';
            } else {
                if ($editingId) {
                    // Update existing employee
                    if ($password) {
                        $hashedPassword = hashPassword($password);
                        $result = executeUpdate(
                            "UPDATE users SET employee_id = ?, first_name = ?, last_name = ?, email = ?, 
                             password = ?, role = ?, department_id = ?, phone = ?, hire_date = ?, status = ?
                             WHERE id = ?",
                            [$employeeId, $firstName, $lastName, $email, $hashedPassword, $role, 
                             $departmentId, $phone, $hireDate ?: null, $status, $editingId]
                        );
                    } else {
                        $result = executeUpdate(
                            "UPDATE users SET employee_id = ?, first_name = ?, last_name = ?, email = ?, 
                             role = ?, department_id = ?, phone = ?, hire_date = ?, status = ?
                             WHERE id = ?",
                            [$employeeId, $firstName, $lastName, $email, $role, 
                             $departmentId, $phone, $hireDate ?: null, $status, $editingId]
                        );
                    }
                    
                    if ($result !== false) {
                        logActivity('Employee Updated', 'Updated employee: ' . $email);
                        setFlashMessage('success', 'Employee updated successfully.');
                        header('Location: /employees.php');
                        exit;
                    } else {
                        $error = 'Failed to update employee.';
                    }
                } else {
                    // Create new employee
                    $hashedPassword = hashPassword($password);
                    $result = executeInsert(
                        "INSERT INTO users (employee_id, first_name, last_name, email, password, role, department_id, phone, hire_date, status) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$employeeId, $firstName, $lastName, $email, $hashedPassword, $role, 
                         $departmentId, $phone, $hireDate ?: null, $status]
                    );
                    
                    if ($result !== false) {
                        // Initialize leave balance for new employee
                        $leaveTypes = getLeaveTypes();
                        $year = date('Y');
                        foreach ($leaveTypes as $type) {
                            executeInsert(
                                "INSERT INTO leave_balance (user_id, leave_type_id, year, total_days, used_days, remaining_days) VALUES (?, ?, ?, ?, 0, ?)",
                                [$result, $type['id'], $year, $type['days_allowed'], $type['days_allowed']]
                            );
                        }
                        
                        logActivity('Employee Created', 'Created new employee: ' . $email);
                        setFlashMessage('success', 'Employee created successfully.');
                        header('Location: /employees.php');
                        exit;
                    } else {
                        $error = 'Failed to create employee.';
                    }
                }
            }
        }
    }
}

// Get employee data for editing
$editEmployee = null;
if ($editId) {
    $editData = executeQuery("SELECT * FROM users WHERE id = ?", [$editId]);
    $editEmployee = $editData && count($editData) > 0 ? $editData[0] : null;
    $action = 'edit';
}

// Get all employees
$employees = executeQuery(
    "SELECT u.*, d.name as department_name 
     FROM users u 
     LEFT JOIN departments d ON u.department_id = d.id 
     ORDER BY u.first_name, u.last_name"
);

// Get departments for dropdown
$departments = getDepartments();

$csrfToken = generateCSRFToken();
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Employees</h1>
        <p class="text-muted mb-0">Manage employee accounts</p>
    </div>
    <?php if ($action === 'list'): ?>
    <a href="?action=add" class="btn btn-primary">
        <i class="fas fa-user-plus me-2"></i>Add Employee
    </a>
    <?php endif; ?>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- Add/Edit Form -->
<div class="card dashboard-card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><?php echo $editEmployee ? 'Edit Employee' : 'Add New Employee'; ?></h5>
        <a href="/employees.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-2"></i>Back
        </a>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo sanitize($error); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="editing_id" value="<?php echo $editEmployee['id'] ?? 0; ?>">
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="employee_id" class="form-label">Employee ID <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="employee_id" name="employee_id" 
                           value="<?php echo sanitize($editEmployee['employee_id'] ?? ''); ?>" required>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="email" name="email" 
                           value="<?php echo sanitize($editEmployee['email'] ?? ''); ?>" required>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="first_name" name="first_name" 
                           value="<?php echo sanitize($editEmployee['first_name'] ?? ''); ?>" required>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="last_name" name="last_name" 
                           value="<?php echo sanitize($editEmployee['last_name'] ?? ''); ?>" required>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="password" class="form-label">
                        Password <?php echo $editEmployee ? '' : '<span class="text-danger">*</span>'; ?>
                    </label>
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="<?php echo $editEmployee ? 'Leave blank to keep current' : 'Enter password'; ?>"
                           <?php echo $editEmployee ? '' : 'required'; ?>>
                    <small class="text-muted">Minimum 6 characters</small>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                    <select class="form-select" id="role" name="role" required>
                        <option value="employee" <?php echo ($editEmployee['role'] ?? '') === 'employee' ? 'selected' : ''; ?>>Employee</option>
                        <option value="manager" <?php echo ($editEmployee['role'] ?? '') === 'manager' ? 'selected' : ''; ?>>Manager</option>
                        <option value="admin" <?php echo ($editEmployee['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="department_id" class="form-label">Department</label>
                    <select class="form-select" id="department_id" name="department_id">
                        <option value="">Select Department</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" 
                                <?php echo ($editEmployee['department_id'] ?? '') == $dept['id'] ? 'selected' : ''; ?>>
                            <?php echo sanitize($dept['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="tel" class="form-control" id="phone" name="phone" 
                           value="<?php echo sanitize($editEmployee['phone'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="hire_date" class="form-label">Hire Date</label>
                    <input type="date" class="form-control" id="hire_date" name="hire_date" 
                           value="<?php echo $editEmployee['hire_date'] ?? ''; ?>">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="active" <?php echo ($editEmployee['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($editEmployee['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i><?php echo $editEmployee ? 'Update' : 'Create'; ?> Employee
                </button>
                <a href="/employees.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Employees Table -->
<div class="card dashboard-card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">All Employees</h5>
        <div>
            <input type="text" class="form-control form-control-sm" id="searchInput" 
                   placeholder="Search employees..." onkeyup="filterTable('searchInput', 'employeesTable')">
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="employeesTable">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($employees && count($employees) > 0): ?>
                        <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td><?php echo sanitize($emp['employee_id']); ?></td>
                            <td>
                                <strong><?php echo sanitize($emp['first_name'] . ' ' . $emp['last_name']); ?></strong>
                            </td>
                            <td><?php echo sanitize($emp['email']); ?></td>
                            <td><?php echo sanitize($emp['department_name'] ?? 'N/A'); ?></td>
                            <td>
                                <?php 
                                $roleColors = ['admin' => 'danger', 'manager' => 'warning', 'employee' => 'primary'];
                                ?>
                                <span class="badge bg-<?php echo $roleColors[$emp['role']] ?? 'secondary'; ?>">
                                    <?php echo ucfirst($emp['role']); ?>
                                </span>
                            </td>
                            <td><?php echo formatStatusBadge($emp['status']); ?></td>
                            <td>
                                <a href="?edit=<?php echo $emp['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($emp['id'] !== getCurrentUserId()): ?>
                                <a href="?delete=<?php echo $emp['id']; ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Are you sure you want to deactivate this employee?')">
                                    <i class="fas fa-user-slash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No employees found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
