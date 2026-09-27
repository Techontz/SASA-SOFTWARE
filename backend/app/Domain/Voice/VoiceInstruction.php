<?php

namespace App\Domain\Voice;

/** What the caller should hear next, in provider-neutral terms. */
final class VoiceInstruction
{
    public function __construct(
        public readonly string $say,
        public readonly string $language = 'en',
        public readonly bool $expectSpeech = false,
        public readonly bool $record = false,
        public readonly bool $hangUp = false,
        public readonly ?string $nextStep = null,
        public readonly array $context = [],
    ) {}
}
