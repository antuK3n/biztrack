<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Create the super admin on a fresh production register.
 *
 * Production starts with reference data only (ProductionSeeder): no demo
 * accounts, and so nobody who can sign in at /admin/login. This makes the one
 * account that can, asked for on the server itself. The password is typed at
 * a hidden prompt, so it never sits in shell history, a chat, or a seeder.
 * Every other account (office admins, officers) is then made by this super
 * admin in the app.
 *
 * There is only ever one super admin (checklist, Office Admin Login 1), so this
 * refuses while one exists. Replacing that person is a job for the app, not
 * for a command that could quietly add a second.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'biztrack:create-super-admin';

    protected $description = 'Create the single super admin account on a fresh register (asks for the password at a hidden prompt)';

    public function handle(): int
    {
        $role = Role::where('name', 'admin')->first();
        if ($role === null) {
            $this->error('The roles are not seeded yet. Run: php artisan db:seed --class=ProductionSeeder --force');

            return self::FAILURE;
        }

        if ($role->users()->exists()) {
            $this->error('A super admin already exists. There is only ever one; change who it is from inside the app.');

            return self::FAILURE;
        }

        $data = [
            'first_name' => trim((string) $this->ask('First name')),
            'last_name' => trim((string) $this->ask('Last name')),
            'email' => strtolower(trim((string) $this->ask('E-mail (used to sign in)'))),
            'password' => (string) $this->secret('Password (not shown as you type)'),
            'password_confirmation' => (string) $this->secret('Password again'),
        ];

        $validator = Validator::make($data, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'name' => "{$data['first_name']} {$data['last_name']}",
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $user->roles()->sync([$role->id]);

            return $user;
        });

        Audit::log('user.super_admin_created', $user, ['via' => 'artisan']);

        $this->info("Super admin created: {$user->email}. Sign in at /admin/login.");

        return self::SUCCESS;
    }
}
