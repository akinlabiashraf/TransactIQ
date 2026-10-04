<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request and enforce institutional RBAC policies.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles Allowed role slugs (e.g. 'admin', 'operations', 'auditor', 'merchant')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Parse roles if passed as a single comma-separated string (e.g. 'admin,operations')
        $allowedRoles = [];
        foreach ($roles as $roleGroup) {
            foreach (explode(',', $roleGroup) as $r) {
                $trimmed = strtolower(trim($r));
                if (!empty($trimmed)) {
                    $allowedRoles[] = $trimmed;
                }
            }
        }

        /** @var User|null $user */
        $user = $request->user();

        // Check 1: Authenticated Platform User
        if ($user) {
            $userRole = strtolower($user->role?->slug ?? '');

            // Admins have universal platform access
            if ($userRole === Role::ADMIN || in_array($userRole, $allowedRoles, true)) {
                return $next($request);
            }

            return response()->json([
                'error' => 'forbidden',
                'message' => 'Access denied. Required role: [' . implode(', ', $allowedRoles) . ']. Current role: [' . ($userRole ?: 'none') . '].',
            ], 403);
        }

        // Check 2: API Key context (Merchant or Platform API)
        $apiKey = $request->attributes->get('api_key');
        $merchant = $request->attributes->get('merchant');

        if ($apiKey && $merchant) {
            // Check if merchant role is allowed
            if (in_array(Role::MERCHANT, $allowedRoles, true) || in_array('*', $allowedRoles, true)) {
                return $next($request);
            }

            // Check if API key has admin permissions
            if (is_array($apiKey->permissions) && in_array('*', $apiKey->permissions, true)) {
                return $next($request);
            }

            return response()->json([
                'error' => 'forbidden',
                'message' => 'Access denied. Merchant API key cannot perform operations restricted to: [' . implode(', ', $allowedRoles) . '].',
            ], 403);
        }

        return response()->json([
            'error' => 'unauthorized',
            'message' => 'Authentication required to access this resource.',
        ], 401);
    }
}
