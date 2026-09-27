<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Configuration\ConfigurationKeys;
use App\Domain\Configuration\ConfigurationRegistry;
use App\Domain\Identity\PermissionCatalogue;
use App\Domain\Notification\NotificationEvents;
use App\Http\Controllers\Controller;
use App\Models\GrievanceCategory;
use App\Models\Holiday;
use App\Models\NotificationRule;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\Stakeholder;
use App\Models\WorkingCalendar;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Configuration is what lets one codebase serve a Tanzanian transmission
 * project and a West African mine without a fork. Every change here is audited.
 */
class ConfigurationController extends Controller
{
    public function __construct(private readonly ConfigurationRegistry $configuration) {}

    public function index()
    {
        $this->can('configuration.view');

        $project = $this->project();

        return ApiResponse::data([
            'catalogue' => collect(ConfigurationKeys::catalogue())->map(fn ($meta, $key) => array_merge(['key' => $key], $meta))->values(),
            'values' => $this->configuration->all($project->organisation_id, $project->id),
            'defaults' => [
                'priority' => config('sasa.priority'),
                'severity_levels' => config('sasa.grievances.severity_levels'),
                'disaggregation' => config('sasa.disaggregation'),
                'commitments' => config('sasa.commitments'),
                'voice' => config('sasa.voice'),
                'calendar' => config('sasa.calendar'),
                'sla' => config('sasa.sla'),
            ],
            'lists' => [
                'stakeholder_types' => $this->configuration->get('stakeholder_types', $project->organisation_id, $project->id, Stakeholder::TYPES),
                'engagement_methods' => $this->configuration->get('engagement_methods', $project->organisation_id, $project->id, [
                    'community_meeting', 'focus_group', 'household_visit', 'public_hearing',
                    'radio_programme', 'phone_call', 'written_disclosure', 'workshop', 'site_walkover',
                ]),
                'project_phases' => $this->configuration->get('project_phases', $project->organisation_id, $project->id, [
                    'pre_construction', 'construction', 'operation', 'decommissioning',
                ]),
                'languages' => $this->configuration->get('languages', $project->organisation_id, $project->id, ['sw', 'en']),
                'vulnerability_categories' => $this->configuration->get('vulnerability_categories', $project->organisation_id, $project->id, [
                    'elderly', 'disability', 'female_headed_household', 'child_headed_household',
                    'landless', 'chronically_ill', 'displaced', 'indigenous', 'linguistic_minority', 'low_income',
                ]),
                'disaggregation_dimensions' => $this->configuration->get('disaggregation_dimensions', $project->organisation_id, $project->id, $this->defaultDimensions()),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->can('configuration.manage');

        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'value' => ['required'],
            'scope' => ['nullable', Rule::in(['project', 'organisation'])],
        ]);

        $project = $this->project();

        $configuration = $this->configuration->set(
            $data['key'],
            $data['value'],
            $project->organisation_id,
            ($data['scope'] ?? 'project') === 'project' ? $project->id : null,
        );

        return ApiResponse::data($configuration, [
            'message' => 'Saved. This change is recorded in the audit trail.',
        ]);
    }

    // ------------------------------------------------------------- categories

    public function categories(Request $request)
    {
        $this->can('configuration.view');

        return ApiResponse::data(
            GrievanceCategory::forProject($this->project()->id)
                ->topLevel()
                ->when(! $request->boolean('include_retired'), fn ($q) => $q->selectable())
                ->with(['subcategories' => fn ($q) => $request->boolean('include_retired') ? $q : $q->selectable()])
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function storeCategory(Request $request)
    {
        $this->can('configuration.manage');

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer'],
            'default_severity' => ['nullable', 'integer', 'between:1,5'],
            'is_restricted' => ['nullable', 'boolean'],
            'handling_groups' => ['nullable', 'array'],
            'aggregate_reporting_only' => ['nullable', 'boolean'],
        ]);

        $category = GrievanceCategory::create(array_merge($data, [
            'organisation_id' => $this->project()->organisation_id,
            'project_id' => $this->project()->id,
        ]));

        return ApiResponse::data($category, [], 201);
    }

    public function updateCategory(Request $request, GrievanceCategory $category)
    {
        $this->can('configuration.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer'],
            'default_severity' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'is_restricted' => ['sometimes', 'boolean'],
            'handling_groups' => ['sometimes', 'nullable', 'array'],
            'aggregate_reporting_only' => ['sometimes', 'boolean'],
        ]);

        $category->update($data);

        return ApiResponse::data($category->fresh());
    }

    /** Retiring hides a category from new cases and keeps it on historical ones. */
    public function retireCategory(GrievanceCategory $category)
    {
        $this->can('configuration.manage');

        $category->update(['retired_at' => $category->retired_at ? null : now()]);

        return ApiResponse::data($category->fresh(), [
            'message' => $category->retired_at
                ? 'Retired. It will not appear on new cases, and stays on the ones that already use it.'
                : 'Reinstated. It can be chosen on new cases again.',
        ]);
    }

    // -------------------------------------------------------------------- SLA

    public function slaPolicies()
    {
        $this->can('configuration.view');

        return ApiResponse::data(
            SlaPolicy::query()
                ->where('organisation_id', $this->project()->organisation_id)
                ->where(fn ($q) => $q->where('project_id', $this->project()->id)->orWhereNull('project_id'))
                ->with(['category:id,name', 'calendar:id,name', 'escalateToRole:id,name'])
                ->orderBy('clock')->orderByDesc('specificity')
                ->get()
        );
    }

    public function storeSlaPolicy(Request $request)
    {
        $this->can('configuration.manage');

        $data = $request->validate([
            'clock' => ['required', Rule::in(['acknowledgement', 'investigation', 'resolution', 'contact', 'commitment'])],
            'unit' => ['required', Rule::in(['working_days', 'working_hours', 'calendar_days', 'calendar_hours'])],
            'target_value' => ['required', 'integer', 'between:1,3650'],
            'category_id' => ['nullable', 'integer', 'exists:grievance_categories,id'],
            'severity' => ['nullable', 'integer', 'between:1,5'],
            'country' => ['nullable', 'string', 'size:2'],
            'working_calendar_id' => ['nullable', 'integer', 'exists:working_calendars,id'],
            'reminder_thresholds' => ['nullable', 'array'],
            'reminder_thresholds.*' => ['integer', 'between:1,99'],
            'escalate_to_role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $policy = SlaPolicy::updateOrCreate(
            [
                'organisation_id' => $this->project()->organisation_id,
                'project_id' => $this->project()->id,
                'clock' => $data['clock'],
                'category_id' => $data['category_id'] ?? null,
                'severity' => $data['severity'] ?? null,
            ],
            array_merge($data, [
                'specificity' => SlaPolicy::computeSpecificity(
                    $data['category_id'] ?? null,
                    $data['severity'] ?? null,
                    $this->project()->id
                ),
                'created_by' => $request->user()->id,
                'is_active' => $data['is_active'] ?? true,
            ])
        );

        return ApiResponse::data($policy->fresh(['category', 'calendar']), [], 201);
    }

    public function deleteSlaPolicy(SlaPolicy $policy)
    {
        $this->can('configuration.manage');

        abort_unless($policy->project_id === $this->project()->id, 404);

        $policy->update(['is_active' => false]);

        return ApiResponse::message('That SLA standard has been switched off. Clocks already running keep their original target.');
    }

    // -------------------------------------------------------------- calendar

    public function calendars()
    {
        $this->can('configuration.view');

        return ApiResponse::data(
            WorkingCalendar::where('organisation_id', $this->project()->organisation_id)
                ->where(fn ($q) => $q->where('project_id', $this->project()->id)->orWhereNull('project_id'))
                ->with('holidays')
                ->get()
        );
    }

    public function updateCalendar(Request $request, WorkingCalendar $calendar)
    {
        $this->can('configuration.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'max:60'],
            'working_days' => ['sometimes', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7'],
            'work_start' => ['sometimes', 'date_format:H:i'],
            'work_end' => ['sometimes', 'date_format:H:i', 'after:work_start'],
        ]);

        $calendar->update($data);
        Cache::forget("sasa.calendar.project.{$this->project()->id}");

        return ApiResponse::data($calendar->fresh('holidays'));
    }

    public function storeHoliday(Request $request, WorkingCalendar $calendar)
    {
        $this->can('configuration.manage');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:120'],
            'recurs_annually' => ['nullable', 'boolean'],
        ]);

        $holiday = Holiday::updateOrCreate(
            ['working_calendar_id' => $calendar->id, 'date' => $data['date']],
            $data
        );

        Cache::forget("sasa.calendar.holidays.{$calendar->id}");

        return ApiResponse::data($holiday, [], 201);
    }

    public function deleteHoliday(Holiday $holiday)
    {
        $this->can('configuration.manage');

        $calendarId = $holiday->working_calendar_id;
        $holiday->delete();
        Cache::forget("sasa.calendar.holidays.{$calendarId}");

        return ApiResponse::noContent();
    }

    // ------------------------------------------------------- roles & notifications

    public function roles()
    {
        $this->can('configuration.view');

        return ApiResponse::data(
            Role::query()
                ->where(fn ($q) => $q->where('organisation_id', $this->project()->organisation_id)->orWhereNull('organisation_id'))
                ->with('permissions:id,key,name,group')
                ->orderBy('escalation_rank')
                ->get()
        );
    }

    public function permissions()
    {
        $this->can('configuration.view');

        return ApiResponse::data(
            collect(PermissionCatalogue::permissions())->groupBy('group')
        );
    }

    public function notificationRules()
    {
        $this->can('configuration.view');

        $existing = NotificationRule::query()
            ->where('organisation_id', $this->project()->organisation_id)
            ->where(fn ($q) => $q->where('project_id', $this->project()->id)->orWhereNull('project_id'))
            ->get()
            ->keyBy('event_key');

        return ApiResponse::data(
            collect(NotificationEvents::defaults())
                ->map(fn ($default) => array_merge($default, [
                    'configured' => $existing->has($default['event_key']),
                    'rule' => $existing->get($default['event_key']),
                ]))
                ->values()
        );
    }

    public function updateNotificationRule(Request $request)
    {
        $this->can('configuration.manage');

        $data = $request->validate([
            'event_key' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:160'],
            'recipient_roles' => ['nullable', 'array'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['in_app', 'email', 'sms', 'whatsapp'])],
            'conditions' => ['nullable', 'array'],
            'notify_owner' => ['nullable', 'boolean'],
            'notify_assignee' => ['nullable', 'boolean'],
            'template_subject' => ['nullable', 'string', 'max:255'],
            'template_body' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $rule = NotificationRule::updateOrCreate(
            [
                'organisation_id' => $this->project()->organisation_id,
                'project_id' => $this->project()->id,
                'event_key' => $data['event_key'],
            ],
            array_merge($data, ['updated_by' => $request->user()->id])
        );

        return ApiResponse::data($rule->fresh());
    }

    private function defaultDimensions(): array
    {
        return [
            ['key' => 'gender', 'label' => 'Gender', 'sensitive' => false, 'enabled' => true, 'options' => ['female', 'male', 'other', 'prefer_not_to_say']],
            ['key' => 'age_band', 'label' => 'Age band', 'sensitive' => false, 'enabled' => true, 'options' => ['under_18', '18_35', '36_60', 'over_60']],
            ['key' => 'disability', 'label' => 'Disability status', 'sensitive' => true, 'enabled' => true, 'options' => ['yes', 'no', 'prefer_not_to_say']],
            ['key' => 'employment', 'label' => 'Employment status', 'sensitive' => false, 'enabled' => true, 'options' => ['employed_by_project', 'contractor', 'self_employed', 'farmer', 'unemployed', 'student', 'retired']],
            ['key' => 'language', 'label' => 'Language', 'sensitive' => false, 'enabled' => true, 'options' => []],
            ['key' => 'marital_status', 'label' => 'Marital status', 'sensitive' => true, 'enabled' => false, 'options' => ['single', 'married', 'widowed', 'separated', 'orphan']],
            ['key' => 'migrant_status', 'label' => 'Migrant or refugee status', 'sensitive' => true, 'enabled' => false, 'options' => ['resident', 'internal_migrant', 'refugee', 'prefer_not_to_say']],
            ['key' => 'economic_status', 'label' => 'Economic status', 'sensitive' => true, 'enabled' => false, 'options' => ['low', 'medium', 'high', 'prefer_not_to_say']],
            // Deliberately off by default: collecting this lawfully, voluntarily
            // and safely is a decision for the project, not a default.
            ['key' => 'gender_identity', 'label' => 'Gender identity or sexual orientation', 'sensitive' => true, 'enabled' => false, 'options' => []],
        ];
    }

    private function can(string $permission): void
    {
        abort_unless($this->context()->can($permission), 403, 'You do not have permission to change configuration on this project.');
    }
}
