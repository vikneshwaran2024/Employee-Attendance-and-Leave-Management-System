<?php
/**
 * Notifications Page
 * View and manage notifications
 */
$pageTitle = 'Notifications';
require_once __DIR__ . '/includes/header.php';
requireLogin();

$userId = getCurrentUserId();

// Mark as read
if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $notifId = (int) $_GET['read'];
    executeUpdate(
        "UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?",
        [$notifId, $userId]
    );
    header('Location: /notifications.php');
    exit;
}

// Mark all as read
if (isset($_GET['read_all'])) {
    executeUpdate(
        "UPDATE notifications SET is_read = TRUE WHERE user_id = ?",
        [$userId]
    );
    setFlashMessage('success', 'All notifications marked as read.');
    header('Location: /notifications.php');
    exit;
}

// Get notifications
$notifications = executeQuery(
    "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
    [$userId]
);

$unreadCount = 0;
if ($notifications) {
    foreach ($notifications as $notif) {
        if (!$notif['is_read']) {
            $unreadCount++;
        }
    }
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">Notifications</h1>
        <p class="text-muted mb-0"><?php echo $unreadCount; ?> unread notifications</p>
    </div>
    <?php if ($unreadCount > 0): ?>
    <a href="?read_all=1" class="btn btn-outline-primary">
        <i class="fas fa-check-double me-2"></i>Mark All as Read
    </a>
    <?php endif; ?>
</div>

<!-- Notifications List -->
<div class="card dashboard-card">
    <div class="card-body p-0">
        <?php if ($notifications && count($notifications) > 0): ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($notifications as $notif): ?>
                <li class="list-group-item <?php echo !$notif['is_read'] ? 'bg-light' : ''; ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="d-flex">
                            <?php
                            $iconClass = 'info';
                            $iconName = 'info-circle';
                            switch ($notif['type']) {
                                case 'success': $iconClass = 'success'; $iconName = 'check-circle'; break;
                                case 'warning': $iconClass = 'warning'; $iconName = 'exclamation-triangle'; break;
                                case 'error': $iconClass = 'danger'; $iconName = 'times-circle'; break;
                            }
                            ?>
                            <div class="me-3">
                                <i class="fas fa-<?php echo $iconName; ?> fa-lg text-<?php echo $iconClass; ?>"></i>
                            </div>
                            <div>
                                <h6 class="mb-1 <?php echo !$notif['is_read'] ? 'fw-bold' : ''; ?>">
                                    <?php echo sanitize($notif['title']); ?>
                                </h6>
                                <p class="mb-1 text-muted"><?php echo sanitize($notif['message']); ?></p>
                                <small class="text-muted">
                                    <i class="fas fa-clock me-1"></i>
                                    <?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?>
                                </small>
                            </div>
                        </div>
                        <?php if (!$notif['is_read']): ?>
                        <a href="?read=<?php echo $notif['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Mark as read">
                            <i class="fas fa-check"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-bell-slash fa-3x mb-3 opacity-50"></i>
                <p>No notifications</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
