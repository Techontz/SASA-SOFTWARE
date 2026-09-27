<?php

namespace App\Domain\Voice;

/**
 * Telephony boundary. SASA is not written around one vendor: a provider
 * normalises whatever the carrier sends into a VoiceCallPayload, and returns
 * the prompts the caller should hear next.
 */
interface VoiceProvider
{
    public function name(): string;

    /** Verify a webhook actually came from the provider. */
    public function verifyWebhook(array $headers, string $rawBody): bool;

    public function normalise(array $request): VoiceCallPayload;

    /** Provider-shaped instruction set for the next turn of the call. */
    public function respond(VoiceInstruction $instruction): array;
}
