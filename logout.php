<?php
/**
 * Logout Page
 * Destroys user session and redirects to login
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    logActivity('Logout', 'User logged out');
    destroySession();
}

header('Location: /login.php');
exit;
