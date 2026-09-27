<?php

namespace App\Models\Concerns;

use App\Domain\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes create / update / archive events to the append-only audit log,
 * with before/after values for the fields that actually changed.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            app(AuditLogger::class)->record(
                action: $model->auditName().'.created',
                entity: $model,
                after: $model->auditableAttributes($model->getAttributes()),
            );
        });

        static::updated(function (Model $model) {
            $changed = $model->auditableAttributes($model->getChanges());

            if ($changed === []) {
                return;
            }

            $before = [];
            foreach (array_keys($changed) as $key) {
                $before[$key] = $model->getOriginal($key);
            }

            $action = $model->auditName().'.updated';

            if (array_key_exists('archived_at', $changed) && $model->archived_at !== null) {
                $action = $model->auditName().'.archived';
            } elseif (array_key_exists('status', $changed)) {
                $action = $model->auditName().'.status_changed';
            }

            app(AuditLogger::class)->record(
                action: $action,
                entity: $model,
                before: $model->auditableAttributes($before),
                after: $changed,
            );
        });
    }

    public function auditName(): string
    {
        return defined(static::class.'::AUDIT_NAME')
            ? static::AUDIT_NAME
            : strtolower(class_basename(static::class));
    }

    /** Never write secrets or encrypted identity into the audit payload. */
    public function auditableAttributes(array $attributes): array
    {
        $hidden = array_merge([
            'password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes',
            'complainant_name', 'complainant_phone', 'complainant_email',
            'complainant_address', 'precise_location', 'caller_number',
            'complainant_name_hash', 'complainant_phone_hash', 'caller_number_hash',
            'updated_at', 'created_at',
        ], $this->auditExcluded ?? []);

        return collect($attributes)
            ->except($hidden)
            ->map(fn ($value) => is_string($value) && strlen($value) > 500
                ? substr($value, 0, 500).'…'
                : $value)
            ->all();
    }

    public function auditReference(): ?string
    {
        return $this->reference ?? null;
    }
}
