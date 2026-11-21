<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Mail\DemoInviteMail;
use App\Models\AuditLog;
use App\Models\Demo;
use App\Models\Enquiry;
use App\Models\EshsAnomaly;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\Incident;
use App\Models\Organisation;
use App\Models\Pap;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\SlaClock;
use App\Models\SlaPolicy;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DemoProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Demo-Pass-2026';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        config([
            'sasa.web_url' => 'https://app.sasa.test',
            'sasa.enquiries.notify' => '',
            'sasa.demos.invite_email' => false,
        ]);

        $this->admin = $this->makeUser('system_administrator', ['*']);
    }

    private function demoEnquiry(array $overrides = []): Enquiry
    {
        $reference = $this->postJson('/api/v1/public/enquiries', array_merge([
            'type' => 'demo',
            'full_name' => 'Halima Sample',
            'email' => 'halima@mfano-energy.example',
            'organization' => 'Mfano Energy Ltd',
            'sector' => 'energy',
            'phone' => '0712 000 000',
        ], $overrides))->assertCreated()->json('data.reference');

        return Enquiry::where('reference', $reference)->firstOrFail();
    }

    private function asAdmin(): static
    {
        $this->signOut();

        return $this->actingAs($this->admin, 'sanctum');
    }

    /** @return array<string,mixed> the created Demo payload */
    private function provision(Enquiry $enquiry, array $body = []): array
    {
        return $this->asAdmin()->postJson("/api/v1/admin/enquiries/{$enquiry->public_id}/demo", $body)
            ->assertCreated()->json('data');
    }

    /** @return array{token:string,email:string} */
    private function inviteParts(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['token' => (string) $query['token'], 'email' => (string) $query['email']];
    }

    /** Each test request should start like a fresh one: no signed-in user, no tenant context. */
    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance(TenantContext::class);
    }

    public function test_one_call_provisions_a_working_demo_workspace_with_sample_data_and_an_invite(): void
    {
        $enquiry = $this->demoEnquiry();

        $started = microtime(true);
        $data = $this->provision($enquiry);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(3.0, $elapsed, 'Provisioning with sample data should take under three seconds.');

        $this->assertSame(
            ['public_id', 'status', 'starts_at', 'ends_at', 'ended_at', 'organisation', 'project', 'user', 'enquiry', 'invite_url', 'email_sent', 'created_at'],
            array_keys($data),
        );
        $this->assertSame('active', $data['status']);
        $this->assertSame('Mfano Energy Ltd', $data['organisation']['name']);
        $this->assertSame('Mfano Energy Ltd demo', $data['project']['name']);
        $this->assertMatchesRegularExpression('/^DEMO-[A-Z0-9]{4}$/', $data['project']['code']);
        $this->assertSame(['id' => $data['user']['id'], 'name' => 'Halima Sample', 'email' => 'halima@mfano-energy.example'], $data['user']);
        $this->assertSame(['public_id' => $enquiry->public_id, 'reference' => $enquiry->reference], $data['enquiry']);
        $this->assertFalse($data['email_sent']);
        $this->assertStringStartsWith('https://app.sasa.test/set-password?token=', $data['invite_url']);
        $this->assertStringEndsWith('&email=halima%40mfano-energy.example&welcome=1', $data['invite_url']);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, strtotime($data['ends_at']), 5);

        $organisation = Organisation::findOrFail($data['organisation']['id']);
        $project = Project::findOrFail($data['project']['id']);
        $user = User::findOrFail($data['user']['id']);

        $this->assertTrue($organisation->is_demo);
        $this->assertSame($organisation->id, $project->organisation_id);
        $this->assertSame('invited', $user->status);
        $this->assertSame($organisation->id, $user->organisation_id);
        $this->assertFalse(Hash::check('password', $user->password));

        $membership = ProjectMembership::where('user_id', $user->id)->where('project_id', $project->id)->firstOrFail();
        $this->assertSame('project_admin', $membership->role->key);
        $this->assertSame('active', $membership->status);
        $this->assertTrue($membership->is_default);

        // The default configuration every project starts with.
        $this->assertSame(8, GrievanceCategory::where('project_id', $project->id)->whereNull('parent_id')->count());
        $this->assertTrue(SlaPolicy::where('project_id', $project->id)->exists());

        // A representative sample of every module.
        $this->assertGreaterThanOrEqual(12, Stakeholder::acrossTenants()->where('project_id', $project->id)->count());
        $this->assertSame(12, Grievance::acrossTenants()->where('project_id', $project->id)->count());
        $this->assertTrue(SlaClock::acrossTenants()->where('project_id', $project->id)->exists());
        $this->assertSame(8, Pap::acrossTenants()->where('project_id', $project->id)->count());
        $this->assertSame(10, EshsAnomaly::acrossTenants()->where('project_id', $project->id)->count());
        $this->assertSame(8, Incident::acrossTenants()->where('project_id', $project->id)->count());

        // Fictional companies and numbers only.
        $text = json_encode([
            Stakeholder::acrossTenants()->where('project_id', $project->id)->pluck('name'),
            Incident::acrossTenants()->where('project_id', $project->id)->pluck('company'),
            EshsAnomaly::acrossTenants()->where('project_id', $project->id)->pluck('contractor'),
        ]);
        foreach (['TANESCO', 'TBEA', 'Kalpataru', 'Baraka Construction', 'CRDB', 'NMB'] as $real) {
            $this->assertStringNotContainsString($real, $text);
        }
        $this->assertSame(0, Stakeholder::acrossTenants()->where('project_id', $project->id)
            ->whereNotNull('phone')->where('phone', 'not like', '+255 700 000 %')->count());

        // The enquiry is marked contacted, and the admin list shows its demo.
        $enquiry->refresh();
        $this->assertSame('contacted', $enquiry->status);
        $this->assertSame($this->admin->id, $enquiry->handled_by);
        $this->getJson('/api/v1/admin/enquiries')->assertOk()
            ->assertJsonPath('data.0.demo.public_id', $data['public_id'])
            ->assertJsonPath('data.0.demo.status', 'active');

        $this->assertTrue(AuditLog::where('action', 'demo.provisioned')->where('entity_reference', $data['public_id'])->exists());
        $this->assertTrue(AuditLog::where('action', 'demo.invite_sent')->exists());

        // The invite link sets a password; the invited person can then sign in.
        $this->signOut();
        $parts = $this->inviteParts($data['invite_url']);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $parts['token'],
            'email' => $parts['email'],
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertOk();
        $this->assertSame('active', $user->fresh()->status);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'halima@mfano-energy.example', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.user.projects.0.id', $project->id)
            ->assertJsonPath('data.user.projects.0.role.key', 'project_admin')
            ->assertJsonPath('data.user.projects.0.demo.status', 'active');
        $this->assertSame($data['ends_at'], $login->json('data.user.projects.0.demo.ends_at'));

        $token = $login->json('data.token');
        $this->signOut();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.projects.0.demo.status', 'active');
        $this->withToken($token)->withHeader('X-Sasa-Project', (string) $project->id)
            ->getJson('/api/v1/stakeholders')->assertOk();

        // A project that is not a demo says so with null.
        $this->signOut();
        $this->asUser($this->makeUser('project_admin'))->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.projects.0.demo', null);
    }

    public function test_options_choose_the_length_contact_and_project_name_and_can_skip_sample_data(): void
    {
        $data = $this->provision($this->demoEnquiry(), [
            'days' => 30,
            'sample_data' => false,
            'name' => 'Baraka Mfano',
            'email' => 'Baraka@Mfano-Energy.example',
            'project_name' => 'Mfano pilot',
        ]);

        $this->assertSame('Mfano pilot', $data['project']['name']);
        $this->assertSame('baraka@mfano-energy.example', $data['user']['email']);
        $this->assertSame('Baraka Mfano', $data['user']['name']);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, strtotime($data['ends_at']), 5);
        $this->assertSame(0, Stakeholder::acrossTenants()->where('project_id', $data['project']['id'])->count());
        $this->assertTrue(GrievanceCategory::where('project_id', $data['project']['id'])->exists());

        $enquiry = $this->demoEnquiry(['email' => 'other@mfano.example']);
        $this->asAdmin()->postJson("/api/v1/admin/enquiries/{$enquiry->public_id}/demo", ['days' => 61])
            ->assertStatus(422)->assertJsonValidationErrors(['days']);
        $this->asAdmin()->postJson("/api/v1/admin/enquiries/{$enquiry->public_id}/demo", ['days' => 0, 'email' => 'not-an-email'])
            ->assertStatus(422)->assertJsonValidationErrors(['days', 'email']);
    }

    public function test_demo_administration_requires_enquiry_manage(): void
    {
        $enquiry = $this->demoEnquiry();
        $projectAdmin = $this->makeUser('project_admin');

        $this->actingAs($projectAdmin, 'sanctum')->postJson("/api/v1/admin/enquiries/{$enquiry->public_id}/demo")
            ->assertForbidden()->assertJsonPath('error', 'forbidden');
        $this->actingAs($projectAdmin, 'sanctum')->getJson('/api/v1/admin/demos')->assertForbidden();

        $this->assertSame(0, Demo::count());
        $this->signOut();
        $this->postJson("/api/v1/admin/enquiries/{$enquiry->public_id}/demo")->assertUnauthorized();
    }

    public function test_an_enquiry_with_a_running_demo_is_refused_and_an_email_in_another_organisation_is_refused(): void
    {
        $enquiry = $this->demoEnquiry();
        $this->provision($enquiry, ['sample_data' => false]);

        $this->asAdmin()->postJson("/api/v1/admin/enquiries/{$enquiry->public_id}/demo", ['sample_data' => false])
            ->assertStatus(422)->assertJsonPath('error', 'demo_exists');
        $this->assertSame(1, Demo::count());

        // Someone who already works in a real organisation cannot be pulled into a demo.
        $member = $this->makeUser('grievance_officer');
        $other = $this->demoEnquiry(['email' => $member->email]);
        $this->asAdmin()->postJson("/api/v1/admin/enquiries/{$other->public_id}/demo", ['sample_data' => false])
            ->assertStatus(422)->assertJsonPath('error', 'email_in_use')->assertJsonValidationErrors(['email']);
        $this->assertSame(1, Demo::count());
        $this->assertSame(1, Organisation::where('is_demo', true)->count());
        $this->assertSame($this->organisation->id, $member->fresh()->organisation_id);

        // Someone from an earlier demo is reused, keeping one account.
        $first = Demo::firstOrFail();
        $this->asAdmin()->postJson("/api/v1/admin/demos/{$first->public_id}/end")->assertOk();
        $again = $this->demoEnquiry(['organization' => 'Mfano Energy Holdings']);
        $data = $this->provision($again, ['sample_data' => false]);
        $this->assertSame($first->user_id, $data['user']['id']);
        $this->assertSame(1, User::where('email', 'halima@mfano-energy.example')->count());
        $this->assertSame($data['organisation']['id'], User::find($first->user_id)->organisation_id);
    }

    public function test_extend_end_and_resend_invite(): void
    {
        $data = $this->provision($this->demoEnquiry(), ['sample_data' => false, 'days' => 5]);
        $demo = Demo::where('public_id', $data['public_id'])->firstOrFail();

        $extended = $this->asAdmin()->postJson("/api/v1/admin/demos/{$demo->public_id}/extend", ['days' => 10])
            ->assertOk()->json('data');
        $this->assertEqualsWithDelta($demo->ends_at->copy()->addDays(10)->timestamp, strtotime($extended['ends_at']), 2);
        $this->assertArrayNotHasKey('invite_url', $extended);
        $this->asAdmin()->postJson("/api/v1/admin/demos/{$demo->public_id}/extend", ['days' => 61])
            ->assertStatus(422)->assertJsonValidationErrors(['days']);

        // An expired demo extends from today, and opens again.
        $demo->forceFill(['status' => 'expired', 'ends_at' => now()->subDays(3)])->save();
        $reopened = $this->asAdmin()->postJson("/api/v1/admin/demos/{$demo->public_id}/extend", ['days' => 7])
            ->assertOk()->assertJsonPath('data.status', 'active')->json('data');
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, strtotime($reopened['ends_at']), 5);

        $ended = $this->asAdmin()->postJson("/api/v1/admin/demos/{$demo->public_id}/end")->assertOk()
            ->assertJsonPath('data.status', 'ended')->json('data');
        $this->assertNotNull($ended['ended_at']);
        $this->assertTrue(AuditLog::where('action', 'demo.status_changed')->where('entity_id', $demo->id)->exists());

        $resent = $this->asAdmin()->postJson("/api/v1/admin/demos/{$demo->public_id}/resend-invite")->assertOk()->json('data');
        $this->assertNotSame($data['invite_url'], $resent['invite_url']);
        $this->assertFalse($resent['email_sent']);

        // Only the newest link works.
        $this->signOut();
        $old = $this->inviteParts($data['invite_url']);
        $this->postJson('/api/v1/auth/reset-password', $old + ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('error', 'reset_failed');
        $new = $this->inviteParts($resent['invite_url']);
        $this->postJson('/api/v1/auth/reset-password', $new + ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertOk();

        // The list never carries an invite link, newest first, filterable.
        $list = $this->asAdmin()->getJson('/api/v1/admin/demos')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertArrayNotHasKey('invite_url', $list->json('data.0'));
        $this->getJson('/api/v1/admin/demos?status=ended')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/demos?status=active')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/demos?status=nonsense')->assertStatus(422);
    }

    public function test_an_expired_or_ended_demo_shuts_its_project_except_for_the_sasa_team(): void
    {
        $data = $this->provision($this->demoEnquiry(), ['sample_data' => false]);
        $demo = Demo::where('public_id', $data['public_id'])->firstOrFail();
        $user = User::findOrFail($data['user']['id']);
        $user->forceFill(['status' => 'active'])->save();
        $project = Project::findOrFail($data['project']['id']);

        $this->signOut();
        $this->asUser($user, $project)->getJson('/api/v1/stakeholders')->assertOk();

        // The end date passes: shut at once, even before demos:expire runs.
        $demo->forceFill(['ends_at' => now()->subMinute()])->save();
        $this->signOut();
        $this->asUser($user, $project)->getJson('/api/v1/stakeholders')
            ->assertForbidden()
            ->assertJsonPath('error', 'demo_ended')
            ->assertJsonPath('message', 'This SASA demo has ended. Contact the SASA team to continue.');
        $this->asUser($user, $project)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.projects.0.demo.status', 'expired');

        // The SASA team can still look inside.
        $this->signOut();
        $this->asUser($this->admin, $project)->getJson('/api/v1/stakeholders')->assertOk();

        // demos:expire records it, once.
        $this->artisan('demos:expire')->expectsOutput('Marked 1 demo expired.')->assertSuccessful();
        $this->artisan('demos:expire')->expectsOutput('Marked 0 demos expired.')->assertSuccessful();
        $this->assertSame('expired', $demo->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'demo.status_changed')->where('entity_id', $demo->id)->count());

        // Extended, it opens again; ended early, it shuts.
        $this->asAdmin()->postJson("/api/v1/admin/demos/{$demo->public_id}/extend", ['days' => 3])->assertOk();
        $this->signOut();
        $this->asUser($user, $project)->getJson('/api/v1/stakeholders')->assertOk();
        $this->asAdmin()->postJson("/api/v1/admin/demos/{$demo->public_id}/end")->assertOk();
        $this->signOut();
        $this->asUser($user, $project)->getJson('/api/v1/stakeholders')->assertForbidden()->assertJsonPath('error', 'demo_ended');
    }

    public function test_an_agreement_for_a_demo_organisation_converts_the_demo_and_lifts_the_limit(): void
    {
        $data = $this->provision($this->demoEnquiry(), ['sample_data' => false]);
        $demo = Demo::where('public_id', $data['public_id'])->firstOrFail();
        $user = User::findOrFail($data['user']['id']);
        $user->forceFill(['status' => 'active'])->save();
        $project = Project::findOrFail($data['project']['id']);
        $demo->forceFill(['status' => 'expired', 'ends_at' => now()->subDay()])->save();

        $this->asAdmin()->postJson('/api/v1/admin/agreements', [
            'organisation_id' => $data['organisation']['id'],
            'name' => 'SASA for Mfano Energy',
            'monthly_amount' => 900000,
            'billing_day' => 1,
            'starts_on' => now()->toDateString(),
            'modules' => ['grievance_management'],
            'limits' => ['projects' => 1, 'users' => 10],
        ])->assertCreated();

        $this->assertSame('converted', $demo->fresh()->status);
        $this->assertFalse(Organisation::findOrFail($data['organisation']['id'])->is_demo);

        $this->signOut();
        $this->asUser($user, $project)->getJson('/api/v1/stakeholders')->assertOk();
        $this->asUser($user, $project)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.projects.0.demo', null);

        $this->asAdmin()->postJson("/api/v1/admin/demos/{$demo->public_id}/extend", ['days' => 3])
            ->assertStatus(422)->assertJsonPath('error', 'demo_converted');

        // An agreement for an ordinary organisation touches no demo.
        $this->asAdmin()->postJson('/api/v1/admin/agreements', [
            'organisation_id' => $this->organisation->id,
            'name' => 'SASA for Test Infrastructure',
            'monthly_amount' => 900000,
            'billing_day' => 1,
            'starts_on' => now()->toDateString(),
            'modules' => ['grievance_management'],
            'limits' => ['projects' => 1, 'users' => 10],
        ])->assertCreated();
    }

    public function test_the_invitation_is_queued_only_when_mail_is_configured(): void
    {
        Mail::fake();

        $this->provision($this->demoEnquiry(), ['sample_data' => false]);
        Mail::assertNothingQueued();

        config(['sasa.demos.invite_email' => true]);
        $data = $this->provision($this->demoEnquiry(['email' => 'second@mfano.example', 'organization' => 'Mfano Mining']), ['sample_data' => false]);
        $this->assertTrue($data['email_sent']);

        Mail::assertQueued(DemoInviteMail::class, function (DemoInviteMail $mail) use ($data) {
            $rendered = $mail->render();

            return $mail->hasTo('second@mfano.example')
                && $mail->envelope()->subject === 'Your SASA demo is ready'
                && str_contains($rendered, $data['invite_url'])
                && str_contains($rendered, 'Mfano Mining')
                && ! str_contains($rendered, '—');
        });
    }

    public function test_a_mail_failure_never_fails_the_request(): void
    {
        config(['sasa.demos.invite_email' => true]);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $data = $this->provision($this->demoEnquiry(), ['sample_data' => false]);

        $this->assertFalse($data['email_sent']);
        $this->assertNotEmpty($data['invite_url']);
    }

    public function test_forgot_password_links_open_the_web_apps_set_password_page_and_activate_an_invited_user(): void
    {
        Notification::fake();
        $member = $this->makeUser('field_officer');
        $member->forceFill(['status' => 'invited'])->save();

        $this->signOut();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $member->email])->assertOk();

        $url = null;
        Notification::assertSentTo($member, ResetPassword::class, function (ResetPassword $notification) use ($member, &$url) {
            $url = $notification->toMail($member)->actionUrl;

            return true;
        });

        $this->assertStringStartsWith('https://app.sasa.test/set-password?token=', (string) $url);
        $this->assertStringEndsWith('&email='.urlencode($member->email), (string) $url);

        $parts = $this->inviteParts((string) $url);
        $this->postJson('/api/v1/auth/reset-password', $parts + ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertOk();
        $this->assertSame('active', $member->fresh()->status);
        $this->postJson('/api/v1/auth/login', ['email' => $member->email, 'password' => self::PASSWORD])->assertOk();
    }
}
