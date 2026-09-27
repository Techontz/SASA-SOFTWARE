<?php

namespace App\Http\Requests;

use App\Models\Stakeholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStakeholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Handled by the policy in the controller.
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'alias' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(Stakeholder::TYPES)],
            'sub_type' => ['nullable', 'string', 'max:60'],
            'organisation_name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],

            'phone' => ['nullable', 'string', 'max:40'],
            'alternate_phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'preferred_language' => ['nullable', 'string', 'max:10'],
            'preferred_contact_method' => ['nullable', 'string', 'max:30'],
            'physical_address' => ['nullable', 'string', 'max:1000'],
            'postal_address' => ['nullable', 'string', 'max:255'],

            'primary_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer', 'exists:locations,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'influence' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'interest' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'power' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'impact' => ['nullable', Rule::in(['high', 'medium', 'low'])],
            'engagement_strategy' => ['nullable', 'string', 'max:255'],
            'communication_frequency' => ['nullable', 'string', 'max:30'],

            'concerns_expectations' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'is_vulnerable' => ['boolean'],
            'vulnerability_categories' => ['nullable', 'array'],
            'vulnerability_categories.*' => ['string', 'max:60'],
            'is_indigenous_or_minority' => ['boolean'],
            'consent_status' => ['nullable', Rule::in(['granted', 'refused', 'not_recorded', 'withdrawn'])],
            'consent_basis' => ['nullable', 'string', 'max:60'],
            'consent_date' => ['nullable', 'date'],
            'identification_source' => ['nullable', 'string', 'max:60'],
            'identification_method' => ['nullable', 'string', 'max:60'],

            // Optional disaggregation dimensions — never mandatory.
            'demographics' => ['nullable', 'array'],
            'custom_fields' => ['nullable', 'array'],

            'status' => ['nullable', Rule::in(['active', 'inactive', 'archived'])],
            'review_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],

            'contacts' => ['nullable', 'array'],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:160'],
            'contacts.*.role' => ['nullable', 'string', 'max:120'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.email' => ['nullable', 'email'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],

            'captured_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'A stakeholder needs a name.',
            'type.required' => 'Choose what kind of stakeholder this is.',
            'type.in' => 'That stakeholder type is not on this project\'s list.',
            'email.email' => 'That does not look like an email address.',
        ];
    }
}
