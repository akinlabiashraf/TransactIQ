<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Authenticate platform user and issue a scoped Sanctum bearer token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::with(['role', 'merchant'])
            ->where('email', strtolower(trim($request->email)))
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'error' => 'invalid_credentials',
                'message' => 'The provided credentials do not match our records.',
            ], 401);
        }

        if ($user->status !== 'ACTIVE') {
            return response()->json([
                'error' => 'account_suspended',
                'message' => 'Your user account is suspended or inactive.',
            ], 403);
        }

        $roleSlug = $user->role?->slug ?? 'merchant';
        $token = $user->createToken('transactiq-session', [$roleSlug])->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'User authenticated successfully.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $roleSlug,
                    'role_name' => $user->role?->name ?? 'User',
                    'merchant' => $user->merchant ? [
                        'id' => $user->merchant->id,
                        'name' => $user->merchant->name,
                        'merchant_code' => $user->merchant->merchant_code,
                    ] : null,
                ],
            ],
        ]);
    }

    /**
     * Terminate user session and revoke current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Retrieve currently authenticated user context, role, and merchant profile.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'error' => 'unauthenticated',
                'message' => 'No active user session.',
            ], 401);
        }

        $user->loadMissing(['role', 'merchant']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->slug ?? 'merchant',
                'role_name' => $user->role?->name ?? 'User',
                'merchant' => $user->merchant ? [
                    'id' => $user->merchant->id,
                    'name' => $user->merchant->name,
                    'merchant_code' => $user->merchant->merchant_code,
                ] : null,
            ],
        ]);
    }
}
