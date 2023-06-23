<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Status;

class StatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Status::truncate();

        $faker = \Faker\Factory::create();

        Status::create([
            'name' => 'suspended',
        ]);
        Status::create([
            'name' => 'free',
        ]);
        Status::create([
            'name' => 'busy',
        ]);
        Status::create([
            'name' => 'mantainence',
        ]);
    }
        //
}
