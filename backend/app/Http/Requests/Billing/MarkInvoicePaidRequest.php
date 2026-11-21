<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/** Money for an invoice arrived outside SASA, for example by bank transfer. */
class MarkInvoicePaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires billing.manage
    }

    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return ['note.max' => 'The note must be 1,000 characters or fewer.'];
    }
}
