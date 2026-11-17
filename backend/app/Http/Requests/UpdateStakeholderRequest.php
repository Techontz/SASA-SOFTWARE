<?php

namespace App\Http\Requests;

use App\Domain\Configuration\RequiredFields;
use App\Models\Stakeholder;
use Illuminate\Validation\Rule;

class UpdateStakeholderRequest extends StoreStakeholderRequest
{
    public function rules(): array
    {
        return app(RequiredFields::class)->apply(array_merge($this->baseRules(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(Stakeholder::TYPES)],
        ]), $this->currentProject(), 'stakeholder', $this->disabledDimensions(), partial: true);
    }
}
