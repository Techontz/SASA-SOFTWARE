<?php

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Messages API implementation of the AI boundary.
 *
 * It returns a PROPOSAL. Nothing here writes to a grievance; the caller stores
 * the result as an AiSuggestion for a person to confirm. When the key is
 * missing or the call fails we fall back to the deterministic provider rather
 * than blocking intake — a concern must never be lost because an API was down.
 */
final class ClaudeAiProvider implements AiProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function name(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return (bool) config('services.anthropic.key', env('ANTHROPIC_API_KEY'));
    }

    public function classify(array $input): AiClassification
    {
        if (! $this->isAvailable()) {
            return (new NullAiProvider)->classify($input);
        }

        $categories = collect($input['categories'] ?? [])
            ->map(fn ($category) => [
                'id' => $category['id'],
                'name' => $category['name'],
                'subcategories' => collect($category['subcategories'] ?? [])
                    ->map(fn ($sub) => ['id' => $sub['id'], 'name' => $sub['name']])->values()->all(),
            ])->values();

        $system = <<<'PROMPT'
        You classify community and worker grievances for an infrastructure project.

        You are a decision-support tool, not a decision maker. Your output is shown
        to a human grievance officer as a suggestion they must confirm.

        Rules:
        - Choose ONLY from the categories provided. Never invent one.
        - Never infer facts that are not in the account. Do not speculate about
          blame, causes or whether the complaint is true.
        - Severity 1 = minor and local; 3 = affects a community or recurs;
          5 = life-threatening, a rights violation, or a reputational crisis.
        - Anything describing sexual harassment or exploitation, retaliation
          against a complainant, child labour or forced labour is at least 5.
        - If the account is too vague to classify, return confidence below 0.3.
        - Reply with JSON only.
        PROMPT;

        $schema = [
            'category_id' => 'integer id from the list, or null',
            'subcategory_id' => 'integer id from the list, or null',
            'severity' => 'integer 1-5',
            'summary' => 'one or two neutral sentences in English',
            'suggested_routing_role' => 'one of: grievance_officer, hr_officer, hse_officer, security_officer, community_relations_officer',
            'confidence' => 'number between 0 and 1',
            'rationale' => 'one short sentence explaining the choice',
        ];

        $payload = [
            'model' => config('sasa.ai.model', 'claude-sonnet-5'),
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => [[
                'role' => 'user',
                'content' => json_encode([
                    'categories' => $categories,
                    'grievance' => [
                        'title' => $input['title'] ?? null,
                        'account' => $input['description'] ?? '',
                        'language' => $input['language'] ?? 'unknown',
                        'channel' => $input['channel'] ?? 'unknown',
                    ],
                    'reply_with_this_shape' => $schema,
                ], JSON_UNESCAPED_UNICODE),
            ]],
        ];

        try {
            $response = Http::timeout((int) config('sasa.ai.timeout_seconds', 30))
                ->withHeaders([
                    'x-api-key' => config('services.anthropic.key', env('ANTHROPIC_API_KEY')),
                    'anthropic-version' => self::API_VERSION,
                    'content-type' => 'application/json',
                ])
                ->post(self::ENDPOINT, $payload);

            if (! $response->successful()) {
                Log::warning('ai.classify_failed', ['status' => $response->status(), 'body' => $response->body()]);

                return (new NullAiProvider)->classify($input);
            }

            $text = collect($response->json('content', []))
                ->where('type', 'text')
                ->pluck('text')
                ->implode('');

            $decoded = $this->decodeJson($text);

            if (! $decoded) {
                return (new NullAiProvider)->classify($input);
            }

            return new AiClassification(
                categoryId: $decoded['category_id'] ?? null,
                subcategoryId: $decoded['subcategory_id'] ?? null,
                severity: isset($decoded['severity']) ? max(1, min(5, (int) $decoded['severity'])) : null,
                summary: $decoded['summary'] ?? null,
                suggestedRoutingRole: $decoded['suggested_routing_role'] ?? null,
                confidence: (float) ($decoded['confidence'] ?? 0),
                language: $input['language'] ?? null,
                raw: ['provider' => 'anthropic', 'model' => $payload['model'], 'response' => $decoded],
                rationale: $decoded['rationale'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::error('ai.classify_exception', ['error' => $e->getMessage()]);

            return (new NullAiProvider)->classify($input);
        }
    }

    public function summarise(string $text, string $language = 'en'): string
    {
        if (! $this->isAvailable() || trim($text) === '') {
            return (new NullAiProvider)->summarise($text, $language);
        }

        try {
            $response = Http::timeout((int) config('sasa.ai.timeout_seconds', 30))
                ->withHeaders([
                    'x-api-key' => config('services.anthropic.key', env('ANTHROPIC_API_KEY')),
                    'anthropic-version' => self::API_VERSION,
                ])
                ->post(self::ENDPOINT, [
                    'model' => config('sasa.ai.model', 'claude-sonnet-5'),
                    'max_tokens' => 400,
                    'system' => 'Summarise the account neutrally in at most two sentences of plain English. Add nothing that is not stated. Reply with the summary only.',
                    'messages' => [['role' => 'user', 'content' => $text]],
                ]);

            $summary = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');

            return trim($summary) ?: (new NullAiProvider)->summarise($text, $language);
        } catch (\Throwable $e) {
            return (new NullAiProvider)->summarise($text, $language);
        }
    }

    public function transcribe(string $audioPath, ?string $language = null): ?string
    {
        // Transcription belongs to the telephony/voice provider, which returns
        // a transcript with the call payload. See App\Domain\Voice.
        return null;
    }

    private function decodeJson(string $text): ?array
    {
        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Tolerate a fenced block or surrounding prose.
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
