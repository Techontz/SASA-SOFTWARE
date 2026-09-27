<?php

namespace App\Domain\Voice;

use App\Domain\Ai\AiClassificationService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Configuration\ConfigurationRegistry;
use App\Domain\Grievance\IntakeEngine;
use App\Domain\Notification\NotificationDispatcher;
use App\Domain\Notification\NotificationEvents;
use App\Models\Grievance;
use App\Models\Project;
use App\Models\VoiceCall;
use Illuminate\Support\Facades\DB;

/**
 * The AI voice agent conversation.
 *
 *   Caller → introduction → CONSENT? → questions → concern capture →
 *   transcription → classification → severity → case creation →
 *   acknowledgement → human assignment → human investigation → resolution
 *
 * Consent is mandatory. If consent is refused we log "Consent Not Granted",
 * offer a human call-back, and NEVER create a grievance.
 *
 * BLUEPRINT AMBIGUITY (§11.4): the two-minute cap has no defined behaviour at
 * the limit. Our configurable default is the SOFT cap — warn the caller, offer
 * to continue or arrange a call-back, and flag every truncated call for human
 * review.
 */
final class VoiceAgentService
{
    private const STEPS = ['introduction', 'consent', 'location', 'concern', 'desired_resolution', 'contact', 'closing'];

    public function __construct(
        private readonly VoiceProvider $provider,
        private readonly IntakeEngine $intake,
        private readonly AiClassificationService $ai,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly ConfigurationRegistry $configuration,
    ) {}

    public function settings(Project $project): array
    {
        $stored = $this->configuration->get('voice', $project->organisation_id, $project->id, []);

        return array_merge(config('sasa.voice'), is_array($stored) ? $stored : []);
    }

    /** One turn of the conversation. Returns the provider-shaped response. */
    public function handleTurn(Project $project, VoiceCallPayload $payload): array
    {
        $call = $this->findOrStartCall($project, $payload);
        $settings = $this->settings($project);
        $language = $payload->language ?? $call->language ?? ($settings['languages'][0] ?? 'en');

        if ($payload->event === 'completed' || $payload->event === 'hangup') {
            $this->finish($call, $payload);

            return $this->provider->respond(new VoiceInstruction(
                say: $this->line('closing_hangup', $language),
                language: $language,
                hangUp: true,
            ));
        }

        $turns = $call->turns ?? [];
        $step = $this->currentStep($turns);

        // First contact: introduce the agent and ask for consent. Nothing is
        // recorded and no case exists until the caller agrees.
        if ($turns === [] && $payload->speech === null && $payload->consent === null) {
            return $this->provider->respond(new VoiceInstruction(
                say: trim($this->line('introduction', $language).' '.$this->line('consent', $language)),
                language: $language,
                expectSpeech: true,
                record: false,
                nextStep: 'consent',
                context: ['call_id' => $call->external_id],
            ));
        }

        // Record what the caller just said against the step it answered.
        if ($payload->speech !== null || $payload->consent !== null) {
            $turns[] = [
                'step' => $step,
                'answer' => $payload->speech ?? ($payload->consent ? 'yes' : 'no'),
                'at' => now()->toIso8601String(),
            ];
            $call->turns = $turns;
        }

        if ($payload->durationSeconds) {
            $call->duration_seconds = $payload->durationSeconds;
        }

        // --- Consent gate -----------------------------------------------
        if ($step === 'consent') {
            $granted = $payload->consent ?? $this->readsAsYes($payload->speech);

            if (! $granted) {
                return $this->denyConsent($call, $language);
            }

            $call->consent_granted = true;
            $call->consent_at = now();
            $call->consent_statement = $this->line('consent', $language);
            $call->save();

            $this->audit->record(
                action: 'voice.consent_granted',
                entity: $call,
                summary: 'Caller consented to the call being recorded and a case being opened',
            );
        }

        $call->save();

        // --- Soft cap ----------------------------------------------------
        $capResponse = $this->applyCap($call, $settings, $language, $payload);

        if ($capResponse !== null) {
            return $capResponse;
        }

        $nextStep = $this->currentStep($call->turns ?? []);

        if ($nextStep === 'closing') {
            return $this->completeCall($project, $call, $language);
        }

        return $this->provider->respond(new VoiceInstruction(
            say: $this->line($nextStep, $language),
            language: $language,
            expectSpeech: true,
            record: true,
            nextStep: $nextStep,
            context: ['call_id' => $call->external_id],
        ));
    }

    private function findOrStartCall(Project $project, VoiceCallPayload $payload): VoiceCall
    {
        $call = VoiceCall::query()
            ->acrossTenants()
            ->where('provider', $this->provider->name())
            ->where('external_id', $payload->externalId)
            ->first();

        if ($call) {
            return $call;
        }

        $call = VoiceCall::create([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'provider' => $this->provider->name(),
            'external_id' => $payload->externalId,
            'caller_number' => $payload->callerNumber,
            'language' => $payload->language,
            'status' => 'in_progress',
            'started_at' => now(),
            'turns' => [],
        ]);

        $this->audit->record(
            action: 'voice.call_started',
            entity: $call,
            summary: 'AI voice agent answered a call',
        );

        return $call;
    }

    private function denyConsent(VoiceCall $call, string $language): array
    {
        $call->forceFill([
            'status' => 'consent_denied',
            'consent_granted' => false,
            'callback_requested' => true,
            'needs_human_review' => true,
            'ended_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'voice.consent_not_granted',
            entity: $call,
            summary: 'Consent Not Granted — routed to the human call-back queue. No grievance was created.',
        );

        $this->notifications->dispatch(
            eventKey: NotificationEvents::VOICE_CALL_NEEDS_REVIEW,
            project: $call->project,
            payload: ['url' => "/ai/voice/{$call->id}", 'reason' => 'Consent was not granted'],
        );

        return $this->provider->respond(new VoiceInstruction(
            say: $this->line('consent_denied', $language),
            language: $language,
            hangUp: true,
        ));
    }

    /** Soft cap: warn, offer to continue, flag truncated calls for review. */
    private function applyCap(VoiceCall $call, array $settings, string $language, VoiceCallPayload $payload): ?array
    {
        $cap = (int) ($settings['cap_seconds'] ?? 120);
        $behaviour = $settings['cap_behaviour'] ?? 'soft';
        $elapsed = $call->duration_seconds;

        if ($behaviour === 'structured_only' || $elapsed < (int) ($settings['warn_at_seconds'] ?? $cap - 20)) {
            return null;
        }

        if ($behaviour === 'hard' && $elapsed >= $cap) {
            $call->forceFill([
                'was_truncated' => true,
                'needs_human_review' => true,
                'callback_requested' => true,
                'status' => 'truncated',
            ])->save();

            $this->notifications->dispatch(
                eventKey: NotificationEvents::VOICE_CALL_NEEDS_REVIEW,
                project: $call->project,
                payload: ['url' => "/ai/voice/{$call->id}", 'reason' => 'The call reached the time limit'],
            );

            return $this->provider->respond(new VoiceInstruction(
                say: $this->line('cap_hard', $language),
                language: $language,
                hangUp: true,
            ));
        }

        if ($behaviour === 'soft' && $elapsed >= (int) ($settings['warn_at_seconds'] ?? $cap - 20) && ! $call->extension_offered) {
            $call->forceFill(['extension_offered' => true])->save();

            return $this->provider->respond(new VoiceInstruction(
                say: $this->line('cap_soft', $language),
                language: $language,
                expectSpeech: true,
                nextStep: 'extension',
                context: ['call_id' => $call->external_id],
            ));
        }

        if ($call->extension_offered && ! $call->extension_accepted && $payload->speech !== null) {
            $accepted = $this->readsAsYes($payload->speech);
            $call->forceFill([
                'extension_accepted' => $accepted,
                'callback_requested' => ! $accepted,
                'was_truncated' => ! $accepted,
                'needs_human_review' => ! $accepted,
            ])->save();
        }

        return null;
    }

    private function completeCall(Project $project, VoiceCall $call, string $language): array
    {
        $answers = collect($call->turns ?? [])->pluck('answer', 'step');
        $description = trim((string) $answers->get('concern', ''));

        if ($description === '') {
            $call->forceFill([
                'status' => 'abandoned',
                'needs_human_review' => true,
                'ended_at' => now(),
            ])->save();

            return $this->provider->respond(new VoiceInstruction(
                say: $this->line('nothing_captured', $language),
                language: $language,
                hangUp: true,
            ));
        }

        $grievance = DB::transaction(function () use ($project, $call, $answers, $description, $language) {
            $grievance = $this->intake->intake($project, [
                'channel' => 'voice',
                'channel_reference' => $call->external_id,
                'idempotency_key' => 'voice:'.$call->external_id,
                'confidentiality' => 'normal',
                'complainant_phone' => $call->caller_number,
                'complainant_language' => $language,
                'complainant_type' => 'community_member',
                'location_text' => $answers->get('location'),
                'title' => mb_substr($description, 0, 120),
                'description' => $description,
                'desired_resolution' => $answers->get('desired_resolution'),
                'voice_call_id' => $call->id,
                'received_at' => now(),
            ]);

            $call->forceFill([
                'status' => $call->was_truncated ? 'truncated' : 'completed',
                'grievance_id' => $grievance->id,
                'transcript' => collect($call->turns ?? [])
                    ->map(fn ($turn) => strtoupper($turn['step']).': '.$turn['answer'])
                    ->implode("\n"),
                'ended_at' => now(),
            ])->save();

            return $grievance;
        });

        // AI proposes; a person confirms.
        $this->ai->suggestFor($grievance);

        if ($call->was_truncated || $call->needs_human_review) {
            $this->notifications->dispatch(
                eventKey: NotificationEvents::VOICE_CALL_NEEDS_REVIEW,
                project: $project,
                payload: ['url' => "/grievances/{$grievance->id}", 'reason' => 'The call was cut short'],
            );
        }

        $this->audit->record(
            action: 'voice.case_created',
            entity: $grievance,
            after: ['call_id' => $call->external_id, 'truncated' => $call->was_truncated],
            summary: "Case {$grievance->reference} created from an AI voice call",
        );

        return $this->provider->respond(new VoiceInstruction(
            say: str_replace(
                ['{reference}', '{days}'],
                [$this->spellReference($grievance->reference), (string) ($this->acknowledgementDays($grievance))],
                $this->line('case_created', $language)
            ),
            language: $language,
            hangUp: true,
            context: ['grievance_reference' => $grievance->reference],
        ));
    }

    private function finish(VoiceCall $call, VoiceCallPayload $payload): void
    {
        if ($call->status === 'in_progress') {
            $call->forceFill([
                'status' => $call->grievance_id ? 'completed' : 'abandoned',
                'needs_human_review' => $call->grievance_id === null,
                'duration_seconds' => $payload->durationSeconds ?: $call->duration_seconds,
                'audio_path' => $payload->recordingUrl ?? $call->audio_path,
                'ended_at' => now(),
            ])->save();
        }
    }

    private function currentStep(array $turns): string
    {
        $answered = collect($turns)->pluck('step')->unique()->all();

        foreach (self::STEPS as $step) {
            if ($step === 'introduction') {
                continue;
            }

            if (! in_array($step, $answered, true)) {
                return $step;
            }
        }

        return 'closing';
    }

    /**
     * Consent must be unambiguous. Negatives are checked FIRST, because
     * "I do not agree" contains "agree" — reading that as consent would be
     * the worst possible bug in this file.
     */
    private function readsAsYes(?string $speech): bool
    {
        if ($speech === null) {
            return false;
        }

        $normalised = ' '.mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $speech))).' ';

        foreach (['no', 'not', 'dont', 'do not', 'never', 'refuse', 'decline', 'hapana', 'sitaki', 'siwezi'] as $negative) {
            if (str_contains($normalised, " {$negative} ")) {
                return false;
            }
        }

        foreach (['yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'agree', 'continue', 'ndiyo', 'ndio', 'sawa', 'naam', 'nakubali'] as $affirmative) {
            if (str_contains($normalised, " {$affirmative} ")) {
                return true;
            }
        }

        return false;
    }

    private function acknowledgementDays(Grievance $grievance): int
    {
        return (int) ($grievance->slaClocks()->where('clock', 'acknowledgement')->value('target_value') ?? 7);
    }

    /** Spoken back digit by digit so the caller can write it down. */
    private function spellReference(string $reference): string
    {
        return implode(' ', str_split(str_replace('-', ' dash ', $reference)));
    }

    private function line(string $key, string $language): string
    {
        $scripts = [
            'en' => [
                'introduction' => 'Hello. You have reached the project grievance line. I am an automated assistant and I will help you record your concern.',
                'consent' => 'Before we start, may I record this call and open a case on your behalf? Please say yes or no.',
                'consent_denied' => 'That is completely fine. I will not record anything and no case will be opened. A member of the project team will call you back instead. Thank you.',
                'location' => 'Thank you. Which village or area are you calling about?',
                'concern' => 'Please tell me what happened, in your own words. Take your time.',
                'desired_resolution' => 'Thank you. What would you like the project to do about this?',
                'contact' => 'Would you like the project to contact you on this number?',
                'cap_soft' => 'We are close to the end of the usual call time. Would you like to keep going, or should someone call you back?',
                'cap_hard' => 'We have reached the end of the call time. I have saved what you told me and someone will call you back. Thank you.',
                'case_created' => 'Thank you. Your case number is {reference}. Someone from the project will contact you within {days} working days.',
                'nothing_captured' => 'I am sorry, I did not manage to record your concern. Someone from the project will call you back. Thank you.',
                'closing_hangup' => 'Thank you for calling.',
            ],
            'sw' => [
                'introduction' => 'Habari. Umefika kwenye simu ya malalamiko ya mradi. Mimi ni msaidizi wa kiotomatiki na nitakusaidia kurekodi jambo lako.',
                'consent' => 'Kabla hatujaanza, naomba ruhusa kurekodi simu hii na kufungua kesi kwa niaba yako. Tafadhali sema ndiyo au hapana.',
                'consent_denied' => 'Hakuna shida. Sitarekodi chochote na hakuna kesi itakayofunguliwa. Mtu kutoka kwenye mradi atakupigia simu. Asante.',
                'location' => 'Asante. Unapiga simu kuhusu kijiji au eneo gani?',
                'concern' => 'Tafadhali niambie kilichotokea, kwa maneno yako mwenyewe. Chukua muda wako.',
                'desired_resolution' => 'Asante. Ungependa mradi ufanye nini kuhusu jambo hili?',
                'contact' => 'Ungependa mradi ukupigie simu kwa namba hii?',
                'cap_soft' => 'Tunakaribia mwisho wa muda wa simu. Ungependa kuendelea, au mtu akupigie simu baadaye?',
                'cap_hard' => 'Tumefika mwisho wa muda wa simu. Nimehifadhi uliyoniambia na mtu atakupigia simu. Asante.',
                'case_created' => 'Asante. Namba ya kesi yako ni {reference}. Mtu kutoka kwenye mradi atawasiliana nawe ndani ya siku {days} za kazi.',
                'nothing_captured' => 'Samahani, sikuweza kurekodi jambo lako. Mtu kutoka kwenye mradi atakupigia simu. Asante.',
                'closing_hangup' => 'Asante kwa kupiga simu.',
            ],
        ];

        return $scripts[$language][$key] ?? $scripts['en'][$key] ?? '';
    }
}
