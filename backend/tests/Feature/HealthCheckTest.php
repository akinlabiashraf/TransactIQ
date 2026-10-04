<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Test the health check endpoint returns 200 and connected services.
     */
    public function test_health_check_returns_successful_status(): void
    {
        Redis::shouldReceive('ping')->andReturn('PONG');

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'platform',
                'version',
                'environment',
                'status',
                'timestamp',
                'total_latency_ms',
                'services' => [
                    'database' => [
                        'status',
                        'engine',
                        'latency_ms',
                        'version',
                    ],
                    'redis' => [
                        'status',
                        'latency_ms',
                        'response',
                    ],
                ],
            ])
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('services.database.status', 'connected')
            ->assertJsonPath('services.database.engine', 'PostgreSQL')
            ->assertJsonPath('services.redis.status', 'connected');
    }
}
