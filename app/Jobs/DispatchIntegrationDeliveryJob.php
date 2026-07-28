<?php

namespace App\Jobs;

use App\Models\AuditEvent;
use App\Models\IntegrationDelivery;
use App\Models\IntegrationEndpoint;
use App\Services\IntegrationEndpointService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DispatchIntegrationDeliveryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $integrationDeliveryId
    ) {}

    public function handle(IntegrationEndpointService $integrations): void
    {
        $delivery = IntegrationDelivery::query()
            ->with('endpoint')
            ->find($this->integrationDeliveryId);

        if (! $delivery instanceof IntegrationDelivery) {
            return;
        }

        if ($delivery->status === IntegrationDelivery::STATUS_SENT) {
            return;
        }

        $endpoint = $delivery->endpoint;
        if (! $endpoint instanceof IntegrationEndpoint || $endpoint->status !== IntegrationEndpoint::STATUS_ACTIVE) {
            $this->markFailure($delivery, null, 'The integration endpoint is not active.');

            return;
        }

        try {
            $body = json_encode(
                $integrations->adapterPayload($endpoint, $delivery, $delivery->payload ?? []),
                JSON_THROW_ON_ERROR
            );
            $headers = $integrations->signatureHeaders($endpoint, $delivery, $body);
            $response = Http::timeout((int) config('integrations.timeout_seconds', 5))
                ->withHeaders($headers)
                ->send('POST', $endpoint->url, [
                    'body' => $body,
                ]);

            $delivery->attempts++;
            $delivery->responseStatus = $response->status();

            if ($response->successful()) {
                $delivery->status = IntegrationDelivery::STATUS_SENT;
                $delivery->sentAt = now();
                $delivery->lastError = null;
                $delivery->nextAttemptAt = null;
                $delivery->save();
                $this->audit($delivery, AuditEvent::RESULT_SUCCESS, [
                    'responseStatus' => $response->status(),
                    'attempts' => $delivery->attempts,
                ]);

                return;
            }

            $this->markFailure($delivery, $response->status(), 'Webhook delivery returned a non-success status.');
        } catch (Throwable $exception) {
            $this->markFailure($delivery, null, $exception->getMessage());
        }
    }

    public function integrationDeliveryId(): int
    {
        return $this->integrationDeliveryId;
    }

    private function markFailure(IntegrationDelivery $delivery, ?int $responseStatus, string $reason): void
    {
        $delivery->attempts++;
        $delivery->responseStatus = $responseStatus;
        $delivery->lastError = Str::limit($reason, 1000, '');

        $maxAttempts = (int) config('integrations.max_attempts', 3);
        if ($delivery->attempts >= $maxAttempts) {
            $delivery->status = IntegrationDelivery::STATUS_DEAD_LETTER;
            $delivery->nextAttemptAt = null;
        } else {
            $delivery->status = IntegrationDelivery::STATUS_FAILED;
            $backoff = config('integrations.retry_backoff_seconds', [60]);
            $delay = $backoff[min($delivery->attempts - 1, count($backoff) - 1)] ?? 60;
            $delivery->nextAttemptAt = now()->addSeconds((int) $delay);
        }

        $delivery->save();
        $this->audit($delivery, AuditEvent::RESULT_FAILURE, [
            'responseStatus' => $responseStatus,
            'attempts' => $delivery->attempts,
            'nextAttemptAt' => optional($delivery->nextAttemptAt)->toISOString(),
            'reason' => $delivery->lastError,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(IntegrationDelivery $delivery, string $result, array $metadata): void
    {
        AuditEvent::create([
            'actorUserId' => null,
            'actorTenantId' => $delivery->idCostumer,
            'activeTenantId' => $delivery->idCostumer,
            'idProject' => $delivery->idProject,
            'action' => 'integration_delivery.dispatch',
            'targetType' => 'integration_delivery',
            'targetId' => (string) $delivery->id,
            'beforeValues' => null,
            'afterValues' => [
                'status' => $delivery->status,
                'deliveryId' => $delivery->deliveryId,
            ],
            'result' => $result,
            'sourceIp' => null,
            'correlationId' => (string) Str::uuid(),
            'metadata' => array_merge($metadata, [
                'job' => self::class,
            ]),
        ]);
    }
}
