<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Check system health including database and redis connectivity.
     */
    public function check(): JsonResponse
    {
        $startTime = microtime(true);
        $status = 'healthy';
        $services = [];

        // Check PostgreSQL Database
        try {
            $dbStart = microtime(true);
            $pgVersion = DB::select('SELECT version()')[0]->version ?? 'unknown';
            $dbLatency = round((microtime(true) - $dbStart) * 1000, 2);

            $services['database'] = [
                'status' => 'connected',
                'engine' => 'PostgreSQL',
                'latency_ms' => $dbLatency,
                'version' => $pgVersion,
            ];
        } catch (Throwable $e) {
            $status = 'degraded';
            $services['database'] = [
                'status' => 'disconnected',
                'error' => $e->getMessage(),
            ];
        }

        // Check Redis Cache / Queue
        try {
            $redisStart = microtime(true);
            $redisResponse = Redis::ping();
            $redisLatency = round((microtime(true) - $redisStart) * 1000, 2);
            $isPong = $redisResponse === true || strtoupper(trim((string) $redisResponse)) === 'PONG';

            $services['redis'] = [
                'status' => $isPong ? 'connected' : 'unknown',
                'latency_ms' => $redisLatency,
                'response' => (string) $redisResponse,
            ];

            if (!$isPong) {
                $status = 'degraded';
            }
        } catch (Throwable $e) {
            $status = 'degraded';
            $services['redis'] = [
                'status' => 'disconnected',
                'error' => $e->getMessage(),
            ];
        }

        $totalLatency = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'platform' => 'TransactIQ Core Infrastructure',
            'version' => '1.0.0',
            'environment' => config('app.env'),
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'total_latency_ms' => $totalLatency,
            'services' => $services,
        ], $status === 'healthy' ? 200 : 503);
    }
}
