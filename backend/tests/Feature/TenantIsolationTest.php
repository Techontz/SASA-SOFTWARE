<?php

namespace Tests\Feature;

use App\Domain\Stakeholder\StakeholderService;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant scope is derived from the authenticated user's membership — never
 * from a request parameter. A client may ASK for a project by id; it may not
 * assert that it belongs to one.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Project $otherProject;

    private Stakeholder $otherStakeholder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $otherOrganisation = Organisation::create([
            'name' => 'Rival Mining Company',
            'slug' => 'rival-mining',
            'country' => 'TZ',
            'status' => 'active',
        ]);

        $this->otherProject = Project::create([
            'organisation_id' => $otherOrganisation->id,
            'name' => 'A project on another tenant',
            'code' => 'OTHER-1',
            'status' => 'active',
        ]);

        $this->otherStakeholder = app(StakeholderService::class)->create($this->otherProject, [
            'name' => 'Someone Else\'s Stakeholder',
            'type' => 'individual',
            'influence' => 'high', 'interest' => 'high', 'power' => 'high', 'impact' => 'high',
        ]);
    }

    public function test_a_record_from_another_project_is_not_found(): void
    {
        $user = $this->makeUser('project_admin');

        $this->asUser($user)->getJson("/api/v1/stakeholders/{$this->otherStakeholder->id}")->assertStatus(404);
    }

    public function test_a_list_never_includes_another_project(): void
    {
        $user = $this->makeUser('community_relations_officer');

        app(StakeholderService::class)->create($this->project, [
            'name' => 'Our Own Stakeholder', 'type' => 'individual',
        ]);

        $response = $this->asUser($user)->getJson('/api/v1/stakeholders');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Our Own Stakeholder', $response->json('data.0.name'));
    }

    public function test_asking_for_a_project_the_user_does_not_belong_to_is_refused(): void
    {
        $user = $this->makeUser('project_admin');

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Sasa-Project', (string) $this->otherProject->id)
            ->getJson('/api/v1/stakeholders')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    public function test_a_client_supplied_project_id_in_the_body_is_ignored(): void
    {
        $user = $this->makeUser('community_relations_officer');

        $response = $this->asUser($user)->postJson('/api/v1/stakeholders', [
            'name' => 'Attempted Cross-Tenant Write',
            'type' => 'individual',
            // A hostile client asserting a different tenant.
            'project_id' => $this->otherProject->id,
            'organisation_id' => $this->otherProject->organisation_id,
        ]);

        $response->assertCreated();

        $created = Stakeholder::withoutGlobalScopes()->find($response->json('data.id'));
        $this->assertSame($this->project->id, $created->project_id);
        $this->assertSame($this->organisation->id, $created->organisation_id);
    }

    public function test_a_request_without_a_project_is_refused_rather_than_guessing(): void
    {
        $user = User::create([
            'organisation_id' => $this->organisation->id,
            'name' => 'No Memberships',
            'email' => 'no.memberships@test.local',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stakeholders')
            ->assertStatus(400)
            ->assertJsonPath('error', 'project_required');
    }

    public function test_the_same_person_can_hold_different_roles_on_different_projects(): void
    {
        $secondProject = Project::create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Second Project',
            'code' => 'TEST-2',
            'status' => 'active',
        ]);

        $user = $this->makeUser('grievance_officer');

        ProjectMembership::create([
            'organisation_id' => $this->organisation->id,
            'project_id' => $secondProject->id,
            'user_id' => $user->id,
            'role_id' => Role::whereNull('organisation_id')->where('key', 'auditor')->first()->id,
            'handling_groups' => [],
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Grievance officer here: may create a case.
        $this->asUser($user, $this->project)->postJson('/api/v1/grievances', [
            'channel' => 'web',
            'confidentiality' => 'normal',
            'title' => 'A case on the first project',
            'description' => 'Created by someone who is a grievance officer here.',
        ])->assertCreated();

        // Read-only there: may not.
        $this->asUser($user, $secondProject)->postJson('/api/v1/grievances', [
            'channel' => 'web',
            'confidentiality' => 'normal',
            'title' => 'A case on the second project',
            'description' => 'The same person is read-only on this project.',
        ])->assertStatus(403);
    }

    public function test_a_system_administrator_may_reach_any_project(): void
    {
        $admin = $this->makeUser('system_administrator', ['*']);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Sasa-Project', (string) $this->otherProject->id)
            ->getJson('/api/v1/stakeholders')
            ->assertOk();
    }

    public function test_a_revoked_membership_ends_access_immediately(): void
    {
        $user = $this->makeUser('grievance_officer');
        $this->asUser($user)->getJson('/api/v1/grievances')->assertOk();

        $user->memberships()->update(['status' => 'revoked']);

        $this->asUser($user)->getJson('/api/v1/grievances')->assertStatus(404);
    }
}
