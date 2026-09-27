<?php

namespace Tests\Feature;

use App\Domain\Voice\NullVoiceProvider;
use App\Domain\Voice\VoiceAgentService;
use App\Models\AiSuggestion;
use App\Models\Grievance;
use App\Models\VoiceCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI may propose. It never owns investigation findings, the resolution
 * decision, corrective-action approval or closure. Consent is mandatory.
 */
class AiVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        config(['sasa.voice.webhook_secret' => null]); // local/testing bypass
    }

    private function turn(array $payload): array
    {
        $provider = new NullVoiceProvider;

        return app(VoiceAgentService::class)->handleTurn($this->project, $provider->normalise($payload));
    }

    public function test_an_ai_suggestion_is_stored_separately_from_the_confirmed_value(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'voice',
            'confidentiality' => 'normal',
            'title' => 'Dust and noise from blasting',
            'description' => 'The dust from the blasting covers our houses and the noise wakes the children every morning.',
            'request_ai_suggestion' => true,
        ]);

        $response->assertCreated();
        $case = Grievance::find($response->json('data.id'));

        // The AI proposed something...
        $suggestion = AiSuggestion::where('subject_id', $case->id)->first();
        $this->assertNotNull($suggestion);
        $this->assertSame('pending', $suggestion->status);
        $this->assertGreaterThan(0, $suggestion->confidence);

        // ...but the case is NOT classified until a person says so.
        $this->assertFalse($case->classification_confirmed);
        $this->assertTrue($case->has_ai_suggestions);
    }

    public function test_a_person_accepts_or_rejects_the_suggestion(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $id = $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'voice', 'confidentiality' => 'normal',
            'title' => 'Wages not paid',
            'description' => 'The overtime wages for June were recorded on the site sheet but never paid.',
        ])->json('data.id');

        $suggestion = AiSuggestion::where('subject_id', $id)->firstOrFail();

        $this->asUser($officer)->postJson("/api/v1/ai/suggestions/{$suggestion->id}/review", [
            'decision' => 'accepted',
        ])->assertOk()->assertJsonPath('data.status', 'accepted');

        $suggestion->refresh();
        $this->assertSame($officer->id, $suggestion->reviewed_by);
        $this->assertNotNull($suggestion->reviewed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.suggestion_reviewed']);
    }

    public function test_accuracy_is_measurable_because_proposals_are_kept_apart(): void
    {
        $officer = $this->makeUser('grievance_officer');

        foreach (['Dust everywhere from the haulage road', 'Overtime wages were not paid at all'] as $description) {
            $id = $this->asUser($officer)->postJson('/api/v1/grievances', [
                'channel' => 'web', 'confidentiality' => 'normal',
                'title' => substr($description, 0, 40), 'description' => $description,
            ])->json('data.id');

            $suggestion = AiSuggestion::where('subject_id', $id)->first();

            if ($suggestion) {
                $this->asUser($officer)->postJson("/api/v1/ai/suggestions/{$suggestion->id}/review", ['decision' => 'accepted']);
            }
        }

        $response = $this->asUser($officer)->getJson('/api/v1/ai/accuracy');

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('data.reviewed'));
        $this->assertNotNull($response->json('data.acceptance_rate'));
    }

    public function test_the_ai_status_states_plainly_what_it_does_not_own(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->getJson('/api/v1/ai/status');

        $response->assertOk();
        $this->assertContains('resolution', $response->json('data.never_owns'));
        $this->assertContains('closure', $response->json('data.never_owns'));
    }

    public function test_a_call_that_is_refused_consent_never_creates_a_grievance(): void
    {
        $this->turn(['call_id' => 'call-consent-1', 'from' => '+255754000111', 'event' => 'answer']);
        $response = $this->turn(['call_id' => 'call-consent-1', 'event' => 'answer', 'speech' => 'No, I do not agree.']);

        $this->assertTrue($response['hang_up']);

        $call = VoiceCall::where('external_id', 'call-consent-1')->firstOrFail();
        $this->assertSame('consent_denied', $call->status);
        $this->assertFalse($call->consent_granted);
        $this->assertTrue($call->callback_requested);
        $this->assertNull($call->grievance_id);

        $this->assertSame(0, Grievance::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'voice.consent_not_granted']);
    }

    public function test_a_consented_call_becomes_a_case_with_the_reference_read_back(): void
    {
        $steps = [
            ['speech' => null],                                    // introduction
            ['speech' => 'Ndiyo, nakubali.'],                      // consent
            ['speech' => 'Nyakato village'],                       // location
            ['speech' => 'The dust from the trucks is covering our houses and the children are coughing.'],
            ['speech' => 'Please water the road every day.'],      // desired resolution
            ['speech' => 'Yes, you can call me on this number.'],  // contact
        ];

        $response = null;

        foreach ($steps as $step) {
            $response = $this->turn(array_merge([
                'call_id' => 'call-ok-1',
                'from' => '+255754000222',
                'language' => 'en',
                'event' => 'answer',
            ], $step));
        }

        $call = VoiceCall::where('external_id', 'call-ok-1')->firstOrFail();
        $this->assertTrue($call->consent_granted);
        $this->assertSame('completed', $call->status);
        $this->assertNotNull($call->grievance_id);
        $this->assertNotEmpty($call->transcript);

        $case = Grievance::findOrFail($call->grievance_id);
        $this->assertSame('voice', $case->channel);
        $this->assertStringContainsString('dust', strtolower($case->description));
        $this->assertStringContainsString('water the road', strtolower($case->desired_resolution));

        // The case reference is read back digit by digit so the caller can
        // write it down.
        $this->assertStringContainsString('G R V', $response['say']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'voice.case_created']);
    }

    public function test_a_replayed_webhook_does_not_create_a_second_case(): void
    {
        $run = function (string $callId) {
            foreach ([null, 'Yes', 'Nyakato', 'The road is closed and we cannot reach the shops.', 'Reopen it.', 'Yes'] as $speech) {
                $this->turn(['call_id' => $callId, 'from' => '+255754000333', 'event' => 'answer', 'speech' => $speech]);
            }
        };

        $run('call-idem-1');
        $this->assertSame(1, Grievance::count());

        // The provider retried the whole call.
        $run('call-idem-1');
        $this->assertSame(1, Grievance::count());
    }

    public function test_the_soft_cap_offers_to_continue_rather_than_cutting_the_caller_off(): void
    {
        config(['sasa.voice.cap_behaviour' => 'soft', 'sasa.voice.warn_at_seconds' => 10, 'sasa.voice.cap_seconds' => 20]);

        $this->turn(['call_id' => 'call-cap-1', 'from' => '+255754000444', 'language' => 'en', 'event' => 'answer']);
        $response = $this->turn(['call_id' => 'call-cap-1', 'language' => 'en', 'event' => 'answer', 'speech' => 'Yes', 'duration' => 15]);

        $this->assertFalse($response['hang_up']);
        $this->assertStringContainsString('call you back', $response['say']);

        $call = VoiceCall::where('external_id', 'call-cap-1')->firstOrFail();
        $this->assertTrue($call->extension_offered);
    }

    public function test_a_hard_cap_flags_the_call_for_human_review(): void
    {
        config(['sasa.voice.cap_behaviour' => 'hard', 'sasa.voice.warn_at_seconds' => 5, 'sasa.voice.cap_seconds' => 10]);

        $this->turn(['call_id' => 'call-cap-2', 'from' => '+255754000555', 'event' => 'answer']);
        $response = $this->turn(['call_id' => 'call-cap-2', 'event' => 'answer', 'speech' => 'Yes', 'duration' => 30]);

        $this->assertTrue($response['hang_up']);

        $call = VoiceCall::where('external_id', 'call-cap-2')->firstOrFail();
        $this->assertTrue($call->was_truncated);
        $this->assertTrue($call->needs_human_review);
    }

    public function test_the_webhook_rejects_an_unverified_request_in_production_configuration(): void
    {
        config(['sasa.voice.webhook_secret' => 'a-real-secret']);

        $this->postJson("/api/v1/voice/{$this->project->code}/webhook", ['call_id' => 'x', 'event' => 'answer'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_signature');
    }

    public function test_a_correctly_signed_webhook_is_accepted(): void
    {
        config(['sasa.voice.webhook_secret' => 'a-real-secret']);

        $payload = ['call_id' => 'signed-1', 'from' => '+255754000666', 'event' => 'answer'];
        $body = json_encode($payload);

        $this->call(
            'POST',
            "/api/v1/voice/{$this->project->code}/webhook",
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_SASA_SIGNATURE' => hash_hmac('sha256', $body, 'a-real-secret'),
            ],
            $body
        )->assertOk();

        $this->assertDatabaseHas('voice_calls', ['external_id' => 'signed-1']);
    }

    public function test_calls_needing_review_are_listed_for_a_person(): void
    {
        config(['sasa.voice.cap_behaviour' => 'hard', 'sasa.voice.warn_at_seconds' => 5, 'sasa.voice.cap_seconds' => 10]);
        $this->turn(['call_id' => 'call-review-1', 'from' => '+255754000777', 'event' => 'answer']);
        $this->turn(['call_id' => 'call-review-1', 'event' => 'answer', 'speech' => 'Yes', 'duration' => 30]);

        $officer = $this->makeUser('grievance_officer');
        $response = $this->asUser($officer)->getJson('/api/v1/ai/voice-calls?needs_review=1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }
}
