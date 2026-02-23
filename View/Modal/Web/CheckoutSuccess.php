<?php

namespace Core\Mod\Commerce\View\Modal\Web;

use Core\Mod\Commerce\Models\Order;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('shared::layouts.checkout')]
class CheckoutSuccess extends Component
{
    public ?Order $order = null;

    public string $orderNumber = '';

    public bool $isPending = false;

    public bool $needsAccount = false;

    public string $guestEmail = '';

    // Registration form fields
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(?string $order = null): void
    {
        if (! $order) {
            return;
        }

        $this->orderNumber = $order;
        $foundOrder = Order::where('order_number', $order)->first();

        if (! $foundOrder) {
            return;
        }

        // Check if this is a guest checkout that needs account creation
        if (! Auth::check()) {
            // Check if order's workspace is a temporary guest workspace
            $workspace = $foundOrder->workspace;
            if ($workspace && str_starts_with($workspace->slug, 'checkout-')) {
                $this->needsAccount = true;
                $this->guestEmail = $workspace->billing_email ?? '';
                $this->order = $foundOrder;
                $this->isPending = $this->order->isPending() || $this->order->isProcessing();

                return;
            }
        }

        // Verify ownership: user must own the workspace that placed this order
        if ($this->authorizeOrder($foundOrder)) {
            $this->order = $foundOrder;
            $this->isPending = $this->order->isPending() || $this->order->isProcessing();
        }
    }

    /**
     * Verify the current user is authorised to view this order.
     */
    protected function authorizeOrder(Order $order): bool
    {
        $user = Auth::user();

        // If not logged in, don't show order details (just generic success)
        if (! $user instanceof User) {
            return false;
        }

        // Check if order belongs to user's workspace
        $workspace = $user->defaultHostWorkspace();

        if (! $workspace) {
            return false;
        }

        return $order->workspace_id === $workspace->id;
    }

    /**
     * Create account for guest checkout user and claim their workspace.
     */
    public function createAccount(): void
    {
        $this->validate();

        // Check email isn't already taken
        if (User::where('email', $this->guestEmail)->exists()) {
            $this->addError('email', 'An account with this email already exists. Please log in instead.');

            return;
        }

        try {
            $user = DB::transaction(function () {
                // Create the user
                $user = User::create([
                    'name' => $this->name,
                    'email' => $this->guestEmail,
                    'password' => Hash::make($this->password),
                ]);

                // Update the guest workspace to be a proper workspace
                $workspace = $this->order->workspace;
                if ($workspace) {
                    $workspace->update([
                        'name' => $this->name ?: 'My Workspace',
                        'slug' => $this->generateUniqueSlug($this->name ?: $this->guestEmail),
                        'is_active' => true,
                    ]);

                    // Attach user to workspace as owner
                    $user->hostWorkspaces()->attach($workspace->id, [
                        'role' => 'owner',
                        'is_default' => true,
                    ]);
                }

                return $user;
            });

            // Fire registered event
            event(new Registered($user));

            // Log them in
            Auth::login($user);

            // Clear the needs account flag
            $this->needsAccount = false;

            // Refresh authorization
            $this->order->refresh();

        } catch (\Exception $e) {
            report($e);
            $this->addError('email', 'Something went wrong. Please try again.');
        }
    }

    /**
     * Generate a unique workspace slug.
     */
    protected function generateUniqueSlug(string $name): string
    {
        $baseSlug = \Illuminate\Support\Str::slug($name);
        if (str_contains($baseSlug, '@')) {
            $baseSlug = \Illuminate\Support\Str::slug(\Illuminate\Support\Str::before($name, '@'));
        }

        $slug = $baseSlug;
        $counter = 1;

        while (Workspace::where('slug', $slug)->where('id', '!=', $this->order->workspace_id)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    public function checkStatus(): void
    {
        if (! $this->order) {
            return;
        }

        $this->order->refresh();
        $this->isPending = $this->order->isPending() || $this->order->isProcessing();

        // If paid, we can stop polling
        if ($this->order->isPaid()) {
            $this->isPending = false;
        }
    }

    public function render()
    {
        return view('commerce::web.checkout.checkout-success');
    }
}
