<?php

namespace Tests\Feature;

use App\Domain\Billing\PhoneNumber;
use App\Domain\Billing\SnippeWebhook;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SubscriptionRequest;
use Database\Seeders\BillingPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        $this->seed(BillingPlanSeeder::class);

        config([
            'sasa.web_url' => 'https://app.sasa.test',
            'sasa.billing.driver' => 'sandbox',
            'sasa.billing.snippe.api_key' => 'snp_test_key',
            'sasa.billing.snippe.webhook_secret' => 'whsec_test',
            'sasa.billing.snippe.base_url' => 'https://api.snippe.sh',
            'sasa.billing.snippe.webhook_url' => 'https://api.sasa.test/api/v1/public/payments/snippe/webhook',
        ]);
    }

    private function plan(string $code): Plan
    {
        return Plan::where('code', $code)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function requestPayload(array $overrides = []): array
    {
        return array_merge([
            'plan_id' => $this->plan('starter')->id,
            'currency' => 'TZS',
            'organisation_name' => 'Kilimanjaro Lines Ltd',
            'contact_name' => 'Neema Kweka',
            'email' => 'neema@example.co.tz',
            'phone' => '0712 345 678',
            'country' => 'TZ',
        ], $overrides);
    }

    private function createRequest(array $overrides = []): SubscriptionRequest
    {
        $response = $this->postJson('/api/v1/public/subscriptions', $this->requestPayload($overrides));
        $response->assertCreated();

        return SubscriptionRequest::where('public_id', $response->json('data.public_id'))->firstOrFail();
    }

    private function useSnippe(): void
    {
        config(['sasa.billing.driver' => 'snippe']);
    }

    private function signedWebhook(array $event, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        $body = json_encode($event, JSON_UNESCAPED_SLASHES);
        $ts = (string) ($timestamp ?? now()->getTimestamp());
        $signature = SnippeWebhook::sign($ts, $body, $secret ?? 'whsec_test');

        return $this->call('POST', '/api/v1/public/payments/snippe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_EVENT' => $event['type'] ?? '',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], $body);
    }

    // ----------------------------------------------------------------- plans

    public function test_the_public_plan_list_hides_archived_plans_and_is_ordered(): void
    {
        $this->plan('starter')->forceFill(['archived_at' => now()])->save();

        $response = $this->getJson('/api/v1/public/plans')->assertOk();

        $this->assertSame(['professional', 'enterprise'], array_column($response->json('data'), 'code'));
        $response->assertJsonPath('data.0.price_usd', 449)
            ->assertJsonPath('data.0.price_tzs', 1150000)
            ->assertJsonPath('data.0.is_featured', true)
            ->assertJsonPath('data.0.limits.projects', 3)
            ->assertJsonPath('data.1.is_custom_quote', true)
            ->assertJsonPath('data.1.price_usd', null)
            ->assertJsonPath('data.1.limits.users', null);
        $this->assertArrayNotHasKey('archived_at', $response->json('data.0'));
    }

    public function test_seeded_copy_has_no_em_dashes(): void
    {
        foreach (Plan::all() as $plan) {
            $text = json_encode([$plan->name, $plan->tagline, $plan->description, $plan->features], JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('—', (string) $text);
        }
    }

    // --------------------------------------------------------------- requests

    public function test_a_request_copies_the_price_for_the_chosen_currency(): void
    {
        $tzs = $this->postJson('/api/v1/public/subscriptions', $this->requestPayload())->assertCreated();
        $tzs->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.currency', 'TZS')
            ->assertJsonPath('data.amount', 380000)
            ->assertJsonPath('data.plan.code', 'starter')
            ->assertJsonPath('data.phone', '255712345678')
            ->assertJsonPath('data.latest_payment', null);
        $this->assertMatchesRegularExpression('/^SUB-\d{4}-0001$/', $tzs->json('data.reference'));
        $this->assertArrayNotHasKey('admin_notes', $tzs->json('data'));

        $usd = $this->postJson('/api/v1/public/subscriptions', $this->requestPayload(['currency' => 'usd']))->assertCreated();
        $usd->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.amount', 149);
        $this->assertMatchesRegularExpression('/^SUB-\d{4}-0002$/', $usd->json('data.reference'));

        // A later price change does not touch what was agreed.
        $this->plan('starter')->update(['price_tzs' => 999999]);
        $this->getJson('/api/v1/public/subscriptions/'.$tzs->json('data.public_id'))
            ->assertOk()->assertJsonPath('data.amount', 380000);

        $this->assertTrue(AuditLog::where('action', 'subscription_request.created')->exists());
    }

    public function test_a_request_is_validated(): void
    {
        $this->postJson('/api/v1/public/subscriptions', $this->requestPayload([
            'currency' => 'EUR', 'email' => 'not-an-email', 'phone' => 'call me',
        ]))->assertStatus(422)->assertJsonValidationErrors(['currency', 'email', 'phone']);

        $archived = $this->plan('starter');
        $archived->forceFill(['archived_at' => now()])->save();
        $this->postJson('/api/v1/public/subscriptions', $this->requestPayload())
            ->assertStatus(422)->assertJsonValidationErrors(['plan_id']);
    }

    public function test_a_custom_quote_plan_is_quote_requested_and_cannot_be_paid(): void
    {
        $response = $this->postJson('/api/v1/public/subscriptions', $this->requestPayload([
            'plan_id' => $this->plan('enterprise')->id,
            'currency' => 'USD',
            'message' => 'Four transmission projects and a lender reporting pack.',
        ]))->assertCreated();

        $response->assertJsonPath('data.status', 'quote_requested')->assertJsonPath('data.amount', null);

        $this->postJson('/api/v1/public/subscriptions/'.$response->json('data.public_id').'/pay', [
            'method' => 'card',
        ])->assertStatus(422)->assertJsonPath('error', 'quote_only');

        $this->assertSame(0, Payment::count());
    }

    // --------------------------------------------------------------- phones

    public function test_phone_numbers_are_normalised(): void
    {
        foreach (['0712345678', '0612 345 678', '+255 712 345 678', '255712345678', '712345678', '+255-612-345-678'] as $input) {
            $this->assertMatchesRegularExpression('/^255[67]\d{8}$/', (string) PhoneNumber::normaliseTanzanian($input), $input);
        }

        foreach (['0812345678', '12345', '+254712345678', '07123456789', ''] as $input) {
            $this->assertNull(PhoneNumber::normaliseTanzanian($input), $input);
        }
    }

    public function test_mobile_money_validates_and_normalises_the_phone(): void
    {
        $request = $this->createRequest();
        $url = '/api/v1/public/subscriptions/'.$request->public_id.'/pay';

        $this->postJson($url, ['method' => 'mobile_money'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->postJson($url, ['method' => 'mobile_money', 'phone' => '0812 345 678'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->postJson($url, ['method' => 'bank'])->assertStatus(422)->assertJsonValidationErrors(['method']);

        $this->postJson($url, ['method' => 'mobile_money', 'phone' => '+255 612-345-678'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '255612345678')
            ->assertJsonPath('data.method', 'mobile_money')
            ->assertJsonPath('data.status', 'pending');
    }

    // --------------------------------------------------------------- sandbox

    public function test_sandbox_mobile_money_succeeds_by_itself_and_marks_the_request_paid(): void
    {
        $request = $this->createRequest();

        $payment = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', [
            'method' => 'mobile_money', 'phone' => '0712345678',
        ])->assertCreated();

        $payment->assertJsonPath('data.amount', 380000)->assertJsonPath('data.currency', 'TZS')->assertJsonPath('data.checkout_url', null);
        $this->assertArrayNotHasKey('raw', $payment->json('data'));
        $id = $payment->json('data.public_id');

        // Still waiting for the person to approve on the phone.
        $this->getJson("/api/v1/public/payments/{$id}")->assertOk()->assertJsonPath('data.status', 'pending');

        $this->travel(7)->seconds();

        $this->getJson("/api/v1/public/payments/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.failure_reason', null);
        $this->assertNotNull($this->getJson("/api/v1/public/payments/{$id}")->json('data.paid_at'));

        $this->getJson('/api/v1/public/subscriptions/'.$request->public_id)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.latest_payment.status', 'succeeded');

        $this->assertTrue(AuditLog::where('action', 'payment.status_changed')->where('entity_reference', $id)->exists());
        $this->assertTrue(AuditLog::where('action', 'subscription_request.status_changed')->where('entity_reference', $request->reference)->exists());

        // Paid is paid.
        $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'card'])
            ->assertStatus(422)->assertJsonPath('error', 'already_paid');
    }

    public function test_polling_the_subscription_also_completes_the_sandbox_payment(): void
    {
        $request = $this->createRequest();
        $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'mobile_money', 'phone' => '0712345678'])->assertCreated();

        $this->travel(7)->seconds();

        $this->getJson('/api/v1/public/subscriptions/'.$request->public_id)
            ->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_a_usd_request_is_charged_the_tzs_price(): void
    {
        $request = $this->createRequest(['currency' => 'USD']);

        $this->getJson('/api/v1/public/subscriptions/'.$request->public_id)
            ->assertJsonPath('data.amount', 149)
            ->assertJsonPath('data.charge_options.mobile_money.currency', 'TZS')
            ->assertJsonPath('data.charge_options.mobile_money.amount', 380000)
            ->assertJsonPath('data.charge_options.card.currency', 'TZS');

        $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'mobile_money', 'phone' => '0712345678'])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'TZS')
            ->assertJsonPath('data.amount', 380000);
    }

    public function test_sandbox_card_gives_a_checkout_url_and_the_sandbox_page_completes_it(): void
    {
        $request = $this->createRequest();

        $response = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'card'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'card')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.phone', null);
        $id = $response->json('data.public_id');

        $this->assertSame("https://app.sasa.test/subscribe/sandbox-card?payment={$id}", $response->json('data.checkout_url'));

        // A card payment does not complete by itself.
        $this->travel(30)->seconds();
        $this->getJson("/api/v1/public/payments/{$id}")->assertJsonPath('data.status', 'pending');

        $this->postJson("/api/v1/public/payments/{$id}/sandbox-complete", ['outcome' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors(['outcome']);

        $this->postJson("/api/v1/public/payments/{$id}/sandbox-complete", ['outcome' => 'succeeded'])
            ->assertOk()->assertJsonPath('data.status', 'succeeded');

        $this->assertSame('paid', $request->fresh()->status);

        $this->postJson("/api/v1/public/payments/{$id}/sandbox-complete", ['outcome' => 'failed'])
            ->assertStatus(422)->assertJsonPath('error', 'payment_finished');
    }

    public function test_a_declined_sandbox_card_leaves_the_request_payable(): void
    {
        $request = $this->createRequest();
        $id = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'card'])->json('data.public_id');

        $this->postJson("/api/v1/public/payments/{$id}/sandbox-complete", ['outcome' => 'failed'])
            ->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertNotNull(Payment::where('public_id', $id)->value('failure_reason'));
        $this->assertSame('pending_payment', $request->fresh()->status);

        // Try again; the new attempt replaces nothing (the old one is finished).
        $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'card'])->assertCreated();
    }

    public function test_a_new_attempt_closes_the_pending_one(): void
    {
        $request = $this->createRequest();
        $first = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'mobile_money', 'phone' => '0712345678'])->json('data.public_id');
        $second = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'card'])->json('data.public_id');

        $this->assertSame('cancelled', Payment::where('public_id', $first)->value('status'));
        $this->getJson('/api/v1/public/subscriptions/'.$request->public_id)->assertJsonPath('data.latest_payment.public_id', $second);
    }

    public function test_sandbox_complete_does_not_exist_when_the_driver_is_snippe(): void
    {
        $request = $this->createRequest();
        $id = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'card'])->json('data.public_id');

        $this->useSnippe();

        $this->postJson("/api/v1/public/payments/{$id}/sandbox-complete", ['outcome' => 'succeeded'])->assertNotFound();
        $this->assertSame('pending', Payment::where('public_id', $id)->value('status'));
    }

    public function test_unknown_public_ids_are_not_found(): void
    {
        $this->getJson('/api/v1/public/subscriptions/01JUNKJUNKJUNKJUNKJUNKJUNK')->assertNotFound();
        $this->getJson('/api/v1/public/payments/01JUNKJUNKJUNKJUNKJUNKJUNK')->assertNotFound();
        $request = $this->createRequest();
        // The numeric id is not an address.
        $this->getJson('/api/v1/public/subscriptions/'.$request->id)->assertNotFound();
    }

    // ---------------------------------------------------------------- snippe

    public function test_snippe_mobile_money_sends_the_documented_request(): void
    {
        $this->useSnippe();
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response([
                'status' => 'success', 'code' => 201,
                'data' => [
                    'reference' => 'pi_9015c155', 'status' => 'pending', 'payment_type' => 'mobile',
                    'amount' => ['value' => 380000, 'currency' => 'TZS'], 'object' => 'payment',
                ],
            ], 201),
        ]);

        $request = $this->createRequest(['currency' => 'USD', 'contact_name' => 'Neema Grace Kweka']);

        $response = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', [
            'method' => 'mobile_money', 'phone' => '0712 345 678',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.currency', 'TZS');

        $payment = Payment::where('public_id', $response->json('data.public_id'))->firstOrFail();
        $this->assertSame('snippe', $payment->provider);
        $this->assertSame('pi_9015c155', $payment->provider_reference);

        Http::assertSent(function (HttpRequest $sent) use ($payment, $request) {
            return $sent->method() === 'POST'
                && $sent->url() === 'https://api.snippe.sh/v1/payments'
                && $sent->hasHeader('Authorization', 'Bearer snp_test_key')
                && $sent->hasHeader('Idempotency-Key', $payment->public_id)
                && strlen($payment->public_id) <= 30
                && $sent['payment_type'] === 'mobile'
                && $sent['details'] === ['amount' => 380000, 'currency' => 'TZS']
                && $sent['phone_number'] === '255712345678'
                && $sent['customer'] === ['firstname' => 'Neema', 'lastname' => 'Grace Kweka', 'email' => 'neema@example.co.tz']
                && $sent['webhook_url'] === 'https://api.sasa.test/api/v1/public/payments/snippe/webhook'
                && $sent['metadata']['payment_id'] === $payment->public_id
                && $sent['metadata']['subscription_reference'] === $request->reference;
        });
    }

    public function test_snippe_card_returns_the_hosted_checkout_url(): void
    {
        $this->useSnippe();
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response([
                'status' => 'success', 'code' => 201,
                'data' => [
                    'reference' => '2e0bcc5f-92ca-44f9-8c1b-4d2966d9921f', 'status' => 'pending', 'payment_type' => 'card',
                    'payment_url' => 'https://tz.selcom.online/paymentgw/checkout/abc/',
                    'amount' => ['value' => 1150000, 'currency' => 'TZS'],
                ],
            ], 201),
        ]);

        $request = $this->createRequest(['plan_id' => $this->plan('professional')->id]);

        $response = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'card'])
            ->assertCreated()
            ->assertJsonPath('data.checkout_url', 'https://tz.selcom.online/paymentgw/checkout/abc/');
        $id = $response->json('data.public_id');

        Http::assertSent(fn (HttpRequest $sent) => $sent['payment_type'] === 'card'
            && $sent['details']['amount'] === 1150000
            && $sent['details']['currency'] === 'TZS'
            && $sent['details']['redirect_url'] === "https://app.sasa.test/subscribe/return?payment={$id}"
            && $sent['details']['cancel_url'] === "https://app.sasa.test/subscribe/return?payment={$id}&outcome=cancelled"
            && $sent['phone_number'] === '255712345678'
            && $sent['customer']['country'] === 'TZ'
            && isset($sent['customer']['address'], $sent['customer']['city'], $sent['customer']['state'], $sent['customer']['postcode']));
    }

    public function test_a_snippe_refusal_is_a_failed_payment_with_a_reason(): void
    {
        $this->useSnippe();
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response([
                'status' => 'error', 'code' => 400, 'error_code' => 'validation_error', 'message' => 'phone_number is invalid',
            ], 400),
        ]);

        $request = $this->createRequest();

        $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'mobile_money', 'phone' => '0712345678'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_reason', 'The payment provider refused the payment: phone_number is invalid.');

        $this->assertSame('pending_payment', $request->fresh()->status);
    }

    public function test_polling_a_snippe_payment_asks_snippe_for_its_status(): void
    {
        $this->useSnippe();
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response(['status' => 'success', 'data' => ['reference' => 'pi_poll', 'status' => 'pending']], 201),
            'api.snippe.sh/v1/payments/pi_poll' => Http::response(['status' => 'success', 'data' => [
                'reference' => 'pi_poll', 'status' => 'completed', 'amount' => ['value' => 380000, 'currency' => 'TZS'],
            ]], 200),
        ]);

        $request = $this->createRequest();
        $id = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'mobile_money', 'phone' => '0712345678'])->json('data.public_id');

        $this->getJson("/api/v1/public/payments/{$id}")->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame('paid', $request->fresh()->status);

        Http::assertSent(fn (HttpRequest $sent) => $sent->method() === 'GET' && $sent->url() === 'https://api.snippe.sh/v1/payments/pi_poll');
    }

    private function snippePendingPayment(): Payment
    {
        $this->useSnippe();
        Http::fake([
            'api.snippe.sh/v1/payments' => Http::response(['status' => 'success', 'data' => ['reference' => 'pi_hook', 'status' => 'pending']], 201),
        ]);

        $request = $this->createRequest();
        $id = $this->postJson('/api/v1/public/subscriptions/'.$request->public_id.'/pay', ['method' => 'mobile_money', 'phone' => '0712345678'])->json('data.public_id');

        return Payment::where('public_id', $id)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function completedEvent(string $reference = 'pi_hook', int $value = 380000, string $id = 'evt_427edf89c5c8c02a2301254e'): array
    {
        return [
            'id' => $id,
            'type' => 'payment.completed',
            'api_version' => '2026-01-25',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'reference' => $reference,
                'external_reference' => 'S20388385575',
                'status' => 'completed',
                'amount' => ['value' => $value, 'currency' => 'TZS'],
                'channel' => ['type' => 'mobile_money', 'provider' => 'airtel'],
                'completed_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function test_a_signed_snippe_webhook_marks_the_payment_and_request_paid_idempotently(): void
    {
        $payment = $this->snippePendingPayment();

        $this->signedWebhook($this->completedEvent())->assertOk()->assertJsonPath('data.result', 'updated');

        $payment->refresh();
        $this->assertSame('succeeded', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('paid', $payment->subscriptionRequest->status);

        $auditCount = AuditLog::where('action', 'payment.status_changed')->count();

        // Snippe may deliver the same event twice.
        $this->signedWebhook($this->completedEvent())->assertOk()->assertJsonPath('data.result', 'duplicate');
        // A different event for a settled payment changes nothing either.
        $this->signedWebhook($this->completedEvent(id: 'evt_other'))->assertOk()->assertJsonPath('data.result', 'unchanged');
        $failed = $this->completedEvent(id: 'evt_failed');
        $failed['type'] = 'payment.failed';
        $failed['data']['status'] = 'failed';
        $this->signedWebhook($failed)->assertOk()->assertJsonPath('data.result', 'unchanged');

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame($auditCount, AuditLog::where('action', 'payment.status_changed')->count());
    }

    public function test_a_failed_snippe_webhook_records_the_reason(): void
    {
        $payment = $this->snippePendingPayment();
        $event = $this->completedEvent();
        $event['type'] = 'payment.failed';
        $event['data']['status'] = 'failed';
        $event['data']['failure_reason'] = 'Insufficient funds';

        $this->signedWebhook($event)->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('Insufficient funds', $payment->fresh()->failure_reason);
        $this->assertSame('pending_payment', $payment->subscriptionRequest->fresh()->status);
    }

    public function test_snippe_webhooks_with_a_bad_signature_or_stale_timestamp_are_refused(): void
    {
        $payment = $this->snippePendingPayment();
        $event = $this->completedEvent();

        $this->signedWebhook($event, secret: 'wrong-secret')->assertStatus(401);
        $this->signedWebhook($event, timestamp: now()->subMinutes(10)->getTimestamp())->assertStatus(401);

        $this->call('POST', '/api/v1/public/payments/snippe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode($event))->assertStatus(401);

        // The signature covers the exact bytes: a tampered body fails.
        $body = json_encode($event);
        $ts = (string) now()->getTimestamp();
        $sig = SnippeWebhook::sign($ts, $body, 'whsec_test');
        $this->call('POST', '/api/v1/public/payments/snippe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
        ], str_replace('380000', '1', $body))->assertStatus(401);

        // No secret configured: nothing is trusted.
        config(['sasa.billing.snippe.webhook_secret' => null]);
        $this->signedWebhook($event)->assertStatus(401);

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_a_snippe_success_for_the_wrong_amount_is_not_accepted(): void
    {
        $payment = $this->snippePendingPayment();

        $this->signedWebhook($this->completedEvent(value: 500))->assertOk()->assertJsonPath('data.result', 'amount_mismatch');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'payment.amount_mismatch')->exists());
    }

    public function test_a_webhook_for_an_unknown_payment_is_acknowledged(): void
    {
        $this->signedWebhook($this->completedEvent('pi_nobody'))->assertOk()->assertJsonPath('data.result', 'ignored_unknown_payment');
    }

    // ----------------------------------------------------------------- admin

    /** @return array<string,mixed> */
    private function planPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'field-team',
            'name' => 'Field team',
            'tagline' => 'For a field team on one project',
            'price_usd' => 99,
            'price_tzs' => 250000,
            'billing_period' => 'monthly',
            'features' => ['Offline field app', '  ', 'Grievance management'],
            'limits' => ['projects' => 1, 'users' => 5],
            'is_featured' => false,
            'is_custom_quote' => false,
            'sort_order' => 5,
        ], $overrides);
    }

    public function test_admin_billing_routes_require_billing_manage(): void
    {
        $this->getJson('/api/v1/admin/plans')->assertUnauthorized();

        // A project administrator runs a project, not the platform.
        $projectAdmin = $this->makeUser('project_admin');
        $this->asUser($projectAdmin)->getJson('/api/v1/admin/plans')->assertForbidden();
        $this->asUser($projectAdmin)->postJson('/api/v1/admin/plans', $this->planPayload())->assertForbidden();
        $this->asUser($projectAdmin)->getJson('/api/v1/admin/subscriptions')->assertForbidden();

        // A fresh request in production starts with an empty tenant context.
        app(TenantContext::class)->setUser(null);
        $admin = $this->makeUser('system_administrator');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/plans')->assertOk();
    }

    public function test_an_administrator_creates_edits_archives_and_restores_plans(): void
    {
        $admin = $this->makeUser('system_administrator');
        $this->actingAs($admin, 'sanctum');

        $created = $this->postJson('/api/v1/admin/plans', $this->planPayload())->assertCreated();
        $created->assertJsonPath('data.code', 'field-team')
            ->assertJsonPath('data.features', ['Offline field app', 'Grievance management'])
            ->assertJsonPath('data.is_archived', false);
        $id = $created->json('data.id');

        $this->postJson('/api/v1/admin/plans', $this->planPayload())->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->postJson('/api/v1/admin/plans', $this->planPayload(['code' => 'no-price', 'price_usd' => null, 'price_tzs' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['price_usd']);

        $this->patchJson("/api/v1/admin/plans/{$id}", ['price_tzs' => 260000, 'is_featured' => true])
            ->assertOk()->assertJsonPath('data.price_tzs', 260000)->assertJsonPath('data.is_featured', true)->assertJsonPath('data.name', 'Field team');

        $this->deleteJson("/api/v1/admin/plans/{$id}")->assertOk()->assertJsonPath('data.is_archived', true);

        // Archived, not deleted.
        $this->assertDatabaseHas('plans', ['id' => $id]);
        $this->assertNotNull(Plan::find($id)->archived_at);
        $this->assertNotContains('field-team', array_column($this->getJson('/api/v1/public/plans')->json('data'), 'code'));
        $this->assertNotContains('field-team', array_column($this->getJson('/api/v1/admin/plans')->json('data'), 'code'));
        $this->assertContains('field-team', array_column($this->getJson('/api/v1/admin/plans?include_archived=1')->json('data'), 'code'));

        $this->postJson("/api/v1/admin/plans/{$id}/restore")->assertOk()->assertJsonPath('data.is_archived', false);
        $this->assertContains('field-team', array_column($this->getJson('/api/v1/public/plans')->json('data'), 'code'));

        foreach (['plan.created', 'plan.updated', 'plan.archived', 'plan.restored'] as $action) {
            $this->assertTrue(
                AuditLog::where('action', $action)->where('entity_type', 'Plan')->where('entity_id', $id)->where('user_id', $admin->id)->exists(),
                "Missing audit {$action}",
            );
        }
    }

    public function test_an_administrator_reviews_subscription_requests(): void
    {
        $paid = $this->createRequest();
        $paymentId = $this->postJson('/api/v1/public/subscriptions/'.$paid->public_id.'/pay', ['method' => 'card'])->json('data.public_id');
        $this->postJson("/api/v1/public/payments/{$paymentId}/sandbox-complete", ['outcome' => 'succeeded'])->assertOk();
        $quote = $this->createRequest(['plan_id' => $this->plan('enterprise')->id]);

        $admin = $this->makeUser('system_administrator');
        $this->actingAs($admin, 'sanctum');

        $list = $this->getJson('/api/v1/admin/subscriptions')->assertOk();
        $this->assertSame([$quote->public_id, $paid->public_id], array_column($list->json('data'), 'public_id'));
        $list->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.payments.0.public_id', $paymentId)
            ->assertJsonPath('data.1.payments.0.provider', 'sandbox')
            ->assertJsonPath('data.1.plan.code', 'starter');
        $this->assertArrayNotHasKey('raw', $list->json('data.1.payments.0'));

        $this->getJson('/api/v1/admin/subscriptions?status=paid')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/subscriptions?status=nonsense')->assertStatus(422);

        $this->getJson('/api/v1/admin/subscriptions/'.$paid->public_id)->assertOk()->assertJsonPath('data.status', 'paid');

        $this->patchJson('/api/v1/admin/subscriptions/'.$paid->public_id, ['status' => 'paid'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->patchJson('/api/v1/admin/subscriptions/'.$paid->public_id, ['status' => 'active', 'admin_notes' => 'Organisation set up on 21 Nov.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.admin_notes', 'Organisation set up on 21 Nov.')
            ->assertJsonPath('data.reviewed_by.id', $admin->id);

        // The public view never carries the administrator's notes.
        $public = $this->getJson('/api/v1/public/subscriptions/'.$paid->public_id)->json('data');
        $this->assertArrayNotHasKey('admin_notes', $public);
        $this->assertArrayNotHasKey('payments', $public);

        $this->assertTrue(AuditLog::where('action', 'subscription_request.status_changed')
            ->where('entity_reference', $paid->reference)->where('user_id', $admin->id)->exists());
    }

    public function test_billing_manage_is_in_the_catalogue_for_the_system_administrator_only(): void
    {
        $this->assertDatabaseHas('permissions', ['key' => 'billing.manage']);

        $roles = Role::whereNull('organisation_id')->get()
            ->filter(fn ($role) => in_array('billing.manage', $role->permissionKeys(), true))
            ->pluck('key')->all();

        $this->assertSame(['system_administrator'], array_values($roles));
    }
}
