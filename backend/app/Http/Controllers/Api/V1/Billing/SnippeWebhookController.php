<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\PaymentService;
use App\Domain\Billing\SnippeWebhook;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * Snippe calls this when a payment settles. Unauthenticated by nature, so the
 * signature is checked against the raw body before anything is read.
 */
class SnippeWebhookController extends Controller
{
    public function __construct(
        private readonly SnippeWebhook $webhook,
        private readonly PaymentService $payments,
    ) {}

    public function __invoke(Request $request)
    {
        $raw = $request->getContent();

        if (! $this->webhook->verify($request->header('X-Webhook-Timestamp'), $request->header('X-Webhook-Signature'), $raw)) {
            return ApiResponse::error('invalid_signature', 'This request could not be verified.', [], 401);
        }

        $event = json_decode($raw, true);

        if (! is_array($event)) {
            return ApiResponse::error('invalid_payload', 'The webhook body is not JSON.', [], 400);
        }

        return ApiResponse::data(['result' => $this->payments->handleSnippeEvent($event)]);
    }
}
