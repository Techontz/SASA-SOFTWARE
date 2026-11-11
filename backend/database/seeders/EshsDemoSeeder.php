<?php

namespace Database\Seeders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Eshs\EshsConfiguration;
use App\Models\Attachment;
use App\Models\EshsAnomaly;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ESHS contractor assurance for the demo project: three contractors and two
 * subcontractors configured, and eighteen anomalies across every category,
 * risk rating and stage — some overdue, some awaiting verification, some
 * closed on evidence — because a demo where everything is green teaches
 * nothing.
 */
class EshsDemoSeeder extends Seeder
{
    public const CONTRACTORS = [
        'Kilimanjaro Power Constructors Ltd',
        'Lake Zone Civil Works Ltd',
        'Sino-Tanzania Grid Engineering Co.',
    ];

    public const SUBCONTRACTORS = [
        'Msalato Haulage & Logistics',
        'Ruvu Security Services',
    ];

    public function run(EshsConfiguration $configuration, AuditLogger $audit): void
    {
        $project = Project::where('code', 'NBT-400KV')->first();

        if (! $project) {
            $this->command?->warn('Demo project NBT-400KV not found — skipping ESHS demo data.');

            return;
        }

        $raiser = User::where('email', 'hse@sasa.test')->first();
        $verifier = User::where('email', 'pm@sasa.test')->first() ?? $raiser;

        if ($raiser) {
            Auth::login($raiser);
        }

        $configuration->updateSettings($project, [
            'contractors' => self::CONTRACTORS,
            'subcontractors' => self::SUBCONTRACTORS,
        ]);

        if (EshsAnomaly::acrossTenants()->where('project_id', $project->id)->exists()) {
            $this->command?->warn('ESHS demo anomalies already present — contractor lists refreshed, anomalies skipped.');

            return;
        }

        $locations = Location::where('organisation_id', $project->organisation_id)
            ->where('level', 'village')->limit(12)->pluck('id')->all();

        [$kpc, $lzc, $stg] = self::CONTRACTORS;
        [$haulage, $security] = self::SUBCONTRACTORS;

        // [days ago raised, contractor, sub, category, subcategory, type, risk, cause group, cause, target offset (days from today), stage, description, action]
        $rows = [
            [62, $kpc, null, 'safety', 'Work at Height', 'Non-Conformance', 'critical', 'People', 'Failure to Follow Procedure', -40, 'closed', 'Two linesmen observed working on tower T-112 without a second lanyard attached during conductor stringing.', 'Stand down the crew, re-brief on 100% tie-off, introduce daily harness inspection log.'],
            [55, $lzc, $haulage, 'environment', 'Dust', 'Inspection Finding', 'medium', 'Environmental', 'Dust Control Failure', -30, 'closed', 'Heavy dust plume from haul trucks on the Shinyanga access road passing Ibadakuli primary school; no watering for two days.', 'Water the access road twice daily during dry-season works and post a 30 km/h limit near the school.'],
            [48, $stg, null, 'environment', 'Spill / Leakage', 'Incident', 'high', 'Resources & Equipment', 'Poor Maintenance', -20, 'closed', 'Hydraulic oil leak from the 50 t crane at the Iguguno substation laydown; approx. 20 L on bare soil.', 'Excavate and dispose of contaminated soil via licensed handler; service crane hoses; place drip trays.'],
            [41, $kpc, $security, 'social', 'Community Complaint', 'Community Grievance', 'medium', 'Social', 'Community Communication Failure', -12, 'closed', 'Residents of Mwamala village complained that security guards blocked a footpath to the water point without notice.', 'Re-open the footpath with a marked pedestrian crossing and brief guards on community access.'],
            [36, $lzc, null, 'safety', 'Excavation / Trenching', 'Inspection Finding', 'critical', 'Planning & Management', 'Inadequate Risk Assessment', -15, 'overdue', 'Tower foundation pit at T-087 deeper than 1.5 m with no shoring or edge protection; children seen near the pit after hours.', 'Install hard barricades and shoring; fence and light the pit after hours; revise excavation method statement.'],
            [33, $stg, null, 'health', 'Heat Stress', 'Observation', 'high', 'Health', 'Heat Stress Management Failure', -10, 'overdue', 'No shaded rest area or drinking water at the Manyoni stringing site; afternoon temperatures above 34 °C.', 'Provide shaded rest shelter, cool drinking water and a work–rest schedule for the hottest hours.'],
            [30, $kpc, $haulage, 'safety', 'Traffic Management', 'Near Miss', 'high', 'Planning & Management', 'Inadequate Planning', -7, 'overdue', 'Low-bed carrying a transformer reversed onto the Dodoma–Singida highway without a banksman or flag person.', 'Appoint trained banksmen for every reversing manoeuvre onto public roads and update the traffic management plan.'],
            [27, $lzc, null, 'environment', 'Waste Management', 'Non-Conformance', 'low', 'People', 'Lack of Awareness', -3, 'overdue', 'General waste and cement bags burned in an open pit at the Ilemela camp.', 'Stop open burning; segregate waste and use the licensed collector; toolbox talk for camp staff.'],
            [22, $stg, null, 'social', 'Labor Rights', 'Worker Grievance', 'high', 'Social', 'Labor Rights / Worker Welfare Failure', 5, 'awaiting', 'Casual workers at the Singida yard report wages paid ten days late for two consecutive months.', 'Pay outstanding wages, put payroll on a fixed date and share the pay calendar with workers.'],
            [19, $kpc, null, 'safety', 'PPE Non-Compliance', 'Inspection Finding', 'medium', 'Resources & Equipment', 'PPE Unavailable or Inadequate', 4, 'awaiting', 'Concrete pour crew at T-140 without gloves or eye protection; PPE store reported out of stock.', 'Restock the PPE store, issue gloves and goggles to the crew, set a minimum stock level.'],
            [16, $lzc, $haulage, 'environment', 'Fuel Storage', 'Audit Finding', 'high', 'Resources & Equipment', 'Inadequate Tools / Equipment', 9, 'awaiting', 'Diesel bowser parked outside the bunded area at the Kahama camp overnight.', 'Park the bowser inside the bund only; add spill kit at refuelling point.'],
            [14, $stg, null, 'health', 'Hygiene & Sanitation', 'Inspection Finding', 'medium', 'Health', 'Drinking Water / Sanitation Failure', 12, 'in_progress', 'Only one latrine for 60 workers at the Itigi stringing camp; handwashing station without soap.', 'Install additional latrines (1 per 25 workers) and keep handwashing stations stocked daily.'],
            [12, $kpc, null, 'social', 'Gender-Based Violence (GBV) Concern', 'Observation', 'critical', 'Social', 'GBV/SEA/SH Control Failure', 7, 'in_progress', 'Workers\' code of conduct not signed by newly mobilised subcontractor staff; no GBV awareness induction recorded.', 'Induct all new staff on the code of conduct and GBV/SEA/SH, with signed acknowledgements on file.'],
            [9, $lzc, null, 'safety', 'Housekeeping', 'Observation', 'low', 'People', 'Lack of Supervision', 14, 'in_progress', 'Rebar off-cuts and nails scattered around the T-091 work front.', 'Daily end-of-shift housekeeping check by the foreman.'],
            [7, $stg, null, 'environment', 'Vegetation Clearing', 'Regulatory Breach', 'high', 'Planning & Management', 'Inadequate Monitoring / Inspection', 21, 'open', 'Clearing extended about 15 m beyond the approved wayleave near the Ngofila forest reserve.', 'Stop clearing beyond pegged limits; re-peg the corridor; prepare a restoration plan for the over-cleared strip.'],
            [5, $kpc, $security, 'social', 'Security Incident', 'Incident', 'medium', 'Social', 'Security Management Failure', 14, 'open', 'Night guard at the Mwanza storage yard detained a trespasser for six hours before handing over to police.', 'Retrain guards on use of force and handover procedure; report to the police liaison within 1 hour.'],
            [3, $lzc, null, 'health', 'Exposure to Dust', 'Observation', 'medium', 'Resources & Equipment', 'PPE Unavailable or Inadequate', 14, 'open', 'Batching plant operators without respiratory protection during cement loading.', 'Issue FFP2 masks and fit-test operators; enclose the cement loading point.'],
            [1, $stg, null, 'safety', 'Lifting Operations', 'Near Miss', 'critical', 'People', 'Inadequate Training / Competency', 7, 'open', 'Tower section swung into the boom during a tandem lift at T-150; rigger had no valid certificate.', 'Suspend tandem lifts until a certified rigger and lift plan are in place.'],
        ];

        foreach ($rows as $i => [$daysAgo, $contractor, $sub, $category, $subcategory, $type, $risk, $group, $cause, $targetOffset, $stage, $description, $action]) {
            $raisedOn = today()->subDays($daysAgo);

            $anomaly = new EshsAnomaly;
            $anomaly->organisation_id = $project->organisation_id;
            $anomaly->project_id = $project->id;
            $anomaly->fill([
                'raised_on' => $raisedOn,
                'location_id' => $locations === [] ? null : $locations[$i % count($locations)],
                'contractor' => $contractor,
                'subcontractor' => $sub,
                'category' => $category,
                'subcategory' => $subcategory,
                'description' => $description,
                'anomaly_type' => $type,
                'risk_rating' => $risk,
                'root_cause_group' => $group,
                'root_cause' => $cause,
                'contractor_action' => $action,
                'action_owner_name' => ['Kisare Lutambi', 'Halima Mrisho', 'Baraka Njau', 'Wei Zhang'][$i % 4],
                'action_owner_email' => ['k.lutambi', 'h.mrisho', 'b.njau', 'w.zhang'][$i % 4].'@contractor.example',
                'action_owner_phone' => '+255 7'.str_pad((string) (64309306 + $i * 1117), 8, '0', STR_PAD_LEFT),
                'target_date' => today()->addDays($targetOffset),
            ]);
            $anomaly->status = 'open';
            $anomaly->raised_by = $raiser?->id;
            $anomaly->save();

            if ($stage === 'open') {
                continue;
            }

            $anomaly->forceFill([
                'status' => 'in_progress',
                'progress_notes' => 'Action under way; contractor HSE lead assigned.',
            ])->save();

            if (in_array($stage, ['awaiting', 'closed'], true)) {
                $this->evidence($anomaly, $raiser, 'Closure photo log', 'closure_evidence');
                $anomaly->forceFill([
                    'evidence_submitted_at' => $raisedOn->copy()->addDays(max(1, (int) ($daysAgo / 2))),
                    'closure_notes' => 'Corrective action completed. Photographs and the signed toolbox-talk register are attached.',
                ])->save();
                $audit->record(action: 'eshs_anomaly.evidence_submitted', entity: $anomaly, summary: 'Closure evidence submitted for verification');
            }

            if ($stage === 'closed') {
                $closedOn = today()->subDays(max(1, $daysAgo - 20 - ($i * 3)));
                $anomaly->forceFill([
                    'status' => 'closed',
                    'closed_on' => $closedOn,
                    'closed_by' => $verifier?->id,
                    'verification_notes' => 'Verified on site with the contractor HSE lead; corrective action effective.',
                ])->save();
                $audit->record(action: 'eshs_anomaly.closed', entity: $anomaly, summary: 'Verified and closed');
            }

            // One overdue anomaly was returned once: the evidence did not show the fix.
            if ($stage === 'overdue' && $risk === 'critical') {
                $anomaly->forceFill([
                    'returned_at' => now()->subDays(4),
                    'return_reason' => 'Photographs show barricades only on two sides of the pit; shoring is not visible. Please resubmit.',
                ])->save();
                $audit->record(action: 'eshs_anomaly.returned', entity: $anomaly, summary: 'Returned to the contractor', context: ['reason' => $anomaly->return_reason]);
            }
        }

        $this->command?->info('ESHS demo: '.count($rows).' anomalies across '.count(self::CONTRACTORS).' contractors.');
    }

    /** A small text file stands in for the evidence photographs in the demo. */
    private function evidence(EshsAnomaly $anomaly, ?User $uploader, string $name, string $kind): void
    {
        $disk = config('filesystems.default', 'local');
        $path = sprintf('projects/%d/eshs_anomaly/%d/%s.txt', $anomaly->project_id, $anomaly->id, Str::uuid());
        $body = "{$anomaly->reference} — {$name}\nDemo evidence placeholder.\n";

        Storage::disk($disk)->put($path, $body);

        Attachment::create([
            'organisation_id' => $anomaly->organisation_id,
            'project_id' => $anomaly->project_id,
            'attachable_type' => $anomaly->getMorphClass(),
            'attachable_id' => $anomaly->id,
            'kind' => $kind,
            'original_name' => Str::slug($name).'-'.$anomaly->reference.'.txt',
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($body),
            'checksum' => hash('sha256', $body),
            'caption' => $name,
            'scan_status' => 'clean',
            'captured_at' => now(),
            'synced_at' => now(),
            'uploaded_by' => $uploader?->id,
        ]);
    }
}
