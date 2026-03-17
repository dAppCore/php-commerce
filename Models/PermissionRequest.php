<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Tenant\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission Request - training data for the Permission Matrix.
 *
 * In training mode, undefined permissions create entries here
 * for approval. This builds a complete map of every action
 * in the system through actual usage.
 *
 * @property int $id
 * @property int $entity_id
 * @property string $method
 * @property string $route
 * @property string $action
 * @property string|null $scope
 * @property array|null $request_data
 * @property string|null $user_agent
 * @property string|null $ip_address
 * @property int|null $user_id
 * @property string $status
 * @property bool $was_trained
 * @property Carbon|null $trained_at
 */
class PermissionRequest extends Model
{
    // Status values
    public const STATUS_ALLOWED = 'allowed';

    public const STATUS_DENIED = 'denied';

    public const STATUS_PENDING = 'pending';

    protected $table = 'permission_requests';

    protected $fillable = [
        'entity_id',
        'method',
        'route',
        'action',
        'scope',
        'request_data',
        'user_agent',
        'ip_address',
        'user_id',
        'status',
        'was_trained',
        'trained_at',
    ];

    protected $casts = [
        'request_data' => 'array',
        'was_trained' => 'boolean',
        'trained_at' => 'datetime',
    ];

    // Relationships

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Status helpers

    public function isAllowed(): bool
    {
        return $this->status === self::STATUS_ALLOWED;
    }

    public function isDenied(): bool
    {
        return $this->status === self::STATUS_DENIED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function wasTrained(): bool
    {
        return $this->was_trained;
    }

    // Factory methods

    /**
     * Create a request log entry from an HTTP request.
     */
    public static function fromRequest(
        Entity $entity,
        string $action,
        string $status,
        ?string $scope = null
    ): self {
        $request = request();

        return static::create([
            'entity_id' => $entity->id,
            'method' => $request->method(),
            'route' => $request->path(),
            'action' => $action,
            'scope' => $scope,
            'request_data' => self::sanitiseRequestData($request->all()),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'user_id' => auth()->id(),
            'status' => $status,
        ]);
    }

    /**
     * Sanitise request data for storage (remove sensitive fields).
     */
    protected static function sanitiseRequestData(array $data): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'token',
            'api_key',
            'secret',
            'credit_card',
            'card_number',
            'cvv',
            'ssn',
        ];

        foreach ($sensitiveKeys as $key) {
            unset($data[$key]);
        }

        // Limit size
        $json = json_encode($data);
        if (strlen($json) > 10000) {
            return ['_truncated' => true, '_size' => strlen($json)];
        }

        return $data;
    }

    // Scopes

    public function scopeForEntity($query, int $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeUntrained($query)
    {
        return $query->where('was_trained', false);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
