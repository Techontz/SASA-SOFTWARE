<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_a_user_can_sign_in_and_receives_their_projects_and_permissions(): void
    {
        $user = $this->makeUser('grievance_officer');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'name', 'projects']]]);

        $this->assertSame($this->project->id, $response->json('data.user.projects.0.id'));
        $this->assertContains('grievance.resolve', $response->json('data.user.projects.0.permissions'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_a_wrong_password_is_rejected_and_recorded(): void
    {
        $user = $this->makeUser('field_officer');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_credentials');

        $this->assertSame(1, $user->fresh()->failed_login_attempts);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_an_unknown_address_is_indistinguishable_from_a_wrong_password(): void
    {
        $user = $this->makeUser('field_officer');

        $known = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@test.local', 'password' => 'wrong-password']);

        $this->assertSame($known->json('error'), $unknown->json('error'));
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_repeated_failures_lock_the_account(): void
    {
        $user = $this->makeUser('field_officer');

        for ($attempt = 0; $attempt < config('sasa.auth.max_login_attempts'); $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'account_locked');
    }

    public function test_a_suspended_account_cannot_sign_in(): void
    {
        $user = $this->makeUser('field_officer');
        $user->update(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'account_inactive');
    }

    public function test_changing_a_password_ends_other_sessions(): void
    {
        $user = $this->makeUser('grievance_officer');
        $user->createToken('other-device');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'password',
                'password' => 'a-much-longer-Passw0rd',
                'password_confirmation' => 'a-much-longer-Passw0rd',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed']);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/stakeholders', $this->projectHeaders())->assertStatus(401);
    }

    public function test_the_health_endpoint_reports_every_dependency(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'storage', 'queue']]);
    }
}
