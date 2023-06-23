<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Type;


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

        $faker = \Faker\Factory::create();

        Type::create([
            'name' => 'desktop',
        ]);
        Type::create([
            'name' => 'mobile devices',
        ]);
    }
}
