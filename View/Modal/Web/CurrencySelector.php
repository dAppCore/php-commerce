<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Services\CurrencyService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Currency selector component.
 *
 * Allows users to select their preferred display currency.
 * Stores selection in session and emits events for parent components.
 */
class CurrencySelector extends Component
{
    /**
     * Currently selected currency code.
     */
    public string $selected = '';

    /**
     * Show as dropdown or inline buttons.
     */
    public string $style = 'dropdown';

    /**
     * Show currency flags.
     */
    public bool $showFlags = true;

    /**
     * Show currency names (vs just codes).
     */
    public bool $showNames = false;

    /**
     * Compact mode for headers.
     */
    public bool $compact = false;

    protected CurrencyService $currencyService;

    public function boot(CurrencyService $currencyService): void
    {
        $this->currencyService = $currencyService;
    }

    public function mount(): void
    {
        $this->selected = $this->currencyService->getCurrentCurrency();
    }

    #[Computed]
    public function currencies(): array
    {
        $supported = $this->currencyService->getSupportedCurrencies();
        $baseCurrency = $this->currencyService->getBaseCurrency();
        $currencies = [];

        foreach ($supported as $code => $config) {
            $currencies[$code] = [
                'code' => $code,
                'name' => $config['name'],
                'symbol' => $config['symbol'],
                'flag' => $config['flag'] ?? strtolower(substr($code, 0, 2)),
                'isBase' => $code === $baseCurrency,
            ];
        }

        return $currencies;
    }

    #[Computed]
    public function currentCurrency(): array
    {
        return $this->currencies[$this->selected] ?? [
            'code' => $this->selected,
            'name' => $this->selected,
            'symbol' => $this->selected,
            'flag' => '',
            'isBase' => false,
        ];
    }

    /**
     * Select a currency.
     */
    public function selectCurrency(string $currency): void
    {
        $currency = strtoupper($currency);

        if (! $this->currencyService->isSupported($currency)) {
            return;
        }

        $this->selected = $currency;
        $this->currencyService->setCurrentCurrency($currency);

        // Emit event for parent components
        $this->dispatch('currency-changed', currency: $currency);
    }

    public function render()
    {
        return view('commerce::web.components.currency-selector');
    }
}
