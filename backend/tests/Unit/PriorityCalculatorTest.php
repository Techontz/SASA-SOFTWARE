<?php

namespace Tests\Unit;

use App\Domain\Stakeholder\PriorityCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The priority engine. The source matrix was internally inconsistent, so what
 * we guarantee is not a particular mapping — it is that the mapping is
 * configurable, transparent and reproducible.
 */
class PriorityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private PriorityCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(PriorityCalculator::class);
    }

    public function test_all_high_scores_twelve_and_bands_as_high(): void
    {
        $result = $this->calculator->calculate('high', 'high', 'high', 'high');

        $this->assertSame(12, $result['score']);
        $this->assertSame('high', $result['priority']);
    }

    public function test_the_documented_boundaries_hold(): void
    {
        // 10 or more is High.
        $this->assertSame('high', $this->calculator->calculate('high', 'high', 'high', 'low')['priority']); // 3+3+3+1 = 10
        // 7 to 9 is Medium.
        $this->assertSame('medium', $this->calculator->calculate('high', 'medium', 'medium', 'medium')['priority']); // 9
        $this->assertSame('medium', $this->calculator->calculate('medium', 'medium', 'medium', 'low')['priority']); // 7
        // 6 or below is Low.
        $this->assertSame('low', $this->calculator->calculate('medium', 'medium', 'low', 'low')['priority']); // 6
        $this->assertSame('low', $this->calculator->calculate('low', 'low', 'low', 'low')['priority']); // 4
    }

    public function test_a_weight_of_zero_removes_a_dimension_from_the_score(): void
    {
        config(['sasa.priority.weights' => ['influence' => 1, 'interest' => 1, 'power' => 0, 'impact' => 1]]);

        $result = $this->calculator->calculate('high', 'high', 'high', 'high');

        $this->assertSame(9, $result['score']);
        $this->assertFalse($result['dimensions']['power']['enabled']);
        $this->assertSame(0, $result['dimensions']['power']['points']);
    }

    public function test_weights_change_the_outcome_without_a_code_change(): void
    {
        config(['sasa.priority.weights' => ['influence' => 2, 'interest' => 1, 'power' => 1, 'impact' => 1]]);

        $result = $this->calculator->calculate('high', 'low', 'low', 'low');

        $this->assertSame(9, $result['score']); // (3*2) + 1 + 1 + 1
        $this->assertSame('medium', $result['priority']);
    }

    public function test_thresholds_are_configurable(): void
    {
        config(['sasa.priority.thresholds' => ['high' => 6, 'medium' => 4]]);

        $this->assertSame('high', $this->calculator->calculate('medium', 'medium', 'low', 'low')['priority']); // 6
    }

    public function test_a_missing_dimension_simply_scores_nothing(): void
    {
        $result = $this->calculator->calculate('high', null, null, null);

        $this->assertSame(3, $result['score']);
        $this->assertSame('low', $result['priority']);
    }

    public function test_the_explanation_shows_the_working(): void
    {
        $result = $this->calculator->calculate('high', 'medium', 'low', 'medium');
        $explanation = $this->calculator->explain($result);

        $this->assertStringContainsString('Influence (high) x1 = 3', $explanation);
        $this->assertStringContainsString('= 8', $explanation);
        $this->assertStringContainsString('10 or more is High', $explanation);
    }

    public function test_a_band_carries_a_strategy_and_a_frequency(): void
    {
        $result = $this->calculator->calculate('high', 'high', 'high', 'high');

        $this->assertNotEmpty($result['strategy']);
        $this->assertNotEmpty($result['frequency']);
    }
}
