<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class DocsController extends Controller
{
    /**
     * Render the interactive Scalar API Reference console.
     */
    public function index(): Response
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>TransactIQ — Core Infrastructure API Documentation</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.ico" />
    <style>
      body {
        margin: 0;
        padding: 0;
        background-color: #0b0f19;
      }
    </style>
  </head>
  <body>
    <script
      id="api-reference"
      data-url="/docs/openapi.json"
      data-proxy-url="https://proxy.scalar.com"
      data-theme="deepSpace"
      data-layout="modern"
      src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"
    ></script>
  </body>
</html>
HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Serve the raw OpenAPI 3.0 specification JSON file.
     */
    public function openapi(): JsonResponse
    {
        $path = public_path('docs/openapi.json');
        if (!file_exists($path)) {
            return response()->json(['error' => 'OpenAPI specification file not found.'], 404);
        }

        $content = json_decode((string) file_get_contents($path), true);

        return response()->json($content);
    }
}
