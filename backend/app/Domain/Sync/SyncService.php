<?php

namespace App\Domain\Sync;

use App\Domain\Audit\AuditLogger;
use App\Domain\Engagement\CommitmentService;
use App\Domain\Engagement\ConcernService;
use App\Domain\Engagement\EngagementService;
use App\Domain\Grievance\IntakeEngine;
use App\Domain\Notification\NotificationDispatcher;
use App\Domain\Notification\NotificationEvents;
use App\Domain\Stakeholder\StakeholderService;
use App\Models\Commitment;
use App\Models\Concern;
use App\Models\Engagement;
use App\Models\EngagementPlan;
use App\Models\Grievance;
use App\Models\GrievanceFollowUp;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\User;
use App\Support\DomainRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The server half of the offline-first contract.
 *
 * A device queues every offline mutation locally, then replays the queue as a
 * batch when the connection returns. Each operation carries a client-generated
 * UUID which is UNIQUE on the server, so replaying a batch that partly
 * succeeded cannot create duplicates — the already-applied operations return
 * their previous result.
 *
 * Conflict policy is last-write-wins PER FIELD, except for the fields listed
 * in config('sasa.sync.protected_fields'): those never silently overwrite a
 * server change made while the device was offline. They are surfaced as a
 * SyncConflict for a person to resolve, showing both values, who changed the
 * server one, and when.
 */
final class SyncService
{
    /** entity => [model class, whether the client may create it offline] */
    private const ENTITIES = [
        'stakeholder' => [Stakeholder::class, true],
        'engagement_plan' => [EngagementPlan::class, true],
        'engagement' => [Engagement::class, true],
        'concern' => [Concern::class, true],
        'commitment' => [Commitment::class, true],
        'grievance' => [Grievance::class, true],
        'grievance_follow_up' => [GrievanceFollowUp::class, true],
    ];

    public function __construct(
        private readonly StakeholderService $stakeholders,
        private readonly EngagementService $engagements,
        private readonly ConcernService $concerns,
        private readonly CommitmentService $commitments,
        private readonly IntakeEngine $intake,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * Apply a batch. Every operation is processed independently: one bad
     * record never blocks the rest of the queue, and a rejected operation
     * comes back with the reason so the device can show it to the user
     * instead of silently dropping their work.
     *
     * @param  array<int,array<string,mixed>>  $operations
     */
    public function push(Project $project, string $deviceId, array $operations): array
    {
        $max = (int) config('sasa.sync.max_batch_operations', 200);
        $operations = array_slice($operations, 0, $max);
        $results = [];

        foreach ($operations as $operation) {
            $results[] = $this->applyOne($project, $deviceId, $operation);
        }

        return [
            'device_id' => $deviceId,
            'server_time' => now()->toIso8601String(),
            'results' => $results,
            'summary' => [
                'applied' => collect($results)->where('status', 'applied')->count(),
                'duplicate' => collect($results)->where('status', 'duplicate')->count(),
                'conflict' => collect($results)->where('status', 'conflict')->count(),
                'rejected' => collect($results)->where('status', 'rejected')->count(),
            ],
        ];
    }

    private function applyOne(Project $project, string $deviceId, array $operation): array
    {
        $uuid = $operation['operation_uuid'] ?? null;
        $entity = $operation['entity'] ?? null;
        $type = $operation['operation'] ?? 'create';

        if (! $uuid || ! $entity || ! isset(self::ENTITIES[$entity])) {
            return $this->result($uuid, $entity, 'rejected', error: 'This change refers to a record type SASA does not recognise.');
        }

        // Idempotency: the same operation replayed returns its first result.
        $existing = SyncOperation::acrossTenants()->where('operation_uuid', $uuid)->first();

        if ($existing) {
            return $this->result(
                $uuid, $entity,
                $existing->status === 'applied' ? 'duplicate' : $existing->status,
                serverId: $existing->server_entity_id,
                reference: $existing->server_reference,
                error: $existing->error,
            );
        }

        $record = SyncOperation::create([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'user_id' => auth()->id(),
            'operation_uuid' => $uuid,
            'device_id' => $deviceId,
            'entity' => $entity,
            'operation' => $type,
            'entity_uuid' => $operation['entity_uuid'] ?? null,
            'payload' => $operation['payload'] ?? [],
            'client_retry_count' => (int) ($operation['retry_count'] ?? 0),
            'client_created_at' => isset($operation['client_created_at'])
                ? CarbonImmutable::parse($operation['client_created_at'])
                : null,
            'status' => 'accepted',
        ]);

        try {
            return DB::transaction(function () use ($project, $record, $operation, $entity, $type, $deviceId) {
                return $type === 'update'
                    ? $this->applyUpdate($project, $record, $operation, $entity, $deviceId)
                    : $this->applyCreate($project, $record, $operation, $entity);
            });
        } catch (ValidationException $e) {
            $record->forceFill([
                'status' => 'rejected',
                'error' => 'Some of the information in this record is not valid.',
                'validation_errors' => $e->errors(),
                'processed_at' => now(),
            ])->save();

            return $this->result($record->operation_uuid, $entity, 'rejected',
                error: 'Some of the information in this record is not valid.',
                validationErrors: $e->errors());
        } catch (\Throwable $e) {
            Log::error('sync.operation_failed', [
                'operation_uuid' => $record->operation_uuid,
                'entity' => $entity,
                'error' => $e->getMessage(),
            ]);

            $record->forceFill([
                'status' => 'rejected',
                'error' => $e->getMessage(),
                'processed_at' => now(),
            ])->save();

            // A rejected operation stays in the device queue for retry — it is
            // never silently dropped.
            return $this->result($record->operation_uuid, $entity, 'rejected', error: $e->getMessage(), retryable: true);
        }
    }

    private function applyCreate(Project $project, SyncOperation $record, array $operation, string $entity): array
    {
        $payload = $operation['payload'] ?? [];
        $clientUuid = $operation['entity_uuid'] ?? ($payload['client_uuid'] ?? null);
        $payload['client_uuid'] = $clientUuid;
        $payload['captured_at'] = $payload['captured_at'] ?? $operation['client_created_at'] ?? now();

        [$modelClass] = self::ENTITIES[$entity];

        // A second create for the same client UUID is the same record.
        if ($clientUuid) {
            $existing = $modelClass::query()->acrossTenants()
                ->where('project_id', $project->id)
                ->where('client_uuid', $clientUuid)
                ->first();

            if ($existing) {
                $record->forceFill([
                    'status' => 'applied',
                    'server_entity_id' => $existing->getKey(),
                    'server_reference' => $existing->reference ?? null,
                    'processed_at' => now(),
                ])->save();

                return $this->result($record->operation_uuid, $entity, 'duplicate',
                    serverId: $existing->getKey(), reference: $existing->reference ?? null);
            }
        }

        $model = match ($entity) {
            'stakeholder' => $this->stakeholders->create($project, $payload),
            'engagement_plan' => $this->engagements->createPlan($project, $payload),
            'engagement' => $this->engagements->logEngagement($project, $payload),
            'concern' => $this->concerns->create($project, $payload),
            'commitment' => $this->commitments->create($project, $payload),
            'grievance' => $this->intake->intake($project, $payload),
            'grievance_follow_up' => $this->createFollowUp($project, $payload),
        };

        $record->forceFill([
            'status' => 'applied',
            'server_entity_id' => $model->getKey(),
            'server_reference' => $model->reference ?? null,
            'processed_at' => now(),
        ])->save();

        return $this->result($record->operation_uuid, $entity, 'applied',
            serverId: $model->getKey(), reference: $model->reference ?? null, model: $model);
    }

    private function applyUpdate(Project $project, SyncOperation $record, array $operation, string $entity, string $deviceId): array
    {
        [$modelClass] = self::ENTITIES[$entity];
        $payload = $operation['payload'] ?? [];

        $model = $this->locate($modelClass, $project, $operation);

        if (! $model) {
            $record->forceFill([
                'status' => 'rejected',
                'error' => 'That record no longer exists on the server.',
                'processed_at' => now(),
            ])->save();

            return $this->result($record->operation_uuid, $entity, 'rejected',
                error: 'That record no longer exists on the server.');
        }

        $baseUpdatedAt = isset($operation['base_updated_at'])
            ? CarbonImmutable::parse($operation['base_updated_at'])
            : null;

        $serverChangedWhileOffline = $baseUpdatedAt !== null
            && $model->updated_at !== null
            && $model->updated_at->greaterThan($baseUpdatedAt);

        $conflicts = [];
        $safeFields = [];
        $protected = config("sasa.sync.protected_fields.$entity", []);
        $base = $operation['base_values'] ?? [];

        foreach ($payload as $field => $value) {
            if (in_array($field, ['client_uuid', 'captured_at'], true)) {
                continue;
            }

            $serverValue = $model->getAttribute($field);

            // Same value — nothing to reconcile.
            if ($this->valuesMatch($serverValue, $value)) {
                continue;
            }

            /*
             * "Did the server move while the device was away?" is answered by
             * comparing the server's CURRENT value with the value the device
             * last saw — not by timestamps, which have one-second resolution
             * and would miss two changes inside the same second. The timestamp
             * is only the fallback when the device sent no base value.
             */
            $serverMovedToo = array_key_exists($field, $base)
                ? ! $this->valuesMatch($serverValue, $base[$field])
                : $serverChangedWhileOffline;

            if ($serverMovedToo && in_array($field, $protected, true)) {
                $conflicts[$field] = [
                    'local' => $value,
                    'server' => $serverValue instanceof \DateTimeInterface ? $serverValue->toIso8601String() : $serverValue,
                    'base' => $base[$field] ?? null,
                    'server_changed_by' => $this->lastEditor($model),
                    'server_changed_at' => $model->updated_at?->toIso8601String(),
                ];

                continue;
            }

            // Last-write-wins per field for everything else.
            $safeFields[$field] = $value;
        }

        if ($safeFields !== []) {
            $model->forceFill(array_merge($safeFields, ['synced_at' => now()]))->save();
        }

        if ($conflicts !== []) {
            $conflict = SyncConflict::create([
                'organisation_id' => $project->organisation_id,
                'project_id' => $project->id,
                'sync_operation_id' => $record->id,
                'entity' => $entity,
                'entity_id' => $model->getKey(),
                'entity_reference' => $model->reference ?? null,
                'conflicting_fields' => $conflicts,
                'device_id' => $deviceId,
                'raised_by' => auth()->id(),
                'status' => 'open',
            ]);

            $record->forceFill([
                'status' => 'conflict',
                'server_entity_id' => $model->getKey(),
                'server_reference' => $model->reference ?? null,
                'processed_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'sync.conflict_raised',
                entity: $model,
                before: collect($conflicts)->map(fn ($c) => $c['server'])->all(),
                after: collect($conflicts)->map(fn ($c) => $c['local'])->all(),
                summary: 'An offline change conflicts with a change made on the server',
            );

            $this->notifications->dispatch(
                eventKey: NotificationEvents::SYNC_CONFLICT_RAISED,
                project: $project,
                payload: [
                    'entity_reference' => $model->reference ?? $entity,
                    'url' => '/sync/conflicts/'.$conflict->id,
                ],
                severity: 'warning',
            );

            return $this->result($record->operation_uuid, $entity, 'conflict',
                serverId: $model->getKey(), reference: $model->reference ?? null,
                conflicts: $conflicts, conflictId: $conflict->id, model: $model->fresh());
        }

        $record->forceFill([
            'status' => 'applied',
            'server_entity_id' => $model->getKey(),
            'server_reference' => $model->reference ?? null,
            'processed_at' => now(),
        ])->save();

        return $this->result($record->operation_uuid, $entity, 'applied',
            serverId: $model->getKey(), reference: $model->reference ?? null, model: $model->fresh());
    }

    /**
     * Everything on this project the device has not seen since `since`.
     * Cursor-based so a partial download resumes rather than restarting.
     */
    public function pull(Project $project, ?CarbonImmutable $since, int $limit = 500): array
    {
        $since ??= CarbonImmutable::now()->subDays(90);
        $data = [];

        foreach (self::ENTITIES as $entity => [$modelClass]) {
            $query = $modelClass::query()->acrossTenants()
                ->where('project_id', $project->id)
                ->where('updated_at', '>', $since)
                ->orderBy('updated_at')
                ->limit($limit);

            $data[$entity] = $query->get()->map(fn (Model $model) => $this->serialiseForDevice($model))->all();
        }

        $latest = collect($data)->flatten(1)->pluck('updated_at')->filter()->max();

        return [
            'since' => $since->toIso8601String(),
            'cursor' => $latest ?: now()->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'complete' => collect($data)->every(fn ($rows) => count($rows) < $limit),
            'data' => $data,
        ];
    }

    private function serialiseForDevice(Model $model): array
    {
        $attributes = $model->attributesToArray();

        // Identity on a confidential case is never cached on a device.
        if ($model instanceof Grievance && ($model->isConfidential() || $model->isAnonymous())) {
            foreach (Grievance::SENSITIVE_FIELDS as $field) {
                unset($attributes[$field]);
            }

            $attributes['identity_withheld'] = true;
        }

        return $attributes;
    }

    private function locate(string $modelClass, Project $project, array $operation): ?Model
    {
        $query = $modelClass::query()->acrossTenants()->where('project_id', $project->id);

        if (! empty($operation['server_id'])) {
            return (clone $query)->find($operation['server_id']);
        }

        if (! empty($operation['entity_uuid'])) {
            return (clone $query)->where('client_uuid', $operation['entity_uuid'])->first();
        }

        return null;
    }

    private function createFollowUp(Project $project, array $payload): GrievanceFollowUp
    {
        return GrievanceFollowUp::create(array_merge(
            collect($payload)->only(['grievance_id', 'type', 'body', 'is_sensitive', 'occurred_on', 'client_uuid', 'captured_at'])->all(),
            [
                'organisation_id' => $project->organisation_id,
                'project_id' => $project->id,
                'created_by' => auth()->id(),
                'synced_at' => now(),
            ]
        ));
    }

    private function lastEditor(Model $model): ?string
    {
        $userId = $model->getAttribute('updated_by');

        return $userId ? User::find($userId)?->name : null;
    }

    private function valuesMatch(mixed $a, mixed $b): bool
    {
        if ($a instanceof \DateTimeInterface) {
            $a = $a->format('c');
        }

        if ($b instanceof \DateTimeInterface) {
            $b = $b->format('c');
        }

        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        return (string) $a === (string) $b;
    }

    private function result(
        ?string $uuid,
        ?string $entity,
        string $status,
        ?int $serverId = null,
        ?string $reference = null,
        ?string $error = null,
        array $conflicts = [],
        ?int $conflictId = null,
        array $validationErrors = [],
        bool $retryable = false,
        ?Model $model = null,
    ): array {
        return array_filter([
            'operation_uuid' => $uuid,
            'entity' => $entity,
            'status' => $status,
            'server_id' => $serverId,
            'reference' => $reference,
            'error' => $error,
            'retryable' => $retryable ?: null,
            'conflicts' => $conflicts ?: null,
            'conflict_id' => $conflictId,
            'validation_errors' => $validationErrors ?: null,
            'record' => $model?->attributesToArray(),
        ], fn ($value) => $value !== null);
    }

    /** A person chooses which version is correct. */
    public function resolveConflict(SyncConflict $conflict, string $resolution, array $values = []): SyncConflict
    {
        [$modelClass] = self::ENTITIES[$conflict->entity] ?? [null];

        if (! $modelClass) {
            throw new DomainRuleException('Unknown record type.', 'unknown_entity');
        }

        return DB::transaction(function () use ($conflict, $resolution, $values, $modelClass) {
            $model = $modelClass::query()->acrossTenants()->find($conflict->entity_id);

            if ($model) {
                $applied = match ($resolution) {
                    'keep_local' => collect($conflict->conflicting_fields)->map(fn ($c) => $c['local'])->all(),
                    'keep_server' => [],
                    'merged' => $values,
                    default => [],
                };

                if ($applied !== []) {
                    $model->forceFill($applied)->save();
                }
            }

            $conflict->forceFill([
                'status' => 'resolved',
                'resolution' => $resolution,
                'resolved_values' => $values ?: null,
                'resolved_by' => auth()->id(),
                'resolved_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'sync.conflict_resolved',
                entity: $model,
                after: ['resolution' => $resolution, 'values' => $values],
                summary: 'Sync conflict resolved by keeping the '.str_replace('keep_', '', $resolution).' version',
            );

            return $conflict->fresh();
        });
    }
}
