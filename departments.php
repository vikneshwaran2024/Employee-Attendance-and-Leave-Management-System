<?php
/**
 * Department Management Page
 * Admin-only: Manage departments
 */
$pageTitle = 'Departments';
require_once __DIR__ . '/includes/header.php';
requireRole('admin');

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $managerId = !empty($_POST['manager_id']) ? (int) $_POST['manager_id'] : null;
        $editingId = (int) ($_POST['editing_id'] ?? 0);
        
        if (!$name) {
            $error = 'Department name is required.';
        } else {
            if ($editingId) {
                $result = executeUpdate(
                    "UPDATE departments SET name = ?, description = ?, manager_id = ? WHERE id = ?",
                    [$name, $description, $managerId, $editingId]
                );
                
                if ($result !== false) {
                    logActivity('Department Updated', 'Updated department: ' . $name);
                    setFlashMessage('success', 'Department updated successfully.');
                } else {
                    setFlashMessage('danger', 'Failed to update department.');
                }
            } else {
                $result = executeInsert(
                    "INSERT INTO departments (name, description, manager_id) VALUES (?, ?, ?)",
                    [$name, $description, $managerId]
                );
                
                if ($result !== false) {
                    logActivity('Department Created', 'Created department: ' . $name);
                    setFlashMessage('success', 'Department created successfully.');
                } else {
                    setFlashMessage('danger', 'Failed to create department.');
                }
            }
        }
        
        if (!$error) {
            header('Location: /departments.php');
            exit;
        }
    }
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];
    
    // Check if department has employees
    $empCount = executeQuery("SELECT COUNT(*) as count FROM users WHERE department_id = ?", [$deleteId]);
    
    if ($empCount && $empCount[0]['count'] > 0) {
        setFlashMessage('danger', 'Cannot delete department with assigned employees.');
    } else {
        $result = executeUpdate("DELETE FROM departments WHERE id = ?", [$deleteId]);
        if ($result !== false) {
            logActivity('Department Deleted', 'Deleted department ID: ' . $deleteId);
            setFlashMessage('success', 'Department deleted successfully.');
        } else {
            setFlashMessage('danger', 'Failed to delete department.');
        }
    }
    header('Location: /departments.php');
    exit;
}

// Get departments with employee count
$departments = executeQuery(
    "SELECT d.*, 
            CONCAT(u.first_name, ' ', u.last_name) as manager_name,
            (SELECT COUNT(*) FROM users WHERE department_id = d.id) as employee_count
     FROM departments d
     LEFT JOIN users u ON d.manager_id = u.id
     ORDER BY d.name"
);

// Get managers for dropdown
$managers = executeQuery(
    "SELECT id, first_name, last_name, employee_id 
     FROM users WHERE role IN ('manager', 'admin') AND status = 'active' ORDER BY first_name"
);

// Get department for editing
$editDept = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = executeQuery("SELECT * FROM departments WHERE id = ?", [(int) $_GET['edit']]);
    $editDept = $editData && count($editData) > 0 ? $editData[0] : null;
}

$csrfToken = generateCSRFToken();
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Departments</h1>
        <p class="text-muted mb-0">Manage company departments</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus me-2"></i>Add Department
    </button>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo sanitize($error); ?>
</div>
<?php endif; ?>

<!-- Departments Grid -->
<div class="row">
    <?php if ($departments && count($departments) > 0): ?>
        <?php foreach ($departments as $dept): ?>
        <div class="col-md-4 mb-4">
            <div class="card dashboard-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5 class="card-title mb-0"><?php echo sanitize($dept['name']); ?></h5>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-link" data-bs-toggle="dropdown">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="?edit=<?php echo $dept['id']; ?>">
                                        <i class="fas fa-edit me-2"></i>Edit
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item text-danger" href="?delete=<?php echo $dept['id']; ?>"
                                       onclick="return confirm('Are you sure you want to delete this department?')">
                                        <i class="fas fa-trash me-2"></i>Delete
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <p class="card-text text-muted small">
                        <?php echo $dept['description'] ? sanitize($dept['description']) : 'No description'; ?>
                    </p>
                    
                    <hr>
                    
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-users text-primary me-2"></i>
                            <span><?php echo $dept['employee_count']; ?> Employees</span>
                        </div>
                    </div>
                    
                    <div class="mt-2">
                        <small class="text-muted">
                            <i class="fas fa-user-tie me-1"></i>
                            Manager: <?php echo $dept['manager_name'] ? sanitize($dept['manager_name']) : 'Not Assigned'; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="text-center text-muted py-5">
                <i class="fas fa-building fa-3x mb-3 opacity-50"></i>
                <p>No departments found</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="editing_id" value="<?php echo $editDept['id'] ?? 0; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo $editDept ? 'Edit Department' : 'Add Department'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Department Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo sanitize($editDept['name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo sanitize($editDept['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="manager_id" class="form-label">Manager</label>
                        <select class="form-select" id="manager_id" name="manager_id">
                            <option value="">Select Manager</option>
                            <?php if ($managers): ?>
                                <?php foreach ($managers as $mgr): ?>
                                <option value="<?php echo $mgr['id']; ?>" 
                                        <?php echo ($editDept['manager_id'] ?? '') == $mgr['id'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize($mgr['first_name'] . ' ' . $mgr['last_name']); ?> 
                                    (<?php echo sanitize($mgr['employee_id']); ?>)
                                </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i><?php echo $editDept ? 'Update' : 'Create'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editDept): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('addModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
