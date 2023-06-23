<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RolesTableSeeder extends Seeder
{
    public function run()
    {
        // Let's clear the users table first
        Role::truncate();

        $faker = \Faker\Factory::create();

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