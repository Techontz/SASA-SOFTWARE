<?php

namespace App\Http\Requests\Billing;

use App\Domain\Billing\TanzanianMobileNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Turn auto-pay on or off. On means: when an invoice is raised, a mobile money
 * prompt is sent to this phone straight away, to be approved with the PIN.
 */
class AutoPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires organisation.billing
    }

    public function rules(): array
    {
        return [
            'auto_pay' => ['required', 'boolean'],
            'phone' => ['nullable', 'required_if_accepted:auto_pay', 'string', 'max:30', new TanzanianMobileNumber],
        ];
    }

    public function messages(): array
    {
        return [
            'auto_pay.required' => 'Choose whether to turn auto-pay on or off.',
            'auto_pay.boolean' => 'Choose whether to turn auto-pay on or off.',
            'phone.required_if_accepted' => 'Enter the mobile number that will receive the monthly payment prompt.',
        ];
    }
}
