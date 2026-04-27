<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Contracts\Orderable;
use Core\Mod\Commerce\Models\TaxRate;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tax calculation service.
 *
 * Handles UK VAT, EU OSS, US state taxes, and Australian GST.
 */
class TaxService
{
    /**
     * Cache TTL for validated tax IDs (24 hours).
     */
    protected const VALIDATION_CACHE_TTL = 86400;

    /**
     * Calculate tax for an amount.
     */
    public function calculate(Workspace $workspace, float $amount): TaxResult
    {
        if (! config('commerce.tax.enabled', true)) {
            return new TaxResult(0, 0, null, null, true, 'Tax disabled');
        }

        // Check for tax exemption
        if ($workspace->tax_exempt) {
            return new TaxResult(0, 0, null, $workspace->billing_country, true, 'Tax exempt');
        }

        $country = strtoupper($workspace->billing_country ?? 'GB');
        $state = $workspace->billing_state;
        $taxId = $workspace->tax_id;

        // B2B reverse charge for EU/UK
        if ($taxId && $this->isValidTaxId($taxId, $country)) {
            if ($this->isReverseChargeApplicable($country)) {
                return new TaxResult(0, 0, 'reverse_charge', $country, true, 'B2B reverse charge');
            }
        }

        // Find applicable tax rate
        $taxRate = TaxRate::findForLocation($country, $state);

        if (! $taxRate) {
            // No tax rate found - could be a non-taxable jurisdiction
            return new TaxResult(0, 0, null, $country, false, null);
        }

        $taxAmount = $taxRate->calculateTax($amount);

        return new TaxResult(
            taxAmount: $taxAmount,
            taxRate: $taxRate->rate,
            taxType: $taxRate->type,
            jurisdiction: $taxRate->state_code
                ? "{$country}-{$taxRate->state_code}"
                : $country,
            isExempt: false,
            exemptionReason: null
        );
    }

    /**
     * Calculate tax for an Orderable (User or Workspace).
     */
    public function calculateForOrderable(Orderable $orderable, float $amount): TaxResult
    {
        if (! config('commerce.tax.enabled', true)) {
            return new TaxResult(0, 0, null, null, true, 'Tax disabled');
        }

        $country = strtoupper($orderable->getTaxCountry() ?? 'GB');

        // Find applicable tax rate
        $taxRate = TaxRate::findForLocation($country, null);

        if (! $taxRate) {
            return new TaxResult(0, 0, null, $country, false, null);
        }

        $taxAmount = $taxRate->calculateTax($amount);

        return new TaxResult(
            taxAmount: $taxAmount,
            taxRate: $taxRate->rate,
            taxType: $taxRate->type,
            jurisdiction: $country,
            isExempt: false,
            exemptionReason: null
        );
    }

    /**
     * Get tax rate for a location.
     */
    public function getRateForLocation(string $country, ?string $state = null): ?TaxRate
    {
        return TaxRate::findForLocation($country, $state);
    }

    /**
     * Check if reverse charge is applicable.
     */
    public function isReverseChargeApplicable(string $country): bool
    {
        // UK B2B: No reverse charge (we're in UK)
        if ($country === 'GB') {
            return false;
        }

        // EU B2B: Reverse charge applies
        if ($this->isEuCountry($country)) {
            return true;
        }

        return false;
    }

    /**
     * Check if country is in EU.
     */
    public function isEuCountry(string $country): bool
    {
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        return in_array(strtoupper($country), $euCountries);
    }

    /**
     * Validate a tax ID (VAT number, ABN, etc.).
     */
    public function isValidTaxId(string $taxId, string $country): bool
    {
        if (! config('commerce.tax.validate_tax_ids', true)) {
            // Skip validation if disabled
            return true;
        }

        $country = strtoupper($country);

        return match (true) {
            $country === 'GB' => $this->validateUkVat($taxId),
            $this->isEuCountry($country) => $this->validateEuVat($taxId),
            $country === 'AU' => $this->validateAbn($taxId),
            default => true, // Accept as valid if we can't validate
        };
    }

    /**
     * Validate UK VAT number via HMRC API.
     *
     * Uses the free HMRC VAT API to verify VAT numbers.
     * Results are cached for 24 hours to reduce API calls.
     *
     * @see https://developer.service.hmrc.gov.uk/api-documentation/docs/api/service/vat-api
     */
    protected function validateUkVat(string $vatNumber): bool
    {
        // Basic format validation: GB followed by 9 or 12 digits
        $vatNumber = strtoupper(str_replace([' ', '-'], '', $vatNumber));

        if (! preg_match('/^GB(\d{9}|\d{12})$/', $vatNumber)) {
            return false;
        }

        // Extract just the numeric part for HMRC API
        $vatNumberOnly = substr($vatNumber, 2);

        // Check cache first
        $cacheKey = "vat_validation:uk:{$vatNumberOnly}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Skip API validation if disabled or in testing
        if (! config('commerce.tax.validate_tax_ids_api', true) || app()->environment('testing')) {
            return true;
        }

        try {
            // HMRC Check VAT Number API (free, no auth required)
            $response = Http::timeout(10)
                ->get("https://api.service.hmrc.gov.uk/organisations/vat/check-vat-number/lookup/{$vatNumberOnly}");

            if ($response->successful()) {
                $data = $response->json();
                $isValid = isset($data['target']['vatNumber']);

                Cache::put($cacheKey, $isValid, self::VALIDATION_CACHE_TTL);

                return $isValid;
            }

            // If API returns 404, the VAT number doesn't exist
            if ($response->status() === 404) {
                Cache::put($cacheKey, false, self::VALIDATION_CACHE_TTL);

                return false;
            }

            // For other errors, log and allow (fail open for availability)
            Log::warning('HMRC VAT validation API error', [
                'vat_number' => $vatNumberOnly,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::warning('HMRC VAT validation failed', [
                'vat_number' => $vatNumberOnly,
                'error' => $e->getMessage(),
            ]);

            // Fail open - don't block transactions if API is unavailable
            return true;
        }
    }

    /**
     * Validate EU VAT number via VIES (VAT Information Exchange System).
     *
     * Uses the EU Commission's VIES SOAP service to verify VAT numbers.
     * Results are cached for 24 hours to reduce API calls.
     *
     * @see https://ec.europa.eu/taxation_customs/vies/
     */
    protected function validateEuVat(string $vatNumber): bool
    {
        // Basic format validation
        $vatNumber = strtoupper(str_replace([' ', '-'], '', $vatNumber));

        if (strlen($vatNumber) < 4) {
            return false;
        }

        // Extract country code (first 2 characters)
        $countryCode = substr($vatNumber, 0, 2);
        $vatNumberOnly = substr($vatNumber, 2);

        // Validate country code is EU
        if (! $this->isEuCountry($countryCode)) {
            return false;
        }

        // Check cache first
        $cacheKey = "vat_validation:eu:{$countryCode}:{$vatNumberOnly}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Skip API validation if disabled or in testing
        if (! config('commerce.tax.validate_tax_ids_api', true) || app()->environment('testing')) {
            return true;
        }

        try {
            // VIES REST API (EU Commission)
            $response = Http::timeout(15)
                ->asJson()
                ->post('https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number', [
                    'countryCode' => $countryCode,
                    'vatNumber' => $vatNumberOnly,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $isValid = $data['valid'] ?? false;

                Cache::put($cacheKey, $isValid, self::VALIDATION_CACHE_TTL);

                return $isValid;
            }

            // Log non-success responses
            Log::warning('VIES VAT validation API error', [
                'country_code' => $countryCode,
                'vat_number' => $vatNumberOnly,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // Fail open for availability
            return true;
        } catch (\Exception $e) {
            Log::warning('VIES VAT validation failed', [
                'country_code' => $countryCode,
                'vat_number' => $vatNumberOnly,
                'error' => $e->getMessage(),
            ]);

            // Fail open - don't block transactions if API is unavailable
            return true;
        }
    }

    /**
     * Validate Australian Business Number (ABN).
     */
    protected function validateAbn(string $abn): bool
    {
        // ABN is 11 digits
        $abn = preg_replace('/[^0-9]/', '', $abn);

        if (strlen($abn) !== 11) {
            return false;
        }

        // ABN checksum validation
        $weights = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];

        // Subtract 1 from first digit
        $abn[0] = (int) $abn[0] - 1;

        $sum = 0;
        for ($i = 0; $i < 11; $i++) {
            $sum += (int) $abn[$i] * $weights[$i];
        }

        return $sum % 89 === 0;
    }

    /**
     * Get tax type label.
     */
    public function getTaxTypeLabel(string $type): string
    {
        return match ($type) {
            'vat' => 'VAT',
            'gst' => 'GST',
            'sales_tax' => 'Sales Tax',
            default => 'Tax',
        };
    }
}

/**
 * Tax calculation result.
 */
class TaxResult
{
    public function __construct(
        public readonly float $taxAmount,
        public readonly float $taxRate,
        public readonly ?string $taxType,
        public readonly ?string $jurisdiction,
        public readonly bool $isExempt,
        public readonly ?string $exemptionReason,
    ) {}

    /**
     * Get total including tax.
     */
    public function addToAmount(float $amount): float
    {
        return $amount + $this->taxAmount;
    }

    /**
     * Check if tax applies.
     */
    public function hasTax(): bool
    {
        return $this->taxAmount > 0;
    }

    /**
     * Get formatted rate.
     */
    public function getFormattedRate(): string
    {
        return number_format($this->taxRate, 1).'%';
    }
}
