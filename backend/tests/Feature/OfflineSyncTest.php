<?php

namespace Tests\Feature;

use App\Domain\Stakeholder\StakeholderService;
use App\Models\Grievance;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACCEPTANCE (blueprint + build brief): given the device is offline, the user
 * creates a record, the record persists, the application reloads, the record
 * remains, the connection returns, the record synchronises, the server stores
 * it, and the local status becomes synced — with no duplicates.
 *
 * These tests cover the server half of that contract: idempotent replay,
 * resumable batches, per-field last-write-wins, and conflicts on protected
 * fields surfaced for a person rather than silently overwritten.
 */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'field-tablet-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function operation(string $entity, array $payload, string $operation = 'create', array $extra = []): array
    {
        return array_merge([
            'operation_uuid' => (string) Str::uuid(),
            'entity' => $entity,
            'operation' => $operation,
            'entity_uuid' => (string) Str::uuid(),
            'payload' => $payload,
            'client_created_at' => now()->subHours(3)->toIso8601String(),
            'retry_count' => 0,
        ], $extra);
    }

    private function push(array $operations, $user = null)
    {
        $user ??= $this->makeUser('field_officer');

        return $this->asUser($user)->postJson('/api/v1/sync/push', [
            'device_id' => self::DEVICE,
            'operations' => $operations,
        ]);
    }

    public function test_a_batch_captured_offline_is_applied_and_gets_server_references(): void
    {
        $response = $this->push([
            $this->operation('stakeholder', [
                'name' => 'Household recorded with no signal',
                'type' => 'household',
                'phone' => '+255 755 111 222',
                'influence' => 'low', 'interest' => 'high', 'power' => 'low', 'impact' => 'high',
                'is_vulnerable' => true,
            ]),
            $this->operation('grievance', [
                'channel' => 'in_person',
                'confidentiality' => 'normal',
                'title' => 'Captured in the field',
                'description' => 'Written down on a tablet with no connection and queued until the signal returned.',
            ]),
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.summary.applied'));
        $this->assertMatchesRegularExpression('/^STK-\d{4}$/', $response->json('data.results.0.reference'));
        $this->assertMatchesRegularExpression('/^GRV-\d{4}$/', $response->json('data.results.1.reference'));

        // Capture time and sync time are both kept, so field lag is measurable.
        $stakeholder = Stakeholder::first();
        $this->assertNotNull($stakeholder->captured_at);
        $this->assertNotNull($stakeholder->synced_at);
        $this->assertTrue($stakeholder->captured_at->lessThan($stakeholder->synced_at));
    }

    public function test_replaying_the_same_batch_creates_no_duplicates(): void
    {
        $operations = [
            $this->operation('stakeholder', ['name' => 'Only Once', 'type' => 'individual']),
        ];

        $user = $this->makeUser('field_officer');

        $first = $this->push($operations, $user);
        $second = $this->push($operations, $user);

        $this->assertSame(1, $first->json('data.summary.applied'));
        $this->assertSame(1, $second->json('data.summary.duplicate'));
        $this->assertSame($first->json('data.results.0.server_id'), $second->json('data.results.0.server_id'));
        $this->assertSame(1, Stakeholder::count());
    }

    public function test_a_resent_record_under_a_new_operation_id_is_still_recognised(): void
    {
        $user = $this->makeUser('field_officer');
        $entityUuid = (string) Str::uuid();

        // The device lost the response and retried with a fresh operation id,
        // but the same record identity.
        $this->push([$this->operation('stakeholder', ['name' => 'Same Record', 'type' => 'individual'], 'create', ['entity_uuid' => $entityUuid])], $user);
        $second = $this->push([$this->operation('stakeholder', ['name' => 'Same Record', 'type' => 'individual'], 'create', ['entity_uuid' => $entityUuid])], $user);

        $this->assertSame(1, $second->json('data.summary.duplicate'));
        $this->assertSame(1, Stakeholder::count());
    }

    public function test_one_invalid_record_does_not_block_the_rest_of_the_queue(): void
    {
        $response = $this->push([
            $this->operation('stakeholder', ['name' => 'Valid Record', 'type' => 'individual']),
            $this->operation('stakeholder', ['type' => 'not_a_real_type']), // no name, bad type
            $this->operation('stakeholder', ['name' => 'Another Valid Record', 'type' => 'individual']),
        ]);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.summary.applied'));
        $this->assertSame(1, $response->json('data.summary.rejected'));

        // The rejected operation is kept with its reason, not silently dropped.
        $this->assertDatabaseHas('sync_operations', ['status' => 'rejected']);
        $this->assertNotEmpty($response->json('data.results.1.error'));
    }

    public function test_a_failed_operation_can_be_retried_after_the_problem_is_fixed(): void
    {
        $user = $this->makeUser('field_officer');

        $bad = $this->operation('stakeholder', ['type' => 'individual']); // missing name
        $this->push([$bad], $user);
        $this->assertSame(0, Stakeholder::count());

        // The device corrects the record and retries under a NEW operation id.
        $fixed = $this->operation('stakeholder', ['name' => 'Now Complete', 'type' => 'individual'], 'create', [
            'entity_uuid' => $bad['entity_uuid'],
        ]);

        $retry = $this->push([$fixed], $user);

        $this->assertSame(1, $retry->json('data.summary.applied'));
        $this->assertSame(1, Stakeholder::count());
    }

    public function test_an_offline_edit_wins_per_field_where_the_server_did_not_move(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        $stakeholder = app(StakeholderService::class)->create($this->project, [
            'name' => 'Original Name', 'type' => 'individual', 'phone' => '+255 700 000 001',
        ]);

        $response = $this->push([
            $this->operation('stakeholder', ['phone' => '+255 700 999 999'], 'update', [
                'server_id' => $stakeholder->id,
                'base_updated_at' => $stakeholder->updated_at->toIso8601String(),
                'base_values' => ['phone' => '+255 700 000 001'],
            ]),
        ], $officer);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.summary.applied'));
        $this->assertSame('+255 700 999 999', $stakeholder->fresh()->phone);
    }

    public function test_a_protected_field_that_moved_on_the_server_raises_a_conflict_instead_of_overwriting(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $case = Grievance::findOrFail($this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'in_person',
            'confidentiality' => 'normal',
            'title' => 'A case edited in two places at once',
            'description' => 'The field officer edited it offline while the office also moved it on.',
        ])->json('data.id'));

        $baseUpdatedAt = $case->updated_at->toIso8601String();
        $baseStatus = $case->status;

        // The office resolves it while the device is offline.
        $this->asUser($officer)->postJson("/api/v1/grievances/{$case->id}/resolve", [
            'resolution_summary' => 'Resolved in the office while the device was out of signal.',
        ])->assertOk();

        // The device comes back with its own status change.
        $response = $this->push([
            $this->operation('grievance', ['status' => 'under_investigation', 'title' => 'A better title from the field'], 'update', [
                'server_id' => $case->id,
                'base_updated_at' => $baseUpdatedAt,
                'base_values' => ['status' => $baseStatus, 'title' => $case->title],
            ]),
        ], $officer);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.summary.conflict'));

        $case->refresh();
        // The protected field was NOT silently overwritten...
        $this->assertSame('awaiting_confirmation', $case->status);
        // ...but the unprotected one was accepted.
        $this->assertSame('A better title from the field', $case->title);

        $conflict = SyncConflict::first();
        $this->assertNotNull($conflict);
        $this->assertSame('open', $conflict->status);
        $this->assertArrayHasKey('status', $conflict->conflicting_fields);
        $this->assertSame('under_investigation', $conflict->conflicting_fields['status']['local']);
        $this->assertSame('awaiting_confirmation', $conflict->conflicting_fields['status']['server']);
        $this->assertSame(self::DEVICE, $conflict->device_id);

        $this->assertDatabaseHas('audit_logs', ['action' => 'sync.conflict_raised']);
    }

    public function test_a_person_resolves_a_conflict_by_choosing_a_version(): void
    {
        $this->test_a_protected_field_that_moved_on_the_server_raises_a_conflict_instead_of_overwriting();

        $conflict = SyncConflict::firstOrFail();
        $admin = $this->makeUser('project_admin');

        $this->asUser($admin)->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
            'resolution' => 'keep_server',
        ])->assertOk();

        $conflict->refresh();
        $this->assertSame('resolved', $conflict->status);
        $this->assertSame('keep_server', $conflict->resolution);
        $this->assertSame('awaiting_confirmation', Grievance::first()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sync.conflict_resolved']);
    }

    public function test_choosing_the_local_version_applies_it(): void
    {
        $this->test_a_protected_field_that_moved_on_the_server_raises_a_conflict_instead_of_overwriting();

        $conflict = SyncConflict::firstOrFail();
        $admin = $this->makeUser('project_admin');

        $this->asUser($admin)->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", [
            'resolution' => 'keep_local',
        ])->assertOk();

        $this->assertSame('under_investigation', Grievance::first()->status);
    }

    public function test_a_field_officer_cannot_resolve_conflicts(): void
    {
        $this->test_a_protected_field_that_moved_on_the_server_raises_a_conflict_instead_of_overwriting();

        $conflict = SyncConflict::firstOrFail();
        $field = $this->makeUser('field_officer');

        $this->asUser($field)->postJson("/api/v1/sync/conflicts/{$conflict->id}/resolve", ['resolution' => 'keep_local'])
            ->assertStatus(403);
    }

    public function test_pull_returns_changes_since_a_cursor(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        app(StakeholderService::class)->create($this->project, [
            'name' => 'Created Before The Cursor', 'type' => 'individual',
        ]);

        $cursor = now()->addSecond();

        $response = $this->asUser($officer)->getJson('/api/v1/sync/pull?since='.urlencode($cursor->toIso8601String()));

        $response->assertOk();
        $this->assertSame([], $response->json('data.data.stakeholder'));
        $this->assertNotNull($response->json('data.cursor'));
    }

    public function test_pull_never_sends_confidential_identity_to_a_device(): void
    {
        $officer = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);

        $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'voice',
            'confidentiality' => 'confidential',
            'complainant_name' => 'Should Never Reach A Device',
            'complainant_phone' => '+255 754 123 456',
            'title' => 'A confidential case',
            'description' => 'The complainant asked for their identity to be protected.',
        ])->assertCreated();

        $response = $this->asUser($officer)->getJson('/api/v1/sync/pull?since='.urlencode(now()->subDay()->toIso8601String()));

        $response->assertOk();
        $this->assertStringNotContainsString('Should Never Reach A Device', $response->getContent());
        $this->assertTrue($response->json('data.data.grievance.0.identity_withheld'));
    }

    public function test_bootstrap_gives_a_device_everything_it_needs_to_work_offline(): void
    {
        $field = $this->makeUser('field_officer');

        $response = $this->asUser($field)->getJson('/api/v1/sync/bootstrap');

        $response->assertOk()->assertJsonStructure(['data' => [
            'project', 'server_time', 'categories', 'locations', 'members',
            'severity_levels', 'stakeholder_types', 'channels', 'configuration',
        ]]);

        $this->assertNotEmpty($response->json('data.categories'));
        $this->assertNotEmpty($response->json('data.locations'));
    }

    public function test_the_device_can_read_the_state_of_its_own_queue(): void
    {
        $user = $this->makeUser('field_officer');
        $this->push([$this->operation('stakeholder', ['name' => 'Queued', 'type' => 'individual'])], $user);

        $response = $this->asUser($user)->getJson('/api/v1/sync/status?device_id='.self::DEVICE);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.counts.applied'));
        $this->assertSame(0, $response->json('data.open_conflicts'));
    }

    public function test_a_batch_larger_than_the_configured_maximum_is_refused(): void
    {
        $user = $this->makeUser('field_officer');
        $operations = [];

        for ($i = 0; $i <= config('sasa.sync.max_batch_operations'); $i++) {
            $operations[] = $this->operation('stakeholder', ['name' => "Record {$i}", 'type' => 'individual']);
        }

        $this->asUser($user)->postJson('/api/v1/sync/push', [
            'device_id' => self::DEVICE,
            'operations' => $operations,
        ])->assertStatus(422);
    }

    public function test_a_device_cannot_push_into_another_project(): void
    {
        $otherOrganisation = Organisation::create(['name' => 'Other Org', 'slug' => 'other-org', 'status' => 'active']);
        $otherProject = Project::create([
            'organisation_id' => $otherOrganisation->id, 'name' => 'Other', 'code' => 'OTH', 'status' => 'active',
        ]);

        $user = $this->makeUser('field_officer');

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Sasa-Project', (string) $otherProject->id)
            ->postJson('/api/v1/sync/push', [
                'device_id' => self::DEVICE,
                'operations' => [$this->operation('stakeholder', ['name' => 'Cross tenant', 'type' => 'individual'])],
            ])
            ->assertStatus(404);

        $this->assertSame(0, SyncOperation::count());
    }
}
