<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Grievance;
use App\Models\Organisation;
use App\Models\Pap;
use App\Models\Pap\PapConsultation;
use App\Models\Pap\PapPayment;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PAP Master Register: profile, child sections, derived values, the
 * automatic sync into the stakeholder register, sensitive fields, payment
 * permission, KPIs and tenant isolation.
 */
class PapRegisterTest extends TestCase
{
    use RefreshDatabase;

    private const NIDA = '19850412-41105-00001-27';

    private User $officer;   // community relations officer: pap.view + pap.manage

    private User $admin;     // project admin: also pap.view_sensitive + pap.manage_payments

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->officer = $this->makeUser('community_relations_officer');
        $this->admin = $this->makeUser('project_admin');
    }

    private function createPap(array $overrides = [], ?User $as = null): Pap
    {
        $response = $this->asUser($as ?? $this->officer)->postJson('/api/v1/paps', array_merge([
            'full_name' => 'Neema Charles Mwita',
            'national_id' => self::NIDA,
            'gender' => 'female',
            'date_of_birth' => '1985-04-12',
            'marital_status' => 'widowed',
            'phone' => '+255 754 123 456',
            'alternative_contact' => '+255 713 000 111',
            'location_id' => $this->village->id,
            'kitongoji' => 'Mwembeni',
            'physical_address' => 'Plot 14, behind the primary school',
            'latitude' => -2.4912,
            'longitude' => 32.9431,
            'next_of_kin' => 'Juma Mwita',
            'next_of_kin_contact' => '+255 765 222 333',
            'vulnerability_category' => 'female_headed_household',
        ], $overrides));

        $response->assertCreated();

        return Pap::findOrFail($response->json('data.id'));
    }

    // ------------------------------------------------------------- profile

    public function test_a_pap_is_created_with_a_reference_household_and_derived_location(): void
    {
        $pap = $this->createPap();

        $this->assertMatchesRegularExpression('/^PAP-\d{4}$/', $pap->reference);
        $this->assertMatchesRegularExpression('/^HH-\d{4}$/', $pap->household_id);
        $this->assertSame('Nyakato', $pap->village);
        $this->assertSame('Buswelu', $pap->ward);
        $this->assertSame('Ilemela', $pap->district);
        $this->assertSame('Mwanza', $pap->region);

        $response = $this->asUser($this->officer)->getJson("/api/v1/paps/{$pap->id}")->assertOk();
        $response->assertJsonPath('data.age', $pap->date_of_birth->diffInYears(today()) | 0);
        $response->assertJsonPath('data.age_is_derived', true);
        $response->assertJsonPath('data.vulnerability_label', 'Female headed household');
        $response->assertJsonPath('data.location.kitongoji', 'Mwembeni');
    }

    public function test_a_second_household_member_can_join_an_existing_household(): void
    {
        $first = $this->createPap();
        $second = $this->createPap(['full_name' => 'Juma Mwita', 'household_id' => $first->household_id, 'gender' => 'male']);

        $this->assertSame($first->household_id, $second->household_id);
    }

    public function test_a_pap_can_be_updated_and_listed_with_filters(): void
    {
        $pap = $this->createPap();
        $this->createPap(['full_name' => 'Hamisi Selemani', 'gender' => 'male', 'vulnerability_category' => 'none']);

        $this->asUser($this->officer)->patchJson("/api/v1/paps/{$pap->id}", ['remarks' => 'Plot boundary confirmed with the VEO.'])
            ->assertOk()->assertJsonPath('data.remarks', 'Plot boundary confirmed with the VEO.');

        $this->asUser($this->officer)->getJson('/api/v1/paps?gender=female')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->officer)->getJson('/api/v1/paps?vulnerable=1')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->officer)->getJson('/api/v1/paps?search=Hamisi')->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->officer)->getJson('/api/v1/paps?search=Hamisi')->assertJsonPath('data.0.money.outstanding', 0);
    }

    public function test_archiving_a_pap_does_not_delete_it_and_is_audited(): void
    {
        $pap = $this->createPap();

        $this->asUser($this->officer)->deleteJson("/api/v1/paps/{$pap->id}")->assertOk();

        $pap->refresh();
        $this->assertNotNull($pap->archived_at);
        $this->assertDatabaseHas('paps', ['id' => $pap->id]);
        $this->assertTrue(AuditLog::where('action', 'pap.archived')->where('entity_id', $pap->id)->exists());
        $this->asUser($this->officer)->getJson('/api/v1/paps')->assertJsonCount(0, 'data');

        // An archived PAP is read-only.
        $this->asUser($this->officer)->patchJson("/api/v1/paps/{$pap->id}", ['remarks' => 'x'])->assertForbidden();
    }

    public function test_a_child_record_is_archived_not_deleted(): void
    {
        $pap = $this->createPap();
        $id = $this->asUser($this->officer)->postJson("/api/v1/paps/{$pap->id}/assets", [
            'asset_category' => 'fence', 'length' => 20, 'width' => 1.5,
        ])->assertCreated()->json('data.id');

        $this->asUser($this->officer)->deleteJson("/api/v1/paps/{$pap->id}/assets/{$id}")->assertOk();

        $this->assertDatabaseHas('pap_assets', ['id' => $id, 'status' => 'archived']);
        $this->asUser($this->officer)->getJson("/api/v1/paps/{$pap->id}/assets")->assertJsonCount(0, 'data');
        $this->asUser($this->officer)->getJson("/api/v1/paps/{$pap->id}/assets?include_archived=1")->assertJsonCount(1, 'data');
    }

    // ------------------------------------------------- stakeholder sync

    public function test_creating_a_pap_creates_its_stakeholder_record_automatically(): void
    {
        $pap = $this->createPap();

        $this->assertNotNull($pap->stakeholder_id);
        $stakeholder = Stakeholder::findOrFail($pap->stakeholder_id);

        $this->assertSame('pap', $stakeholder->type);
        $this->assertSame('Neema Charles Mwita', $stakeholder->name);
        $this->assertSame('+255 754 123 456', $stakeholder->phone);
        $this->assertSame('+255 713 000 111', $stakeholder->alternate_phone);
        $this->assertSame($this->village->id, $stakeholder->primary_location_id);
        $this->assertSame('Nyakato', $stakeholder->village);
        $this->assertTrue($stakeholder->is_vulnerable);
        $this->assertSame(['female_headed_household'], $stakeholder->vulnerability_categories);
        $this->assertSame('female', $stakeholder->demographics['gender']);
        $this->assertMatchesRegularExpression('/^STK-\d{4}$/', $stakeholder->reference);
        // Went through the stakeholder service: the priority engine ran.
        $this->assertNotNull($stakeholder->calculated_priority);
        $this->assertDatabaseHas('stakeholder_assessments', ['stakeholder_id' => $stakeholder->id, 'is_current' => true]);
        $this->assertStringContainsString($pap->reference, (string) $stakeholder->notes);

        // The National ID never crosses into the stakeholder register.
        $row = json_encode(DB::table('stakeholders')->where('id', $stakeholder->id)->first());
        $this->assertStringNotContainsString(self::NIDA, $row);

        // And the stakeholder record says where it is managed from.
        $this->asUser($this->officer)->getJson("/api/v1/stakeholders/{$stakeholder->id}")
            ->assertOk()
            ->assertJsonPath('data.managed_by_pap.id', $pap->id)
            ->assertJsonPath('data.managed_by_pap.reference', $pap->reference);
    }

    public function test_updating_a_pap_updates_the_same_stakeholder_record(): void
    {
        $pap = $this->createPap();
        $stakeholderId = $pap->stakeholder_id;

        $this->asUser($this->officer)->patchJson("/api/v1/paps/{$pap->id}", [
            'full_name' => 'Neema C. Mwita',
            'phone' => '+255 700 999 888',
            'vulnerability_category' => 'none',
        ])->assertOk();

        $this->assertSame($stakeholderId, $pap->fresh()->stakeholder_id);
        $this->assertSame(1, Stakeholder::where('type', 'pap')->count());

        $stakeholder = Stakeholder::findOrFail($stakeholderId);
        $this->assertSame('Neema C. Mwita', $stakeholder->name);
        $this->assertSame('+255 700 999 888', $stakeholder->phone);
        $this->assertFalse($stakeholder->is_vulnerable);
        $this->assertSame([], $stakeholder->vulnerability_categories);
        $this->assertTrue(AuditLog::where('action', 'stakeholder.updated')->where('entity_id', $stakeholderId)->exists());
    }

    public function test_archiving_a_pap_archives_its_stakeholder_record(): void
    {
        $pap = $this->createPap();

        $this->asUser($this->officer)->deleteJson("/api/v1/paps/{$pap->id}")->assertOk();

        $stakeholder = Stakeholder::findOrFail($pap->stakeholder_id);
        $this->assertNotNull($stakeholder->archived_at);
        $this->assertDatabaseHas('stakeholders', ['id' => $stakeholder->id]);
    }

    // ---------------------------------------------------- sensitive data

    public function test_sensitive_keys_are_absent_without_pap_view_sensitive(): void
    {
        $pap = $this->createPap();
        $this->asUser($this->admin)->postJson("/api/v1/paps/{$pap->id}/payments", [
            'amount_approved' => 1000000,
            'bank_name' => 'NMB Bank',
            'bank_account_number' => '40110012345',
            'mobile_money_number' => '+255 754 123 456',
        ])->assertCreated();
        $this->asUser($this->admin)->postJson("/api/v1/paps/{$pap->id}/documents", [
            'document_type' => 'nida_card', 'document_number' => self::NIDA,
        ])->assertCreated();

        $profile = $this->asUser($this->officer)->getJson("/api/v1/paps/{$pap->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('national_id', $profile);
        $this->assertTrue($profile['sensitive_withheld']);

        $list = $this->asUser($this->officer)->getJson('/api/v1/paps')->json('data.0');
        $this->assertArrayNotHasKey('national_id', $list);

        $payment = $this->asUser($this->officer)->getJson("/api/v1/paps/{$pap->id}/payments")->json('data.0');
        $this->assertArrayNotHasKey('bank_account_number', $payment);
        $this->assertArrayNotHasKey('mobile_money_number', $payment);
        $this->assertSame('NMB Bank', $payment['bank_name']);

        $document = $this->asUser($this->officer)->getJson("/api/v1/paps/{$pap->id}/documents")->json('data.0');
        $this->assertArrayNotHasKey('document_number', $document);

        $raw = $this->asUser($this->officer)->getJson("/api/v1/paps/{$pap->id}/payments")->getContent();
        $this->assertStringNotContainsString('40110012345', $raw);

        // With the permission, the values are there.
        $this->asUser($this->admin)->getJson("/api/v1/paps/{$pap->id}")->assertJsonPath('data.national_id', self::NIDA);
        $this->asUser($this->admin)->getJson("/api/v1/paps/{$pap->id}/payments")
            ->assertJsonPath('data.0.bank_account_number', '40110012345');
        $this->assertTrue(AuditLog::where('action', 'like', '%sensitive%')->where('entity_id', $pap->id)->exists()
            || AuditLog::where('is_sensitive_view', true)->where('entity_id', $pap->id)->exists());

        // Stored encrypted, and never written into the audit trail.
        $this->assertStringNotContainsString(self::NIDA, (string) DB::table('paps')->where('id', $pap->id)->value('national_id'));
        $this->assertFalse(AuditLog::where('after', 'like', '%40110012345%')->exists());
        $this->assertFalse(AuditLog::where('after', 'like', '%'.self::NIDA.'%')->exists());
    }

    public function test_the_export_strips_sensitive_fields_unless_permitted(): void
    {
        $this->createPap();

        $officerCsv = $this->asUser($this->officer)->get('/api/v1/paps/export?format=csv');
        $officerCsv->assertOk();
        $content = $officerCsv->streamedContent();
        $this->assertStringContainsString('Neema Charles Mwita', $content);
        $this->assertStringNotContainsString(self::NIDA, $content);

        $adminCsv = $this->asUser($this->admin)->get('/api/v1/paps/export?format=csv');
        $this->assertStringContainsString(self::NIDA, $adminCsv->streamedContent());

        $field = $this->makeUser('field_officer');
        $this->asUser($field)->get('/api/v1/paps/export?format=csv')->assertForbidden();
    }

    // ----------------------------------------------------------- payments

    public function test_recording_a_payment_requires_pap_manage_payments(): void
    {
        $pap = $this->createPap();

        $this->asUser($this->officer)->postJson("/api/v1/paps/{$pap->id}/payments", [
            'amount_approved' => 500000,
        ])->assertForbidden();
        $this->assertSame(0, PapPayment::count());

        $id = $this->asUser($this->admin)->postJson("/api/v1/paps/{$pap->id}/payments", [
            'amount_approved' => 500000,
        ])->assertCreated()->json('data.id');

        $this->asUser($this->officer)->patchJson("/api/v1/paps/{$pap->id}/payments/{$id}", ['amount_paid' => 500000])
            ->assertForbidden();
        $this->asUser($this->officer)->deleteJson("/api/v1/paps/{$pap->id}/payments/{$id}")->assertForbidden();

        // The officer can still READ the payment register.
        $this->asUser($this->officer)->getJson("/api/v1/paps/{$pap->id}/payments")->assertOk()->assertJsonCount(1, 'data');
    }

    // ----------------------------------------------------- derived values

    public function test_derived_values_are_computed_server_side_and_never_typed(): void
    {
        $pap = $this->createPap();
        $base = "/api/v1/paps/{$pap->id}";
        $as = $this->asUser($this->admin);

        $as->postJson("$base/land", [
            'plot_number' => 'NYK/114', 'total_plot_size_ha' => 2.5, 'affected_area_ha' => 0.75,
            'ownership_type' => 'customary_occupant', 'percentage_affected' => 99,
        ])->assertCreated()->assertJsonPath('data.percentage_affected', 30);

        $as->postJson("$base/land", ['plot_number' => 'X', 'total_plot_size_ha' => 1, 'affected_area_ha' => 2])
            ->assertStatus(422);

        $as->postJson("$base/crops", [
            'crop_type' => 'Mango', 'number_of_plants' => 12, 'unit_rate' => 45000, 'compensation_amount' => 1,
        ])->assertCreated()->assertJsonPath('data.compensation_amount', 540000);

        $as->postJson("$base/crops", [
            'crop_type' => 'Maize', 'area_cultivated' => 0.5, 'rate_basis' => 'per_hectare', 'unit_rate' => 1200000,
        ])->assertCreated()->assertJsonPath('data.compensation_amount', 600000);

        $as->postJson("$base/assets", ['asset_category' => 'residential_structure', 'length' => 8, 'width' => 6.5])
            ->assertCreated()->assertJsonPath('data.area', 52);

        $as->postJson("$base/valuations", [
            'valuation_date' => '2026-06-01', 'land_value' => 4000000, 'structure_value' => 6500000,
            'crop_value' => 1140000, 'disturbance_allowance' => 320000, 'transport_allowance' => 150000,
            'total_compensation_value' => 1, 'approval_status' => 'approved',
        ])->assertCreated()->assertJsonPath('data.total_compensation_value', 12110000);

        $as->postJson("$base/entitlements", [
            'entitlement_type' => 'moving_assistance', 'quantity' => 3, 'unit_rate' => 50000, 'value' => 1,
        ])->assertCreated()->assertJsonPath('data.value', 150000);

        $payment = $as->postJson("$base/payments", [
            'amount_approved' => 12110000, 'amount_paid' => 6000000, 'outstanding_amount' => 0,
        ])->assertCreated();
        $payment->assertJsonPath('data.outstanding_amount', 6110000);
        $payment->assertJsonPath('data.payment_status', 'partially_paid');

        $id = $payment->json('data.id');
        $as->patchJson("$base/payments/$id", ['amount_paid' => 12110000])
            ->assertOk()
            ->assertJsonPath('data.outstanding_amount', 0)
            ->assertJsonPath('data.payment_status', 'paid');

        $as->patchJson("$base/payments/$id", ['amount_paid' => 99999999])->assertStatus(422);

        $show = $as->getJson($base)->assertOk();
        $show->assertJsonPath('meta.totals.approved_valuation', 12110000);
        $show->assertJsonPath('meta.totals.amount_paid', 12110000);
        $show->assertJsonPath('meta.totals.crop_compensation', 1140000);
        $show->assertJsonPath('meta.counts.land', 1);
    }

    public function test_livelihood_monitoring_visits_are_a_repeatable_list(): void
    {
        $pap = $this->createPap();

        $this->asUser($this->officer)->postJson("/api/v1/paps/{$pap->id}/livelihoods", [
            'livelihood_program' => 'Poultry keeping',
            'income_source_lost' => 'Vegetable garden',
            'date_enrolled' => '2026-03-01',
            'programme_status' => 'active',
            'monitoring_visits' => [
                ['visit_date' => '2026-07-01', 'visited_by' => 'Field officer', 'findings' => 'Flock of 40.'],
                ['visit_date' => '2026-05-01', 'visited_by' => 'Field officer', 'findings' => 'Coop built.'],
                ['visit_date' => '2026-09-01', 'findings' => 'Selling eggs weekly.'],
                ['visit_date' => '2026-10-01', 'findings' => 'A fourth visit.'],
            ],
        ])->assertCreated()
            ->assertJsonCount(4, 'data.monitoring_visits')
            ->assertJsonPath('data.monitoring_visits.0.visit_date', '2026-05-01')
            ->assertJsonPath('data.programme_status_label', 'Active');
    }

    // ----------------------------------------------------------------- KPIs

    public function test_the_kpi_summary_is_correct(): void
    {
        $a = $this->createPap();
        $b = $this->createPap(['full_name' => 'Juma Mwita', 'gender' => 'male', 'household_id' => $a->household_id, 'vulnerability_category' => 'none']);
        $c = $this->createPap(['full_name' => 'Hamisi Selemani', 'gender' => 'male', 'vulnerability_category' => 'elderly_person']);
        $archived = $this->createPap(['full_name' => 'Archived Person', 'gender' => 'female']);
        $as = $this->asUser($this->admin);
        $as->deleteJson("/api/v1/paps/{$archived->id}")->assertOk();

        // Budget: latest approved valuation per PAP; drafts excluded.
        $as->postJson("/api/v1/paps/{$a->id}/valuations", ['valuation_date' => '2026-01-01', 'land_value' => 1000000, 'approval_status' => 'approved']);
        $as->postJson("/api/v1/paps/{$a->id}/valuations", ['valuation_date' => '2026-05-01', 'land_value' => 3000000, 'approval_status' => 'approved']);
        $as->postJson("/api/v1/paps/{$b->id}/valuations", ['valuation_date' => '2026-05-01', 'land_value' => 2000000, 'approval_status' => 'approved']);
        $as->postJson("/api/v1/paps/{$c->id}/valuations", ['valuation_date' => '2026-05-01', 'land_value' => 9000000]);

        $as->postJson("/api/v1/paps/{$a->id}/payments", ['amount_approved' => 3000000, 'amount_paid' => 3000000])->assertCreated();
        $as->postJson("/api/v1/paps/{$b->id}/payments", ['amount_approved' => 2000000, 'amount_paid' => 500000])->assertCreated();

        $as->postJson("/api/v1/paps/{$a->id}/relocations", ['relocation_status' => 'relocated'])->assertCreated();
        $as->postJson("/api/v1/paps/{$b->id}/relocations", ['relocation_status' => 'planned'])->assertCreated();

        $as->postJson("/api/v1/paps/{$a->id}/livelihoods", ['livelihood_program' => 'Poultry', 'programme_status' => 'completed'])->assertCreated();
        $as->postJson("/api/v1/paps/{$b->id}/livelihoods", ['livelihood_program' => 'Tailoring', 'programme_status' => 'active'])->assertCreated();
        $as->postJson("/api/v1/paps/{$c->id}/livelihoods", ['livelihood_program' => 'Beekeeping', 'programme_status' => 'needs_follow_up'])->assertCreated();
        $as->postJson("/api/v1/paps/{$c->id}/livelihoods", ['livelihood_program' => 'Goats', 'programme_status' => 'completed'])->assertCreated();

        $officer = $this->makeUser('grievance_officer');
        foreach ([[$a, true], [$a, false], [$c, true]] as [$pap, $close]) {
            $id = $this->asUser($officer)->postJson('/api/v1/grievances', [
                'channel' => 'in_person', 'confidentiality' => 'normal',
                'complainant_name' => $pap->full_name, 'stakeholder_id' => $pap->stakeholder_id,
                'title' => 'Compensation query', 'description' => 'The complainant asks when the balance of the compensation will be paid.',
                'category_id' => $this->categories['dust']->id, 'severity' => 2,
            ])->assertCreated()->json('data.id');

            if ($close) {
                Grievance::whereKey($id)->update(['status' => 'closed', 'closed_at' => now()]);
            }
        }

        $kpis = collect($this->asUser($this->officer)->getJson('/api/v1/paps/summary')->assertOk()->json('data.kpis'))
            ->keyBy('key')->map(fn ($kpi) => $kpi['value']);

        $this->assertEquals(3, $kpis['total_paps']);
        $this->assertEquals(2, $kpis['households']);
        $this->assertEquals(2, $kpis['male_paps']);
        $this->assertEquals(1, $kpis['female_paps']);
        $this->assertEquals(2, $kpis['vulnerable_paps']);
        $this->assertEquals(5000000, $kpis['compensation_budget']);
        $this->assertEquals(3500000, $kpis['compensation_paid']);
        $this->assertEquals(1500000, $kpis['outstanding_payments']);
        $this->assertEquals(3, $kpis['grievances_total']);
        $this->assertEquals(1, $kpis['grievances_open']);
        $this->assertEquals(2, $kpis['grievances_closed']);
        $this->assertEquals(1, $kpis['paps_relocated']);
        $this->assertEquals(50, $kpis['livelihood_progress']);

        // Part K: the PAP's own grievances come from the grievance module.
        $this->asUser($this->officer)->getJson("/api/v1/paps/{$a->id}/grievances")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.can_view', true);
    }

    // ------------------------------------------------ permissions & tenancy

    public function test_permission_denials(): void
    {
        $pap = $this->createPap();
        $hr = $this->makeUser('hr_officer'); // no pap.view
        $auditor = $this->makeUser('auditor'); // pap.view only

        $this->asUser($hr)->getJson('/api/v1/paps')->assertForbidden();
        $this->asUser($hr)->getJson("/api/v1/paps/{$pap->id}")->assertForbidden();
        $this->asUser($hr)->getJson('/api/v1/paps/summary')->assertForbidden();

        $this->asUser($auditor)->getJson("/api/v1/paps/{$pap->id}")->assertOk();
        $this->asUser($auditor)->postJson('/api/v1/paps', ['full_name' => 'X'])->assertForbidden();
        $this->asUser($auditor)->patchJson("/api/v1/paps/{$pap->id}", ['remarks' => 'x'])->assertForbidden();
        $this->asUser($auditor)->postJson("/api/v1/paps/{$pap->id}/land", ['plot_number' => 'A'])->assertForbidden();
        $this->asUser($auditor)->postJson('/api/v1/pap-consultations', [
            'consultation_date' => '2026-05-01', 'topic_discussed' => 'Valuation disclosure',
        ])->assertForbidden();
    }

    public function test_a_pap_from_another_project_is_not_found(): void
    {
        $pap = $this->createPap();

        $otherProject = Project::create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Other Project', 'code' => 'OTHER-1', 'country' => 'TZ',
            'timezone' => 'Africa/Dar_es_Salaam', 'status' => 'active',
        ]);
        $outsider = $this->makeUser('project_admin', ['general'], $otherProject);

        $this->asUser($outsider, $otherProject)->getJson("/api/v1/paps/{$pap->id}")->assertNotFound();
        $this->asUser($outsider, $otherProject)->getJson("/api/v1/paps/{$pap->id}/land")->assertNotFound();
        $this->asUser($outsider, $otherProject)->postJson("/api/v1/paps/{$pap->id}/payments", ['amount_approved' => 1])->assertNotFound();
        $this->asUser($outsider, $otherProject)->getJson('/api/v1/paps')->assertJsonCount(0, 'data');

        // A child record id from a different PAP is not found either.
        $other = $this->createPap(['full_name' => 'Second Person']);
        $landId = $this->asUser($this->officer)->postJson("/api/v1/paps/{$pap->id}/land", ['plot_number' => 'A1'])->json('data.id');
        $this->asUser($this->officer)->patchJson("/api/v1/paps/{$other->id}/land/{$landId}", ['plot_number' => 'B'])->assertNotFound();
    }

    public function test_project_id_in_the_body_is_ignored(): void
    {
        $other = Project::create([
            'organisation_id' => Organisation::first()->id,
            'name' => 'Other', 'code' => 'OTHER-2', 'country' => 'TZ',
            'timezone' => 'Africa/Dar_es_Salaam', 'status' => 'active',
        ]);

        $pap = $this->createPap(['project_id' => $other->id, 'organisation_id' => 999]);

        $this->assertSame($this->project->id, $pap->project_id);
    }

    // -------------------------------------------------------- consultations

    public function test_consultations_are_recorded_at_project_level(): void
    {
        $response = $this->asUser($this->officer)->postJson('/api/v1/pap-consultations', [
            'consultation_date' => '2026-05-14',
            'location_id' => $this->village->id,
            'meeting_type' => 'valuation_disclosure',
            'topic_discussed' => 'Disclosure of valuation rates for crops and structures.',
            'facilitator' => 'Community relations team',
            'number_male' => 42,
            'number_female' => 37,
            'number_vulnerable' => 9,
            'key_concerns_raised' => 'Rates for mango trees considered too low.',
            'actions_agreed' => 'Valuer to explain the rate basis at the next meeting.',
        ])->assertCreated();

        $response->assertJsonPath('data.village', 'Nyakato');
        $response->assertJsonPath('data.total_attendance', 79);
        $this->assertMatchesRegularExpression('/^CNS-\d{4}$/', $response->json('data.reference'));

        $id = $response->json('data.id');
        $this->asUser($this->officer)->getJson('/api/v1/pap-consultations')
            ->assertOk()->assertJsonPath('meta.summary.male', 42)->assertJsonPath('meta.summary.vulnerable', 9);

        $this->asUser($this->officer)->patchJson("/api/v1/pap-consultations/{$id}", ['number_female' => 40])
            ->assertOk()->assertJsonPath('data.number_female', 40);

        $this->asUser($this->officer)->deleteJson("/api/v1/pap-consultations/{$id}")->assertOk();
        $this->assertNotNull(PapConsultation::findOrFail($id)->archived_at);
    }

    public function test_options_are_served_from_configuration(): void
    {
        $this->asUser($this->officer)->getJson('/api/v1/paps/options')
            ->assertOk()
            ->assertJsonPath('data.currency', 'TZS')
            ->assertJsonFragment(['value' => 'orphan_headed_household', 'label' => 'Orphan headed household'])
            ->assertJsonFragment(['value' => 'customary_occupant', 'label' => 'Customary occupant']);
    }
}
