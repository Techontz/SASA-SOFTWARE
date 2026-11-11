<?php

namespace App\Http\Resources\Pap;

use App\Domain\Tenancy\TenantContext;

/**
 * One answer to "may this viewer receive National ID, bank account and
 * mobile money numbers?" — used by every PAP resource and the export.
 */
final class PapSensitivity
{
    public static function canSee(): bool
    {
        $context = app(TenantContext::class);

        return $context->isSystemAdmin() || $context->can('pap.view_sensitive');
    }
}
