<?php
/**
 * Holiday Management Page
 * Admin-only: Manage company holidays
 */
$pageTitle = 'Holidays';
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
        $date = $_POST['date'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
        $editingId = (int) ($_POST['editing_id'] ?? 0);
        
        if (!$name || !$date) {
            $error = 'Holiday name and date are required.';
        } else {
            if ($editingId) {
                $result = executeUpdate(
                    "UPDATE holidays SET name = ?, date = ?, description = ?, is_recurring = ? WHERE id = ?",
                    [$name, $date, $description, $isRecurring, $editingId]
                );
                
                if ($result !== false) {
                    logActivity('Holiday Updated', 'Updated holiday: ' . $name);
                    setFlashMessage('success', 'Holiday updated successfully.');
                } else {
                    setFlashMessage('danger', 'Failed to update holiday.');
                }
            } else {
                $result = executeInsert(
                    "INSERT INTO holidays (name, date, description, is_recurring) VALUES (?, ?, ?, ?)",
                    [$name, $date, $description, $isRecurring]
                );
                
                if ($result !== false) {
                    logActivity('Holiday Created', 'Created holiday: ' . $name);
                    setFlashMessage('success', 'Holiday added successfully.');
                } else {
                    setFlashMessage('danger', 'Failed to add holiday.');
                }
            }
        }
        
        if (!$error) {
            header('Location: /holidays.php');
            exit;
        }
    }
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];
    $result = executeUpdate("DELETE FROM holidays WHERE id = ?", [$deleteId]);
    
    if ($result !== false) {
        logActivity('Holiday Deleted', 'Deleted holiday ID: ' . $deleteId);
        setFlashMessage('success', 'Holiday deleted successfully.');
    } else {
        setFlashMessage('danger', 'Failed to delete holiday.');
    }
    header('Location: /holidays.php');
    exit;
}

// Get holidays
$year = $_GET['year'] ?? date('Y');
$holidays = executeQuery(
    "SELECT * FROM holidays WHERE YEAR(date) = ? OR is_recurring = 1 ORDER BY date",
    [$year]
);

// Get holiday for editing
$editHoliday = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $editData = executeQuery("SELECT * FROM holidays WHERE id = ?", [(int) $_GET['edit']]);
    $editHoliday = $editData && count($editData) > 0 ? $editData[0] : null;
}

$csrfToken = generateCSRFToken();
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Holidays</h1>
        <p class="text-muted mb-0">Manage company holidays and observances</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus me-2"></i>Add Holiday
    </button>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo sanitize($error); ?>
</div>
<?php endif; ?>

<!-- Year Navigation -->
<div class="card dashboard-card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
            <a href="?year=<?php echo $year - 1; ?>" class="btn btn-outline-primary">
                <i class="fas fa-chevron-left me-2"></i><?php echo $year - 1; ?>
            </a>
            <h4 class="mb-0"><?php echo $year; ?></h4>
            <a href="?year=<?php echo $year + 1; ?>" class="btn btn-outline-primary">
                <?php echo $year + 1; ?><i class="fas fa-chevron-right ms-2"></i>
            </a>
        </div>
    </div>
</div>

<!-- Holidays Table -->
<div class="card dashboard-card">
    <div class="card-header bg-white">
        <h5 class="mb-0">Holidays for <?php echo $year; ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Holiday Name</th>
                        <th>Description</th>
                        <th>Recurring</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($holidays && count($holidays) > 0): ?>
                        <?php foreach ($holidays as $holiday): ?>
                        <tr class="<?php echo strtotime($holiday['date']) < time() ? 'text-muted' : ''; ?>">
                            <td><?php echo formatDate($holiday['date']); ?></td>
                            <td><?php echo date('l', strtotime($holiday['date'])); ?></td>
                            <td>
                                <i class="fas fa-gift text-danger me-2"></i>
                                <strong><?php echo sanitize($holiday['name']); ?></strong>
                            </td>
                            <td><?php echo $holiday['description'] ? sanitize($holiday['description']) : '-'; ?></td>
                            <td>
                                <?php if ($holiday['is_recurring']): ?>
                                    <span class="badge bg-info">Yearly</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">One-time</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?edit=<?php echo $holiday['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?delete=<?php echo $holiday['id']; ?>" 
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Are you sure you want to delete this holiday?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-umbrella-beach fa-3x mb-3 opacity-50"></i>
                                <p>No holidays found for <?php echo $year; ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="editing_id" value="<?php echo $editHoliday['id'] ?? 0; ?>">
                
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo $editHoliday ? 'Edit Holiday' : 'Add Holiday'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Holiday Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo sanitize($editHoliday['name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="date" class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="date" name="date" 
                               value="<?php echo $editHoliday['date'] ?? ''; ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo sanitize($editHoliday['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="is_recurring" name="is_recurring" 
                               <?php echo ($editHoliday['is_recurring'] ?? false) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_recurring">
                            Recurring holiday (repeats every year)
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i><?php echo $editHoliday ? 'Update' : 'Add'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editHoliday): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = new bootstrap.Modal(document.getElementById('addModal'));
        modal.show();
    });
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
