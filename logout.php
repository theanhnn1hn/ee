<?php
/**
 * Logout Script
 * Clean session logout
 */

require_once 'config.php';

// Log logout activity if user is logged in
if (isLoggedIn()) {
    logActivity('logout', ['method' => 'web']);
}

// Destroy session
session_destroy();

// Clear session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Redirect to login
header('Location: login.php');
exit;

?>
