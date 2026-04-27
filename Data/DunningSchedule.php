<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

use Carbon\Carbon;

/**
 * Failed-payment retry and suspension dates for a subscription.
 */
readonly class DunningSchedule
{
    /**
     * @param  array<int, Carbon>  $retryDates
     */
    public function __construct(
        public array $retryDates,
        public Carbon $suspensionDate,
    ) {}

    /**
     * @return array{retry_dates: array<int, string>, suspension_date: string}
     */
    public function toArray(): array
    {
        return [
            'retry_dates' => array_map(
                fn (Carbon $date): string => $date->toISOString(),
                $this->retryDates
            ),
            'suspension_date' => $this->suspensionDate->toISOString(),
        ];
    }
}
