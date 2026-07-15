<?php

namespace Database\Seeders;

use App\Models\Role;
use Faker\Factory;
use Illuminate\Database\Seeder;

class RolesTableSeeder extends Seeder
{
    public function run()
    {
        // Let's clear the users table first
        Role::truncate();

        $faker = Factory::create();

        Role::create([
            'name' => 'superadmin',
        ]);
        Role::create([
            'name' => 'admin',
        ]);
        Role::create([
            'name' => 'user',
        ]);

    }
}
