<?php

namespace Tests\Feature;

use App\Domain\Configuration\ConfigurationRegistry;
use App\Domain\Incident\IncidentOptions;
use App\Domain\Incident\IncidentService;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Notifications\SasaNotification;
use Database\Seeders\IncidentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IncidentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'occurred_at' => now()->subHours(3)->format('Y-m-d H:i:s'),
            'location_id' => $this->village->id,
            'site_detail' => 'Tower 42 access road',
            'category' => 'personal_injury',
            'incident_type' => 'hs_fac',
            'actual_consequence' => 1,
            'potential_consequence' => 2,
            'summary' => 'Rigger cut his hand on a steel edge while unloading; first aid given on site.',
        ], $overrides);
    }

    private function configureLists(?Project $project = null): void
    {
        $project ??= $this->project;
        $registry = app(ConfigurationRegistry::class);
        $registry->set('incident_companies', ['Kilimanjaro Lines Ltd', 'Mwanza Civils'], $project->organisation_id, $project->id);
        $registry->set('incident_contractor_managers', ['Joseph Mrema'], $project->organisation_id, $project->id);
        $registry->set('incident_resident_engineers', ['Eng. Neema Kweka'], $project->organisation_id, $project->id);
    }

    private function report(?User $user = null, array $overrides = []): Incident
    {
        $user ??= $this->makeUser('field_officer');
        $response = $this->asUser($user)->postJson('/api/v1/incidents', $this->payload($overrides));
        $response->assertCreated();

        return Incident::findOrFail($response->json('data.id'));
    }

    public function test_a_field_officer_reports_an_incident_with_a_reference_and_submitted_status(): void
    {
        $this->configureLists();
        $user = $this->makeUser('field_officer');

        $response = $this->asUser($user)->postJson('/api/v1/incidents', $this->payload([
            'company' => 'Mwanza Civils',
            'contractor_manager' => 'Joseph Mrema',
            'resident_engineer' => 'Eng. Neema Kweka',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.company', 'Mwanza Civils')
            ->assertJsonPath('data.category_label', 'Incident – Personal Injury')
            ->assertJsonPath('data.incident_type_label', 'Health & Safety - First Aid Case (FAC)')
            ->assertJsonPath('data.potential_consequence_label', 'Level 2: Moderate')
            ->assertJsonPath('data.is_serious', false)
            ->assertJsonPath('data.reported_by.id', $user->id);

        $this->assertMatchesRegularExpression('/^INC-\d{4}$/', $response->json('data.reference'));

        $this->asUser($user)->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($user)->getJson('/api/v1/incidents/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.location.id', $this->village->id);
    }

    public function test_reported_by_is_the_signed_in_user_even_when_another_id_is_sent(): void
    {
        $someoneElse = $this->makeUser('hse_officer');
        $reporter = $this->makeUser('field_officer');

        $incident = $this->report($reporter, ['reported_by' => $someoneElse->id, 'project_id' => 999999]);

        $this->assertSame($reporter->id, (int) $incident->reported_by);
        $this->assertSame($this->project->id, (int) $incident->project_id);
    }

    public function test_invalid_category_type_and_levels_are_rejected(): void
    {
        $user = $this->makeUser('field_officer');

        $this->asUser($user)->postJson('/api/v1/incidents', $this->payload([
            'category' => 'weather',
            'incident_type' => 'alien_landing',
            'actual_consequence' => 6,
            'potential_consequence' => -1,
        ]))->assertStatus(422)->assertJsonValidationErrors(['category', 'incident_type', 'actual_consequence', 'potential_consequence']);

        $this->asUser($user)->postJson('/api/v1/incidents', $this->payload([
            'actual_consequence' => 3, 'potential_consequence' => 1,
        ]))->assertStatus(422)->assertJsonValidationErrors(['potential_consequence']);

        $this->asUser($user)->postJson('/api/v1/incidents', $this->payload(['summary' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['summary']);
    }

    public function test_any_type_is_allowed_under_any_category(): void
    {
        $this->report(null, ['category' => 'social', 'incident_type' => 'industrial_action']);
        $this->report(null, ['category' => 'near_miss', 'incident_type' => 'ml_dropped_objects', 'actual_consequence' => 0, 'potential_consequence' => 3]);

        $this->assertSame(2, Incident::count());
    }

    public function test_company_and_names_must_come_from_the_project_lists(): void
    {
        $user = $this->makeUser('field_officer');

        // No lists configured: the default is empty, so nothing is accepted.
        $this->asUser($user)->postJson('/api/v1/incidents', $this->payload(['company' => 'Anyone Ltd']))
            ->assertStatus(422)->assertJsonValidationErrors(['company']);

        $this->configureLists();

        $this->asUser($user)->postJson('/api/v1/incidents', $this->payload([
            'company' => 'Not On The List', 'contractor_manager' => 'Stranger', 'resident_engineer' => 'Nobody',
        ]))->assertStatus(422)->assertJsonValidationErrors(['company', 'contractor_manager', 'resident_engineer']);
    }

    public function test_per_project_lists_are_overridden_through_the_registry(): void
    {
        $other = Project::create([
            'organisation_id' => $this->organisation->id, 'name' => 'Other line', 'code' => 'OTHER-1',
            'country' => 'TZ', 'timezone' => 'Africa/Dar_es_Salaam', 'status' => 'active',
        ]);
        $this->configureLists();

        $user = $this->makeUser('field_officer');
        $this->asUser($user)->getJson('/api/v1/incidents/options')->assertOk()
            ->assertJsonPath('data.companies', ['Kilimanjaro Lines Ltd', 'Mwanza Civils'])
            ->assertJsonPath('data.contractor_managers', ['Joseph Mrema'])
            ->assertJsonCount(6, 'data.categories')
            ->assertJsonCount(46, 'data.types')
            ->assertJsonCount(6, 'data.consequence_levels');

        $elsewhere = $this->makeUser('field_officer', ['general'], $other);
        $this->asUser($elsewhere, $other)->getJson('/api/v1/incidents/options')->assertOk()
            ->assertJsonPath('data.companies', []);
    }

    public function test_only_configuration_managers_can_edit_the_lists_and_the_change_is_audited(): void
    {
        $this->asUser($this->makeUser('field_officer'))
            ->putJson('/api/v1/incidents/settings', ['companies' => ['A']])
            ->assertForbidden();

        $admin = $this->makeUser('project_admin');
        $this->asUser($admin)->putJson('/api/v1/incidents/settings', [
            'companies' => ['Tanesco', ' Tanesco ', 'Sinohydro'],
            'resident_engineers' => ['Eng. Baraka'],
        ])->assertOk()
            ->assertJsonPath('data.companies', ['Tanesco', 'Sinohydro'])
            ->assertJsonPath('data.resident_engineers', ['Eng. Baraka'])
            ->assertJsonPath('data.contractor_managers', []);

        $this->assertTrue(AuditLog::where('action', 'configuration.changed')->exists());

        $this->asUser($this->makeUser('field_officer'))
            ->postJson('/api/v1/incidents', $this->payload(['company' => 'Sinohydro']))
            ->assertCreated();
    }

    public function test_permission_denials(): void
    {
        // grievance_officer holds no incident permission at all.
        $outsider = $this->makeUser('grievance_officer');
        $this->asUser($outsider)->getJson('/api/v1/incidents')->assertForbidden();
        $this->asUser($outsider)->getJson('/api/v1/incidents/summary')->assertForbidden();
        $this->asUser($outsider)->postJson('/api/v1/incidents', $this->payload())->assertForbidden();

        // management can view but not report.
        $viewer = $this->makeUser('management');
        $this->asUser($viewer)->getJson('/api/v1/incidents')->assertOk();
        $this->asUser($viewer)->postJson('/api/v1/incidents', $this->payload())->assertForbidden();
    }

    public function test_only_incident_managers_can_review_and_close(): void
    {
        $reporter = $this->makeUser('field_officer');
        $incident = $this->report($reporter);

        $this->asUser($reporter)->postJson("/api/v1/incidents/{$incident->id}/status", ['status' => 'closed'])
            ->assertForbidden();
        $this->asUser($this->makeUser('management'))->postJson("/api/v1/incidents/{$incident->id}/status", ['status' => 'under_review'])
            ->assertForbidden();

        $manager = $this->makeUser('hse_officer');
        $this->asUser($manager)->postJson("/api/v1/incidents/{$incident->id}/status", [
            'status' => 'under_review', 'review_note' => 'Toolbox talk on edge protection booked.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.reviewed_by', $manager->name)
            ->assertJsonPath('data.review_note', 'Toolbox talk on edge protection booked.');

        $response = $this->asUser($manager)->postJson("/api/v1/incidents/{$incident->id}/status", ['status' => 'closed']);
        $response->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed_by', $manager->name);
        $this->assertNotNull($incident->fresh()->closed_at);

        // Closed is final.
        $this->asUser($manager)->postJson("/api/v1/incidents/{$incident->id}/status", ['status' => 'under_review'])
            ->assertStatus(422);

        $this->assertTrue(AuditLog::where('action', 'incident.status_changed')->exists());
    }

    public function test_the_review_note_is_absent_for_people_who_cannot_manage(): void
    {
        $incident = $this->report();
        $manager = $this->makeUser('hse_officer');
        $this->asUser($manager)->postJson("/api/v1/incidents/{$incident->id}/status", [
            'status' => 'under_review', 'review_note' => 'Internal: contractor warned.',
        ])->assertOk();

        $viewer = $this->makeUser('auditor');
        $response = $this->asUser($viewer)->getJson("/api/v1/incidents/{$incident->id}")->assertOk();
        $this->assertArrayNotHasKey('review_note', $response->json('data'));
    }

    public function test_the_reporter_may_add_evidence_while_submitted_but_not_other_reporters(): void
    {
        $reporter = $this->makeUser('field_officer');
        $incident = $this->report($reporter);

        $url = "/api/v1/incidents/{$incident->id}";

        $this->asUser($reporter)->getJson($url)->assertJsonPath('data.permissions.can_add_evidence', true);
        $this->asUser($this->makeUser('field_officer'))->getJson($url)->assertJsonPath('data.permissions.can_add_evidence', false);
        $this->asUser($this->makeUser('hse_officer'))->getJson($url)->assertJsonPath('data.permissions.can_add_evidence', true);

        // Once a manager has picked it up, the reporter's window to edit closes.
        $this->asUser($this->makeUser('hse_officer'))->postJson("{$url}/status", ['status' => 'under_review'])->assertOk();
        $this->asUser($reporter)->getJson($url)->assertJsonPath('data.permissions.can_add_evidence', false);
        $this->asUser($reporter)->patchJson($url, ['site_detail' => 'Changed'])->assertForbidden();
    }

    public function test_serious_flag_derivation(): void
    {
        $level4 = $this->report(null, ['actual_consequence' => 2, 'potential_consequence' => 4]);
        $fatality = $this->report(null, ['incident_type' => 'hs_fatality', 'actual_consequence' => 1, 'potential_consequence' => 1]);
        $disability = $this->report(null, ['incident_type' => 'hs_permanent_disability', 'potential_consequence' => 3]);
        $minor = $this->report(null, ['potential_consequence' => 3]);

        $this->assertTrue($level4->isSerious());
        $this->assertTrue($fatality->isSerious());
        $this->assertTrue($disability->isSerious());
        $this->assertFalse($minor->isSerious());

        $this->assertEqualsCanonicalizing(
            [$level4->id, $fatality->id, $disability->id],
            Incident::query()->serious()->pluck('id')->all(),
        );

        $user = $this->makeUser('auditor');
        $this->asUser($user)->getJson('/api/v1/incidents?serious=1')->assertOk()->assertJsonCount(3, 'data');
        $this->asUser($user)->getJson("/api/v1/incidents/{$fatality->id}")->assertJsonPath('data.is_serious', true);
    }

    public function test_incident_managers_are_notified_and_serious_ones_are_marked(): void
    {
        Notification::fake();
        $manager = $this->makeUser('hse_officer');
        $viewer = $this->makeUser('auditor');

        $this->report(null, ['potential_consequence' => 5, 'actual_consequence' => 2]);

        Notification::assertSentTo($manager, SasaNotification::class, function (SasaNotification $n) {
            return $n->eventKey === IncidentService::EVENT_REPORTED
                && $n->severity === 'danger'
                && str_starts_with($n->subject, 'SERIOUS')
                && $n->payload['serious'] === true;
        });
        Notification::assertNotSentTo($viewer, SasaNotification::class);
    }

    public function test_summary_numbers(): void
    {
        $this->report(null, ['category' => 'near_miss', 'incident_type' => 'near_miss', 'actual_consequence' => 0, 'potential_consequence' => 2]);
        $this->report(null, ['category' => 'near_miss', 'incident_type' => 'near_miss', 'actual_consequence' => 0, 'potential_consequence' => 4]);
        $this->report(null, ['category' => 'environmental', 'incident_type' => 'env_spill_hydrocarbon', 'actual_consequence' => 2, 'potential_consequence' => 3]);
        $old = $this->report(null, ['occurred_at' => now()->subDays(60)->format('Y-m-d H:i:s')]);
        $archived = $this->report(null, ['category' => 'security', 'incident_type' => 'sec_theft']);

        $manager = $this->makeUser('hse_officer');
        $this->asUser($manager)->postJson("/api/v1/incidents/{$old->id}/status", ['status' => 'closed'])->assertOk();
        $this->asUser($manager)->deleteJson("/api/v1/incidents/{$archived->id}")->assertOk();

        $data = $this->asUser($this->makeUser('auditor'))->getJson('/api/v1/incidents/summary')->assertOk()->json('data');

        $this->assertSame(4, $data['total']);
        $this->assertSame(3, $data['open']);
        $this->assertSame(1, $data['serious']);
        $this->assertSame(3, $data['last_30_days']);
        $this->assertSame(2, $data['near_misses']);
        $this->assertEquals(50.0, $data['near_miss_ratio']);
        $this->assertSame(3, collect($data['by_status'])->firstWhere('key', 'submitted')['count']);
        $this->assertSame(1, collect($data['by_status'])->firstWhere('key', 'closed')['count']);
        $this->assertSame(2, collect($data['by_category'])->firstWhere('key', 'near_miss')['count']);
        $this->assertSame(0, collect($data['by_category'])->firstWhere('key', 'security')['count']);
        $this->assertSame(2, collect($data['by_actual_consequence'])->firstWhere('level', 0)['count']);
        $this->assertSame(1, collect($data['by_potential_consequence'])->firstWhere('level', 4)['count']);
        $this->assertCount(6, $data['by_potential_consequence']);
    }

    public function test_a_record_from_another_project_is_not_found(): void
    {
        $other = Project::create([
            'organisation_id' => $this->organisation->id, 'name' => 'Other line', 'code' => 'OTHER-2',
            'country' => 'TZ', 'timezone' => 'Africa/Dar_es_Salaam', 'status' => 'active',
        ]);
        $incident = $this->report();

        $elsewhere = $this->makeUser('hse_officer', ['general'], $other);
        $this->asUser($elsewhere, $other)->getJson("/api/v1/incidents/{$incident->id}")->assertNotFound();
        $this->asUser($elsewhere, $other)->postJson("/api/v1/incidents/{$incident->id}/status", ['status' => 'closed'])->assertNotFound();
        $this->asUser($elsewhere, $other)->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_archive_does_not_delete_and_is_audited(): void
    {
        $incident = $this->report();
        $manager = $this->makeUser('hse_officer');

        $this->asUser($this->makeUser('field_officer'))->deleteJson("/api/v1/incidents/{$incident->id}")->assertForbidden();
        $this->asUser($manager)->deleteJson("/api/v1/incidents/{$incident->id}")->assertOk();

        $fresh = Incident::find($incident->id);
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->archived_at);
        $this->assertTrue(AuditLog::where('action', 'incident.archived')->exists());

        $this->asUser($manager)->getJson('/api/v1/incidents')->assertJsonCount(0, 'data');
        $this->asUser($manager)->getJson('/api/v1/incidents?include_archived=1')->assertJsonCount(1, 'data');
        $this->asUser($manager)->postJson("/api/v1/incidents/{$incident->id}/status", ['status' => 'closed'])->assertForbidden();
    }

    public function test_export_is_gated_by_incident_view(): void
    {
        $this->report();

        $this->asUser($this->makeUser('grievance_officer'))->get('/api/v1/incidents/export')->assertForbidden();
        $this->asUser($this->makeUser('auditor'))->get('/api/v1/incidents/export?format=xlsx')->assertOk();
    }

    public function test_the_demo_seeder_configures_lists_and_seeds_fourteen_incidents(): void
    {
        $this->makeUser('hse_officer');
        $this->project->forceFill(['code' => 'NBT-400KV'])->save();

        $this->seed(IncidentDemoSeeder::class);

        $this->assertSame(14, Incident::count());
        $this->assertSame(2, Incident::query()->serious()->count());
        $this->assertSame(3, count(app(IncidentOptions::class)->list($this->project->fresh(), 'companies')));
        $this->assertSame(['submitted', 'under_review', 'closed'], Incident::query()->distinct()->pluck('status')->sort(fn ($a, $b) => array_search($a, Incident::STATUSES) <=> array_search($b, Incident::STATUSES))->values()->all());
    }
}
