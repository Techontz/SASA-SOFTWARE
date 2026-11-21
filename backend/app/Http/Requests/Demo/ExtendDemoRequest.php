<?php

namespace App\Http\Requests\Demo;

use Illuminate\Foundation\Http\FormRequest;

/** Give a demo more days. */
class ExtendDemoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires enquiry.manage
    }

    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:'.(int) config('sasa.demos.max_days', 60)],
        ];
    }

    public function messages(): array
    {
        $max = (int) config('sasa.demos.max_days', 60);

        return [
            'days.required' => "Choose how many days to add, from 1 to {$max}.",
            'days.integer' => "Choose how many days to add, from 1 to {$max}.",
            'days.min' => "Choose how many days to add, from 1 to {$max}.",
            'days.max' => "Choose how many days to add, from 1 to {$max}.",
        ];
    }
}
