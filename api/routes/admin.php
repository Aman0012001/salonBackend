<?php
// Admin routes

// Secure route protection
$userData = protectRoute(['admin']);

// GET /api/admin/stats - Get platform statistics
if ($method === 'GET' && $uriParts[1] === 'stats') {
    $cacheFile = __DIR__ . '/../../cache/admin_stats.json';
    $cacheTime = 300; // 5 minutes cache

    // Try to serve from cache first to avoid DB hang during wake-up
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        if ($cachedData) {
            error_log("[Admin Cache] Serving stats from cache");
            sendResponse($cachedData);
        }
    }

    // Use $db directly (the proxy will connect only if needed)
    $stats = [];

    // Total salons
    $stmt = $db->query("SELECT COUNT(*) as count FROM salons");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_salons'] = $row ? $row['count'] : 0;

    // Active salons
    $stmt = $db->query("SELECT COUNT(*) as count FROM salons WHERE is_active = 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['active_salons'] = $row ? $row['count'] : 0;

    // Pending salons
    $stmt = $db->query("SELECT COUNT(*) as count FROM salons WHERE approval_status = 'pending'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_salons'] = $row ? $row['count'] : 0;

    // Total bookings
    $stmt = $db->query("SELECT COUNT(*) as count FROM bookings");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_bookings'] = $row ? $row['count'] : 0;

    // Total users
    $stmt = $db->query("SELECT COUNT(*) as count FROM users");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_users'] = $row ? $row['count'] : 0;

    // Plan Sales (from platform_payments)
    $stmt = $db->query("SELECT SUM(amount) as total FROM platform_payments WHERE status = 'completed'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['plan_revenue'] = $row ? ($row['total'] ?? 0) : 0;

    // Service Sales (from bookings)
    $stmt = $db->query("
        SELECT SUM(COALESCE(s.price, 0)) as total 
        FROM bookings b 
        LEFT JOIN services s ON b.service_id = s.id 
        WHERE b.status = 'completed'
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['service_revenue'] = $row ? ($row['total'] ?? 0) : 0;

    // Product Sales (from customer_product_purchases)
    $stmt = $db->query("SELECT SUM(total_amount) as total FROM customer_product_purchases");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['product_revenue'] = $row ? ($row['total'] ?? 0) : 0;

    // Total Revenue (Combined)
    $stats['total_revenue'] = $stats['plan_revenue'] + $stats['service_revenue'] + $stats['product_revenue'];

    // Today's Bookings
    $stmt = $db->query("SELECT COUNT(*) as count FROM bookings WHERE DATE(created_at) = CURDATE()");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['today_bookings'] = $row ? $row['count'] : 0;

    // Weekly Bookings
    $stmt = $db->query("SELECT COUNT(*) as count FROM bookings WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['weekly_bookings'] = $row ? $row['count'] : 0;

    // Recent Activity (Combined)
    $stmt = $db->query("
        (SELECT 'user' as type, id, 'New user registered' as description, created_at FROM users)
        UNION ALL
        (SELECT 'booking' as type, id, 'New booking received' as description, created_at FROM bookings)
        UNION ALL
        (SELECT 'salon' as type, id, 'New salon registered' as description, created_at FROM salons)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stats['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Popular Treatments (Top 5 Services)
    $stmt = $db->query("
        SELECT s.name, COUNT(b.id) as value 
        FROM bookings b
        JOIN services s ON b.service_id = s.id
        GROUP BY s.id, s.name
        ORDER BY value DESC
        LIMIT 5
    ");
    $stats['popular_treatments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Customer Stats
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $newCust = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stmt = $db->query("SELECT COUNT(*) as count FROM users");
    $totalCust = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $stats['customer_stats'] = [
        'new' => $newCust,
        'existing' => $totalCust - $newCust,
        'total' => $totalCust
    ];

    $monthlyData = $db->query("
        SELECT name, SUM(value) as value FROM (
            SELECT DATE_FORMAT(created_at, '%b %Y') as name, amount as value, DATE_FORMAT(created_at, '%Y-%m') as sort_key
            FROM platform_payments 
            WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            UNION ALL
            SELECT DATE_FORMAT(b.created_at, '%b %Y') as name, COALESCE(s.price, 0) as value, DATE_FORMAT(b.created_at, '%Y-%m') as sort_key
            FROM bookings b
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.status = 'completed' AND b.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            UNION ALL
            SELECT DATE_FORMAT(purchase_date, '%b %Y') as name, total_amount as value, DATE_FORMAT(purchase_date, '%Y-%m') as sort_key
            FROM customer_product_purchases 
            WHERE purchase_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        ) combined_revenue
        GROUP BY name, sort_key
        ORDER BY sort_key ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // If no data, provide at least the current month
    if (empty($monthlyData)) {
        $monthlyData = [
            ['name' => date('M Y'), 'value' => 0]
        ];
    }

    $stats['revenue_history'] = $monthlyData;

    // Save to cache
    if (!is_dir(dirname($cacheFile))) {
        mkdir(dirname($cacheFile), 0777, true);
    }
    file_put_contents($cacheFile, json_encode($stats));

    sendResponse($stats);
}

// GET /api/admin/salons - Get all salons for admin
if ($method === 'GET' && $uriParts[1] === 'salons') {
    $status = $_GET['status'] ?? 'all';

    $query = "
        SELECT s.*, p.full_name as owner_name, u.email as owner_account_email,
               (SELECT COUNT(*) FROM bookings b WHERE b.salon_id = s.id) as booking_count
        FROM salons s
        LEFT JOIN user_roles ur ON s.id = ur.salon_id AND ur.role = 'owner'
        LEFT JOIN profiles p ON ur.user_id = p.user_id
        LEFT JOIN users u ON ur.user_id = u.id
    ";
    if ($status !== 'all') {
        $query .= " WHERE s.approval_status = ?";
    }
    $query .= " ORDER BY s.created_at DESC";

    $stmt = $db->prepare($query);
    if ($status !== 'all') {
        $stmt->execute([$status]);
    }
    else {
        $stmt->execute();
    }
    $salons = $stmt->fetchAll();

    sendResponse($salons);
    sendResponse($salons);
}

// POST /api/admin/salons - Create a new salon
if ($method === 'POST' && $uriParts[1] === 'salons') {
    $data = getRequestBody();

    if (!isset($data['name']) || !isset($data['slug'])) {
        sendResponse(['error' => 'Salon name and slug are required'], 400);
    }

    $db->beginTransaction();
    try {
        // 1. Check for duplicate slug
        $stmt = $db->prepare("SELECT COUNT(*) FROM salons WHERE slug = ?");
        $stmt->execute([$data['slug']]);
        if ($stmt->fetchColumn() > 0) {
            sendResponse(['error' => 'Salon slug already exists'], 409);
        }

        // 2. Create Salon
        $salonId = Auth::generateUuid();
        $stmt = $db->prepare("
            INSERT INTO salons (id, name, slug, description, address, city, state, phone, email, owner_password_plain, is_active, approval_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'approved', NOW())
        ");
        $stmt->execute([
            $salonId,
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $data['address'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['owner_password'] ?? null
        ]);

        // 3. Assign Owner (Optional)
        // 3. Assign Owner (Optional)
        if (isset($data['owner_email']) && !empty($data['owner_email'])) {
            $email = $data['owner_email'];
            $password = $data['owner_password'] ?? null;

            // Check if user exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $ownerId = $stmt->fetchColumn();

            if (!$ownerId && $password) {
                // Create New User
                $ownerId = Auth::generateUuid();
                $hashedPassword = Auth::hashPassword($password);

                $stmt = $db->prepare("INSERT INTO users (id, email, password_hash, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$ownerId, $email, $hashedPassword]);

                // Create Profile
                $stmt = $db->prepare("INSERT INTO profiles (user_id, full_name, user_type) VALUES (?, ?, 'salon_owner')");
                $stmt->execute([$ownerId, 'Salon Owner']);
            }

            if ($ownerId) {
                // Assign "owner" role for this new salon
                $stmt = $db->prepare("INSERT INTO user_roles (user_id, role, salon_id) VALUES (?, 'owner', ?)");
                $stmt->execute([$ownerId, $salonId]);

                // Ensure profile user_type is salon_owner
                $stmt = $db->prepare("UPDATE profiles SET user_type = 'salon_owner' WHERE user_id = ? AND user_type = 'customer'");
                $stmt->execute([$ownerId]);
            }
        }

        $db->commit();
        sendResponse(['message' => 'Salon created successfully', 'salon_id' => $salonId]);
    }
    catch (Exception $e) {
        $db->rollBack();
        sendResponse(['error' => 'Failed to create salon: ' . $e->getMessage()], 500);
    }
}

// PUT /api/admin/salons/:id/approve - Approve salon
if ($method === 'PUT' && $uriParts[1] === 'salons' && isset($uriParts[3]) && $uriParts[3] === 'approve') {
    $salonId = $uriParts[2];

    $stmt = $db->prepare("
        UPDATE salons SET
            approval_status = 'approved',
            approved_at = NOW(),
            approved_by = ?
        WHERE id = ?
    ");
    $stmt->execute([$userData['user_id'], $salonId]);

    // Notify Owner
    $ownerId = $invoiceService->getSalonOwnerId($salonId);
    if ($ownerId) {
        $notifService->notifyUser($ownerId, "Access Granted", "Your salon has been approved. You now have full access to the management console.", 'success', '/dashboard');
    }

    // Notify Subscribers
    $stmt = $db->prepare("SELECT name, city FROM salons WHERE id = ?");
    $stmt->execute([$salonId]);
    $salon = $stmt->fetch();
    if ($salon) {
        $newsletterService->notifySubscribers('salon', $salon['name'], $salon['city'] ?? '');
    }

    sendResponse(['message' => 'Salon approved successfully']);
}

// POST /api/admin/salons/:id/reset-password - Reset owner password
if ($method === 'POST' && $uriParts[1] === 'salons' && isset($uriParts[3]) && $uriParts[3] === 'reset-password') {
    $salonId = $uriParts[2];
    $data = getRequestBody();
    $newPassword = $data['password'] ?? null;

    if (!$newPassword) {
        sendResponse(['error' => 'Password is required'], 400);
    }

    $db->beginTransaction();
    try {
        // 1. Get Owner ID
        $stmt = $db->prepare("SELECT user_id FROM user_roles WHERE salon_id = ? AND role = 'owner' LIMIT 1");
        $stmt->execute([$salonId]);
        $ownerId = $stmt->fetchColumn();

        if (!$ownerId) {
            sendResponse(['error' => 'No owner found for this salon'], 404);
        }

        // 2. Hash and update user table
        $hashedPassword = Auth::hashPassword($newPassword);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $ownerId]);

        // 3. Update plain text in salons table for admin reference
        $stmt = $db->prepare("UPDATE salons SET owner_password_plain = ? WHERE id = ?");
        $stmt->execute([$newPassword, $salonId]);

        $db->commit();
        sendResponse(['message' => 'Password reset successfully']);
    }
    catch (Exception $e) {
        $db->rollBack();
        sendResponse(['error' => 'Reset failed: ' . $e->getMessage()], 500);
    }
}

// PUT /api/admin/salons/:id/reject - Reject salon
if ($method === 'PUT' && $uriParts[1] === 'salons' && isset($uriParts[3]) && $uriParts[3] === 'reject') {
    $salonId = $uriParts[2];
    $data = getRequestBody();

    $stmt = $db->prepare("
        UPDATE salons SET
            approval_status = 'rejected',
            rejection_reason = ?
        WHERE id = ?
    ");
    $stmt->execute([$data['reason'] ?? 'Not specified', $salonId]);

    sendResponse(['message' => 'Salon rejected']);
}

// DELETE /api/admin/salons/:id - Delete a salon
if ($method === 'DELETE' && $uriParts[1] === 'salons' && isset($uriParts[2])) {
    $salonId = $uriParts[2];

    $db->beginTransaction();
    try {
        // 0. Delete Cloudinary Images (Best effort before DB deletion)
        global $cloudinaryService;

        // Get Salon images
        $stmt = $db->prepare("SELECT logo_public_id, cover_image_public_id FROM salons WHERE id = ?");
        $stmt->execute([$salonId]);
        $salonFiles = $stmt->fetch();

        if ($salonFiles) {
            if (!empty($salonFiles['logo_public_id']))
                $cloudinaryService->deleteFile($salonFiles['logo_public_id']);
            if (!empty($salonFiles['cover_image_public_id']))
                $cloudinaryService->deleteFile($salonFiles['cover_image_public_id']);
        }

        // Get all service images
        $stmt = $db->prepare("SELECT image_public_id FROM services WHERE salon_id = ?");
        $stmt->execute([$salonId]);
        $serviceFiles = $stmt->fetchAll();
        foreach ($serviceFiles as $sf) {
            if (!empty($sf['image_public_id']))
                $cloudinaryService->deleteFile($sf['image_public_id']);
        }

        // 1. Delete services
        $stmt = $db->prepare("DELETE FROM services WHERE salon_id = ?");
        $stmt->execute([$salonId]);

        // 2. Delete inventory/products (correct table name: salon_inventory)
        $stmt = $db->prepare("DELETE FROM salon_inventory WHERE salon_id = ?");
        $stmt->execute([$salonId]);

        // 2b. Delete suppliers
        $stmt = $db->prepare("DELETE FROM salon_suppliers WHERE salon_id = ?");
        $stmt->execute([$salonId]);

        // 3. Delete user roles linked to this salon
        $stmt = $db->prepare("DELETE FROM user_roles WHERE salon_id = ?");
        $stmt->execute([$salonId]);

        // 4. Delete the salon
        $stmt = $db->prepare("DELETE FROM salons WHERE id = ?");
        $stmt->execute([$salonId]);

        $db->commit();
        sendResponse(['message' => 'Salon deleted successfully']);
    }
    catch (Exception $e) {
        $db->rollBack();
        sendResponse(['error' => 'Failed to delete salon: ' . $e->getMessage()], 500);
    }
}

// GET /api/admin/users - Get all users
if ($method === 'GET' && $uriParts[1] === 'users') {
    $stmt = $db->prepare("
        SELECT u.id, u.email, u.email_verified, u.coin_balance, u.created_at,
               p.full_name, p.phone, p.user_type
        FROM users u
        LEFT JOIN profiles p ON u.id = p.user_id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll();

    sendResponse($users);
}

// DELETE /api/admin/users/:id - Delete a user
if ($method === 'DELETE' && $uriParts[1] === 'users' && isset($uriParts[2])) {
    $userIdToDelete = $uriParts[2];

    // Prevent deleting self
    if ($userIdToDelete === $userData['user_id']) {
        sendResponse(['error' => 'Cannot delete your own admin account.'], 403);
    }

    $db->beginTransaction();
    try {
        // 1. Delete user roles
        $stmt = $db->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $stmt->execute([$userIdToDelete]);

        // 2. Delete profile
        $stmt = $db->prepare("DELETE FROM profiles WHERE user_id = ?");
        $stmt->execute([$userIdToDelete]);

        // 3. Delete user account
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userIdToDelete]);

        $db->commit();
        sendResponse(['message' => 'User deleted successfully']);
    }
    catch (Exception $e) {
        $db->rollBack();
        sendResponse(['error' => 'Failed to delete user: ' . $e->getMessage()], 500);
    }
}

// GET /api/admin/payments - Get all payments
if ($method === 'GET' && $uriParts[1] === 'payments') {
    $stmt = $db->prepare("
        SELECT p.*, s.name as salon_name
        FROM platform_payments p
        INNER JOIN salons s ON p.salon_id = s.id
        ORDER BY p.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $payments = $stmt->fetchAll();

    sendResponse($payments);
}

// POST /api/admin/payments/:id/resend-invoice - Trigger invoice delivery
if ($method === 'POST' && $uriParts[1] === 'payments' && isset($uriParts[3]) && $uriParts[3] === 'resend-invoice') {
    $paymentId = $uriParts[2];
    $success = $invoiceService->generateAndDeliver($paymentId);

    if ($success) {
        sendResponse(['message' => 'Invoice re-dispatched successfully through governance nodes.']);
    }
    else {
        sendResponse(['error' => 'Dispatch failed. Check system logs.'], 500);
    }
}

// GET /api/admin/reports - Get platform reports
if ($method === 'GET' && $uriParts[1] === 'reports') {
    $range = $_GET['range'] ?? '30';
    $range = intval($range);

    // 1. Core Totals
    $stmt = $db->prepare("SELECT IFNULL(SUM(amount), 0) as total FROM platform_payments WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$range]);
    $planRevenue = $stmt->fetch()['total'];

    $stmt = $db->prepare("
        SELECT 
            IFNULL(SUM(COALESCE(s.price, 0)), 0) as total,
            IFNULL(SUM(COALESCE(s.price, 0) - COALESCE(s.cost_price, 0)), 0) as profit
        FROM bookings b 
        LEFT JOIN services s ON b.service_id = s.id 
        WHERE b.status = 'completed' AND b.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$range]);
    $serviceStats = $stmt->fetch();
    $serviceRevenue = $serviceStats['total'];
    $serviceProfit = $serviceStats['profit'];

    $stmt = $db->prepare("
        SELECT 
            IFNULL(SUM(total_amount), 0) as total,
            IFNULL(SUM(total_amount - COALESCE(cost_price, 0)), 0) as profit
        FROM customer_product_purchases 
        WHERE purchase_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt->execute([$range]);
    $productStats = $stmt->fetch();
    $productRevenue = $productStats['total'];
    $productProfit = $productStats['profit'];

    $totalRevenue = $planRevenue + $serviceRevenue + $productRevenue;
    $totalProfit = $planRevenue + $serviceProfit + $productProfit; // Platform plans have 0 cost for now

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM bookings WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$range]);
    $totalBookings = $stmt->fetch()['total'];

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM bookings WHERE status = 'cancelled' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$range]);
    $cancelledBookings = $stmt->fetch()['total'];

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$range]);
    $newUsers = $stmt->fetch()['total'];

    $cancellationRate = ($totalBookings > 0) ? round(($cancelledBookings / $totalBookings) * 100, 1) : 0;

    // 2. Revenue & Profit History - Unified
    $stmt = $db->prepare("
        SELECT date, SUM(value) as value, SUM(profit) as profit FROM (
            SELECT DATE(created_at) as date, amount as value, amount as profit
            FROM platform_payments
            WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            UNION ALL
            SELECT DATE(b.created_at) as date, COALESCE(s.price, 0) as value, (COALESCE(s.price, 0) - COALESCE(s.cost_price, 0)) as profit
            FROM bookings b
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.status = 'completed' AND b.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            UNION ALL
            SELECT DATE(purchase_date) as date, total_amount as value, (total_amount - COALESCE(cost_price, 0)) as profit
            FROM customer_product_purchases
            WHERE purchase_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ) combined_stats
        GROUP BY date
        ORDER BY date ASC
    ");
    $stmt->execute([$range, $range, $range]);
    $revenueHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Top Salons (by activity)
    $stmt = $db->prepare("
        SELECT s.name, COUNT(b.id) as count
        FROM salons s
        JOIN bookings b ON s.id = b.salon_id
        WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY s.id, s.name
        ORDER BY count DESC
        LIMIT 5
    ");
    $stmt->execute([$range]);
    $topSalons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse([
        'reports' => [
            'total_revenue' => $totalRevenue,
            'total_profit' => $totalProfit,
            'plan_revenue' => $planRevenue,
            'service_revenue' => $serviceRevenue,
            'service_profit' => $serviceProfit,
            'product_revenue' => $productRevenue,
            'product_profit' => $productProfit,
            'total_bookings' => $totalBookings,
            'cancellation_rate' => $cancellationRate,
            'new_users' => $newUsers,
            'revenue_history' => $revenueHistory,
            'top_salons' => $topSalons
        ]
    ]);
}

// GET /api/admin/settings - Get platform settings
if ($method === 'GET' && $uriParts[1] === 'settings') {
    $stmt = $db->query("SELECT setting_key, setting_value FROM platform_settings");
    $settingsRaw = $stmt->fetchAll();

    $settings = [];
    foreach ($settingsRaw as $row) {
        $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
    }

    sendResponse($settings);
}

// PUT /api/admin/settings - Update platform settings
if ($method === 'PUT' && $uriParts[1] === 'settings') {
    $data = getRequestBody();

    $db->beginTransaction();
    try {
        foreach ($data as $key => $value) {
            $stmt = $db->prepare("
                INSERT INTO platform_settings (id, setting_key, setting_value, updated_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
            ");
            $stmt->execute([Auth::generateUuid(), $key, json_encode($value), $userData['user_id']]);
        }
        $db->commit();
        sendResponse(['message' => 'Settings updated successfully']);
    }
    catch (Exception $e) {
        $db->rollBack();
        sendResponse(['error' => 'Failed to update settings: ' . $e->getMessage()], 500);
    }
}

// GET /api/admin/contact-enquiries - Get all contact enquiries
if ($method === 'GET' && $uriParts[1] === 'contact-enquiries' && count($uriParts) === 2) {
    try {
        $stmt = $db->query("SELECT * FROM contact_enquiries ORDER BY created_at DESC");
        $enquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse(['enquiries' => $enquiries]);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to fetch contact enquiries: ' . $e->getMessage()], 500);
    }
}

// GET /api/admin/subscriptions/plans - Get all plans including inactive
if ($method === 'GET' && $uriParts[1] === 'subscriptions' && isset($uriParts[2]) && $uriParts[2] === 'plans') {
    try {
        $stmt = $db->query("
            SELECT id, name, slug, description, 
                   price_monthly as monthly_price, 
                   price_yearly as annual_price, 
                   features, is_active, sort_order, created_at 
            FROM subscription_plans 
            ORDER BY sort_order ASC, created_at DESC
        ");
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse(['plans' => $plans]);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to fetch plans: ' . $e->getMessage()], 500);
    }
}

// POST /api/admin/subscriptions/plans - Create new plan
if ($method === 'POST' && $uriParts[1] === 'subscriptions' && isset($uriParts[2]) && $uriParts[2] === 'plans') {
    $data = getRequestBody();
    $id = Auth::generateUuid();
    $slug = strtolower(str_replace(' ', '-', $data['name'])) . '-' . rand(1000, 9999);

    try {
        $stmt = $db->prepare("
            INSERT INTO subscription_plans (id, name, slug, description, price_monthly, price_yearly, features, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $id,
            $data['name'],
            $slug,
            $data['description'] ?? '',
            $data['monthly_price'] ?? 0,
            $data['annual_price'] ?? 0,
            $data['features'] ?? '[]',
            isset($data['is_active']) ? $data['is_active'] : 1
        ]);
        sendResponse(['message' => 'Plan created', 'id' => $id]);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to create plan: ' . $e->getMessage()], 500);
    }
}

// PUT /api/admin/subscriptions/plans/:id - Update plan
if ($method === 'PUT' && $uriParts[1] === 'subscriptions' && isset($uriParts[2]) && $uriParts[2] === 'plans' && isset($uriParts[3])) {
    $id = $uriParts[3];
    $data = getRequestBody();

    try {
        $stmt = $db->prepare("
            UPDATE subscription_plans SET 
                name = ?, 
                description = ?, 
                price_monthly = ?, 
                price_yearly = ?, 
                features = ?, 
                is_active = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['monthly_price'] ?? 0,
            $data['annual_price'] ?? 0,
            $data['features'] ?? '[]',
            isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
            $id
        ]);
        sendResponse(['message' => 'Plan updated']);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to update plan: ' . $e->getMessage()], 500);
    }
}

// GET /api/admin/subscriptions/addons - Get all add-ons including inactive
if ($method === 'GET' && $uriParts[1] === 'subscriptions' && isset($uriParts[2]) && $uriParts[2] === 'addons') {
    try {
        $stmt = $db->query("
            SELECT id, name, slug, description, price_monthly, icon, is_active, created_at 
            FROM subscription_addons 
            ORDER BY price_monthly ASC
        ");
        $addons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse(['addons' => $addons]);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to fetch add-ons: ' . $e->getMessage()], 500);
    }
}

// POST /api/admin/subscriptions/addons - Create new add-on
if ($method === 'POST' && $uriParts[1] === 'subscriptions' && isset($uriParts[2]) && $uriParts[2] === 'addons') {
    $data = getRequestBody();
    $id = Auth::generateUuid();
    $slug = strtolower(str_replace(' ', '-', $data['name'])) . '-' . rand(1000, 9999);

    try {
        $stmt = $db->prepare("
            INSERT INTO subscription_addons (id, name, slug, description, price_monthly, icon, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $id,
            $data['name'],
            $slug,
            $data['description'] ?? '',
            $data['price_monthly'] ?? 0,
            $data['icon'] ?? 'Puzzle',
            isset($data['is_active']) ? $data['is_active'] : 1
        ]);
        sendResponse(['message' => 'Add-on created', 'id' => $id]);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to create add-on: ' . $e->getMessage()], 500);
    }
}

// PUT /api/admin/subscriptions/addons/:id - Update add-on
if ($method === 'PUT' && $uriParts[1] === 'subscriptions' && isset($uriParts[2]) && $uriParts[2] === 'addons' && isset($uriParts[3])) {
    $id = $uriParts[3];
    $data = getRequestBody();

    try {
        $stmt = $db->prepare("
            UPDATE subscription_addons SET 
                name = ?, 
                description = ?, 
                price_monthly = ?, 
                icon = ?, 
                is_active = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            $data['price_monthly'] ?? 0,
            $data['icon'] ?? 'Puzzle',
            isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
            $id
        ]);
        sendResponse(['message' => 'Add-on updated']);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to update add-on: ' . $e->getMessage()], 500);
    }
}
if ($method === 'PUT' && $uriParts[1] === 'contact-enquiries' && isset($uriParts[3]) && $uriParts[3] === 'status') {
    $id = $uriParts[2];
    $input = getRequestBody();
    $status = $input['status'] ?? null;

    if (!$status || !in_array($status, ['pending', 'replied', 'closed'])) {
        sendResponse(['error' => 'Invalid status'], 400);
    }

    try {
        $stmt = $db->prepare("UPDATE contact_enquiries SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $id]);
        sendResponse(['message' => 'Status updated successfully']);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to update status: ' . $e->getMessage()], 500);
    }
}

// GET /api/admin/memberships - Get all salons with subscription info
if ($method === 'GET' && $uriParts[1] === 'memberships') {
    $query = "
        SELECT s.id as salon_id, s.name as salon_name, s.email as salon_email,
               ss.id as subscription_id, ss.plan_id, ss.status as subscription_status,
               ss.end_date, sp.name as plan_name
        FROM salons s
        LEFT JOIN salon_subscriptions ss ON s.id = ss.salon_id
        LEFT JOIN subscription_plans sp ON ss.plan_id = sp.id
        ORDER BY s.created_at DESC
    ";
    $stmt = $db->query($query);
    $memberships = $stmt->fetchAll();
    sendResponse($memberships);
}

// POST /api/admin/memberships/assign - Assign or update a plan for a salon
if ($method === 'POST' && $uriParts[1] === 'memberships' && isset($uriParts[2]) && $uriParts[2] === 'assign') {
    $data = getRequestBody();
    $salonId = $data['salon_id'] ?? null;
    $planId = $data['plan_id'] ?? null;
    $status = $data['status'] ?? 'active';

    if (!$salonId || !$planId) {
        sendResponse(['error' => 'Salon ID and Plan ID are required'], 400);
    }

    try {
        // Fetch Plan Details (Price & Name)
        $stmt = $db->prepare("SELECT name, price_monthly FROM subscription_plans WHERE id = ?");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            sendResponse(['error' => 'Invalid Plan ID'], 400);
        }

        $price = $plan['price_monthly'];
        $planName = $plan['name'];

        // Check if subscription exists
        $stmt = $db->prepare("SELECT id FROM salon_subscriptions WHERE salon_id = ?");
        $stmt->execute([$salonId]);
        $subscriptionId = $stmt->fetchColumn();

        if ($subscriptionId) {
            // Update existing
            $stmt = $db->prepare("
                UPDATE salon_subscriptions SET 
                    plan_id = ?, 
                    status = ?, 
                    end_date = DATE_ADD(NOW(), INTERVAL 1 MONTH),
                    updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$planId, $status, $subscriptionId]);
        }
        else {
            // Create new
            $subscriptionId = Auth::generateUuid();
            $stmt = $db->prepare("
                INSERT INTO salon_subscriptions (id, salon_id, plan_id, status, start_date, end_date)
                VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH))
            ");
            $stmt->execute([$subscriptionId, $salonId, $planId, $status]);
        }

        // Record Revenue (Platform Payment)
        if ($price > 0) {
            $paymentId = Auth::generateUuid();
            $stmt = $db->prepare("
                INSERT INTO platform_payments (id, salon_id, subscription_id, amount, status, payment_method, created_at, paid_at)
                VALUES (?, ?, ?, ?, 'completed', 'admin_assignment', NOW(), NOW())
            ");
            $stmt->execute([$paymentId, $salonId, $subscriptionId, $price]);
        }

        // Notify Salon Owner
        $ownerId = $invoiceService->getSalonOwnerId($salonId);
        if ($ownerId) {
            $notifService->notifyUser($ownerId, "Plan Updated", "Your salon plan has been updated to $planName by Admin.", 'info', '/dashboard/settings');
        }

        sendResponse(['message' => 'Membership assigned successfully']);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to assign membership: ' . $e->getMessage()], 500);
    }
}

// GET /api/admin/salons/addons - Get all assigned addons
if ($method === 'GET' && $uriParts[1] === 'salons' && isset($uriParts[2]) && $uriParts[2] === 'addons') {
    try {
        $stmt = $db->query("
            SELECT sa.*, a.name as addon_name, a.price_monthly, a.icon 
            FROM salon_addons sa
            JOIN subscription_addons a ON sa.addon_id = a.id
        ");
        $salonAddons = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse(['salonAddons' => $salonAddons]);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to fetch salon addons: ' . $e->getMessage()], 500);
    }
}

// POST /api/admin/salons/addons/assign - Assign or revoke addon
if ($method === 'POST' && $uriParts[1] === 'salons' && isset($uriParts[2]) && $uriParts[2] === 'addons' && isset($uriParts[3]) && $uriParts[3] === 'assign') {
    $data = getRequestBody();
    $salonId = $data['salon_id'] ?? null;
    $addonId = $data['addon_id'] ?? null;
    $action = $data['action'] ?? 'assign'; // assign or revoke

    if (!$salonId || !$addonId) {
        sendResponse(['error' => 'Salon ID and Addon ID are required'], 400);
    }

    try {
        if ($action === 'revoke') {
            $stmt = $db->prepare("DELETE FROM salon_addons WHERE salon_id = ? AND addon_id = ?");
            $stmt->execute([$salonId, $addonId]);
            sendResponse(['message' => 'Addon revoked successfully']);
        }
        else {
            $id = Auth::generateUuid();
            $stmt = $db->prepare("INSERT IGNORE INTO salon_addons (id, salon_id, addon_id, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$id, $salonId, $addonId]);
            sendResponse(['message' => 'Addon assigned successfully', 'id' => $id]);
        }
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Action failed: ' . $e->getMessage()], 500);
    }
}

// GET /api/admin/reviews - Get all ecosystem reviews
if ($method === 'GET' && $uriParts[1] === 'reviews') {
    $stmt = $db->query("
        SELECT r.*, p.full_name as user_name, p.avatar_url as user_avatar, 
               s.name as salon_name, srv.name as service_name
        FROM booking_reviews r
        LEFT JOIN profiles p ON r.user_id = p.user_id
        LEFT JOIN salons s ON r.salon_id = s.id
        LEFT JOIN services srv ON (
             SELECT service_id FROM bookings WHERE id = r.booking_id
        ) = srv.id
        ORDER BY r.created_at DESC
    ");
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback if services join didn't work via booking_id logic above
    // or if we need a direct join. The logic above assumes r.booking_id exists.
    // If your reviews table schema has service_id directly, use that instead.

    sendResponse(['reviews' => $reviews]);
}

// GET /api/admin/orders - Get all orders
if ($method === 'GET' && $uriParts[1] === 'orders') {
    $stmt = $db->query("
        SELECT o.*, u.email as user_email, p.full_name as user_name 
        FROM platform_orders o
        LEFT JOIN users u ON BINARY o.user_id = BINARY u.id
        LEFT JOIN profiles p ON BINARY o.user_id = BINARY p.user_id
        ORDER BY o.created_at DESC
    ");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode JSON fields & Normalize names
    foreach ($orders as &$order) {
        $order['items'] = json_decode($order['items'] ?? '[]');
        $order['shipping_address'] = json_decode($order['shipping_address'] ?? 'null');
        $order['customer_name'] = $order['guest_name'] ?: ($order['user_name'] ?? 'Unknown');
        $order['customer_email'] = $order['guest_email'] ?: ($order['user_email'] ?? 'N/A');
    }

    sendResponse(['orders' => $orders]);
}

// PUT /api/admin/orders/:id/status - Update order status
if ($method === 'PUT' && $uriParts[1] === 'orders' && isset($uriParts[3]) && $uriParts[3] === 'status') {
    $orderId = $uriParts[2]; // /api/admin/orders/:id/status
    $data = getRequestBody();
    $newStatus = $data['status'] ?? null;

    // Status validation
    if (!$newStatus || !in_array($newStatus, ['placed', 'dispatched', 'delivered', 'cancelled'])) {
        sendResponse(['error' => 'Invalid status. Must be one of: placed, dispatched, delivered, cancelled'], 400);
    }

    try {
        $stmt = $db->prepare("UPDATE platform_orders SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $orderId]);

        // Optional: Send Notification to User if they are registered
        $stmt = $db->prepare("SELECT user_id, guest_email FROM platform_orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order && $order['user_id'] && $order['user_id'] !== 'guest') {
        // You can use NotifService here if you want
        }

        sendResponse(['message' => 'Order status updated successfully']);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Failed to update status: ' . $e->getMessage()], 500);
    }
}



// DELETE /api/admin/reviews/:id - Delete a review
if ($method === 'DELETE' && $uriParts[1] === 'reviews' && isset($uriParts[2])) {
    $reviewId = $uriParts[2];
    $stmt = $db->prepare("DELETE FROM booking_reviews WHERE id = ?");
    $stmt->execute([$reviewId]);
    sendResponse(['message' => 'Review purged successfully']);
}

sendResponse(['error' => 'Admin route not found'], 404);
