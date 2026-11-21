<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Domain\Billing\InvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\MarkInvoicePaidRequest;
use App\Http\Requests\Billing\VoidInvoiceRequest;
use App\Http\Resources\Billing\AdminInvoiceResource;
use App\Models\Invoice;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Every organisation's invoices, for whoever holds billing.manage. */
class AdminInvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request)
    {
        $request->validate([
            'organisation_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(Invoice::STATUSES)],
        ]);

        $query = Invoice::query()
            ->with(['organisation:id,name', 'agreement:id,public_id,name', 'latestPayment'])
            ->when($request->filled('organisation_id'), fn ($q) => $q->where('organisation_id', (int) $request->input('organisation_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('period_start')
            ->orderByDesc('id');

        return AdminInvoiceResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    public function markPaid(MarkInvoicePaidRequest $request, Invoice $invoice)
    {
        return ApiResponse::data(new AdminInvoiceResource(
            $this->invoices->markPaid($invoice, $request->user(), $request->validated('note'))
        ));
    }

    public function void(VoidInvoiceRequest $request, Invoice $invoice)
    {
        return ApiResponse::data(new AdminInvoiceResource(
            $this->invoices->void($invoice, $request->user(), (string) $request->validated('reason'))
        ));
    }
}
