<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\Referral;
use Core\Mod\Commerce\Models\ReferralCommission;
use Core\Mod\Commerce\Models\ReferralPayout;
use Core\Mod\Commerce\Services\ReferralService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * User-facing referral dashboard showing earnings and referrals.
 */
#[Layout('hub::web.layouts.app')]
#[Title('Affiliate Dashboard')]
class ReferralDashboard extends Component
{
    use WithPagination;

    // Active tab
    public string $tab = 'overview';

    // Payout request
    public bool $showPayoutModal = false;

    public string $payoutMethod = 'btc';

    public string $payoutBtcAddress = '';

    public ?float $payoutAmount = null;

    public function mount(): void
    {
        // Ensure user has activated referrals
        $user = auth()->user();
        if (! $user->hasActivatedReferrals()) {
            $user->activateReferrals();
        }
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Statistics
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function stats()
    {
        return app(ReferralService::class)->getStatsForUser(auth()->user());
    }

    #[Computed]
    public function referralLink(): string
    {
        $user = auth()->user();
        $namespace = $user->defaultNamespace();

        if ($namespace) {
            return url('/?ref='.$namespace->slug);
        }

        // Fallback to user ID if no namespace
        return url('/?ref=u'.$user->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Referrals
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function referrals()
    {
        return Referral::forReferrer(auth()->id())
            ->with('referee')
            ->latest()
            ->paginate(15);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Commissions
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function commissions()
    {
        return ReferralCommission::forReferrer(auth()->id())
            ->with(['referral.referee', 'order'])
            ->latest()
            ->paginate(15);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Payouts
    // ─────────────────────────────────────────────────────────────────────────

    #[Computed]
    public function payouts()
    {
        return ReferralPayout::forUser(auth()->id())
            ->latest()
            ->paginate(15);
    }

    public function openPayoutModal(): void
    {
        $this->payoutMethod = 'btc';
        $this->payoutBtcAddress = '';
        $this->payoutAmount = null;
        $this->showPayoutModal = true;
    }

    public function requestPayout(ReferralService $referralService): void
    {
        $this->validate([
            'payoutMethod' => ['required', 'in:btc,account_credit'],
            'payoutBtcAddress' => ['required_if:payoutMethod,btc', 'nullable', 'string', 'min:26', 'max:128'],
            'payoutAmount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $availableBalance = $this->stats['available_balance'];

        // Check minimum payout
        $minimum = ReferralPayout::getMinimumPayout($this->payoutMethod);
        if ($availableBalance < $minimum) {
            session()->flash('error', "Minimum payout amount is GBP {$minimum}.");

            return;
        }

        try {
            $referralService->requestPayout(
                auth()->user(),
                $this->payoutMethod,
                $this->payoutAmount,
                $this->payoutMethod === 'btc' ? $this->payoutBtcAddress : null
            );

            session()->flash('message', 'Payout requested successfully.');
            $this->closePayoutModal();
        } catch (\InvalidArgumentException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function cancelPayout(int $id): void
    {
        $payout = ReferralPayout::forUser(auth()->id())->findOrFail($id);

        if (! $payout->isRequested()) {
            session()->flash('error', 'Cannot cancel payout that is already being processed.');

            return;
        }

        $payout->cancel('Cancelled by user');
        session()->flash('message', 'Payout request cancelled.');
    }

    public function closePayoutModal(): void
    {
        $this->showPayoutModal = false;
    }

    public function render()
    {
        return view('commerce::web.referral-dashboard')
            ->layout('hub::web.layouts.app', ['title' => 'Affiliate Dashboard']);
    }
}
