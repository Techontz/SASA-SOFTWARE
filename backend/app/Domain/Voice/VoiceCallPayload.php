<?php

namespace App\Domain\Voice;

final class VoiceCallPayload
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $callerNumber = null,
        public readonly ?string $language = null,
        public readonly ?string $event = null,      // started|consent|answer|completed|hangup
        public readonly ?string $speech = null,     // transcribed caller speech for this turn
        public readonly ?bool $consent = null,
        public readonly int $durationSeconds = 0,
        public readonly ?string $recordingUrl = null,
        public readonly array $raw = [],
    ) {}
}
