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
             * No home address here, on purpose. This resource also feeds the
             * super admin's user listings, and an owner's home is personal data
             * nobody at City Hall needs to browse (RA 10173 proportionality,
             * docs/questions-for-malabon.md B5). The owner's own payload adds it
             * in AuthController::userPayload. If an office turns out to need it
             * (A27), add it there for that office, not back here for everyone.
             */
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
