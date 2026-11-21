<?php

namespace App\Http\Requests\Demo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A platform administrator gives the company behind an enquiry its own demo
 * workspace. Every field is optional: the enquiry supplies the person and the
 * company, and config/sasa.php the length.
 */
class StoreDemoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires enquiry.manage
    }

    public function rules(): array
    {
        return [
            'days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('sasa.demos.max_days', 60)],
            'sample_data' => ['sometimes', 'nullable', 'boolean'],
            'name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'project_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:160'],
        ];
    }

    public function messages(): array
    {
        $max = (int) config('sasa.demos.max_days', 60);

        return [
            'days.integer' => "Choose a demo length of 1 to {$max} days.",
            'days.min' => "Choose a demo length of 1 to {$max} days.",
            'days.max' => "Choose a demo length of 1 to {$max} days.",
            'email.email' => 'Enter a valid e-mail address, for example name@company.co.tz.',
            'name.min' => 'Enter the contact\'s name.',
            'project_name.min' => 'Give the demo project a name of at least two characters.',
        ];
    }
}
