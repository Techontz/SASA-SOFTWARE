<?php

namespace Tests\Feature;

use App\Domain\Engagement\CommitmentService;
use App\Domain\Stakeholder\StakeholderService;
use App\Models\Grievance;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ACCEPTANCE (blueprint): given records exist, the dashboard KPIs reflect live
 * data; and given filters are selected, the generated report contains data
 * matching those filters.
 */
class DashboardAndReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function makeCases(int $count, array $overrides = []): void
    {
        $officer = $this->makeUser('grievance_officer');

        for ($i = 0; $i < $count; $i++) {
            $this->asUser($officer)->postJson('/api/v1/grievances', array_merge([
                'channel' => 'web',
                'confidentiality' => 'normal',
                'title' => "Case {$i}",
                'description' => "A complaint recorded for the dashboard tests, number {$i}.",
                'category_id' => $this->categories['environment']->id,
                'severity' => 3,
                'location_id' => $this->village->id,
            ], $overrides))->assertCreated();
        }
    }

    public function test_the_executive_dashboard_counts_live_records(): void
    {
        app(StakeholderService::class)->create($this->project, ['name' => 'A stakeholder', 'type' => 'individual']);
        $this->makeCases(3);

        $executive = $this->makeUser('management');
        $response = $this->asUser($executive)->getJson('/api/v1/dashboard/executive');

        $response->assertOk();
        $kpis = collect($response->json('data.kpis'))->keyBy('key');

        $this->assertSame(1, $kpis['total_stakeholders']['value']);
        $this->assertSame(3, $kpis['grievances_received']['value']);
        $this->assertSame(3, $kpis['grievances_open']['value']);
        $this->assertSame(0, $kpis['grievances_closed']['value']);

        // Every KPI carries its definition and a drill-through target.
        $this->assertNotEmpty($kpis['grievances_open']['definition']);
        $this->assertNotEmpty($kpis['grievances_open']['drill']);
    }

    public function test_the_dashboard_moves_when_a_case_is_closed(): void
    {
        $this->makeCases(2);
        $officer = $this->makeUser('grievance_officer');
        $case = Grievance::first();

        $this->asUser($officer)->postJson("/api/v1/grievances/{$case->id}/resolve", [
            'resolution_summary' => 'Resolved and confirmed with the complainant on site.',
        ]);
        $this->asUser($officer)->postJson("/api/v1/grievances/{$case->id}/close");

        $kpis = collect($this->asUser($officer)->getJson('/api/v1/dashboard/executive')->json('data.kpis'))->keyBy('key');

        $this->assertSame(1, $kpis['grievances_open']['value']);
        $this->assertSame(1, $kpis['grievances_closed']['value']);
        $this->assertEqualsWithDelta(50, $kpis['resolution_rate']['value'], 0.01);
    }

    public function test_the_landing_dashboard_asks_a_different_question_per_role(): void
    {
        $this->makeCases(1);

        $executive = $this->makeUser('management');
        $field = $this->makeUser('field_officer');

        $this->assertSame(
            'What is happening on this project?',
            $this->asUser($executive)->getJson('/api/v1/dashboard/landing')->json('data.question')
        );

        $this->assertSame(
            'What do I need to do today?',
            $this->asUser($field)->getJson('/api/v1/dashboard/landing')->json('data.question')
        );
    }

    public function test_critical_actions_surface_what_needs_a_decision(): void
    {
        $this->makeCases(2, ['severity' => 5]);

        $admin = $this->makeUser('project_admin');
        $actions = collect($this->asUser($admin)->getJson('/api/v1/dashboard/executive')->json('data.critical_actions'));

        $this->assertTrue($actions->contains(fn ($action) => $action['kind'] === 'critical_case'));
        $this->assertTrue($actions->contains(fn ($action) => $action['kind'] === 'unassigned'));
    }

    public function test_the_disaggregation_dashboard_suppresses_small_cells(): void
    {
        $officer = $this->makeUser('grievance_officer');

        // Two women and six men — the women's cell falls below the minimum.
        foreach (range(1, 2) as $i) {
            $this->asUser($officer)->postJson('/api/v1/grievances', [
                'channel' => 'web', 'confidentiality' => 'normal',
                'title' => "F{$i}", 'description' => 'A complaint used for the disaggregation test.',
                'category_id' => $this->categories['environment']->id,
                'demographics' => ['gender' => 'female'],
            ]);
        }

        foreach (range(1, 6) as $i) {
            $this->asUser($officer)->postJson('/api/v1/grievances', [
                'channel' => 'web', 'confidentiality' => 'normal',
                'title' => "M{$i}", 'description' => 'A complaint used for the disaggregation test.',
                'category_id' => $this->categories['environment']->id,
                'demographics' => ['gender' => 'male'],
            ]);
        }

        $executive = $this->makeUser('management');
        $response = $this->asUser($executive)->getJson('/api/v1/dashboard/disaggregation?dimension=gender&against=category');

        $response->assertOk();
        $this->assertSame(5, $response->json('data.minimum_cell_size'));
        $this->assertGreaterThan(0, $response->json('data.suppressed_cells'));

        $rows = collect($response->json('data.rows'))->keyBy('dimension_value');
        $this->assertNull($rows['female']['cells']['Environmental, Health & Safety']);
        $this->assertSame(6, $rows['male']['cells']['Environmental, Health & Safety']);
        $this->assertNotEmpty($response->json('data.suppression_note'));
    }

    public function test_the_minimum_cell_size_is_configurable(): void
    {
        config(['sasa.disaggregation.minimum_cell_size' => 1]);

        $officer = $this->makeUser('grievance_officer');
        $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'normal',
            'title' => 'One case', 'description' => 'A single complaint to check the suppression threshold.',
            'category_id' => $this->categories['environment']->id,
            'demographics' => ['gender' => 'female'],
        ]);

        $executive = $this->makeUser('management');
        $response = $this->asUser($executive)->getJson('/api/v1/dashboard/disaggregation?dimension=gender');

        $this->assertSame(0, $response->json('data.suppressed_cells'));
    }

    public function test_a_role_without_the_permission_cannot_open_the_disaggregation_dashboard(): void
    {
        $field = $this->makeUser('field_officer');

        $this->asUser($field)->getJson('/api/v1/dashboard/disaggregation')->assertStatus(403);
    }

    public function test_the_timeliness_dashboard_reports_the_clock_states(): void
    {
        $this->makeCases(2);
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->getJson('/api/v1/dashboard/timeliness');

        $response->assertOk()->assertJsonStructure(['data' => ['kpis', 'clock_states', 'open_clocks', 'paused_clocks', 'by_category', 'by_severity']]);
        $this->assertGreaterThan(0, $response->json('data.open_clocks'));
    }

    public function test_the_engagement_dashboard_reports_commitments(): void
    {
        app(CommitmentService::class)->create($this->project, [
            'commitment_text' => 'A promise that is already overdue.',
            'due_date' => now()->subDays(5)->toDateString(),
            'risk_level' => 'high',
        ]);

        $officer = $this->makeUser('community_relations_officer');
        $response = $this->asUser($officer)->getJson('/api/v1/dashboard/engagement');

        $response->assertOk();
        $kpis = collect($response->json('data.kpis'))->keyBy('key');
        $this->assertSame(1, $kpis['commitments_overdue']['value']);
        $this->assertSame(1, $kpis['commitments_high_risk']['value']);
    }

    public function test_every_metric_has_exactly_one_published_definition(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->getJson('/api/v1/dashboard/definitions');

        $response->assertOk();
        $definitions = collect($response->json('data'));

        $this->assertGreaterThan(20, $definitions->count());
        $this->assertSame($definitions->pluck('key')->unique()->count(), $definitions->count());
        $definitions->each(fn ($definition) => $this->assertNotEmpty($definition['definition']));
    }

    #[DataProvider('formats')]
    public function test_a_report_generates_in_each_format(string $format): void
    {
        $this->makeCases(3);
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->postJson('/api/v1/reports', [
            'template' => 'executive_summary',
            'format' => $format,
        ]);

        $response->assertCreated();
        $this->assertSame('ready', $response->json('data.status'));
        $this->assertGreaterThan(0, $response->json('data.size_bytes'));

        $report = Report::findOrFail($response->json('data.id'));
        $this->assertTrue(Storage::disk(config('filesystems.default'))->exists($report->path));
    }

    public static function formats(): array
    {
        return [['pdf'], ['docx'], ['xlsx'], ['csv']];
    }

    public function test_a_report_is_retained_with_its_parameters_and_its_numbers(): void
    {
        $this->makeCases(4);
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->postJson('/api/v1/reports', [
            'template' => 'executive_summary',
            'format' => 'csv',
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $report = Report::findOrFail($response->json('data.id'));

        $this->assertSame($officer->id, $report->generated_by);
        $this->assertNotNull($report->generated_at);
        $this->assertSame(4, $report->metrics['grievances_received']);
        $this->assertSame(now()->subMonth()->toDateString(), $report->filters['from']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.generated']);
    }

    public function test_a_filtered_report_contains_only_the_filtered_records(): void
    {
        $this->makeCases(2, ['severity' => 5]);
        $this->makeCases(3, ['severity' => 2]);

        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->postJson('/api/v1/reports', [
            'template' => 'grievance_register',
            'format' => 'csv',
            'severity' => 5,
        ]);

        $report = Report::findOrFail($response->json('data.id'));
        $content = Storage::disk(config('filesystems.default'))->get($report->path);

        $this->assertSame(2, substr_count($content, 'Level 5'));
        $this->assertStringNotContainsString('Level 2', $content);
        $this->assertStringContainsString('Severity: 5', $content);
    }

    public function test_a_report_download_is_audited(): void
    {
        $this->makeCases(1);
        $officer = $this->makeUser('grievance_officer');

        $id = $this->asUser($officer)->postJson('/api/v1/reports', [
            'template' => 'executive_summary', 'format' => 'csv',
        ])->json('data.id');

        $this->asUser($officer)->get("/api/v1/reports/{$id}/download")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.downloaded']);
    }

    public function test_the_system_report_definitions_are_available(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $this->asUser($officer)->getJson('/api/v1/reports/templates')
            ->assertOk()
            ->assertJsonCount(7, 'data');
    }
}
