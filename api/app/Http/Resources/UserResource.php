<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Matches web/src/lib/types.ts `User`. */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'mobile_number' => $this->mobile_number,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'gender' => $this->gender,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'code' => $this->department->code,
                'name' => $this->department->name,
            ] : null,
            'is_active' => (bool) $this->is_active,
            'email_verified_at' => optional($this->email_verified_at)->toISOString(),
            'roles' => $this->roleNames(),
            'permissions' => $this->permissionNames(),
        ];
    }
}
