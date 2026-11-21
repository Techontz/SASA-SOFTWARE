<?php

namespace App\Http\Controllers\Api\V1\Enquiry;

use App\Domain\Enquiry\EnquiryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Enquiry\UpdateEnquiryRequest;
use App\Http\Resources\Enquiry\EnquiryResource;
use App\Models\Enquiry;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Website enquiries, newest first, for whoever holds enquiry.manage. */
class AdminEnquiryController extends Controller
{
    public function __construct(private readonly EnquiryService $enquiries) {}

    public function index(Request $request)
    {
        $request->validate([
            'type' => ['nullable', 'string', Rule::in(Enquiry::TYPES)],
            'status' => ['nullable', 'string', Rule::in(Enquiry::STATUSES)],
        ]);

        $query = Enquiry::query()
            ->whereNull('archived_at')
            ->with(['handler:id,name', 'latestDemo'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return EnquiryResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    public function update(UpdateEnquiryRequest $request, Enquiry $enquiry)
    {
        abort_if($enquiry->archived_at !== null, 404);

        return ApiResponse::data(new EnquiryResource(
            $this->enquiries->handle($enquiry, $request->user(), $request->validated())
        ));
    }
}
