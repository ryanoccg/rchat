<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Permission mappings for API routes.
     * Maps route patterns to required permissions.
     *
     * IMPORTANT: Keep this in sync with routes/api.php
     * When adding new routes, always add the corresponding permission mapping here.
     */
    private const ROUTE_PERMISSIONS = [
        // Dashboard
        'GET:/dashboard/*' => 'view reports',

        // Conversations
        'GET:/conversations' => 'view conversations',
        'GET:/conversations/*' => 'view conversations',
        'POST:/conversations/*/messages' => 'reply conversations',
        'POST:/conversations/*/assign' => 'assign conversations',
        'PATCH:/conversations/*/status' => 'close conversations',
        'POST:/conversations/*/transfer-to-ai' => 'close conversations',
        'PATCH:/conversations/*/ai-handling' => 'close conversations',
        'GET:/conversations/*/summary' => 'view conversations',
        'POST:/conversations/*/summary' => 'edit ai config',
        'DELETE:/conversations/*/summary' => 'delete knowledge base',
        'POST:/conversations/summaries/batch' => 'edit ai config',

        // Customers
        'GET:/customers' => 'view conversations',
        'GET:/customers-stats' => 'view conversations',
        'GET:/customers-tags' => 'view conversations',
        'POST:/customers' => 'create users',
        'GET:/customers/*' => 'view conversations',
        'PUT:/customers/*' => 'edit users',
        'DELETE:/customers/*' => 'delete users',
        'GET:/customers/*/conversations' => 'view conversations',
        'PUT:/customers/*/notes' => 'edit users',
        'PUT:/customers/*/tags' => 'edit users',
        'POST:/customers/*/generate-insights' => 'view ai config',

        // Platform Connections
        'GET:/platforms' => 'view platforms',
        'GET:/platform-connections' => 'view platforms',
        'POST:/platform-connections' => 'connect platforms',
        'GET:/platform-connections/*' => 'view platforms',
        'PUT:/platform-connections/*' => 'connect platforms',
        'DELETE:/platform-connections/*' => 'disconnect platforms',
        'POST:/platform-connections/*/toggle' => 'disconnect platforms',
        'POST:/platform-connections/*/test' => 'view platforms',

        // Facebook OAuth
        'GET:/auth/facebook/*' => 'connect platforms',
        'POST:/auth/facebook/*' => 'connect platforms',

        // Google Calendar OAuth & Configuration
        'GET:/auth/google/*' => 'view calendar',
        'GET:/calendar/calendars' => 'view calendar',
        'POST:/calendar/connect' => 'manage calendar',
        'GET:/calendar/configuration' => 'view calendar',
        'PUT:/calendar/configuration' => 'manage calendar',
        'DELETE:/calendar/disconnect' => 'manage calendar',
        'GET:/calendar/available-slots' => 'view appointments',

        // AI Configuration
        'GET:/ai-providers' => 'view ai config',
        'GET:/ai-providers/*/models' => 'view ai config',
        'GET:/ai-configuration' => 'view ai config',
        'POST:/ai-configuration' => 'edit ai config',
        'PUT:/ai-configuration' => 'edit ai config',
        'POST:/ai-configuration/test' => 'edit ai config',
        'POST:/ai-configuration/toggle-auto-respond' => 'edit ai config',
        'GET:/ai-configuration/rate-limit' => 'view ai config',

        // AI Agents
        'GET:/ai-agents' => 'view ai config',
        'GET:/ai-agents/types' => 'view ai config',
        'GET:/ai-agents/*' => 'view ai config',
        'POST:/ai-agents' => 'edit ai config',
        'PUT:/ai-agents/*' => 'edit ai config',
        'DELETE:/ai-agents/*' => 'edit ai config',
        'POST:/ai-agents/reorder' => 'edit ai config',
        'POST:/ai-agents/*/duplicate' => 'edit ai config',
        'POST:/ai-agents/initialize-defaults' => 'edit ai config',

        // Knowledge Base
        'GET:/knowledge-base' => 'view knowledge base',
        'GET:/knowledge-base/categories' => 'view knowledge base',
        'GET:/knowledge-base/search' => 'view knowledge base',
        'POST:/knowledge-base' => 'create knowledge base',
        'GET:/knowledge-base/*' => 'view knowledge base',
        'PUT:/knowledge-base/*' => 'edit knowledge base',
        'DELETE:/knowledge-base/*' => 'delete knowledge base',
        'POST:/knowledge-base/*/toggle' => 'edit knowledge base',
        'GET:/knowledge-base/*/download' => 'view knowledge base',

        // Reports/Analytics
        'GET:/analytics/*' => 'view reports',
        'POST:/analytics/export' => 'export reports',

        // Subscriptions & Billing
        'GET:/subscriptions/*' => 'view settings',
        'POST:/subscriptions/*' => 'edit settings',

        // Team Management
        'GET:/team' => 'view users',
        'GET:/team/invitations' => 'view users',
        'GET:/team/roles' => 'view users',
        'POST:/team/invite' => 'create users',
        'POST:/team/invitations/*/resend' => 'create users',
        'DELETE:/team/invitations/*' => 'delete users',
        'PUT:/team/members/*/role' => 'edit users',
        'DELETE:/team/members/*' => 'delete users',

        // Roles & Permissions
        'GET:/roles' => 'view users',
        'GET:/roles/permissions' => 'view users',
        'GET:/roles/*' => 'view users',
        'PUT:/roles/*/permissions' => 'edit users',
        'POST:/roles' => 'edit users',
        'PUT:/roles/*' => 'edit users',
        'DELETE:/roles/*' => 'edit users',

        // Notifications
        'GET:/notifications' => 'view reports',
        'GET:/notifications/unread-count' => 'view reports',
        'POST:/notifications/*/read' => 'view reports',
        'POST:/notifications/mark-all-read' => 'view reports',
        'DELETE:/notifications/*' => 'delete knowledge base',

        // Company Settings
        'GET:/settings/company' => 'view settings',
        'PUT:/settings/company' => 'edit settings',
        'POST:/settings/company/logo' => 'edit settings',
        'DELETE:/settings/company/logo' => 'edit settings',
        'GET:/settings/timezones' => 'view settings',

        // User Profile & Preferences (always accessible to authenticated user)
        'GET:/settings/profile' => null,
        'PUT:/settings/profile' => null,
        'PUT:/settings/preferences' => null,
        'POST:/settings/password' => null,

        // Two-Factor Authentication (always accessible to authenticated user)
        'POST:/settings/2fa/*' => null,
        'GET:/settings/2fa/*' => null,

        // API Tokens
        'GET:/settings/api-tokens' => 'view settings',
        'POST:/settings/api-tokens' => 'edit settings',
        'DELETE:/settings/api-tokens/*' => 'edit settings',

        // Broadcasts
        'GET:/broadcasts' => 'view broadcasts',
        'GET:/broadcasts/estimate' => 'view broadcasts',
        'POST:/broadcasts' => 'create broadcasts',
        'GET:/broadcasts/*' => 'view broadcasts',
        'PUT:/broadcasts/*' => 'edit broadcasts',
        'DELETE:/broadcasts/*' => 'delete broadcasts',
        'POST:/broadcasts/*/send' => 'send broadcasts',
        'POST:/broadcasts/*/schedule' => 'send broadcasts',
        'POST:/broadcasts/*/cancel' => 'send broadcasts',
        'GET:/broadcasts/*/recipients' => 'view broadcasts',
        'GET:/broadcasts/*/statistics' => 'view broadcasts',

        // Products
        'GET:/products' => 'view products',
        'GET:/products/stats' => 'view products',
        'GET:/products/search' => 'view products',
        'GET:/products/export' => 'export reports',
        'POST:/products' => 'create products',
        'POST:/products/import' => 'create products',
        'POST:/products/bulk-delete' => 'delete products',
        'POST:/products/regenerate-embeddings' => 'edit products',
        'GET:/products/*' => 'view products',
        'PUT:/products/*' => 'edit products',
        'DELETE:/products/*' => 'delete products',
        'POST:/products/*/toggle' => 'edit products',
        'POST:/products/*/toggle-featured' => 'edit products',
        'POST:/products/*/regenerate-embeddings' => 'edit products',
        'POST:/products/upload-image' => 'create products',
        'POST:/products/delete-image' => 'edit products',

        // Product Categories
        'GET:/product-categories' => 'view products',
        'GET:/product-categories/tree' => 'view products',
        'POST:/product-categories' => 'create products',
        'GET:/product-categories/*' => 'view products',
        'PUT:/product-categories/*' => 'edit products',
        'DELETE:/product-categories/*' => 'delete products',
        'POST:/product-categories/*/toggle' => 'edit products',
        'POST:/product-categories/reorder' => 'edit products',

        // Media Library
        'GET:/media' => 'view products',
        'POST:/media' => 'create products',
        'POST:/media/bulk-upload' => 'create products',
        'POST:/media/import-from-url' => 'create products',
        'GET:/media/storage-usage' => 'view products',
        'GET:/media/folders' => 'view products',
        'GET:/media/collection/*' => 'view products',
        'GET:/media/for-model' => 'view products',
        'POST:/media/reorder' => 'edit products',
        'GET:/media/*' => 'view products',
        'PUT:/media/*' => 'edit products',
        'DELETE:/media/*' => 'delete products',
        'POST:/media/*/copy' => 'create products',
        'POST:/media/*/move' => 'edit products',
        'POST:/media/*/attach' => 'edit products',
        'POST:/media/*/detach' => 'edit products',
        'POST:/media/*/analyze' => 'view ai config',
        'POST:/media/bulk-delete' => 'delete products',

        // Activity Logs
        'GET:/activity-logs' => 'view reports',
        'GET:/activity-logs/action-types' => 'view reports',
        'GET:/activity-logs/stats' => 'view reports',

        // Workflows
        'GET:/workflows' => 'view workflows',
        'POST:/workflows' => 'create workflows',
        'GET:/workflows/statistics' => 'view workflow executions',
        'PUT:/workflows/steps/*' => 'edit workflows',
        'DELETE:/workflows/steps/*' => 'delete workflows',
        'GET:/workflows/executions' => 'view workflow executions',
        'GET:/workflows/executions/*' => 'view workflow executions',
        'POST:/workflows/executions/*/cancel' => 'execute workflows',
        'POST:/workflows/executions/*/retry' => 'execute workflows',
        'GET:/workflows/*' => 'view workflows',
        'PUT:/workflows/*' => 'edit workflows',
        'DELETE:/workflows/*' => 'delete workflows',
        'POST:/workflows/*/activate' => 'edit workflows',
        'POST:/workflows/*/deactivate' => 'edit workflows',
        'POST:/workflows/*/duplicate' => 'create workflows',
        'POST:/workflows/*/test' => 'execute workflows',
        'POST:/workflows/*/steps' => 'edit workflows',

        // Dev endpoints (local only, no permission check)
        'GET:/dev/*' => null,
        'DELETE:/dev/*' => null,
    ];

    /**
     * Company Owners have all permissions.
     */
    private array $ownerPermissionsCache = [];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $company = $user->currentCompany;

        // Get user's role in this company from pivot table
        $userRole = $company->users()
            ->where('users.id', $user->id)
            ->first()
            ?->pivot
            ?->role ?? 'Agent';

        // Company Owner bypasses all permission checks
        if ($userRole === 'Company Owner') {
            return $next($request);
        }

        // Get the required permission for this route
        $requiredPermission = $this->getRequiredPermission($request);

        // If no permission is required, allow access
        if (!$requiredPermission) {
            return $next($request);
        }

        // Check if user has the required permission
        if ($this->hasPermission($user, $company, $userRole, $requiredPermission)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You do not have permission to perform this action.',
            'permission' => $requiredPermission,
        ], 403);
    }

    /**
     * Get the required permission for the current route.
     */
    private function getRequiredPermission(Request $request): ?string
    {
        $method = $request->method();
        $path = $this->normalizePath($request->path());

        // Try exact match first
        $key = "{$method}:{$path}";
        if (isset(self::ROUTE_PERMISSIONS[$key])) {
            return self::ROUTE_PERMISSIONS[$key];
        }

        // Try wildcard match
        foreach (self::ROUTE_PERMISSIONS as $pattern => $permission) {
            [$patternMethod, $patternPath] = explode(':', $pattern, 2);

            if ($patternMethod !== $method) {
                continue;
            }

            if ($this->pathMatches($patternPath, $path)) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * Normalize the API path.
     */
    private function normalizePath(string $path): string
    {
        // Remove /api prefix if present
        $path = preg_replace('#^/api/#', '/', $path);
        // Remove query string
        $path = strtok($path, '?');
        // Ensure leading slash
        return '/' . ltrim($path, '/');
    }

    /**
     * Check if a path matches a pattern with wildcards.
     */
    private function pathMatches(string $pattern, string $path): bool
    {
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\*', '.*', $pattern);
        return (bool) preg_match("#^{$pattern}$#", $path);
    }

    /**
     * Check if user has a specific permission.
     * Checks company-level overrides first, then falls back to role's default permissions.
     */
    private function hasPermission($user, $company, string $userRole, string $permission): bool
    {
        $cacheKey = "{$company->id}_{$user->id}_{$userRole}";

        // Get permissions for this user's role
        if (!isset($this->ownerPermissionsCache[$cacheKey])) {
            // Check for company-level permission override
            $override = DB::table('company_role_permissions')
                ->where('company_id', $company->id)
                ->where('role_id', function ($query) use ($userRole) {
                    $query->select('id')
                        ->from('roles')
                        ->where('name', $userRole)
                        ->where('guard_name', 'web');
                })
                ->first();

            if ($override) {
                $this->ownerPermissionsCache[$cacheKey] = json_decode($override->permissions, true) ?? [];
            } else {
                // Fall back to Spatie role permissions
                $this->ownerPermissionsCache[$cacheKey] = $user->getAllPermissions()
                    ->pluck('name')
                    ->toArray();
            }
        }

        return in_array($permission, $this->ownerPermissionsCache[$cacheKey], true);
    }
}
