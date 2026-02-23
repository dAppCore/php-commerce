<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\ContentOverride;
use Core\Mod\Commerce\Models\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Content Override Service - Sparse override resolution for white-label commerce.
 *
 * Resolution chain: entity → parent → parent → M1 (original)
 * Only stores what's different. Returns merged view at runtime.
 */
class ContentOverrideService
{
    /**
     * Get a single field value, resolved through the entity hierarchy.
     *
     * Checks from the entity up to root, returns first override found.
     * If no override, returns the original model value.
     */
    public function get(Entity $entity, Model $model, string $field): mixed
    {
        $hierarchy = $this->getHierarchyBottomUp($entity);
        $morphType = $model->getMorphClass();
        $modelId = $model->getKey();

        // Check from this entity up to root
        foreach ($hierarchy as $ancestor) {
            $override = ContentOverride::where('entity_id', $ancestor->id)
                ->where('overrideable_type', $morphType)
                ->where('overrideable_id', $modelId)
                ->where('field', $field)
                ->first();

            if ($override) {
                return $override->getCastedValue();
            }
        }

        // No override found - return original model value
        return $model->getAttribute($field);
    }

    /**
     * Set an override for an entity.
     */
    public function set(Entity $entity, Model $model, string $field, mixed $value): ContentOverride
    {
        return ContentOverride::setOverride($entity, $model, $field, $value);
    }

    /**
     * Clear (remove) an override for an entity.
     *
     * After clearing, the entity will inherit from parent or original.
     */
    public function clear(Entity $entity, Model $model, string $field): bool
    {
        return ContentOverride::clearOverride($entity, $model, $field);
    }

    /**
     * Get all resolved fields for a model within an entity context.
     *
     * Returns the model's attributes with all applicable overrides applied.
     */
    public function getEffective(Entity $entity, Model $model, ?array $fields = null): array
    {
        // Start with original model data
        $resolved = $model->toArray();

        // If specific fields requested, filter to just those
        if ($fields !== null) {
            $resolved = array_intersect_key($resolved, array_flip($fields));
        }

        // Get hierarchy from M1 down to this entity
        $hierarchy = $this->getHierarchyTopDown($entity);
        $morphType = $model->getMorphClass();
        $modelId = $model->getKey();

        // Apply overrides in order (M1 first, then M2, then M3, etc.)
        // Later overrides win, so entity's own overrides take precedence
        foreach ($hierarchy as $ancestor) {
            $overrides = ContentOverride::where('entity_id', $ancestor->id)
                ->where('overrideable_type', $morphType)
                ->where('overrideable_id', $modelId)
                ->when($fields !== null, fn ($q) => $q->whereIn('field', $fields))
                ->get();

            foreach ($overrides as $override) {
                $resolved[$override->field] = $override->getCastedValue();
            }
        }

        return $resolved;
    }

    /**
     * Get override status for all fields of a model.
     *
     * Returns information about what's overridden vs inherited.
     */
    public function getOverrideStatus(Entity $entity, Model $model, array $fields): array
    {
        $morphType = $model->getMorphClass();
        $modelId = $model->getKey();
        $hierarchy = $this->getHierarchyBottomUp($entity);
        $hierarchyIds = $hierarchy->pluck('id')->toArray();

        $status = [];

        foreach ($fields as $field) {
            // Find the override for this field (if any)
            $override = ContentOverride::where('overrideable_type', $morphType)
                ->where('overrideable_id', $modelId)
                ->where('field', $field)
                ->whereIn('entity_id', $hierarchyIds)
                ->orderByRaw('FIELD(entity_id, '.implode(',', $hierarchyIds).')')
                ->with('entity')
                ->first();

            $resolvedValue = $override
                ? $override->getCastedValue()
                : $model->getAttribute($field);

            $status[$field] = [
                'value' => $resolvedValue,
                'original' => $model->getAttribute($field),
                'source' => $override ? $override->entity->name : 'original',
                'source_type' => $override ? $override->entity->type : null,
                'is_overridden' => $override && $override->entity_id === $entity->id,
                'inherited_from' => $override && $override->entity_id !== $entity->id
                    ? $override->entity->name
                    : null,
                'can_override' => true, // Could add permission check here
            ];
        }

        return $status;
    }

    /**
     * Get all overrides for an entity (for admin UI).
     */
    public function getEntityOverrides(Entity $entity): Collection
    {
        return ContentOverride::where('entity_id', $entity->id)
            ->orderBy('overrideable_type')
            ->orderBy('overrideable_id')
            ->orderBy('field')
            ->get();
    }

    /**
     * Get overrides grouped by model (for admin UI).
     */
    public function getEntityOverridesGrouped(Entity $entity): Collection
    {
        return $this->getEntityOverrides($entity)
            ->groupBy(['overrideable_type', 'overrideable_id']);
    }

    /**
     * Bulk set overrides for a model.
     */
    public function setBulk(Entity $entity, Model $model, array $overrides): array
    {
        $results = [];

        foreach ($overrides as $field => $value) {
            $results[$field] = $this->set($entity, $model, $field, $value);
        }

        return $results;
    }

    /**
     * Clear all overrides for a model within an entity.
     */
    public function clearAll(Entity $entity, Model $model): int
    {
        return ContentOverride::where('entity_id', $entity->id)
            ->where('overrideable_type', $model->getMorphClass())
            ->where('overrideable_id', $model->getKey())
            ->delete();
    }

    /**
     * Copy overrides from one entity to another.
     *
     * Useful when creating child entities that should start with parent's customisations.
     */
    public function copyOverrides(Entity $source, Entity $target, ?Model $model = null): int
    {
        $query = ContentOverride::where('entity_id', $source->id);

        if ($model) {
            $query->where('overrideable_type', $model->getMorphClass())
                ->where('overrideable_id', $model->getKey());
        }

        $overrides = $query->get();
        $count = 0;

        foreach ($overrides as $override) {
            ContentOverride::updateOrCreate(
                [
                    'entity_id' => $target->id,
                    'overrideable_type' => $override->overrideable_type,
                    'overrideable_id' => $override->overrideable_id,
                    'field' => $override->field,
                ],
                [
                    'value' => $override->value,
                    'value_type' => $override->value_type,
                    'created_by' => auth()->id(),
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Check if an entity has any overrides for a model.
     */
    public function hasOverrides(Entity $entity, Model $model): bool
    {
        return ContentOverride::where('entity_id', $entity->id)
            ->where('overrideable_type', $model->getMorphClass())
            ->where('overrideable_id', $model->getKey())
            ->exists();
    }

    /**
     * Get which fields are overridden by an entity.
     */
    public function getOverriddenFields(Entity $entity, Model $model): array
    {
        return ContentOverride::where('entity_id', $entity->id)
            ->where('overrideable_type', $model->getMorphClass())
            ->where('overrideable_id', $model->getKey())
            ->pluck('field')
            ->toArray();
    }

    /**
     * Get hierarchy from this entity up to root (including self).
     */
    protected function getHierarchyBottomUp(Entity $entity): Collection
    {
        $hierarchy = $entity->getHierarchy(); // Includes self

        return $hierarchy->reverse()->values();
    }

    /**
     * Get hierarchy from root down to this entity (including self).
     */
    protected function getHierarchyTopDown(Entity $entity): Collection
    {
        return $entity->getHierarchy(); // Already ordered root to self
    }
}
