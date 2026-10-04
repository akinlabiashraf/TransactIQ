<?php

namespace App\Services\Webhook;

class WebhookSignerService
{
    /**
     * Generate an HMAC-SHA256 signature for a webhook payload.
     *
     * @param string|array $payload Raw JSON string or associative array
     * @param string $secret Merchant webhook secret
     * @param int|null $timestamp Unix timestamp (defaults to current time)
     * @return array{header: string, timestamp: int, signature: string}
     */
    public function generateSignature(string|array $payload, string $secret, ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? time();
        $encodedPayload = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : $payload;

        // Signature signed over "timestamp.payload" to prevent replay attacks
        $signedPayload = "{$timestamp}.{$encodedPayload}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return [
            'header' => "t={$timestamp},v1={$signature}",
            'timestamp' => $timestamp,
            'signature' => $signature,
        ];
    }

    /**
     * Verify that an incoming webhook signature is valid and within timestamp tolerance.
     *
     * @param string $rawPayload Exact raw body of request
     * @param string $signatureHeader Header value (e.g. "t=1727078400,v1=abcdef...")
     * @param string $secret Merchant webhook secret
     * @param int $tolerance Maximum age in seconds to prevent replay attacks (default 300s = 5m)
     */
    public function verifySignature(
        string $rawPayload,
        string $signatureHeader,
        string $secret,
        int $tolerance = 300
    ): bool {
        $parsed = $this->parseSignatureHeader($signatureHeader);
        if (!$parsed) {
            return false;
        }

        $timestamp = $parsed['timestamp'];
        $receivedSignature = $parsed['signature'];

        // 1. Check timestamp freshness
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        // 2. Recompute expected signature
        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$rawPayload}", $secret);

        // 3. Constant-time comparison to prevent timing attacks
        return hash_equals($expectedSignature, $receivedSignature);
    }

    /**
     * Parse "t={timestamp},v1={signature}" format.
     *
     * @return array{timestamp: int, signature: string}|null
     */
    public function parseSignatureHeader(string $header): ?array
    {
        $parts = explode(',', $header);
        $timestamp = null;
        $signature = null;

        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                if ($kv[0] === 't') {
                    $timestamp = (int) $kv[1];
                } elseif ($kv[0] === 'v1') {
                    $signature = $kv[1];
                }
            }
        }

        if ($timestamp === null || empty($signature)) {
            return null;
        }

        return [
            'timestamp' => $timestamp,
            'signature' => $signature,
        ];
    }
}
