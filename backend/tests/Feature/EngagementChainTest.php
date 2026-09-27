<?php

namespace Tests\Feature;

use App\Domain\Engagement\CommitmentService;
use App\Domain\Engagement\ConcernService;
use App\Domain\Stakeholder\StakeholderService;
use App\Models\Commitment;
use App\Models\Concern;
use App\Models\Engagement;
use App\Models\EngagementPlan;
use App\Models\Grievance;
use App\Models\Stakeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACCEPTANCE (blueprint): given a planned engagement exists, when the actual
 * engagement is logged, the system links it to the plan and calculates the
 * planned-vs-actual status. And: given an engagement creates a commitment, a
 * commitments register entry is created automatically.
 */
class EngagementChainTest extends TestCase
{
    use RefreshDatabase;

    private Stakeholder $stakeholder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->stakeholder = app(StakeholderService::class)
            ->create($this->project, [
                'name' => 'Buswelu Village Council',
                'type' => 'community_group',
                'primary_location_id' => $this->village->id,
                'influence' => 'high', 'interest' => 'high', 'power' => 'high', 'impact' => 'high',
            ]);
    }

    private function plan(array $overrides = []): EngagementPlan
    {
        $user = $this->makeUser('community_relations_officer');

        $response = $this->asUser($user)->postJson('/api/v1/engagement-plans', array_merge([
            'title' => 'Corridor disclosure meeting',
            'stakeholder_id' => $this->stakeholder->id,
            'purpose' => 'Disclose the works programme and hear concerns.',
            'method' => 'community_meeting',
            'target_date' => now()->subDays(5)->toDateString(),
            'location_id' => $this->village->id,
            'owner_id' => $user->id,
            'priority' => 'high',
        ], $overrides));

        $response->assertCreated();

        return EngagementPlan::findOrFail($response->json('data.id'));
    }

    public function test_a_plan_gets_a_reference_and_starts_as_planned(): void
    {
        $plan = $this->plan();

        $this->assertMatchesRegularExpression('/^PLAN-\d{4}$/', $plan->reference);
        $this->assertSame('planned', $plan->status);
    }

    public function test_logging_an_engagement_on_the_planned_day_closes_the_plan_as_on_plan(): void
    {
        $plan = $this->plan();
        $user = $this->makeUser('community_relations_officer');

        $response = $this->asUser($user)->postJson('/api/v1/engagements', [
            'engagement_plan_id' => $plan->id,
            'topic' => 'Corridor disclosure meeting',
            'held_at' => $plan->target_date->toDateTimeString(),
            'method' => 'community_meeting',
            'location_id' => $this->village->id,
            'attendance_total' => 180,
            'attendance_female' => 74,
            'discussion_points' => 'Works programme presented; grievance mechanism explained.',
        ]);

        $response->assertCreated();
        $this->assertSame('on_plan', $response->json('data.planned_vs_actual'));
        $this->assertMatchesRegularExpression('/^ENG-\d{4}$/', $response->json('data.reference'));
        $this->assertSame('completed', $plan->fresh()->status);
    }

    public function test_logging_late_is_flagged_late_with_the_variance_in_days(): void
    {
        $plan = $this->plan(['target_date' => now()->subDays(20)->toDateString()]);
        $user = $this->makeUser('community_relations_officer');

        $response = $this->asUser($user)->postJson('/api/v1/engagements', [
            'engagement_plan_id' => $plan->id,
            'topic' => 'Corridor disclosure meeting (rescheduled on the day)',
            'held_at' => now()->subDays(6)->toDateTimeString(),
            'method' => 'community_meeting',
            'attendance_total' => 90,
        ]);

        $response->assertCreated();
        $this->assertSame('late', $response->json('data.planned_vs_actual'));
        $this->assertSame(14, $response->json('data.variance_days'));
    }

    public function test_an_engagement_with_no_plan_is_flagged_unplanned(): void
    {
        $user = $this->makeUser('field_officer');

        $response = $this->asUser($user)->postJson('/api/v1/engagements', [
            'topic' => 'Unscheduled visit after a complaint',
            'held_at' => now()->toDateTimeString(),
            'method' => 'household_visit',
            'attendance_total' => 6,
        ]);

        $response->assertCreated();
        $this->assertSame('unplanned', $response->json('data.planned_vs_actual'));
    }

    public function test_a_plan_whose_window_passed_with_nothing_logged_becomes_missed(): void
    {
        $this->plan(['target_date' => now()->subDays(12)->toDateString()]);

        $this->artisan('sasa:missed-engagements')->assertSuccessful();

        $this->assertDatabaseHas('engagement_plans', ['status' => 'missed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'engagement_plan.missed']);
    }

    public function test_concerns_and_commitments_captured_in_the_meeting_become_records(): void
    {
        $user = $this->makeUser('community_relations_officer');

        $response = $this->asUser($user)->postJson('/api/v1/engagements', [
            'topic' => 'Village assembly',
            'held_at' => now()->toDateTimeString(),
            'method' => 'community_meeting',
            'attendance_total' => 120,
            'stakeholder_ids' => [$this->stakeholder->id],
            'participants' => [
                ['name' => 'Village chairperson', 'category' => 'community', 'signed_attendance' => true],
                ['name' => 'Ward executive officer', 'category' => 'government', 'signed_attendance' => true],
            ],
            'concerns' => [[
                'title' => 'Dust from the access road',
                'description' => 'Trucks pass all day and the dust reaches the school.',
                'severity_hint' => 'high',
                'grievance_category_id' => $this->categories['environment']->id,
            ]],
            'commitments' => [[
                'commitment_text' => 'Water the access road twice daily during dry-season works.',
                'owner_id' => $user->id,
                'due_date' => now()->addDays(14)->toDateString(),
                'risk_level' => 'high',
                'stakeholder_ids' => [$this->stakeholder->id],
            ]],
        ]);

        $response->assertCreated();
        $engagementId = $response->json('data.id');

        $concern = Concern::where('engagement_id', $engagementId)->first();
        $this->assertNotNull($concern);
        $this->assertMatchesRegularExpression('/^CON-\d{4}$/', $concern->reference);

        // The commitments register entry is created automatically.
        $commitment = Commitment::where('engagement_id', $engagementId)->first();
        $this->assertNotNull($commitment);
        $this->assertMatchesRegularExpression('/^COM-\d{4}$/', $commitment->reference);
        $this->assertSame('engagement', $commitment->source_type);
        $this->assertTrue($commitment->stakeholders->contains($this->stakeholder->id));

        $this->assertDatabaseCount('engagement_participants', 2);
    }

    public function test_a_concern_escalates_into_a_grievance_without_retyping_it(): void
    {
        $officer = $this->makeUser('community_relations_officer');

        $engagementId = $this->asUser($officer)->postJson('/api/v1/engagements', [
            'topic' => 'Village assembly',
            'held_at' => now()->toDateTimeString(),
            'method' => 'community_meeting',
            'attendance_total' => 40,
            'concerns' => [[
                'title' => 'Borehole water turned cloudy after excavation',
                'description' => 'Both boreholes serving the village turned cloudy in the week after excavation began.',
                'severity_hint' => 'high',
                'stakeholder_id' => $this->stakeholder->id,
                'grievance_category_id' => $this->categories['environment']->id,
            ]],
        ])->json('data.id');

        $concern = Concern::where('engagement_id', $engagementId)->firstOrFail();

        $grievanceOfficer = $this->makeUser('grievance_officer');

        // The draft carries the concern's own words across.
        $draft = $this->asUser($grievanceOfficer)->getJson("/api/v1/concerns/{$concern->id}/escalation-draft");
        $draft->assertOk();
        $this->assertSame($concern->description, $draft->json('data.draft.description'));
        $this->assertSame($this->categories['environment']->id, $draft->json('data.draft.category_id'));

        $response = $this->asUser($grievanceOfficer)->postJson("/api/v1/concerns/{$concern->id}/escalate", []);
        $response->assertCreated();

        $grievance = Grievance::findOrFail($response->json('data.id'));
        $this->assertSame($concern->description, $grievance->description);
        $this->assertSame($concern->id, $grievance->source_concern_id);

        $concern->refresh();
        $this->assertSame('escalated', $concern->status);
        $this->assertSame($grievance->id, $concern->grievance_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'concern.escalated', 'entity_id' => $concern->id]);
    }

    public function test_the_same_concern_cannot_be_escalated_twice(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $concern = app(ConcernService::class)->create($this->project, [
            'title' => 'Access road closed',
            'description' => 'The access road was closed without notice.',
            'raised_on' => now()->toDateString(),
        ]);

        $this->asUser($officer)->postJson("/api/v1/concerns/{$concern->id}/escalate", [])->assertCreated();
        $this->asUser($officer)->postJson("/api/v1/concerns/{$concern->id}/escalate", [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'already_escalated');
    }

    public function test_a_field_officer_may_log_but_not_plan(): void
    {
        $field = $this->makeUser('field_officer');

        $this->asUser($field)->postJson('/api/v1/engagements', [
            'topic' => 'Household visit',
            'held_at' => now()->toDateTimeString(),
            'method' => 'household_visit',
        ])->assertCreated();

        $this->asUser($field)->postJson('/api/v1/engagement-plans', [
            'title' => 'A plan the field officer should not be able to make',
            'target_date' => now()->addWeek()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_a_commitment_must_be_fulfilled_before_it_can_be_verified(): void
    {
        $manager = $this->makeUser('project_management');

        $commitment = app(CommitmentService::class)->create($this->project, [
            'commitment_text' => 'Build two classrooms at the primary school.',
            'due_date' => now()->addDays(60)->toDateString(),
            'risk_level' => 'high',
        ]);

        $this->asUser($manager)->postJson("/api/v1/commitments/{$commitment->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(422)->assertJsonPath('error', 'not_fulfilled');

        $this->asUser($manager)->postJson("/api/v1/commitments/{$commitment->id}/status", [
            'status' => 'fulfilled',
            'evidence_notes' => 'Handover certificate signed by the village council.',
        ])->assertOk();

        $this->asUser($manager)->postJson("/api/v1/commitments/{$commitment->id}/verify", [
            'verification_status' => 'verified',
            'notes' => 'Verified on site with the village chairperson.',
        ])->assertOk()->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_overdue_commitments_are_flagged_and_the_owner_is_reminded(): void
    {
        $manager = $this->makeUser('project_management');

        $commitment = app(CommitmentService::class)->create($this->project, [
            'commitment_text' => 'Deliver the quarterly monitoring report.',
            'due_date' => now()->subDays(3)->toDateString(),
            'owner_id' => $manager->id,
            'risk_level' => 'high',
        ]);

        $this->artisan('sasa:commitment-reminders')->assertSuccessful();

        $this->assertSame('overdue', $commitment->fresh()->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $manager->id]);
    }

    public function test_the_engagement_calendar_returns_upcoming_plans(): void
    {
        $this->plan(['target_date' => now()->addDays(5)->toDateString()]);
        $user = $this->makeUser('community_relations_officer');

        $response = $this->asUser($user)->getJson('/api/v1/engagement-plans/calendar');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
