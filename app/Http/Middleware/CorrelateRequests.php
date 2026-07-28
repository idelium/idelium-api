<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelateRequests
{
    public const ATTRIBUTE = 'correlationId';

    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, self::correlationId($request));

        $response = $next($request);
        $response->headers->set(self::HEADER, self::correlationId($request));

        return $response;
    }

    public static function correlationId(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);
        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }

        $header = $request->headers->get(self::HEADER);
        if (is_string($header) && Str::isUuid($header)) {
            return $header;
        }

        $generated = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $generated);

        return $generated;
    }
}
