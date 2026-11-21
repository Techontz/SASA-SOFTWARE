<?php

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Enquiry;
use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\OrganisationAgreement;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BillingPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agreed plans (one per organisation), their monthly invoices, the billing
 * cycle and paying an invoice from the organisation's own panel.
 */
class AgreementBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        config([
            'sasa.web_url' => 'https://app.sasa.test',
            'sasa.billing.driver' => 'sandbox',
            'sasa.billing.grace_days' => 7,
            'sasa.billing.invoice_email' => false,
            'sasa.billing.snippe.api_key' => 'snp_test_key',
            'sasa.billing.snippe.base_url' => 'https://api.snippe.sh',
            'sasa.billing.snippe.webhook_url' => 'https://api.sasa.test/api/v1/public/payments/snippe/webhook',
            'sasa.enquiries.notify' => '',
        ]);

        $this->travelTo(Carbon::parse('2026-11-21 09:00:00'));
        $this->admin = $this->makeUser('system_administrator');
    }

    /** A fresh request starts with an empty tenant context, as in production. */
    private function as(User $user, ?Project $project = null): static
    {
        app(TenantContext::class)->setUser(null);

        return $this->asUser($user, $project);
    }

    private function asAdmin(): static
    {
        app(TenantContext::class)->setUser(null);
        $this->actingAs($this->admin, 'sanctum');

        return $this;
    }

    /** @return array<string,mixed> */
    private function agreementPayload(array $overrides = []): array
    {
        return array_merge([
            'organisation_id' => $this->organisation->id,
            'name' => 'SASA for Test Infrastructure',
            'monthly_amount' => 1150000,
            'billing_day' => 1,
            'starts_on' => '2026-11-21',
            'modules' => ['grievance_management', 'stakeholder_register'],
            'limits' => ['projects' => 3, 'users' => 50],
            'terms_note' => 'Agreed scope: three projects, 50 users.',
            'internal_note' => 'Discounted 10% for a two-year term. Do not share.',
        ], $overrides);
    }

    private function createAgreement(array $overrides = []): OrganisationAgreement
    {
        $id = $this->asAdmin()->postJson('/api/v1/admin/agreements', $this->agreementPayload($overrides))
            ->assertCreated()->json('data.public_id');

        return OrganisationAgreement::where('public_id', $id)->firstOrFail();
    }

    /** A second customer, with its own project, administrator and agreement. */
    private function otherOrganisation(): array
    {
        $organisation = Organisation::create(['name' => 'Other Mining Co', 'slug' => 'other-mining', 'country' => 'TZ', 'timezone' => 'Africa/Dar_es_Salaam', 'status' => 'active']);
        $project = Project::create(['organisation_id' => $organisation->id, 'name' => 'Other Pit', 'code' => 'OTH-1', 'country' => 'TZ', 'timezone' => 'Africa/Dar_es_Salaam', 'status' => 'active']);
        $user = User::create([
            'organisation_id' => $organisation->id, 'name' => 'Other Admin', 'email' => 'other.admin@test.local',
            'password' => Hash::make('password'), 'status' => 'active', 'email_verified_at' => now(),
        ]);
        ProjectMembership::create([
            'organisation_id' => $organisation->id, 'project_id' => $project->id, 'user_id' => $user->id,
            'role_id' => Role::whereNull('organisation_id')->where('key', 'project_admin')->firstOrFail()->id,
            'handling_groups' => ['general'], 'status' => 'active', 'is_default' => true, 'joined_at' => now(),
        ]);

        return [$organisation, $project, $user];
    }

    private function runCycle(): void
    {
        $this->artisan('billing:run-cycle')->assertSuccessful();
    }

    // ------------------------------------------------------ quote details

    public function test_quote_details_are_validated_in_plain_english_and_stored(): void
    {
        $base = [
            'type' => 'quote', 'full_name' => 'Neema Kweka', 'email' => 'neema@example.co.tz',
            'organization' => 'Lake Zone Power', 'sector' => 'energy',
        ];

        $missing = $this->postJson('/api/v1/public/enquiries', $base)->assertStatus(422)
            ->assertJsonValidationErrors(['company_size', 'projects_count', 'users_count']);
        $this->assertSame('Choose the size of your company.', $missing->json('errors.company_size.0'));
        $this->assertSame('Enter how many projects you want to run on SASA.', $missing->json('errors.projects_count.0'));
        $this->assertSame('Enter roughly how many people will use SASA.', $missing->json('errors.users_count.0'));

        $this->postJson('/api/v1/public/enquiries', $base + [
            'company_size' => '10-20', 'projects_count' => 0, 'users_count' => 100001,
            'project_location' => str_repeat('a', 121), 'project_stage' => 'demolition', 'start_timeframe' => 'never',
        ])->assertStatus(422)->assertJsonValidationErrors([
            'company_size', 'projects_count', 'users_count', 'project_location', 'project_stage', 'start_timeframe',
        ])->assertJsonPath('errors.project_stage.0', 'Choose planning, construction, operation or a mix.');

        $reference = $this->postJson('/api/v1/public/enquiries', $base + [
            'company_size' => '201-1000', 'projects_count' => 3, 'users_count' => 50,
            'project_location' => ' Mwanza and Geita ', 'project_stage' => 'Construction', 'start_timeframe' => '1-3_months',
        ])->assertCreated()->json('data.reference');

        $quote = Enquiry::where('reference', $reference)->firstOrFail();
        $this->assertEquals([
            'company_size' => '201-1000', 'projects_count' => 3, 'users_count' => 50,
            'project_location' => 'Mwanza and Geita', 'project_stage' => 'construction', 'start_timeframe' => '1-3_months',
        ], $quote->details);

        // Optional questions skipped: present, and null.
        $bare = $this->postJson('/api/v1/public/enquiries', $base + ['company_size' => '1-50', 'projects_count' => 1, 'users_count' => 5])
            ->assertCreated()->json('data.reference');
        $this->assertNull(Enquiry::where('reference', $bare)->firstOrFail()->details['project_stage']);

        // Not a quote: the fields are neither required nor kept.
        $demo = $this->postJson('/api/v1/public/enquiries', array_merge($base, ['type' => 'demo', 'company_size' => 'nonsense', 'projects_count' => 3]))
            ->assertCreated()->json('data.reference');
        $this->assertNull(Enquiry::where('reference', $demo)->firstOrFail()->details);

        $list = $this->asAdmin()->getJson('/api/v1/admin/enquiries')->assertOk()->json('data');
        $byReference = collect($list)->keyBy('reference');
        // In the order the questions are asked, whatever order the database keeps.
        $this->assertSame(Enquiry::DETAIL_KEYS, array_keys($byReference[$reference]['details']));
        $this->assertSame('201-1000', $byReference[$reference]['details']['company_size']);
        $this->assertSame(3, $byReference[$reference]['details']['projects_count']);
        $this->assertNull($byReference[$demo]['details']);
    }

    // ----------------------------------------------------- public contact

    public function test_public_contact_details_come_from_configuration(): void
    {
        $this->getJson('/api/v1/public/contact')->assertOk()
            ->assertExactJson(['data' => ['email' => null, 'phone' => null, 'whatsapp' => null]]);

        config(['sasa.contact' => ['email' => 'sales@sasa.test', 'phone' => '+255 712 000 111', 'whatsapp' => '']]);

        $this->getJson('/api/v1/public/contact')->assertOk()
            ->assertExactJson(['data' => ['email' => 'sales@sasa.test', 'phone' => '+255 712 000 111', 'whatsapp' => null]]);
    }

    // ------------------------------------------------- platform administration

    public function test_admin_agreement_and_invoice_routes_require_billing_manage(): void
    {
        $this->getJson('/api/v1/admin/agreements')->assertUnauthorized();

        $agreement = $this->createAgreement();
        $projectAdmin = $this->makeUser('project_admin');

        $routes = [
            ['get', '/api/v1/admin/organisations'],
            ['get', '/api/v1/admin/agreements'],
            ['post', '/api/v1/admin/agreements'],
            ['patch', '/api/v1/admin/agreements/'.$agreement->public_id],
            ['post', '/api/v1/admin/agreements/'.$agreement->public_id.'/invoices'],
            ['get', '/api/v1/admin/invoices'],
        ];

        foreach ($routes as [$method, $url]) {
            $this->as($projectAdmin)->json($method, $url, [])->assertForbidden();
        }

        $this->assertSame(0, Invoice::count());
    }

    public function test_an_administrator_creates_lists_and_changes_agreements_one_current_per_organisation(): void
    {
        $enquiry = Enquiry::forceCreate([
            'public_id' => (string) Str::ulid(), 'reference' => 'ENQ-2026-0042', 'type' => 'quote',
            'full_name' => 'Neema', 'email' => 'n@example.test', 'organization' => 'Test Infrastructure Group', 'sector' => 'energy', 'status' => 'new',
        ]);

        $created = $this->asAdmin()->postJson('/api/v1/admin/agreements', $this->agreementPayload([
            'enquiry_id' => $enquiry->id,
            'modules' => ['stakeholder_register', 'grievance_management', 'stakeholder_register'],
        ]))->assertStatus(422)->assertJsonValidationErrors(['modules.0']);

        $created = $this->postJson('/api/v1/admin/agreements', $this->agreementPayload(['enquiry_id' => $enquiry->id]))
            ->assertCreated()
            ->assertJsonPath('data.organisation.id', $this->organisation->id)
            ->assertJsonPath('data.organisation.name', 'Test Infrastructure Group')
            ->assertJsonPath('data.currency', 'TZS')
            ->assertJsonPath('data.monthly_amount', 1150000)
            ->assertJsonPath('data.starts_on', '2026-11-21')
            ->assertJsonPath('data.next_due_on', '2026-11-21')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.enquiry_id', $enquiry->id)
            ->assertJsonPath('data.modules', ['stakeholder_register', 'grievance_management'])
            ->assertJsonPath('data.limits', ['projects' => 3, 'users' => 50])
            ->assertJsonPath('data.internal_note', 'Discounted 10% for a two-year term. Do not share.')
            ->assertJsonPath('data.auto_pay', false)
            ->assertJsonPath('data.created_by.id', $this->admin->id);
        $id = $created->json('data.public_id');
        $this->assertSame(26, strlen($id));

        // Validation in plain English.
        $this->postJson('/api/v1/admin/agreements', [])->assertStatus(422)
            ->assertJsonValidationErrors(['organisation_id', 'name', 'monthly_amount', 'billing_day', 'starts_on', 'modules', 'limits']);
        $this->postJson('/api/v1/admin/agreements', $this->agreementPayload(['billing_day' => 31, 'modules' => ['payroll']]))
            ->assertStatus(422)
            ->assertJsonPath('errors.billing_day.0', 'Choose a billing day from 1 to 28, so every month has it.')
            ->assertJsonValidationErrors(['modules.0']);

        // One current agreement per organisation.
        $this->postJson('/api/v1/admin/agreements', $this->agreementPayload())
            ->assertStatus(422)->assertJsonPath('error', 'agreement_exists')->assertJsonValidationErrors(['organisation_id']);

        $this->getJson('/api/v1/admin/agreements?status=active')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.public_id', $id)->assertJsonPath('data.0.organisation.name', 'Test Infrastructure Group');
        $this->getJson('/api/v1/admin/agreements?status=suspended')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/admin/agreements?status=nonsense')->assertStatus(422);

        $organisations = $this->getJson('/api/v1/admin/organisations')->assertOk()->json('data');
        $mine = collect($organisations)->firstWhere('id', $this->organisation->id);
        $this->assertSame(['id', 'name', 'projects_count', 'users_count', 'agreement'], array_keys($mine));
        $this->assertSame(1, $mine['projects_count']);
        $this->assertSame(1, $mine['users_count']);
        $this->assertSame($id, $mine['agreement']['public_id']);
        $this->assertArrayNotHasKey('internal_note', $mine['agreement']);

        // Change price and scope, then suspend.
        $this->patchJson("/api/v1/admin/agreements/{$id}", ['monthly_amount' => 1250000])->assertOk();
        $this->patchJson("/api/v1/admin/agreements/{$id}", ['limits' => ['projects' => 4, 'users' => null], 'status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.monthly_amount', 1250000)
            ->assertJsonPath('data.limits', ['projects' => 4, 'users' => null])
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.name', 'SASA for Test Infrastructure');
        $this->patchJson("/api/v1/admin/agreements/{$id}", ['status' => 'past_due'])->assertStatus(422)->assertJsonValidationErrors(['status']);

        // A suspended agreement still counts; a cancelled one does not, and is closed.
        $this->postJson('/api/v1/admin/agreements', $this->agreementPayload())->assertStatus(422)->assertJsonPath('error', 'agreement_exists');
        $this->patchJson("/api/v1/admin/agreements/{$id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->patchJson("/api/v1/admin/agreements/{$id}", ['name' => 'Renamed'])->assertStatus(422)->assertJsonPath('error', 'agreement_closed');
        $this->postJson('/api/v1/admin/agreements', $this->agreementPayload(['name' => 'SASA renewed']))->assertCreated();

        $this->assertDatabaseHas('organisation_agreements', ['public_id' => $id]); // never deleted
        foreach (['agreement.created', 'agreement.updated', 'agreement.status_changed'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->where('entity_reference', $id)->where('user_id', $this->admin->id)->exists(), "Missing audit {$action}");
        }
    }

    // -------------------------------------------------- the customer's panel

    public function test_customer_endpoints_are_scoped_to_their_own_organisation(): void
    {
        $agreement = $this->createAgreement();
        $this->asAdmin()->postJson("/api/v1/admin/agreements/{$agreement->public_id}/invoices")->assertCreated();
        $mine = Invoice::firstOrFail();

        [$otherOrganisation, $otherProject, $otherAdmin] = $this->otherOrganisation();
        $theirs = $this->createAgreement(['organisation_id' => $otherOrganisation->id, 'name' => 'SASA for Other Mining']);
        $this->asAdmin()->postJson("/api/v1/admin/agreements/{$theirs->public_id}/invoices")->assertCreated();
        $theirInvoice = Invoice::where('organisation_id', $otherOrganisation->id)->firstOrFail();

        $projectAdmin = $this->makeUser('project_admin');

        $plan = $this->as($projectAdmin)->getJson('/api/v1/organisation/agreement')->assertOk()->json('data');
        $this->assertSame([
            'public_id', 'name', 'currency', 'monthly_amount', 'billing_day', 'starts_on', 'next_due_on',
            'status', 'modules', 'limits', 'terms_note', 'auto_pay', 'auto_pay_phone', 'usage',
        ], array_keys($plan));
        $this->assertSame($agreement->public_id, $plan['public_id']);
        $this->assertSame('Agreed scope: three projects, 50 users.', $plan['terms_note']);
        $this->assertSame(['projects' => 1, 'users' => 2], $plan['usage']);
        $this->assertStringNotContainsString('Do not share', json_encode($plan));

        $invoices = $this->as($projectAdmin)->getJson('/api/v1/organisation/invoices')->assertOk()->json('data');
        $this->assertSame([$mine->public_id], array_column($invoices, 'public_id'));
        $this->assertSame([
            'public_id', 'number', 'period_start', 'period_end', 'amount', 'currency', 'due_on', 'status', 'paid_at', 'latest_payment',
        ], array_keys($invoices[0]));

        // Another organisation's invoice does not exist, as far as this user is concerned.
        $this->as($projectAdmin)->postJson("/api/v1/organisation/invoices/{$theirInvoice->public_id}/pay", ['method' => 'card'])->assertNotFound();
        $this->assertSame(0, Payment::count());

        // The organisation comes from the project, never from the request.
        $this->as($projectAdmin)->getJson('/api/v1/organisation/invoices?organisation_id='.$otherOrganisation->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $mine->public_id);

        // The other organisation sees only its own.
        $this->as($otherAdmin, $otherProject)->getJson('/api/v1/organisation/agreement')->assertOk()
            ->assertJsonPath('data.public_id', $theirs->public_id);
        $this->as($otherAdmin, $otherProject)->getJson('/api/v1/organisation/invoices')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $theirInvoice->public_id);
        $this->as($otherAdmin, $otherProject)->postJson("/api/v1/organisation/invoices/{$mine->public_id}/pay", ['method' => 'card'])->assertNotFound();
        // ...and cannot borrow a project it is not a member of.
        $this->as($otherAdmin, $this->project)->getJson('/api/v1/organisation/agreement')->assertNotFound();

        // A field officer cannot see the plan.
        $field = $this->makeUser('field_officer');
        $this->as($field)->getJson('/api/v1/organisation/agreement')->assertForbidden();
        $this->as($field)->getJson('/api/v1/organisation/invoices')->assertForbidden();

        // The roles on the organisation's projects, read-only.
        $roles = $this->as($projectAdmin)->getJson('/api/v1/organisation/roles')->assertOk()->json('data');
        $keys = array_column($roles, 'key');
        $this->assertContains('project_admin', $keys);
        $this->assertContains('field_officer', $keys);
        $this->assertNotContains('system_administrator', $keys);
        $fieldOfficer = collect($roles)->firstWhere('key', 'field_officer');
        $this->assertSame(['key', 'name', 'description', 'permissions'], array_keys($fieldOfficer));
        $this->assertSame(['key', 'name', 'group'], array_keys($fieldOfficer['permissions'][0]));
        $this->assertContains('grievance.create', array_column($fieldOfficer['permissions'], 'key'));
    }

    public function test_an_organisation_without_an_agreement_sees_null(): void
    {
        $projectAdmin = $this->makeUser('project_admin');

        $this->as($projectAdmin)->getJson('/api/v1/organisation/agreement')->assertOk()->assertExactJson(['data' => null]);
        $this->as($projectAdmin)->getJson('/api/v1/organisation/invoices')->assertOk()->assertExactJson(['data' => []]);
        $this->as($projectAdmin)->patchJson('/api/v1/organisation/agreement/auto-pay', ['auto_pay' => false])->assertNotFound();
    }

    // --------------------------------------------------------- billing cycle

    public function test_the_cycle_issues_one_invoice_per_period_advances_and_notifies(): void
    {
        $agreement = $this->createAgreement();
        $projectAdmin = $this->makeUser('project_admin');
        $field = $this->makeUser('field_officer');

        $this->runCycle();
        $this->runCycle(); // the same day again: nothing new

        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::firstOrFail();
        $this->assertMatchesRegularExpression('/^INV-2026-0001$/', $invoice->number);
        $this->assertSame('2026-11-21', $invoice->period_start->toDateString());
        $this->assertSame('2026-11-30', $invoice->period_end->toDateString());
        $this->assertSame('2026-11-21', $invoice->due_on->toDateString());
        $this->assertSame('1150000.00', (string) $invoice->amount);
        $this->assertSame('TZS', $invoice->currency);
        $this->assertSame('open', $invoice->status);
        $this->assertSame('2026-12-01', $agreement->fresh()->next_due_on->toDateString());
        $this->assertTrue(AuditLog::where('action', 'invoice.created')->where('entity_reference', $invoice->number)->exists());

        // The billing users are told in SASA; a field officer is not.
        $this->assertSame(1, $projectAdmin->notifications()->count());
        $notice = $projectAdmin->notifications()->first();
        $this->assertSame('billing.invoice_issued', $notice->data['event']);
        $this->assertSame($invoice->number, $notice->data['invoice_number']);
        $this->assertSame('/subscription', $notice->data['url']);
        $this->assertSame(0, $field->notifications()->count());

        // A price change applies to invoices not yet issued.
        $this->asAdmin()->patchJson("/api/v1/admin/agreements/{$agreement->public_id}", ['monthly_amount' => 1200000])->assertOk();
        $this->assertSame('1150000.00', (string) $invoice->fresh()->amount);

        $this->travelTo(Carbon::parse('2026-12-01 06:00:00'));
        $this->runCycle();
        $this->runCycle();

        $this->assertSame(2, Invoice::count());
        $december = Invoice::orderByDesc('id')->firstOrFail();
        $this->assertSame('INV-2026-0002', $december->number);
        $this->assertSame('2026-12-01', $december->period_start->toDateString());
        $this->assertSame('2026-12-31', $december->period_end->toDateString());
        $this->assertSame('1200000.00', (string) $december->amount);
        $this->assertSame('2027-01-01', $agreement->fresh()->next_due_on->toDateString());

        // A suspended agreement is not invoiced.
        $this->asAdmin()->patchJson("/api/v1/admin/agreements/{$agreement->public_id}", ['status' => 'suspended'])->assertOk();
        $this->travelTo(Carbon::parse('2027-01-01 06:00:00'));
        $this->runCycle();
        $this->assertSame(2, Invoice::count());
    }

    public function test_auto_pay_sends_a_mobile_money_prompt_when_the_invoice_is_issued(): void
    {
        $agreement = $this->createAgreement();
        $projectAdmin = $this->makeUser('project_admin');

        $this->as($projectAdmin)->patchJson('/api/v1/organisation/agreement/auto-pay', ['auto_pay' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['phone'])
            ->assertJsonPath('errors.phone.0', 'Enter the mobile number that will receive the monthly payment prompt.');
        $this->as($projectAdmin)->patchJson('/api/v1/organisation/agreement/auto-pay', ['auto_pay' => true, 'phone' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);

        $this->as($projectAdmin)->patchJson('/api/v1/organisation/agreement/auto-pay', ['auto_pay' => true, 'phone' => '0712 345 678'])
            ->assertOk()->assertJsonPath('data.auto_pay', true)->assertJsonPath('data.auto_pay_phone', '255712345678');
        $this->assertTrue(AuditLog::where('action', 'agreement.updated')->where('entity_reference', $agreement->public_id)->where('user_id', $projectAdmin->id)->exists());

        $this->runCycle();
        $this->runCycle();

        $invoice = Invoice::firstOrFail();
        $this->assertSame(1, Payment::count());
        $payment = Payment::firstOrFail();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertNull($payment->subscription_request_id);
        $this->assertSame('mobile_money', $payment->method);
        $this->assertSame('255712345678', $payment->phone);
        $this->assertSame('pending', $payment->status);
        $this->assertStringContainsString('Approve it on that phone with your PIN', $projectAdmin->notifications()->first()->data['body']);

        // The payer approves on the phone (the sandbox does this by itself).
        $this->travel(7)->seconds();
        $this->getJson("/api/v1/public/payments/{$payment->public_id}")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->paid_at);

        $this->as($projectAdmin)->getJson('/api/v1/organisation/invoices')->assertOk()
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.latest_payment.public_id', $payment->public_id)
            ->assertJsonPath('data.0.latest_payment.status', 'succeeded');

        $this->as($projectAdmin)->patchJson('/api/v1/organisation/agreement/auto-pay', ['auto_pay' => false])
            ->assertOk()->assertJsonPath('data.auto_pay', false);
    }

    public function test_unpaid_invoices_become_overdue_after_the_grace_period_and_paying_clears_past_due(): void
    {
        $agreement = $this->createAgreement();
        $projectAdmin = $this->makeUser('project_admin');
        $this->runCycle();
        $invoice = Invoice::firstOrFail();

        // Seven days late is still within the grace period.
        $this->travelTo(Carbon::parse('2026-11-28 06:00:00'));
        $this->runCycle();
        $this->assertSame('open', $invoice->fresh()->status);
        $this->assertSame('active', $agreement->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-29 06:00:00'));
        $this->runCycle();
        $this->runCycle();
        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame('past_due', $agreement->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'agreement.status_changed')->where('entity_reference', $agreement->public_id)->exists());
        $this->assertSame(1, $projectAdmin->notifications()->where('data->event', 'billing.invoice_overdue')->count());

        // Never suspended by the system, however late.
        $this->travelTo(Carbon::parse('2027-03-15 06:00:00'));
        $this->runCycle();
        $this->assertSame('past_due', $agreement->fresh()->status);
        $this->assertGreaterThan(1, Invoice::count());

        // Pay every overdue invoice by card in the sandbox.
        $this->as($projectAdmin)->getJson('/api/v1/organisation/agreement')->assertJsonPath('data.status', 'past_due');

        foreach (Invoice::where('status', 'overdue')->orderBy('id')->get() as $i => $overdue) {
            $payment = $this->as($projectAdmin)->postJson("/api/v1/organisation/invoices/{$overdue->public_id}/pay", ['method' => 'card'])
                ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.amount', 1150000)->assertJsonPath('data.currency', 'TZS');
            $id = $payment->json('data.public_id');

            // The sandbox card page returns the person to the plan page.
            $this->assertSame("https://app.sasa.test/subscribe/sandbox-card?payment={$id}&return=/subscription", $payment->json('data.checkout_url'));

            $this->postJson("/api/v1/public/payments/{$id}/sandbox-complete", ['outcome' => 'succeeded'])->assertOk()->assertJsonPath('data.status', 'succeeded');
            $this->assertSame('paid', $overdue->fresh()->status);
        }

        $this->assertSame('active', $agreement->fresh()->status);
        $this->as($projectAdmin)->postJson("/api/v1/organisation/invoices/{$invoice->public_id}/pay", ['method' => 'card'])
            ->assertStatus(422)->assertJsonPath('error', 'already_paid');
    }

    public function test_a_subscription_payment_keeps_its_own_sandbox_card_url(): void
    {
        $this->seed(BillingPlanSeeder::class);
        $plan = Plan::where('code', 'starter')->firstOrFail();
        $request = $this->postJson('/api/v1/public/subscriptions', [
            'plan_id' => $plan->id, 'currency' => 'TZS', 'organisation_name' => 'Kilimanjaro Lines', 'contact_name' => 'Neema Kweka',
            'email' => 'neema@example.co.tz', 'phone' => '0712345678',
        ])->assertCreated()->json('data.public_id');

        $payment = $this->postJson("/api/v1/public/subscriptions/{$request}/pay", ['method' => 'card'])->assertCreated();
        $this->assertSame('https://app.sasa.test/subscribe/sandbox-card?payment='.$payment->json('data.public_id'), $payment->json('data.checkout_url'));
    }

    public function test_snippe_invoice_card_payments_return_to_the_plan_page(): void
    {
        $agreement = $this->createAgreement();
        $this->asAdmin()->postJson("/api/v1/admin/agreements/{$agreement->public_id}/invoices")->assertCreated();
        $invoice = Invoice::firstOrFail();

        config(['sasa.billing.driver' => 'snippe']);
        Http::fake(['api.snippe.sh/v1/payments' => Http::response([
            'status' => 'success', 'code' => 201,
            'data' => ['reference' => 'pi_inv_1', 'status' => 'pending', 'payment_type' => 'card', 'payment_url' => 'https://pay.snippe.sh/c/inv1'],
        ], 201)]);

        $projectAdmin = $this->makeUser('project_admin');
        $response = $this->as($projectAdmin)->postJson("/api/v1/organisation/invoices/{$invoice->public_id}/pay", ['method' => 'card'])
            ->assertCreated()->assertJsonPath('data.checkout_url', 'https://pay.snippe.sh/c/inv1');
        $id = $response->json('data.public_id');

        Http::assertSent(fn (HttpRequest $sent) => $sent['payment_type'] === 'card'
            && $sent['details']['amount'] === 1150000
            && $sent['details']['currency'] === 'TZS'
            && $sent['details']['redirect_url'] === "https://app.sasa.test/subscription?payment={$id}"
            && $sent['details']['cancel_url'] === "https://app.sasa.test/subscription?payment={$id}&outcome=cancelled"
            && $sent['metadata'] === ['payment_id' => $id, 'invoice_number' => $invoice->number]
            && $sent['customer']['email'] === $projectAdmin->email);

        // There is no sandbox shortcut outside the sandbox.
        $this->postJson("/api/v1/public/payments/{$id}/sandbox-complete", ['outcome' => 'succeeded'])->assertNotFound();
    }

    // -------------------------------------------------- admin settlements

    public function test_an_administrator_marks_an_invoice_paid_or_voids_it_and_both_are_audited(): void
    {
        $agreement = $this->createAgreement();
        $projectAdmin = $this->makeUser('project_admin');

        $issued = $this->asAdmin()->postJson("/api/v1/admin/agreements/{$agreement->public_id}/invoices")->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.organisation.id', $this->organisation->id)
            ->assertJsonPath('data.agreement.public_id', $agreement->public_id)
            ->assertJsonPath('data.period_start', '2026-11-21');
        $first = $issued->json('data.public_id');
        $this->assertSame(1, $projectAdmin->notifications()->count());

        // Issuing now bills the next period early.
        $second = $this->postJson("/api/v1/admin/agreements/{$agreement->public_id}/invoices")->assertCreated()
            ->assertJsonPath('data.period_start', '2026-12-01')->json('data.public_id');

        // A customer started paying by card, then paid by bank transfer.
        $this->as($projectAdmin)->postJson("/api/v1/organisation/invoices/{$first}/pay", ['method' => 'card'])->assertCreated();

        $this->asAdmin()->postJson("/api/v1/admin/invoices/{$first}/mark-paid", ['note' => 'Bank transfer CRDB ref 77812'])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('cancelled', Payment::firstOrFail()->status);
        $paid = Invoice::where('public_id', $first)->firstOrFail();
        $this->assertNotNull($paid->paid_at);
        $audit = AuditLog::where('action', 'invoice.marked_paid')->where('entity_reference', $paid->number)->firstOrFail();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame('Bank transfer CRDB ref 77812', $audit->context['note']);

        $this->postJson("/api/v1/admin/invoices/{$first}/mark-paid")->assertStatus(422)->assertJsonPath('error', 'already_paid');
        $this->postJson("/api/v1/admin/invoices/{$first}/void", ['reason' => 'Mistake'])->assertStatus(422)->assertJsonPath('error', 'already_paid');

        $this->postJson("/api/v1/admin/invoices/{$second}/void", [])->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'Say why this invoice is being voided.');
        $this->postJson("/api/v1/admin/invoices/{$second}/void", ['reason' => 'Issued early by mistake'])
            ->assertOk()->assertJsonPath('data.status', 'void');
        $voided = Invoice::where('public_id', $second)->firstOrFail();
        $audit = AuditLog::where('action', 'invoice.voided')->where('entity_reference', $voided->number)->firstOrFail();
        $this->assertSame('Issued early by mistake', $audit->context['reason']);
        $this->assertTrue(AuditLog::where('action', 'invoice.status_changed')->where('entity_reference', $voided->number)->exists());

        $this->as($projectAdmin)->postJson("/api/v1/organisation/invoices/{$second}/pay", ['method' => 'card'])
            ->assertStatus(422)->assertJsonPath('error', 'invoice_void');
        $this->asAdmin()->postJson("/api/v1/admin/invoices/{$second}/mark-paid")->assertStatus(422)->assertJsonPath('error', 'invoice_void');

        // Listed for administrators, newest period first, filterable.
        $this->getJson('/api/v1/admin/invoices')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.public_id', $second)->assertJsonPath('data.1.latest_payment.provider', 'sandbox');
        $this->getJson('/api/v1/admin/invoices?status=void')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/invoices?organisation_id='.($this->organisation->id + 999))->assertOk()->assertJsonPath('meta.total', 0);

        // Never deleted.
        $this->assertSame(2, Invoice::count());

        // An invoice cannot be issued for a suspended agreement.
        $this->patchJson("/api/v1/admin/agreements/{$agreement->public_id}", ['status' => 'suspended'])->assertOk();
        $this->postJson("/api/v1/admin/agreements/{$agreement->public_id}/invoices")->assertStatus(422)->assertJsonPath('error', 'agreement_not_billable');
    }

    public function test_marking_paid_by_hand_clears_past_due(): void
    {
        $agreement = $this->createAgreement();
        $this->runCycle();
        $this->travelTo(Carbon::parse('2026-11-29 06:00:00'));
        $this->runCycle();
        $this->assertSame('past_due', $agreement->fresh()->status);

        $invoice = Invoice::firstOrFail();
        $this->asAdmin()->postJson("/api/v1/admin/invoices/{$invoice->public_id}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('active', $agreement->fresh()->status);
    }

    // ------------------------------------------------------------ permission

    public function test_organisation_billing_is_granted_to_the_project_administrator_and_not_the_field_officer(): void
    {
        $this->assertDatabaseHas('permissions', ['key' => 'organisation.billing', 'name' => "See and pay your organisation's SASA plan"]);

        $roles = Role::whereNull('organisation_id')->get()
            ->filter(fn ($role) => in_array('organisation.billing', $role->permissionKeys(), true))
            ->pluck('key')->sort()->values()->all();

        $this->assertSame(['project_admin', 'system_administrator'], $roles);
    }
}
