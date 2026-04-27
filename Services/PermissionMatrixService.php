<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\Entity;
use Core\Mod\Commerce\Models\PermissionMatrix;
use Core\Mod\Commerce\Models\PermissionRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Permission Matrix Service - enforces top-down immutable permissions.
 *
 * Rules:
 * - If M1 says "NO" → Everything below is "NO"
 * - If M1 says "YES" → M2 can say "NO" for itself
 * - Permissions cascade DOWN, restrictions are IMMUTABLE from above
 */
class PermissionMatrixService
{
    protected bool $trainingMode;

    protected bool $strictMode;

    protected bool $logAllChecks;

    protected bool $logDenials;

    public function __construct()
    {
        $this->trainingMode = config('commerce.matrix.training_mode', false);
        $this->strictMode = config('commerce.matrix.strict_mode', true);
        $this->logAllChecks = config('commerce.matrix.log_all_checks', false);
        $this->logDenials = config('commerce.matrix.log_denials', true);
    }

    /**
     * Check if an entity can perform an action.
     */
    public function can(Entity $entity, string $key, ?string $scope = null): PermissionResult
    {
        // Build the hierarchy path (M1 → M2 → M3)
        $hierarchy = $this->getHierarchy($entity);

        // Check from top down (M1 first, ancestors)
        foreach ($hierarchy as $ancestor) {
            $permission = PermissionMatrix::where('entity_id', $ancestor->id)
                ->where('key', $key)
                ->where(function ($q) use ($scope) {
                    $q->whereNull('scope')->orWhere('scope', $scope);
                })
                ->first();

            if ($permission) {
                // If locked and denied at this level, everything below is denied
                if ($permission->locked && ! $permission->allowed) {
                    return PermissionResult::denied(
                        reason: "Locked by {$ancestor->name}",
                        lockedBy: $ancestor
                    );
                }

                // If explicitly denied (not locked), continue checking
                if (! $permission->allowed && ! $permission->locked) {
                    return PermissionResult::denied(
                        reason: "Denied by {$ancestor->name}"
                    );
                }
            }
        }

        // Check the entity itself
        $ownPermission = PermissionMatrix::where('entity_id', $entity->id)
            ->where('key', $key)
            ->where(function ($q) use ($scope) {
                $q->whereNull('scope')->orWhere('scope', $scope);
            })
            ->first();

        if ($ownPermission) {
            return $ownPermission->allowed
                ? PermissionResult::allowed()
                : PermissionResult::denied(reason: 'Denied by own policy');
        }

        // No permission found
        return PermissionResult::undefined(key: $key, scope: $scope);
    }

    /**
     * Gate a request through the matrix.
     */
    public function gateRequest(Request $request, Entity $entity, string $action): PermissionResult
    {
        $scope = $this->extractScope($request);
        $result = $this->can($entity, $action, $scope);

        // Log the request if configured
        if ($this->logAllChecks || ($this->logDenials && $result->isDenied())) {
            $this->logRequest($request, $entity, $action, $scope, $result);
        }

        // Training mode records undefined permissions and allows the request.
        if ($result->isUndefined() && $this->trainingMode) {
            PermissionRequest::fromRequest($entity, $action, PermissionRequest::STATUS_PENDING, $scope);

            return PermissionResult::allowed();
        }

        // Production mode (strict): undefined = denied
        if ($result->isUndefined() && $this->strictMode) {
            return PermissionResult::denied(
                reason: "No permission defined for {$action}"
            );
        }

        // Non-strict mode with undefined: check default_allow config
        if ($result->isUndefined()) {
            $defaultAllow = config('commerce.matrix.default_allow', false);

            return $defaultAllow
                ? PermissionResult::allowed()
                : PermissionResult::denied(reason: "No permission defined for {$action}");
        }

        return $result;
    }

    public function check(Entity $entity, Entity $target, string $permission): \Core\Mod\Commerce\DTOs\PermissionResult
    {
        $matrix = PermissionMatrix::query()
            ->where('entity_id', $entity->id)
            ->where('target_entity_id', $target->id)
            ->where(function ($query) use ($permission): void {
                $query->where('key', $permission)
                    ->orWhereJsonContains('permissions', $permission);
            })
            ->first();

        if ($this->trainingMode && ! $matrix) {
            PermissionRequest::create([
                'entity_id' => $entity->id,
                'from_entity_id' => $entity->id,
                'to_entity_id' => $target->id,
                'method' => request()->method(),
                'route' => request()->path(),
                'action' => $permission,
                'permissions' => [$permission],
                'request_data' => request()->except([
                    'password',
                    'password_confirmation',
                    'token',
                    'api_key',
                    'secret',
                    'credit_card',
                    'card_number',
                    'cvv',
                    'ssn',
                ]),
                'user_agent' => request()->userAgent(),
                'ip_address' => request()->ip(),
                'user_id' => auth()->id(),
                'status' => PermissionRequest::STATUS_PENDING,
            ]);

            return new \Core\Mod\Commerce\DTOs\PermissionResult(true, 'training', [$permission]);
        }

        if (! $matrix) {
            return new \Core\Mod\Commerce\DTOs\PermissionResult(false, "No permission defined for {$permission}", []);
        }

        if (! $matrix->allowed) {
            return new \Core\Mod\Commerce\DTOs\PermissionResult(false, 'Permission denied', []);
        }

        $permissions = $matrix->permissions ?: [$matrix->key];

        return new \Core\Mod\Commerce\DTOs\PermissionResult(true, null, $permissions);
    }

    public function grant(Entity $entity, Entity $target, array $permissions): PermissionMatrix
    {
        $this->assertCanGrant($entity, $target);

        return PermissionMatrix::updateOrCreate(
            [
                'entity_id' => $entity->id,
                'target_entity_id' => $target->id,
                'key' => 'matrix.grant',
                'scope' => (string) $target->id,
            ],
            [
                'permissions' => array_values($permissions),
                'allowed' => true,
                'locked' => false,
                'source' => PermissionMatrix::SOURCE_EXPLICIT,
                'set_by_entity_id' => $entity->id,
            ]
        );
    }

    public function revoke(Entity $entity, Entity $target, array $permissions): void
    {
        $matrix = PermissionMatrix::query()
            ->where('entity_id', $entity->id)
            ->where('target_entity_id', $target->id)
            ->first();

        if (! $matrix) {
            return;
        }

        $remaining = array_values(array_diff($matrix->permissions ?? [], $permissions));

        if ($remaining === []) {
            $matrix->delete();

            return;
        }

        $matrix->update(['permissions' => $remaining]);
    }

    public function approveRequest(PermissionRequest $request): void
    {
        $request->update([
            'status' => PermissionRequest::STATUS_ALLOWED,
            'was_trained' => true,
            'trained_at' => now(),
        ]);

        if ($request->from_entity_id && $request->to_entity_id) {
            $from = Entity::find($request->from_entity_id);
            $to = Entity::find($request->to_entity_id);

            if ($from && $to) {
                $this->grant($from, $to, $request->permissions ?: [$request->action]);
            }
        }
    }

    public function denyRequest(PermissionRequest $request): void
    {
        $request->update([
            'status' => PermissionRequest::STATUS_DENIED,
            'was_trained' => true,
            'trained_at' => now(),
        ]);
    }

    protected function assertCanGrant(Entity $entity, Entity $target): void
    {
        $allowed = ($entity->isM1() && $target->isM2())
            || ($entity->isM2() && $target->isM3());

        if (! $allowed) {
            throw new \InvalidArgumentException('Commerce Matrix permissions may only be granted M1 to M2 or M2 to M3.');
        }
    }

    /**
     * Train a permission (dev mode).
     */
    public function train(
        Entity $entity,
        string $key,
        ?string $scope,
        bool $allow,
        ?string $route = null
    ): PermissionMatrix {
        // Check if parent has locked this
        $hierarchy = $this->getHierarchy($entity);

        foreach ($hierarchy as $ancestor) {
            $parentPerm = PermissionMatrix::where('entity_id', $ancestor->id)
                ->where('key', $key)
                ->where('locked', true)
                ->first();

            if ($parentPerm && ! $parentPerm->allowed) {
                throw new PermissionLockedException(
                    "Cannot train permission '{$key}' - locked by {$ancestor->name}"
                );
            }
        }

        return PermissionMatrix::updateOrCreate(
            [
                'entity_id' => $entity->id,
                'key' => $key,
                'scope' => $scope,
            ],
            [
                'allowed' => $allow,
                'locked' => false,
                'source' => PermissionMatrix::SOURCE_TRAINED,
                'trained_at' => now(),
                'trained_route' => $route,
            ]
        );
    }

    /**
     * Lock a permission (cascades down).
     */
    public function lock(Entity $entity, string $key, bool $allowed, ?string $scope = null): void
    {
        // Set on this entity
        PermissionMatrix::updateOrCreate(
            [
                'entity_id' => $entity->id,
                'key' => $key,
                'scope' => $scope,
            ],
            [
                'allowed' => $allowed,
                'locked' => true,
                'source' => PermissionMatrix::SOURCE_EXPLICIT,
                'set_by_entity_id' => $entity->id,
            ]
        );

        // Cascade to all descendants
        $descendants = Entity::where('path', 'like', $entity->path.'/%')->get();

        foreach ($descendants as $descendant) {
            PermissionMatrix::updateOrCreate(
                [
                    'entity_id' => $descendant->id,
                    'key' => $key,
                    'scope' => $scope,
                ],
                [
                    'allowed' => $allowed,
                    'locked' => true,
                    'source' => PermissionMatrix::SOURCE_INHERITED,
                    'set_by_entity_id' => $entity->id,
                ]
            );
        }
    }

    /**
     * Set an explicit permission (not locked, not trained).
     */
    public function setPermission(
        Entity $entity,
        string $key,
        bool $allowed,
        ?string $scope = null
    ): PermissionMatrix {
        // Check if parent has locked this
        $hierarchy = $this->getHierarchy($entity);

        foreach ($hierarchy as $ancestor) {
            $parentPerm = PermissionMatrix::where('entity_id', $ancestor->id)
                ->where('key', $key)
                ->where('locked', true)
                ->first();

            if ($parentPerm && ! $parentPerm->allowed && $allowed) {
                throw new PermissionLockedException(
                    "Cannot allow permission '{$key}' - locked as denied by {$ancestor->name}"
                );
            }
        }

        return PermissionMatrix::updateOrCreate(
            [
                'entity_id' => $entity->id,
                'key' => $key,
                'scope' => $scope,
            ],
            [
                'allowed' => $allowed,
                'locked' => false,
                'source' => PermissionMatrix::SOURCE_EXPLICIT,
            ]
        );
    }

    /**
     * Unlock a permission (removes inherited locks from descendants).
     */
    public function unlock(Entity $entity, string $key, ?string $scope = null): void
    {
        // Update this entity's permission to unlocked
        PermissionMatrix::where('entity_id', $entity->id)
            ->where('key', $key)
            ->where('scope', $scope)
            ->update(['locked' => false, 'source' => PermissionMatrix::SOURCE_EXPLICIT]);

        // Remove inherited locks from descendants
        $descendantIds = Entity::where('path', 'like', $entity->path.'/%')
            ->pluck('id');

        PermissionMatrix::whereIn('entity_id', $descendantIds)
            ->where('key', $key)
            ->where('scope', $scope)
            ->where('set_by_entity_id', $entity->id)
            ->delete();
    }

    /**
     * Get all permissions for an entity.
     */
    public function getPermissions(Entity $entity): Collection
    {
        return PermissionMatrix::where('entity_id', $entity->id)
            ->orderBy('key')
            ->get();
    }

    /**
     * Get effective permissions for an entity (including inherited).
     */
    public function getEffectivePermissions(Entity $entity): Collection
    {
        $hierarchy = $this->getHierarchy($entity);
        $hierarchy->push($entity);

        $entityIds = $hierarchy->pluck('id');

        return PermissionMatrix::whereIn('entity_id', $entityIds)
            ->orderBy('key')
            ->get()
            ->groupBy('key')
            ->map(function ($permissions) use ($entity) {
                // For each key, determine the effective permission
                foreach ($permissions as $perm) {
                    if ($perm->locked && ! $perm->allowed) {
                        return $perm; // Locked denial wins
                    }
                }

                // Return the entity's own permission if exists
                return $permissions->firstWhere('entity_id', $entity->id)
                    ?? $permissions->last();
            });
    }

    /**
     * Get pending permission requests for training.
     */
    public function getPendingRequests(?Entity $entity = null): Collection
    {
        $query = PermissionRequest::pending()->untrained();

        if ($entity) {
            $query->forEntity($entity->id);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Mark pending requests as trained.
     */
    public function markRequestsTrained(Entity $entity, string $action, ?string $scope = null): int
    {
        return PermissionRequest::forEntity($entity->id)
            ->forAction($action)
            ->where('scope', $scope)
            ->pending()
            ->update([
                'was_trained' => true,
                'trained_at' => now(),
            ]);
    }

    /**
     * Check if training mode is enabled.
     */
    public function isTrainingMode(): bool
    {
        return $this->trainingMode;
    }

    /**
     * Check if strict mode is enabled.
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    /**
     * Get hierarchy from root to parent (not including entity itself).
     */
    protected function getHierarchy(Entity $entity): Collection
    {
        return $entity->getAncestors();
    }

    /**
     * Extract scope from request (resource type or ID).
     */
    protected function extractScope(Request $request): ?string
    {
        // Try route parameters
        $route = $request->route();

        if ($route) {
            // Look for common resource parameters
            foreach (['id', 'product', 'order', 'customer'] as $param) {
                if ($value = $route->parameter($param)) {
                    return is_object($value) ? (string) $value->id : (string) $value;
                }
            }
        }

        return null;
    }

    /**
     * Log a permission request.
     */
    protected function logRequest(
        Request $request,
        Entity $entity,
        string $action,
        ?string $scope,
        PermissionResult $result
    ): void {
        PermissionRequest::fromRequest(
            $entity,
            $action,
            match (true) {
                $result->isAllowed() => PermissionRequest::STATUS_ALLOWED,
                $result->isDenied() => PermissionRequest::STATUS_DENIED,
                default => PermissionRequest::STATUS_PENDING,
            },
            $scope
        );
    }
}
