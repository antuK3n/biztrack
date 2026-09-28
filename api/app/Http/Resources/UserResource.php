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
            /*
             * The owner's home address [checklist 2026-09-28, Register 2]. Null
             * for staff, who are never asked, and for owners who registered
             * before it was — AuthController::userPayload says which of those
             * still owes one.
             */
            'home_street' => $this->home_street,
            'home_barangay' => $this->home_barangay,
            'home_city' => $this->home_city,
            'home_province' => $this->home_province,
            'home_postal_code' => $this->home_postal_code,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'code' => $this->department->code,
                'name' => $this->department->name,
            ] : null,
            /*
             * Whether to fetch the photo, not where it lives. `avatar_path`
             * points into the private disk, and a client that knew it could ask
             * for another account's file by editing the path; the photo route
             * serves the signed-in user's own row instead of taking a path.
             */
            'has_photo' => $this->avatar_path !== null,
            'is_active' => (bool) $this->is_active,
            'email_verified_at' => optional($this->email_verified_at)->toISOString(),
            'roles' => $this->roleNames(),
            'permissions' => $this->permissionNames(),
        ];
    }
}
