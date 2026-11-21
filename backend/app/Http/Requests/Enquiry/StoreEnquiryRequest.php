<?php

namespace App\Http\Requests\Enquiry;

use App\Models\Enquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Register interest", "Book a demo" and "Request a quote" on the public
 * website. A quote also answers a few qualifying questions (company size,
 * projects, users, location, stage, timing); for other types those fields are
 * ignored and never stored. No account is
 * needed. `website` is a honeypot: people never see it, bots fill it in.
 */
class StoreEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public by design; rate-limited at the route
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(Enquiry::TYPES)],
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'organization' => ['required', 'string', 'min:2', 'max:200'],
            'sector' => ['required', 'string', Rule::in(Enquiry::SECTORS)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()\-]{6,24}$/'],
            'modules' => ['nullable', 'array', 'max:'.count(Enquiry::MODULES)],
            'modules.*' => ['string', 'distinct', Rule::in(Enquiry::MODULES)],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable'],

            // Quote only. `exclude_unless` drops them for every other type.
            'company_size' => ['exclude_unless:type,quote', 'required', 'string', Rule::in(Enquiry::COMPANY_SIZES)],
            'projects_count' => ['exclude_unless:type,quote', 'required', 'integer', 'min:1', 'max:500'],
            'users_count' => ['exclude_unless:type,quote', 'required', 'integer', 'min:1', 'max:100000'],
            'project_location' => ['exclude_unless:type,quote', 'nullable', 'string', 'max:120'],
            'project_stage' => ['exclude_unless:type,quote', 'nullable', 'string', Rule::in(Enquiry::PROJECT_STAGES)],
            'start_timeframe' => ['exclude_unless:type,quote', 'nullable', 'string', Rule::in(Enquiry::START_TIMEFRAMES)],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Choose whether you want to register interest, book a demo or request a quote.',
            'type.in' => 'Choose whether you want to register interest, book a demo or request a quote.',
            'full_name.required' => 'Enter your full name.',
            'full_name.min' => 'Enter your full name.',
            'full_name.max' => 'Your name must be 160 characters or fewer.',
            'email.required' => 'Enter your e-mail address.',
            'email.email' => 'Enter a valid e-mail address, for example name@company.com.',
            'email.max' => 'Your e-mail address must be 190 characters or fewer.',
            'organization.required' => 'Enter the name of your organisation.',
            'organization.min' => 'Enter the name of your organisation.',
            'organization.max' => 'The organisation name must be 200 characters or fewer.',
            'sector.required' => 'Choose your sector.',
            'sector.in' => 'Choose infrastructure, mining, energy or other.',
            'phone.regex' => 'Enter a phone number, for example 0712 345 678 or +255 712 345 678.',
            'phone.max' => 'Enter a phone number, for example 0712 345 678 or +255 712 345 678.',
            'modules.array' => 'Choose the modules you are interested in.',
            'modules.max' => 'Choose the modules you are interested in.',
            'modules.*.in' => 'Choose modules from the list.',
            'modules.*.distinct' => 'Choose each module only once.',
            'modules.*.string' => 'Choose modules from the list.',
            'message.max' => 'Your message must be 2,000 characters or fewer.',
            'company_size.required' => 'Choose the size of your company.',
            'company_size.in' => 'Choose 1-50, 51-200, 201-1000 or more than 1000 people.',
            'company_size.string' => 'Choose 1-50, 51-200, 201-1000 or more than 1000 people.',
            'projects_count.required' => 'Enter how many projects you want to run on SASA.',
            'projects_count.integer' => 'Enter the number of projects as a whole number, for example 3.',
            'projects_count.min' => 'Enter at least 1 project.',
            'projects_count.max' => 'For more than 500 projects, tell us in your message and enter 500 here.',
            'users_count.required' => 'Enter roughly how many people will use SASA.',
            'users_count.integer' => 'Enter the number of users as a whole number, for example 40.',
            'users_count.min' => 'Enter at least 1 user.',
            'users_count.max' => 'For more than 100,000 users, tell us in your message and enter 100000 here.',
            'project_location.max' => 'The project location must be 120 characters or fewer.',
            'project_stage.in' => 'Choose planning, construction, operation or a mix.',
            'start_timeframe.in' => 'Choose now, in 1 to 3 months, in 3 to 6 months or later.',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['type', 'sector', 'project_stage', 'start_timeframe'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => strtolower(trim($this->input($key)))]);
            }
        }
    }
}
