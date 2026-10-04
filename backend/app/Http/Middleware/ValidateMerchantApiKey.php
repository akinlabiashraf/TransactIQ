<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateMerchantApiKey
{
    /**
     * Handle an incoming request authenticated with a Merchant API Key.
     */
    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        // 1. Check if an API Key is explicitly provided via X-Api-Key or Authorization: Bearer tiq_...
        $apiKeyString = $request->header('X-Api-Key');
        if (!$apiKeyString) {
            $authHeader = $request->header('Authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer tiq_')) {
                $apiKeyString = substr($authHeader, 7);
            }
        }

        // 2. If no API key is passed, check if authenticated via Sanctum User Session token
        if (!$apiKeyString) {
            if ($user = auth('sanctum')->user()) {
                if ($user->status !== 'ACTIVE') {
                    return response()->json([
                        'error' => 'user_inactive',
                        'message' => 'Your user account is suspended or inactive.',
                    ], 403);
                }

                $request->setUserResolver(fn () => $user);

                // Resolve merchant context: user's assigned merchant or platform operator target
                $merchant = $user->merchant;
                if (!$merchant && ($user->isAdmin() || $user->isOperations() || $user->isAuditor())) {
                    $merchantId = $request->header('X-Merchant-Id') ?? $request->query('merchant_id');
                    if ($merchantId) {
                        $merchant = \App\Models\Merchant::find($merchantId);
                    } else {
                        $merchant = \App\Models\Merchant::first();
                    }
                }

                if ($merchant) {
                    $request->attributes->set('merchant', $merchant);
                }

                return $next($request);
            }
        }

        if (!$apiKeyString) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Authentication credentials missing. Please provide a valid API key (X-Api-Key) or user session bearer token.',
            ], 401);
        }

        // 2. Validate prefix format (e.g. tiq_live_sec_... or tiq_test_sec_...)
        if (!preg_match('/^tiq_(live|test)_(sec|pub)_[a-zA-Z0-9]+$/', $apiKeyString)) {
            return response()->json([
                'error' => 'invalid_api_key_format',
                'message' => 'The provided API key does not conform to the TransactIQ key format.',
            ], 401);
        }

        // 3. Hash secret with SHA-256 and look up in database
        $secretHash = hash('sha256', $apiKeyString);
        $apiKey = ApiKey::with('merchant')
            ->where('secret_key_hash', $secretHash)
            ->where('status', 'ACTIVE')
            ->first();

        // Also check if public key was supplied for read-only endpoints
        if (!$apiKey) {
            $apiKey = ApiKey::with('merchant')
                ->where('public_key', $apiKeyString)
                ->where('status', 'ACTIVE')
                ->first();
        }

        if (!$apiKey || !$apiKey->merchant) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => 'Invalid or revoked API key.',
            ], 401);
        }

        // 4. Verify Merchant Account Status
        if ($apiKey->merchant->status !== 'ACTIVE') {
            return response()->json([
                'error' => 'merchant_inactive',
                'message' => 'The merchant account associated with this API key is currently inactive or suspended.',
            ], 403);
        }

        // 5. Verify Scope Permission if required
        if ($requiredScope && !empty($apiKey->permissions)) {
            if (!in_array($requiredScope, $apiKey->permissions, true) && !in_array('*', $apiKey->permissions, true)) {
                return response()->json([
                    'error' => 'insufficient_scope',
                    'message' => "This API key does not have the required [{$requiredScope}] permission.",
                ], 403);
            }
        }

        // 6. Update last_used_at asynchronously or direct
        $apiKey->updateQuietly(['last_used_at' => now()]);

        // 7. Inject merchant and api_key into request attributes
        $request->attributes->set('merchant', $apiKey->merchant);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
