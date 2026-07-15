<?php

namespace Database\Seeders;

use App\Models\Status;
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
        foreach (['suspended', 'free', 'busy', 'mantainence'] as $name) {
            Status::firstOrCreate(['name' => $name]);
        }
    }
    //
}
