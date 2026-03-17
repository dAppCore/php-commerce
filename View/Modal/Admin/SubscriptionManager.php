<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Admin;

use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\SubscriptionService;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Subscriptions')]
class SubscriptionManager extends Component
{
    use WithPagination;

    // Bulk selection
    public array $selected = [];

    public bool $selectAll = false;

    // Filters
    public string $search = '';

    public string $statusFilter = '';

    public string $gatewayFilter = '';

    public ?int $workspaceFilter = null;

    // Detail modal
    public bool $showDetailModal = false;

    public ?Subscription $selectedSubscription = null;

    // Status update modal
    public bool $showStatusModal = false;

    public string $newStatus = '';

    public string $statusNote = '';

    // Extend period modal
    public bool $showExtendModal = false;

    public int $extendDays = 30;

    /**
     * Authorize access - Hades tier only.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades tier required for subscription management.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->selectAll = false;
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selected = $this->subscriptions->pluck('id')->map(fn ($id) => (string) $id)->all();
        } else {
            $this->selected = [];
        }
    }

    public function exportSelected(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('commerce::commerce.bulk.no_selection'));

            return;
        }

        $subscriptions = Subscription::with(['workspace', 'workspacePackage.package'])->whereIn('id', $this->selected)->get();

        $csv = "Workspace,Package,Gateway,Status,Billing Cycle,Period End,Created\n";
        foreach ($subscriptions as $sub) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                str_replace(',', ' ', $sub->workspace?->name ?? 'Unknown'),
                str_replace(',', ' ', $sub->workspacePackage?->package?->name ?? 'Unknown'),
                $sub->gateway ?? 'unknown',
                $sub->status,
                $sub->billing_cycle ?? 'monthly',
                $sub->current_period_end?->format('Y-m-d') ?? '',
                $sub->created_at->format('Y-m-d H:i:s')
            );
        }

        $this->dispatch('download-csv', filename: 'subscriptions-export-'.now()->format('Y-m-d').'.csv', content: $csv);
        session()->flash('message', __('commerce::commerce.bulk.export_success', ['count' => count($this->selected)]));
        $this->selected = [];
        $this->selectAll = false;
    }

    public function bulkUpdateStatus(string $status): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('commerce::commerce.bulk.no_selection'));

            return;
        }

        $validStatuses = ['active', 'trialing', 'past_due', 'paused', 'cancelled', 'incomplete', 'expired'];

        if (! in_array($status, $validStatuses)) {
            session()->flash('error', 'Invalid status selected.');

            return;
        }

        $count = Subscription::whereIn('id', $this->selected)->update([
            'status' => $status,
        ]);

        // Handle status-specific updates
        if ($status === 'cancelled') {
            Subscription::whereIn('id', $this->selected)->update([
                'cancelled_at' => now(),
                'ended_at' => now(),
            ]);
        } elseif ($status === 'paused') {
            Subscription::whereIn('id', $this->selected)->update(['paused_at' => now()]);
        }

        session()->flash('message', __('commerce::commerce.bulk.status_updated', ['count' => $count, 'status' => ucfirst($status)]));
        $this->selected = [];
        $this->selectAll = false;
    }

    public function bulkExtendPeriod(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('commerce::commerce.bulk.no_selection'));

            return;
        }

        $count = Subscription::whereIn('id', $this->selected)
            ->whereNotNull('current_period_end')
            ->update([
                'current_period_end' => DB::raw('DATE_ADD(current_period_end, INTERVAL 30 DAY)'),
            ]);

        session()->flash('message', __('commerce::commerce.bulk.period_extended', ['count' => $count, 'days' => 30]));
        $this->selected = [];
        $this->selectAll = false;
    }

    public function viewSubscription(int $id): void
    {
        $this->selectedSubscription = Subscription::with([
            'workspace',
            'workspacePackage.package',
        ])->findOrFail($id);

        $this->showDetailModal = true;
    }

    public function openStatusChange(int $id): void
    {
        $this->selectedSubscription = Subscription::findOrFail($id);
        $this->newStatus = $this->selectedSubscription->status;
        $this->statusNote = '';
        $this->showStatusModal = true;
    }

    public function updateStatus(): void
    {
        if (! $this->selectedSubscription) {
            return;
        }

        $validStatuses = ['active', 'trialing', 'past_due', 'paused', 'cancelled', 'incomplete', 'expired'];

        if (! in_array($this->newStatus, $validStatuses)) {
            session()->flash('error', 'Invalid status selected.');

            return;
        }

        $oldStatus = $this->selectedSubscription->status;

        $this->selectedSubscription->update([
            'status' => $this->newStatus,
            'metadata' => array_merge($this->selectedSubscription->metadata ?? [], [
                'status_history' => array_merge(
                    $this->selectedSubscription->metadata['status_history'] ?? [],
                    [[
                        'from' => $oldStatus,
                        'to' => $this->newStatus,
                        'note' => $this->statusNote ?: null,
                        'by' => auth()->id(),
                        'at' => now()->toIso8601String(),
                    ]]
                ),
            ]),
        ]);

        // Handle status-specific updates
        if ($this->newStatus === 'cancelled') {
            $this->selectedSubscription->update([
                'cancelled_at' => now(),
                'ended_at' => now(),
            ]);
        } elseif ($this->newStatus === 'paused') {
            $this->selectedSubscription->update(['paused_at' => now()]);
        } elseif ($this->newStatus === 'active' && $oldStatus === 'paused') {
            $this->selectedSubscription->update(['paused_at' => null]);
        }

        session()->flash('message', "Subscription status updated from {$oldStatus} to {$this->newStatus}.");
        $this->closeStatusModal();
    }

    public function openExtendPeriod(int $id): void
    {
        $this->selectedSubscription = Subscription::findOrFail($id);
        $this->extendDays = 30;
        $this->showExtendModal = true;
    }

    public function extendPeriod(): void
    {
        if (! $this->selectedSubscription) {
            return;
        }

        $newEndDate = $this->selectedSubscription->current_period_end->addDays($this->extendDays);

        $this->selectedSubscription->update([
            'current_period_end' => $newEndDate,
            'metadata' => array_merge($this->selectedSubscription->metadata ?? [], [
                'period_extensions' => array_merge(
                    $this->selectedSubscription->metadata['period_extensions'] ?? [],
                    [[
                        'days' => $this->extendDays,
                        'by' => auth()->id(),
                        'at' => now()->toIso8601String(),
                    ]]
                ),
            ]),
        ]);

        session()->flash('message', "Subscription extended by {$this->extendDays} days.");
        $this->closeExtendModal();
    }

    public function cancelSubscription(int $id): void
    {
        $subscription = Subscription::findOrFail($id);

        app(SubscriptionService::class)->cancel($subscription, 'Cancelled by admin');

        session()->flash('message', 'Subscription cancelled.');
    }

    public function resumeSubscription(int $id): void
    {
        $subscription = Subscription::findOrFail($id);

        app(SubscriptionService::class)->resume($subscription);

        session()->flash('message', 'Subscription resumed.');
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedSubscription = null;
    }

    public function closeStatusModal(): void
    {
        $this->showStatusModal = false;
        $this->selectedSubscription = null;
        $this->newStatus = '';
        $this->statusNote = '';
    }

    public function closeExtendModal(): void
    {
        $this->showExtendModal = false;
        $this->selectedSubscription = null;
        $this->extendDays = 30;
    }

    #[Computed]
    public function subscriptions()
    {
        return Subscription::query()
            ->with(['workspace', 'workspacePackage.package'])
            ->when($this->search, function ($query) {
                $query->whereHas('workspace', fn ($q) => $q->where('name', 'like', "%{$this->search}%"));
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->gatewayFilter, fn ($q) => $q->where('gateway', $this->gatewayFilter))
            ->when($this->workspaceFilter, fn ($q) => $q->where('workspace_id', $this->workspaceFilter))
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function workspaces()
    {
        return Workspace::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function statuses(): array
    {
        return [
            'active' => 'Active',
            'trialing' => 'Trialing',
            'past_due' => 'Past Due',
            'paused' => 'Paused',
            'cancelled' => 'Cancelled',
            'incomplete' => 'Incomplete',
            'expired' => 'Expired',
        ];
    }

    #[Computed]
    public function gateways(): array
    {
        return [
            'stripe' => 'Stripe',
            'btcpay' => 'BTCPay',
        ];
    }

    #[Computed]
    public function tableColumns(): array
    {
        return [
            'Workspace',
            'Package',
            'Gateway',
            ['label' => 'Status', 'align' => 'center'],
            'Billing',
            'Period Ends',
            ['label' => 'Actions', 'align' => 'center'],
        ];
    }

    #[Computed]
    public function tableRowIds(): array
    {
        return $this->subscriptions->pluck('id')->all();
    }

    #[Computed]
    public function tableRows(): array
    {
        return $this->subscriptions->map(function ($s) {
            $statusColors = [
                'active' => 'green',
                'trialing' => 'blue',
                'past_due' => 'amber',
                'paused' => 'gray',
                'cancelled' => 'red',
                'incomplete' => 'amber',
                'expired' => 'gray',
            ];

            $actions = [
                ['icon' => 'eye', 'click' => "viewSubscription({$s->id})", 'title' => 'View details'],
                ['icon' => 'pencil', 'click' => "openStatusChange({$s->id})", 'title' => 'Change status'],
                ['icon' => 'clock', 'click' => "openExtendPeriod({$s->id})", 'title' => 'Extend period'],
            ];

            if ($s->cancelled_at && ! $s->ended_at) {
                $actions[] = ['icon' => 'play', 'click' => "resumeSubscription({$s->id})", 'title' => 'Resume', 'class' => 'text-green-600'];
            } elseif ($s->isActive()) {
                $actions[] = ['icon' => 'x-mark', 'click' => "cancelSubscription({$s->id})", 'confirm' => 'Are you sure you want to cancel this subscription?', 'title' => 'Cancel', 'class' => 'text-red-600'];
            }

            return [
                ['bold' => $s->workspace?->name ?? 'Unknown'],
                [
                    'lines' => [
                        ['bold' => $s->workspacePackage?->package?->name ?? 'Unknown'],
                        ['mono' => $s->workspacePackage?->package?->code],
                    ],
                ],
                ['badge' => ucfirst($s->gateway ?? 'unknown'), 'color' => 'gray'],
                [
                    'lines' => array_filter([
                        ['badge' => ucfirst($s->status), 'color' => $statusColors[$s->status] ?? 'gray'],
                        $s->cancel_at_period_end ? ['muted' => 'Cancels at period end'] : null,
                    ]),
                ],
                ucfirst($s->billing_cycle ?? 'monthly'),
                $s->current_period_end
                    ? [
                        'lines' => [
                            ['bold' => $s->current_period_end->format('d M Y')],
                            ['muted' => $s->current_period_end->isPast()
                                ? 'Ended '.$s->current_period_end->diffForHumans()
                                : $s->current_period_end->diffForHumans()],
                        ],
                    ]
                    : '-',
                ['actions' => $actions],
            ];
        })->all();
    }

    public function render()
    {
        return view('commerce::admin.subscription-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Subscriptions']);
    }
}
