<?php

namespace App\Services;

use App\Http\Middleware\CorrelateRequests;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\AuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditEventService
{
    /**
     * @param array<string, mixed>|null $beforeValues
     * @param array<string, mixed>|null $afterValues
     * @param array<string, mixed>|null $metadata
     */
    public function record(
        Request $request,
        string $action,
        string $targetType,
        ?string $targetId = null,
        string $result = AuditEvent::RESULT_SUCCESS,
        ?array $beforeValues = null,
        ?array $afterValues = null,
        ?int $projectId = null,
        ?array $metadata = null,
        bool $failSafe = true,
    ): ?AuditEvent {
        try {
            $context = $request->attributes->get(ResolveTenantContext::ATTRIBUTE);
            if (! $context instanceof TenantContext) {
                throw new \LogicException('Tenant context is required for audit events.');
            }

            return AuditEvent::create([
                'actorUserId' => $context->actorUserId,
                'actorTenantId' => $context->actorTenantId,
                'activeTenantId' => $context->activeTenantId,
                'idProject' => $projectId,
                'action' => $action,
                'targetType' => $targetType,
                'targetId' => $targetId,
                'beforeValues' => $this->redact($beforeValues),
                'afterValues' => $this->redact($afterValues),
                'result' => $result,
                'sourceIp' => $request->ip(),
                'correlationId' => CorrelateRequests::correlationId($request),
                'metadata' => $this->redact($metadata),
            ]);
        } catch (Throwable $exception) {
            Log::error('Audit event persistence failed.', [
                'action' => $action,
                'targetType' => $targetType,
                'targetId' => $targetId,
                'error' => $exception->getMessage(),
            ]);

            if ($failSafe && (bool) config('audit.fail_safe', true)) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $values
     * @return array<string, mixed>|null
     */
    public function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $redacted = [];
        foreach ($values as $key => $value) {
            $redacted[$key] = $this->isSensitiveKey((string) $key)
                ? '[REDACTED]'
                : $this->redactValue($value);
        }

        return $redacted;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redact($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/password|passwd|secret|token|apikey|api_key|authorization|cookie|credential|session/i',
            $key
        ) === 1;
    }
}
