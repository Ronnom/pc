<?php
/**
 * Logout Page
 */

require_once 'includes/init.php';

logout();

setFlashMessage('success', 'You have been logged out successfully.');
redirect(getBaseUrl() . '/login.php');

