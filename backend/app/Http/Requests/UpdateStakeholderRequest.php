<?php

namespace App\Http\Requests;

use App\Models\Stakeholder;
use Illuminate\Validation\Rule;

class UpdateStakeholderRequest extends StoreStakeholderRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(Stakeholder::TYPES)],
        ]);
    }
}
