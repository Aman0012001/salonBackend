<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'online',
    'debug_id' => 'DIRECT_FILE_TEST',
    'cwd' => getcwd(),
    'vendor_exists' => file_exists('../vendor'),
    'vendor_contents' => file_exists('../vendor') ? scandir('../vendor') : [],
    'stripe_exists' => file_exists('../vendor/stripe'),
    'autoload_exists' => file_exists('../vendor/autoload.php'),
    'php_info' => [
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
    ]
]);
