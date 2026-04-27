<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Services\CommerceService;
use Core\Tenant\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('hub::admin.layouts.app')]
class Invoices extends Component
{
    use WithPagination;

    public ?Workspace $workspace = null;

    public string $status = 'all';

    protected CommerceService $commerce;

    public function boot(CommerceService $commerce): void
    {
        $this->commerce = $commerce;
    }

    public function mount(): void
    {
        $this->workspace = Auth::user()?->defaultHostWorkspace();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function getInvoicesProperty(): LengthAwarePaginator
    {
        if (! $this->workspace) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10);
        }

        $query = $this->workspace->invoices()
            ->with('items')
            ->latest('issued_at');

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        return $query->paginate(10);
    }

    public function formatMoney(float $amount, ?string $currency = null): string
    {
        return $this->commerce->formatMoney($amount, $currency);
    }

    public function render()
    {
        return view('commerce::web.invoices', [
            'invoices' => $this->invoices,
        ]);
    }
}
