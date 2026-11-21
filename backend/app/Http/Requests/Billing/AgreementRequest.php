<?php

namespace App\Http\Requests\Billing;

use App\Models\OrganisationAgreement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A platform administrator records (POST) or changes (PATCH) the plan agreed
 * with an organisation. On PATCH every field is optional, and the status can
 * be set to active, suspended or cancelled.
 *
 * `organisation_id` names the customer the administrator is billing; it is a
 * platform-level choice, not a tenant scope, and is only accepted on the
 * billing.manage routes.
 */
class AgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires billing.manage
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'organisation_id' => [$required, 'integer', Rule::exists('organisations', 'id')->whereNull('archived_at')],
            'enquiry_id' => ['sometimes', 'nullable', 'integer', Rule::exists('enquiries', 'id')],
            'name' => [$required, 'string', 'min:2', 'max:160'],
            'monthly_amount' => [$required, 'numeric', 'min:1', 'max:999999999999'],
            'billing_day' => [$required, 'integer', 'min:1', 'max:28'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'modules' => [$required, 'array', 'min:1', 'max:'.count(OrganisationAgreement::MODULES)],
            'modules.*' => ['string', 'distinct', Rule::in(OrganisationAgreement::MODULES)],
            'limits' => [$required, 'array'],
            'limits.projects' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'limits.users' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'terms_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];

        if (! $creating) {
            $rules['status'] = ['sometimes', 'string', Rule::in(OrganisationAgreement::ADMIN_STATUSES)];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'organisation_id.required' => 'Choose the organisation this agreement is for.',
            'organisation_id.exists' => 'Choose an organisation from the list.',
            'organisation_id.integer' => 'Choose an organisation from the list.',
            'enquiry_id.exists' => 'Choose an enquiry from the list, or leave it empty.',
            'name.required' => 'Give the agreement a name, for example "SASA for Lake Zone Power".',
            'name.min' => 'Give the agreement a name, for example "SASA for Lake Zone Power".',
            'name.max' => 'The name must be 160 characters or fewer.',
            'monthly_amount.required' => 'Enter the agreed monthly amount in Tanzanian shillings.',
            'monthly_amount.numeric' => 'Enter the monthly amount as a number, for example 1150000.',
            'monthly_amount.min' => 'The monthly amount must be more than zero.',
            'monthly_amount.max' => 'That monthly amount is too large.',
            'billing_day.required' => 'Choose the day of the month the invoice is due (1 to 28).',
            'billing_day.integer' => 'Choose the day of the month the invoice is due (1 to 28).',
            'billing_day.min' => 'Choose a billing day from 1 to 28.',
            'billing_day.max' => 'Choose a billing day from 1 to 28, so every month has it.',
            'starts_on.required' => 'Enter the date the agreement starts.',
            'starts_on.date_format' => 'Enter the start date as YYYY-MM-DD.',
            'modules.required' => 'Choose at least one module.',
            'modules.min' => 'Choose at least one module.',
            'modules.array' => 'Choose the modules the agreement covers.',
            'modules.*.in' => 'Choose modules from the list.',
            'modules.*.distinct' => 'Choose each module only once.',
            'limits.required' => 'Enter the project and user limits, or leave each empty for no limit.',
            'limits.array' => 'Enter the project and user limits, or leave each empty for no limit.',
            'limits.projects.integer' => 'Enter the project limit as a whole number, or leave it empty for no limit.',
            'limits.projects.min' => 'The project limit must be at least 1, or empty for no limit.',
            'limits.users.integer' => 'Enter the user limit as a whole number, or leave it empty for no limit.',
            'limits.users.min' => 'The user limit must be at least 1, or empty for no limit.',
            'terms_note.max' => 'The terms note must be 5,000 characters or fewer.',
            'internal_note.max' => 'The internal note must be 5,000 characters or fewer.',
            'status.in' => 'Choose active, suspended or cancelled.',
        ];
    }
}
