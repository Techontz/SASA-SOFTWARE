<?php

namespace Tests\Feature;

use App\Domain\Configuration\ConfigurationKeys;
use App\Domain\Configuration\ConfigurationRegistry;
use App\Domain\Eshs\EshsConfiguration;
use App\Domain\Stakeholder\StakeholderService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client's 17 November 2026 review: the fields marked "optional" on the
 * six staff forms are mandatory. The API holds the line, not just the form.
 */
class RequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sasa.required_fields' => $this->realRequiredFields]);
        $this->seedPlatform();
        $this->admin = $this->makeUser('project_admin');
    }

    private function errorsFor(string $path, array $payload, string $method = 'postJson'): array
    {
        $response = $this->asUser($this->admin)->{$method}($path, $payload);
        $response->assertStatus(422);

        return array_keys($response->json('errors'));
    }

    public function test_a_grievance_needs_every_field_the_review_marked(): void
    {
        $errors = $this->errorsFor('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'normal', 'description' => 'Dust on the road.',
        ]);

        foreach ([
            'complainant_name', 'complainant_phone', 'complainant_email', 'complainant_type',
            'complainant_language', 'preferred_contact_method', 'title', 'desired_resolution',
            'location_id', 'location_text', 'demographics.gender',
        ] as $field) {
            $this->assertContains($field, $errors, "$field should be required");
        }

        // "Already in the register?" is answered in the form — "no" is an answer.
        $this->assertNotContains('stakeholder_id', $errors);
    }

    public function test_the_required_message_is_plain_language(): void
    {
        $response = $this->asUser($this->admin)->postJson('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'normal', 'description' => 'Dust on the road.',
        ]);

        $this->assertSame('This is needed before the record can be saved.', $response->json('errors.complainant_phone.0'));
        $this->assertSame('Add their e-mail, or tick that they have none.', $response->json('errors.complainant_email.0'));
    }

    public function test_an_anonymous_grievance_is_never_asked_who_raised_it(): void
    {
        $errors = $this->errorsFor('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'anonymous', 'description' => 'Dust on the road.',
        ]);

        foreach (['complainant_name', 'complainant_phone', 'complainant_email', 'preferred_contact_method'] as $field) {
            $this->assertNotContains($field, $errors, "$field must not be asked on an anonymous case");
        }
        $this->assertContains('title', $errors);
    }

    public function test_a_complete_grievance_saves_and_no_email_is_an_honest_answer(): void
    {
        $this->asUser($this->admin)->postJson('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'normal',
            'complainant_name' => 'Rehema Mollel', 'complainant_phone' => '+255754000111',
            // Sent the way the form sends it: an empty e-mail and the tick.
            'complainant_email' => null, 'complainant_has_no_email' => true,
            'complainant_type' => 'community_member', 'complainant_language' => 'sw',
            'preferred_contact_method' => 'phone',
            'title' => 'Dust from haulage trucks', 'description' => 'Trucks pass every few minutes.',
            'desired_resolution' => 'Water the road.',
            'location_id' => $this->village->id, 'location_text' => 'Near the school',
            'demographics' => ['gender' => 'female'],
        ])->assertCreated();
    }

    public function test_a_commitment_needs_its_dates_owner_people_and_place(): void
    {
        $errors = $this->errorsFor('/api/v1/commitments', ['commitment_text' => 'Water the road.']);

        foreach (['source_date', 'due_date', 'owner_id', 'owner_team', 'stakeholder_ids', 'location_id', 'notes'] as $field) {
            $this->assertContains($field, $errors, "$field should be required");
        }
    }

    public function test_changing_one_thing_on_a_commitment_does_not_resend_everything(): void
    {
        $stakeholder = app(StakeholderService::class)->create($this->project, ['name' => 'Buswelu Council', 'type' => 'community_group']);

        $created = $this->asUser($this->admin)->postJson('/api/v1/commitments', [
            'commitment_text' => 'Water the road.', 'source_date' => today()->toDateString(),
            'due_date' => today()->addDays(7)->toDateString(), 'owner_id' => $this->admin->id,
            'owner_team' => 'Community relations', 'stakeholder_ids' => [$stakeholder->id],
            'location_id' => $this->village->id, 'notes' => 'Agreed at the ward meeting.',
        ])->assertCreated();

        $this->asUser($this->admin)
            ->patchJson('/api/v1/commitments/'.$created->json('data.id'), ['commitment_text' => 'Water the road twice a day.'])
            ->assertOk();

        // But a required field cannot be emptied.
        $this->asUser($this->admin)
            ->patchJson('/api/v1/commitments/'.$created->json('data.id'), ['notes' => ''])
            ->assertStatus(422);
    }

    public function test_an_engagement_needs_its_people_numbers_and_minutes(): void
    {
        $errors = $this->errorsFor('/api/v1/engagements', ['topic' => 'Corridor disclosure', 'held_at' => now()->toDateTimeString()]);

        foreach ([
            'method', 'project_phase', 'facilitator_id', 'location_id', 'venue', 'organised_by', 'stakeholder_ids',
            'attendance_total', 'attendance_female', 'attendance_male', 'attendance_youth', 'attendance_elderly',
            'attendance_disability', 'aim', 'discussion_points', 'outcomes',
        ] as $field) {
            $this->assertContains($field, $errors, "$field should be required");
        }
        $this->assertNotContains('engagement_plan_id', $errors, '"Not planned" is an answer');
    }

    public function test_a_stakeholder_needs_contact_place_and_priority(): void
    {
        $errors = $this->errorsFor('/api/v1/stakeholders', ['name' => 'Buswelu Council', 'type' => 'community_group']);

        foreach ([
            'phone', 'preferred_language', 'preferred_contact_method', 'primary_location_id', 'physical_address',
            'influence', 'interest', 'power', 'impact', 'concerns_expectations', 'notes',
        ] as $field) {
            $this->assertContains($field, $errors, "$field should be required");
        }
        $this->assertNotContains('email', $errors);
    }

    public function test_a_priority_dimension_switched_off_is_not_compulsory(): void
    {
        app(ConfigurationRegistry::class)->set('priority', ['weights' => ['power' => 0]], $this->organisation->id, $this->project->id);

        $errors = $this->errorsFor('/api/v1/stakeholders', ['name' => 'Buswelu Council', 'type' => 'community_group']);

        $this->assertNotContains('power', $errors);
        $this->assertContains('influence', $errors);
    }

    public function test_an_eshs_anomaly_needs_its_place_cause_and_action_owner(): void
    {
        app(ConfigurationRegistry::class)->set(EshsConfiguration::CONTRACTORS, ['Alpha Builders'], $this->organisation->id, $this->project->id);
        $raiser = $this->makeUser('hse_officer');

        $response = $this->asUser($raiser)->postJson('/api/v1/eshs', [
            'raised_on' => today()->toDateString(), 'contractor' => 'Alpha Builders', 'category' => 'safety',
            'subcategory' => 'Work at Height', 'description' => 'Two workers without a second lanyard.',
            'anomaly_type' => 'Non-Conformance', 'risk_rating' => 'critical',
        ])->assertStatus(422);

        foreach (['location_id', 'root_cause', 'contractor_action', 'action_owner_name', 'action_owner_email', 'action_owner_phone', 'target_date'] as $field) {
            $this->assertArrayHasKey($field, $response->json('errors'), "$field should be required");
        }
    }

    public function test_an_incident_needs_company_site_and_people_when_the_project_has_those_lists(): void
    {
        $registry = app(ConfigurationRegistry::class);
        $registry->set('incident_companies', ['Mwanza Civils'], $this->organisation->id, $this->project->id);
        $registry->set('incident_contractor_managers', ['Joseph Mrema'], $this->organisation->id, $this->project->id);
        // No resident engineers listed yet: that field cannot be compulsory.
        $registry->set('incident_resident_engineers', [], $this->organisation->id, $this->project->id);

        $response = $this->asUser($this->makeUser('hse_officer'))->postJson('/api/v1/incidents', [
            'occurred_at' => now()->subHour()->toDateTimeString(), 'category' => 'personal_injury',
            'incident_type' => 'hs_fac', 'actual_consequence' => 1, 'potential_consequence' => 2,
            'summary' => 'Rigger cut his hand on a steel edge.',
        ])->assertStatus(422);

        $errors = array_keys($response->json('errors'));
        foreach (['company', 'location_id', 'site_detail', 'contractor_manager'] as $field) {
            $this->assertContains($field, $errors, "$field should be required");
        }
        $this->assertNotContains('resident_engineer', $errors);
    }

    public function test_the_device_bootstrap_carries_the_lists_and_a_project_can_change_them(): void
    {
        $bootstrap = $this->asUser($this->admin)->getJson('/api/v1/sync/bootstrap')->assertOk();
        $this->assertContains('complainant_phone', $bootstrap->json('data.required_fields.grievance'));

        $lists = $this->realRequiredFields;
        $lists['grievance'] = ['title'];
        app(ConfigurationRegistry::class)->set(ConfigurationKeys::REQUIRED_FIELDS, $lists, $this->organisation->id, $this->project->id);

        $errors = $this->errorsFor('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'normal', 'description' => 'Dust on the road.',
        ]);
        $this->assertSame(['title'], $errors);
    }

    public function test_the_browser_starts_with_the_same_lists_as_the_server(): void
    {
        $file = base_path('../web/src/lib/requiredFields.defaults.json');

        if (! is_file($file)) {
            $this->markTestSkipped('The web app is not checked out next to the API.');
        }

        $this->assertSame($this->realRequiredFields, json_decode(file_get_contents($file), true));
    }
}
