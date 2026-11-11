<?php

namespace App\Http\Controllers\Api\V1\Pap;

use App\Domain\Pap\PapOptions;
use App\Domain\Pap\PapRecordService;
use App\Domain\Pap\PapSections;
use App\Http\Controllers\Controller;
use App\Http\Resources\Pap\PapRecordResource;
use App\Models\Pap;
use App\Models\Pap\PapRecord;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * Parts B–J and M of a PAP record, one endpoint shape for every section:
 * /paps/{pap}/{section}. Payments are guarded by pap.manage_payments; every
 * other section by pap.manage.
 */
class PapRecordController extends Controller
{
    public function __construct(private readonly PapRecordService $records) {}

    public function index(Request $request, Pap $pap, string $section)
    {
        $this->authorize('view', $pap);

        $definition = PapSections::get($section);

        $records = $pap->{$definition['relation']}()
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('id')
            ->get();

        return ApiResponse::data(PapRecordResource::collection($records));
    }

    public function store(Request $request, Pap $pap, string $section)
    {
        $definition = PapSections::get($section);
        $this->authorize($definition['ability'], $pap);

        $record = $this->records->create($pap, $section, $this->validated($request, $section));

        return ApiResponse::data(new PapRecordResource($record), [], 201);
    }

    public function update(Request $request, Pap $pap, string $section, int $record)
    {
        $definition = PapSections::get($section);
        $this->authorize($definition['ability'], $pap);

        $model = $this->find($pap, $section, $record);

        abort_if($model->archived_at !== null, 409, 'An archived record is read-only.');

        $updated = $this->records->update($model, $this->validated($request, $section, updating: true));

        return ApiResponse::data(new PapRecordResource($updated));
    }

    public function archive(Pap $pap, string $section, int $record)
    {
        $definition = PapSections::get($section);
        $this->authorize($definition['ability'], $pap);

        $model = $this->find($pap, $section, $record);
        $this->records->archive($model);

        return ApiResponse::message(($model->getAttribute('reference') ?? 'The record').' has been archived.');
    }

    private function find(Pap $pap, string $section, int $id): PapRecord
    {
        $class = PapSections::get($section)['model'];

        return $class::query()->where('pap_id', $pap->id)->findOrFail($id);
    }

    private function validated(Request $request, string $section, bool $updating = false): array
    {
        $project = $this->project();
        $options = app(PapOptions::class);

        return $request->validate(
            PapSections::rules($section, fn (string $list) => $options->values($project, $list), $updating)
        );
    }
}
