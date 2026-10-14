<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Only local and testing get the demo data (everyone's password is
     * "password", run it with `migrate:fresh --seed`); every other environment gets only the admin.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->seedProductionAdmin();

            return;
        }

        $this->call([
            UserSeeder::class,
            EventSeeder::class,
            OrderSeeder::class,
        ]);
    }

    /**
     * Create the single production admin with the password from SEED_ADMIN_PASSWORD.
     * An existing admin@example.com is left untouched, so running it again never resets a changed password.
     * Factories are not used because Faker is not installed in production.
     */
    private function seedProductionAdmin(): void
    {
        $password = (string) config('app.seed_admin_password');

        if ($password === '') {
            throw new RuntimeException('本番の管理者のパスワード（SEED_ADMIN_PASSWORD）が設定されていません。');
        }

        if (User::where('email', 'admin@example.com')->exists()) {
            return;
        }

        $admin = new User;
        $admin->email = 'admin@example.com';
        $admin->name = '管理者';
        $admin->password = Hash::make($password);
        $admin->role = UserRole::Admin;
        $admin->save();

        if (! $admin->hasVerifiedEmail()) {
            $admin->markEmailAsVerified();
        }
    }
}
