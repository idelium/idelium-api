<?php

namespace App\Library;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TestLauncher
{
    public function launch($host, $browser, $idTestCycle, $idProject, $environment, $key)
    {
        $endpoint = $this->endpoint($host);
        $data = [
            'idTestCycle' => $idTestCycle,
            'idProject' => $idProject,
            'environment' => $environment,
            'browser' => $browser,
            'key' => $key,
            'host' => $host,
        ];

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout($this->positiveTimeout('connect_timeout'))
                ->timeout($this->positiveTimeout('timeout'))
                ->withOptions([
                    'verify' => $this->tlsVerification(),
                ])
                ->send('GET', $endpoint, [
                    'json' => $data,
                ]);
        } catch (ConnectionException) {
            throw new TestLauncherException(
                'launcher_connection_failed',
                'The remote launcher connection failed. Verify its TLS certificate and the configured CA bundle.',
                502
            );
        }

        if (! $response->successful()) {
            throw new TestLauncherException(
                'launcher_upstream_error',
                'The remote launcher rejected the request.',
                502
            );
        }

        return $response->body();
    }

    /**
     * Resolve the certificate verification mode used by the remote launcher.
     */
    public function tlsVerification(): bool|string
    {
        if ((bool) config('idelium.launcher.insecure', false)) {
            if (! app()->environment(['local', 'testing'])) {
                throw new TestLauncherException(
                    'launcher_invalid_tls_configuration',
                    'TLS verification cannot be disabled outside a development environment.',
                    500
                );
            }

            return false;
        }

        $caBundle = trim((string) config('idelium.launcher.ca_bundle', ''));
        if ($caBundle === '') {
            return true;
        }

        if (! is_file($caBundle) || ! is_readable($caBundle)) {
            throw new TestLauncherException(
                'launcher_invalid_tls_configuration',
                'The configured remote launcher CA bundle is not readable.',
                500
            );
        }

        return $caBundle;
    }

    private function endpoint(string $host): string
    {
        $parts = parse_url($host);
        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new TestLauncherException(
                'launcher_invalid_endpoint',
                'The remote launcher endpoint must be a valid HTTPS URL without credentials, query parameters, or fragments.',
                422
            );
        }

        return rtrim($host, '/').'/launchtest';
    }

    private function positiveTimeout(string $name): float
    {
        $timeout = (float) config('idelium.launcher.'.$name);
        if ($timeout <= 0) {
            throw new TestLauncherException(
                'launcher_invalid_configuration',
                'Remote launcher timeouts must be greater than zero.',
                500
            );
        }

        return $timeout;
    }
}
