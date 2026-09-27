<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Stakeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Append-only, survives archival, and answers "who read this". */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_an_audit_record_cannot_be_modified(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        $this->asUser($officer)->postJson('/api/v1/stakeholders', ['name' => 'Someone', 'type' => 'individual']);

        $log = AuditLog::first();
        $log->summary = 'Rewriting history';

        $this->expectException(\LogicException::class);
        $log->save();
    }

    public function test_an_audit_record_cannot_be_deleted(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        $this->asUser($officer)->postJson('/api/v1/stakeholders', ['name' => 'Someone', 'type' => 'individual']);

        $this->expectException(\LogicException::class);
        AuditLog::first()->delete();
    }

    public function test_updates_record_what_changed_and_what_it_was(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/stakeholders', [
            'name' => 'Original Name', 'type' => 'individual',
        ])->json('data.id');

        $this->asUser($officer)->patchJson("/api/v1/stakeholders/{$id}", ['name' => 'Corrected Name']);

        $log = AuditLog::where('action', 'stakeholder.updated')->where('entity_id', $id)->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('Original Name', $log->before['name']);
        $this->assertSame('Corrected Name', $log->after['name']);
        $this->assertSame($officer->id, $log->user_id);
        $this->assertNotNull($log->request_id);
    }

    public function test_the_audit_trail_survives_the_record_being_archived(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/stakeholders', ['name' => 'Someone', 'type' => 'individual'])->json('data.id');

        $this->asUser($officer)->deleteJson("/api/v1/stakeholders/{$id}");

        $this->assertGreaterThan(0, AuditLog::where('entity_id', $id)->count());
        $this->assertNotNull(Stakeholder::withoutGlobalScopes()->find($id));
    }

    public function test_secrets_and_identity_never_reach_the_audit_payload(): void
    {
        $officer = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);

        $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'voice',
            'confidentiality' => 'confidential',
            'complainant_name' => 'Protected Identity',
            'complainant_phone' => '+255 754 000 000',
            'title' => 'A confidential case',
            'description' => 'The complainant asked for their identity to be protected.',
        ])->assertCreated();

        $logs = AuditLog::where('action', 'grievance.created')->get();

        foreach ($logs as $log) {
            $this->assertStringNotContainsString('Protected Identity', json_encode($log->after));
            $this->assertArrayNotHasKey('complainant_name', $log->after ?? []);
        }
    }

    public function test_the_per_record_history_is_available_to_authorised_roles(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/stakeholders', ['name' => 'Someone', 'type' => 'individual'])->json('data.id');

        $admin = $this->makeUser('project_admin');
        $response = $this->asUser($admin)->getJson("/api/v1/audit/Stakeholder/{$id}");

        $response->assertOk();
        $this->assertGreaterThan(0, count($response->json('data')));
    }

    public function test_a_field_officer_cannot_read_the_audit_trail(): void
    {
        $field = $this->makeUser('field_officer');

        $this->asUser($field)->getJson('/api/v1/audit')->assertStatus(403);
    }

    public function test_sensitive_view_events_are_queryable_on_their_own(): void
    {
        $officer = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);

        $id = $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'voice', 'confidentiality' => 'confidential',
            'complainant_name' => 'Someone', 'title' => 'Case', 'description' => 'A confidential complaint.',
        ])->json('data.id');

        $this->asUser($officer)->getJson("/api/v1/grievances/{$id}");

        $admin = $this->makeUser('project_admin');
        $response = $this->asUser($admin)->getJson('/api/v1/audit/sensitive-views');

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('data.total'));
    }

    public function test_a_role_without_the_sensitive_permission_does_not_see_those_events(): void
    {
        $officer = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);
        $id = $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'voice', 'confidentiality' => 'confidential',
            'complainant_name' => 'Someone', 'title' => 'Case', 'description' => 'A confidential complaint.',
        ])->json('data.id');
        $this->asUser($officer)->getJson("/api/v1/grievances/{$id}");

        // The auditor role can read the trail but not the sensitive-view events.
        $auditor = $this->makeUser('auditor', []);
        $response = $this->asUser($auditor)->getJson('/api/v1/audit');

        $response->assertOk();
        $actions = collect($response->json('data.data'))->pluck('action');
        $this->assertFalse($actions->contains('grievance.sensitive_view'));

        $this->asUser($auditor)->getJson('/api/v1/audit/sensitive-views')->assertStatus(403);
    }

    public function test_configuration_changes_are_audited(): void
    {
        $admin = $this->makeUser('project_admin');

        $this->asUser($admin)->putJson('/api/v1/configuration', [
            'key' => 'priority',
            'value' => ['weights' => ['influence' => 2, 'interest' => 1, 'power' => 1, 'impact' => 1]],
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'configuration.changed']);
    }

    public function test_login_and_failed_login_are_both_recorded(): void
    {
        $user = $this->makeUser('field_officer');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }
}
