<?php

namespace App\Http\Requests\Billing;

use App\Domain\Billing\TanzanianMobileNumber;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tap Pay. Mobile money needs only a phone number: no network to choose,
 * the push goes to whichever network the number belongs to.
 */
class PayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::in(Payment::METHODS)],
            'phone' => ['nullable', 'required_if:method,mobile_money', 'string', 'max:30', new TanzanianMobileNumber],
        ];
    }

    public function messages(): array
    {
        return [
            'method.in' => 'Choose mobile money or card.',
            'phone.required_if' => 'Enter the mobile number that will receive the payment prompt.',
        ];
    }
}
