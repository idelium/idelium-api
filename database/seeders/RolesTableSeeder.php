<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesTableSeeder extends Seeder
{
    public function run()
    {
        foreach (['superadmin', 'admin', 'user'] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }
}
