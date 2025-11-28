<?php
/**
 * Profile Page
 * View and update user profile
 */
$pageTitle = 'Profile';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$userId = getCurrentUserId();
$error = '';
$success = '';

// Get user data
$userData = executeQuery("SELECT * FROM users WHERE id = ?", [$userId]);
$user = $userData && count($userData) > 0 ? $userData[0] : null;

if (!$user) {
    header('Location: /logout.php');
    exit;
}

// Get department name
$deptData = executeQuery("SELECT name FROM departments WHERE id = ?", [$user['department_id']]);
$departmentName = $deptData && count($deptData) > 0 ? $deptData[0]['name'] : 'Not Assigned';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            
            $result = executeUpdate(
                "UPDATE users SET phone = ?, address = ? WHERE id = ?",
                [$phone, $address, $userId]
            );
            
            if ($result !== false) {
                logActivity('Profile Updated', 'Updated profile information');
                setFlashMessage('success', 'Profile updated successfully.');
                header('Location: /profile.php');
                exit;
            } else {
                $error = 'Failed to update profile.';
            }
        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (!$currentPassword || !$newPassword || !$confirmPassword) {
                $error = 'All password fields are required.';
            } elseif (!password_verify($currentPassword, $user['password'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($newPassword) < 6) {
                $error = 'New password must be at least 6 characters long.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'New passwords do not match.';
            } else {
                $hashedPassword = hashPassword($newPassword);
                $result = executeUpdate(
                    "UPDATE users SET password = ? WHERE id = ?",
                    [$hashedPassword, $userId]
                );
                
                if ($result !== false) {
                    logActivity('Password Changed', 'Changed account password');
                    setFlashMessage('success', 'Password changed successfully.');
                    header('Location: /profile.php');
                    exit;
                } else {
                    $error = 'Failed to change password.';
                }
            }
        }
    }
}

$csrfToken = generateCSRFToken();
?>

<!-- Page Header -->
<div class="mb-4">
    <h1 class="h3 mb-0">My Profile</h1>
    <p class="text-muted mb-0">View and update your profile information</p>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo sanitize($error); ?>
</div>
<?php endif; ?>

<div class="row">
    <!-- Profile Card -->
    <div class="col-lg-4 mb-4">
        <div class="card dashboard-card text-center">
            <div class="card-body">
                <img class="profile-img-lg mb-3" src="/assets/default-avatar.png" alt="Profile">
                <h4 class="mb-1"><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                <p class="text-muted mb-2"><?php echo sanitize($user['employee_id']); ?></p>
                <span class="badge bg-<?php echo $user['role'] === 'admin' ? 'danger' : ($user['role'] === 'manager' ? 'warning' : 'primary'); ?>">
                    <?php echo ucfirst($user['role']); ?>
                </span>
            </div>
            <div class="card-footer bg-white">
                <small class="text-muted">Member since <?php echo formatDate($user['created_at']); ?></small>
            </div>
        </div>
        
        <!-- Quick Info -->
        <div class="card dashboard-card mt-4">
            <div class="card-header bg-white">
                <h6 class="mb-0">Quick Information</h6>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Email</span>
                        <span><?php echo sanitize($user['email']); ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Department</span>
                        <span><?php echo sanitize($departmentName); ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Phone</span>
                        <span><?php echo $user['phone'] ? sanitize($user['phone']) : 'Not set'; ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Hire Date</span>
                        <span><?php echo $user['hire_date'] ? formatDate($user['hire_date']) : 'Not set'; ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0">
                        <span class="text-muted">Status</span>
                        <span><?php echo formatStatusBadge($user['status']); ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Profile Forms -->
    <div class="col-lg-8">
        <!-- Update Profile -->
        <div class="card dashboard-card mb-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i>Update Profile</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" value="<?php echo sanitize($user['first_name']); ?>" disabled>
                            <small class="text-muted">Contact admin to change</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" value="<?php echo sanitize($user['last_name']); ?>" disabled>
                            <small class="text-muted">Contact admin to change</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?php echo sanitize($user['email']); ?>" disabled>
                            <small class="text-muted">Contact admin to change</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   value="<?php echo sanitize($user['phone'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="3"><?php echo sanitize($user['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Profile
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="card dashboard-card">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                            <small class="text-muted">Minimum 6 characters</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-2"></i>Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
