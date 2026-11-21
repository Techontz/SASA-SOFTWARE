<?php

namespace App\Domain\Billing;

use App\Models\Plan;
use App\Models\SubscriptionRequest;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Requests to subscribe. The price is copied from the plan at the moment of
 * asking, so a later price change never alters what someone agreed to.
 */
final class SubscriptionService
{
    /** @param array<string,mixed> $data */
    public function request(array $data): SubscriptionRequest
    {
        $plan = Plan::query()->active()->findOrFail($data['plan_id']);
        $currency = strtoupper((string) $data['currency']);

        $amount = null;

        if (! $plan->is_custom_quote) {
            $amount = $plan->priceIn($currency);

            if ($amount === null) {
                throw new DomainRuleException(
                    "{$plan->name} has no price in {$currency}. Choose another currency.",
                    'currency_unavailable',
                    422,
                    ['errors' => ['currency' => ["{$plan->name} has no price in {$currency}."]]],
                );
            }
        }

        return DB::transaction(function () use ($plan, $currency, $amount, $data) {
            $request = new SubscriptionRequest;
            $request->fill([
                'plan_id' => $plan->id,
                'currency' => $currency,
                'amount' => $amount,
                'organisation_name' => trim((string) $data['organisation_name']),
                'contact_name' => trim((string) $data['contact_name']),
                'email' => strtolower(trim((string) $data['email'])),
                'phone' => PhoneNumber::normaliseTanzanian((string) $data['phone']) ?? trim((string) $data['phone']),
                'country' => isset($data['country']) ? trim((string) $data['country']) : null,
                'message' => isset($data['message']) ? trim((string) $data['message']) : null,
            ]);
            $request->public_id = (string) Str::ulid();
            $request->reference = $this->nextReference();
            $request->status = $plan->is_custom_quote ? 'quote_requested' : 'pending_payment';
            $request->save();

            return $request->fresh(['plan', 'latestPayment']);
        });
    }

    /**
     * An administrator's decision. Only active, rejected and cancelled can be
     * set by hand; "paid" comes from a payment, never from a person.
     *
     * @param  array<string,mixed>  $data
     */
    public function review(SubscriptionRequest $request, User $reviewer, array $data): SubscriptionRequest
    {
        $status = $data['status'] ?? $request->status;

        if ($status !== $request->status && ! in_array($status, SubscriptionRequest::REVIEW_STATUSES, true)) {
            throw new DomainRuleException('A request can be made active, rejected or cancelled.', 'invalid_status');
        }

        return DB::transaction(function () use ($request, $reviewer, $data, $status) {
            $changes = ['status' => $status, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()];

            if (array_key_exists('admin_notes', $data)) {
                $notes = trim((string) $data['admin_notes']);
                $changes['admin_notes'] = $notes === '' ? null : $notes;
            }

            $request->forceFill($changes)->save();

            return $request->fresh(['plan', 'latestPayment', 'payments', 'reviewer:id,name']);
        });
    }

    /** SUB-2026-0001, numbered per calendar year, under a row lock. */
    private function nextReference(): string
    {
        $config = (array) config('sasa.billing.reference', []);
        $prefix = ($config['prefix'] ?? 'SUB').'-'.now()->format('Y').'-';
        $pad = (int) ($config['pad'] ?? 4);

        $last = SubscriptionRequest::query()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('reference');

        $next = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }
}
