<?php

namespace App\Http\Requests\Eshs;

use Illuminate\Foundation\Http\FormRequest;

/** The contractor's update on the action they are taking. */
class EshsProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The policy decides, in the controller.
    }

    public function rules(): array
    {
        return [
            'contractor_action' => ['nullable', 'string', 'max:5000'],
            'action_owner_name' => ['nullable', 'string', 'max:160'],
            'action_owner_email' => ['nullable', 'email', 'max:255'],
            'action_owner_phone' => ['nullable', 'string', 'max:40'],
            'progress_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
