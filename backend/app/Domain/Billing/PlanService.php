<?php

namespace App\Domain\Billing;

use App\Domain\Audit\AuditLogger;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Subscription plans. Platform level. Archived, never deleted: a request made
 * against a plan must still be able to say what it was for.
 */
final class PlanService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int,Plan> */
    public function list(bool $includeArchived = false): Collection
    {
        return Plan::query()
            ->when(! $includeArchived, fn ($q) => $q->active())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): Plan
    {
        return DB::transaction(function () use ($data) {
            $plan = new Plan;
            $plan->fill($this->normalise($data));
            $plan->save();

            return $plan->fresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function update(Plan $plan, array $data): Plan
    {
        return DB::transaction(function () use ($plan, $data) {
            $plan->fill($this->normalise($data));
            $plan->save();

            return $plan->fresh();
        });
    }

    /** The Auditable trait records this as plan.archived. */
    public function archive(Plan $plan): Plan
    {
        if (! $plan->isArchived()) {
            $plan->forceFill(['archived_at' => now()])->save();
        }

        return $plan->fresh();
    }

    public function restore(Plan $plan): Plan
    {
        if ($plan->isArchived()) {
            $before = $plan->archived_at?->toIso8601String();
            $plan->forceFill(['archived_at' => null])->saveQuietly();

            $this->audit->record(
                action: 'plan.restored',
                entity: $plan,
                before: ['archived_at' => $before],
                after: ['archived_at' => null],
            );
        }

        return $plan->fresh();
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function normalise(array $data): array
    {
        if (array_key_exists('features', $data)) {
            $data['features'] = array_values(array_filter(
                array_map(fn ($feature) => trim((string) $feature), (array) $data['features']),
                fn (string $feature) => $feature !== '',
            ));
        }

        if (array_key_exists('limits', $data)) {
            $limits = (array) ($data['limits'] ?? []);
            $data['limits'] = [
                'projects' => isset($limits['projects']) ? (int) $limits['projects'] : null,
                'users' => isset($limits['users']) ? (int) $limits['users'] : null,
            ];
        }

        if (isset($data['code'])) {
            $data['code'] = strtolower(trim((string) $data['code']));
        }

        return $data;
    }
}
