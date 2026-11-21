<?php

namespace App\Http\Requests\Enquiry;

use App\Models\Enquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An administrator follows up an enquiry. */
class UpdateEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires enquiry.manage
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Enquiry::STATUSES)],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Choose new, contacted or closed.',
            'status.in' => 'Choose new, contacted or closed.',
        ];
    }
}
