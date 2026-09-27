<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The append-only audit trail.
 *
 * Everything material lands here: creates, updates, archives, assignments,
 * status changes, exports, downloads, sensitive views, logins, permission and
 * configuration changes, imports and report generation.
 */
final class AuditLogger
{
    public function __construct(private readonly TenantContext $context) {}

    public function record(
        string $action,
        ?Model $entity = null,
        array $before = [],
        array $after = [],
        ?string $summary = null,
        array $context = [],
        bool $sensitiveView = false,
        ?int $projectId = null,
        ?int $organisationId = null,
    ): ?AuditLog {
        $request = request();
        $user = $this->context->user() ?? auth()->user();

        try {
            return AuditLog::create([
                'organisation_id' => $organisationId
                    ?? $entity?->organisation_id
                    ?? $this->context->organisationId(),
                'project_id' => $projectId
                    ?? $entity?->project_id
                    ?? $this->context->projectId(),
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'action' => $action,
                'entity_type' => $entity ? class_basename($entity) : null,
                'entity_id' => $entity?->getKey(),
                'entity_reference' => ($entity && method_exists($entity, 'auditReference')) ? $entity->auditReference() : null,
                'summary' => $summary,
                'before' => $before ?: null,
                'after' => $after ?: null,
                'context' => $context ?: null,
                'is_sensitive_view' => $sensitiveView,
                'ip_address' => $request?->ip(),
                'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
                'device_id' => $request?->attributes->get('sasa_device_id') ?: null,
                'request_id' => $request?->attributes->get('sasa_request_id'),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // An audit write must never take down the user's action, but a
            // failure to audit is itself an incident.
            Log::error('audit.write_failed', [
                'action' => $action,
                'entity' => $entity ? class_basename($entity).'#'.$entity->getKey() : null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** "Who read this confidential case" is the question an investigation asks. */
    public function sensitiveView(Model $entity, string $reason = 'viewed_sensitive_fields'): void
    {
        $this->record(
            action: strtolower(class_basename($entity)).'.sensitive_view',
            entity: $entity,
            summary: $reason,
            sensitiveView: true,
        );
    }
}
