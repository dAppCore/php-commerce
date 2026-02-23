<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Admin;

use Core\Mod\Commerce\Models\Referral;
use Core\Mod\Commerce\Models\ReferralCode;
use Core\Mod\Commerce\Models\ReferralCommission;
use Core\Mod\Commerce\Models\ReferralPayout;
use Core\Mod\Commerce\Services\ReferralService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin dashboard for managing referrals, commissions, and payouts.
 */
#[Layout('hub::admin.layouts.app')]
#[Title('Referrals')]
class ReferralManager extends Component
{
    use WithPagination;

    // Active tab
    public string $tab = 'referrals';

    // Filters
    public string $search = '';

    public string $statusFilter = '';

    // Referral modal
    public bool $showReferralModal = false;

    public ?int $viewingReferralId = null;

    // Payout modal
    public bool $showPayoutModal = false;

    public ?int $processingPayoutId = null;

    public string $payoutBtcTxid = '';

    public ?float $payoutBtcAmount = null;

    public ?float $payoutBtcRate = null;

    public string $payoutFailReason = '';

    // Code modal
    public bool $showCodeModal = false;

    public ?int $editingCodeId = null;

    public string $codeCode = '';

    public ?int $codeUserId = null;

    public string $codeType = 'custom';

    public ?float $codeCommissionRate = null;

    public int $codeCookieDays = 90;

    public ?int $codeMaxUses = null;

    public ?string $codeValidFrom = null;

    public ?string $codeValidUntil = null;

    public bool $codeIsActive = true;

    public ?string $codeCampaignName = null;

    public function mount(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades tier required for referral management.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
        $this->search = '';
        $this->statusFilter = '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Referrals
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function referrals()
    {
        return Referral::with(['referrer', 'referee'])
            ->when($this->search, function ($query) {
                $query->whereHas('referrer', fn ($q) => $q->where('email', 'like', "%{$this->search}%"))
                    ->orWhereHas('referee', fn ($q) => $q->where('email', 'like', "%{$this->search}%"))
                    ->orWhere('code', 'like', "%{$this->search}%");
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->latest()
            ->paginate(25);
    }

    public function viewReferral(int $id): void
    {
        $this->viewingReferralId = $id;
        $this->showReferralModal = true;
    }

    public function disqualifyReferral(int $id, ReferralService $referralService): void
    {
        $referral = Referral::findOrFail($id);
        $referralService->disqualifyReferral($referral, 'Manually disqualified by admin');
        session()->flash('message', 'Referral disqualified.');
        $this->showReferralModal = false;
    }

    public function closeReferralModal(): void
    {
        $this->showReferralModal = false;
        $this->viewingReferralId = null;
    }

    #[Computed]
    public function viewingReferral()
    {
        if (! $this->viewingReferralId) {
            return null;
        }

        return Referral::with(['referrer', 'referee', 'commissions'])
            ->find($this->viewingReferralId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Commissions
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function commissions()
    {
        return ReferralCommission::with(['referrer', 'referral.referee', 'order'])
            ->when($this->search, function ($query) {
                $query->whereHas('referrer', fn ($q) => $q->where('email', 'like', "%{$this->search}%"));
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->latest()
            ->paginate(25);
    }

    public function matureCommissions(ReferralService $referralService): void
    {
        $count = $referralService->matureReadyCommissions();
        session()->flash('message', "{$count} commissions matured.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Payouts
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function payouts()
    {
        return ReferralPayout::with(['user', 'processor'])
            ->when($this->search, function ($query) {
                $query->whereHas('user', fn ($q) => $q->where('email', 'like', "%{$this->search}%"))
                    ->orWhere('payout_number', 'like', "%{$this->search}%");
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->latest()
            ->paginate(25);
    }

    public function openProcessPayout(int $id): void
    {
        $this->processingPayoutId = $id;
        $this->payoutBtcTxid = '';
        $this->payoutBtcAmount = null;
        $this->payoutBtcRate = null;
        $this->payoutFailReason = '';
        $this->showPayoutModal = true;
    }

    public function processPayout(ReferralService $referralService): void
    {
        $payout = ReferralPayout::findOrFail($this->processingPayoutId);
        $referralService->processPayout($payout, auth()->user());
        session()->flash('message', 'Payout marked as processing.');
    }

    public function completePayout(ReferralService $referralService): void
    {
        $payout = ReferralPayout::findOrFail($this->processingPayoutId);
        $referralService->completePayout(
            $payout,
            $this->payoutBtcTxid ?: null,
            $this->payoutBtcAmount,
            $this->payoutBtcRate
        );
        session()->flash('message', 'Payout completed.');
        $this->closePayoutModal();
    }

    public function failPayout(ReferralService $referralService): void
    {
        if (! $this->payoutFailReason) {
            session()->flash('error', 'Please provide a failure reason.');

            return;
        }

        $payout = ReferralPayout::findOrFail($this->processingPayoutId);
        $referralService->failPayout($payout, $this->payoutFailReason);
        session()->flash('message', 'Payout marked as failed.');
        $this->closePayoutModal();
    }

    public function closePayoutModal(): void
    {
        $this->showPayoutModal = false;
        $this->processingPayoutId = null;
    }

    #[Computed]
    public function processingPayout()
    {
        if (! $this->processingPayoutId) {
            return null;
        }

        return ReferralPayout::with(['user', 'commissions'])->find($this->processingPayoutId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Referral Codes
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function codes()
    {
        return ReferralCode::with('user')
            ->when($this->search, function ($query) {
                $query->where('code', 'like', "%{$this->search}%")
                    ->orWhere('campaign_name', 'like', "%{$this->search}%");
            })
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn ($q) => $q->where('is_active', false))
            ->latest()
            ->paginate(25);
    }

    public function openCreateCode(): void
    {
        $this->resetCodeForm();
        $this->codeCode = strtoupper(substr(md5(uniqid()), 0, 8));
        $this->showCodeModal = true;
    }

    public function openEditCode(int $id): void
    {
        $code = ReferralCode::findOrFail($id);
        $this->editingCodeId = $id;
        $this->codeCode = $code->code;
        $this->codeUserId = $code->user_id;
        $this->codeType = $code->type;
        $this->codeCommissionRate = $code->commission_rate;
        $this->codeCookieDays = $code->cookie_days;
        $this->codeMaxUses = $code->max_uses;
        $this->codeValidFrom = $code->valid_from?->format('Y-m-d');
        $this->codeValidUntil = $code->valid_until?->format('Y-m-d');
        $this->codeIsActive = $code->is_active;
        $this->codeCampaignName = $code->campaign_name;
        $this->showCodeModal = true;
    }

    public function saveCode(): void
    {
        $this->validate([
            'codeCode' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'codeType' => ['required', 'in:user,campaign,custom'],
            'codeCommissionRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'codeCookieDays' => ['required', 'integer', 'min:1', 'max:365'],
            'codeMaxUses' => ['nullable', 'integer', 'min:1'],
            'codeValidFrom' => ['nullable', 'date'],
            'codeValidUntil' => ['nullable', 'date', 'after_or_equal:codeValidFrom'],
        ]);

        $data = [
            'code' => strtoupper($this->codeCode),
            'user_id' => $this->codeUserId,
            'type' => $this->codeType,
            'commission_rate' => $this->codeCommissionRate,
            'cookie_days' => $this->codeCookieDays,
            'max_uses' => $this->codeMaxUses,
            'valid_from' => $this->codeValidFrom ? \Carbon\Carbon::parse($this->codeValidFrom) : null,
            'valid_until' => $this->codeValidUntil ? \Carbon\Carbon::parse($this->codeValidUntil) : null,
            'is_active' => $this->codeIsActive,
            'campaign_name' => $this->codeCampaignName,
        ];

        if ($this->editingCodeId) {
            ReferralCode::findOrFail($this->editingCodeId)->update($data);
            session()->flash('message', 'Referral code updated.');
        } else {
            ReferralCode::create($data);
            session()->flash('message', 'Referral code created.');
        }

        $this->closeCodeModal();
    }

    public function toggleCodeActive(int $id): void
    {
        $code = ReferralCode::findOrFail($id);
        $code->update(['is_active' => ! $code->is_active]);
        session()->flash('message', $code->is_active ? 'Code activated.' : 'Code deactivated.');
    }

    public function deleteCode(int $id): void
    {
        $code = ReferralCode::findOrFail($id);
        if ($code->uses_count > 0) {
            session()->flash('error', 'Cannot delete code that has been used.');

            return;
        }
        $code->delete();
        session()->flash('message', 'Referral code deleted.');
    }

    public function closeCodeModal(): void
    {
        $this->showCodeModal = false;
        $this->resetCodeForm();
    }

    protected function resetCodeForm(): void
    {
        $this->editingCodeId = null;
        $this->codeCode = '';
        $this->codeUserId = null;
        $this->codeType = 'custom';
        $this->codeCommissionRate = null;
        $this->codeCookieDays = 90;
        $this->codeMaxUses = null;
        $this->codeValidFrom = null;
        $this->codeValidUntil = null;
        $this->codeIsActive = true;
        $this->codeCampaignName = null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Statistics
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function stats()
    {
        return app(ReferralService::class)->getGlobalStats();
    }

    #[Computed]
    public function statusOptions(): array
    {
        return match ($this->tab) {
            'referrals' => [
                'pending' => 'Pending',
                'converted' => 'Converted',
                'qualified' => 'Qualified',
                'disqualified' => 'Disqualified',
            ],
            'commissions' => [
                'pending' => 'Pending',
                'matured' => 'Matured',
                'paid' => 'Paid',
                'cancelled' => 'Cancelled',
            ],
            'payouts' => [
                'requested' => 'Requested',
                'processing' => 'Processing',
                'completed' => 'Completed',
                'failed' => 'Failed',
                'cancelled' => 'Cancelled',
            ],
            'codes' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
            ],
            default => [],
        };
    }

    public function render()
    {
        return view('commerce::admin.referral-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Referrals']);
    }
}
