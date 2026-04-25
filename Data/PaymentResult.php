<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

use Carbon\Carbon;
use Core\Mod\Commerce\Models\Payment;

/**
 * Result from an attempted automatic invoice payment retry.
 */
readonly class PaymentResult
{
    public function __construct(
        public bool $successful,
        public ?Payment $payment = null,
        public ?string $reason = null,
        public int $attempts = 0,
        public ?Carbon $nextRetryAt = null,
    ) {}

    public static function successful(?Payment $payment = null, int $attempts = 0): self
    {
        return new self(
            successful: true,
            payment: $payment,
            attempts: $attempts,
        );
    }

    public static function failed(string $reason, int $attempts = 0, ?Carbon $nextRetryAt = null): self
    {
        return new self(
            successful: false,
            reason: $reason,
            attempts: $attempts,
            nextRetryAt: $nextRetryAt,
        );
    }

    public function succeeded(): bool
    {
        return $this->successful;
    }

    public function isFailed(): bool
    {
        return ! $this->successful;
    }
}
