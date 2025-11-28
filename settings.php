<?php
/**
 * Settings Page
 * Admin-only: Configure system settings
 */
$pageTitle = 'Settings';
require_once __DIR__ . '/includes/header.php';
requireRole('admin');

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $settings = [
            'company_name' => trim($_POST['company_name'] ?? ''),
            'work_start_time' => $_POST['work_start_time'] ?? '09:00:00',
            'work_end_time' => $_POST['work_end_time'] ?? '18:00:00',
            'standard_work_hours' => (int) ($_POST['standard_work_hours'] ?? 8),
            'overtime_threshold' => (int) ($_POST['overtime_threshold'] ?? 8),
            'late_threshold_minutes' => (int) ($_POST['late_threshold_minutes'] ?? 15),
            'timezone' => $_POST['timezone'] ?? 'UTC'
        ];
        
        $allSuccess = true;
        foreach ($settings as $key => $value) {
            if (!updateSetting($key, $value)) {
                $allSuccess = false;
            }
        }
        
        if ($allSuccess) {
            logActivity('Settings Updated', 'Updated system settings');
            setFlashMessage('success', 'Settings updated successfully.');
            header('Location: /settings.php');
            exit;
        } else {
            $error = 'Some settings could not be saved.';
        }
    }
}

// Get current settings
$currentSettings = [
    'company_name' => getSetting('company_name', 'Employee Attendance System'),
    'work_start_time' => getSetting('work_start_time', '09:00:00'),
    'work_end_time' => getSetting('work_end_time', '18:00:00'),
    'standard_work_hours' => getSetting('standard_work_hours', '8'),
    'overtime_threshold' => getSetting('overtime_threshold', '8'),
    'late_threshold_minutes' => getSetting('late_threshold_minutes', '15'),
    'timezone' => getSetting('timezone', 'UTC')
];

$csrfToken = generateCSRFToken();

// Common timezones
$timezones = [
    'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Asia/Tokyo', 'Asia/Shanghai',
    'Asia/Kolkata', 'Asia/Dubai', 'Australia/Sydney', 'Pacific/Auckland'
];
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Settings</h1>
        <p class="text-muted mb-0">Configure system settings</p>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo sanitize($error); ?>
</div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    
    <!-- General Settings -->
    <div class="card dashboard-card mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-cog me-2"></i>General Settings</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="company_name" class="form-label">Company Name</label>
                    <input type="text" class="form-control" id="company_name" name="company_name" 
                           value="<?php echo sanitize($currentSettings['company_name']); ?>">
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="timezone" class="form-label">Timezone</label>
                    <select class="form-select" id="timezone" name="timezone">
                        <?php foreach ($timezones as $tz): ?>
                        <option value="<?php echo $tz; ?>" <?php echo $currentSettings['timezone'] === $tz ? 'selected' : ''; ?>>
                            <?php echo $tz; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Work Hours Settings -->
    <div class="card dashboard-card mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Work Hours Settings</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="work_start_time" class="form-label">Work Start Time</label>
                    <input type="time" class="form-control" id="work_start_time" name="work_start_time" 
                           value="<?php echo $currentSettings['work_start_time']; ?>">
                    <small class="text-muted">Default work start time for all employees</small>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="work_end_time" class="form-label">Work End Time</label>
                    <input type="time" class="form-control" id="work_end_time" name="work_end_time" 
                           value="<?php echo $currentSettings['work_end_time']; ?>">
                    <small class="text-muted">Default work end time for all employees</small>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="standard_work_hours" class="form-label">Standard Work Hours/Day</label>
                    <input type="number" class="form-control" id="standard_work_hours" name="standard_work_hours" 
                           value="<?php echo $currentSettings['standard_work_hours']; ?>" min="1" max="24">
                    <small class="text-muted">Regular working hours per day</small>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label for="overtime_threshold" class="form-label">Overtime Threshold (hours)</label>
                    <input type="number" class="form-control" id="overtime_threshold" name="overtime_threshold" 
                           value="<?php echo $currentSettings['overtime_threshold']; ?>" min="1" max="24">
                    <small class="text-muted">Hours after which overtime is calculated</small>
                </div>
                
                <div class="col-md-4 mb-3">
                    <label for="late_threshold_minutes" class="form-label">Late Threshold (minutes)</label>
                    <input type="number" class="form-control" id="late_threshold_minutes" name="late_threshold_minutes" 
                           value="<?php echo $currentSettings['late_threshold_minutes']; ?>" min="0" max="120">
                    <small class="text-muted">Minutes after work start to mark as late</small>
                </div>
            </div>
        </div>
    </div>
    
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>Save Settings
        </button>
        <button type="reset" class="btn btn-outline-secondary">
            <i class="fas fa-undo me-2"></i>Reset
        </button>
    </div>
</form>

<!-- System Info -->
<div class="card dashboard-card mt-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>System Information</h5>
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <tr>
                <td class="text-muted" width="200">PHP Version</td>
                <td><?php echo phpversion(); ?></td>
            </tr>
            <tr>
                <td class="text-muted">Server Time</td>
                <td><?php echo date('Y-m-d H:i:s'); ?></td>
            </tr>
            <tr>
                <td class="text-muted">System Version</td>
                <td>1.0.0</td>
            </tr>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
