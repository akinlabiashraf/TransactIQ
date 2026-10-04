<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocsAndApiSpecTest extends TestCase
{
    public function test_root_url_redirects_to_interactive_docs(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/docs');
    }

    public function test_docs_html_endpoint_serves_interactive_scalar_interface(): void
    {
        $response = $this->get('/docs');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertStringContainsString('TransactIQ — Core Infrastructure API Documentation', $response->getContent());
        $this->assertStringContainsString('data-url="/docs/openapi.json"', $response->getContent());
    }

    public function test_openapi_json_endpoint_serves_valid_specification(): void
    {
        $response = $this->getJson('/docs/openapi.json');

        $response->assertStatus(200);
        $response->assertJsonPath('openapi', '3.0.3');
        $response->assertJsonPath('info.title', 'TransactIQ Core Infrastructure API');
        $response->assertJsonStructure([
            'openapi',
            'info' => ['title', 'version', 'description'],
            'paths' => [
                '/health',
                '/auth/login',
                '/payments',
                '/ledger/integrity',
                '/webhooks',
                '/analytics/summary',
            ],
            'components' => [
                'securitySchemes',
                'schemas',
            ],
        ]);
    }

    public function test_api_v1_gateway_serves_openapi_json(): void
    {
        $response = $this->getJson('/api/v1/docs/openapi.json');

        $response->assertStatus(200);
        $response->assertJsonPath('openapi', '3.0.3');
    }
}
