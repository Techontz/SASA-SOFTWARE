<?php

namespace App\Domain\Pap;

use App\Models\Location;
use App\Models\Pap\PapConsultation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/** Part L — the resettlement consultation register. */
final class PapConsultationService
{
    public function create(Project $project, array $data): PapConsultation
    {
        return DB::transaction(function () use ($project, $data) {
            $consultation = new PapConsultation;
            $consultation->organisation_id = $project->organisation_id;
            $consultation->project_id = $project->id;
            $consultation->fill(collect($data)->only(PapConsultation::INPUT_FIELDS)->all());
            $this->applyVillage($consultation);
            $consultation->save();

            return $consultation->fresh('location');
        });
    }

    public function update(PapConsultation $consultation, array $data): PapConsultation
    {
        return DB::transaction(function () use ($consultation, $data) {
            $consultation->fill(collect($data)->only(PapConsultation::INPUT_FIELDS)->all());
            $this->applyVillage($consultation);
            $consultation->save();

            return $consultation->fresh('location');
        });
    }

    public function archive(PapConsultation $consultation): void
    {
        $consultation->archive();
    }

    /** A village picked from the hierarchy fills the village name. */
    private function applyVillage(PapConsultation $consultation): void
    {
        foreach (['number_male', 'number_female', 'number_vulnerable'] as $count) {
            $consultation->{$count} = (int) ($consultation->{$count} ?? 0);
        }

        if ($consultation->isDirty('location_id') && $consultation->location_id && (blank($consultation->village) || ! $consultation->isDirty('village'))) {
            $consultation->village = Location::find($consultation->location_id)?->name;
        }
    }
}
