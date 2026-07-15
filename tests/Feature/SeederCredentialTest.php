<?php

namespace Tests\Feature;

use Database\Seeders\DemoProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederCredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('IDELIUM_DEMO_EMAIL');
        putenv('IDELIUM_DEMO_PASSWORD');
        unset($_ENV['IDELIUM_DEMO_EMAIL'], $_ENV['IDELIUM_DEMO_PASSWORD']);
        parent::tearDown();
    }

    public function test_demo_seeder_requires_runtime_credentials(): void
    {
        putenv('IDELIUM_DEMO_EMAIL');
        putenv('IDELIUM_DEMO_PASSWORD');
        unset($_ENV['IDELIUM_DEMO_EMAIL'], $_ENV['IDELIUM_DEMO_PASSWORD']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Demo seeding requires');

        (new DemoProfileSeeder)->run();
    }

    public function test_demo_seeder_is_idempotent_with_runtime_credentials(): void
    {
        putenv('IDELIUM_DEMO_EMAIL=demo-user@example.invalid');
        putenv('IDELIUM_DEMO_PASSWORD=generated-test-password');
        $_ENV['IDELIUM_DEMO_EMAIL'] = 'demo-user@example.invalid';
        $_ENV['IDELIUM_DEMO_PASSWORD'] = 'generated-test-password';

        (new DemoProfileSeeder)->run();
        (new DemoProfileSeeder)->run();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('costumers', 1);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('environments', 1);
    }
}
