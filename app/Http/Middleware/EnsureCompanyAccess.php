<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyAccess
{
    /**
     * Handle an incoming request.
     * Ensures user has access to their current company and sets company context.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        if (!$user->current_company_id) {
            return response()->json(['message' => 'No company selected'], 403);
        }

        // Verify user belongs to this company (cached per request)
        $cacheKey = "company_access_{$user->id}_{$user->current_company_id}";
        $hasAccess = cache()->store('array')->remember($cacheKey, 60, function () use ($user) {
            return $user->companies()->where('company_id', $user->current_company_id)->exists();
        });

        if (!$hasAccess) {
            return response()->json(['message' => 'Access denied to this company'], 403);
        }

        // Set company in request for easy access
        $request->merge(['company_id' => $user->current_company_id]);

        return $next($request);
    }
}
