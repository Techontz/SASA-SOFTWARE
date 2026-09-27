<?php

namespace App\Domain\Ai;

/**
 * The AI boundary.
 *
 * AI may PROPOSE a category, subcategory, severity, summary or routing. It
 * never owns investigation findings, the resolution decision, corrective
 * action approval or closure. Proposals are stored separately from confirmed
 * values with a confidence score, and the case reads "AI-suggested — confirm"
 * until a person accepts or changes it.
 *
 * The application is written against this interface, never against a vendor
 * SDK, so the provider is a configuration choice.
 */
interface AiProvider
{
    public function name(): string;

    /**
     * @param  array{description:string,title?:string,language?:string,channel?:string,categories:array<int,array{id:int,name:string,subcategories?:array}>}  $input
     */
    public function classify(array $input): AiClassification;

    /** Plain-language summary of a long or rambling account. */
    public function summarise(string $text, string $language = 'en'): string;

    /** Best-effort transcription. Returns null when the provider cannot do it. */
    public function transcribe(string $audioPath, ?string $language = null): ?string;

    public function isAvailable(): bool;
}
