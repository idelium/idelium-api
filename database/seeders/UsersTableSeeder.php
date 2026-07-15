<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
        $email = env('IDELIUM_ADMIN_EMAIL');
        $password = env('IDELIUM_ADMIN_PASSWORD');
        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Base seeding requires IDELIUM_ADMIN_EMAIL and IDELIUM_ADMIN_PASSWORD.');
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => 'Administrator',
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'role' => 1,
            'idCostumer' => 1,
        ])->save();
    }
}
