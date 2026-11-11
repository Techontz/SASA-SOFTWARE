<?php

namespace App\Http\Requests\Eshs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submit, close and return all carry a note; returning requires one, because
 * a contractor told "no" without a reason cannot put it right.
 */
class EshsVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The policy decides, in the controller.
    }

    public function rules(): array
    {
        $returning = str_ends_with((string) $this->route()?->uri(), '/return');

        return [
            'notes' => ['nullable', 'string', 'max:5000'],
            'reason' => [$returning ? 'required' : 'nullable', 'string', 'min:5', 'max:5000'],
            'closed_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the evidence is being returned, so the contractor knows what to put right.',
        ];
    }
}
