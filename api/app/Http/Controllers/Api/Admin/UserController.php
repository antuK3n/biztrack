<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Admin user management — create/edit officers, toggle activation.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with('department', 'roles.permissions')->orderBy('name');

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }
        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($r) => $r->where('name', $role));
        }

        return response()->json(['data' => UserResource::collection($query->get())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => ['required', 'in:M,F'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile_number' => ['required', 'string', 'max:20'],
            'password' => ['required', PasswordRule::min(8)],
            'department_id' => ['nullable', 'exists:departments,id'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['exists:roles,name'],
        ], [
            'email.unique' => 'This email is already registered.',
        ]);

        $user = User::create([
            'name' => trim("{$data['first_name']} {$data['last_name']}"),
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'suffix' => $data['suffix'] ?? null,
            'gender' => $data['gender'],
            'email' => strtolower(trim($data['email'])),
            'mobile_number' => $data['mobile_number'],
            'password' => $data['password'],
            'department_id' => $data['department_id'] ?? null,
            'is_active' => true,
            'data_privacy_consent_at' => now(),
            'email_verified_at' => now(),
        ]);
        $user->roles()->sync(Role::whereIn('name', $data['roles'])->pluck('id'));

        Audit::log('user.created', $user);

        return response()->json([
            'data' => new UserResource($user->load('department', 'roles.permissions')),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => ['sometimes', 'in:M,F'],
            'mobile_number' => ['sometimes', 'string', 'max:20'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'department_id' => ['nullable', 'exists:departments,id'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['exists:roles,name'],
            'password' => ['nullable', PasswordRule::min(8)],
        ]);

        $user->fill(collect($data)->except(['roles', 'email'])->toArray());
        if (isset($data['email'])) {
            $user->email = strtolower(trim($data['email']));
        }
        if (isset($data['first_name']) || isset($data['last_name'])) {
            $user->name = trim(($data['first_name'] ?? $user->first_name).' '.($data['last_name'] ?? $user->last_name));
        }
        $user->save();

        if (isset($data['roles'])) {
            $user->roles()->sync(Role::whereIn('name', $data['roles'])->pluck('id'));
        }

        Audit::log('user.updated', $user);

        return response()->json([
            'data' => new UserResource($user->fresh()->load('department', 'roles.permissions')),
        ]);
    }

    public function toggleActive(Request $request, User $user): JsonResponse
    {
        // Route-gated by permission:owner.manage_status.
        $user->update(['is_active' => ! $user->is_active]);
        if (! $user->is_active) {
            $user->tokens()->delete();
        }
        Audit::log('user.toggle_active', $user, ['is_active' => $user->is_active]);

        return response()->json([
            'data' => new UserResource($user->fresh()->load('department', 'roles.permissions')),
        ]);
    }
}
