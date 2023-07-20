<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Library\ApiKey;
use App\Models\User;
use App\Models\Costumer;
use App\Models\Project;
use App\Models\Environment;

class DemoProfileSeeder extends Seeder
{
    public function run()
    {
        // Let's clear the users table first

        $faker = \Faker\Factory::create();

        $password = bcrypt('demo');
        $apiKey=new ApiKey;
        User::create([
            'name' => 'Demo User',
            'email' => 'demo@idelium.io',
            'email_verified_at' => time(),
            'password' => $password,
            'role' => 2,
            'idCostumer' => 1,
        ]);
        Costumer::create([
            'costumer' => 'demo',
            'description' => 'Demo Costumer',
            'logo' => "[]",
            'apiKey' => $apiKey->generateApiSignature(),
            'licenseExpiration' => date('Y-m-d H:i:s', strtotime('+365 day', time()))
        ]);
        Project::create([
            'name' => 'demo',
            'description' => 'Demo Project',
            'idCostumer' => 1,
        ]);
        
        Environment::create([
            'code' => 'demo',
            'description' => 'Demo Environment',
            'config' => "{\"base_url\":\"\",\"url\":\"\",\"xpath_check_url\":\"\",\"userAgent\":\"\",\"browser\":\"chrome\",\"accept_self_certificate\":true}",
            'idProject' => 1,
            'idCostumer' => 1,
        ]);

    }
}
