<?php

namespace App\Http\Requests\Pap;

use App\Domain\Pap\PapOptions;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Part L — a resettlement consultation. */
class PapConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handled by the policy in the controller.
    }

    public function rules(): array
    {
        $project = app(TenantContext::class)->requireProject();
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $count = ['nullable', 'integer', 'min:0', 'max:100000'];

        return [
            'consultation_date' => [$required, 'date'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('organisation_id', $project->organisation_id)],
            'village' => ['nullable', 'string', 'max:120'],
            'meeting_type' => ['nullable', Rule::in(app(PapOptions::class)->values($project, 'meeting_types'))],
            'topic_discussed' => [$required, 'string', 'max:5000'],
            'facilitator' => ['nullable', 'string', 'max:160'],
            'number_male' => $count,
            'number_female' => $count,
            'number_vulnerable' => $count,
            'attendance_sheet_reference' => ['nullable', 'string', 'max:120'],
            'key_concerns_raised' => ['nullable', 'string', 'max:10000'],
            'actions_agreed' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function messages(): array
    {
        return [
            'topic_discussed.required' => 'Record what the meeting discussed.',
            'consultation_date.required' => 'When was the consultation held?',
        ];
    }
}
