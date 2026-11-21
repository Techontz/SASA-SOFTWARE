<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\AgreementService;
use App\Domain\Billing\InvoiceService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AutoPayRequest;
use App\Http\Requests\Billing\PayRequest;
use App\Http\Resources\Billing\CustomerAgreementResource;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\OrganisationRoleResource;
use App\Http\Resources\Billing\PaymentResource;
use App\Models\OrganisationAgreement;
use App\Models\Role;
use App\Support\ApiResponse;

/**
 * An organisation's own plan, invoices and roles, for its billing users
 * (organisation.billing). The organisation is ALWAYS the current project's
 * organisation, from TenantContext; nothing in the request can name another.
 */
class OrganisationBillingController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AgreementService $agreements,
        private readonly InvoiceService $invoices,
    ) {}

    public function agreement()
    {
        $agreement = $this->agreements->currentFor($this->organisationId());

        return ApiResponse::data($agreement ? $this->customerAgreement($agreement) : null);
    }

    public function invoices()
    {
        return ApiResponse::data(InvoiceResource::collection($this->invoices->forOrganisation($this->organisationId())));
    }

    public function pay(PayRequest $request, string $invoice)
    {
        $payment = $this->invoices->pay(
            $this->organisationId(),
            $invoice,
            (string) $request->validated('method'),
            $request->validated('phone'),
            $request->user(),
        );

        return ApiResponse::data(new PaymentResource($payment), [], 201);
    }

    public function autoPay(AutoPayRequest $request)
    {
        $agreement = $this->agreements->currentFor($this->organisationId());
        abort_if($agreement === null, 404, 'Your organisation has no agreed plan yet.');

        $agreement = $this->agreements->setAutoPay($agreement, (bool) $request->validated('auto_pay'), $request->validated('phone'));

        return ApiResponse::data($this->customerAgreement($agreement));
    }

    /** The roles on the organisation's projects and what each can do. Read-only. */
    public function roles()
    {
        $roles = Role::query()
            ->where(fn ($q) => $q->where('organisation_id', $this->organisationId())->orWhereNull('organisation_id'))
            ->where('key', '!=', 'system_administrator') // runs the SASA platform, not a customer role
            ->with('permissions:id,key,name,group')
            ->orderBy('escalation_rank')
            ->orderBy('name')
            ->get();

        return ApiResponse::data(OrganisationRoleResource::collection($roles));
    }

    private function organisationId(): int
    {
        return (int) $this->context->requireProject()->organisation_id;
    }

    private function customerAgreement(OrganisationAgreement $agreement): CustomerAgreementResource
    {
        return new CustomerAgreementResource([
            'agreement' => $agreement,
            'usage' => $this->agreements->usage($agreement->organisation_id),
        ]);
    }
}
