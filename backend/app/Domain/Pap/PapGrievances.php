<?php

namespace App\Domain\Pap;

use App\Domain\Grievance\GrievanceVisibility;
use App\Domain\Tenancy\TenantContext;
use App\Models\Grievance;
use App\Models\Pap;

/**
 * Part K. Grievances are NOT a second system here: a PAP's grievances are the
 * grievance module's cases linked to the PAP's stakeholder record.
 *
 * Linking a case to a named person reveals who complained, so a confidential
 * case is listed only to viewers who may see its complainant identity, and a
 * restricted category never appears outside its handling group.
 */
final class PapGrievances
{
    public function __construct(
        private readonly GrievanceVisibility $visibility,
        private readonly TenantContext $context,
    ) {}

    /** @return array{can_view:bool,data:array<int,array<string,mixed>>} */
    public function forPap(Pap $pap): array
    {
        if (! $this->context->isSystemAdmin() && ! $this->context->can('grievance.view')) {
            return ['can_view' => false, 'data' => []];
        }

        if (! $pap->stakeholder_id) {
            return ['can_view' => true, 'data' => []];
        }

        $cases = $this->visibility->scopeVisible(
            Grievance::query()
                ->where('project_id', $pap->project_id)
                ->where('stakeholder_id', $pap->stakeholder_id)
                ->whereNull('archived_at')
        )
            ->with(['category:id,name', 'subcategory:id,name', 'assignee:id,name'])
            ->orderByDesc('received_at')
            ->limit(100)
            ->get()
            ->filter(fn (Grievance $grievance) => $this->visibility->canSeeIdentity($grievance))
            ->map(fn (Grievance $grievance) => [
                'id' => $grievance->id,
                'reference' => $grievance->reference,
                'title' => $grievance->title,
                'date_logged' => $grievance->received_at?->toIso8601String(),
                'category' => $grievance->subcategory?->name ?? $grievance->category?->name,
                'status' => $grievance->status,
                'status_label' => ucfirst(str_replace('_', ' ', (string) $grievance->status)),
                'is_open' => $grievance->isOpen(),
                'assigned_officer' => $grievance->assignee?->name,
                'investigation_findings' => $grievance->investigation_findings,
                'resolution_agreed' => $grievance->resolution_summary,
                'resolution_date' => $grievance->resolved_at?->toIso8601String(),
                'closure_date' => $grievance->closed_at?->toIso8601String(),
                'comments' => $grievance->closure_notes,
                'href' => "/grievances/{$grievance->id}",
            ])
            ->values()
            ->all();

        return ['can_view' => true, 'data' => $cases];
    }
}
