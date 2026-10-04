<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignCorrelationId
{
    /**
     * Handle an incoming request, assigning a unique correlation ID and logging structured telemetry.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Resolve or generate a unique correlation ID (UUIDv4)
        $correlationId = $request->header('X-Correlation-ID')
            ?: $request->header('X-Request-ID')
            ?: (string) Str::uuid();

        // 2. Bind into request headers and Laravel Context for consistent structured logging
        $request->headers->set('X-Correlation-ID', $correlationId);
        if (class_exists(Context::class)) {
            Context::add('correlation_id', $correlationId);
        }

        $startTime = hrtime(true);

        // 3. Process the HTTP request pipeline
        /** @var Response $response */
        $response = $next($request);

        $durationMs = round((hrtime(true) - $startTime) / 1e6, 2);

        // 4. Attach correlation ID to response headers
        $response->headers->set('X-Correlation-ID', $correlationId);

        // 5. Emit structured JSON telemetry log
        Log::info('HTTP_ACCESS_TELEMETRY', [
            'correlation_id' => $correlationId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return $response;
    }
}
