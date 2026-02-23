<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Admin;

use Core\Mod\Commerce\Models\CreditNote;
use Core\Mod\Commerce\Services\CreditNoteService;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Credit Notes')]
class CreditNoteManager extends Component
{
    use WithPagination;

    // Bulk selection
    public array $selected = [];

    public bool $selectAll = false;

    // Filters
    public string $search = '';

    public string $statusFilter = '';

    public string $reasonFilter = '';

    public string $dateRange = '';

    public ?int $workspaceFilter = null;

    // Detail modal
    public bool $showDetailModal = false;

    public ?CreditNote $selectedCreditNote = null;

    // Create modal
    public bool $showCreateModal = false;

    public ?int $workspaceId = null;

    public ?int $userId = null;

    public string $amount = '';

    public string $reason = '';

    public string $description = '';

    public string $currency = 'GBP';

    // Void confirmation modal
    public bool $showVoidModal = false;

    public ?CreditNote $creditNoteToVoid = null;

    /**
     * Authorize access - Hades tier only.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades tier required for credit note management.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->selectAll = false;
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->selectAll = false;
    }

    public function updatingReasonFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->selectAll = false;
    }

    public function updatingDateRange(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->selectAll = false;
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selected = $this->creditNotes->pluck('id')->map(fn ($id) => (string) $id)->all();
        } else {
            $this->selected = [];
        }
    }

    public function openCreate(): void
    {
        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->resetCreateForm();
    }

    public function resetCreateForm(): void
    {
        $this->workspaceId = null;
        $this->userId = null;
        $this->amount = '';
        $this->reason = '';
        $this->description = '';
        $this->currency = 'GBP';
    }

    public function create(): void
    {
        $this->validate([
            'workspaceId' => 'required|exists:workspaces,id',
            'userId' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string',
            'description' => 'nullable|string|max:1000',
            'currency' => 'required|string|size:3',
        ]);

        $service = app(CreditNoteService::class);
        $workspace = Workspace::findOrFail($this->workspaceId);
        $user = User::findOrFail($this->userId);

        $creditNote = $service->create(
            workspace: $workspace,
            user: $user,
            amount: (float) $this->amount,
            reason: $this->reason,
            description: $this->description ?: null,
            currency: $this->currency,
            issuedBy: auth()->user(),
            issueImmediately: true
        );

        session()->flash('message', "Credit note {$creditNote->reference_number} created and issued.");
        $this->closeCreateModal();
    }

    public function viewCreditNote(int $id): void
    {
        $this->selectedCreditNote = CreditNote::with([
            'workspace',
            'user',
            'order',
            'refund',
            'appliedToOrder',
            'issuedByUser',
            'voidedByUser',
        ])->findOrFail($id);

        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedCreditNote = null;
    }

    public function confirmVoid(int $id): void
    {
        $this->creditNoteToVoid = CreditNote::findOrFail($id);
        $this->showVoidModal = true;
    }

    public function closeVoidModal(): void
    {
        $this->showVoidModal = false;
        $this->creditNoteToVoid = null;
    }

    public function voidCreditNote(): void
    {
        if (! $this->creditNoteToVoid) {
            return;
        }

        try {
            $service = app(CreditNoteService::class);
            $service->void($this->creditNoteToVoid, auth()->user());
            session()->flash('message', "Credit note {$this->creditNoteToVoid->reference_number} has been voided.");
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->closeVoidModal();
    }

    public function exportSelected(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', 'No credit notes selected.');

            return;
        }

        $creditNotes = CreditNote::whereIn('id', $this->selected)->with(['user', 'workspace'])->get();

        $csv = "Reference,Workspace,User,Amount,Currency,Status,Reason,Issued At,Created\n";
        foreach ($creditNotes as $cn) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $cn->reference_number,
                str_replace(',', ' ', $cn->workspace?->name ?? 'N/A'),
                str_replace(',', ' ', $cn->user?->name ?? 'N/A'),
                number_format($cn->amount, 2),
                $cn->currency,
                $cn->status,
                str_replace(',', ' ', $cn->reason),
                $cn->issued_at?->format('Y-m-d H:i:s') ?? 'Not issued',
                $cn->created_at->format('Y-m-d H:i:s')
            );
        }

        $this->dispatch('download-csv', filename: 'credit-notes-export-'.now()->format('Y-m-d').'.csv', content: $csv);
        session()->flash('message', 'Exported '.count($this->selected).' credit notes.');
        $this->selected = [];
        $this->selectAll = false;
    }

    #[Computed]
    public function creditNotes()
    {
        return CreditNote::query()
            ->with(['workspace', 'user', 'order', 'refund'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('reference_number', 'like', "%{$this->search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")
                            ->orWhere('email', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->reasonFilter, fn ($q) => $q->where('reason', $this->reasonFilter))
            ->when($this->dateRange, function ($query) {
                $startDate = match ($this->dateRange) {
                    'today' => now()->startOfDay(),
                    '7d' => now()->subDays(7)->startOfDay(),
                    '30d' => now()->subDays(30)->startOfDay(),
                    '90d' => now()->subDays(90)->startOfDay(),
                    'this_month' => now()->startOfMonth(),
                    'last_month' => now()->subMonth()->startOfMonth(),
                    default => null,
                };

                if ($startDate) {
                    $query->where('created_at', '>=', $startDate);
                }

                if ($this->dateRange === 'last_month') {
                    $query->where('created_at', '<', now()->startOfMonth());
                }
            })
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
    public function users()
    {
        return User::query()
            ->when($this->workspaceId, function ($q) {
                $q->whereHas('workspaces', fn ($w) => $w->where('workspace_id', $this->workspaceId));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    #[Computed]
    public function statuses(): array
    {
        return [
            'draft' => 'Draft',
            'issued' => 'Issued',
            'partially_applied' => 'Partially Applied',
            'applied' => 'Applied',
            'void' => 'Void',
        ];
    }

    #[Computed]
    public function reasons(): array
    {
        return CreditNote::reasons();
    }

    #[Computed]
    public function dateRangeOptions(): array
    {
        return [
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
        ];
    }

    #[Computed]
    public function tableColumns(): array
    {
        return [
            'Reference',
            'Customer',
            ['label' => 'Amount', 'align' => 'right'],
            ['label' => 'Used', 'align' => 'right'],
            'Reason',
            ['label' => 'Status', 'align' => 'center'],
            'Date',
            ['label' => 'Actions', 'align' => 'center'],
        ];
    }

    #[Computed]
    public function tableRowIds(): array
    {
        return $this->creditNotes->pluck('id')->all();
    }

    #[Computed]
    public function tableRows(): array
    {
        $statusColors = [
            'draft' => 'gray',
            'issued' => 'blue',
            'partially_applied' => 'amber',
            'applied' => 'green',
            'void' => 'red',
        ];

        return $this->creditNotes->map(function ($cn) use ($statusColors) {
            $remaining = $cn->getRemainingAmount();

            return [
                [
                    'lines' => [
                        ['mono' => $cn->reference_number],
                        ['muted' => $cn->workspace?->name],
                    ],
                ],
                [
                    'lines' => [
                        ['bold' => $cn->user?->name ?? 'Unknown'],
                        ['muted' => $cn->user?->email ?? ''],
                    ],
                ],
                [
                    'lines' => [
                        ['bold' => $cn->currency.' '.number_format($cn->amount, 2)],
                    ],
                ],
                [
                    'lines' => [
                        ['bold' => $cn->currency.' '.number_format($cn->amount_used, 2)],
                        ['muted' => $remaining > 0 ? number_format($remaining, 2).' remaining' : 'Fully used'],
                    ],
                ],
                ['badge' => $cn->getReasonLabel(), 'color' => 'gray'],
                ['badge' => ucfirst(str_replace('_', ' ', $cn->status)), 'color' => $statusColors[$cn->status] ?? 'gray'],
                [
                    'lines' => [
                        ['bold' => $cn->created_at->format('d M Y')],
                        ['muted' => $cn->created_at->format('H:i')],
                    ],
                ],
                [
                    'actions' => array_filter([
                        ['icon' => 'eye', 'click' => "viewCreditNote({$cn->id})", 'title' => 'View details'],
                        $cn->isUsable() && $cn->amount_used == 0
                            ? ['icon' => 'x-mark', 'click' => "confirmVoid({$cn->id})", 'title' => 'Void credit note']
                            : null,
                    ]),
                ],
            ];
        })->all();
    }

    #[Computed]
    public function summaryStats(): array
    {
        $query = CreditNote::query()
            ->when($this->workspaceFilter, fn ($q) => $q->where('workspace_id', $this->workspaceFilter));

        return [
            'total_issued' => (clone $query)->sum('amount'),
            'total_used' => (clone $query)->sum('amount_used'),
            'total_available' => (clone $query)->usable()->selectRaw('SUM(amount - amount_used)')->value('SUM(amount - amount_used)') ?? 0,
            'count_active' => (clone $query)->usable()->count(),
        ];
    }

    public function render()
    {
        return view('commerce::admin.credit-note-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Credit Notes']);
    }
}
