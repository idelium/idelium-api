<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
        // Let's clear the users table first
        User::truncate();

        $faker = \Faker\Factory::create();

        // Let's make sure everyone has the same password and 
        // let's hash it before the loop, or else our seeder 
        // will be too slow.
        $password = bcrypt('admin');

        User::create([
            'name' => 'Administrator',
            'email' => 'admin@idelium.io',
            'email_verified_at' => time(),
            'password' => $password,
            'role' => 1,
            'idCostumer' => 1,
        ]);

    }
}