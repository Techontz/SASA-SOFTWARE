<?php

namespace App\Domain\Pap;

use App\Domain\Audit\AuditLogger;
use App\Domain\Stakeholder\StakeholderService;
use App\Domain\Tenancy\ReferenceGenerator;
use App\Models\Location;
use App\Models\Pap;
use App\Models\Project;
use App\Models\Stakeholder;
use Illuminate\Support\Facades\DB;

/**
 * The PAP profile write path (Part A).
 *
 * Every PAP is mirrored into the stakeholder register as a stakeholder of
 * type "pap", in the same transaction and through StakeholderService — so the
 * reference number, the priority assessment, the duplicate hash and the audit
 * trail on the stakeholder side are exactly what a hand-made record gets.
 * Only particulars cross over: the National ID and bank details never do.
 */
final class PapService
{
    public function __construct(
        private readonly StakeholderService $stakeholders,
        private readonly ReferenceGenerator $references,
        private readonly PapOptions $options,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Project $project, array $data): Pap
    {
        return DB::transaction(function () use ($project, $data) {
            $pap = new Pap;
            $pap->organisation_id = $project->organisation_id;
            $pap->project_id = $project->id;
            $pap->fill(collect($data)->only(Pap::INPUT_FIELDS)->all());
            $pap->vulnerability_category = $pap->vulnerability_category ?: 'none';
            $this->normalise($pap);

            // A PAP who is not joining an existing household starts one.
            if (blank($pap->household_id)) {
                $pap->household_id = $this->references->next($project->id, 'pap_household');
            }

            $pap->captured_at = $data['captured_at'] ?? now();
            $pap->synced_at = now();
            $pap->save();

            $this->syncStakeholder($project, $pap);

            return $pap->fresh(['stakeholder', 'location']);
        });
    }

    public function update(Pap $pap, array $data): Pap
    {
        return DB::transaction(function () use ($pap, $data) {
            $pap->fill(collect($data)->only(array_diff(Pap::INPUT_FIELDS, ['client_uuid']))->all());
            $pap->vulnerability_category = $pap->vulnerability_category ?: 'none';
            $this->normalise($pap);

            if (blank($pap->household_id)) {
                $pap->household_id = $pap->getOriginal('household_id')
                    ?: $this->references->next($pap->project_id, 'pap_household');
            }

            $pap->save();

            $this->syncStakeholder($pap->project, $pap);

            return $pap->fresh(['stakeholder', 'location']);
        });
    }

    /**
     * Archive, never delete. The mirrored stakeholder is archived with it —
     * it stays on every engagement and grievance it already appears on.
     */
    public function archive(Pap $pap, ?string $reason = null): Pap
    {
        return DB::transaction(function () use ($pap, $reason) {
            $pap->archive();

            if ($reason) {
                $this->audit->record(action: 'pap.archive_reason', entity: $pap, summary: $reason);
            }

            $stakeholder = $pap->stakeholder_id ? Stakeholder::acrossTenants()->find($pap->stakeholder_id) : null;

            if ($stakeholder && $stakeholder->archived_at === null) {
                $stakeholder->archive();
                $this->audit->record(
                    action: 'stakeholder.archived_with_pap',
                    entity: $stakeholder,
                    summary: "Archived because {$pap->reference} was archived in the PAP register.",
                );
            }

            return $pap->fresh();
        });
    }

    /** Derived location columns and the age rule. */
    private function normalise(Pap $pap): void
    {
        if ($pap->date_of_birth !== null) {
            $pap->stated_age = null; // age is derived from the date of birth
        }

        if ($pap->isDirty('location_id') || ! $pap->exists) {
            $pap->region = $pap->district = $pap->ward = $pap->village = null;

            $cursor = $pap->location_id ? Location::find($pap->location_id) : null;
            $guard = 0;

            while ($cursor && $guard++ < 10) {
                match ($cursor->level) {
                    'region' => $pap->region = $cursor->name,
                    'district' => $pap->district = $cursor->name,
                    'ward' => $pap->ward = $cursor->name,
                    'village' => $pap->village = $cursor->name,
                    default => null,
                };
                $cursor = $cursor->parent;
            }
        }
    }

    /**
     * Create or update the PAP's stakeholder record. Called inside the PAP's
     * own transaction: if the stakeholder write fails, the PAP write does too.
     */
    private function syncStakeholder(Project $project, Pap $pap): void
    {
        $stakeholder = $pap->stakeholder_id ? Stakeholder::acrossTenants()->find($pap->stakeholder_id) : null;

        // A stakeholder officer may have merged the PAP's record into another
        // one; follow the merge so the survivor keeps receiving updates.
        $guard = 0;
        while ($stakeholder?->merged_into_id && $guard++ < 10) {
            $stakeholder = Stakeholder::acrossTenants()->find($stakeholder->merged_into_id);
        }

        $payload = $this->stakeholderPayload($project, $pap, $stakeholder);

        if ($stakeholder === null) {
            $stakeholder = $this->stakeholders->create($project, array_merge(
                $payload,
                $this->options->all($project)['stakeholder_defaults'] ?? [],
                [
                    'notes' => "Created automatically from the PAP register ({$pap->reference}). "
                        .'Name, contact, location and vulnerability are maintained on the PAP record.',
                    'captured_at' => $pap->captured_at,
                ],
            ));
        } else {
            $stakeholder = $this->stakeholders->update($stakeholder, $payload);
        }

        if ((int) $pap->stakeholder_id !== (int) $stakeholder->id) {
            $pap->forceFill(['stakeholder_id' => $stakeholder->id])->saveQuietly();
        }
    }

    /** What crosses into the stakeholder register. Never national_id; never bank details. */
    private function stakeholderPayload(Project $project, Pap $pap, ?Stakeholder $existing): array
    {
        $map = $this->options->all($project)['stakeholder_vulnerability_map'] ?? [];
        $category = $pap->isVulnerable() ? ($map[$pap->vulnerability_category] ?? $pap->vulnerability_category) : null;

        $demographics = array_merge($existing?->demographics ?? [], array_filter([
            'gender' => $pap->gender,
            'age_band' => $this->ageBand($pap->age()),
        ], fn ($value) => $value !== null));

        $address = collect([$pap->physical_address, $pap->kitongoji ? 'Kitongoji '.$pap->kitongoji : null])
            ->filter()->implode(' — ');

        return [
            'name' => $pap->full_name,
            'type' => 'pap',
            'phone' => $pap->phone,
            'alternate_phone' => $pap->alternative_contact ? mb_substr($pap->alternative_contact, 0, 40) : null,
            'physical_address' => $address ?: null,
            'primary_location_id' => $pap->location_id,
            'latitude' => $pap->latitude,
            'longitude' => $pap->longitude,
            'is_vulnerable' => $pap->isVulnerable(),
            'vulnerability_categories' => $category ? [$category] : [],
            'demographics' => $demographics,
            'identification_source' => 'pap_register',
            'identification_method' => 'pap_census',
            'status' => 'active',
        ];
    }

    private function ageBand(?int $age): ?string
    {
        return match (true) {
            $age === null => null,
            $age < 18 => 'under_18',
            $age <= 35 => '18_35',
            $age <= 60 => '36_60',
            default => 'over_60',
        };
    }
}
