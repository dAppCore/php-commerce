<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Tests\Feature;

use Core\Mod\Commerce\Models\ExchangeRate;
use Core\Mod\Commerce\Services\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CurrencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CurrencyService::class);
    }

    public function test_get_base_currency_returns_configured_currency(): void
    {
        config(['commerce.currencies.base' => 'GBP']);

        $this->assertEquals('GBP', $this->service->getBaseCurrency());
    }

    public function test_is_supported_returns_true_for_supported_currencies(): void
    {
        $this->assertTrue($this->service->isSupported('GBP'));
        $this->assertTrue($this->service->isSupported('USD'));
        $this->assertTrue($this->service->isSupported('EUR'));
    }

    public function test_is_supported_returns_false_for_unsupported_currencies(): void
    {
        $this->assertFalse($this->service->isSupported('XYZ'));
        $this->assertFalse($this->service->isSupported('BTC'));
    }

    public function test_format_formats_gbp_correctly(): void
    {
        $formatted = $this->service->format(99.99, 'GBP');

        $this->assertEquals('£99.99', $formatted);
    }

    public function test_format_formats_usd_correctly(): void
    {
        $formatted = $this->service->format(99.99, 'USD');

        $this->assertEquals('$99.99', $formatted);
    }

    public function test_format_formats_eur_correctly(): void
    {
        $formatted = $this->service->format(99.99, 'EUR');

        // EUR uses space as thousands separator and comma as decimal
        $this->assertEquals('€99,99', $formatted);
    }

    public function test_format_handles_cents(): void
    {
        $formatted = $this->service->format(9999, 'GBP', isCents: true);

        $this->assertEquals('£99.99', $formatted);
    }

    public function test_format_handles_large_numbers(): void
    {
        $formatted = $this->service->format(1234567.89, 'GBP');

        $this->assertEquals('£1,234,567.89', $formatted);
    }

    public function test_get_symbol_returns_correct_symbols(): void
    {
        $this->assertEquals('£', $this->service->getSymbol('GBP'));
        $this->assertEquals('$', $this->service->getSymbol('USD'));
        $this->assertEquals('€', $this->service->getSymbol('EUR'));
        $this->assertEquals('A$', $this->service->getSymbol('AUD'));
    }

    public function test_exchange_rate_model_stores_and_retrieves_rates(): void
    {
        ExchangeRate::storeRate('GBP', 'USD', 1.27, 'test');

        $rate = ExchangeRate::getRate('GBP', 'USD');

        $this->assertEquals(1.27, $rate);
    }

    public function test_exchange_rate_converts_amounts(): void
    {
        ExchangeRate::storeRate('GBP', 'USD', 1.27, 'test');

        $converted = ExchangeRate::convert(100, 'GBP', 'USD');

        $this->assertEquals(127.0, $converted);
    }

    public function test_exchange_rate_converts_cents(): void
    {
        ExchangeRate::storeRate('GBP', 'USD', 1.27, 'test');

        $converted = ExchangeRate::convertCents(10000, 'GBP', 'USD');

        $this->assertEquals(12700, $converted);
    }

    public function test_exchange_rate_same_currency_returns_one(): void
    {
        $rate = ExchangeRate::getRate('GBP', 'GBP');

        $this->assertEquals(1.0, $rate);
    }

    public function test_exchange_rate_calculates_inverse(): void
    {
        ExchangeRate::storeRate('GBP', 'USD', 1.27, 'test');

        $inverseRate = ExchangeRate::getRate('USD', 'GBP');

        $this->assertEqualsWithDelta(0.7874, $inverseRate, 0.001);
    }

    public function test_exchange_rate_uses_fixed_fallback(): void
    {
        config(['commerce.currencies.exchange_rates.fixed' => [
            'GBP_USD' => 1.25,
        ]]);

        // Clear any cached rates
        cache()->forget('exchange_rate:GBP:USD');

        $rate = ExchangeRate::getRate('GBP', 'USD');

        $this->assertEquals(1.25, $rate);
    }

    public function test_currency_service_convert_uses_exchange_rates(): void
    {
        ExchangeRate::storeRate('GBP', 'EUR', 1.17, 'test');

        $converted = $this->service->convert(100, 'GBP', 'EUR');

        $this->assertEquals(117.0, $converted);
    }

    public function test_currency_service_convert_cents(): void
    {
        ExchangeRate::storeRate('GBP', 'EUR', 1.17, 'test');

        $converted = $this->service->convertCents(10000, 'GBP', 'EUR');

        $this->assertEquals(11700, $converted);
    }

    public function test_set_and_get_current_currency(): void
    {
        $this->service->setCurrentCurrency('USD');

        $this->assertEquals('USD', $this->service->getCurrentCurrency());
    }

    public function test_set_currency_rejects_unsupported(): void
    {
        $original = $this->service->getCurrentCurrency();
        $this->service->setCurrentCurrency('XYZ');

        // Should not change from original
        $this->assertEquals($original, $this->service->getCurrentCurrency());
    }

    public function test_get_js_data_returns_all_currencies(): void
    {
        ExchangeRate::storeRate('GBP', 'USD', 1.27, 'test');
        ExchangeRate::storeRate('GBP', 'EUR', 1.17, 'test');

        $data = $this->service->getJsData();

        $this->assertArrayHasKey('base', $data);
        $this->assertArrayHasKey('current', $data);
        $this->assertArrayHasKey('currencies', $data);

        $this->assertEquals('GBP', $data['base']);
        $this->assertArrayHasKey('GBP', $data['currencies']);
        $this->assertArrayHasKey('USD', $data['currencies']);
        $this->assertArrayHasKey('EUR', $data['currencies']);

        $this->assertEquals(1.27, $data['currencies']['USD']['rate']);
    }
}
