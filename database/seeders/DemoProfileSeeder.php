<?php

namespace Database\Seeders;

use App\Library\ApiKey;
use App\Models\Costumer;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoProfileSeeder extends Seeder
{
    public function run()
    {
        $email = env('IDELIUM_DEMO_EMAIL');
        $password = env('IDELIUM_DEMO_PASSWORD');
        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Demo seeding requires IDELIUM_DEMO_EMAIL and IDELIUM_DEMO_PASSWORD.');
        }

        $costumer = Costumer::where('costumer', 'demo')->first();
        if ($costumer === null) {
            $costumer = new Costumer;
            $costumer->forceFill([
                'costumer' => 'demo',
                'description' => 'Demo Costumer',
                'logo' => '[]',
                'apiKey' => (new ApiKey)->generateApiSignature(),
                'licenseExpiration' => now()->addYear(),
            ])->save();
        }
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => 'Demo User',
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'role' => 2,
            'idCostumer' => $costumer->id,
        ])->save();
        $project = Project::where('name', 'demo')->where('idCostumer', $costumer->id)->first();
        if ($project === null) {
            $project = new Project;
            $project->forceFill([
                'name' => 'demo',
                'idCostumer' => $costumer->id,
                'description' => 'Demo Project',
            ])->save();
        }

        $environment = Environment::where('code', 'demo')
            ->where('idProject', $project->id)
            ->where('idCostumer', $costumer->id)
            ->first();
        if ($environment === null) {
            $environment = new Environment;
            $environment->forceFill([
                'code' => 'demo',
                'idProject' => $project->id,
                'idCostumer' => $costumer->id,
                'description' => 'Demo Environment',
                'config' => '{"base_url":"","url":"","xpath_check_url":"","userAgent":"","browser":"chrome","accept_self_certificate":true}',
            ])->save();
        }
    }
}
