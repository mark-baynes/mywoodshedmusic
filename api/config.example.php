<?php
// My Woodshed Music — Configuration
// Copy this to config.php and update with your hosting credentials

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');

// Secret key for JWT tokens — change this to something random and long
define('JWT_SECRET', 'change-this-to-a-random-string');

// CORS — update to your domain in production
define('ALLOWED_ORIGIN', 'https://yourdomain.com');

// Error reporting — turn off display_errors in production
ini_set('display_errors', 0);
error_reporting(E_ALL);
