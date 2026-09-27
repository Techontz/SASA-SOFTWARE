<?php

namespace Tests;

use App\Domain\Notification\NotificationEvents;
use App\Domain\Tenancy\TenantContext;
use App\Models\GrievanceCategory;
use App\Models\Location;
use App\Models\NotificationRule;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Models\WorkingCalendar;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    protected Organisation $organisation;

    protected Project $project;

    protected Location $village;

    protected WorkingCalendar $calendar;

    /** @var array<string,GrievanceCategory> */
    protected array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->setUser(null);
    }

    /**
     * A minimal but realistic tenant: one organisation, one project, the role
     * templates, a location, a working calendar, the SLA defaults, one open
     * category and one restricted category.
     */
    protected function seedPlatform(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->organisation = Organisation::create([
            'name' => 'Test Infrastructure Group',
            'slug' => 'test-infrastructure',
            'country' => 'TZ',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
        ]);

        $this->project = Project::create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Test Transmission Line',
            'code' => 'TEST-1',
            'country' => 'TZ',
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
        ]);

        $country = Location::create([
            'organisation_id' => $this->organisation->id,
            'level' => 'country', 'name' => 'Tanzania', 'code' => 'TZ', 'status' => 'active',
        ]);
        $region = Location::create([
            'organisation_id' => $this->organisation->id, 'parent_id' => $country->id,
            'level' => 'region', 'name' => 'Mwanza', 'status' => 'active',
        ]);
        $district = Location::create([
            'organisation_id' => $this->organisation->id, 'parent_id' => $region->id,
            'level' => 'district', 'name' => 'Ilemela', 'status' => 'active',
        ]);
        $ward = Location::create([
            'organisation_id' => $this->organisation->id, 'parent_id' => $district->id,
            'level' => 'ward', 'name' => 'Buswelu', 'status' => 'active',
        ]);
        $this->village = Location::create([
            'organisation_id' => $this->organisation->id, 'parent_id' => $ward->id,
            'level' => 'village', 'name' => 'Nyakato', 'status' => 'active', 'estimated_population' => 3200,
        ]);

        $this->calendar = WorkingCalendar::create([
            'organisation_id' => $this->organisation->id,
            'project_id' => $this->project->id,
            'name' => 'Test calendar',
            'country' => 'TZ',
            'timezone' => 'Africa/Dar_es_Salaam',
            'working_days' => [1, 2, 3, 4, 5],
            'work_start' => '08:00',
            'work_end' => '17:00',
            'is_default' => true,
        ]);

        $this->categories['environment'] = GrievanceCategory::create([
            'organisation_id' => $this->organisation->id,
            'project_id' => $this->project->id,
            'key' => 'environmental_health_safety',
            'name' => 'Environmental, Health & Safety',
            'default_severity' => 3,
        ]);

        $this->categories['dust'] = GrievanceCategory::create([
            'organisation_id' => $this->organisation->id,
            'project_id' => $this->project->id,
            'parent_id' => $this->categories['environment']->id,
            'key' => 'environmental_health_safety.dust',
            'name' => 'Dust and air quality',
            'default_severity' => 3,
        ]);

        $this->categories['restricted'] = GrievanceCategory::create([
            'organisation_id' => $this->organisation->id,
            'project_id' => $this->project->id,
            'key' => 'human_rights_workplace_conduct',
            'name' => 'Human Rights & Workplace Conduct',
            'default_severity' => 5,
            'is_restricted' => true,
            'handling_groups' => ['restricted_handling'],
            'aggregate_reporting_only' => true,
        ]);

        foreach (config('sasa.sla.defaults') as $clock => $default) {
            SlaPolicy::create([
                'organisation_id' => $this->organisation->id,
                'project_id' => $this->project->id,
                'clock' => $clock,
                'unit' => $default['unit'],
                'target_value' => $default['value'],
                'working_calendar_id' => $this->calendar->id,
                'reminder_thresholds' => [50, 80],
                'is_active' => true,
                'specificity' => SlaPolicy::computeSpecificity(null, null, $this->project->id),
            ]);
        }

        foreach (NotificationEvents::defaults() as $default) {
            NotificationRule::create(array_merge($default, [
                'organisation_id' => $this->organisation->id,
                'project_id' => $this->project->id,
                'is_active' => true,
            ]));
        }
    }

    protected function makeUser(string $roleKey, array $handlingGroups = ['general'], ?Project $project = null): User
    {
        $project ??= $this->project;

        $user = User::create([
            'organisation_id' => $this->organisation->id,
            'name' => ucwords(str_replace('_', ' ', $roleKey)),
            'email' => $roleKey.'.'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'status' => 'active',
            'email_verified_at' => now(),
            'is_system_admin' => $roleKey === 'system_administrator',
        ]);

        ProjectMembership::create([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role_id' => Role::whereNull('organisation_id')->where('key', $roleKey)->firstOrFail()->id,
            'handling_groups' => $handlingGroups,
            'status' => 'active',
            'is_default' => true,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** Authenticate AND set the project header, the way the client does. */
    protected function asUser(User $user, ?Project $project = null): static
    {
        $project ??= $this->project;

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Sasa-Project', (string) $project->id);

        return $this;
    }

    protected function projectHeaders(?Project $project = null): array
    {
        return ['X-Sasa-Project' => (string) ($project ?? $this->project)->id];
    }
}
