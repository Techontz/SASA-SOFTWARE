<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Mail\NewEnquiryMail;
use App\Models\AuditLog;
use App\Models\Enquiry;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EnquiryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/public/enquiries';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        config(['sasa.enquiries.notify' => '']);
    }

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'interest',
            'full_name' => 'Neema Kweka',
            'email' => 'Neema@Example.co.tz',
            'organization' => 'Kilimanjaro Lines Ltd',
            'sector' => 'infrastructure',
            'phone' => '0712 345 678',
            'modules' => ['grievance_management', 'stakeholder_register'],
            'message' => 'We are starting a 220 kV line in March.',
        ], $overrides);
    }

    private function enquiry(array $overrides = []): Enquiry
    {
        $reference = $this->postJson(self::URL, $this->payload($overrides))->assertCreated()->json('data.reference');

        return Enquiry::where('reference', $reference)->firstOrFail();
    }

    // --------------------------------------------------------------- public

    public function test_interest_and_demo_enquiries_are_stored_with_a_reference(): void
    {
        $interest = $this->postJson(self::URL, $this->payload())->assertCreated();
        $this->assertSame(['reference', 'type'], array_keys($interest->json('data')));
        $interest->assertJsonPath('data.type', 'interest');
        $this->assertMatchesRegularExpression('/^ENQ-\d{4}-0001$/', $interest->json('data.reference'));

        $demo = $this->postJson(self::URL, $this->payload(['type' => 'demo', 'sector' => 'mining', 'phone' => null, 'modules' => null, 'message' => null]))
            ->assertCreated()->assertJsonPath('data.type', 'demo');
        $this->assertMatchesRegularExpression('/^ENQ-\d{4}-0002$/', $demo->json('data.reference'));

        $stored = Enquiry::where('reference', $interest->json('data.reference'))->firstOrFail();
        $this->assertSame('new', $stored->status);
        $this->assertSame('neema@example.co.tz', $stored->email);
        // Catalogue order, not the order ticked.
        $this->assertSame(['stakeholder_register', 'grievance_management'], $stored->modules);
        $this->assertSame(26, strlen($stored->public_id));
        // Never the raw IP: a keyed hash of it.
        $this->assertSame(hash_hmac('sha256', '127.0.0.1', (string) config('app.key')), $stored->ip_hash);
        $this->assertStringNotContainsString('127.0.0.1', json_encode($stored->getAttributes()));

        $bare = Enquiry::where('reference', $demo->json('data.reference'))->firstOrFail();
        $this->assertNull($bare->phone);
        $this->assertNull($bare->modules);
        $this->assertNull($bare->message);

        $this->assertTrue(AuditLog::where('action', 'enquiry.created')->where('entity_reference', $stored->reference)->exists());
    }

    public function test_a_quote_request_is_stored_and_an_unknown_type_is_refused(): void
    {
        $quote = $this->postJson(self::URL, $this->payload(['type' => 'quote', 'company_size' => '51-200', 'projects_count' => 2, 'users_count' => 30]))
            ->assertCreated()->assertJsonPath('data.type', 'quote');
        $this->assertSame('quote', Enquiry::where('reference', $quote->json('data.reference'))->value('type'));

        $this->postJson(self::URL, $this->payload(['type' => 'subscription']))->assertStatus(422);
    }

    public function test_required_fields_are_validated_in_plain_english(): void
    {
        $response = $this->postJson(self::URL, [])->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'full_name', 'email', 'organization', 'sector']);

        $this->assertSame('Enter your full name.', $response->json('errors.full_name.0'));
        $this->assertSame('Enter your e-mail address.', $response->json('errors.email.0'));
        $this->assertSame('Enter the name of your organisation.', $response->json('errors.organization.0'));
        $this->assertSame('Choose your sector.', $response->json('errors.sector.0'));

        $this->postJson(self::URL, $this->payload([
            'type' => 'newsletter', 'email' => 'not-an-email', 'phone' => 'call me', 'message' => str_repeat('a', 2001),
        ]))->assertStatus(422)->assertJsonValidationErrors(['type', 'email', 'phone', 'message']);

        $this->assertSame(0, Enquiry::count());
    }

    public function test_sector_and_modules_are_validated(): void
    {
        $this->postJson(self::URL, $this->payload(['sector' => 'farming']))
            ->assertStatus(422)->assertJsonValidationErrors(['sector'])
            ->assertJsonPath('errors.sector.0', 'Choose infrastructure, mining, energy or other.');

        $this->postJson(self::URL, $this->payload(['modules' => ['grievance_management', 'payroll']]))
            ->assertStatus(422)->assertJsonValidationErrors(['modules.1']);

        $this->postJson(self::URL, $this->payload(['modules' => 'grievance_management']))
            ->assertStatus(422)->assertJsonValidationErrors(['modules']);

        $this->postJson(self::URL, $this->payload(['sector' => 'Energy']))->assertCreated();
        $this->assertSame(1, Enquiry::count());
    }

    public function test_not_sure_alongside_other_modules_keeps_only_not_sure(): void
    {
        $enquiry = $this->enquiry(['modules' => ['pap_register', 'not_sure', 'eshs_assurance']]);
        $this->assertSame(['not_sure'], $enquiry->modules);

        $alone = $this->enquiry(['modules' => ['not_sure']]);
        $this->assertSame(['not_sure'], $alone->modules);
    }

    public function test_the_honeypot_answers_201_and_stores_nothing(): void
    {
        Mail::fake();
        config(['sasa.enquiries.notify' => 'team@sasa.test']);

        $response = $this->postJson(self::URL, $this->payload(['type' => 'demo', 'website' => 'https://spam.example']))
            ->assertCreated()
            ->assertJsonPath('data.type', 'demo');

        $this->assertSame(['reference', 'type'], array_keys($response->json('data')));
        $this->assertMatchesRegularExpression('/^ENQ-\d{4}-\d{4}$/', $response->json('data.reference'));
        $this->assertSame(0, Enquiry::count());
        $this->assertFalse(AuditLog::where('action', 'enquiry.created')->exists());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------- admin

    public function test_admin_enquiry_routes_require_enquiry_manage(): void
    {
        $enquiry = $this->enquiry();

        $this->getJson('/api/v1/admin/enquiries')->assertUnauthorized();

        foreach (['project_admin', 'field_officer'] as $role) {
            $user = $this->makeUser($role);
            $this->asUser($user)->getJson('/api/v1/admin/enquiries')->assertForbidden();
            $this->asUser($user)->patchJson('/api/v1/admin/enquiries/'.$enquiry->public_id, ['status' => 'closed'])->assertForbidden();
        }

        $this->assertSame('new', $enquiry->fresh()->status);

        app(TenantContext::class)->setUser(null);
        $admin = $this->makeUser('system_administrator');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/enquiries')->assertOk();
    }

    public function test_an_administrator_lists_and_filters_enquiries_newest_first(): void
    {
        $interest = $this->enquiry();
        $this->travel(1)->minutes();
        $demo = $this->enquiry(['type' => 'demo', 'sector' => 'energy']);
        $this->travel(1)->minutes();
        $archived = $this->enquiry(['type' => 'demo']);
        $archived->forceFill(['archived_at' => now()])->save();

        $admin = $this->makeUser('system_administrator');
        $this->actingAs($admin, 'sanctum');

        $list = $this->getJson('/api/v1/admin/enquiries')->assertOk();
        $this->assertSame([$demo->public_id, $interest->public_id], array_column($list->json('data'), 'public_id'));
        $list->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.reference', $interest->reference)
            ->assertJsonPath('data.1.organization', 'Kilimanjaro Lines Ltd')
            ->assertJsonPath('data.1.modules', ['stakeholder_register', 'grievance_management'])
            ->assertJsonPath('data.1.status', 'new')
            ->assertJsonPath('data.1.handled_by', null)
            ->assertJsonPath('data.0.sector', 'energy');

        $this->assertSame(
            ['id', 'public_id', 'reference', 'type', 'full_name', 'email', 'organization', 'sector', 'phone',
                'modules', 'details', 'message', 'status', 'admin_notes', 'handled_by', 'handled_at', 'created_at'],
            array_keys($list->json('data.0')),
        );
        $this->assertArrayNotHasKey('ip_hash', $list->json('data.0'));

        $this->getJson('/api/v1/admin/enquiries?type=demo')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $demo->public_id);
        $this->getJson('/api/v1/admin/enquiries?status=closed')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/enquiries?type=newsletter')->assertStatus(422);
        $this->getJson('/api/v1/admin/enquiries?status=nonsense')->assertStatus(422);
    }

    public function test_an_administrator_updates_the_status_and_it_is_audited(): void
    {
        $enquiry = $this->enquiry(['type' => 'demo']);

        $admin = $this->makeUser('system_administrator');
        $this->actingAs($admin, 'sanctum');
        $url = '/api/v1/admin/enquiries/'.$enquiry->public_id;

        $this->patchJson($url, ['status' => 'archived'])->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->patchJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->patchJson($url, ['status' => 'contacted', 'admin_notes' => 'Demo booked for Tuesday.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted')
            ->assertJsonPath('data.admin_notes', 'Demo booked for Tuesday.')
            ->assertJsonPath('data.handled_by.id', $admin->id)
            ->assertJsonPath('data.handled_by.name', $admin->name);

        $fresh = $enquiry->fresh();
        $this->assertSame($admin->id, $fresh->handled_by);
        $this->assertNotNull($fresh->handled_at);

        $this->assertTrue(AuditLog::where('action', 'enquiry.status_changed')
            ->where('entity_reference', $enquiry->reference)->where('user_id', $admin->id)->exists());

        // Notes are kept when not sent.
        $this->patchJson($url, ['status' => 'closed'])->assertOk()->assertJsonPath('data.admin_notes', 'Demo booked for Tuesday.');

        $this->patchJson('/api/v1/admin/enquiries/01J0000000000000000000000Z', ['status' => 'closed'])->assertNotFound();
    }

    public function test_enquiry_manage_is_for_the_system_administrator_only(): void
    {
        $this->assertDatabaseHas('permissions', ['key' => 'enquiry.manage']);

        $roles = Role::whereNull('organisation_id')->get()
            ->filter(fn ($role) => in_array('enquiry.manage', $role->permissionKeys(), true))
            ->pluck('key')->all();

        $this->assertSame(['system_administrator'], array_values($roles));
    }

    // ------------------------------------------------------------------ mail

    public function test_no_mail_is_queued_when_no_one_is_configured(): void
    {
        Mail::fake();

        $this->enquiry();

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_the_team_is_mailed_about_each_real_enquiry_when_configured(): void
    {
        Mail::fake();
        config(['sasa.enquiries.notify' => 'sales@sasa.test, Founder@SASA.test ,not-an-address']);

        $demo = $this->enquiry(['type' => 'demo', 'organization' => 'Geita Gold & Co']);

        Mail::assertQueued(NewEnquiryMail::class, function (NewEnquiryMail $mail) use ($demo) {
            return $mail->enquiry->is($demo)
                && $mail->hasTo('sales@sasa.test')
                && $mail->hasTo('founder@sasa.test')
                && ! $mail->hasTo('not-an-address')
                && $mail->hasReplyTo('neema@example.co.tz')
                && $mail->envelope()->subject === 'New demo request from Geita Gold & Co';
        });

        $interest = $this->enquiry();
        Mail::assertQueued(NewEnquiryMail::class, fn (NewEnquiryMail $mail) => $mail->enquiry->is($interest)
            && $mail->envelope()->subject === 'New interest registration from Kilimanjaro Lines Ltd');
        Mail::assertQueuedCount(2);

        $text = (new NewEnquiryMail($demo))->render();
        $this->assertStringContainsString($demo->reference, $text);
        $this->assertStringContainsString('Geita Gold & Co', $text);
        $this->assertStringContainsString('stakeholder register, grievance management', $text);
    }

    public function test_a_mail_failure_does_not_fail_the_enquiry(): void
    {
        config(['sasa.enquiries.notify' => 'sales@sasa.test']);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $this->postJson(self::URL, $this->payload())->assertCreated();
        $this->assertSame(1, Enquiry::count());
    }
}
