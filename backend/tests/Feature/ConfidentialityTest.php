<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Grievance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ACCEPTANCE (blueprint): an unauthorised user must not receive the
 * confidential grievance identity fields.
 *
 * The guarantee is stronger than "hidden": the keys are ABSENT from the API
 * response, so there is nothing in the payload, a proxy log or a cached
 * response to reveal.
 */
class ConfidentialityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function createCase(string $confidentiality, ?int $categoryId = null): Grievance
    {
        $officer = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);

        $id = $this->asUser($officer)->postJson('/api/v1/grievances', array_filter([
            'channel' => 'voice',
            'confidentiality' => $confidentiality,
            'complainant_name' => $confidentiality === 'anonymous' ? null : 'Neema Charles Mwita',
            'complainant_phone' => $confidentiality === 'anonymous' ? null : '+255 754 123 456',
            'title' => 'A case for the confidentiality tests',
            'description' => 'The substance of the complaint, which everyone with case access may read.',
            'category_id' => $categoryId,
            'severity' => 3,
        ]))->json('data.id');

        return Grievance::findOrFail($id);
    }

    public function test_an_authorised_handler_receives_the_complainant_details(): void
    {
        $case = $this->createCase('confidential');
        $officer = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);

        $response = $this->asUser($officer)->getJson("/api/v1/grievances/{$case->id}");

        $response->assertOk();
        $this->assertTrue($response->json('data.identity_visible'));
        $this->assertSame('Neema Charles Mwita', $response->json('data.complainant.name'));
    }

    public function test_an_unauthorised_user_receives_a_case_with_the_identity_keys_absent(): void
    {
        $case = $this->createCase('confidential');
        $field = $this->makeUser('field_officer');

        $response = $this->asUser($field)->getJson("/api/v1/grievances/{$case->id}");

        $response->assertOk();
        $this->assertFalse($response->json('data.identity_visible'));

        // Absent, not blanked.
        $payload = $response->json('data');
        $this->assertArrayNotHasKey('complainant', $payload);
        $this->assertArrayNotHasKey('precise_location', $payload);
        $this->assertArrayNotHasKey('coordinates', $payload);

        // And the name appears nowhere in the raw response body.
        $this->assertStringNotContainsString('Neema Charles Mwita', $response->getContent());

        // The substance of the complaint is still readable — the case is
        // workable, only the identity is withheld.
        $this->assertStringContainsString('The substance of the complaint', $response->json('data.description'));
        $this->assertNotEmpty($response->json('data.identity_withheld_reason'));
    }

    public function test_an_anonymous_case_stores_no_identity_at_all(): void
    {
        $case = $this->createCase('anonymous');

        $this->assertNull($case->complainant_name);
        $this->assertNull($case->complainant_phone);
        $this->assertNull($case->complainant_name_hash);
        $this->assertNull($case->stakeholder_id);

        // Not even to a system administrator — there is nothing to release.
        $admin = $this->makeUser('system_administrator', ['*']);
        $response = $this->asUser($admin)->getJson("/api/v1/grievances/{$case->id}");

        $this->assertFalse($response->json('data.identity_visible'));
        $this->assertStringContainsString('anonymously', $response->json('data.identity_withheld_reason'));
    }

    public function test_identity_submitted_on_an_anonymous_case_is_never_stored(): void
    {
        $officer = $this->makeUser('grievance_officer');

        $response = $this->asUser($officer)->postJson('/api/v1/grievances', [
            'channel' => 'web',
            'confidentiality' => 'anonymous',
            'complainant_name' => 'Should Not Be Kept',
            'complainant_phone' => '+255 700 000 000',
            'title' => 'Anonymous submission',
            'description' => 'The complainant chose to stay anonymous.',
        ]);

        $response->assertCreated();
        $case = Grievance::find($response->json('data.id'));

        $this->assertNull($case->complainant_name);
        $this->assertNull($case->complainant_phone);
    }

    public function test_a_restricted_category_hides_the_case_entirely(): void
    {
        $case = $this->createCase('normal', $this->categories['restricted']->id);

        $this->assertTrue($case->fresh()->is_restricted);
        // A restricted category also forces confidentiality.
        $this->assertSame('confidential', $case->fresh()->confidentiality);

        $field = $this->makeUser('field_officer');

        // Not merely 403 — the case does not exist for this user.
        $this->asUser($field)->getJson("/api/v1/grievances/{$case->id}")->assertStatus(404);

        $list = $this->asUser($field)->getJson('/api/v1/grievances');
        $this->assertCount(0, $list->json('data'));

        // The handling group sees it normally.
        $handler = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);
        $this->asUser($handler)->getJson("/api/v1/grievances/{$case->id}")->assertOk();
    }

    public function test_a_restricted_case_cannot_be_assigned_outside_the_handling_group(): void
    {
        $case = $this->createCase('normal', $this->categories['restricted']->id);
        $handler = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);
        $outsider = $this->makeUser('hse_officer', ['general']);

        $this->asUser($handler)->postJson("/api/v1/grievances/{$case->id}/assign", [
            'assigned_to_id' => $outsider->id,
        ])->assertStatus(403)->assertJsonPath('error', 'assignee_not_in_handling_group');
    }

    public function test_reading_a_confidential_case_writes_a_sensitive_view_audit_event(): void
    {
        $case = $this->createCase('confidential');
        $officer = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);

        AuditLog::query()->where('is_sensitive_view', true)->delete();

        $this->asUser($officer)->getJson("/api/v1/grievances/{$case->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'grievance.sensitive_view',
            'entity_id' => $case->id,
            'user_id' => $officer->id,
            'is_sensitive_view' => true,
        ]);
    }

    public function test_an_unauthorised_read_writes_no_sensitive_view_event(): void
    {
        $case = $this->createCase('confidential');
        $field = $this->makeUser('field_officer');

        AuditLog::query()->where('is_sensitive_view', true)->delete();

        $this->asUser($field)->getJson("/api/v1/grievances/{$case->id}")->assertOk();

        // Nothing was released, so there is nothing to log as released.
        $this->assertSame(0, AuditLog::where('is_sensitive_view', true)->count());
    }

    public function test_search_never_surfaces_a_confidential_complainant_to_an_outsider(): void
    {
        $this->createCase('confidential');
        $field = $this->makeUser('field_officer');

        $response = $this->asUser($field)->getJson('/api/v1/search?q=Neema');

        $response->assertOk();
        $this->assertStringNotContainsString('Neema Charles Mwita', $response->getContent());
    }

    public function test_a_handler_can_find_a_case_by_the_complainant_phone_number(): void
    {
        $this->createCase('confidential');
        $officer = $this->makeUser('grievance_officer', ['general', 'restricted_handling']);

        $response = $this->asUser($officer)->getJson('/api/v1/grievances?search='.urlencode('754123456'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_an_export_strips_identity_for_a_user_outside_the_handling_group(): void
    {
        $this->createCase('confidential');
        $auditor = $this->makeUser('auditor', []);

        $response = $this->asUser($auditor)->get('/api/v1/grievances/export?format=csv');

        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringNotContainsString('Neema Charles Mwita', $body);
        $this->assertStringContainsString('Case ID', $body);
        $this->assertDatabaseHas('export_logs', ['identity_stripped' => true, 'entity' => 'grievances']);
    }

    public function test_identity_cannot_be_edited_by_someone_who_cannot_see_it(): void
    {
        $case = $this->createCase('confidential');
        $field = $this->makeUser('field_officer');

        $this->asUser($field)->patchJson("/api/v1/grievances/{$case->id}", [
            'complainant_name' => 'Changed By Someone Who Should Not See It',
        ])->assertStatus(403);

        $this->assertSame('Neema Charles Mwita', $case->fresh()->complainant_name);
    }

    public function test_complainant_details_are_encrypted_at_rest(): void
    {
        $case = $this->createCase('confidential');

        $raw = DB::table('grievances')->where('id', $case->id)->first();

        $this->assertNotSame('Neema Charles Mwita', $raw->complainant_name);
        $this->assertStringNotContainsString('Neema', (string) $raw->complainant_name);
        // But the application can still read it.
        $this->assertSame('Neema Charles Mwita', $case->complainant_name);
    }
}
