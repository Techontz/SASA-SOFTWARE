<?php

namespace App\Http\Controllers\Api\V1\Demo;

use App\Domain\Demo\DemoService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Demo\ExtendDemoRequest;
use App\Http\Requests\Demo\StoreDemoRequest;
use App\Http\Resources\Demo\DemoResource;
use App\Models\Demo;
use App\Models\Enquiry;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Demo workspaces, for whoever holds enquiry.manage. Addressed by public id. */
class AdminDemoController extends Controller
{
    public function __construct(private readonly DemoService $demos) {}

    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'string', Rule::in(Demo::STATUSES)]]);

        $query = Demo::query()
            ->current()
            ->with(['organisation:id,name', 'project:id,name,code', 'user:id,name,email', 'enquiry:id,public_id,reference'])
            ->when($request->filled('status'), function ($q) use ($request) {
                // "expired" includes active demos whose end has passed but demos:expire has not yet marked.
                match ($request->input('status')) {
                    'active' => $q->where('status', 'active')->where('ends_at', '>', now()),
                    'expired' => $q->where(fn ($w) => $w->where('status', 'expired')
                        ->orWhere(fn ($a) => $a->where('status', 'active')->where('ends_at', '<=', now()))),
                    default => $q->where('status', $request->input('status')),
                };
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return DemoResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        );
    }

    public function store(StoreDemoRequest $request, Enquiry $enquiry)
    {
        abort_if($enquiry->archived_at !== null, 404);

        $result = $this->demos->provision($enquiry, $request->user(), $request->validated());

        return ApiResponse::data(
            (new DemoResource($result['demo']))->withInvite($result['invite_url'], $result['email_sent']),
            [],
            201
        );
    }

    public function extend(ExtendDemoRequest $request, Demo $demo)
    {
        abort_if($demo->archived_at !== null, 404);

        return ApiResponse::data(new DemoResource($this->demos->extend($demo, (int) $request->validated('days'))));
    }

    public function end(Demo $demo)
    {
        abort_if($demo->archived_at !== null, 404);

        return ApiResponse::data(new DemoResource($this->demos->end($demo)));
    }

    public function resendInvite(Demo $demo)
    {
        abort_if($demo->archived_at !== null, 404);

        $result = $this->demos->resendInvite($demo);

        return ApiResponse::data((new DemoResource($result['demo']))->withInvite($result['invite_url'], $result['email_sent']));
    }
}
