<?php

namespace App\Domain\Stakeholder;

use App\Domain\Configuration\ConfigurationRegistry;

/**
 * The stakeholder priority engine.
 *
 *   score = w1·influence + w2·interest + w3·power + w4·impact
 *   High = 3, Medium = 2, Low = 1; weights default to 1; 0 disables a dimension
 *   score >= 10 -> HIGH, 7..9 -> MEDIUM, <= 6 -> LOW
 *
 * BLUEPRINT AMBIGUITY (§7.2): the source five-strategy matrix is internally
 * inconsistent — identical inputs map to different strategies, "impact" is in
 * the register but not the matrix, and High priority is paired with the
 * conventionally low-priority strategy. We do not silently correct it. The
 * calculation is a configurable default and the strategy label hangs off the
 * priority band, so the client can set their intended mapping without a
 * release.
 */
final class PriorityCalculator
{
    public function __construct(private readonly ConfigurationRegistry $configuration) {}

    /**
     * @return array{score:int,priority:string,weights:array,levels:array,strategy:?string,frequency:?string,dimensions:array}
     */
    public function calculate(
        ?string $influence,
        ?string $interest,
        ?string $power,
        ?string $impact,
        ?int $organisationId = null,
        ?int $projectId = null,
    ): array {
        $settings = $this->settings($organisationId, $projectId);
        $weights = $settings['weights'];
        $levels = $settings['levels'];

        $dimensions = [
            'influence' => $influence,
            'interest' => $interest,
            'power' => $power,
            'impact' => $impact,
        ];

        $score = 0;
        $contributions = [];

        foreach ($dimensions as $name => $value) {
            $weight = (int) ($weights[$name] ?? 0);
            $levelValue = (int) ($levels[strtolower((string) $value)] ?? 0);
            $contribution = $weight * $levelValue;
            $score += $contribution;

            $contributions[$name] = [
                'value' => $value,
                'weight' => $weight,
                'points' => $contribution,
                'enabled' => $weight > 0,
            ];
        }

        $priority = $this->band($score, $settings['thresholds']);
        $bandSettings = $settings['bands'][$priority] ?? [];

        return [
            'score' => $score,
            'priority' => $priority,
            'weights' => $weights,
            'levels' => $levels,
            'thresholds' => $settings['thresholds'],
            'strategy' => $bandSettings['strategy'] ?? null,
            'frequency' => $bandSettings['frequency'] ?? null,
            'dimensions' => $contributions,
        ];
    }

    public function band(int $score, array $thresholds): string
    {
        return match (true) {
            $score >= (int) ($thresholds['high'] ?? 10) => 'high',
            $score >= (int) ($thresholds['medium'] ?? 7) => 'medium',
            default => 'low',
        };
    }

    /** The human-readable formula, shown in the UI next to the score. */
    public function explain(array $result): string
    {
        $parts = [];

        foreach ($result['dimensions'] as $name => $dimension) {
            if (! $dimension['enabled']) {
                continue;
            }

            $parts[] = sprintf(
                '%s (%s) x%d = %d',
                ucfirst($name),
                $dimension['value'] ?? 'not set',
                $dimension['weight'],
                $dimension['points']
            );
        }

        $thresholds = $result['thresholds'];

        return sprintf(
            '%s = %d. %d or more is High, %d to %d is Medium, below %d is Low.',
            implode(' + ', $parts) ?: 'No dimensions enabled',
            $result['score'],
            $thresholds['high'],
            $thresholds['medium'],
            $thresholds['high'] - 1,
            $thresholds['medium'],
        );
    }

    public function settings(?int $organisationId, ?int $projectId): array
    {
        $stored = $this->configuration->get('priority', $organisationId, $projectId, []);
        $defaults = config('sasa.priority');

        return [
            'weights' => array_merge($defaults['weights'], $stored['weights'] ?? []),
            'levels' => array_merge($defaults['levels'], $stored['levels'] ?? []),
            'thresholds' => array_merge($defaults['thresholds'], $stored['thresholds'] ?? []),
            'bands' => array_replace_recursive($defaults['bands'], $stored['bands'] ?? []),
        ];
    }
}
