<?php

namespace App\Domain\Stakeholder;

use App\Domain\Audit\AuditLogger;
use App\Models\Location;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\StakeholderAssessment;
use App\Support\DomainRuleException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MODULE 1 write path. Everything that creates or changes a register entry
 * goes through here, so the reference number, the priority assessment, the
 * duplicate hash and the audit trail stay consistent.
 */
final class StakeholderService
{
    public function __construct(
        private readonly PriorityCalculator $priority,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Project $project, array $data): Stakeholder
    {
        return DB::transaction(function () use ($project, $data) {
            $stakeholder = new Stakeholder;
            $stakeholder->organisation_id = $project->organisation_id;
            $stakeholder->project_id = $project->id;
            $stakeholder->fill($this->fillable($data));
            $this->applyLocation($stakeholder, $data);
            $stakeholder->duplicate_hash = Stakeholder::duplicateHashFor(
                $stakeholder->name, $stakeholder->phone, $stakeholder->village
            );
            $stakeholder->captured_at = $data['captured_at'] ?? now();
            $stakeholder->synced_at = now();
            $stakeholder->save();

            $this->assess($stakeholder, $data, initial: true);
            $this->syncRelations($stakeholder, $data);

            return $stakeholder->fresh(['currentAssessment', 'primaryLocation', 'owner', 'contacts']);
        });
    }

    public function update(Stakeholder $stakeholder, array $data): Stakeholder
    {
        return DB::transaction(function () use ($stakeholder, $data) {
            $stakeholder->fill($this->fillable($data));
            $this->applyLocation($stakeholder, $data);

            if ($stakeholder->isDirty(['name', 'phone', 'village'])) {
                $stakeholder->duplicate_hash = Stakeholder::duplicateHashFor(
                    $stakeholder->name, $stakeholder->phone, $stakeholder->village
                );
            }

            $stakeholder->save();

            $touchesAssessment = array_intersect_key($data, array_flip(['influence', 'interest', 'power', 'impact'])) !== [];

            if ($touchesAssessment) {
                $this->assess($stakeholder, $data);
            }

            $this->syncRelations($stakeholder, $data);

            return $stakeholder->fresh(['currentAssessment', 'primaryLocation', 'owner', 'contacts']);
        });
    }

    /**
     * Recalculate priority and write a new assessment version.
     * The calculated value is always kept alongside the stored one.
     */
    public function assess(Stakeholder $stakeholder, array $data = [], bool $initial = false): StakeholderAssessment
    {
        $result = $this->priority->calculate(
            $data['influence'] ?? $stakeholder->influence,
            $data['interest'] ?? $stakeholder->interest,
            $data['power'] ?? $stakeholder->power,
            $data['impact'] ?? $stakeholder->impact,
            $stakeholder->organisation_id,
            $stakeholder->project_id,
        );

        StakeholderAssessment::where('stakeholder_id', $stakeholder->id)->update(['is_current' => false]);

        $assessment = StakeholderAssessment::create([
            'organisation_id' => $stakeholder->organisation_id,
            'project_id' => $stakeholder->project_id,
            'stakeholder_id' => $stakeholder->id,
            'influence' => $data['influence'] ?? $stakeholder->influence,
            'interest' => $data['interest'] ?? $stakeholder->interest,
            'power' => $data['power'] ?? $stakeholder->power,
            'impact' => $data['impact'] ?? $stakeholder->impact,
            'weights' => $result['weights'],
            'score' => $result['score'],
            'calculated_priority' => $result['priority'],
            'stored_priority' => $result['priority'],
            'is_override' => false,
            'is_current' => true,
            'assessed_by' => auth()->id(),
            'assessed_at' => now(),
        ]);

        $stakeholder->forceFill([
            'priority_score' => $result['score'],
            'calculated_priority' => $result['priority'],
            'priority' => $result['priority'],
            'priority_overridden' => false,
            'engagement_strategy' => $stakeholder->engagement_strategy ?: $result['strategy'],
            'communication_frequency' => $stakeholder->communication_frequency ?: $result['frequency'],
        ])->save();

        if (! $initial) {
            $this->audit->record(
                action: 'stakeholder.priority_recalculated',
                entity: $stakeholder,
                after: ['score' => $result['score'], 'priority' => $result['priority']],
                summary: $this->priority->explain($result),
            );
        }

        return $assessment;
    }

    /**
     * An officer may override the calculated priority. The override, the
     * previous value and the reason go to the audit log, and overridden
     * records stay countable — systematic override signals a wrong weighting
     * rather than a wrong record.
     */
    public function overridePriority(Stakeholder $stakeholder, string $priority, string $reason): Stakeholder
    {
        if (! in_array($priority, ['high', 'medium', 'low'], true)) {
            throw new DomainRuleException('Priority must be high, medium or low.', 'invalid_priority');
        }

        if (trim($reason) === '') {
            throw new DomainRuleException('Give a reason for changing the calculated priority.', 'reason_required');
        }

        return DB::transaction(function () use ($stakeholder, $priority, $reason) {
            $previous = $stakeholder->priority;

            StakeholderAssessment::where('stakeholder_id', $stakeholder->id)->update(['is_current' => false]);

            $settings = $this->priority->settings($stakeholder->organisation_id, $stakeholder->project_id);

            StakeholderAssessment::create([
                'organisation_id' => $stakeholder->organisation_id,
                'project_id' => $stakeholder->project_id,
                'stakeholder_id' => $stakeholder->id,
                'influence' => $stakeholder->influence,
                'interest' => $stakeholder->interest,
                'power' => $stakeholder->power,
                'impact' => $stakeholder->impact,
                'weights' => $settings['weights'],
                'score' => (int) $stakeholder->priority_score,
                'calculated_priority' => $stakeholder->calculated_priority ?? $previous,
                'stored_priority' => $priority,
                'is_override' => true,
                'previous_priority' => $previous,
                'override_reason' => $reason,
                'is_current' => true,
                'assessed_by' => auth()->id(),
                'assessed_at' => now(),
            ]);

            $stakeholder->forceFill([
                'priority' => $priority,
                'priority_overridden' => true,
            ])->save();

            $this->audit->record(
                action: 'stakeholder.priority_overridden',
                entity: $stakeholder,
                before: ['priority' => $previous, 'calculated' => $stakeholder->calculated_priority],
                after: ['priority' => $priority],
                summary: 'Priority overridden: '.$reason,
            );

            return $stakeholder->fresh('currentAssessment');
        });
    }

    /**
     * Duplicate detection. The source document provides a "Merged (duplicate)"
     * status but no detection rules; ours are name + phone tail + village,
     * surfaced as a warning rather than a hard block — a real village often
     * has two people with the same name.
     *
     * @return Collection<int,Stakeholder>
     */
    public function findPotentialDuplicates(Project $project, array $data, ?int $excludeId = null): Collection
    {
        $hash = Stakeholder::duplicateHashFor($data['name'] ?? null, $data['phone'] ?? null, $data['village'] ?? null);
        $phoneTail = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));
        $phoneTail = $phoneTail !== '' ? substr($phoneTail, -9) : null;

        return Stakeholder::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where(function ($q) use ($hash, $phoneTail, $data) {
                $q->where('duplicate_hash', $hash);

                if ($phoneTail) {
                    $q->orWhere('phone', 'like', '%'.$phoneTail);
                }

                if (! empty($data['name'])) {
                    $q->orWhere('name', 'like', trim($data['name']));
                }
            })
            ->limit(10)
            ->get(['id', 'reference', 'name', 'phone', 'village', 'ward', 'district', 'type', 'status']);
    }

    /** Merge keeps both records: the duplicate is marked merged, not deleted. */
    public function merge(Stakeholder $duplicate, Stakeholder $survivor, string $reason): Stakeholder
    {
        if ($duplicate->id === $survivor->id) {
            throw new DomainRuleException('A record cannot be merged into itself.', 'invalid_merge');
        }

        return DB::transaction(function () use ($duplicate, $survivor, $reason) {
            $duplicate->engagementPlans()->update(['stakeholder_id' => $survivor->id]);
            $duplicate->concerns()->update(['stakeholder_id' => $survivor->id]);
            $duplicate->grievances()->update(['stakeholder_id' => $survivor->id]);

            // MySQL will not let a statement read the table it is updating,
            // so the "already linked" set is resolved first.
            $this->repointPivot('engagement_stakeholder', 'engagement_id', $duplicate->id, $survivor->id);
            $this->repointPivot('commitment_stakeholder', 'commitment_id', $duplicate->id, $survivor->id);

            $duplicate->forceFill([
                'status' => 'merged',
                'merged_into_id' => $survivor->id,
                'archived_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'stakeholder.merged',
                entity: $duplicate,
                after: ['merged_into' => $survivor->reference],
                summary: "Merged into {$survivor->reference}: {$reason}",
            );

            return $survivor->fresh();
        });
    }

    /** Move pivot rows to the survivor, dropping links it already has. */
    private function repointPivot(string $table, string $otherKey, int $duplicateId, int $survivorId): void
    {
        $survivorLinks = DB::table($table)->where('stakeholder_id', $survivorId)->pluck($otherKey)->all();

        DB::table($table)
            ->where('stakeholder_id', $duplicateId)
            ->when($survivorLinks !== [], fn ($q) => $q->whereNotIn($otherKey, $survivorLinks))
            ->update(['stakeholder_id' => $survivorId]);

        DB::table($table)->where('stakeholder_id', $duplicateId)->delete();
    }

    private function fillable(array $data): array
    {
        return collect($data)->only([
            'client_uuid', 'name', 'alias', 'type', 'sub_type', 'organisation_name', 'position',
            'phone', 'alternate_phone', 'email', 'preferred_language', 'preferred_contact_method',
            'physical_address', 'postal_address', 'primary_location_id', 'latitude', 'longitude',
            'influence', 'interest', 'power', 'impact', 'engagement_strategy', 'communication_frequency',
            'concerns_expectations', 'notes', 'is_vulnerable', 'vulnerability_categories',
            'is_indigenous_or_minority', 'consent_status', 'consent_basis', 'consent_date',
            'identification_source', 'identification_method', 'demographics', 'custom_fields',
            'status', 'review_date', 'owner_id',
        ])->all();
    }

    /** Denormalise the location path so list views need no joins. */
    private function applyLocation(Stakeholder $stakeholder, array $data): void
    {
        if (! array_key_exists('primary_location_id', $data)) {
            return;
        }

        $location = $data['primary_location_id'] ? Location::find($data['primary_location_id']) : null;

        $stakeholder->country = null;
        $stakeholder->region = null;
        $stakeholder->district = null;
        $stakeholder->ward = null;
        $stakeholder->village = null;

        $cursor = $location;
        $guard = 0;

        while ($cursor && $guard++ < 10) {
            match ($cursor->level) {
                'country' => $stakeholder->country = substr($cursor->code ?: $cursor->name, 0, 2),
                'region' => $stakeholder->region = $cursor->name,
                'district' => $stakeholder->district = $cursor->name,
                'ward' => $stakeholder->ward = $cursor->name,
                'village' => $stakeholder->village = $cursor->name,
                default => null,
            };

            $cursor = $cursor->parent;
        }
    }

    private function syncRelations(Stakeholder $stakeholder, array $data): void
    {
        if (array_key_exists('location_ids', $data)) {
            $stakeholder->locations()->sync($data['location_ids'] ?? []);
        }

        if (array_key_exists('contacts', $data) && is_array($data['contacts'])) {
            $stakeholder->contacts()->delete();

            foreach ($data['contacts'] as $contact) {
                if (empty($contact['name'])) {
                    continue;
                }

                $stakeholder->contacts()->create(collect($contact)->only([
                    'name', 'role', 'phone', 'email', 'preferred_language', 'is_primary', 'notes',
                ])->all());
            }
        }
    }
}
