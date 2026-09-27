<?php

namespace App\Domain\Ai;

/**
 * The default provider: a deterministic keyword classifier that runs with no
 * API key, no network and no cost.
 *
 * It exists so the whole AI pathway — suggestion storage, the
 * "AI-suggested — confirm" state, review and audit — is exercised in
 * development, in tests and in a demo, and so a project that has not bought an
 * AI subscription still gets a useful first guess rather than an empty screen.
 * Its confidence is deliberately modest.
 */
final class NullAiProvider implements AiProvider
{
    /** @var array<string,array{keywords:array<int,string>,severity:int,role:string}> */
    private const SIGNALS = [
        'environmental_health_safety' => [
            'keywords' => ['dust', 'noise', 'pollution', 'spill', 'water', 'blast', 'vibration', 'waste', 'accident', 'injury', 'safety', 'smoke', 'chemical'],
            'severity' => 3, 'role' => 'hse_officer',
        ],
        'land_assets_livelihoods' => [
            'keywords' => ['land', 'compensation', 'crop', 'farm', 'shamba', 'property', 'house', 'relocation', 'resettlement', 'boundary', 'grave', 'livestock'],
            'severity' => 3, 'role' => 'community_relations_officer',
        ],
        'community_relations_engagement' => [
            'keywords' => ['meeting', 'information', 'consultation', 'notice', 'engagement', 'communication', 'promise', 'road', 'access'],
            'severity' => 2, 'role' => 'community_relations_officer',
        ],
        'labor_hr_industrial_relations' => [
            'keywords' => ['wage', 'salary', 'pay', 'contract', 'overtime', 'dismissal', 'union', 'leave', 'working hours', 'ppe', 'supervisor'],
            'severity' => 3, 'role' => 'hr_officer',
        ],
        'human_rights_workplace_conduct' => [
            'keywords' => ['harassment', 'assault', 'abuse', 'sexual', 'discrimination', 'threat', 'intimidation', 'retaliation', 'child labour', 'forced'],
            'severity' => 5, 'role' => 'grievance_officer',
        ],
        'cultural_heritage' => [
            'keywords' => ['sacred', 'shrine', 'burial', 'heritage', 'ritual', 'cultural', 'ancestral'],
            'severity' => 4, 'role' => 'community_relations_officer',
        ],
        'local_employment_economic' => [
            'keywords' => ['job', 'employment', 'hiring', 'recruitment', 'tender', 'supplier', 'local content', 'business'],
            'severity' => 2, 'role' => 'community_relations_officer',
        ],
        'ethics_compliance' => [
            'keywords' => ['bribe', 'corruption', 'fraud', 'theft', 'kickback', 'conflict of interest', 'favouritism'],
            'severity' => 4, 'role' => 'grievance_officer',
        ],
    ];

    private const ESCALATORS = ['death', 'died', 'fire', 'collapse', 'hospital', 'weapon', 'police', 'court', 'strike', 'riot', 'blocked'];

    public function name(): string
    {
        return 'keyword';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function classify(array $input): AiClassification
    {
        $text = mb_strtolower(($input['title'] ?? '').' '.($input['description'] ?? ''));
        $categories = $input['categories'] ?? [];

        $bestKey = null;
        $bestHits = 0;

        foreach (self::SIGNALS as $key => $signal) {
            $hits = 0;

            foreach ($signal['keywords'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    $hits++;
                }
            }

            if ($hits > $bestHits) {
                $bestHits = $hits;
                $bestKey = $key;
            }
        }

        if (! $bestKey) {
            return new AiClassification(
                confidence: 0.0,
                rationale: 'No recognisable keywords — a person should classify this case.',
                raw: ['provider' => 'keyword'],
            );
        }

        $signal = self::SIGNALS[$bestKey];
        $category = collect($categories)->firstWhere('key', $bestKey);

        $severity = $signal['severity'];
        foreach (self::ESCALATORS as $escalator) {
            if (str_contains($text, $escalator)) {
                $severity = min(5, $severity + 1);
                break;
            }
        }

        // Deliberately capped: a keyword match is a hint, not an assessment.
        $confidence = min(0.72, 0.35 + ($bestHits * 0.12));

        return new AiClassification(
            categoryId: $category['id'] ?? null,
            severity: $severity,
            summary: $this->summarise($input['description'] ?? '', $input['language'] ?? 'en'),
            suggestedRoutingRole: $signal['role'],
            confidence: round($confidence, 2),
            language: $input['language'] ?? null,
            raw: ['provider' => 'keyword', 'matched_category' => $bestKey, 'keyword_hits' => $bestHits],
            rationale: "Matched {$bestHits} keyword(s) associated with ".str_replace('_', ' ', $bestKey).'.',
        );
    }

    public function summarise(string $text, string $language = 'en'): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text));

        if ($clean === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $clean) ?: [$clean];
        $summary = implode(' ', array_slice($sentences, 0, 2));

        return mb_strlen($summary) > 300 ? mb_substr($summary, 0, 297).'…' : $summary;
    }

    public function transcribe(string $audioPath, ?string $language = null): ?string
    {
        return null;
    }
}
