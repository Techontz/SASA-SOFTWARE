<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\PaymentService;
use App\Domain\Billing\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PayRequest;
use App\Http\Requests\Billing\StoreSubscriptionRequest;
use App\Http\Resources\Billing\PaymentResource;
use App\Http\Resources\Billing\SubscriptionRequestResource;
use App\Models\SubscriptionRequest;
use App\Support\ApiResponse;

/**
 * Request a subscription and pay for it, without an account. Requests are
 * addressed by an unguessable public id, never by their numeric id.
 */
class PublicSubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentService $payments,
    ) {}

    public function store(StoreSubscriptionRequest $request)
    {
        $subscription = $this->subscriptions->request($request->validated());

        return ApiResponse::data(new SubscriptionRequestResource($subscription), [], 201);
    }

    public function show(SubscriptionRequest $subscription)
    {
        abort_if($subscription->archived_at !== null, 404);

        if ($subscription->latestPayment) {
            $this->payments->refresh($subscription->latestPayment);
        }

        return ApiResponse::data(new SubscriptionRequestResource($subscription->fresh(['plan', 'latestPayment'])));
    }

    public function pay(PayRequest $request, SubscriptionRequest $subscription)
    {
        abort_if($subscription->archived_at !== null, 404);

        $payment = $this->payments->start($subscription, $request->validated('method'), $request->validated('phone'));

        return ApiResponse::data(new PaymentResource($payment), [], 201);
    }
}
