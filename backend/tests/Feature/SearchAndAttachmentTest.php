<?php

namespace Tests\Feature;

use App\Domain\Engagement\CommitmentService;
use App\Domain\Stakeholder\StakeholderService;
use App\Models\Attachment;
use App\Models\Organisation;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchAndAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_search_returns_results_grouped_by_record_type(): void
    {
        $officer = $this->makeUser('grievance_officer');

        app(StakeholderService::class)->create($this->project, [
            'name' => 'Buswelu Village Council', 'type' => 'community_group',
        ]);
        app(CommitmentService::class)->create($this->project, [
            'commitment_text' => 'Water the Buswelu access road twice daily.',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'web', 'confidentiality' => 'normal',
            'title' => 'Dust on the Buswelu road',
            'description' => 'The Buswelu access road is not being watered.',
        ]);

        $response = $this->asUser($officer)->getJson('/api/v1/search?q=Buswelu');

        $response->assertOk();
        $groups = collect($response->json('data.groups'))->keyBy('key');

        $this->assertTrue($groups->has('stakeholders'));
        $this->assertTrue($groups->has('grievances'));
        $this->assertTrue($groups->has('commitments'));
        $this->assertNotEmpty($groups['stakeholders']['results'][0]['href']);
    }

    public function test_search_by_reference_finds_the_record(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        $stakeholder = app(StakeholderService::class)->create($this->project, ['name' => 'Someone', 'type' => 'individual']);

        $response = $this->asUser($officer)->getJson('/api/v1/search?q='.$stakeholder->reference);

        $this->assertSame($stakeholder->reference, $response->json('data.groups.0.results.0.reference'));
    }

    public function test_a_one_character_query_returns_nothing_rather_than_everything(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $this->asUser($officer)->getJson('/api/v1/search?q=a')->assertStatus(422);
    }

    public function test_search_never_crosses_a_project_boundary(): void
    {
        $otherOrganisation = Organisation::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $otherProject = Project::create([
            'organisation_id' => $otherOrganisation->id, 'name' => 'Other', 'code' => 'OTH', 'status' => 'active',
        ]);

        app(StakeholderService::class)->create($otherProject, ['name' => 'Distinctive Other Name', 'type' => 'individual']);

        $officer = $this->makeUser('grievance_officer');
        $response = $this->asUser($officer)->getJson('/api/v1/search?q=Distinctive');

        $this->assertSame([], $response->json('data.groups'));
    }

    public function test_a_photo_captured_in_the_field_attaches_to_a_record(): void
    {
        Storage::fake('local');

        $officer = $this->makeUser('field_officer');
        $stakeholder = app(StakeholderService::class)->create($this->project, ['name' => 'Someone', 'type' => 'individual']);

        $response = $this->asUser($officer)->post('/api/v1/attachments', [
            'attachable_type' => 'stakeholder',
            'attachable_id' => $stakeholder->id,
            'file' => UploadedFile::fake()->image('consent-form.jpg', 800, 600),
            'kind' => 'consent',
            'caption' => 'Signed consent form.',
        ]);

        $response->assertCreated();
        $this->assertSame('consent', $response->json('data.kind'));
        $this->assertNotEmpty($response->json('data.download_url'));

        // The storage path is never exposed to the client.
        $this->assertArrayNotHasKey('path', $response->json('data'));
        $this->assertStringNotContainsString('projects/', $response->getContent());

        $this->assertDatabaseHas('audit_logs', ['action' => 'attachment.uploaded']);
    }

    public function test_the_same_offline_upload_is_not_stored_twice(): void
    {
        Storage::fake('local');

        $officer = $this->makeUser('field_officer');
        $stakeholder = app(StakeholderService::class)->create($this->project, ['name' => 'Someone', 'type' => 'individual']);
        $clientUuid = (string) Str::uuid();

        $payload = fn () => [
            'attachable_type' => 'stakeholder',
            'attachable_id' => $stakeholder->id,
            'file' => UploadedFile::fake()->image('photo.jpg'),
            'client_uuid' => $clientUuid,
        ];

        $this->asUser($officer)->post('/api/v1/attachments', $payload())->assertCreated();
        $second = $this->asUser($officer)->post('/api/v1/attachments', $payload());

        $second->assertOk();
        $this->assertTrue($second->json('meta.duplicate'));
        $this->assertSame(1, Attachment::count());
    }

    public function test_a_download_needs_a_valid_signature_and_is_audited(): void
    {
        Storage::fake('local');

        $officer = $this->makeUser('field_officer');
        $stakeholder = app(StakeholderService::class)->create($this->project, ['name' => 'Someone', 'type' => 'individual']);

        $url = $this->asUser($officer)->post('/api/v1/attachments', [
            'attachable_type' => 'stakeholder',
            'attachable_id' => $stakeholder->id,
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->json('data.download_url');

        $attachment = Attachment::first();

        // Without the signature, nothing is served.
        $this->get("/api/v1/attachments/{$attachment->id}/download")
            ->assertStatus(403)
            ->assertJsonPath('error', 'expired_link');

        $this->get($url)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'attachment.downloaded']);
    }

    public function test_an_unsupported_file_type_is_refused_with_a_readable_reason(): void
    {
        Storage::fake('local');

        $officer = $this->makeUser('field_officer');
        $stakeholder = app(StakeholderService::class)->create($this->project, ['name' => 'Someone', 'type' => 'individual']);

        $response = $this->asUser($officer)->post('/api/v1/attachments', [
            'attachable_type' => 'stakeholder',
            'attachable_id' => $stakeholder->id,
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be attached', $response->json('message'));
    }

    public function test_deleting_a_file_archives_it_rather_than_destroying_the_trail(): void
    {
        Storage::fake('local');

        $officer = $this->makeUser('community_relations_officer');
        $stakeholder = app(StakeholderService::class)->create($this->project, ['name' => 'Someone', 'type' => 'individual']);

        $this->asUser($officer)->post('/api/v1/attachments', [
            'attachable_type' => 'stakeholder',
            'attachable_id' => $stakeholder->id,
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $attachment = Attachment::first();
        $this->asUser($officer)->deleteJson("/api/v1/attachments/{$attachment->id}")->assertOk();

        $this->assertNotNull($attachment->fresh()->archived_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attachment.archived']);
    }

    public function test_saved_views_can_be_created_and_shared_with_a_role(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->postJson('/api/v1/saved-views', [
            'entity' => 'grievances',
            'name' => 'My open Level 4–5',
            'filters' => ['status' => 'open', 'severity_min' => 4, 'assignee' => 'me'],
            'visibility' => 'project',
            'is_pinned' => true,
        ]);

        $response->assertCreated();

        $other = $this->makeUser('hse_officer');
        $visible = $this->asUser($other)->getJson('/api/v1/saved-views?entity=grievances');

        $this->assertCount(1, $visible->json('data'));
        $this->assertSame('My open Level 4–5', $visible->json('data.0.name'));
    }

    public function test_a_private_saved_view_stays_private(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $this->asUser($officer)->postJson('/api/v1/saved-views', [
            'entity' => 'grievances',
            'name' => 'Just for me',
            'filters' => ['status' => 'open'],
            'visibility' => 'private',
        ])->assertCreated();

        $other = $this->makeUser('hse_officer');
        $this->assertCount(0, $this->asUser($other)->getJson('/api/v1/saved-views')->json('data'));
    }
}
