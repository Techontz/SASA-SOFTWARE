<?php

namespace Tests\Feature;

use App\Domain\Configuration\ConfigurationRegistry;
use App\Domain\Eshs\EshsConfiguration;
use App\Models\Attachment;
use App\Models\EshsAnomaly;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The client's ESHS contractor assurance rules: the client / supervision team
 * raises, the contractor responds with closure evidence, only supervision
 * closes — and one contractor never sees another's anomalies.
 */
class EshsAssuranceTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $contractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        Storage::fake('local');

        app(ConfigurationRegistry::class)->set(EshsConfiguration::CONTRACTORS, ['Alpha Builders', 'Beta Civils'], $this->organisation->id, $this->project->id);
        app(ConfigurationRegistry::class)->set(EshsConfiguration::SUBCONTRACTORS, ['Gamma Haulage'], $this->organisation->id, $this->project->id);

        $this->supervisor = $this->makeUser('hse_officer');      // eshs.raise + eshs.verify
        $this->contractor = $this->contractorUser('Alpha Builders');
    }

    private function contractorUser(?string $company, ?Project $project = null): User
    {
        $user = $this->makeUser('contractor', [], $project);

        ProjectMembership::where('user_id', $user->id)->update(['contractor_name' => $company]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'raised_on' => today()->subDays(3)->toDateString(),
            'location_id' => $this->village->id,
            'contractor' => 'Alpha Builders',
            'subcontractor' => 'Gamma Haulage',
            'category' => 'safety',
            'subcategory' => 'Work at Height',
            'description' => 'Two workers on tower T-12 without a second lanyard attached.',
            'anomaly_type' => 'Non-Conformance',
            'risk_rating' => 'critical',
            'root_cause_group' => 'People',
            'root_cause' => 'Failure to Follow Procedure',
            'contractor_action' => 'Stand down and re-brief the crew.',
            'action_owner_name' => 'Kisare Lutambi',
            'action_owner_email' => 'kisare@alpha.example',
            'action_owner_phone' => '+255764309306',
            'target_date' => today()->addDays(7)->toDateString(),
        ], $overrides);
    }

    private function raise(array $overrides = []): EshsAnomaly
    {
        $response = $this->asUser($this->supervisor)->postJson('/api/v1/eshs', $this->payload($overrides));
        $response->assertCreated();

        return EshsAnomaly::findOrFail($response->json('data.id'));
    }

    private function uploadClosureEvidence(EshsAnomaly $anomaly, User $as)
    {
        return $this->asUser($as)->post('/api/v1/attachments', [
            'attachable_type' => 'eshs_anomaly',
            'attachable_id' => $anomaly->id,
            'kind' => 'closure_evidence',
            'file' => UploadedFile::fake()->image('barricade.jpg'),
        ], $this->projectHeaders());
    }

    // ------------------------------------------------------------ raising

    public function test_supervision_raises_an_anomaly_with_a_reference_and_every_field(): void
    {
        $anomaly = $this->raise();

        $this->assertMatchesRegularExpression('/^ESA-\d{4}$/', $anomaly->reference);
        $this->assertSame('open', $anomaly->status);
        $this->assertSame($this->supervisor->id, $anomaly->raised_by);
        $this->assertSame('People', $anomaly->root_cause_group);
        $this->assertSame('Gamma Haulage', $anomaly->subcontractor);
        $this->assertDatabaseHas('audit_logs', ['action' => 'eshs_anomaly.created', 'entity_id' => $anomaly->id]);

        $this->asUser($this->supervisor)->getJson("/api/v1/eshs/{$anomaly->id}")
            ->assertOk()
            ->assertJsonPath('data.stage', 'open')
            ->assertJsonPath('data.category_label', 'Safety')
            ->assertJsonPath('data.risk_label', 'Critical')
            ->assertJsonPath('data.action_owner_email', 'kisare@alpha.example');
    }

    public function test_a_contractor_cannot_raise_an_anomaly(): void
    {
        $this->asUser($this->contractor)->postJson('/api/v1/eshs', $this->payload())->assertForbidden();
        $this->assertSame(0, EshsAnomaly::count());
    }

    public function test_dropdowns_are_enforced_including_the_dependent_lists(): void
    {
        $this->asUser($this->supervisor)->postJson('/api/v1/eshs', $this->payload(['subcategory' => 'Dust']))
            ->assertUnprocessable()->assertJsonValidationErrors('subcategory');

        $this->asUser($this->supervisor)->postJson('/api/v1/eshs', $this->payload(['root_cause_group' => 'Health']))
            ->assertUnprocessable()->assertJsonValidationErrors('root_cause');

        $this->asUser($this->supervisor)->postJson('/api/v1/eshs', $this->payload(['contractor' => 'Unknown Ltd']))
            ->assertUnprocessable()->assertJsonValidationErrors('contractor');

        $this->asUser($this->supervisor)->postJson('/api/v1/eshs', $this->payload(['risk_rating' => 'extreme']))
            ->assertUnprocessable()->assertJsonValidationErrors('risk_rating');
    }

    public function test_options_come_from_configuration_and_a_project_can_override_a_list(): void
    {
        $this->asUser($this->supervisor)->getJson('/api/v1/eshs/options')
            ->assertOk()
            ->assertJsonPath('data.contractors', ['Alpha Builders', 'Beta Civils'])
            ->assertJsonPath('data.categories.0.key', 'environment')
            ->assertJsonPath('data.root_causes.0.group', 'People');

        app(ConfigurationRegistry::class)->set(EshsConfiguration::ANOMALY_TYPES, ['Observation', 'Stop-work order'], $this->organisation->id, $this->project->id);

        $this->asUser($this->supervisor)->getJson('/api/v1/eshs/options')
            ->assertJsonPath('data.anomaly_types', ['Observation', 'Stop-work order']);

        $this->raise(['anomaly_type' => 'Stop-work order']);
    }

    public function test_contractor_lists_are_edited_only_with_configuration_manage(): void
    {
        $this->asUser($this->supervisor)->putJson('/api/v1/eshs/settings', ['contractors' => ['X']])->assertForbidden();

        $admin = $this->makeUser('project_admin');
        $this->asUser($admin)->putJson('/api/v1/eshs/settings', [
            'contractors' => ['Alpha Builders', ' Delta Lines ', ''],
            'subcontractors' => [],
        ])->assertOk()->assertJsonPath('data.contractors', ['Alpha Builders', 'Delta Lines']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'configuration.changed']);
    }

    // ----------------------------------------------------------- workflow

    public function test_the_full_workflow_contractor_responds_and_supervision_closes(): void
    {
        $anomaly = $this->raise();

        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/progress", [
            'progress_notes' => 'Crew stood down; re-briefing tomorrow.',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        // Submitting needs closure evidence first.
        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/submit", [])
            ->assertStatus(422)->assertJsonPath('error', 'closure_evidence_required');

        $this->uploadClosureEvidence($anomaly, $this->contractor)->assertCreated();

        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/submit", ['notes' => 'Photos attached.'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'awaiting_verification')
            ->assertJsonPath('data.stage_label', 'Awaiting verification');

        // Returned with a reason, then resubmitted.
        $this->asUser($this->supervisor)->postJson("/api/v1/eshs/{$anomaly->id}/return", [])->assertUnprocessable();
        $this->asUser($this->supervisor)->postJson("/api/v1/eshs/{$anomaly->id}/return", ['reason' => 'Lanyards not visible in the photos.'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'in_progress')
            ->assertJsonPath('data.return_reason', 'Lanyards not visible in the photos.');

        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/submit", [])->assertOk();

        $response = $this->asUser($this->supervisor)->postJson("/api/v1/eshs/{$anomaly->id}/close", ['notes' => 'Verified on site.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_on', today()->toDateString())
            ->assertJsonPath('data.closed_by.id', $this->supervisor->id);

        $actions = collect($response->json('data.history'))->pluck('action')->all();
        $this->assertContains('created', $actions);
        $this->assertContains('evidence_submitted', $actions);
        $this->assertContains('returned', $actions);
        $this->assertContains('closed', $actions);

        // The contractor was told about the return and the closure; supervision about the submission.
        $this->assertGreaterThan(0, $this->contractor->notifications()->where('data->event', 'eshs.closed')->count());
        $this->assertGreaterThan(0, $this->supervisor->notifications()->where('data->event', 'eshs.evidence_submitted')->count());
        $this->assertGreaterThan(0, $this->contractor->notifications()->where('data->event', 'eshs.raised')->count());
    }

    public function test_a_contractor_cannot_close_or_return(): void
    {
        $anomaly = $this->raise();
        $this->uploadClosureEvidence($anomaly, $this->contractor)->assertCreated();
        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/submit", [])->assertOk();

        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/close", [])->assertForbidden();
        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/return", ['reason' => 'Self-return attempt'])->assertForbidden();
        $this->assertNotSame('closed', $anomaly->fresh()->status);
    }

    public function test_a_contractor_cannot_edit_the_raisers_fields(): void
    {
        $anomaly = $this->raise();

        $this->asUser($this->contractor)->patchJson("/api/v1/eshs/{$anomaly->id}", ['risk_rating' => 'low'])->assertForbidden();
        $this->assertSame('critical', $anomaly->fresh()->risk_rating);
    }

    public function test_the_verifier_cannot_close_without_closure_evidence(): void
    {
        $anomaly = $this->raise();

        // Evidence of the anomaly itself is not closure evidence.
        $this->asUser($this->supervisor)->post('/api/v1/attachments', [
            'attachable_type' => 'eshs_anomaly',
            'attachable_id' => $anomaly->id,
            'kind' => 'evidence',
            'file' => UploadedFile::fake()->image('finding.jpg'),
        ], $this->projectHeaders())->assertCreated();

        $this->asUser($this->supervisor)->postJson("/api/v1/eshs/{$anomaly->id}/close", [])
            ->assertStatus(422)->assertJsonPath('error', 'closure_evidence_required');

        $this->assertSame('open', $anomaly->fresh()->status);
    }

    public function test_nobody_changes_a_closed_anomaly(): void
    {
        $anomaly = $this->raise();
        $this->uploadClosureEvidence($anomaly, $this->contractor)->assertCreated();
        $this->asUser($this->supervisor)->postJson("/api/v1/eshs/{$anomaly->id}/close", [])->assertOk();

        $this->asUser($this->supervisor)->patchJson("/api/v1/eshs/{$anomaly->id}", ['risk_rating' => 'low'])->assertForbidden();
        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/progress", [])->assertForbidden();
        $this->uploadClosureEvidence($anomaly, $this->contractor)->assertForbidden();
        $this->uploadClosureEvidence($anomaly, $this->supervisor)->assertForbidden();
    }

    // ------------------------------------------------- scoping and isolation

    public function test_a_contractor_sees_only_their_own_companys_anomalies(): void
    {
        $own = $this->raise();
        $other = $this->raise(['contractor' => 'Beta Civils']);

        $this->asUser($this->contractor)->getJson('/api/v1/eshs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        $this->asUser($this->contractor)->getJson("/api/v1/eshs/{$other->id}")->assertNotFound();
        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$other->id}/progress", [])->assertNotFound();
        $this->uploadClosureEvidence($other, $this->contractor)->assertNotFound();
        $this->asUser($this->contractor)->getJson("/api/v1/attachments?attachable_type=eshs_anomaly&attachable_id={$other->id}")->assertNotFound();

        $this->asUser($this->contractor)->getJson('/api/v1/eshs/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.total', 1)
            ->assertJsonCount(1, 'data.contractor_performance');

        // The supervision team sees both.
        $this->asUser($this->supervisor)->getJson('/api/v1/eshs')->assertJsonCount(2, 'data');
    }

    public function test_a_contractor_with_no_contractor_assigned_sees_nothing(): void
    {
        $anomaly = $this->raise();
        $unassigned = $this->contractorUser(null);

        $this->asUser($unassigned)->getJson('/api/v1/eshs')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($unassigned)->getJson("/api/v1/eshs/{$anomaly->id}")->assertNotFound();
    }

    public function test_a_contractor_cannot_remove_the_supervision_teams_evidence(): void
    {
        $anomaly = $this->raise();

        $theirs = $this->asUser($this->supervisor)->post('/api/v1/attachments', [
            'attachable_type' => 'eshs_anomaly',
            'attachable_id' => $anomaly->id,
            'kind' => 'evidence',
            'file' => UploadedFile::fake()->image('tower.jpg'),
        ], $this->projectHeaders())->assertCreated()->json('data.id');

        $own = $this->uploadClosureEvidence($anomaly, $this->contractor)->assertCreated()->json('data.id');

        $this->asUser($this->contractor)->deleteJson("/api/v1/attachments/{$theirs}")->assertForbidden();
        $this->asUser($this->contractor)->deleteJson("/api/v1/attachments/{$own}")->assertOk();
        $this->asUser($this->supervisor)->deleteJson("/api/v1/attachments/{$theirs}")->assertOk();
    }

    public function test_the_contractor_scope_is_set_on_the_people_page(): void
    {
        $admin = $this->makeUser('project_admin');
        $membership = ProjectMembership::where('user_id', $this->contractor->id)->firstOrFail();

        $this->asUser($admin)->patchJson("/api/v1/members/{$membership->id}", ['contractor_name' => 'Beta Civils'])->assertOk();
        $this->assertSame('Beta Civils', $membership->fresh()->contractor_name);

        $this->asUser($admin)->getJson('/api/v1/members')->assertOk()
            ->assertJsonFragment(['contractor_name' => 'Beta Civils']);
    }

    public function test_another_projects_anomaly_is_not_found(): void
    {
        $anomaly = $this->raise();

        $otherProject = Project::create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Other Line', 'code' => 'OTHER-1', 'country' => 'TZ',
            'timezone' => 'Africa/Dar_es_Salaam', 'status' => 'active',
        ]);
        $outsider = $this->makeUser('hse_officer', ['general'], $otherProject);

        $this->asUser($outsider, $otherProject)->getJson("/api/v1/eshs/{$anomaly->id}")->assertNotFound();
        $this->asUser($outsider, $otherProject)->postJson("/api/v1/eshs/{$anomaly->id}/close", [])->assertNotFound();
        $this->asUser($outsider, $otherProject)->getJson('/api/v1/eshs')->assertJsonCount(0, 'data');
    }

    public function test_action_owner_contact_details_are_absent_for_a_read_only_viewer(): void
    {
        $anomaly = $this->raise();
        $auditor = $this->makeUser('auditor');

        $data = $this->asUser($auditor)->getJson("/api/v1/eshs/{$anomaly->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('action_owner_email', $data);
        $this->assertArrayNotHasKey('action_owner_phone', $data);
        $this->assertSame('Kisare Lutambi', $data['action_owner_name']);
    }

    public function test_a_user_without_eshs_view_is_refused(): void
    {
        $field = $this->makeUser('field_officer');

        $this->asUser($field)->getJson('/api/v1/eshs')->assertForbidden();
    }

    public function test_archiving_keeps_the_row_and_writes_an_audit_event(): void
    {
        $anomaly = $this->raise();

        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$anomaly->id}/archive")->assertForbidden();
        $this->asUser($this->supervisor)->postJson("/api/v1/eshs/{$anomaly->id}/archive")->assertOk();

        $this->assertNotNull($anomaly->fresh()->archived_at);
        $this->assertDatabaseHas('eshs_anomalies', ['id' => $anomaly->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'eshs_anomaly.archived', 'entity_id' => $anomaly->id]);
        $this->asUser($this->supervisor)->getJson('/api/v1/eshs')->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------ overdue and dashboard

    public function test_overdue_is_derived_from_the_target_date_and_never_stored(): void
    {
        $late = $this->raise(['target_date' => today()->subDay()->toDateString()]);
        $onTime = $this->raise(['target_date' => today()->addDay()->toDateString()]);

        $this->assertTrue($late->fresh()->isOverdue());
        $this->assertFalse($onTime->fresh()->isOverdue());
        $this->assertSame('open', $late->fresh()->status);

        $this->asUser($this->supervisor)->getJson('/api/v1/eshs?overdue=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $late->id)
            ->assertJsonPath('data.0.is_overdue', true);

        // Closing a late anomaly takes it out of overdue.
        $this->uploadClosureEvidence($late, $this->contractor)->assertCreated();
        $this->asUser($this->supervisor)->postJson("/api/v1/eshs/{$late->id}/close", [])->assertOk();
        $this->assertFalse($late->fresh()->isOverdue());
        $this->asUser($this->supervisor)->getJson('/api/v1/eshs?overdue=1')->assertJsonCount(0, 'data');
    }

    public function test_dashboard_numbers(): void
    {
        // Alpha: one closed (raised 10 days ago, closed today), one overdue, one awaiting verification.
        $closed = $this->raise(['raised_on' => today()->subDays(10)->toDateString()]);
        $this->uploadClosureEvidence($closed, $this->contractor)->assertCreated();
        $this->asUser($this->supervisor)->postJson("/api/v1/eshs/{$closed->id}/close", [])->assertOk();

        $this->raise(['target_date' => today()->subDays(2)->toDateString(), 'risk_rating' => 'high', 'category' => 'environment', 'subcategory' => 'Dust']);

        $awaiting = $this->raise(['risk_rating' => 'medium']);
        $this->uploadClosureEvidence($awaiting, $this->contractor)->assertCreated();
        $this->asUser($this->contractor)->postJson("/api/v1/eshs/{$awaiting->id}/submit", [])->assertOk();

        // Beta: one open critical.
        $this->raise(['contractor' => 'Beta Civils']);

        $data = $this->asUser($this->supervisor)->getJson('/api/v1/eshs/summary')->assertOk()->json('data');

        $this->assertSame(4, $data['totals']['total']);
        $this->assertSame(3, $data['totals']['open']);
        $this->assertSame(1, $data['totals']['overdue']);
        $this->assertSame(1, $data['totals']['awaiting_verification']);
        $this->assertSame(1, $data['totals']['closed']);
        $this->assertEquals(25.0, $data['totals']['closure_rate']);
        $this->assertSame(1, $data['totals']['critical_open']);
        $this->assertEquals(10.0, $data['totals']['avg_days_to_close']);

        $categories = collect($data['by_category'])->keyBy('category');
        $this->assertSame(3, $categories['safety']['total']);
        $this->assertSame(1, $categories['environment']['total']);
        $this->assertSame(0, $categories['health']['total']);

        $alpha = collect($data['contractor_performance'])->firstWhere('contractor', 'Alpha Builders');
        $this->assertSame(3, $alpha['raised']);
        $this->assertSame(2, $alpha['open']);
        $this->assertSame(1, $alpha['overdue']);
        $this->assertSame(1, $alpha['closed']);
        $this->assertEquals(33.3, $alpha['closure_rate']);
        $this->assertEquals(10.0, $alpha['avg_days_to_close']);

        $beta = collect($data['contractor_performance'])->firstWhere('contractor', 'Beta Civils');
        $this->assertSame(1, $beta['raised']);
        $this->assertEquals(0.0, $beta['closure_rate']);
        $this->assertNull($beta['avg_days_to_close']);

        $kpi = collect($data['kpis'])->firstWhere('key', 'overdue');
        $this->assertSame(1, $kpi['value']);
        $this->assertNotEmpty($kpi['definition']);
    }

    public function test_export_is_gated_and_audited(): void
    {
        $this->raise();

        $this->asUser($this->makeUser('field_officer'))->get('/api/v1/eshs/export?format=csv', $this->projectHeaders())->assertForbidden();

        $this->asUser($this->supervisor)->get('/api/v1/eshs/export?format=csv', $this->projectHeaders())->assertOk();
        $this->assertDatabaseHas('export_logs', ['entity' => 'eshs_anomalies']);
    }

    public function test_seeded_closure_evidence_counts_only_active_closure_attachments(): void
    {
        $anomaly = $this->raise();
        $this->uploadClosureEvidence($anomaly, $this->contractor)->assertCreated();
        $this->assertSame(1, $anomaly->closureEvidenceCount());

        Attachment::where('attachable_id', $anomaly->id)->update(['archived_at' => now()]);
        $this->assertSame(0, $anomaly->closureEvidenceCount());
    }
}
