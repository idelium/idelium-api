<?php

namespace Database\Seeders;

use App\Models\Type;
use Faker\Factory;
use Illuminate\Database\Seeder;

class TypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        Type::truncate();

        $faker = Factory::create();

        Type::create([
            'name' => 'desktop',
        ]);
        Type::create([
            'name' => 'mobile devices',
        ]);
    }
}
