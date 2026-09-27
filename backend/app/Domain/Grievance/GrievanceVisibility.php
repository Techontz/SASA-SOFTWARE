<?php

namespace App\Domain\Grievance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\Grievance;
use Illuminate\Database\Eloquent\Builder;

/**
 * Field-level confidentiality, enforced at the API.
 *
 *  - CONFIDENTIAL: complainant name, contact and precise location are returned
 *    ONLY to the handling group. Everyone else receives the case with those
 *    fields genuinely ABSENT — not blanked in the browser.
 *  - RESTRICTED CATEGORY (SEA/SH, Retaliation, Ethics & Compliance): the case
 *    itself is invisible outside the handling group. Access from outside is
 *    impossible rather than logged; access from inside is a sensitive-view
 *    audit event.
 *  - ANONYMOUS: there is no identity stored, so there is nothing to leak.
 */
final class GrievanceVisibility
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** Removes restricted cases from a query entirely. */
    public function scopeVisible(Builder $query): Builder
    {
        if ($this->context->isSystemAdmin() || $this->context->can('grievance.view_restricted')) {
            return $query;
        }

        $groups = $this->context->handlingGroups();

        return $query->where(function (Builder $q) use ($groups) {
            $q->where('is_restricted', false);

            foreach ($groups as $group) {
                // JSON_CONTAINS on the case's own handling group list.
                $q->orWhereJsonContains('handling_groups', $group);
            }
        });
    }

    public function canSeeCase(Grievance $grievance): bool
    {
        if ($this->context->isSystemAdmin() || $this->context->can('grievance.view_restricted')) {
            return true;
        }

        if (! $grievance->is_restricted) {
            return true;
        }

        return $this->context->inHandlingGroup($grievance->handling_groups);
    }

    /** May this user receive complainant identity and precise location? */
    public function canSeeIdentity(Grievance $grievance): bool
    {
        if ($grievance->isAnonymous()) {
            return false; // Nothing exists to release.
        }

        if ($this->context->isSystemAdmin()) {
            return true;
        }

        if (! $grievance->isConfidential()) {
            return $this->context->can('grievance.view');
        }

        if (! $this->context->can('grievance.view_confidential')) {
            return false;
        }

        return $this->context->inHandlingGroup($grievance->handling_groups);
    }

    /**
     * Called when identity fields are actually serialised into a response.
     * "Who read this confidential case" is the question an investigation asks.
     */
    public function recordSensitiveView(Grievance $grievance): void
    {
        if (! $grievance->isConfidential()) {
            return;
        }

        $this->audit->record(
            action: 'grievance.sensitive_view',
            entity: $grievance,
            summary: 'Complainant identity released to an authorised handler',
            context: ['confidentiality' => $grievance->confidentiality],
            sensitiveView: true,
        );
    }

    /** Exports strip identity for anyone outside the handling group. */
    public function exportStripsIdentity(): bool
    {
        return ! $this->context->can('grievance.view_confidential');
    }
}
