<?php

namespace Tests\Feature;

use App\Domain\Configuration\ConfigurationRegistry;
use App\Models\GrievanceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Anything that differs between projects is configuration; anything that is
 * the same everywhere is code."
 */
class ConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_a_project_value_overrides_the_organisation_value(): void
    {
        $registry = app(ConfigurationRegistry::class);

        $registry->set('languages', ['en'], $this->organisation->id, null);
        $registry->set('languages', ['sw', 'en'], $this->organisation->id, $this->project->id);

        $this->assertSame(['en'], $registry->get('languages', $this->organisation->id, null));
        $this->assertSame(['sw', 'en'], $registry->get('languages', $this->organisation->id, $this->project->id));
    }

    public function test_configuration_falls_back_to_the_file_defaults(): void
    {
        $registry = app(ConfigurationRegistry::class);

        $this->assertSame(
            config('sasa.disaggregation.minimum_cell_size'),
            $registry->get('disaggregation.minimum_cell_size', $this->organisation->id, $this->project->id)
        );
    }

    public function test_changing_the_priority_weights_changes_the_score_without_a_release(): void
    {
        $admin = $this->makeUser('project_admin');

        $before = $this->asUser($admin)->postJson('/api/v1/stakeholders', [
            'name' => 'Before', 'type' => 'individual',
            'influence' => 'high', 'interest' => 'low', 'power' => 'low', 'impact' => 'low',
        ])->json('data.assessment.score');

        $this->assertSame(6, $before);

        $this->asUser($admin)->putJson('/api/v1/configuration', [
            'key' => 'priority',
            'value' => ['weights' => ['influence' => 3, 'interest' => 1, 'power' => 1, 'impact' => 1]],
        ])->assertOk();

        $after = $this->asUser($admin)->postJson('/api/v1/stakeholders', [
            'name' => 'After', 'type' => 'individual',
            'influence' => 'high', 'interest' => 'low', 'power' => 'low', 'impact' => 'low',
        ])->json('data.assessment.score');

        $this->assertSame(12, $after); // (3 x 3) + 1 + 1 + 1
    }

    public function test_only_authorised_roles_can_change_configuration(): void
    {
        $field = $this->makeUser('field_officer');

        $this->asUser($field)->putJson('/api/v1/configuration', ['key' => 'languages', 'value' => ['en']])
            ->assertStatus(403);
    }

    public function test_a_retired_category_disappears_from_new_cases_but_stays_on_old_ones(): void
    {
        $admin = $this->makeUser('project_admin');
        $officer = $this->makeUser('grievance_officer');

        $caseId = $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'normal',
            'title' => 'A case classified before the category was retired',
            'description' => 'Recorded while the category was still in use.',
            'category_id' => $this->categories['environment']->id,
        ])->json('data.id');

        $this->asUser($admin)->postJson("/api/v1/configuration/categories/{$this->categories['environment']->id}/retire")
            ->assertOk();

        $selectable = $this->asUser($admin)->getJson('/api/v1/configuration/categories');
        $this->assertFalse(collect($selectable->json('data'))->contains('id', $this->categories['environment']->id));

        // The historical case keeps its category.
        $case = $this->asUser($officer)->getJson("/api/v1/grievances/{$caseId}");
        $this->assertSame('Environmental, Health & Safety', $case->json('data.category.name'));
    }

    public function test_a_restricted_category_can_be_created_from_configuration(): void
    {
        $admin = $this->makeUser('project_admin');

        $response = $this->asUser($admin)->postJson('/api/v1/configuration/categories', [
            'key' => 'community_security',
            'name' => 'Community Security',
            'default_severity' => 4,
            'is_restricted' => true,
            'handling_groups' => ['security_handling'],
        ]);

        $response->assertCreated();
        $this->assertTrue(GrievanceCategory::find($response->json('data.id'))->is_restricted);
    }

    public function test_an_sla_standard_can_be_changed_per_severity(): void
    {
        $admin = $this->makeUser('project_admin');

        $this->asUser($admin)->postJson('/api/v1/configuration/sla-policies', [
            'clock' => 'resolution',
            'unit' => 'working_days',
            'target_value' => 3,
            'severity' => 5,
            'reminder_thresholds' => [40, 70],
        ])->assertCreated();

        $officer = $this->makeUser('grievance_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'normal', 'severity' => 5,
            'title' => 'A critical case', 'description' => 'It should get the tighter standard.',
        ])->json('data.id');

        $this->assertDatabaseHas('sla_clocks', [
            'subject_id' => $id, 'clock' => 'resolution', 'target_value' => 3,
        ]);
    }

    public function test_the_working_calendar_and_holidays_are_editable(): void
    {
        $admin = $this->makeUser('project_admin');

        $this->asUser($admin)->patchJson("/api/v1/configuration/calendars/{$this->calendar->id}", [
            'working_days' => [1, 2, 3, 4, 5, 6],
            'work_start' => '07:30',
            'work_end' => '16:30',
        ])->assertOk();

        $this->asUser($admin)->postJson("/api/v1/configuration/calendars/{$this->calendar->id}/holidays", [
            'date' => '2026-12-09',
            'name' => 'Independence Day',
            'recurs_annually' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('holidays', ['name' => 'Independence Day']);
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->calendar->fresh()->working_days);
    }

    public function test_notification_rules_are_configurable_per_project(): void
    {
        $admin = $this->makeUser('project_admin');

        $this->asUser($admin)->putJson('/api/v1/configuration/notification-rules', [
            'event_key' => 'grievance.created',
            'name' => 'A new grievance is logged',
            'recipient_roles' => ['project_admin'],
            'channels' => ['in_app'],
            'template_subject' => 'New case {reference}',
            'template_body' => 'A new case arrived via {channel}.',
        ])->assertOk();

        $this->assertDatabaseHas('notification_rules', [
            'event_key' => 'grievance.created',
            'project_id' => $this->project->id,
            'template_subject' => 'New case {reference}',
        ]);
    }

    public function test_the_configuration_screen_lists_what_can_be_changed(): void
    {
        $admin = $this->makeUser('project_admin');

        $response = $this->asUser($admin)->getJson('/api/v1/configuration');

        $response->assertOk()->assertJsonStructure(['data' => ['catalogue', 'values', 'defaults', 'lists']]);
        $this->assertNotEmpty($response->json('data.lists.disaggregation_dimensions'));

        // Sensitive dimensions are off by default; collecting them lawfully is
        // a decision for the project, not a default.
        $dimensions = collect($response->json('data.lists.disaggregation_dimensions'));
        $genderIdentity = $dimensions->firstWhere('key', 'gender_identity');
        $this->assertFalse($genderIdentity['enabled']);
        $this->assertTrue($genderIdentity['sensitive']);
    }
}
