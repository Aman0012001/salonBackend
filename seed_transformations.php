<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

try {
    $db = Database::getInstance()->getConnection();

    // 1. Get a user
    $user = $db->query("SELECT id FROM users LIMIT 1")->fetch();
    if (!$user) {
        die("Error: No users found. Please create a user first.\n");
    }
    $userId = $user['id'];

    // 2. Get some services
    $services = $db->query("SELECT id, name, salon_id FROM services LIMIT 3")->fetchAll();
    if (empty($services)) {
        die("Error: No services found. Please create services first.\n");
    }

    echo "Seeding transformations for User ID: $userId\n";

    $transformations = [
        [
            'treatment_name' => 'Hair Care & Styling',
            'comment' => 'Visible reduction in cystic inflammation and texture refinement.',
            'before' => 'https://images.unsplash.com/photo-1562322140-8baeececf3df?q=80&w=2069&auto=format&fit=crop',
            'after' => 'https://images.unsplash.com/photo-1560066984-138dadb4c035?q=80&w=2074&auto=format&fit=crop'
        ],
        [
            'treatment_name' => 'Skin Rejuvenation',
            'comment' => 'Hydration Facial after 3-4 weeks showing great results.',
            'before' => 'https://images.unsplash.com/photo-1522337660859-02fbefca4702?q=80&w=2069&auto=format&fit=crop',
            'after' => 'https://images.unsplash.com/photo-1516975080664-ed2fc6a32937?q=80&w=2070&auto=format&fit=crop'
        ]
    ];

    foreach ($transformations as $index => $t) {
        $service = $services[$index % count($services)];
        $bookingId = 'fake-booking-' . bin2hex(random_bytes(4));

        // Create a fake booking for the record to link to
        $stmt = $db->prepare("
            INSERT INTO bookings (id, user_id, salon_id, service_id, booking_date, booking_time, status)
            VALUES (?, ?, ?, ?, CURDATE(), '10:00:00', 'completed')
        ");
        $stmt->execute([$bookingId, $userId, $service['salon_id'], $service['id']]);

        // Create the treatment record
        $stmt = $db->prepare("
            INSERT INTO treatment_records (
                id, booking_id, user_id, salon_id, treatment_details, 
                before_photo_url, after_photo_url
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            Auth::generateUuid(),
            $bookingId,
            $userId,
            $service['salon_id'],
            $t['comment'],
            $t['before'],
            $t['after']
        ]);

        echo "✓ Seeded transformation: " . $t['treatment_name'] . "\n";
    }

    echo "\n✓ Seeding completed successfully!\n";

}
catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
