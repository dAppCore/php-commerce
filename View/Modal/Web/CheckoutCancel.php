<?php

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\Order;
use Core\Tenant\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('shared::layouts.checkout')]
class CheckoutCancel extends Component
{
    public ?Order $order = null;

    public string $orderNumber = '';

    public function mount(?string $order = null): void
    {
        if ($order) {
            $this->orderNumber = $order;
            $foundOrder = Order::where('order_number', $order)->first();

            // Verify ownership before exposing order details
            if ($foundOrder && $this->authorizeOrder($foundOrder)) {
                $this->order = $foundOrder;
            }
        }
    }

    /**
     * Verify the current user is authorised to view this order.
     */
    protected function authorizeOrder(Order $order): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $workspace = $user->defaultHostWorkspace();

        if (! $workspace) {
            return false;
        }

        return $order->workspace_id === $workspace->id;
    }

    public function render()
    {
        return view('commerce::web.checkout.checkout-cancel');
    }
}
