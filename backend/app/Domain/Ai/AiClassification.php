<?php

namespace App\Domain\Ai;

/** A proposal, never a decision. */
final class AiClassification
{
    public function __construct(
        public readonly ?int $categoryId = null,
        public readonly ?int $subcategoryId = null,
        public readonly ?int $severity = null,
        public readonly ?string $summary = null,
        public readonly ?string $suggestedRoutingRole = null,
        public readonly float $confidence = 0.0,
        public readonly ?string $language = null,
        public readonly array $raw = [],
        public readonly ?string $rationale = null,
    ) {}

    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'subcategory_id' => $this->subcategoryId,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'suggested_routing_role' => $this->suggestedRoutingRole,
            'language' => $this->language,
            'rationale' => $this->rationale,
        ];
    }

    public function isUsable(): bool
    {
        return $this->confidence >= (float) config('sasa.ai.min_confidence_to_suggest', 0.35);
    }
}
