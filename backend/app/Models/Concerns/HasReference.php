<?php

namespace App\Models\Concerns;

use App\Domain\Tenancy\ReferenceGenerator;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable human-readable identifiers: STK-0001, ENG-0001, GRV-0001.
 * Allocated under a row lock so two field officers syncing at the same moment
 * can never be handed the same number.
 */
trait HasReference
{
    public static function bootHasReference(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->reference) && ! empty($model->project_id)) {
                $model->reference = app(ReferenceGenerator::class)->next(
                    (int) $model->project_id,
                    $model->referenceKey()
                );
            }
        });

        static::updating(function (Model $model) {
            // The reference is part of the audit record. It never changes.
            if ($model->isDirty('reference')) {
                $model->reference = $model->getOriginal('reference');
            }
        });
    }

    public function referenceKey(): string
    {
        return static::REFERENCE_KEY;
    }
}
