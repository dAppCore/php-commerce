<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Middleware;

use Closure;
use Core\Mod\Commerce\Models\Entity;
use Core\Mod\Commerce\Services\PermissionMatrixService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Commerce Matrix Gate - enforces permissions on every request.
 *
 * Every request through commerce routes is gated:
 * - Can THIS REQUEST from THIS ENTITY do THIS ACTION on THIS RESOURCE?
 *
 * Training mode shows a UI to approve undefined permissions.
 * Production mode denies undefined permissions.
 */
class CommerceMatrixGate
{
    public function __construct(
        protected PermissionMatrixService $matrix
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        $entity = $this->resolveEntity($request);
        $action = $action ?? $this->resolveAction($request);

        // If no entity or action, skip matrix check
        if (! $entity || ! $action) {
            return $next($request);
        }

        $result = $this->matrix->gateRequest($request, $entity, $action);

        if ($result->isDenied()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'error' => 'permission_denied',
                    'message' => $result->reason,
                    'key' => $action,
                ], 403);
            }

            abort(403, $result->reason ?? 'Permission denied');
        }

        if ($result->isPending()) {
            // Training mode - show the training UI
            if ($request->wantsJson()) {
                return response()->json([
                    'error' => 'permission_undefined',
                    'message' => 'Permission not yet trained',
                    'training_url' => $result->trainingUrl,
                    'key' => $result->key,
                    'scope' => $result->scope,
                ], 428); // Precondition Required
            }

            return response()->view('commerce.matrix.train-prompt', [
                'result' => $result,
                'request' => $request,
                'entity' => $entity,
            ], 428);
        }

        return $next($request);
    }

    /**
     * Resolve the commerce entity from the request.
     */
    protected function resolveEntity(Request $request): ?Entity
    {
        // Option 1: Explicit entity from route parameter
        if ($entityId = $request->route('entity')) {
            return Entity::find($entityId);
        }

        // Option 2: Entity header (for API requests)
        if ($entityCode = $request->header('X-Commerce-Entity')) {
            return Entity::where('code', $entityCode)->first();
        }

        // Option 3: Domain-based entity resolution
        $host = $request->getHost();
        if ($entity = Entity::where('domain', $host)->first()) {
            return $entity;
        }

        // Option 4: Workspace-based entity (from authenticated user)
        if ($workspace = $this->getCurrentWorkspace($request)) {
            return Entity::where('workspace_id', $workspace->id)->first();
        }

        // Option 5: Session-stored entity
        if ($entityId = session('commerce_entity_id')) {
            return Entity::find($entityId);
        }

        return null;
    }

    /**
     * Resolve the action from the request.
     */
    protected function resolveAction(Request $request): ?string
    {
        $route = $request->route();

        if (! $route) {
            return null;
        }

        // Option 1: Explicit matrix_action on route
        if ($action = $route->getAction('matrix_action')) {
            return $action;
        }

        // Option 2: Controller@method convention
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();

        if ($controller && $method) {
            // Convert ProductController@store → product.store
            $resource = Str::snake(
                str_replace(['Controller', 'App\\Http\\Controllers\\Commerce\\'], '', class_basename($controller))
            );

            return "{$resource}.{$method}";
        }

        // Option 3: REST convention from route name
        if ($routeName = $route->getName()) {
            // commerce.products.store → product.store
            $parts = explode('.', $routeName);
            if (count($parts) >= 2) {
                $resource = Str::singular($parts[count($parts) - 2]);
                $action = $parts[count($parts) - 1];

                return "{$resource}.{$action}";
            }
        }

        // Option 4: HTTP method + resource convention
        $method = $request->method();
        $segment = $request->segment(2); // /commerce/products → products

        if ($segment) {
            $resource = Str::singular($segment);

            return match ($method) {
                'GET' => "{$resource}.view",
                'POST' => "{$resource}.create",
                'PUT', 'PATCH' => "{$resource}.update",
                'DELETE' => "{$resource}.delete",
                default => null,
            };
        }

        return null;
    }

    /**
     * Get current workspace from request context.
     */
    protected function getCurrentWorkspace(Request $request)
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'defaultHostWorkspace')) {
            return null;
        }

        return $user->defaultHostWorkspace();
    }
}
