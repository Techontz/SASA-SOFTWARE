<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\AgreementSummaryResource;
use App\Models\Organisation;
use App\Models\OrganisationAgreement;
use App\Support\ApiResponse;

/** Every customer organisation, its size and its current agreement, for billing.manage. */
class AdminOrganisationController extends Controller
{
    public function index()
    {
        $organisations = Organisation::query()
            ->whereNull('archived_at')
            ->withCount([
                'projects' => fn ($q) => $q->whereNull('archived_at')->where('status', '!=', 'archived'),
                'users' => fn ($q) => $q->whereNull('archived_at')->where('status', 'active'),
            ])
            ->orderBy('name')
            ->get();

        $agreements = OrganisationAgreement::query()
            ->current()
            ->whereIn('organisation_id', $organisations->pluck('id'))
            ->orderBy('id')
            ->get()
            ->keyBy('organisation_id');

        return ApiResponse::data($organisations->map(fn (Organisation $organisation) => [
            'id' => $organisation->id,
            'name' => $organisation->name,
            'projects_count' => (int) $organisation->projects_count,
            'users_count' => (int) $organisation->users_count,
            'agreement' => isset($agreements[$organisation->id]) ? new AgreementSummaryResource($agreements[$organisation->id]) : null,
        ])->values());
    }
}
