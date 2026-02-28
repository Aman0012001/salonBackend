<?php
/**
 * 🚀 SALON BOOKING API - MAIN ENTRY POINT
 */

// 0. ==========================================
// 🛡️ CRITIAL: SUPPRESS ERRORS IMMEDIATELY
// ==========================================
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 1. ==========================================
// 🚀 CORS (ROBUST SETUP)
// ==========================================

// 1. ==========================================
// 🚀 CORS HEADERS HANDLED BY APACHE (.htaccess)
// ==========================================

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Apache adds the CORS headers automatically
    // We just need to respond with 200 OK and exit
    header('Access-Control-Max-Age: 86400');
    header('Content-Length: 0');
    header('Content-Type: text/plain');
    http_response_code(200);
    exit();
}

// 2. ==========================================
// 📦 HEADERS & UTILS
// ==========================================

ob_start(); // Prevent accidental output

// Bulletproof Error Handling
function handleFatalError()
{
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR)) {
        if (ob_get_level() > 0)
            ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Fatal Error in Local Backend',
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
        exit();
    }
}
register_shutdown_function('handleFatalError');

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('X-Backend-Server: Salon-PHP-API');

function sendResponse($data, $statusCode = 200)
{
    if (ob_get_level() > 0) {
        ob_end_clean(); // Clear any accidental output before sending JSON
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');

    $payload = ['data' => $data];
    if (isset($data['error'])) {
        $payload = $data; // Don't wrap if it's already an error structure
    }

    $json = json_encode($payload);
    if ($json === false) {
        echo json_encode([
            'error' => 'JSON Encode Error',
            'message' => json_last_error_msg()
        ]);
    } else {
        echo $json;
    }
    exit();
}

function getRequestBody()
{
    $input = file_get_contents('php://input');
    if (empty($input))
        return [];
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}

// 3. ==========================================
// ⚙️ INITIALIZATION
// ==========================================

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../Database.php';
    require_once __DIR__ . '/../Auth.php';

    // Get database instance (lazy connection)
    $db = Database::getInstance();

    require_once __DIR__ . '/../Services/NotificationService.php';
    require_once __DIR__ . '/../Services/InvoiceService.php';
    require_once __DIR__ . '/../Services/RBACService.php';
    require_once __DIR__ . '/../Services/NewsletterService.php';
    require_once __DIR__ . '/../Services/MembershipService.php';
    require_once __DIR__ . '/../Services/GoogleDriveService.php';
    require_once __DIR__ . '/../Services/CloudinaryService.php';
    require_once __DIR__ . '/../Services/EmailService.php';


    $notifService = new NotificationService($db);
    $invoiceService = new InvoiceService($db, $notifService);
    $rbacService = new RBACService($db);
    $newsletterService = new NewsletterService($db);
    $membershipService = new MembershipService($db);
    $googleDriveService = new GoogleDriveService();
    $cloudinaryService = new CloudinaryService();


    /**
     * Role-Based Access Control Helper
     */
    if (!function_exists('protectRoute')) {
        function protectRoute($allowedRoles = [], $requiredPermission = null, $explicitSalonId = null)
        {
            global $rbacService;
            $userData = Auth::getUserFromToken();
            if (!$userData) {
                sendResponse(['error' => 'Authentication required in local registry.'], 401);
            }

            // Map profile user_types to salon roles for legacy tokens/backward compatibility
            $effectiveRole = $userData['role'];

            // BYPASS: Check if user is actually a super admin in DB, regardless of token
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT 1 FROM platform_admins WHERE user_id = ? AND is_active = 1");
                $stmt->execute([$userData['user_id']]);
                if ($stmt->fetchColumn()) {
                    $effectiveRole = 'admin';
                }
            } catch (Exception $e) {
                // Ignore DB errors here, fall back to token role
            }

            if ($effectiveRole === 'salon_owner')
                $effectiveRole = 'owner';
            if ($effectiveRole === 'salon_staff')
                $effectiveRole = 'staff';
            if ($effectiveRole === 'admin')
                $effectiveRole = 'super_admin';

            // If specific roles are required
            if (!empty($allowedRoles)) {
                $isAllowed = in_array($effectiveRole, (array) $allowedRoles);

                // Also allow super_admin to everything
                if ($effectiveRole === 'super_admin')
                    $isAllowed = true;

                if (!$isAllowed) {
                    sendResponse(['error' => "Forbidden - Insufficient clearance level (Role: $effectiveRole)."], 403);
                }
            }

            // If a specific permission is required
            if ($requiredPermission) {
                // BYPASS: Super Admin has all permissions
                if ($effectiveRole === 'super_admin') {
                    return $userData;
                }

                $salonId = $explicitSalonId ?: ($_GET['salon_id'] ?? $_POST['salon_id'] ?? null);

                // If not in GET/POST, check request body
                if (!$salonId) {
                    $body = json_decode(file_get_contents('php://input'), true);
                    $salonId = $body['salon_id'] ?? null;
                }

                if ($salonId) {
                    if (!$rbacService->hasPermission($userData['user_id'], $salonId, $requiredPermission)) {
                        // Special case: owners should have all permissions if they are verified owners of the salon
                        $db = Database::getInstance()->getConnection();
                        $stmt = $db->prepare("SELECT role FROM user_roles WHERE user_id = ? AND salon_id = ?");
                        $stmt->execute([$userData['user_id'], $salonId]);
                        $allRoles = $stmt->fetchAll(PDO::FETCH_COLUMN);

                        // Prioritize the most powerful role
                        $actualRole = null;
                        if (in_array('super_admin', $allRoles))
                            $actualRole = 'super_admin';
                        elseif (in_array('owner', $allRoles))
                            $actualRole = 'owner';
                        elseif (in_array('manager', $allRoles))
                            $actualRole = 'manager';
                        elseif (in_array('staff', $allRoles))
                            $actualRole = 'staff';

                        // Allow owners, super_admins, and managers to bypass granular checks
                        // Also allow 'staff' to bypass 'manage_bookings' and 'view_bookings' as they are core functions
                        $isBypassRole = in_array($actualRole, ['owner', 'super_admin', 'manager']);
                        $isStaffBypass = ($actualRole === 'staff' && in_array($requiredPermission, ['manage_bookings', 'view_bookings']));

                        if (!$isBypassRole && !$isStaffBypass) {
                            sendResponse(['error' => "Forbidden - Missing required permission: $requiredPermission (Role in salon: " . ($actualRole ?: 'none') . ")"], 403);
                        }
                    }
                }
            }

            return $userData;
        }
    }

    // 4. ==========================================
    // 🛣️ ROUTING
    // ==========================================

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Parse URI path
    $basePath = '/api';
    if (strpos($_SERVER['REQUEST_URI'], '/backend/api') !== false) {
        $basePath = '/backend/api';
    }
    $path = parse_url($uri, PHP_URL_PATH);
    $path = str_replace($basePath, '', $path);
    $uriParts = explode('/', trim($path, '/'));

    error_log("[API Request] Method: $method, Path: $path");
    if (empty($uriParts[0]) || $uriParts[0] === '') {
        sendResponse([
            'status' => 'online',
            'message' => 'Salon API is active'
        ]);
    }

    // DEBUG ROUTE: Promote current user to admin
    if (in_array('debug', $uriParts) && in_array('promote-me', $uriParts)) {
        $userData = Auth::getUserFromToken();
        if (!$userData) {
            sendResponse(['error' => 'Not logged in - Please login as a user first'], 401);
        }
        $userId = $userData['user_id'];

        $db->exec("CREATE TABLE IF NOT EXISTS platform_admins (
            id VARCHAR(36) PRIMARY KEY,
            user_id VARCHAR(36) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id)
        )");

        $db->prepare("INSERT IGNORE INTO platform_admins (id, user_id, is_active) VALUES (?, ?, 1)")
            ->execute([Auth::generateUuid(), $userId]);

        $db->prepare("UPDATE profiles SET user_type = 'admin' WHERE user_id = ?")
            ->execute([$userId]);

        sendResponse(['success' => true, 'message' => 'You have been promoted to super admin', 'user_id' => $userId]);
    }

    // DEBUG ROUTE: Promote first user to admin
    if (in_array('debug', $uriParts) && in_array('promote-first-user', $uriParts)) {
        $db->exec("CREATE TABLE IF NOT EXISTS platform_admins (
            id VARCHAR(36) PRIMARY KEY,
            user_id VARCHAR(36) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id)
        )");
        $stmt = $db->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $db->prepare("INSERT IGNORE INTO platform_admins (id, user_id, is_active) VALUES (?, ?, 1)")
                ->execute([Auth::generateUuid(), $user['id']]);
            // Also update profile type
            $db->prepare("UPDATE profiles SET user_type = 'admin' WHERE user_id = ?")
                ->execute([$user['id']]);
            sendResponse(['success' => true, 'message' => 'First user promoted to admin and user_type updated', 'user_id' => $user['id']]);
        }
        sendResponse(['error' => 'No users found'], 404);
    }

    // Load sub-routers
    switch ($uriParts[0]) {
        case 'auth':
            require_once __DIR__ . '/routes/auth.php';
            break;
        case 'search':
            require_once __DIR__ . '/routes/search.php';
            break;
        case 'salons':
            require_once __DIR__ . '/routes/salons.php';
            break;
        case 'services':
            require_once __DIR__ . '/routes/services.php';
            break;
        case 'bookings':
            require_once __DIR__ . '/routes/bookings.php';
            break;
        case 'users':
        case 'profiles':
            require_once __DIR__ . '/routes/users.php';
            break;
        case 'staff':
            require_once __DIR__ . '/routes/staff.php';
            break;
        case 'admin':
            require_once __DIR__ . '/routes/admin.php';
            break;
        case 'subscriptions':
            require_once __DIR__ . '/routes/subscriptions.php';
            break;
        case 'notifications':
            require_once __DIR__ . '/routes/notifications.php';
            break;
        case 'uploads':
            require_once __DIR__ . '/routes/uploads.php';
            break;
        case 'reviews':
            require_once __DIR__ . '/routes/reviews.php';
            break;
        case 'customer_records':
            require_once __DIR__ . '/routes/customer_records.php';
            break;
        case 'platform_products':
            require_once __DIR__ . '/routes/platform_products.php';
            break;
        case 'inventory':
            require_once __DIR__ . '/routes/inventory.php';
            break;
        case 'offers':
            require_once __DIR__ . '/routes/offers.php';
            break;
        case 'contact-enquiries':
            require_once __DIR__ . '/routes/contact-enquiries.php';
            break;
        case 'messages':
            require_once __DIR__ . '/routes/messages.php';
            break;
        case 'knowledge-base':
            require_once __DIR__ . '/routes/knowledge_base.php';
            break;
        case 'product_purchases':
            require_once __DIR__ . '/routes/product_purchases.php';
            break;
        case 'orders':
            require_once __DIR__ . '/routes/orders.php';
            break;
        case 'mail':
            require_once __DIR__ . '/routes/mail.php';
            break;
        case 'newsletter':
            require_once __DIR__ . '/routes/newsletter.php';
            break;
        case 'reminders':
            require_once __DIR__ . '/routes/reminders.php';
            break;
        case 'loyalty':
            require_once __DIR__ . '/routes/loyalty.php';
            break;
        case 'coins':
            require_once __DIR__ . '/routes/coins.php';
            break;
        case 'coupons':
            require_once __DIR__ . '/routes/coupons.php';
            break;
        case 'stripe':
            require_once __DIR__ . '/routes/stripe.php';
            break;
        default:
            sendResponse([
                'error' => 'Route not found',
                'uri' => $uri,
                'path' => $path,
                'uriParts' => $uriParts
            ], 404);
            break;
    }

} catch (Exception $e) {
    sendResponse(['error' => 'System error: ' . $e->getMessage()], 500);
}
