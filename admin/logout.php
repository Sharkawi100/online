<?php
require_once '../config/database.php';

// Destroy all session data
session_destroy();

// Redirect to admin login page using relative path
header("Location: login.php");
exit();