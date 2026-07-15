<?php

namespace Database\Seeders;

use App\Models\Type;
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
        foreach (['desktop', 'mobile devices'] as $name) {
            Type::firstOrCreate(['name' => $name]);
        }
    }
}
