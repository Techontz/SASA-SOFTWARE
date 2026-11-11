<?php

namespace App\Http\Requests\Eshs;

use App\Domain\Eshs\EshsConfiguration;
use App\Domain\Tenancy\TenantContext;
use App\Models\EshsAnomaly;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Raising (POST) and correcting (PATCH) an anomaly. Every list is the
 * project's configured list, so a value another project allows is refused
 * here. Contractor lists are enforced once a project has configured them.
 */
class EshsAnomalyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Raising is refused before validation, so a contractor is told no
        // rather than how to fill in a form they may not submit. Corrections
        // are authorised against the record in the controller.
        return $this->isMethod('POST') ? Gate::allows('create', EshsAnomaly::class) : true;
    }

    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $project = $this->project();
        $config = app(EshsConfiguration::class);

        $contractors = $config->contractors($project);
        $subcontractors = $config->subcontractors($project);

        return [
            'raised_on' => [$required, 'date', 'before_or_equal:today'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('organisation_id', $project->organisation_id)],
            'contractor' => array_merge([$required, 'string', 'max:160'], $contractors ? [Rule::in($contractors)] : []),
            'subcontractor' => array_merge(['nullable', 'string', 'max:160'], $subcontractors ? [Rule::in($subcontractors)] : []),
            'category' => [$required, Rule::in(array_keys($config->categories($project)))],
            'subcategory' => [$required, 'string', 'max:160'],
            'description' => [$required, 'string', 'min:10', 'max:20000'],
            'anomaly_type' => [$required, Rule::in($config->anomalyTypes($project))],
            'risk_rating' => [$required, Rule::in(array_keys($config->riskRatings($project)))],
            'root_cause_group' => ['nullable', 'required_with:root_cause', Rule::in(array_keys($config->rootCauses($project)))],
            'root_cause' => ['nullable', 'string', 'max:160'],
            'contractor_action' => ['nullable', 'string', 'max:5000'],
            'action_owner_name' => ['nullable', 'string', 'max:160'],
            'action_owner_email' => ['nullable', 'email', 'max:255'],
            'action_owner_phone' => ['nullable', 'string', 'max:40'],
            'target_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'contractor.in' => 'Choose a contractor from this project\'s list.',
            'subcontractor.in' => 'Choose a subcontractor from this project\'s list.',
            'description.required' => 'Describe what was found — what, where and why it matters.',
            'description.min' => 'Describe what was found in a little more detail.',
            'raised_on.before_or_equal' => 'The date raised cannot be in the future.',
        ];
    }

    /** The dependent lists: subcategory within its category, cause within its heading. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $config = app(EshsConfiguration::class);
                $project = $this->project();
                $anomaly = $this->route('anomaly');

                $category = $this->input('category', $anomaly?->category);
                $subcategory = $this->input('subcategory', $anomaly?->subcategory);

                if (($this->has('category') || $this->has('subcategory'))
                    && ! in_array($subcategory, $config->categories($project)[$category]['subcategories'] ?? [], true)) {
                    $validator->errors()->add('subcategory', 'Choose a subcategory that belongs to the chosen ESHS category.');
                }

                if ($this->filled('root_cause')) {
                    $group = $this->input('root_cause_group');

                    if (! in_array($this->input('root_cause'), $config->rootCauses($project)[$group] ?? [], true)) {
                        $validator->errors()->add('root_cause', 'Choose a root cause listed under the chosen heading.');
                    }
                }
            },
        ];
    }

    private function project(): Project
    {
        return app(TenantContext::class)->requireProject();
    }
}
