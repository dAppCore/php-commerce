<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Console;

use Core\Mod\Commerce\Models\ExchangeRate;
use Core\Mod\Commerce\Services\CurrencyService;
use Illuminate\Console\Command;

/**
 * Refresh exchange rates from configured provider.
 *
 * Should be scheduled to run periodically (e.g., hourly).
 */
class RefreshExchangeRates extends Command
{
    protected $signature = 'commerce:refresh-exchange-rates
                            {--force : Force refresh even if rates are fresh}';

    protected $description = 'Refresh exchange rates from the configured provider';

    public function handle(CurrencyService $currencyService): int
    {
        $this->info('Refreshing exchange rates...');

        $baseCurrency = $currencyService->getBaseCurrency();
        $provider = config('commerce.currencies.exchange_rates.provider', 'ecb');

        $this->line("Base currency: {$baseCurrency}");
        $this->line("Provider: {$provider}");

        // Check if rates need refresh
        if (! $this->option('force') && ! ExchangeRate::needsRefresh()) {
            $this->info('Rates are still fresh. Use --force to refresh anyway.');

            return self::SUCCESS;
        }

        $rates = $currencyService->refreshExchangeRates();

        if (empty($rates)) {
            $this->error('No rates were updated. Check logs for errors.');

            return self::FAILURE;
        }

        $this->info('Updated '.count($rates).' exchange rates:');

        $rows = [];
        foreach ($rates as $currency => $rate) {
            $rows[] = [$baseCurrency, $currency, number_format($rate, 6)];
        }

        $this->table(['From', 'To', 'Rate'], $rows);

        return self::SUCCESS;
    }
}
