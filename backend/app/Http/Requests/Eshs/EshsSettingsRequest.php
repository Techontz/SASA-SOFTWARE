<?php

namespace App\Http\Requests\Eshs;

use Illuminate\Foundation\Http\FormRequest;

/** The project's contractor and subcontractor lists. */
class EshsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // configuration.manage is checked in the controller.
    }

    public function rules(): array
    {
        return [
            'contractors' => ['sometimes', 'array', 'max:200'],
            'contractors.*' => ['nullable', 'string', 'max:160'],
            'subcontractors' => ['sometimes', 'array', 'max:200'],
            'subcontractors.*' => ['nullable', 'string', 'max:160'],
        ];
    }
}
