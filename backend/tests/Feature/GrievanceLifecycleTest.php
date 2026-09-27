<?php

namespace Tests\Feature;

use App\Models\Grievance;
use App\Models\SlaClock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACCEPTANCE (blueprint): given a grievance is submitted, the system assigns a
 * case ID, classifies it, starts the SLA, assigns an owner, and tracks
 * investigation, resolution and closure.
 */
class GrievanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function intake(array $overrides = []): array
    {
        return array_merge([
            'channel' => 'voice',
            'confidentiality' => 'normal',
            'complainant_name' => 'Neema Charles Mwita',
            'complainant_phone' => '+255 754 123 456',
            'complainant_type' => 'community_member',
            'location_id' => $this->village->id,
            'category_id' => $this->categories['environment']->id,
            'subcategory_id' => $this->categories['dust']->id,
            'severity' => 3,
            'title' => 'Dust from haulage trucks is covering houses',
            'description' => 'Trucks pass every few minutes from early morning and the dust settles on houses, food and washing.',
            'desired_resolution' => 'Water the road, or move the haulage route.',
        ], $overrides);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_intake_assigns_a_case_id_and_starts_the_clocks(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake());

        $response->assertCreated();
        $this->assertMatchesRegularExpression('/^GRV-\d{4}$/', $response->json('data.reference'));
        $this->assertSame('new', $response->json('data.status'));
        $this->assertNotNull($response->json('data.sla.acknowledgement_due_at'));
        $this->assertNotNull($response->json('data.sla.resolution_due_at'));

        $this->assertDatabaseHas('sla_clocks', [
            'subject_type' => 'grievance',
            'subject_id' => $response->json('data.id'),
            'clock' => 'acknowledgement',
            'state' => 'running',
        ]);

        // Cycle 1 exists from the moment the case does.
        $this->assertDatabaseHas('grievance_resolution_cycles', [
            'grievance_id' => $response->json('data.id'),
            'cycle_number' => 1,
        ]);
    }

    public function test_every_channel_produces_one_unified_case(): void
    {
        $officer = $this->makeUser('grievance_officer');

        foreach (Grievance::CHANNELS as $channel) {
            $response = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake([
                'channel' => $channel,
                'confidentiality' => $channel === 'suggestion_box' ? 'anonymous' : 'normal',
            ]));

            $response->assertCreated();
            $this->assertSame($channel, $response->json('data.channel'));
        }

        $this->assertCount(count(Grievance::CHANNELS), Grievance::all());
    }

    public function test_an_idempotency_key_prevents_a_retried_submission_creating_a_second_case(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $payload = $this->intake(['idempotency_key' => 'whatsapp:msg-12345']);

        $first = $this->asUser($officer)->postJson('/api/v1/grievances', $payload);
        $second = $this->asUser($officer)->postJson('/api/v1/grievances', $payload);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Grievance::count());
    }

    public function test_a_suggestion_box_case_records_that_acknowledgement_was_not_possible(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake([
            'channel' => 'suggestion_box',
            'confidentiality' => 'anonymous',
        ]));

        $response->assertCreated();
        $this->assertFalse($response->json('data.acknowledgement_possible'));
        $this->assertNotEmpty($response->json('data.acknowledgement_not_possible_reason'));
    }

    public function test_the_full_lifecycle_runs_to_closure(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $hse = $this->makeUser('hse_officer');

        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/classify", [
            'category_id' => $this->categories['environment']->id,
            'subcategory_id' => $this->categories['dust']->id,
            'severity' => 3,
        ])->assertOk()->assertJsonPath('data.classification_confirmed', true);

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/assign", [
            'assigned_to_id' => $hse->id,
            'reason' => 'Environmental matter.',
        ])->assertOk()->assertJsonPath('data.status', 'assigned');

        $this->asUser($hse)->postJson("/api/v1/grievances/{$id}/acknowledge", [
            'method' => 'sms',
        ])->assertOk()->assertJsonPath('data.status', 'acknowledged');

        // The acknowledgement clock is met, not merely stopped.
        $this->assertDatabaseHas('sla_clocks', [
            'subject_id' => $id, 'clock' => 'acknowledgement', 'state' => 'met',
        ]);

        $this->asUser($hse)->postJson("/api/v1/grievances/{$id}/investigation/start")
            ->assertOk()->assertJsonPath('data.status', 'under_investigation');

        $this->asUser($hse)->postJson("/api/v1/grievances/{$id}/investigation", [
            'investigation_summary' => 'Site visit carried out with the ward executive officer.',
            'investigation_findings' => 'The complaint is substantiated. Watering had stopped.',
            'corrective_action' => 'Reinstate twice-daily watering and check weekly for a month.',
            'completed' => true,
        ])->assertOk()->assertJsonPath('data.status', 'action_pending');

        $this->asUser($hse)->postJson("/api/v1/grievances/{$id}/resolve", [
            'resolution_summary' => 'Watering reinstated and verified on site. The complainant was visited and shown the change.',
        ])->assertOk()->assertJsonPath('data.status', 'awaiting_confirmation');

        $this->asUser($hse)->postJson("/api/v1/grievances/{$id}/complainant-response", [
            'response' => 'accepted',
        ])->assertOk();

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/close", [
            'notes' => 'Closed with the complainant\'s agreement.',
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        $grievance = Grievance::find($id);
        $this->assertNotNull($grievance->closed_at);
        $this->assertSame('accepted', $grievance->complainant_response);

        foreach (['grievance.classified', 'grievance.assigned', 'grievance.acknowledged', 'grievance.resolved', 'grievance.closed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_id' => $id]);
        }
    }

    public function test_a_case_cannot_be_closed_before_it_is_resolved(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/close")
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_resolved');
    }

    public function test_reopening_keeps_the_case_id_and_opens_a_second_cycle(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');
        $reference = Grievance::find($id)->reference;

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/resolve", [
            'resolution_summary' => 'The road was watered for three days.',
        ]);
        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/close");

        $response = $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/reopen", [
            'reason' => 'The complainant says watering stopped after three days and the dust is back.',
        ]);

        $response->assertOk();
        $grievance = Grievance::find($id);

        // The case ID is retained — no duplicate case is created.
        $this->assertSame($reference, $grievance->reference);
        $this->assertSame('reopened', $grievance->status);
        $this->assertSame(2, $grievance->resolution_cycle);
        $this->assertSame(1, $grievance->reopen_count);
        $this->assertNull($grievance->closed_at);
        $this->assertSame(1, Grievance::count());

        // Both cycles are on the record, so the statistics cannot be flattered.
        $this->assertDatabaseCount('grievance_resolution_cycles', 2);
        $this->assertDatabaseHas('grievance_resolution_cycles', ['grievance_id' => $id, 'cycle_number' => 1]);
        $this->assertDatabaseHas('grievance_resolution_cycles', ['grievance_id' => $id, 'cycle_number' => 2]);

        // A fresh resolution clock for the new cycle; the first is preserved.
        $this->assertSame(2, SlaClock::where('subject_id', $id)->where('clock', 'resolution')->count());
    }

    public function test_an_open_case_cannot_be_reopened(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/reopen", ['reason' => 'Trying anyway.'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_closed');
    }

    public function test_assignment_keeps_a_history_rather_than_overwriting_a_field(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $hse = $this->makeUser('hse_officer');
        $hr = $this->makeUser('hr_officer');

        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/assign", ['assigned_to_id' => $hse->id]);
        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/assign", ['assigned_to_id' => $hr->id, 'reason' => 'Labour matter after all.']);

        $this->assertDatabaseCount('assignments', 2);
        $this->assertDatabaseHas('assignments', ['grievance_id' => $id, 'assigned_to_id' => $hse->id, 'is_current' => false]);
        $this->assertDatabaseHas('assignments', ['grievance_id' => $id, 'assigned_to_id' => $hr->id, 'is_current' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'grievance.reassigned']);
    }

    public function test_a_case_cannot_be_assigned_to_someone_outside_the_project(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');

        $outsider = User::create([
            'name' => 'Outside Person', 'email' => 'outside@test.local',
            'password' => bcrypt('password'), 'status' => 'active',
        ]);

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/assign", ['assigned_to_id' => $outsider->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'assignee_not_member');
    }

    public function test_a_resolution_needs_more_than_a_few_words(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/resolve", ['resolution_summary' => 'Fixed'])
            ->assertStatus(422);
    }

    public function test_a_field_officer_can_log_a_case_but_not_resolve_it(): void
    {
        $field = $this->makeUser('field_officer');

        $id = $this->asUser($field)->postJson('/api/v1/grievances', $this->intake())->json('data.id');
        $this->assertNotNull($id);

        $this->asUser($field)->postJson("/api/v1/grievances/{$id}/resolve", [
            'resolution_summary' => 'A resolution a field officer should not be able to record.',
        ])->assertStatus(403);
    }

    public function test_an_acknowledgement_is_logged_as_a_communication(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/acknowledge", ['method' => 'sms'])->assertOk();

        $this->assertDatabaseHas('communications', [
            'grievance_id' => $id,
            'direction' => 'outbound',
            'template_key' => 'acknowledgement',
            'status' => 'sent',
        ]);
    }

    public function test_follow_ups_build_the_case_timeline(): void
    {
        $officer = $this->makeUser('grievance_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', $this->intake())->json('data.id');

        $this->asUser($officer)->postJson("/api/v1/grievances/{$id}/follow-ups", [
            'type' => 'site_visit',
            'body' => 'Visited the location with the ward executive officer and photographed the conditions.',
            'occurred_on' => now()->toDateString(),
        ])->assertCreated();

        $show = $this->asUser($officer)->getJson("/api/v1/grievances/{$id}");
        $show->assertOk();
        $this->assertCount(1, $show->json('data.follow_ups'));
    }
}
