<?php

namespace App\Http\Requests\Billing;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create or edit a plan. PATCH changes only the fields sent. */
class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires billing.manage
    }

    public function rules(): array
    {
        $required = $this->isMethod('patch') ? 'sometimes' : 'required';
        $plan = $this->route('plan');
        $planId = $plan instanceof Plan ? $plan->id : (is_numeric($plan) ? (int) $plan : null);

        return [
            'code' => [$required, 'string', 'max:60', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', Rule::unique('plans', 'code')->ignore($planId)],
            'name' => [$required, 'string', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'price_usd' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'price_tzs' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999'],
            'billing_period' => [$required, 'string', Rule::in(Plan::PERIODS)],
            'features' => ['sometimes', 'array', 'max:50'],
            'features.*' => ['nullable', 'string', 'max:300'], // blank lines are dropped
            'limits' => ['sometimes', 'array'],
            'limits.projects' => ['nullable', 'integer', 'min:1'],
            'limits.users' => ['nullable', 'integer', 'min:1'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_custom_quote' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Use lower-case letters, numbers and hyphens, for example "professional".',
        ];
    }

    /** A plan paid online needs at least one price. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $plan = $this->route('plan');
            $current = $plan instanceof Plan ? $plan : null;

            $value = fn (string $key) => $this->has($key) ? $this->input($key) : $current?->{$key};

            $customQuote = filter_var($value('is_custom_quote'), FILTER_VALIDATE_BOOLEAN);

            if (! $customQuote && ($value('price_usd') === null || $value('price_usd') === '') && ($value('price_tzs') === null || $value('price_tzs') === '')) {
                $validator->errors()->add('price_usd', 'Give the plan a price in USD or TZS, or mark it as a custom quote.');
            }
        });
    }
}
