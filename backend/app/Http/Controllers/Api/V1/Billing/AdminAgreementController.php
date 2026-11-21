<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\AgreementService;
use App\Domain\Billing\InvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AgreementRequest;
use App\Http\Resources\Billing\AdminInvoiceResource;
use App\Http\Resources\Billing\AgreementResource;
use App\Models\OrganisationAgreement;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Agreed plans, for whoever holds billing.manage. Addressed by public id. */
class AdminAgreementController extends Controller
{
    public function __construct(
        private readonly AgreementService $agreements,
        private readonly InvoiceService $invoices,
    ) {}

    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'string', Rule::in(OrganisationAgreement::STATUSES)]]);

        $query = OrganisationAgreement::query()
            ->whereNull('archived_at')
            ->with(['organisation:id,name', 'creator:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return AgreementResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    public function store(AgreementRequest $request)
    {
        $agreement = $this->agreements->create($request->validated(), $request->user());

        return ApiResponse::data(new AgreementResource($agreement->load('creator:id,name')), [], 201);
    }

    public function update(AgreementRequest $request, OrganisationAgreement $agreement)
    {
        abort_if($agreement->archived_at !== null, 404);

        $agreement = $this->agreements->update($agreement, $request->validated());

        return ApiResponse::data(new AgreementResource($agreement->load('creator:id,name')));
    }

    /** Issue the next period's invoice now, exactly as the billing cycle would. */
    public function issueInvoice(OrganisationAgreement $agreement)
    {
        abort_if($agreement->archived_at !== null, 404);

        return ApiResponse::data(new AdminInvoiceResource($this->invoices->issueNow($agreement)), [], 201);
    }
}
