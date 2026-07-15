<?php

namespace Database\Seeders;

use App\Models\Browser;
use Faker\Factory;
use Illuminate\Database\Seeder;

class VersionBrowserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Browser::truncate();

        $faker = Factory::create();

        // Let's make sure everyone has the same password and
        // let's hash it before the loop, or else our seeder
        // will be too slow.

        //
    }
}
