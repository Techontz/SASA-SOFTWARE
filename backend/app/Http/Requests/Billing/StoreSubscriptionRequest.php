<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The public "request a subscription" form. No account is needed. */
class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public by design; rate-limited at the route
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->whereNull('archived_at')],
            'currency' => ['required', 'string', Rule::in((array) config('sasa.billing.currencies', ['USD', 'TZS']))],
            'organisation_name' => ['required', 'string', 'min:2', 'max:200'],
            'contact_name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            // International numbers are allowed here (a card payer may be
            // abroad); the mobile money number is checked when paying.
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()\-]{6,24}$/'],
            'country' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'plan_id.exists' => 'That plan is no longer available. Choose another plan.',
            'currency.in' => 'Choose USD or TZS.',
            'phone.regex' => 'Enter a phone number, for example 0712 345 678 or +255 712 345 678.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }
}
