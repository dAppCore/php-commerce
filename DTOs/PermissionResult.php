<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\DTOs;

readonly class PermissionResult
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public bool $allowed,
        public ?string $reason,
        public array $permissions,
    ) {
    }

    /**
     * @return array{allowed: bool, reason: string|null, permissions: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'permissions' => $this->permissions,
        ];
    }
}
