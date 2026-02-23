<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Concerns;

use Core\Mod\Commerce\Models\ContentOverride;
use Core\Mod\Commerce\Models\Entity;
use Core\Mod\Commerce\Services\ContentOverrideService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Trait for models that can have content overrides.
 *
 * Add to Product, Category, Page, etc. to enable white-label customisation.
 *
 * Usage:
 *   $product->getOverriddenAttribute('name', $entity);
 *   $product->forEntity($entity); // Returns array with all overrides applied
 *   $product->setOverride($entity, 'name', 'Custom Name');
 */
trait HasContentOverrides
{
    /**
     * Get all content overrides for this model.
     */
    public function contentOverrides(): MorphMany
    {
        return $this->morphMany(ContentOverride::class, 'overrideable');
    }

    /**
     * Get overrides for a specific entity.
     */
    public function overridesFor(Entity $entity): MorphMany
    {
        return $this->contentOverrides()->where('entity_id', $entity->id);
    }

    /**
     * Get an attribute value with overrides applied for an entity.
     *
     * Resolution: entity → parent → parent → M1 (original)
     */
    public function getOverriddenAttribute(string $field, Entity $entity): mixed
    {
        return app(ContentOverrideService::class)->get($entity, $this, $field);
    }

    /**
     * Get multiple attributes with overrides applied.
     */
    public function getOverriddenAttributes(array $fields, Entity $entity): array
    {
        $result = [];
        $service = app(ContentOverrideService::class);

        foreach ($fields as $field) {
            $result[$field] = $service->get($entity, $this, $field);
        }

        return $result;
    }

    /**
     * Get all model data with overrides applied for an entity.
     *
     * Returns the full model as an array with all applicable overrides merged in.
     */
    public function forEntity(Entity $entity, ?array $fields = null): array
    {
        return app(ContentOverrideService::class)->getEffective($entity, $this, $fields);
    }

    /**
     * Set an override for this model.
     */
    public function setOverride(Entity $entity, string $field, mixed $value): ContentOverride
    {
        return app(ContentOverrideService::class)->set($entity, $this, $field, $value);
    }

    /**
     * Set multiple overrides at once.
     */
    public function setOverrides(Entity $entity, array $overrides): array
    {
        return app(ContentOverrideService::class)->setBulk($entity, $this, $overrides);
    }

    /**
     * Clear an override (revert to inherited/original).
     */
    public function clearOverride(Entity $entity, string $field): bool
    {
        return app(ContentOverrideService::class)->clear($entity, $this, $field);
    }

    /**
     * Clear all overrides for an entity.
     */
    public function clearAllOverrides(Entity $entity): int
    {
        return app(ContentOverrideService::class)->clearAll($entity, $this);
    }

    /**
     * Get override status for specified fields.
     *
     * Returns array with value, source, is_overridden, inherited_from, etc.
     */
    public function getOverrideStatus(Entity $entity, array $fields): array
    {
        return app(ContentOverrideService::class)->getOverrideStatus($entity, $this, $fields);
    }

    /**
     * Check if this model has any overrides for an entity.
     */
    public function hasOverridesFor(Entity $entity): bool
    {
        return app(ContentOverrideService::class)->hasOverrides($entity, $this);
    }

    /**
     * Get which fields are overridden by an entity.
     */
    public function getOverriddenFieldsFor(Entity $entity): array
    {
        return app(ContentOverrideService::class)->getOverriddenFields($entity, $this);
    }

    /**
     * Scope to load models with override data for an entity.
     *
     * Note: This returns models; use forEntity() on each to get resolved values.
     */
    public function scopeWithOverridesFor($query, Entity $entity)
    {
        return $query->with(['contentOverrides' => function ($q) use ($entity) {
            $hierarchy = $entity->getHierarchy();
            $q->whereIn('entity_id', $hierarchy->pluck('id'));
        }]);
    }

    /**
     * Get the fields that can be overridden.
     *
     * Override this in your model to restrict which fields can be customised.
     */
    public function getOverrideableFields(): array
    {
        // Default: allow common content fields
        return [
            'name',
            'description',
            'short_description',
            'image_url',
            'gallery_urls',
            'meta_title',
            'meta_description',
        ];
    }

    /**
     * Check if a field can be overridden.
     */
    public function canOverrideField(string $field): bool
    {
        $allowed = $this->getOverrideableFields();

        // If empty array, all fields allowed
        if (empty($allowed)) {
            return true;
        }

        return in_array($field, $allowed, true);
    }
}
