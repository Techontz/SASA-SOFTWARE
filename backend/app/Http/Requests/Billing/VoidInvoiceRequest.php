<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/** An invoice that should not be paid is voided, never deleted, with a reason. */
class VoidInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires billing.manage
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this invoice is being voided.',
            'reason.min' => 'Say why this invoice is being voided.',
            'reason.max' => 'The reason must be 1,000 characters or fewer.',
        ];
    }
}
