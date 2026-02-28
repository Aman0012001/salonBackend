<?php
/**
 * Migration: Add payment columns to platform_orders and bookings tables
 * Run this script once on Railway to add Stripe payment tracking columns.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

$db = Database::getInstance();

$migrations = [
    // Add payment columns to platform_orders
    "ALTER TABLE platform_orders ADD COLUMN IF NOT EXISTS payment_intent_id VARCHAR(255) NULL AFTER status",
    "ALTER TABLE platform_orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER payment_intent_id",

    // Add payment columns to bookings
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS payment_intent_id VARCHAR(255) NULL AFTER status",
    "ALTER TABLE bookings ADD COLUMN IF NOT EXISTS payment_status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER payment_intent_id",
];

$results = [];
foreach ($migrations as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'OK'];
    } catch (PDOException $e) {
        // Ignore "Duplicate column" errors - means it already exists
        if (
            strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), 'already exists') !== false
        ) {
            $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'ALREADY_EXISTS'];
        } else {
            $results[] = ['sql' => substr($sql, 0, 80), 'status' => 'ERROR: ' . $e->getMessage()];
        }
    }
}

header('Content-Type: application/json');
echo json_encode(['results' => $results], JSON_PRETTY_PRINT);
