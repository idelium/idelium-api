<?php

namespace App\Services;

class TestToolResultPayloadPolicy
{
    private const REDACTED_VALUE = '[REDACTED]';

    private const REDACTED_BODY = '[REDACTED BODY]';

    private const SENSITIVE_KEYS = [
        'api-key',
        'apikey',
        'api_key',
        'access-token',
        'access_token',
        'authorization',
        'cookie',
        'id-token',
        'id_token',
        'key',
        'password',
        'refresh-token',
        'refresh_token',
        'secret',
        'session',
        'sessionid',
        'set-cookie',
        'token',
        'x-api-key',
    ];

    private const BODY_KEYS = [
        'body',
        'requestBody',
        'response',
        'responseBody',
    ];

    public function validateArtifactPolicy(mixed $payload): ?string
    {
        $decoded = $this->decodePayload($payload);
        if ($decoded === null) {
            return null;
        }

        return $this->validateNode($decoded);
    }

    public function redactJsonString(string $payload): string
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return $payload;
        }

        return json_encode($this->redactNode($decoded));
    }

    public function redactJsonValue(mixed $payload): mixed
    {
        $decoded = $this->decodePayload($payload);
        if ($decoded === null) {
            return $payload;
        }

        return $this->redactNode($decoded);
    }

    private function validateNode(mixed $node): ?string
    {
        if (! is_array($node)) {
            return null;
        }

        $artifactCount = $this->countArtifactNodes($node);
        if ($artifactCount > (int) config('idelium.artifact_collection_max_items')) {
            return 'The result payload contains too many artifacts.';
        }

        foreach ($node as $key => $value) {
            if ($this->isInlineArtifactKey((string) $key)
                && is_string($value)
                && strlen($value) > (int) config('idelium.artifact_inline_max_bytes')) {
                return 'The result payload contains an inline artifact that exceeds the allowed size.';
            }

            $nestedError = $this->validateNode($value);
            if ($nestedError !== null) {
                return $nestedError;
            }
        }

        return null;
    }

    private function redactNode(mixed $node, ?string $parentKey = null): mixed
    {
        if (! is_array($node)) {
            return $this->redactScalar($node, $parentKey);
        }

        $redacted = [];
        foreach ($node as $key => $value) {
            $keyString = (string) $key;
            $normalizedKey = $this->normalizeKey($keyString);

            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                $redacted[$key] = self::REDACTED_VALUE;

                continue;
            }

            if (in_array($normalizedKey, $this->normalizedBodyKeys(), true)) {
                $redacted[$key] = $this->redactBodyValue($value);

                continue;
            }

            if ($normalizedKey === 'url' && is_string($value)) {
                $redacted[$key] = $this->redactUrl($value);

                continue;
            }

            $redacted[$key] = $this->redactNode($value, $keyString);
        }

        return $redacted;
    }

    private function redactBodyValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redactNode($value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return json_encode($this->redactNode($decoded));
        }

        return $this->redactPlainTextBody($value);
    }

    private function redactPlainTextBody(string $value): string
    {
        $redacted = preg_replace(
            '/\b(api[-_\s]?key|access[-_\s]?token|authorization|cookie|id[-_\s]?token|password|refresh[-_\s]?token|secret|session|sessionid|token|x[-_\s]?api[-_\s]?key)\s*([:=])\s*([^&\s,;]+)/i',
            '$1$2'.self::REDACTED_VALUE,
            $value
        );

        $redacted ??= $value;

        return preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/=-]+/i', '$1'.self::REDACTED_VALUE, $redacted) ?? $redacted;
    }

    private function redactScalar(mixed $value, ?string $parentKey): mixed
    {
        if (! is_string($value) || $parentKey === null) {
            return $value;
        }

        if ($this->normalizeKey($parentKey) === 'url') {
            return $this->redactUrl($value);
        }

        return $value;
    }

    private function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);
        foreach (array_keys($query) as $key) {
            if (in_array($this->normalizeKey((string) $key), self::SENSITIVE_KEYS, true)) {
                $query[$key] = self::REDACTED_VALUE;
            }
        }

        $redactedQuery = http_build_query($query);
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.$host.$port.$path.($redactedQuery === '' ? '' : '?'.$redactedQuery).$fragment;
    }

    private function countArtifactNodes(array $node): int
    {
        $count = 0;
        foreach ($node as $key => $value) {
            if ((string) $key === 'artifacts' && is_array($value)) {
                $count += count($value);
            }

            if (is_array($value)) {
                $count += $this->countArtifactNodes($value);
            }
        }

        return $count;
    }

    private function isInlineArtifactKey(string $key): bool
    {
        return in_array($key, ['content', 'base64', 'dataUri', 'video', 'screenshot'], true);
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['_', ' '], '-', $key));
    }

    private function normalizedBodyKeys(): array
    {
        return array_map(fn (string $key) => $this->normalizeKey($key), self::BODY_KEYS);
    }

    private function decodePayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
