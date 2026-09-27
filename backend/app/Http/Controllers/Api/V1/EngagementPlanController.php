<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\EngagementService;
use App\Http\Controllers\Controller;
use App\Http\Resources\EngagementPlanResource;
use App\Models\EngagementPlan;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EngagementPlanController extends Controller
{
    public function __construct(private readonly EngagementService $engagements) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', EngagementPlan::class);

        $query = $this->buildQuery($request)
            ->with(['stakeholder:id,reference,name', 'owner:id,name', 'location:id,name,path'])
            ->withCount('engagements');

        QueryFilters::sort($query, $request, ['target_date', 'reference', 'status', 'priority', 'created_at'], 'target_date');

        return EngagementPlanResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        )->additional(['meta' => ['summary' => $this->summary($request)]]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', EngagementPlan::class);

        $plan = $this->engagements->createPlan($this->project(), $this->validated($request));

        return ApiResponse::data(new EngagementPlanResource($plan), [], 201);
    }

    public function show(EngagementPlan $engagementPlan)
    {
        $this->authorize('view', $engagementPlan);

        $engagementPlan->load(['stakeholder', 'owner:id,name', 'location', 'engagements', 'attachments']);

        return ApiResponse::data(new EngagementPlanResource($engagementPlan));
    }

    public function update(Request $request, EngagementPlan $engagementPlan)
    {
        $this->authorize('update', $engagementPlan);

        $engagementPlan->update($this->validated($request, updating: true));

        return ApiResponse::data(new EngagementPlanResource(
            $engagementPlan->fresh(['stakeholder', 'owner', 'location'])
        ));
    }

    /** Rescheduling and cancelling keep the plan and the reason on the record. */
    public function changeStatus(Request $request, EngagementPlan $engagementPlan)
    {
        $this->authorize('update', $engagementPlan);

        $data = $request->validate([
            'status' => ['required', Rule::in(EngagementPlan::STATUSES)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'target_date' => ['nullable', 'date'],
        ]);

        $engagementPlan->update([
            'status' => $data['status'],
            'status_reason' => $data['reason'] ?? $engagementPlan->status_reason,
            'target_date' => $data['target_date'] ?? $engagementPlan->target_date,
        ]);

        return ApiResponse::data(new EngagementPlanResource($engagementPlan->fresh()));
    }

    public function archive(EngagementPlan $engagementPlan)
    {
        $this->authorize('archive', $engagementPlan);

        $engagementPlan->archive();

        return ApiResponse::message("{$engagementPlan->reference} has been archived.");
    }

    /** Calendar feed for the month view on the engagement screen. */
    public function calendar(Request $request)
    {
        $this->authorize('viewAny', EngagementPlan::class);

        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now()->endOfMonth()->addMonths(2);

        $plans = EngagementPlan::query()
            ->where('project_id', $this->project()->id)
            ->whereNull('archived_at')
            ->whereBetween('target_date', [$from, $to])
            ->with(['stakeholder:id,name', 'owner:id,name', 'location:id,path'])
            ->orderBy('target_date')
            ->get()
            ->map(fn (EngagementPlan $plan) => [
                'id' => $plan->id,
                'reference' => $plan->reference,
                'title' => $plan->title,
                'date' => $plan->target_date?->toDateString(),
                'status' => $plan->status,
                'priority' => $plan->priority,
                'method' => $plan->method,
                'stakeholder' => $plan->stakeholder?->name ?? $plan->stakeholder_group,
                'location' => $plan->location?->path ?? $plan->location_text,
                'owner' => $plan->owner?->name,
                'href' => "/engagements/plans/{$plan->id}",
            ]);

        return ApiResponse::data($plans, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'title' => [$required, 'string', 'max:255'],
            'project_phase' => ['nullable', 'string', 'max:60'],
            'stakeholder_id' => ['nullable', 'integer', 'exists:stakeholders,id'],
            'stakeholder_group' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'method' => ['nullable', 'string', 'max:60'],
            'target_date' => [$required, 'date'],
            'window_start' => ['nullable', 'date'],
            'window_end' => ['nullable', 'date', 'after_or_equal:window_start'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'vulnerable_group_accommodation' => ['boolean'],
            'accommodation_notes' => ['nullable', 'string', 'max:2000'],
            'fpic_required' => ['boolean'],
            'fpic_notes' => ['nullable', 'string', 'max:2000'],
            'grievance_channel_available' => ['boolean'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'responsible_team' => ['nullable', 'string', 'max:60'],
            'priority' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'recurrence' => ['nullable', Rule::in(['once', 'weekly', 'monthly', 'quarterly', 'semi_annual', 'annual'])],
            'recurrence_until' => ['nullable', 'date', 'after:target_date'],
            'status' => ['nullable', Rule::in(EngagementPlan::STATUSES)],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'budget_currency' => ['nullable', 'string', 'size:3'],
            'resources_required' => ['nullable', 'string', 'max:2000'],
            'custom_fields' => ['nullable', 'array'],
            'captured_at' => ['nullable', 'date'],
        ], [
            'title.required' => 'Give the planned engagement a short title.',
            'target_date.required' => 'When is this engagement planned for?',
        ]);
    }

    private function buildQuery(Request $request)
    {
        $query = EngagementPlan::query()->where('project_id', $this->project()->id);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        QueryFilters::apply($query, $request, [
            'status', 'priority', 'method', 'project_phase', 'owner_id', 'stakeholder_id',
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v),
            'search' => fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%$v%")
                ->orWhere('reference', 'like', "%$v%")
                ->orWhere('purpose', 'like', "%$v%")),
            'upcoming' => fn ($q, $v) => $q->upcoming((int) $v),
            'missed' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->where('status', 'missed') : $q,
        ]);

        QueryFilters::dateRange($query, $request, 'target_date');

        return $query;
    }

    private function summary(Request $request): array
    {
        $request = QueryFilters::without($request, ['status', 'priority']);
        $base = fn () => $this->buildQuery($request);

        return [
            'total' => $base()->count(),
            'planned' => $base()->where('status', 'planned')->count(),
            'completed' => $base()->where('status', 'completed')->count(),
            'missed' => $base()->where('status', 'missed')->count(),
            'upcoming_30_days' => $base()->upcoming(30)->count(),
        ];
    }
}
