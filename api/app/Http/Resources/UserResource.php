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
            /*
             * What this officer is carrying, when the caller asked for it.
             *
             * `whenCounted`, so every other consumer of this resource — the
             * profile, the assignment payloads, the message participants — is
             * unchanged and pays nothing. Only the officer directory adds the
             * counts, and only that screen sees these keys.
             *
             * OPEN work only, by the same rule the caseload screen uses
             * (`Caseload::scopeOpen`). A finished review keeps the officer's
             * name on it and is not something they are still carrying; the
             * caseload page states that separately, and the two numbers have
             * to be able to differ without either being wrong.
             */
            'open_reviews' => $this->whenCounted('open_reviews_count'),
            'open_inspections' => $this->whenCounted('open_inspections_count'),
        ];
    }
}
