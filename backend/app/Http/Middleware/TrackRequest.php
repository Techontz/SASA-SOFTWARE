<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Structured observability: every API request carries a correlation id that
 * appears in the response, in the logs and on every audit row it produced.
 */
class TrackRequest
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $request->header('X-Sasa-Request-Id') ?: (string) Str::uuid();
        $request->attributes->set('sasa_request_id', $requestId);
        $request->attributes->set('sasa_device_id', substr((string) $request->header('X-Sasa-Device-Id', ''), 0, 64));

        Log::withContext([
            'request_id' => $requestId,
            'device_id' => $request->attributes->get('sasa_device_id') ?: null,
        ]);

        $startedAt = microtime(true);
        $response = $next($request);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $response->headers->set('X-Sasa-Request-Id', $requestId);

        if ($response->getStatusCode() >= 500 || $durationMs > 2000) {
            Log::warning('api.request', [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'user_id' => optional($request->user())->id,
            ]);
        }

        return $response;
    }
}
