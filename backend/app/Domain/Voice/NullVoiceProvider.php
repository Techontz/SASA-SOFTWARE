<?php

namespace App\Domain\Voice;

/**
 * Default provider. It carries the full conversation contract without a
 * telephony account, so the voice workflow — consent, questions, the soft cap,
 * case creation — can be driven end to end from the API and from tests.
 */
final class NullVoiceProvider implements VoiceProvider
{
    public function name(): string
    {
        return 'simulator';
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        $secret = config('sasa.voice.webhook_secret');

        if (! $secret) {
            return app()->environment('local', 'testing');
        }

        $signature = $headers['x-sasa-signature'][0] ?? ($headers['X-Sasa-Signature'][0] ?? null);

        return $signature !== null && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function normalise(array $request): VoiceCallPayload
    {
        return new VoiceCallPayload(
            externalId: (string) ($request['call_id'] ?? uniqid('call_', true)),
            callerNumber: $request['from'] ?? null,
            language: $request['language'] ?? null,
            event: $request['event'] ?? 'answer',
            speech: $request['speech'] ?? null,
            consent: array_key_exists('consent', $request) ? (bool) $request['consent'] : null,
            durationSeconds: (int) ($request['duration'] ?? 0),
            recordingUrl: $request['recording_url'] ?? null,
            raw: $request,
        );
    }

    public function respond(VoiceInstruction $instruction): array
    {
        return [
            'say' => $instruction->say,
            'language' => $instruction->language,
            'expect_speech' => $instruction->expectSpeech,
            'record' => $instruction->record,
            'hang_up' => $instruction->hangUp,
            'next_step' => $instruction->nextStep,
            'context' => $instruction->context,
        ];
    }
}
