<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Currency service for multi-currency support.
 *
 * Handles currency conversion, formatting, exchange rate fetching,
 * and currency detection based on location/preferences.
 */
class CurrencyService
{
    /**
     * Session key for storing selected currency.
     */
    protected const SESSION_KEY = 'commerce_currency';

    /**
     * Get the base currency.
     */
    public function getBaseCurrency(): string
    {
        return config('commerce.currencies.base', 'GBP');
    }

    /**
     * Get all supported currencies.
     *
     * @return array<string, array>
     */
    public function getSupportedCurrencies(): array
    {
        return config('commerce.currencies.supported', []);
    }

    /**
     * Check if a currency is supported.
     */
    public function isSupported(string $currency): bool
    {
        return array_key_exists(
            strtoupper($currency),
            $this->getSupportedCurrencies()
        );
    }

    /**
     * Get currency configuration.
     */
    public function getCurrencyConfig(string $currency): ?array
    {
        return config("commerce.currencies.supported.{$currency}");
    }

    /**
     * Get the current currency for the session.
     */
    public function getCurrentCurrency(): string
    {
        // Check session first
        if (Session::has(self::SESSION_KEY)) {
            $currency = Session::get(self::SESSION_KEY);
            if ($this->isSupported($currency)) {
                return $currency;
            }
        }

        // Detect currency
        return $this->detectCurrency();
    }

    /**
     * Set the current currency for the session.
     */
    public function setCurrentCurrency(string $currency): void
    {
        $currency = strtoupper($currency);

        if ($this->isSupported($currency)) {
            Session::put(self::SESSION_KEY, $currency);
        }
    }

    /**
     * Detect the best currency for a request.
     */
    public function detectCurrency(?Request $request = null): string
    {
        $request = $request ?? request();
        $detectionOrder = config('commerce.currencies.detection_order', ['geolocation', 'browser', 'default']);

        foreach ($detectionOrder as $method) {
            $currency = match ($method) {
                'geolocation' => $this->detectFromGeolocation($request),
                'browser' => $this->detectFromBrowser($request),
                'default' => $this->getBaseCurrency(),
                default => null,
            };

            if ($currency && $this->isSupported($currency)) {
                return $currency;
            }
        }

        return $this->getBaseCurrency();
    }

    /**
     * Detect currency from geolocation (country).
     */
    protected function detectFromGeolocation(Request $request): ?string
    {
        // Check for country header (set by CDN/load balancer)
        $country = $request->header('CF-IPCountry') // Cloudflare
            ?? $request->header('X-Country-Code')   // Generic
            ?? $request->header('X-Geo-Country');   // Bunny CDN

        if (! $country) {
            return null;
        }

        $country = strtoupper($country);
        $countryCurrencies = config('commerce.currencies.country_currencies', []);

        return $countryCurrencies[$country] ?? null;
    }

    /**
     * Detect currency from browser Accept-Language header.
     */
    protected function detectFromBrowser(Request $request): ?string
    {
        $acceptLanguage = $request->header('Accept-Language');

        if (! $acceptLanguage) {
            return null;
        }

        // Parse primary locale (e.g., "en-GB,en;q=0.9" -> "en-GB")
        $primaryLocale = explode(',', $acceptLanguage)[0];
        $parts = explode('-', str_replace('_', '-', $primaryLocale));

        if (count($parts) >= 2) {
            $country = strtoupper($parts[1]);
            $countryCurrencies = config('commerce.currencies.country_currencies', []);

            return $countryCurrencies[$country] ?? null;
        }

        return null;
    }

    /**
     * Format an amount for display.
     *
     * @param  float|int  $amount  Amount in decimal or cents
     * @param  bool  $isCents  Whether amount is in cents
     */
    public function format(float|int $amount, string $currency, bool $isCents = false): string
    {
        $currency = strtoupper($currency);
        $config = $this->getCurrencyConfig($currency);

        if (! $config) {
            // Fallback formatting
            return $currency.' '.number_format($isCents ? $amount / 100 : $amount, 2);
        }

        $symbol = $config['symbol'] ?? $currency;
        $position = $config['symbol_position'] ?? 'before';
        $decimals = $config['decimal_places'] ?? 2;
        $thousandsSep = $config['thousands_separator'] ?? ',';
        $decimalSep = $config['decimal_separator'] ?? '.';

        $value = $isCents ? $amount / 100 : $amount;
        $formatted = number_format($value, $decimals, $decimalSep, $thousandsSep);

        return $position === 'before'
            ? "{$symbol}{$formatted}"
            : "{$formatted}{$symbol}";
    }

    /**
     * Format an amount in the current session currency.
     */
    public function formatCurrent(float|int $amount, bool $isCents = false): string
    {
        return $this->format($amount, $this->getCurrentCurrency(), $isCents);
    }

    /**
     * Get the currency symbol.
     */
    public function getSymbol(string $currency): string
    {
        $config = $this->getCurrencyConfig($currency);

        return $config['symbol'] ?? $currency;
    }

    /**
     * Convert an amount between currencies.
     */
    public function convert(float $amount, string $from, string $to): ?float
    {
        return ExchangeRate::convert($amount, $from, $to);
    }

    /**
     * Convert cents between currencies.
     */
    public function convertCents(int $amount, string $from, string $to): ?int
    {
        return ExchangeRate::convertCents($amount, $from, $to);
    }

    /**
     * Get the exchange rate between currencies.
     */
    public function getExchangeRate(string $from, string $to): ?float
    {
        return ExchangeRate::getRate($from, $to);
    }

    /**
     * Refresh exchange rates from the configured provider.
     */
    public function refreshExchangeRates(): array
    {
        $provider = config('commerce.currencies.exchange_rates.provider', 'ecb');
        $baseCurrency = $this->getBaseCurrency();
        $supportedCurrencies = array_keys($this->getSupportedCurrencies());

        return match ($provider) {
            'ecb' => $this->fetchFromEcb($baseCurrency, $supportedCurrencies),
            'stripe' => $this->fetchFromStripe($baseCurrency, $supportedCurrencies),
            'openexchangerates' => $this->fetchFromOpenExchangeRates($baseCurrency, $supportedCurrencies),
            'fixed' => $this->loadFixedRates($baseCurrency, $supportedCurrencies),
            default => [],
        };
    }

    /**
     * Fetch rates from European Central Bank (free, no API key).
     */
    protected function fetchFromEcb(string $baseCurrency, array $targetCurrencies): array
    {
        try {
            // ECB provides rates in EUR
            $response = Http::timeout(10)
                ->get('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml');

            if (! $response->successful()) {
                Log::warning('ECB exchange rate fetch failed', ['status' => $response->status()]);

                return [];
            }

            $xml = simplexml_load_string($response->body());
            $rates = ['EUR' => 1.0];

            foreach ($xml->Cube->Cube->Cube as $rate) {
                $rates[(string) $rate['currency']] = (float) $rate['rate'];
            }

            // Convert to base currency
            $stored = [];
            $baseInEur = $rates[$baseCurrency] ?? null;

            if (! $baseInEur) {
                Log::warning("ECB does not have rate for base currency: {$baseCurrency}");

                return [];
            }

            foreach ($targetCurrencies as $currency) {
                if ($currency === $baseCurrency) {
                    continue;
                }

                $targetInEur = $rates[$currency] ?? null;

                if ($targetInEur) {
                    // Convert: baseCurrency -> EUR -> targetCurrency
                    $rate = $targetInEur / $baseInEur;
                    ExchangeRate::storeRate($baseCurrency, $currency, $rate, 'ecb');
                    $stored[$currency] = $rate;
                }
            }

            Log::info('ECB exchange rates updated', ['count' => count($stored)]);

            return $stored;
        } catch (\Exception $e) {
            Log::error('ECB exchange rate fetch error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Fetch rates from Stripe's balance transaction API.
     * Requires Stripe API key.
     */
    protected function fetchFromStripe(string $baseCurrency, array $targetCurrencies): array
    {
        // Stripe provides real-time rates through their conversion API
        // This requires an active Stripe account
        $stripeSecret = config('commerce.gateways.stripe.secret');

        if (! $stripeSecret) {
            Log::warning('Stripe exchange rates requested but no API key configured');

            return $this->loadFixedRates($baseCurrency, $targetCurrencies);
        }

        try {
            // Stripe doesn't have a direct exchange rate API, but we can use balance transactions
            // For simplicity, fall back to ECB and just log that Stripe was requested
            Log::info('Stripe exchange rates: falling back to ECB');

            return $this->fetchFromEcb($baseCurrency, $targetCurrencies);
        } catch (\Exception $e) {
            Log::error('Stripe exchange rate fetch error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Fetch rates from Open Exchange Rates API.
     */
    protected function fetchFromOpenExchangeRates(string $baseCurrency, array $targetCurrencies): array
    {
        $apiKey = config('commerce.currencies.exchange_rates.api_key');

        if (! $apiKey) {
            Log::warning('Open Exchange Rates requested but no API key configured');

            return $this->loadFixedRates($baseCurrency, $targetCurrencies);
        }

        try {
            $response = Http::timeout(10)
                ->get('https://openexchangerates.org/api/latest.json', [
                    'app_id' => $apiKey,
                    'base' => 'USD', // Free tier only supports USD as base
                    'symbols' => implode(',', array_merge([$baseCurrency], $targetCurrencies)),
                ]);

            if (! $response->successful()) {
                Log::warning('Open Exchange Rates fetch failed', ['status' => $response->status()]);

                return [];
            }

            $data = $response->json();
            $rates = $data['rates'] ?? [];

            if (empty($rates)) {
                return [];
            }

            // Convert from USD base to our base currency
            $stored = [];
            $baseInUsd = $rates[$baseCurrency] ?? null;

            if (! $baseInUsd) {
                Log::warning("Open Exchange Rates does not have rate for: {$baseCurrency}");

                return [];
            }

            foreach ($targetCurrencies as $currency) {
                if ($currency === $baseCurrency) {
                    continue;
                }

                $targetInUsd = $rates[$currency] ?? null;

                if ($targetInUsd) {
                    $rate = $targetInUsd / $baseInUsd;
                    ExchangeRate::storeRate($baseCurrency, $currency, $rate, 'openexchangerates');
                    $stored[$currency] = $rate;
                }
            }

            Log::info('Open Exchange Rates updated', ['count' => count($stored)]);

            return $stored;
        } catch (\Exception $e) {
            Log::error('Open Exchange Rates fetch error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Load fixed rates from configuration.
     */
    protected function loadFixedRates(string $baseCurrency, array $targetCurrencies): array
    {
        $fixedRates = config('commerce.currencies.exchange_rates.fixed', []);
        $stored = [];

        foreach ($targetCurrencies as $currency) {
            if ($currency === $baseCurrency) {
                continue;
            }

            $directKey = "{$baseCurrency}_{$currency}";
            $inverseKey = "{$currency}_{$baseCurrency}";

            if (isset($fixedRates[$directKey])) {
                $rate = (float) $fixedRates[$directKey];
                ExchangeRate::storeRate($baseCurrency, $currency, $rate, 'fixed');
                $stored[$currency] = $rate;
            } elseif (isset($fixedRates[$inverseKey]) && $fixedRates[$inverseKey] > 0) {
                $rate = 1.0 / (float) $fixedRates[$inverseKey];
                ExchangeRate::storeRate($baseCurrency, $currency, $rate, 'fixed');
                $stored[$currency] = $rate;
            }
        }

        return $stored;
    }

    /**
     * Get currency data for JavaScript/frontend.
     *
     * @return array<string, array>
     */
    public function getJsData(): array
    {
        $currencies = [];
        $baseCurrency = $this->getBaseCurrency();

        foreach ($this->getSupportedCurrencies() as $code => $config) {
            $rate = $code === $baseCurrency ? 1.0 : ExchangeRate::getRate($baseCurrency, $code);

            $currencies[$code] = [
                'code' => $code,
                'name' => $config['name'],
                'symbol' => $config['symbol'],
                'symbolPosition' => $config['symbol_position'],
                'decimalPlaces' => $config['decimal_places'],
                'thousandsSeparator' => $config['thousands_separator'],
                'decimalSeparator' => $config['decimal_separator'],
                'flag' => $config['flag'],
                'rate' => $rate,
            ];
        }

        return [
            'base' => $baseCurrency,
            'current' => $this->getCurrentCurrency(),
            'currencies' => $currencies,
        ];
    }
}
