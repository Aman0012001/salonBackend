<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

try {
    $db = Database::getInstance()->getConnection();

    echo "Attempting to query platform_orders with collation fix description...\n";

    // Try to force the join to work by casting to binary or common charset
    $stmt = $db->query("
        SELECT o.*, u.email as user_email, p.full_name as user_name 
        FROM platform_orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN profiles p ON o.user_id = p.user_id
        ORDER BY o.created_at DESC
    ");
    // If getting here, it worked (but it failed before this is for test perpose)
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Success! \n";

} catch (PDOException $e) {
    echo "SQL Error: " . $e->getMessage() . "\n";

    // Try workaround query
    echo "Retrying with modified query...\n";
    try {
        $stmt = $db->query("
            SELECT o.*, u.email as user_email, p.full_name as user_name 
            FROM platform_orders o
            LEFT JOIN users u ON BINARY o.user_id = BINARY u.id
            LEFT JOIN profiles p ON BINARY o.user_id = BINARY p.user_id
            ORDER BY o.created_at DESC
        ");
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Workaround Success! Found " . count($orders) . " orders.\n";
    } catch (PDOException $e2) {
        echo "Workaround Failed: " . $e2->getMessage() . "\n";
    }
}
