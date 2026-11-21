<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SandboxCompleteRequest;
use App\Http\Resources\Billing\PaymentResource;
use App\Models\Payment;
use App\Support\ApiResponse;

class PublicPaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** The polling read: a pending payment is checked with the gateway. */
    public function show(Payment $payment)
    {
        return ApiResponse::data(new PaymentResource($this->payments->refresh($payment)));
    }

    /** The sandbox card checkout's outcome. Does not exist outside the sandbox. */
    public function sandboxComplete(SandboxCompleteRequest $request, Payment $payment)
    {
        abort_unless(config('sasa.billing.driver') === 'sandbox' && $payment->provider === 'sandbox', 404);

        return ApiResponse::data(new PaymentResource(
            $this->payments->completeSandbox($payment, $request->validated('outcome'))
        ));
    }
}
