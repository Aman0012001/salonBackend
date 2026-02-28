<?php
// Stripe Payment Routes
// Requires: stripe/stripe-php installed via composer

require_once __DIR__ . '/../../vendor/autoload.php';

$stripeSecretKey = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?? '';

if (empty($stripeSecretKey)) {
    sendResponse(['error' => 'Stripe is not configured on this server.'], 503);
}

\Stripe\Stripe::setApiKey($stripeSecretKey);

// POST /api/stripe/create-payment-intent
// Body: { amount (in MYR), currency, metadata: { type, reference_id } }
if ($method === 'POST' && count($uriParts) === 2 && $uriParts[1] === 'create-payment-intent') {
    $data = getRequestBody();

    if (!isset($data['amount']) || !is_numeric($data['amount']) || (float) $data['amount'] <= 0) {
        sendResponse(['error' => 'A valid amount is required.'], 400);
    }

    $amount = (int) round((float) $data['amount'] * 100); // Stripe uses smallest currency unit (sen for MYR)
    $currency = strtolower($data['currency'] ?? 'myr');
    $metadata = $data['metadata'] ?? [];

    // Optional: attach authenticated user if present
    $userData = Auth::getUserFromToken();
    if ($userData) {
        $metadata['user_id'] = $userData['user_id'];
    }

    try {
        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => $metadata,
        ]);

        sendResponse([
            'client_secret' => $paymentIntent->client_secret,
            'payment_intent_id' => $paymentIntent->id,
        ]);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('[Stripe] create-payment-intent error: ' . $e->getMessage());
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

// POST /api/stripe/confirm-payment
// Body: { payment_intent_id, type ('order'|'booking'|'subscription'), reference_id }
if ($method === 'POST' && count($uriParts) === 2 && $uriParts[1] === 'confirm-payment') {
    $data = getRequestBody();

    if (empty($data['payment_intent_id'])) {
        sendResponse(['error' => 'payment_intent_id is required.'], 400);
    }

    $paymentIntentId = $data['payment_intent_id'];
    $type = $data['type'] ?? null;
    $referenceId = $data['reference_id'] ?? null;

    try {
        $pi = \Stripe\PaymentIntent::retrieve($paymentIntentId);

        if ($pi->status !== 'succeeded') {
            sendResponse([
                'success' => false,
                'status' => $pi->status,
                'message' => 'Payment has not been completed yet.',
            ], 402);
        }

        // Update the corresponding DB record based on type
        if ($type === 'order' && $referenceId) {
            // Update platform_orders table
            $updateStmt = $db->prepare(
                "UPDATE platform_orders 
                 SET payment_status = 'paid', payment_intent_id = ?, status = 'confirmed' 
                 WHERE id = ?"
            );
            $updateStmt->execute([$paymentIntentId, $referenceId]);

        } elseif ($type === 'booking' && $referenceId) {
            // Update bookings table (referenceId might be a comma-separated list)
            $refIds = explode(',', $referenceId);
            $inQuery = implode(',', array_fill(0, count($refIds), '?'));
            $params = array_merge([$paymentIntentId], $refIds);

            $updateStmt = $db->prepare(
                "UPDATE bookings 
                 SET payment_status = 'paid', payment_intent_id = ?, status = 'confirmed' 
                 WHERE id IN ($inQuery)"
            );
            $updateStmt->execute($params);
        }

        sendResponse([
            'success' => true,
            'status' => $pi->status,
            'payment_intent_id' => $paymentIntentId,
            'amount_received' => $pi->amount_received / 100,
            'currency' => strtoupper($pi->currency),
        ]);

    } catch (\Stripe\Exception\ApiErrorException $e) {
        error_log('[Stripe] confirm-payment error: ' . $e->getMessage());
        sendResponse(['error' => $e->getMessage()], 500);
    }
}

// POST /api/stripe/webhook
// Called by Stripe dashboard webhooks (optional – for production reliability)
if ($method === 'POST' && count($uriParts) === 2 && $uriParts[1] === 'webhook') {
    $webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? getenv('STRIPE_WEBHOOK_SECRET') ?? '';
    $payload = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    try {
        if (!empty($webhookSecret) && !empty($sigHeader)) {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } else {
            $event = \Stripe\Event::constructFrom(json_decode($payload, true));
        }
    } catch (\UnexpectedValueException $e) {
        sendResponse(['error' => 'Invalid payload'], 400);
    } catch (\Stripe\Exception\SignatureVerificationException $e) {
        sendResponse(['error' => 'Invalid signature'], 400);
    }

    if ($event->type === 'payment_intent.succeeded') {
        $pi = $event->data->object;
        $metadata = $pi->metadata;
        $type = $metadata['type'] ?? null;
        $refId = $metadata['reference_id'] ?? null;

        if ($type === 'order' && $refId) {
            $stmt = $db->prepare(
                "UPDATE platform_orders SET payment_status='paid', payment_intent_id=?, status='confirmed' WHERE id=?"
            );
            $stmt->execute([$pi->id, $refId]);
        } elseif ($type === 'booking' && $refId) {
            $refIds = explode(',', $refId);
            $inQuery = implode(',', array_fill(0, count($refIds), '?'));
            $params = array_merge([$pi->id], $refIds);

            $stmt = $db->prepare(
                "UPDATE bookings SET payment_status='paid', payment_intent_id=?, status='confirmed' WHERE id IN ($inQuery)"
            );
            $stmt->execute($params);
        }
    }

    sendResponse(['received' => true]);
}

sendResponse(['error' => 'Stripe route not found'], 404);
