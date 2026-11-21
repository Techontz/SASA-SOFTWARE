<?php

namespace App\Http\Controllers\Api\V1\Enquiry;

use App\Domain\Enquiry\EnquiryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Enquiry\StoreEnquiryRequest;
use App\Http\Resources\Enquiry\EnquiryReceiptResource;
use App\Support\ApiResponse;

/** The website's "Register interest" and "Book a demo" forms. No account. */
class PublicEnquiryController extends Controller
{
    public function __construct(private readonly EnquiryService $enquiries) {}

    public function store(StoreEnquiryRequest $request)
    {
        $type = (string) $request->validated('type');
        $enquiry = $this->enquiries->submit($request->validated(), $request->ip());

        // A bot that filled the honeypot is told it worked, and nothing is kept.
        $receipt = $enquiry
            ? ['reference' => $enquiry->reference, 'type' => $enquiry->type]
            : ['reference' => sprintf('ENQ-%s-%04d', now()->format('Y'), random_int(1, 9999)), 'type' => $type];

        return ApiResponse::data(new EnquiryReceiptResource($receipt), [], 201);
    }
}
