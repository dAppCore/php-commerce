<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Exception;

/**
 * Thrown when attempting to modify a locked permission.
 */
class PermissionLockedException extends Exception
{
}
