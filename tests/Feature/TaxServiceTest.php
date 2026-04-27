<?php

declare(strict_types=1);

use Core\Mod\Commerce\Models\TaxRate;
use Core\Mod\Commerce\Services\TaxService;
use Core\Tenant\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::factory()->create([
        'billing_country' => 'GB',
        'billing_state' => null,
        'vat_number' => null,
    ]);

    // Seed essential tax rates
    TaxRate::create([
        'country_code' => 'GB',
        'name' => 'UK VAT',
        'type' => 'vat',
        'rate' => 20.00,
        'is_digital_services' => true,
        'effective_from' => '2020-01-01',
        'is_active' => true,
    ]);

    TaxRate::create([
        'country_code' => 'DE',
        'name' => 'Germany VAT',
        'type' => 'vat',
        'rate' => 19.00,
        'is_digital_services' => true,
        'effective_from' => '2020-01-01',
        'is_active' => true,
    ]);

    TaxRate::create([
        'country_code' => 'US',
        'state_code' => 'TX',
        'name' => 'Texas Sales Tax',
        'type' => 'sales_tax',
        'rate' => 6.25,
        'is_digital_services' => true,
        'effective_from' => '2020-01-01',
        'is_active' => true,
    ]);

    TaxRate::create([
        'country_code' => 'US',
        'name' => 'US Federal (No Tax)',
        'type' => 'sales_tax',
        'rate' => 0.00,
        'is_digital_services' => true,
        'effective_from' => '2020-01-01',
        'is_active' => true,
    ]);

    TaxRate::create([
        'country_code' => 'AU',
        'name' => 'Australia GST',
        'type' => 'gst',
        'rate' => 10.00,
        'is_digital_services' => true,
        'effective_from' => '2020-01-01',
        'is_active' => true,
    ]);

    $this->service = app(TaxService::class);
});

describe('TaxService', function () {
    describe('calculate() method', function () {
        it('calculates UK VAT at 20%', function () {
            $this->workspace->update(['billing_country' => 'GB']);

            $result = $this->service->calculate($this->workspace, 100.00);

            expect($result->taxRate)->toBe(20.0)
                ->and($result->taxAmount)->toBe(20.00)
                ->and($result->jurisdiction)->toBe('GB')
                ->and($result->taxType)->toBe('vat');
        });

        it('calculates German VAT at 19%', function () {
            $this->workspace->update(['billing_country' => 'DE']);

            $result = $this->service->calculate($this->workspace, 100.00);

            expect($result->taxRate)->toBe(19.0)
                ->and($result->taxAmount)->toBe(19.00)
                ->and($result->jurisdiction)->toBe('DE');
        });

        it('calculates Texas sales tax at 6.25%', function () {
            $this->workspace->update([
                'billing_country' => 'US',
                'billing_state' => 'TX',
            ]);

            $result = $this->service->calculate($this->workspace, 100.00);

            expect($result->taxRate)->toBe(6.25)
                ->and($result->taxAmount)->toBe(6.25)
                ->and($result->jurisdiction)->toBe('US-TX');
        });

        it('falls back to federal rate for US states without specific rate', function () {
            $this->workspace->update([
                'billing_country' => 'US',
                'billing_state' => 'MT', // Montana - no state sales tax
            ]);

            $result = $this->service->calculate($this->workspace, 100.00);

            expect($result->taxRate)->toBe(0.0)
                ->and($result->taxAmount)->toBe(0.00);
        });

        it('calculates Australian GST at 10%', function () {
            $this->workspace->update(['billing_country' => 'AU']);

            $result = $this->service->calculate($this->workspace, 100.00);

            expect($result->taxRate)->toBe(10.0)
                ->and($result->taxAmount)->toBe(10.00)
                ->and($result->taxType)->toBe('gst');
        });

        it('returns zero tax for countries without rates', function () {
            $this->workspace->update(['billing_country' => 'XX']); // Unknown

            $result = $this->service->calculate($this->workspace, 100.00);

            expect($result->taxRate)->toBe(0.0)
                ->and($result->taxAmount)->toBe(0.00);
        });

        it('rounds tax amount to two decimal places', function () {
            $this->workspace->update(['billing_country' => 'GB']);

            $result = $this->service->calculate($this->workspace, 33.33);

            expect($result->taxAmount)->toBe(6.67); // 33.33 * 0.20 = 6.666
        });
    });

    describe('B2B reverse charge', function () {
        it('applies zero rate for valid EU VAT numbers', function () {
            $this->workspace->update([
                'billing_country' => 'DE',
                'tax_id' => 'DE123456789',
            ]);

            $result = $this->service->calculate($this->workspace, 100.00);

            expect($result->isExempt)->toBeTrue()
                ->and($result->taxAmount)->toBe(0.00)
                ->and($result->exemptionReason)->toContain('reverse charge');
        });

        it('does not apply reverse charge for UK to UK sales', function () {
            $this->workspace->update([
                'billing_country' => 'GB',
                'tax_id' => 'GB123456789',
            ]);

            $result = $this->service->calculate($this->workspace, 100.00);

            // UK to UK is not reverse charge
            expect($result->taxRate)->toBe(20.0)
                ->and($result->taxAmount)->toBe(20.00);
        });
    });

    describe('getRateForLocation() method', function () {
        it('returns rate for country', function () {
            $rate = $this->service->getRateForLocation('GB');

            expect($rate)->not->toBeNull()
                ->and((float) $rate->rate)->toBe(20.00);
        });

        it('returns state-specific rate when available', function () {
            $rate = $this->service->getRateForLocation('US', 'TX');

            expect($rate)->not->toBeNull()
                ->and((float) $rate->rate)->toBe(6.25)
                ->and($rate->state_code)->toBe('TX');
        });

        it('returns null for unknown location', function () {
            $rate = $this->service->getRateForLocation('XX');

            expect($rate)->toBeNull();
        });
    });
});

describe('TaxRate model', function () {
    it('calculates tax correctly', function () {
        $rate = TaxRate::where('country_code', 'GB')->first();

        expect($rate->calculateTax(100.00))->toBe(20.00)
            ->and($rate->calculateTax(50.00))->toBe(10.00);
    });

    it('checks if rate is effective', function () {
        $rate = TaxRate::where('country_code', 'GB')->first();

        expect($rate->isEffective())->toBeTrue();

        // Create a future rate
        $futureRate = TaxRate::create([
            'country_code' => 'ZZ',
            'name' => 'Future Tax',
            'type' => 'vat',
            'rate' => 25.00,
            'is_digital_services' => true,
            'effective_from' => now()->addYear(),
            'is_active' => true,
        ]);

        expect($futureRate->isEffective())->toBeFalse();
    });

    it('scopes to effective rates', function () {
        $effectiveRates = TaxRate::effective()->get();

        expect($effectiveRates->count())->toBeGreaterThan(0);
        expect($effectiveRates->every(fn ($r) => $r->isEffective()))->toBeTrue();
    });

    it('finds rate for location', function () {
        $rate = TaxRate::findForLocation('GB');

        expect($rate)->not->toBeNull()
            ->and($rate->country_code)->toBe('GB');
    });
});
