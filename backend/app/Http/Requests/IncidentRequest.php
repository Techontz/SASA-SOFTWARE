<?php

namespace App\Http\Requests;

use App\Domain\Incident\IncidentOptions;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Incident Notification Report. Every dropdown is validated against the
 * project's own lists; `reported_by` is not accepted at all — the reporter is
 * the signed-in user.
 */
class IncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorises through the policy
    }

    public function rules(): array
    {
        $project = app(TenantContext::class)->requireProject();
        $options = app(IncidentOptions::class);
        $required = $this->isMethod('patch') ? 'sometimes' : 'required';
        $levels = array_keys($options->levels());

        return [
            'client_uuid' => ['nullable', 'uuid'],
            'occurred_at' => [$required, 'date', 'before_or_equal:'.now()->addDay()->toDateTimeString()],
            'company' => ['nullable', 'string', Rule::in($options->list($project, 'companies'))],
            'location_id' => [
                'nullable', 'integer',
                Rule::exists('locations', 'id')->where('organisation_id', $project->organisation_id),
            ],
            'site_detail' => ['nullable', 'string', 'max:255'],
            'category' => [$required, 'string', Rule::in($options->categoryKeys($project))],
            'incident_type' => [$required, 'string', Rule::in($options->typeKeys($project))],
            'actual_consequence' => [$required, 'integer', Rule::in($levels)],
            'potential_consequence' => [$required, 'integer', Rule::in($levels)],
            'summary' => [$required, 'string', 'min:10', 'max:20000'],
            'contractor_manager' => ['nullable', 'string', Rule::in($options->list($project, 'contractor_managers'))],
            'resident_engineer' => ['nullable', 'string', Rule::in($options->list($project, 'resident_engineers'))],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $actual = $this->input('actual_consequence');
            $potential = $this->input('potential_consequence');

            if (is_numeric($actual) && is_numeric($potential) && (int) $potential < (int) $actual) {
                $validator->errors()->add(
                    'potential_consequence',
                    'The potential consequence cannot be lower than what actually happened.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'occurred_at.required' => 'Give the date and time of the incident.',
            'category.required' => 'Choose a category.',
            'category.in' => 'Choose a category from the list.',
            'incident_type.required' => 'Choose the incident type.',
            'incident_type.in' => 'Choose an incident type from the list.',
            'actual_consequence.in' => 'Choose a consequence level from 0 to 5.',
            'potential_consequence.in' => 'Choose a consequence level from 0 to 5.',
            'summary.required' => 'Describe what happened.',
            'company.in' => 'Choose a company from this project\'s list.',
            'contractor_manager.in' => 'Choose a contractor manager from this project\'s list.',
            'resident_engineer.in' => 'Choose a resident engineer from this project\'s list.',
        ];
    }
}
