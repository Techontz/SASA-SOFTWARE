<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\SubscriptionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\UpdateSubscriptionRequest;
use App\Http\Resources\Billing\AdminSubscriptionRequestResource;
use App\Models\SubscriptionRequest;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Subscription requests, newest first, for whoever holds billing.manage. */
class AdminSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'string', Rule::in(SubscriptionRequest::STATUSES)]]);

        $query = SubscriptionRequest::query()
            ->whereNull('archived_at')
            ->with(['plan', 'latestPayment', 'payments', 'reviewer:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return AdminSubscriptionRequestResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    public function show(SubscriptionRequest $subscription)
    {
        return ApiResponse::data(new AdminSubscriptionRequestResource(
            $subscription->load(['plan', 'latestPayment', 'payments', 'reviewer:id,name'])
        ));
    }

    public function update(UpdateSubscriptionRequest $request, SubscriptionRequest $subscription)
    {
        return ApiResponse::data(new AdminSubscriptionRequestResource(
            $this->subscriptions->review($subscription, $request->user(), $request->validated())
        ));
    }
}
