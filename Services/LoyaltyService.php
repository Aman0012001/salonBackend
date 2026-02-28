<?php

require_once __DIR__ . "/NotificationService.php";

class LoyaltyService
{
    private $db;
    private $notifService;

    public function __construct($db)
    {
        $this->db = $db;
        $this->notifService = new NotificationService($db);
    }

    // --- Settings ---

    public function getSettings($salonId)
    {
        $stmt = $this->db->prepare("SELECT * FROM loyalty_programs WHERE salon_id = ?");
        $stmt->execute([$salonId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            // Create default settings if not exists
            $id = Auth::generateUuid();
            $stmt = $this->db->prepare("
                INSERT INTO loyalty_programs (id, salon_id, is_active)
                VALUES (?, ?, 1)
            ");
            $stmt->execute([$id, $salonId]);
            return $this->getSettings($salonId);
        }

        return $settings;
    }

    public function updateSettings($salonId, $data)
    {
        $fields = [];
        $params = [];
        $validFields = ["program_name", "is_active", "points_per_currency_unit", "min_points_redemption", "signup_bonus_points", "description"];

        foreach ($validFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $salonId;
        $sql = "UPDATE loyalty_programs SET " . implode(", ", $fields) . " WHERE salon_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    // --- Rewards ---

    public function getRewards($salonId, $activeOnly = false)
    {
        $sql = "SELECT * FROM loyalty_rewards WHERE salon_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY points_required ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$salonId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createReward($salonId, $data)
    {
        $id = Auth::generateUuid();
        $stmt = $this->db->prepare("
            INSERT INTO loyalty_rewards (id, salon_id, name, description, points_required, discount_amount, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $id,
            $salonId,
            $data["name"],
            $data["description"] ?? null,
            (int) $data["points_required"],
            (float) ($data["discount_amount"] ?? 0),
            isset($data["is_active"]) ? (int) $data["is_active"] : 1
        ]);
        return $id;
    }

    public function deleteReward($salonId, $rewardId)
    {
        $stmt = $this->db->prepare("DELETE FROM loyalty_rewards WHERE id = ? AND salon_id = ?");
        return $stmt->execute([$rewardId, $salonId]);
    }

    // --- Points Management ---

    public function getCustomerStatus($salonId, $userId)
    {
        $stmt = $this->db->prepare("SELECT loyalty_points, membership_tier, membership_expiry FROM customer_salon_profiles WHERE salon_id = ? AND user_id = ?");
        $stmt->execute([$salonId, $userId]);
        $status = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$status) {
            return ["loyalty_points" => 0, "membership_tier" => "standard", "membership_expiry" => null];
        }

        // Check if prestige has expired
        if ($status["membership_tier"] === "prestige" && $status["membership_expiry"] && strtotime($status["membership_expiry"]) < time()) {
            // Revert to standard
            $stmt = $this->db->prepare("UPDATE customer_salon_profiles SET membership_tier = 'standard' WHERE salon_id = ? AND user_id = ?");
            $stmt->execute([$salonId, $userId]);
            $status["membership_tier"] = "standard";
        }

        return $status;
    }

    public function getCustomerPoints($salonId, $userId)
    {
        $status = $this->getCustomerStatus($salonId, $userId);
        return (int)($status["loyalty_points"] ?? 0);
    }

    public function earnPoints($salonId, $userId, $amountSpent, $bookingId)
    {
        $settings = $this->getSettings($salonId);
        if (!$settings || !$settings["is_active"]) {
            return false;
        }

        $customerStatus = $this->getCustomerStatus($salonId, $userId);
        $isPrestige = ($customerStatus["membership_tier"] === "prestige");
        $multiplier = $isPrestige ? 2.0 : 1.0;
        $points = floor($amountSpent * ($settings["points_per_currency_unit"] ?? 1) * $multiplier);
        $isUpgrade = ($amountSpent >= 600 && !$isPrestige);

        if ($points <= 0 && !$isUpgrade) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            // 1. Log transaction
            $txnId = Auth::generateUuid();
            $desc = $isPrestige ? "Points earned (Double Rewards)" : "Points earned from service";
            $stmt = $this->db->prepare("INSERT INTO loyalty_transactions (id, salon_id, user_id, points, transaction_type, reference_id, description) VALUES (?, ?, ?, ?, 'earned', ?, ?)");
            $stmt->execute([$txnId, $salonId, $userId, $points, $bookingId, $desc]);

            // 2. Update balance and tier
            $params = [$points];
            $sql = "UPDATE customer_salon_profiles SET loyalty_points = loyalty_points + ?";
            if ($isUpgrade) {
                $expiry = date("Y-m-d", strtotime("+1 year"));
                $sql .= ", membership_tier = 'prestige', membership_expiry = ?";
                $params[] = $expiry;
            }
            $sql .= " WHERE salon_id = ? AND user_id = ?";
            $params[] = $salonId;
            $params[] = $userId;
            $this->db->prepare($sql)->execute($params);

            $this->db->commit();

            // 3. Notifications
            $msg = "You just earned $points loyalty points.";
            if ($isPrestige) $msg = "PRESTIGE BENEFIT: You earned DOUBLE points ($points)!";
            $this->notifService->notifyUser($userId, "Loyalty Points Earned!", $msg, "success", "/client/rewards");

            if ($isUpgrade) {
                $this->notifService->notifyUser($userId, "Prestige Membership Activated!", "You've been upgraded to Prestige for 1 year!", "premium", "/membership");
            }

            return $points;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Loyalty Earn Error: " . $e->getMessage());
            return false;
        }
    }

    public function spendPoints($salonId, $userId, $points, $description = "Points used for booking", $referenceId = null)
    {
        if ($points <= 0) return true;
        $currentPoints = $this->getCustomerPoints($salonId, $userId);
        if ($currentPoints < $points) return ["error" => "Insufficient points"];

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE customer_salon_profiles SET loyalty_points = loyalty_points - ? WHERE salon_id = ? AND user_id = ?")->execute([$points, $salonId, $userId]);
            $txnId = Auth::generateUuid();
            $stmt = $this->db->prepare("INSERT INTO loyalty_transactions (id, salon_id, user_id, points, transaction_type, reference_id, description) VALUES (?, ?, ?, ?, 'redeemed', ?, ?)");
            $stmt->execute([$txnId, $salonId, $userId, -$points, $referenceId, $description]);
            $this->db->commit();
            return ["success" => true, "balance" => $currentPoints - $points];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ["error" => $e->getMessage()];
        }
    }

    public function redeemPoints($salonId, $userId, $rewardId)
    {
        $settings = $this->getSettings($salonId);
        if (!$settings || !$settings["is_active"]) return ["error" => "Inactive"];

        $stmt = $this->db->prepare("SELECT * FROM loyalty_rewards WHERE id = ? AND salon_id = ?");
        $stmt->execute([$rewardId, $salonId]);
        $reward = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reward || !$reward["is_active"]) return ["error" => "Invalid reward"];

        $currentPoints = $this->getCustomerPoints($salonId, $userId);
        if ($currentPoints < $reward["points_required"]) return ["error" => "Insufficient points"];

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE customer_salon_profiles SET loyalty_points = loyalty_points - ? WHERE salon_id = ? AND user_id = ?")->execute([$reward["points_required"], $salonId, $userId]);
            $txnId = Auth::generateUuid();
            $stmt = $this->db->prepare("INSERT INTO loyalty_transactions (id, salon_id, user_id, points, transaction_type, reference_id, description) VALUES (?, ?, ?, ?, 'redeemed', ?, ?)");
            $stmt->execute([$txnId, $salonId, $userId, -$reward["points_required"], $rewardId, "Redeemed: " . $reward["name"]]);
            $this->db->commit();
            return ["success" => true, "balance" => $currentPoints - $reward["points_required"]];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ["error" => $e->getMessage()];
        }
    }

    public function getAllCustomerPoints($userId)
    {
        $stmt = $this->db->prepare("SELECT s.name as salon_name, p.loyalty_points, s.id as salon_id FROM customer_salon_profiles p JOIN salons s ON p.salon_id = s.id WHERE p.user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hasTransactionForReference($bookingId)
    {
        if (!$bookingId) return false;
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM loyalty_transactions WHERE reference_id = ?");
        $stmt->execute([$bookingId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
