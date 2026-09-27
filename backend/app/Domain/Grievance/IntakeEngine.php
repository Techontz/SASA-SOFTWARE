<?php

namespace App\Domain\Grievance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Notification\NotificationDispatcher;
use App\Domain\Notification\NotificationEvents;
use App\Domain\Sla\SlaEngine;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\GrievanceResolutionCycle;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ONE intake engine for every channel.
 *
 *   VOICE · WHATSAPP · SMS · WEB · IN-PERSON · E-MAIL · LEADER · SUGGESTION BOX
 *                              ↓
 *                    normalise · deduplicate · attach media · stamp channel
 *                              ↓
 *                        UNIFIED CASE (GRV-000n)
 *
 * The channel is recorded, but it changes nothing about how the case is
 * worked — only how it arrived and how the complainant is contacted back.
 */
final class IntakeEngine
{
    public function __construct(
        private readonly SlaEngine $sla,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Idempotent by (project, idempotency_key) and by (project, client_uuid),
     * so a retried webhook or a replayed offline batch cannot create a second
     * case for the same complaint.
     */
    public function intake(Project $project, array $data): Grievance
    {
        $existing = $this->findExisting($project, $data);

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($project, $data) {
            $grievance = new Grievance;
            $grievance->organisation_id = $project->organisation_id;
            $grievance->project_id = $project->id;
            $grievance->fill($this->normalise($data));
            $grievance->received_at = $data['received_at'] ?? now();
            $grievance->status = 'new';
            $grievance->captured_at = $data['captured_at'] ?? now();
            $grievance->synced_at = now();
            $grievance->received_by = $data['received_by'] ?? auth()->id();

            $this->applyCategoryRestrictions($grievance);
            $this->applyAcknowledgementCapability($grievance);

            $grievance->save();

            GrievanceResolutionCycle::create([
                'grievance_id' => $grievance->id,
                'cycle_number' => 1,
                'opened_at' => $grievance->received_at,
            ]);

            // The acknowledgement clock starts the moment the case exists;
            // the resolution clock starts too, so a case that is never
            // classified still shows as ageing rather than as invisible.
            $this->sla->start($grievance, 'acknowledgement', CarbonImmutable::parse($grievance->received_at));
            $this->sla->start($grievance, 'resolution', CarbonImmutable::parse($grievance->received_at));
            $this->refreshSlaSnapshot($grievance);

            $this->audit->record(
                action: 'grievance.created',
                entity: $grievance,
                after: [
                    'channel' => $grievance->channel,
                    'confidentiality' => $grievance->confidentiality,
                    'severity' => $grievance->severity,
                ],
                summary: "Case logged via {$grievance->channel}",
            );

            $this->notifications->dispatch(
                eventKey: NotificationEvents::GRIEVANCE_CREATED,
                project: $project,
                payload: $this->notificationPayload($grievance),
            );

            if ($grievance->severity >= config('sasa.grievances.management_alert_from_severity', 4)) {
                $this->notifications->dispatch(
                    eventKey: NotificationEvents::GRIEVANCE_HIGH_SEVERITY,
                    project: $project,
                    payload: $this->notificationPayload($grievance),
                    severity: 'danger',
                );
            }

            return $grievance->fresh(['category', 'subcategory', 'location', 'stakeholder']);
        });
    }

    private function findExisting(Project $project, array $data): ?Grievance
    {
        $query = Grievance::query()->acrossTenants()->where('project_id', $project->id);

        if (! empty($data['idempotency_key'])) {
            $found = (clone $query)->where('idempotency_key', $data['idempotency_key'])->first();

            if ($found) {
                return $found;
            }
        }

        if (! empty($data['client_uuid'])) {
            return (clone $query)->where('client_uuid', $data['client_uuid'])->first();
        }

        return null;
    }

    private function normalise(array $data): array
    {
        $fields = collect($data)->only([
            'client_uuid', 'idempotency_key', 'channel', 'channel_reference', 'occurred_at',
            'confidentiality', 'complainant_type', 'complainant_name', 'complainant_phone',
            'complainant_email', 'complainant_address', 'complainant_language',
            'preferred_contact_method', 'stakeholder_id', 'location_id', 'location_text',
            'precise_location', 'latitude', 'longitude', 'category_id', 'subcategory_id',
            'severity', 'title', 'description', 'desired_resolution', 'demographics',
            'custom_fields', 'source_concern_id', 'voice_call_id',
        ])->all();

        $fields['channel'] = in_array($data['channel'] ?? '', Grievance::CHANNELS, true)
            ? $data['channel']
            : 'web';

        $fields['confidentiality'] = in_array($data['confidentiality'] ?? '', ['normal', 'confidential', 'anonymous'], true)
            ? $data['confidentiality']
            : 'normal';

        $fields['title'] = trim((string) ($data['title'] ?? '')) !== ''
            ? $data['title']
            : mb_substr(trim((string) ($data['description'] ?? 'Grievance')), 0, 120);

        if (! empty($fields['complainant_phone'])) {
            $fields['complainant_phone'] = trim($fields['complainant_phone']);
        }

        return $fields;
    }

    /**
     * A restricted category makes the whole case restricted and pins it to the
     * category's handling group. This is a configuration flag on the category,
     * not hard-coded behaviour.
     */
    public function applyCategoryRestrictions(Grievance $grievance): void
    {
        $category = $grievance->subcategory_id
            ? GrievanceCategory::with('parent')->find($grievance->subcategory_id)
            : ($grievance->category_id ? GrievanceCategory::find($grievance->category_id) : null);

        if (! $category) {
            return;
        }

        if ($category->isEffectivelyRestricted()) {
            $grievance->is_restricted = true;
            $grievance->handling_groups = $category->effectiveHandlingGroups() ?? ['restricted_handling'];

            if ($grievance->confidentiality === 'normal') {
                $grievance->confidentiality = 'confidential';
            }
        }

        if (! $grievance->severity && $category->default_severity) {
            $grievance->severity = $category->default_severity;
        }
    }

    /**
     * Where a channel cannot carry an acknowledgement (suggestion box,
     * anonymous with no contact), record that acknowledgement was NOT POSSIBLE
     * rather than leaving the field blank — otherwise timeliness statistics
     * silently degrade.
     */
    private function applyAcknowledgementCapability(Grievance $grievance): void
    {
        $unreachableChannel = in_array($grievance->channel, ['suggestion_box'], true);
        $noContact = empty($grievance->complainant_phone)
            && empty($grievance->complainant_email)
            && $grievance->confidentiality === 'anonymous';

        if ($unreachableChannel || $noContact) {
            $grievance->acknowledgement_possible = false;
            $grievance->acknowledgement_not_possible_reason = $unreachableChannel
                ? 'The case arrived through a channel with no return address.'
                : 'The complainant chose to remain anonymous and left no contact details.';
        }
    }

    public function refreshSlaSnapshot(Grievance $grievance): void
    {
        $acknowledgement = $this->sla->find($grievance, 'acknowledgement');
        $resolution = $this->sla->find($grievance, 'resolution');

        $grievance->forceFill([
            'acknowledgement_due_at' => $acknowledgement?->due_at,
            'acknowledgement_sla_state' => $acknowledgement?->state,
            'resolution_due_at' => $resolution?->due_at,
            'resolution_sla_state' => $resolution?->state,
        ])->save();
    }

    public function notificationPayload(Grievance $grievance): array
    {
        return [
            'reference' => $grievance->reference,
            'title' => $grievance->title,
            'channel' => str_replace('_', ' ', $grievance->channel),
            'project' => $grievance->project?->name,
            'severity' => $grievance->severity,
            'severity_label' => $grievance->severityLabel(),
            'acknowledgement_due' => $grievance->acknowledgement_due_at?->toDayDateTimeString() ?? 'not set',
            'url' => "/grievances/{$grievance->id}",
        ];
    }
}
