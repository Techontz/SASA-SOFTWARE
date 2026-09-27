<?php

namespace Tests\Feature;

use App\Models\Stakeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACCEPTANCE (blueprint): given an authorised user submits a stakeholder, the
 * system creates a unique stakeholder ID, saves the record, and makes it
 * available to the engagement and grievance modules.
 */
class StakeholderRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Neema Charles Mwita',
            'type' => 'individual',
            'phone' => '+255 754 123 456',
            'primary_location_id' => $this->village->id,
            'influence' => 'medium',
            'interest' => 'high',
            'power' => 'low',
            'impact' => 'high',
            'is_vulnerable' => true,
            'vulnerability_categories' => ['female_headed_household'],
            'consent_status' => 'granted',
            'consent_basis' => 'consent',
        ], $overrides);
    }

    public function test_creating_a_stakeholder_assigns_a_unique_reference_and_scores_priority(): void
    {
        $user = $this->makeUser('community_relations_officer');

        $response = $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload());

        $response->assertCreated();
        $this->assertMatchesRegularExpression('/^STK-\d{4}$/', $response->json('data.reference'));

        // medium(2) + high(3) + low(1) + high(3) = 9 -> Medium
        $this->assertSame(9, $response->json('data.assessment.score'));
        $this->assertSame('medium', $response->json('data.assessment.calculated_priority'));
        $this->assertSame('medium', $response->json('data.assessment.stored_priority'));
        $this->assertFalse($response->json('data.assessment.is_overridden'));

        // The location path is denormalised so list views need no joins.
        $this->assertSame('Nyakato', $response->json('data.location.village'));
        $this->assertSame('Ilemela', $response->json('data.location.district'));
    }

    public function test_references_are_sequential_and_never_repeat(): void
    {
        $user = $this->makeUser('community_relations_officer');

        $first = $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload(['name' => 'One']));
        $second = $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload(['name' => 'Two']));

        $this->assertSame('STK-0001', $first->json('data.reference'));
        $this->assertSame('STK-0002', $second->json('data.reference'));
    }

    public function test_the_reference_cannot_be_changed_afterwards(): void
    {
        $user = $this->makeUser('community_relations_officer');
        $stakeholder = Stakeholder::findOrFail(
            $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload())->json('data.id')
        );

        $stakeholder->reference = 'STK-9999';
        $stakeholder->save();

        $this->assertSame('STK-0001', $stakeholder->fresh()->reference);
    }

    public function test_an_override_records_the_previous_value_the_reason_and_the_person(): void
    {
        $user = $this->makeUser('community_relations_officer');
        $id = $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload())->json('data.id');

        $response = $this->asUser($user)->postJson("/api/v1/stakeholders/{$id}/priority", [
            'priority' => 'high',
            'reason' => 'Chairs the district land committee, which the four scoring dimensions do not capture.',
        ]);

        $response->assertOk();
        $this->assertSame('high', $response->json('data.assessment.stored_priority'));
        // The calculated value is still shown next to the stored one.
        $this->assertSame('medium', $response->json('data.assessment.calculated_priority'));
        $this->assertTrue($response->json('data.assessment.is_overridden'));

        $this->assertDatabaseHas('stakeholder_assessments', [
            'stakeholder_id' => $id,
            'is_override' => true,
            'previous_priority' => 'medium',
            'stored_priority' => 'high',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stakeholder.priority_overridden',
            'entity_id' => $id,
        ]);
    }

    public function test_an_override_without_a_reason_is_refused(): void
    {
        $user = $this->makeUser('community_relations_officer');
        $id = $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload())->json('data.id');

        $this->asUser($user)
            ->postJson("/api/v1/stakeholders/{$id}/priority", ['priority' => 'high', 'reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_a_field_officer_cannot_override_priority(): void
    {
        $officer = $this->makeUser('community_relations_officer');
        $id = $this->asUser($officer)->postJson('/api/v1/stakeholders', $this->payload())->json('data.id');

        $field = $this->makeUser('field_officer');

        $this->asUser($field)
            ->postJson("/api/v1/stakeholders/{$id}/priority", ['priority' => 'high', 'reason' => 'A good enough reason to try.'])
            ->assertStatus(403);
    }

    public function test_an_auditor_can_read_but_not_write(): void
    {
        $auditor = $this->makeUser('auditor', []);

        $this->asUser($auditor)->getJson('/api/v1/stakeholders')->assertOk();
        $this->asUser($auditor)->postJson('/api/v1/stakeholders', $this->payload())->assertStatus(403);
    }

    public function test_duplicate_detection_warns_without_blocking(): void
    {
        $user = $this->makeUser('community_relations_officer');
        $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload());

        $matches = $this->asUser($user)->getJson('/api/v1/stakeholders/duplicates?'.http_build_query([
            'name' => 'Neema Charles Mwita',
            'phone' => '+255 754 123 456',
        ]));

        $matches->assertOk();
        $this->assertCount(1, $matches->json('data'));

        // A warning, not a block: a village really can hold two people with
        // the same name.
        $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload())->assertCreated();
    }

    public function test_archiving_never_deletes_the_record(): void
    {
        $user = $this->makeUser('community_relations_officer');
        $id = $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload())->json('data.id');

        $this->asUser($user)->deleteJson("/api/v1/stakeholders/{$id}")->assertOk();

        $this->assertDatabaseHas('stakeholders', ['id' => $id, 'status' => 'archived']);
        $this->assertNotNull(Stakeholder::withoutGlobalScopes()->find($id)->archived_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stakeholder.archived', 'entity_id' => $id]);
    }

    public function test_merging_keeps_both_records_and_relinks_the_history(): void
    {
        $user = $this->makeUser('community_relations_officer');
        $duplicateId = $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload())->json('data.id');
        $survivorId = $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload(['name' => 'Neema C. Mwita']))->json('data.id');

        $this->asUser($user)->postJson("/api/v1/stakeholders/{$duplicateId}/merge", [
            'survivor_id' => $survivorId,
            'reason' => 'Same person recorded twice during the household survey.',
        ])->assertOk();

        $this->assertDatabaseHas('stakeholders', [
            'id' => $duplicateId,
            'status' => 'merged',
            'merged_into_id' => $survivorId,
        ]);
    }

    public function test_the_list_view_filters_sorts_and_summarises(): void
    {
        $user = $this->makeUser('community_relations_officer');
        $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload(['name' => 'Vulnerable One', 'is_vulnerable' => true]));
        $this->asUser($user)->postJson('/api/v1/stakeholders', $this->payload(['name' => 'Not Vulnerable', 'is_vulnerable' => false, 'phone' => '+255 700 000 000']));

        $response = $this->asUser($user)->getJson('/api/v1/stakeholders?is_vulnerable=1&sort=name');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(2, $response->json('meta.summary.total'));
        $this->assertSame(1, $response->json('meta.summary.vulnerable'));
    }

    public function test_the_priority_model_is_visible_to_the_user(): void
    {
        $user = $this->makeUser('community_relations_officer');

        $this->asUser($user)->getJson('/api/v1/stakeholders/priority-model')
            ->assertOk()
            ->assertJsonStructure(['data' => ['settings' => ['weights', 'thresholds', 'bands'], 'formula', 'note']]);
    }
}
