<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Dashboard\DashboardFilters;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Dashboard\MetricDefinitions;
use App\Http\Controllers\Controller;
use App\Models\Commitment;
use App\Models\EngagementPlan;
use App\Models\Grievance;
use App\Models\Stakeholder;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboards) {}

    public function executive(Request $request)
    {
        $this->authorizePermission('dashboard.view');

        return ApiResponse::data($this->dashboards->executive($this->filters($request)));
    }

    public function timeliness(Request $request)
    {
        $this->authorizePermission('dashboard.view');

        return ApiResponse::data($this->dashboards->timeliness($this->filters($request)));
    }

    public function disaggregation(Request $request)
    {
        $this->authorizePermission('dashboard.view_disaggregation');

        $dimension = $request->input('dimension', 'gender');
        $against = $request->input('against', 'category');

        return ApiResponse::data($this->dashboards->disaggregation($this->filters($request), $dimension, $against));
    }

    public function severity(Request $request)
    {
        $this->authorizePermission('dashboard.view');

        return ApiResponse::data($this->dashboards->severity($this->filters($request)));
    }

    public function engagement(Request $request)
    {
        $this->authorizePermission('dashboard.view');

        return ApiResponse::data($this->dashboards->engagementCommitments($this->filters($request)));
    }

    /**
     * The landing view changes by role, because the first question of the day
     * is different for each — without building six different applications.
     */
    public function landing(Request $request)
    {
        $this->authorizePermission('dashboard.view');

        $filters = $this->filters($request);
        $role = $this->context()->roleKey();

        $primary = match ($role) {
            'management', 'project_management' => 'executive',
            'grievance_officer', 'hr_officer', 'hse_officer', 'security_officer' => 'timeliness',
            'community_relations_officer' => 'engagement',
            'field_officer' => 'field',
            'auditor' => 'executive',
            default => 'executive',
        };

        $question = match ($role) {
            'management' => 'What is happening on this project?',
            'project_management', 'project_admin' => 'What needs my decision?',
            'grievance_officer' => 'What needs attention today?',
            'hr_officer', 'hse_officer', 'security_officer' => 'Which of my cases need attention?',
            'community_relations_officer' => 'Who am I engaging, and what did they raise?',
            'field_officer' => 'What do I need to do today?',
            'auditor' => 'Can I verify what happened?',
            default => 'What is the state of this project?',
        };

        $executive = $this->dashboards->executive($filters);

        return ApiResponse::data([
            'role' => $role,
            'question' => $question,
            'primary_dashboard' => $primary,
            'period' => $filters->toArray(),
            'kpis' => $executive['kpis'],
            'critical_actions' => $executive['critical_actions'],
            'recent_activity' => $executive['recent_activity'],
            'trend' => $executive['trend'],
            'my_work' => $this->myWork($request),
        ]);
    }

    public function definitions()
    {
        return ApiResponse::data(collect(MetricDefinitions::all())->map(fn ($definition, $key) => array_merge(
            ['key' => $key], $definition
        ))->values());
    }

    /** The personal queue: what this specific person owns right now. */
    private function myWork(Request $request): array
    {
        $userId = $request->user()->id;
        $projectId = $this->project()->id;

        return [
            'assigned_open_cases' => Grievance::where('project_id', $projectId)
                ->where('assigned_to_id', $userId)->open()->count(),
            'cases_breaching' => Grievance::where('project_id', $projectId)
                ->where('assigned_to_id', $userId)->open()
                ->whereIn('resolution_sla_state', ['at_risk', 'breached'])->count(),
            'commitments_due_this_week' => Commitment::where('project_id', $projectId)
                ->where('owner_id', $userId)->upcoming(7)->count(),
            'commitments_overdue' => Commitment::where('project_id', $projectId)
                ->where('owner_id', $userId)->overdue()->count(),
            'engagements_this_week' => EngagementPlan::where('project_id', $projectId)
                ->where('owner_id', $userId)->upcoming(7)->count(),
            'stakeholders_due_review' => Stakeholder::where('project_id', $projectId)
                ->where('owner_id', $userId)->where('status', 'active')->dueForReview()->count(),
        ];
    }

    private function filters(Request $request): DashboardFilters
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'location_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'severity' => ['nullable', 'integer', 'between:1,5'],
            'channel' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer'],
            'project_phase' => ['nullable', 'string'],
        ]);

        return DashboardFilters::fromRequest($this->project()->id, $request->all());
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless($this->context()->can($permission), 403, 'You do not have permission to see this dashboard.');
    }
}
