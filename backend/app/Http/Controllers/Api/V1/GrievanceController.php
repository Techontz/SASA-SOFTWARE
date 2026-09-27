<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\AiClassificationService;
use App\Domain\Export\ExportService;
use App\Domain\Grievance\GrievanceService;
use App\Domain\Grievance\GrievanceVisibility;
use App\Domain\Grievance\IntakeEngine;
use App\Http\Controllers\Controller;
use App\Http\Resources\GrievanceFollowUpResource;
use App\Http\Resources\GrievanceResource;
use App\Models\Grievance;
use App\Models\GrievanceFollowUp;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class GrievanceController extends Controller
{
    public function __construct(
        private readonly IntakeEngine $intake,
        private readonly GrievanceService $grievances,
        private readonly GrievanceVisibility $visibility,
        private readonly AiClassificationService $ai,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Grievance::class);

        $query = $this->buildQuery($request)
            ->with(['category:id,name', 'subcategory:id,name', 'assignee:id,name', 'location:id,name,path'])
            ->withCount(['followUps', 'attachments', 'commitments']);

        QueryFilters::sort($query, $request, [
            'received_at', 'reference', 'severity', 'status', 'resolution_due_at', 'closed_at', 'updated_at',
        ], '-received_at');

        return GrievanceResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        )->additional(['meta' => ['summary' => $this->summary($request)]]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Grievance::class);

        $data = $request->validate([
            'client_uuid' => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'channel' => ['required', Rule::in(Grievance::CHANNELS)],
            'channel_reference' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'occurred_at' => ['nullable', 'date'],
            'confidentiality' => ['required', Rule::in(['normal', 'confidential', 'anonymous'])],

            'complainant_type' => ['nullable', 'string', 'max:40'],
            // Identity is forbidden on an anonymous case — not merely ignored.
            'complainant_name' => ['nullable', 'exclude_if:confidentiality,anonymous', 'string', 'max:160'],
            'complainant_phone' => ['nullable', 'exclude_if:confidentiality,anonymous', 'string', 'max:40'],
            'complainant_email' => ['nullable', 'exclude_if:confidentiality,anonymous', 'email', 'max:160'],
            'complainant_address' => ['nullable', 'exclude_if:confidentiality,anonymous', 'string', 'max:1000'],
            'complainant_language' => ['nullable', 'string', 'max:10'],
            'preferred_contact_method' => ['nullable', 'string', 'max:30'],
            'stakeholder_id' => ['nullable', 'exclude_if:confidentiality,anonymous', 'integer', 'exists:stakeholders,id'],

            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'location_text' => ['nullable', 'string', 'max:255'],
            'precise_location' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'category_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'severity' => ['nullable', 'integer', 'between:1,5'],

            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:50000'],
            'desired_resolution' => ['nullable', 'string', 'max:20000'],
            'demographics' => ['nullable', 'array'],
            'custom_fields' => ['nullable', 'array'],
            'captured_at' => ['nullable', 'date'],
            'request_ai_suggestion' => ['nullable', 'boolean'],
        ], [
            'description.required' => 'Write down what the complainant told you, in their own words where you can.',
            'confidentiality.required' => 'Choose whether this case is normal, confidential or anonymous.',
        ]);

        $grievance = $this->intake->intake($this->project(), $data);

        // AI proposes a classification; it never sets one.
        if ($request->boolean('request_ai_suggestion', true) && ! $grievance->classification_confirmed) {
            $this->ai->suggestFor($grievance);
        }

        return ApiResponse::data(
            new GrievanceResource($grievance->fresh(['category', 'subcategory', 'location', 'aiSuggestions'])),
            [],
            201
        );
    }

    public function show(Grievance $grievance)
    {
        $this->authorize('view', $grievance);

        $grievance->load([
            'category', 'subcategory', 'location', 'assignee:id,name', 'stakeholder:id,reference,name',
            'followUps.author:id,name', 'followUps.attachments', 'assignments.assignee:id,name',
            'assignments.assigner:id,name', 'cycles.resolver:id,name', 'escalations.escalatedTo:id,name',
            'communications.sender:id,name', 'attachments.uploader:id,name', 'aiSuggestions.reviewer:id,name',
            'commitments.owner:id,name', 'sourceConcern:id,reference,title',
        ]);

        return ApiResponse::data(new GrievanceResource($grievance));
    }

    public function update(Request $request, Grievance $grievance)
    {
        $this->authorize('update', $grievance);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:50000'],
            'desired_resolution' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'location_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'complainant_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'complainant_language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'preferred_contact_method' => ['sometimes', 'nullable', 'string', 'max:30'],
            'demographics' => ['sometimes', 'nullable', 'array'],
            'custom_fields' => ['sometimes', 'nullable', 'array'],
        ]);

        // Identity may only be edited by someone who can already see it.
        if ($request->hasAny(['complainant_name', 'complainant_phone', 'complainant_email', 'complainant_address', 'precise_location'])) {
            $this->authorize('viewIdentity', $grievance);

            $data = array_merge($data, $request->validate([
                'complainant_name' => ['sometimes', 'nullable', 'string', 'max:160'],
                'complainant_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'complainant_email' => ['sometimes', 'nullable', 'email', 'max:160'],
                'complainant_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'precise_location' => ['sometimes', 'nullable', 'string', 'max:1000'],
            ]));
        }

        $grievance->update($data);

        return ApiResponse::data(new GrievanceResource($grievance->fresh(['category', 'subcategory', 'location'])));
    }

    public function classify(Request $request, Grievance $grievance)
    {
        $this->authorize('classify', $grievance);

        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:grievance_categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'severity' => ['required', 'integer', 'between:1,5'],
        ], [
            'category_id.required' => 'Choose a category for this case.',
            'severity.required' => 'Assess how serious this case is, from Level 1 to Level 5.',
        ]);

        $updated = $this->grievances->classify($grievance, $data);

        return ApiResponse::data(new GrievanceResource($updated->load(['category', 'subcategory'])));
    }

    public function assign(Request $request, Grievance $grievance)
    {
        $this->authorize('assign', $grievance);

        $data = $request->validate([
            'assigned_to_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_team' => ['nullable', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->grievances->assign(
            $grievance,
            $data['assigned_to_id'] ?? null,
            $data['assigned_team'] ?? null,
            $data['reason'] ?? null
        );

        return ApiResponse::data(new GrievanceResource($updated->load(['assignee', 'assignments.assignee'])));
    }

    public function acknowledge(Request $request, Grievance $grievance)
    {
        $this->authorize('acknowledge', $grievance);

        $data = $request->validate([
            'method' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        $body = $data['message'] ?? $this->defaultAcknowledgement($grievance);
        $updated = $this->grievances->acknowledge($grievance, $data['method'], $body);

        return ApiResponse::data(new GrievanceResource($updated->load('communications.sender')));
    }

    public function startInvestigation(Request $request, Grievance $grievance)
    {
        $this->authorize('investigate', $grievance);

        $updated = $this->grievances->startInvestigation($grievance, $request->input('note'));

        return ApiResponse::data(new GrievanceResource($updated));
    }

    public function recordInvestigation(Request $request, Grievance $grievance)
    {
        $this->authorize('investigate', $grievance);

        $data = $request->validate([
            'investigation_summary' => ['nullable', 'string', 'max:50000'],
            'investigation_findings' => ['nullable', 'string', 'max:50000'],
            'corrective_action' => ['nullable', 'string', 'max:50000'],
            'corrective_action_owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'corrective_action_due' => ['nullable', 'date'],
            'completed' => ['nullable', 'boolean'],
        ]);

        $updated = $this->grievances->recordInvestigation($grievance, $data);

        return ApiResponse::data(new GrievanceResource($updated));
    }

    public function resolve(Request $request, Grievance $grievance)
    {
        $this->authorize('resolve', $grievance);

        $data = $request->validate([
            'resolution_summary' => ['required', 'string', 'min:10', 'max:50000'],
            'corrective_action' => ['nullable', 'string', 'max:50000'],
        ], [
            'resolution_summary.required' => 'Describe how the case was resolved.',
            'resolution_summary.min' => 'A resolution needs more than a few words — the complainant and any auditor will read this.',
        ]);

        $updated = $this->grievances->resolve($grievance, $data['resolution_summary'], $data['corrective_action'] ?? null);

        return ApiResponse::data(new GrievanceResource($updated));
    }

    public function recordComplainantResponse(Request $request, Grievance $grievance)
    {
        $this->authorize('resolve', $grievance);

        $data = $request->validate([
            'response' => ['required', Rule::in(['accepted', 'rejected', 'no_response', 'not_contactable'])],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $updated = $this->grievances->recordComplainantResponse($grievance, $data['response'], $data['note'] ?? null);

        return ApiResponse::data(new GrievanceResource($updated));
    }

    public function close(Request $request, Grievance $grievance)
    {
        $this->authorize('close', $grievance);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        $updated = $this->grievances->close($grievance, $data['notes'] ?? null);

        return ApiResponse::data(new GrievanceResource($updated));
    }

    public function reopen(Request $request, Grievance $grievance)
    {
        $this->authorize('reopen', $grievance);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:5000'],
        ], [
            'reason.required' => 'Why is this case being reopened? The complainant\'s own words are best.',
        ]);

        $updated = $this->grievances->reopen($grievance, $data['reason']);

        return ApiResponse::data(new GrievanceResource($updated->load('cycles')));
    }

    public function escalate(Request $request, Grievance $grievance)
    {
        $this->authorize('escalate', $grievance);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:5000'],
            'escalated_to_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $updated = $this->grievances->escalate(
            $grievance, 'manual', $data['reason'], $data['escalated_to_id'] ?? null
        );

        return ApiResponse::data(new GrievanceResource($updated->load('escalations')));
    }

    public function withdraw(Request $request, Grievance $grievance)
    {
        $this->authorize('close', $grievance);

        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);

        return ApiResponse::data(new GrievanceResource($this->grievances->withdraw($grievance, $data['reason'])));
    }

    public function addFollowUp(Request $request, Grievance $grievance)
    {
        $this->authorize('investigate', $grievance);

        $data = $request->validate([
            'type' => ['nullable', Rule::in(['note', 'investigation_step', 'contact', 'site_visit', 'decision'])],
            'body' => ['required', 'string', 'max:50000'],
            'is_sensitive' => ['nullable', 'boolean'],
            'occurred_on' => ['nullable', 'date'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        $followUp = GrievanceFollowUp::create(array_merge($data, [
            'organisation_id' => $grievance->organisation_id,
            'project_id' => $grievance->project_id,
            'grievance_id' => $grievance->id,
            'type' => $data['type'] ?? 'note',
            'resolution_cycle' => $grievance->resolution_cycle,
            'created_by' => $request->user()->id,
            'captured_at' => now(),
            'synced_at' => now(),
        ]));

        return ApiResponse::data(new GrievanceFollowUpResource($followUp->load('author')), [], 201);
    }

    public function sendCommunication(Request $request, Grievance $grievance)
    {
        $this->authorize('acknowledge', $grievance);

        $data = $request->validate([
            'channel' => ['required', 'string', 'max:30'],
            'template_key' => ['nullable', 'string', 'max:80'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $communication = $this->grievances->logCommunication(
            $grievance,
            $data['template_key'] ?? 'manual',
            $data['body'],
            $data['channel'],
            'outbound',
            $data['subject'] ?? null,
        );

        return ApiResponse::data([
            'id' => $communication->id,
            'status' => $communication->status,
            'sent_at' => $communication->sent_at?->toIso8601String(),
            'note' => $grievance->acknowledgement_possible
                ? 'Message logged against the case.'
                : 'Recorded, but this case has no reachable contact — the message was not sent.',
        ], [], 201);
    }

    public function export(Request $request, ExportService $exports)
    {
        $this->authorize('export', Grievance::class);

        $result = $exports->export(
            project: $this->project(),
            user: $request->user(),
            entity: 'grievances',
            format: $request->input('format', 'xlsx'),
            query: $this->buildQuery($request)->with(['category:id,name', 'subcategory:id,name', 'assignee:id,name', 'location:id,path']),
            shape: [
                'columns' => array_merge(
                    ['Case ID', 'Received', 'Channel', 'Category', 'Subcategory', 'Severity', 'Confidentiality', 'Location', 'Status', 'Owner', 'Acknowledged', 'Resolved', 'Closed', 'Days open', 'Reopened'],
                    $this->visibility->exportStripsIdentity() ? [] : ['Complainant', 'Phone']
                ),
                'mapper' => function (Grievance $g) {
                    $row = [
                        $g->reference, $g->received_at?->toDateString(), str_replace('_', ' ', $g->channel),
                        $g->category?->name, $g->subcategory?->name,
                        $g->severity ? 'Level '.$g->severity : 'Not assessed',
                        $g->confidentiality, $g->location?->path ?? $g->location_text,
                        str_replace('_', ' ', $g->status), $g->assignee?->name,
                        $g->acknowledged_at?->toDateString() ?? ($g->acknowledgement_possible ? '' : 'Not possible'),
                        $g->resolved_at?->toDateString(), $g->closed_at?->toDateString(),
                        $g->daysOpen(), $g->reopen_count,
                    ];

                    if (! $this->visibility->exportStripsIdentity()) {
                        $row[] = $g->isAnonymous() ? 'Anonymous' : $g->complainant_name;
                        $row[] = $g->isAnonymous() ? '' : $g->complainant_phone;
                    }

                    return $row;
                },
            ],
            filters: $request->except(['page', 'per_page', 'format']),
        );

        return Storage::disk(config('filesystems.default', 'local'))->download($result['path'], $result['filename']);
    }

    private function defaultAcknowledgement(Grievance $grievance): string
    {
        $days = (int) ($grievance->slaClocks()->where('clock', 'resolution')->value('target_value') ?? 7);

        return "Thank you for raising this with us. Your case reference is {$grievance->reference}. "
            ."We aim to come back to you within {$days} working days. If you need to reach us before then, "
            .'quote your case reference.';
    }

    private function buildQuery(Request $request)
    {
        $query = Grievance::query()->where('project_id', $this->project()->id);

        // Restricted cases are removed from the query itself, for everyone
        // outside the handling group.
        $this->visibility->scopeVisible($query);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        QueryFilters::apply($query, $request, [
            'channel', 'severity', 'confidentiality', 'complainant_type', 'category_id', 'subcategory_id',
            'status' => fn ($q, $v) => match ($v) {
                'open' => $q->open(),
                'closed' => $q->closed(),
                default => is_array($v) ? $q->whereIn('status', $v) : $q->where('status', $v),
            },
            'severity_min' => fn ($q, $v) => $q->where('severity', '>=', (int) $v),
            'assignee' => fn ($q, $v) => $v === 'none'
                ? $q->whereNull('assigned_to_id')
                : ($v === 'me' ? $q->where('assigned_to_id', auth()->id()) : $q->where('assigned_to_id', $v)),
            'assigned_to_id',
            'location_id' => fn ($q, $v) => QueryFilters::location($q, $v),
            'sla' => fn ($q, $v) => match ($v) {
                'breached' => $q->where(fn ($w) => $w->where('resolution_sla_state', 'breached')->orWhere('acknowledgement_sla_state', 'breached')),
                'at_risk' => $q->where('resolution_sla_state', 'at_risk'),
                'paused' => $q->where('resolution_sla_state', 'paused'),
                default => $q,
            },
            'acknowledged' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN)
                ? $q->whereNotNull('acknowledged_at')
                : $q->whereNull('acknowledged_at'),
            'reopened' => fn ($q, $v) => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? $q->where('reopen_count', '>', 0) : $q,
            'stakeholder_id',
            'search' => fn ($q, $v) => $q->search($v),
        ]);

        QueryFilters::dateRange($query, $request, 'received_at');

        return $query;
    }

    private function summary(Request $request): array
    {
        $request = QueryFilters::without($request, ['status', 'severity', 'severity_min', 'sla', 'acknowledged', 'assignee']);
        $base = fn () => $this->buildQuery($request);

        return [
            'total' => $base()->count(),
            'open' => $base()->open()->count(),
            'closed' => $base()->closed()->count(),
            'unassigned' => $base()->open()->whereNull('assigned_to_id')->count(),
            'high_severity' => $base()->open()->highSeverity()->count(),
            'breached' => $base()->where('resolution_sla_state', 'breached')->count(),
            'awaiting_acknowledgement' => $base()->open()->whereNull('acknowledged_at')->where('acknowledgement_possible', true)->count(),
        ];
    }
}
