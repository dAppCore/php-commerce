<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\Entity;

/**
 * Result of a permission check in the Commerce Matrix.
 */
final readonly class PermissionResult
{
    private function __construct(
        public string $status,
        public ?string $reason = null,
        public ?Entity $lockedBy = null,
        public ?string $key = null,
        public ?string $scope = null,
        public ?string $trainingUrl = null,
    ) {
    }

    // Status constants
    public const STATUS_ALLOWED = 'allowed';

    public const STATUS_DENIED = 'denied';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDEFINED = 'undefined';

    // Factory methods

    public static function allowed(): self
    {
        return new self(status: self::STATUS_ALLOWED);
    }

    public static function denied(string $reason, ?Entity $lockedBy = null): self
    {
        return new self(
            status: self::STATUS_DENIED,
            reason: $reason,
            lockedBy: $lockedBy,
        );
    }

    public static function pending(string $key, ?string $scope, string $trainingUrl): self
    {
        return new self(
            status: self::STATUS_PENDING,
            key: $key,
            scope: $scope,
            trainingUrl: $trainingUrl,
        );
    }

    public static function undefined(string $key, ?string $scope): self
    {
        return new self(
            status: self::STATUS_UNDEFINED,
            key: $key,
            scope: $scope,
        );
    }

    // Status checks

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

    public function isUndefined(): bool
    {
        return $this->status === self::STATUS_UNDEFINED;
    }

    public function isLocked(): bool
    {
        return $this->lockedBy !== null;
    }

    // Conversion

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'reason' => $this->reason,
            'locked_by' => $this->lockedBy?->name,
            'key' => $this->key,
            'scope' => $this->scope,
            'training_url' => $this->trainingUrl,
        ], fn ($v) => $v !== null);
    }
}
