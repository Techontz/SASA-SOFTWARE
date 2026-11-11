<?php

namespace App\Http\Controllers\Api\V1\Pap;

use App\Domain\Pap\PapConsultationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pap\PapConsultationRequest;
use App\Http\Resources\Pap\PapConsultationResource;
use App\Models\Pap\PapConsultation;
use App\Support\ApiResponse;
use App\Support\QueryFilters;
use Illuminate\Http\Request;

/** Part L — project-level resettlement consultations. */
class PapConsultationController extends Controller
{
    public function __construct(private readonly PapConsultationService $consultations) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PapConsultation::class);

        $query = $this->buildQuery($request)->with('location:id,path');

        QueryFilters::sort($query, $request, ['consultation_date', 'reference', 'village'], '-consultation_date');

        $totals = $this->buildQuery($request)
            ->selectRaw('COUNT(*) as meetings, COALESCE(SUM(number_male),0) as male, COALESCE(SUM(number_female),0) as female, COALESCE(SUM(number_vulnerable),0) as vulnerable')
            ->first();

        return PapConsultationResource::collection(
            $query->paginate(QueryFilters::perPage($request))->withQueryString()
        )->additional(['meta' => ['summary' => [
            'meetings' => (int) $totals->meetings,
            'male' => (int) $totals->male,
            'female' => (int) $totals->female,
            'vulnerable' => (int) $totals->vulnerable,
        ]]]);
    }

    public function store(PapConsultationRequest $request)
    {
        $this->authorize('create', PapConsultation::class);

        $consultation = $this->consultations->create($this->project(), $request->validated());

        return ApiResponse::data(new PapConsultationResource($consultation), [], 201);
    }

    public function show(PapConsultation $consultation)
    {
        $this->authorize('view', $consultation);

        return ApiResponse::data(new PapConsultationResource($consultation->load('location')));
    }

    public function update(PapConsultationRequest $request, PapConsultation $consultation)
    {
        $this->authorize('update', $consultation);

        return ApiResponse::data(new PapConsultationResource(
            $this->consultations->update($consultation, $request->validated())
        ));
    }

    public function archive(PapConsultation $consultation)
    {
        $this->authorize('update', $consultation);

        $this->consultations->archive($consultation);

        return ApiResponse::message("{$consultation->reference} has been archived.");
    }

    private function buildQuery(Request $request)
    {
        $query = PapConsultation::query()->where('project_id', $this->project()->id);

        if (! $request->boolean('include_archived')) {
            $query->whereNull('archived_at');
        }

        QueryFilters::apply($query, $request, [
            'meeting_type', 'village',
            'search' => fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('topic_discussed', 'like', "%$v%")
                ->orWhere('reference', 'like', "%$v%")
                ->orWhere('village', 'like', "%$v%")
                ->orWhere('facilitator', 'like', "%$v%")),
        ]);

        QueryFilters::dateRange($query, $request, 'consultation_date', 'from', 'to');

        return $query;
    }
}
