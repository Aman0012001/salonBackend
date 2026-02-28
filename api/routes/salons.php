<?php
// Salon routes

// GET /api/salons - List all active salons
if ($method === 'GET' && count($uriParts) === 1) {
    try {
        $stmt = $db->prepare("
            SELECT s.id, s.name, s.slug, s.description, s.address, s.city, s.state, s.pincode, 
                   s.phone, s.email, s.logo_url, s.cover_image_url, s.is_active, s.rating, s.total_reviews,
                   p.full_name as owner_name
            FROM salons s
            LEFT JOIN user_roles ur ON s.id = ur.salon_id AND ur.role = 'owner'
            LEFT JOIN profiles p ON ur.user_id = p.user_id
            WHERE s.is_active = 1 AND s.approval_status = 'approved'
            GROUP BY s.id, p.full_name
            ORDER BY s.created_at DESC
        ");
        $stmt->execute();
        $salons = $stmt->fetchAll();
        error_log("[Salons API] Found " . count($salons) . " active salons");
        sendResponse(['salons' => $salons]);
    }
    catch (PDOException $e) {
        sendResponse(['error' => 'Query failed: ' . $e->getMessage()], 500);
    }
}

// GET /api/salons/my - Get user's salons
if ($method === 'GET' && $uriParts[1] === 'my') {
    $userData = Auth::getUserFromToken();
    if (!$userData) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }

    $stmt = $db->prepare("
        SELECT s.*, ur.role
        FROM salons s
        INNER JOIN user_roles ur ON s.id = ur.salon_id
        WHERE ur.user_id = ?
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$userData['user_id']]);
    $salons = $stmt->fetchAll();

    sendResponse(['salons' => $salons]);
}

// GET /api/salons/:id - Get salon by ID
if ($method === 'GET' && count($uriParts) === 2) {
    $salonId = $uriParts[1];
    $stmt = $db->prepare("
        SELECT s.* 
        FROM salons s
        WHERE s.id = ? AND s.is_active = 1
    ");
    $stmt->execute([$salonId]);
    $salon = $stmt->fetch();

    if (!$salon) {
        sendResponse(['error' => 'Salon not found'], 404);
    }

    sendResponse(['salon' => $salon]);
}

// POST /api/salons - Create new salon
if ($method === 'POST' && count($uriParts) === 1) {
    $userData = Auth::getUserFromToken();
    if (!$userData) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }

    $data = getRequestBody();
    $salonId = Auth::generateUuid();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO salons (id, name, slug, description, address, city, state, pincode, phone, email, logo_url, cover_image_url, approval_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')
        ");
        $stmt->execute([
            $salonId,
            $data['name'],
            $data['slug'],
            $data['description'] ?? null,
            $data['address'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['pincode'] ?? null,
            $data['phone'] ?? null,
            $data['email'] ?? null,
            $data['logo_url'] ?? null,
            $data['cover_image_url'] ?? null
        ]);

        // Create owner role
        $roleId = Auth::generateUuid();
        $stmt = $db->prepare("INSERT INTO user_roles (id, user_id, salon_id, role) VALUES (?, ?, ?, 'owner')");
        $stmt->execute([$roleId, $userData['user_id'], $salonId]);

        $db->commit();

        // Notify Admins
        if (isset($notifService)) {
            $notifService->notifyAdmins(
                "New Salon Registration",
                "A new salon '{$data['name']}' has been registered and is pending approval.",
                'alert',
                '/admin/salons?status=pending'
            );
        }

        // Notify subscribers
        $newsletterService->notifySubscribers('salon', $data['name'], $data['city'] ?? '');

        $stmt = $db->prepare("SELECT * FROM salons WHERE id = ?");
        $stmt->execute([$salonId]);
        $salon = $stmt->fetch();

        sendResponse(['salon' => $salon], 201);
    }
    catch (Exception $e) {
        $db->rollBack();
        sendResponse(['error' => 'Failed to create salon: ' . $e->getMessage()], 500);
    }
}

// PUT /api/salons/:id - Update salon
if ($method === 'PUT' && count($uriParts) === 2) {
    $userData = Auth::getUserFromToken();
    if (!$userData) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }

    $salonId = $uriParts[1];
    $data = getRequestBody();

    // Check if user is owner
    $stmt = $db->prepare("SELECT id FROM user_roles WHERE user_id = ? AND salon_id = ? AND role = 'owner'");
    $stmt->execute([$userData['user_id'], $salonId]);
    if (!$stmt->fetch()) {
        sendResponse(['error' => 'Forbidden'], 403);
    }

    // Dynamic update query
    $fields = [];
    $params = [];

    // Allowed fields to be updated
    $allowedFields = [
        'name',
        'description',
        'address',
        'city',
        'state',
        'pincode',
        'phone',
        'email',
        'logo_url',
        'logo_public_id',
        'cover_image_url',
        'cover_image_public_id',
        'is_active',
        'business_hours',
        'notification_settings',
        'gst_number',
        'upi_id',
        'bank_details'
    ];

    foreach ($data as $key => $value) {
        if (in_array($key, $allowedFields)) {
            $fields[] = "$key = ?";
            $params[] = $value;
        }
    }

    if (empty($fields)) {
        sendResponse(['error' => 'No valid fields provided for update'], 400);
    }

    $params[] = $salonId;

    $query = "UPDATE salons SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $stmt = $db->prepare("SELECT * FROM salons WHERE id = ?");
    $stmt->execute([$salonId]);
    $salon = $stmt->fetch();

    sendResponse(['salon' => $salon]);
}



// GET /api/salons/:id/analytics - Get salon analytics
if ($method === 'GET' && count($uriParts) === 3 && $uriParts[2] === 'analytics') {
    $userData = Auth::getUserFromToken();
    if (!$userData)
        sendResponse(['error' => 'Unauthorized'], 401);

    $salonId = $uriParts[1];

    // Check permission
    $stmt = $db->prepare("SELECT role FROM user_roles WHERE user_id = ? AND salon_id = ?");
    $stmt->execute([$userData['user_id'], $salonId]);
    $role = $stmt->fetch();
    if (!$role || !in_array($role['role'], ['owner', 'manager'])) {
        sendResponse(['error' => 'Forbidden - Analytical clearance required'], 403);
    }

    $analytics = [];

    // 1. Revenue & Profit Trends (Monthly/Annual)
    $stmt = $db->prepare("
        SELECT month, SUM(revenue) as revenue, SUM(profit) as profit FROM (
            SELECT 
                DATE_FORMAT(booking_date, '%Y-%m') as month,
                SUM(s.price) as revenue,
                SUM(s.price - COALESCE(s.cost_price, 0)) as profit
            FROM bookings b
            JOIN services s ON b.service_id = s.id
            WHERE b.salon_id = ? AND b.status = 'completed'
            GROUP BY DATE_FORMAT(booking_date, '%Y-%m')
            
            UNION ALL
            
            SELECT 
                DATE_FORMAT(purchase_date, '%Y-%m') as month,
                SUM(total_amount) as revenue,
                SUM(total_amount - COALESCE(cost_price, 0)) as profit
            FROM customer_product_purchases
            WHERE salon_id = ?
            GROUP BY DATE_FORMAT(purchase_date, '%Y-%m')
        ) combined_analytics
        GROUP BY month
        ORDER BY month ASC
        LIMIT 12
    ");
    $stmt->execute([$salonId, $salonId]);
    $analytics['revenue_monthly'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Most Popular & Profitable Treatments
    $stmt = $db->prepare("
        SELECT s.name, 
               COUNT(b.id) as count, 
               SUM(CASE WHEN b.status = 'completed' THEN s.price ELSE 0 END) as total_earned, 
               SUM(CASE WHEN b.status = 'completed' THEN (s.price - COALESCE(s.cost_price, 0)) ELSE 0 END) as total_profit
        FROM services s
        LEFT JOIN bookings b ON s.id = b.service_id
        WHERE s.salon_id = ?
        GROUP BY s.id, s.name
        ORDER BY count DESC, s.name ASC
        LIMIT 10
    ");
    $stmt->execute([$salonId]);
    $analytics['popular_treatments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Customer Ratios (New vs Existing)
    $stmt = $db->prepare("
        SELECT 
            CASE WHEN booking_count = 1 THEN 'New' ELSE 'Existing' END as type,
            COUNT(*) as customer_count
        FROM (
            SELECT user_id, COUNT(*) as booking_count
            FROM bookings
            WHERE salon_id = ? AND status = 'completed'
            GROUP BY user_id
        ) as customer_stats
        GROUP BY type
    ");
    $stmt->execute([$salonId]);
    $analytics['customer_ratio'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Customer Activity
    $stmt = $db->prepare("
        SELECT p.full_name, b.service_id, b.booking_date, b.status, s.name as service_name, b.created_at
        FROM bookings b
        JOIN profiles p ON b.user_id = p.user_id
        JOIN services s ON b.service_id = s.id
        WHERE b.salon_id = ?
        ORDER BY b.created_at DESC
        LIMIT 15
    ");
    $stmt->execute([$salonId]);
    $analytics['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse($analytics);
}

sendResponse(['error' => 'Salon route not found'], 404);
