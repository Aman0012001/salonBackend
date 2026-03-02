<?php
/**
 * 🛠️ SIMPLE .ENV LOADER
 * Loads variables from .env file into environment
 */
if (file_exists(dirname(__DIR__) . '/.env')) {
    $lines = file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2)
            continue;
        $name = trim($parts[0]);
        $value = trim($parts[1]);
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'salon_booking');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// JWT Secret Key
define('JWT_SECRET', 'your-secret-key-change-this-in-production');
define('JWT_EXPIRY', 86400); // 24 hours

// CORS Settings - Added 127.0.0.1 and common ports for safety
define('ALLOWED_ORIGINS', [
    'http://localhost:8081',
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost:5175',
    'http://localhost:5176',
    'http://localhost:3000',
    'http://localhost:3001',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:5174',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:3001'
]);

// File Upload Settings (Disabled - Moving to Cloudinary)
// define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');
// define('MAX_FILE_SIZE', 5242880); // 5MB

// Timezone
date_default_timezone_set('Asia/Kolkata');

// Error Reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Important: stop HTML output breaking JSON
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__) . '/logs/php_error.log');
// Google Drive Configuration
define('GOOGLE_CLIENT_ID', '');
define('GOOGLE_CLIENT_SECRET', '');
define('GOOGLE_REFRESH_TOKEN', '');
define('GOOGLE_DRIVE_FOLDER_ID', ''); // Optional: ID of the folder to upload to

// Cloudinary Configuration
define('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME'));
define('CLOUDINARY_API_KEY', getenv('CLOUDINARY_API_KEY'));
define('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET'));

// ToyyibPay Configuration
define('TOYYIBPAY_SECRET_KEY', getenv('TOYYIBPAY_SECRET_KEY') ?: 'gy5xfe0i-89wc-riyw-ggg3-nxfksodv7fw3');
define('TOYYIBPAY_CATEGORY_CODE', getenv('TOYYIBPAY_CATEGORY_CODE') ?: 'p1cd10dd');
define('TOYYIBPAY_BASE_URL', getenv('TOYYIBPAY_BASE_URL') ?: 'https://dev.toyyibpay.com');
define('TOYYIBPAY_CALLBACK_URL', getenv('TOYYIBPAY_CALLBACK_URL') ?: 'https://complete-salon-saas-product-production.up.railway.app/api/toyyibpay/callback');
define('TOYYIBPAY_RETURN_URL', getenv('TOYYIBPAY_RETURN_URL') ?: 'https://complete-salon-saas-product-production.up.railway.app/payment-success');

// SMTP Configuration
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587); // Use 465 for SSL, 587 for TLS
define('SMTP_USER', getenv('SMTP_USER') ?: 'your-email@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: ''); // Use App Password
define('SMTP_FROM_EMAIL', getenv('SMTP_FROM') ?: 'noreply@salonsaas.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Salon Style Support');
