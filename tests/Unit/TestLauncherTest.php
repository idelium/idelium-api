<?php

namespace Tests\Unit;

use App\Library\TestLauncher;
use App\Library\TestLauncherException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestLauncherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'idelium.launcher.ca_bundle' => null,
            'idelium.launcher.connect_timeout' => 5,
            'idelium.launcher.timeout' => 30,
            'idelium.launcher.insecure' => false,
        ]);
    }

    public function test_tls_verification_is_enabled_by_default(): void
    {
        $this->assertTrue((new TestLauncher)->tlsVerification());
    }

    public function test_custom_ca_bundle_is_used_when_readable(): void
    {
        $caBundle = tempnam(sys_get_temp_dir(), 'idelium-ca-');
        file_put_contents($caBundle, 'test certificate bundle');
        config(['idelium.launcher.ca_bundle' => $caBundle]);

        try {
            $this->assertSame($caBundle, (new TestLauncher)->tlsVerification());
        } finally {
            unlink($caBundle);
        }
    }

    public function test_unreadable_ca_bundle_fails_safely(): void
    {
        config(['idelium.launcher.ca_bundle' => '/missing/idelium-ca.pem']);

        $this->expectException(TestLauncherException::class);
        $this->expectExceptionMessage('CA bundle is not readable');

        (new TestLauncher)->tlsVerification();
    }

    public function test_explicit_insecure_mode_is_limited_to_development(): void
    {
        config(['idelium.launcher.insecure' => true]);

        $this->assertFalse((new TestLauncher)->tlsVerification());

        $this->app['env'] = 'production';
        $this->expectException(TestLauncherException::class);
        $this->expectExceptionMessage('cannot be disabled outside');

        (new TestLauncher)->tlsVerification();
    }

    public function test_launcher_preserves_the_legacy_request_contract_over_https(): void
    {
        Http::fake([
            'https://runner.example.test/launchtest' => Http::response('accepted', 200),
        ]);

        $result = (new TestLauncher)->launch(
            'https://runner.example.test',
            'chrome',
            12,
            34,
            'staging',
            'sensitive-key'
        );

        $this->assertSame('accepted', $result);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://runner.example.test/launchtest'
            && $request['idTestCycle'] === 12
            && $request['idProject'] === 34
            && $request['environment'] === 'staging'
            && $request['browser'] === 'chrome');
    }

    public function test_plain_http_launcher_endpoint_is_rejected(): void
    {
        Http::preventStrayRequests();

        try {
            (new TestLauncher)->launch(
                'http://runner.example.test',
                'chrome',
                12,
                34,
                'staging',
                'sensitive-key'
            );
            $this->fail('The insecure launcher endpoint should have been rejected.');
        } catch (TestLauncherException $exception) {
            $this->assertSame('launcher_invalid_endpoint', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
            $this->assertStringNotContainsString('sensitive-key', $exception->getMessage());
        }
    }

    public function test_upstream_errors_do_not_expose_response_bodies_or_credentials(): void
    {
        Http::fake([
            'https://runner.example.test/launchtest' => Http::response(
                'secret upstream response',
                502
            ),
        ]);

        try {
            (new TestLauncher)->launch(
                'https://runner.example.test',
                'chrome',
                12,
                34,
                'staging',
                'sensitive-key'
            );
            $this->fail('The upstream error should have been classified.');
        } catch (TestLauncherException $exception) {
            $this->assertSame('launcher_upstream_error', $exception->errorCode);
            $this->assertStringNotContainsString('secret upstream response', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-key', $exception->getMessage());
        }
    }

    public function test_connection_errors_are_classified_without_transport_details(): void
    {
        Http::fake(function () {
            throw new ConnectionException('certificate failure with sensitive detail');
        });

        try {
            (new TestLauncher)->launch(
                'https://runner.example.test',
                'chrome',
                12,
                34,
                'staging',
                'sensitive-key'
            );
            $this->fail('The connection error should have been classified.');
        } catch (TestLauncherException $exception) {
            $this->assertSame('launcher_connection_failed', $exception->errorCode);
            $this->assertStringNotContainsString('sensitive detail', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-key', $exception->getMessage());
        }
    }
}
