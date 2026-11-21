<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A role and what it can do, read-only, for the organisation's administrator. */
class OrganisationRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => $this->permissions
                ->sortBy(['group', 'key'])
                ->values()
                ->map(fn ($permission) => ['key' => $permission->key, 'name' => $permission->name, 'group' => $permission->group])
                ->all(),
        ];
    }
}
