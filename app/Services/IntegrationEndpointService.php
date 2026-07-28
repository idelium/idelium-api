<?php

namespace App\Services;

use App\Jobs\DispatchIntegrationDeliveryJob;
use App\Models\IntegrationDelivery;
use App\Models\IntegrationEndpoint;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IntegrationEndpointService
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(int $tenantId, int $projectId, array $attributes): IntegrationEndpoint
    {
        $this->assertProjectScope($tenantId, $projectId);
        $this->assertAdapter($attributes['adapter']);
        $this->assertSafeDestination($attributes['url']);

        return IntegrationEndpoint::create([
            'idCostumer' => $tenantId,
            'idProject' => $projectId,
            'name' => $attributes['name'],
            'adapter' => $attributes['adapter'],
            'url' => $attributes['url'],
            'secretEncrypted' => Crypt::encryptString($attributes['secret']),
            'events' => array_values(array_unique($attributes['events'] ?? ['*'])),
            'status' => IntegrationEndpoint::STATUS_ACTIVE,
            'metadata' => [
                'schemaVersion' => config('integrations.schema_version'),
                'secretRotatedAt' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createDelivery(
        IntegrationEndpoint $endpoint,
        string $event,
        array $payload,
        ?string $idempotencyKey = null,
        bool $dispatch = true,
    ): IntegrationDelivery {
        if ($endpoint->status !== IntegrationEndpoint::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'endpoint' => ['The integration endpoint is disabled.'],
            ]);
        }

        if (! $endpoint->acceptsEvent($event)) {
            throw ValidationException::withMessages([
                'event' => ['The integration endpoint is not subscribed to this event.'],
            ]);
        }

        $redactedPayload = app(AuditEventService::class)->redact($payload) ?? [];
        $payloadDigest = hash('sha256', json_encode($redactedPayload, JSON_THROW_ON_ERROR));
        $idempotencyKey ??= $event.':'.$payloadDigest;

        $delivery = IntegrationDelivery::firstOrCreate([
            'idCostumer' => $endpoint->idCostumer,
            'idProject' => $endpoint->idProject,
            'integrationEndpointId' => $endpoint->id,
            'idempotencyKey' => $idempotencyKey,
        ], [
            'event' => $event,
            'deliveryId' => 'idwh_'.Str::uuid()->toString(),
            'schemaVersion' => config('integrations.schema_version'),
            'payloadDigest' => $payloadDigest,
            'status' => IntegrationDelivery::STATUS_PENDING,
            'payload' => $redactedPayload,
        ]);

        if ($dispatch && $delivery->wasRecentlyCreated) {
            DispatchIntegrationDeliveryJob::dispatch($delivery->id);
        }

        return $delivery;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function adapterPayload(IntegrationEndpoint $endpoint, IntegrationDelivery $delivery, array $data): array
    {
        $base = [
            'schemaVersion' => $delivery->schemaVersion,
            'event' => $delivery->event,
            'deliveryId' => $delivery->deliveryId,
            'tenantId' => $endpoint->idCostumer,
            'projectId' => $endpoint->idProject,
            'occurredAt' => Carbon::now()->toISOString(),
            'data' => $data,
        ];

        return match ($endpoint->adapter) {
            IntegrationEndpoint::ADAPTER_SLACK => [
                'text' => '[Idelium] '.$delivery->event,
                'idelium' => $base,
            ],
            IntegrationEndpoint::ADAPTER_TEAMS => [
                'type' => 'message',
                'text' => '[Idelium] '.$delivery->event,
                'idelium' => $base,
            ],
            IntegrationEndpoint::ADAPTER_JIRA => [
                'summary' => '[Idelium] '.$delivery->event,
                'description' => json_encode($base, JSON_THROW_ON_ERROR),
                'labels' => ['idelium'],
                'idelium' => $base,
            ],
            default => $base,
        };
    }

    /**
     * @return array<string, string>
     */
    public function signatureHeaders(IntegrationEndpoint $endpoint, IntegrationDelivery $delivery, string $body): array
    {
        $timestamp = (string) time();
        $secret = Crypt::decryptString($endpoint->secretEncrypted);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Idelium-Webhook/1.0',
            'Idelium-Delivery-Id' => $delivery->deliveryId,
            'Idelium-Event' => $delivery->event,
            'Idelium-Tenant-Id' => (string) $endpoint->idCostumer,
            'Idelium-Project-Id' => (string) $endpoint->idProject,
            'Idelium-Signature' => 't='.$timestamp.',v1='.$signature,
            'Idelium-Signature-Tolerance' => (string) config('integrations.signature_tolerance_seconds'),
            'Idelium-Schema-Version' => $delivery->schemaVersion,
        ];
    }

    public function assertProjectScope(int $tenantId, int $projectId): void
    {
        abort_unless(
            Project::query()
                ->whereKey($projectId)
                ->where('idCostumer', $tenantId)
                ->exists(),
            404
        );
    }

    private function assertAdapter(string $adapter): void
    {
        if (! in_array($adapter, config('integrations.allowed_adapters', []), true)) {
            throw ValidationException::withMessages([
                'adapter' => ['The integration adapter is not supported.'],
            ]);
        }
    }

    private function assertSafeDestination(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw ValidationException::withMessages([
                'url' => ['The integration destination must be an HTTP or HTTPS URL.'],
            ]);
        }

        if (in_array(strtolower($host), ['localhost'], true)) {
            throw ValidationException::withMessages([
                'url' => ['The integration destination cannot target localhost.'],
            ]);
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if ($ips === false || $ips === []) {
            throw ValidationException::withMessages([
                'url' => ['The integration destination host cannot be resolved safely.'],
            ]);
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw ValidationException::withMessages([
                    'url' => ['The integration destination cannot target private or reserved networks.'],
                ]);
            }
        }
    }
}
