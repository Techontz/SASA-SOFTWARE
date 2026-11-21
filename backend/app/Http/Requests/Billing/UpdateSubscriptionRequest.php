<?php

namespace App\Http\Requests\Billing;

use App\Models\SubscriptionRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An administrator's decision on a request. "paid" is never set by hand. */
class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires billing.manage
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(SubscriptionRequest::REVIEW_STATUSES)],
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
