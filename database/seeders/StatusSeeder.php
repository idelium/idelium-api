<?php

namespace Database\Seeders;

use App\Models\Status;
use Faker\Factory;
use Illuminate\Database\Seeder;

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

        $faker = Factory::create();

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
