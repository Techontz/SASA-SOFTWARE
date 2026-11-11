<?php

namespace App\Http\Requests\Pap;

use App\Domain\Pap\PapOptions;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Part A — the PAP profile. POST creates; PATCH accepts any subset. */
class PapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handled by the policy in the controller.
    }

    public function rules(): array
    {
        $project = app(TenantContext::class)->requireProject();
        $options = app(PapOptions::class);
        $in = fn (string $list) => ['nullable', Rule::in($options->values($project, $list))];
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'client_uuid' => ['nullable', 'uuid'],
            'household_id' => ['nullable', 'string', 'max:40'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('organisation_id', $project->organisation_id)],
            'kitongoji' => ['nullable', 'string', 'max:120'],
            'full_name' => [$required, 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:40'],
            'gender' => $in('genders'),
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'stated_age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'marital_status' => $in('marital_statuses'),
            'phone' => ['nullable', 'string', 'max:40'],
            'alternative_contact' => ['nullable', 'string', 'max:120'],
            'physical_address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'next_of_kin' => ['nullable', 'string', 'max:160'],
            'next_of_kin_contact' => ['nullable', 'string', 'max:120'],
            'vulnerability_category' => $in('vulnerability_categories'),
            'remarks' => ['nullable', 'string', 'max:5000'],
            'captured_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'A PAP needs a full name.',
            'date_of_birth.before_or_equal' => 'The date of birth cannot be in the future.',
            'vulnerability_category.in' => 'That vulnerability category is not on this project\'s list.',
        ];
    }
}
