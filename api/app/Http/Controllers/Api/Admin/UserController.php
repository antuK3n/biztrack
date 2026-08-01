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
    /**
     * The staff directory. Paginated, alphabetical.
     *
     * Alphabetical rather than newest-first on purpose: this is a directory you
     * look somebody up in, not a feed. 81 rows and 39 KB today, but the payload
     * carries every role and every permission per user, so it grows faster than
     * the row count suggests.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', 'string', 'max:60'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = User::with('department', 'roles.permissions')
            ->orderBy('name')
            ->orderBy('id');

        if ($q = $request->query('q')) {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }
        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($r) => $r->where('name', $role));
        }
        // Lets the officer picker on the review screen ask for one office's
        // staff instead of paging the whole directory to find them.
        if ($departmentId = $request->query('department_id')) {
            $query->where('department_id', $departmentId);
        }

        $users = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => $this->pageMeta($users),
        ]);
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

        /*
         * `password` is dropped unless one was actually typed.
         *
         * It used to go straight through this fill(). The rule is `nullable`, so
         * an edit form that always posts its password field — empty, because the
         * admin came to fix a typo in a surname — sends `password: ""`, and
         * ConvertEmptyStringsToNull turns that into null. The `hashed` cast
         * passes null through untouched, and `users.password` is NOT NULL, so
         * the whole edit died on a PDOException and the endpoint answered 500:
         * the surname change was lost and the message said nothing about
         * passwords. Where the column is nullable the same write succeeds and is
         * worse — a 200 that has locked the account out of every password it
         * will ever be given.
         */
        $writable = collect($data)->except(['roles', 'email']);
        if (! filled($data['password'] ?? null)) {
            $writable->forget('password');
        }
        $user->fill($writable->toArray());
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
