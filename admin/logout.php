<?php
require_once '../config/database.php';

// Destroy all session data
session_destroy();

// Redirect to admin login page
header("Location: " . BASE_URL . "/admin/login.php");
exit();