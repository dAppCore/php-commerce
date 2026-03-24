<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Admin;

use Core\Mod\Commerce\Models\Coupon;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Commerce Dashboard')]
class Dashboard extends Component
{
    public function mount(): void
    {
        $this->checkHadesAccess();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total_revenue' => Order::where('status', 'completed')->sum('total') / 100,
            'monthly_revenue' => Order::where('status', 'completed')
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('total') / 100,
            'total_orders' => Order::count(),
            'pending_orders' => Order::whereIn('status', ['pending', 'processing'])->count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
            'total_subscriptions' => Subscription::count(),
            'active_coupons' => Coupon::where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('valid_until')
                        ->orWhere('valid_until', '>', now());
                })->count(),
        ];
    }

    #[Computed]
    public function recentOrders(): Collection
    {
        return Order::with('workspace')
            ->latest()
            ->take(5)
            ->get();
    }

    #[Computed]
    public function statCards(): array
    {
        return [
            ['value' => '£'.number_format($this->stats['total_revenue'], 2), 'label' => 'Total Revenue', 'icon' => 'sterling-sign', 'color' => 'green'],
            ['value' => '£'.number_format($this->stats['monthly_revenue'], 2), 'label' => 'This Month', 'icon' => 'calendar', 'color' => 'blue'],
            ['value' => number_format($this->stats['total_orders']), 'label' => 'Total Orders', 'icon' => 'shopping-cart', 'color' => 'orange'],
            ['value' => number_format($this->stats['active_subscriptions']), 'label' => 'Active Subscriptions', 'icon' => 'repeat', 'color' => 'purple'],
        ];
    }

    #[Computed]
    public function quickActions(): array
    {
        return [
            ['href' => route('hub.commerce.orders'), 'title' => 'Orders', 'subtitle' => $this->stats['pending_orders'].' pending', 'icon' => 'shopping-cart', 'color' => 'orange'],
            ['href' => route('hub.commerce.subscriptions'), 'title' => 'Subscriptions', 'subtitle' => $this->stats['total_subscriptions'].' total', 'icon' => 'repeat', 'color' => 'purple'],
            ['href' => route('hub.commerce.coupons'), 'title' => 'Coupons', 'subtitle' => $this->stats['active_coupons'].' active', 'icon' => 'ticket', 'color' => 'green'],
            ['href' => route('hub.commerce.entities'), 'title' => 'Entities', 'subtitle' => 'M1/M2/M3 hierarchy', 'icon' => 'sitemap', 'color' => 'blue'],
            ['href' => route('hub.commerce.permissions'), 'title' => 'Permissions', 'subtitle' => 'Matrix training', 'icon' => 'shield', 'color' => 'amber'],
            ['href' => route('hub.commerce.products'), 'title' => 'Products', 'subtitle' => 'Master catalog', 'icon' => 'boxes-stacked', 'color' => 'indigo'],
        ];
    }

    #[Computed]
    public function orderRows(): array
    {
        return $this->recentOrders->map(fn ($o) => [
            ['mono' => '#'.$o->id],
            ['muted' => $o->workspace?->name ?? 'N/A'],
            ['badge' => ucfirst($o->status), 'color' => match ($o->status) {
                'completed' => 'green',
                'pending' => 'yellow',
                'processing' => 'blue',
                'cancelled', 'refunded' => 'red',
                default => 'gray',
            }],
            ['bold' => '£'.number_format($o->total / 100, 2)],
        ])->all();
    }

    #[Computed]
    public function revenueByMonth(): array
    {
        return Order::where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(6))
            ->select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                DB::raw('SUM(total) / 100 as revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('revenue', 'month')
            ->toArray();
    }

    private function checkHadesAccess(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades access required');
        }
    }

    public function render(): View
    {
        return view('commerce::admin.dashboard')
            ->layout('hub::admin.layouts.app', ['title' => 'Commerce Dashboard']);
    }
}
