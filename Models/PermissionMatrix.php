<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission Matrix entry - defines what an entity can do.
 *
 * Top-down immutable rules:
 * - If M1 says "NO" → Everything below is "NO"
 * - If M1 says "YES" → M2 can say "NO" for itself
 * - Permissions cascade DOWN, restrictions are IMMUTABLE from above
 *
 * @property int $id
 * @property int $entity_id
 * @property string $key
 * @property string|null $scope
 * @property bool $allowed
 * @property bool $locked
 * @property string $source
 * @property int|null $set_by_entity_id
 * @property Carbon|null $trained_at
 * @property string|null $trained_route
 */
class PermissionMatrix extends Model
{
    // Source types
    public const SOURCE_INHERITED = 'inherited';

    public const SOURCE_EXPLICIT = 'explicit';

    public const SOURCE_TRAINED = 'trained';

    protected $table = 'permission_matrix';

    protected $fillable = [
        'entity_id',
        'key',
        'scope',
        'allowed',
        'locked',
        'source',
        'set_by_entity_id',
        'trained_at',
        'trained_route',
    ];

    protected $casts = [
        'allowed' => 'boolean',
        'locked' => 'boolean',
        'trained_at' => 'datetime',
    ];

    // Relationships

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function setByEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'set_by_entity_id');
    }

    // Status helpers

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return ! $this->allowed;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function isTrained(): bool
    {
        return $this->source === self::SOURCE_TRAINED;
    }

    public function isInherited(): bool
    {
        return $this->source === self::SOURCE_INHERITED;
    }

    public function isExplicit(): bool
    {
        return $this->source === self::SOURCE_EXPLICIT;
    }

    // Scopes

    public function scopeForEntity($query, int $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeForKey($query, string $key)
    {
        return $query->where('key', $key);
    }

    public function scopeForScope($query, ?string $scope)
    {
        return $query->where(function ($q) use ($scope) {
            $q->whereNull('scope')->orWhere('scope', $scope);
        });
    }

    public function scopeAllowed($query)
    {
        return $query->where('allowed', true);
    }

    public function scopeDenied($query)
    {
        return $query->where('allowed', false);
    }

    public function scopeLocked($query)
    {
        return $query->where('locked', true);
    }

    public function scopeTrained($query)
    {
        return $query->where('source', self::SOURCE_TRAINED);
    }
}
