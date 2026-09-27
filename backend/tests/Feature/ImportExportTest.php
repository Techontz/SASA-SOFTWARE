<?php

namespace Tests\Feature;

use App\Domain\Stakeholder\StakeholderService;
use App\Models\ImportJob;
use App\Models\Stakeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sasa-import').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'stakeholders.csv', 'text/csv', null, true);
    }

    public function test_a_template_lists_the_exact_expected_columns(): void
    {
        $admin = $this->makeUser('project_admin');

        $response = $this->asUser($admin)->getJson('/api/v1/imports/template/stakeholders?format=json');

        $response->assertOk();
        $columns = collect($response->json('data.columns'));
        $this->assertTrue($columns->contains(fn ($c) => $c['key'] === 'name' && $c['required']));
        $this->assertTrue($columns->contains(fn ($c) => $c['key'] === 'type' && $c['required']));
    }

    public function test_the_wizard_maps_validates_previews_and_only_then_commits(): void
    {
        $admin = $this->makeUser('project_admin');

        $file = $this->csv(<<<'CSV'
        Name,Type,Phone,Village,Influence,Interest,Power,Impact
        Mwenyekiti Daniel Masanja,traditional_leader,+255754000001,Nyakato,high,high,high,high
        Neema Charles Mwita,individual,+255754000002,Nyakato,medium,high,low,high
        CSV);

        $upload = $this->asUser($admin)->post('/api/v1/imports', ['entity' => 'stakeholders', 'file' => $file]);
        $upload->assertCreated();

        $jobId = $upload->json('data.job.id');
        $this->assertSame(2, $upload->json('data.job.rows_total'));

        // Nothing has been written yet.
        $this->assertSame(0, Stakeholder::count());

        // The wizard has already guessed the mapping from the headers.
        $mapping = $upload->json('data.job.mapping');
        $this->assertSame('Name', $mapping['name']);
        $this->assertSame('Type', $mapping['type']);

        $validated = $this->asUser($admin)->postJson("/api/v1/imports/{$jobId}/validate", ['mapping' => $mapping]);
        $validated->assertOk();
        $this->assertSame(2, $validated->json('data.rows_valid'));
        $this->assertSame(0, $validated->json('data.rows_invalid'));
        $this->assertSame(0, Stakeholder::count());

        $committed = $this->asUser($admin)->postJson("/api/v1/imports/{$jobId}/commit");
        $committed->assertOk();
        $this->assertSame(2, $committed->json('data.rows_committed'));

        $this->assertSame(2, Stakeholder::count());
        $this->assertNotNull(Stakeholder::first()->reference);
        // Imported records still go through the priority engine.
        $this->assertSame('high', Stakeholder::where('name', 'Mwenyekiti Daniel Masanja')->first()->priority);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import.committed']);
    }

    public function test_invalid_rows_are_reported_and_the_valid_ones_still_land(): void
    {
        $admin = $this->makeUser('project_admin');

        $file = $this->csv(<<<'CSV'
        Name,Type
        Valid Person,individual
        ,individual
        Another Valid,not_a_real_type
        Third Valid,household
        CSV);

        $jobId = $this->asUser($admin)->post('/api/v1/imports', ['entity' => 'stakeholders', 'file' => $file])->json('data.job.id');
        $job = ImportJob::find($jobId);

        $validated = $this->asUser($admin)->postJson("/api/v1/imports/{$jobId}/validate", ['mapping' => $job->mapping]);

        $this->assertSame(2, $validated->json('data.rows_valid'));
        $this->assertSame(2, $validated->json('data.rows_invalid'));
        $this->assertNotEmpty($validated->json('data.errors'));
        // The error names the row number the user sees in their spreadsheet.
        $this->assertSame(3, $validated->json('data.errors.0.row'));

        $committed = $this->asUser($admin)->postJson("/api/v1/imports/{$jobId}/commit");
        $this->assertSame(2, $committed->json('data.rows_committed'));
        $this->assertSame(2, Stakeholder::count());
    }

    public function test_a_commit_cannot_run_before_validation(): void
    {
        $admin = $this->makeUser('project_admin');
        $file = $this->csv("Name,Type\nSomeone,individual\n");

        $jobId = $this->asUser($admin)->post('/api/v1/imports', ['entity' => 'stakeholders', 'file' => $file])->json('data.job.id');

        $this->asUser($admin)->postJson("/api/v1/imports/{$jobId}/commit")
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_validated');
    }

    public function test_committing_twice_does_not_import_twice(): void
    {
        $admin = $this->makeUser('project_admin');
        $file = $this->csv("Name,Type\nOnly Once,individual\n");

        $jobId = $this->asUser($admin)->post('/api/v1/imports', ['entity' => 'stakeholders', 'file' => $file])->json('data.job.id');
        $job = ImportJob::find($jobId);

        $this->asUser($admin)->postJson("/api/v1/imports/{$jobId}/validate", ['mapping' => $job->mapping]);
        $this->asUser($admin)->postJson("/api/v1/imports/{$jobId}/commit");
        $this->asUser($admin)->postJson("/api/v1/imports/{$jobId}/commit");

        $this->assertSame(1, Stakeholder::count());
    }

    public function test_a_field_officer_cannot_import(): void
    {
        $field = $this->makeUser('field_officer');
        $file = $this->csv("Name,Type\nSomeone,individual\n");

        $this->asUser($field)->post('/api/v1/imports', ['entity' => 'stakeholders', 'file' => $file])
            ->assertStatus(403);
    }

    public function test_an_export_is_audit_logged_with_its_filters_and_row_count(): void
    {
        $officer = $this->makeUser('community_relations_officer');

        foreach (['One', 'Two', 'Three'] as $name) {
            app(StakeholderService::class)->create($this->project, [
                'name' => $name, 'type' => 'individual', 'influence' => 'high',
            ]);
        }

        $response = $this->asUser($officer)->get('/api/v1/stakeholders/export?format=csv&type=individual');

        $response->assertOk();
        $this->assertStringContainsString('One', $response->streamedContent());

        $this->assertDatabaseHas('export_logs', [
            'entity' => 'stakeholders',
            'format' => 'csv',
            'row_count' => 3,
            'user_id' => $officer->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.generated']);
    }

    public function test_an_export_respects_the_active_filters(): void
    {
        $officer = $this->makeUser('community_relations_officer');

        app(StakeholderService::class)->create($this->project, ['name' => 'A Household', 'type' => 'household']);
        app(StakeholderService::class)->create($this->project, ['name' => 'An Individual', 'type' => 'individual']);

        $response = $this->asUser($officer)->get('/api/v1/stakeholders/export?format=csv&type=household');

        $content = $response->streamedContent();
        $this->assertStringContainsString('A Household', $content);
        $this->assertStringNotContainsString('An Individual', $content);
    }

    public function test_a_role_without_the_export_permission_is_refused(): void
    {
        $field = $this->makeUser('field_officer');

        $this->asUser($field)->get('/api/v1/stakeholders/export?format=csv')->assertStatus(403);
    }
}
