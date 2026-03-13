# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

`lthn/php-commerce` — A Laravel package providing orders, subscriptions, invoices, and payment processing. This is a **package** (not a standalone app), tested with Orchestra Testbench.

## Commands

```bash
composer run lint          # vendor/bin/pint
composer run test          # vendor/bin/pest
vendor/bin/pint --dirty    # Format changed files only
vendor/bin/pest --filter=CheckoutFlowTest  # Run single test file
vendor/bin/pest --filter="checkout"         # Run tests matching name
```

## Architecture

### Dual Namespace System

The package has two PSR-4 roots, serving different purposes:

- `Core\Mod\Commerce\` (root `./`) — The module: models, services, events, Livewire components, routes. `Boot.php` extends `ServiceProvider` with event-driven lazy-loading via `$listens`.
- `Core\Service\Commerce\` (root `./Service/`) — The service definition layer: implements `ServiceDefinition` for the platform's service registry (admin menus, entitlements, versioning).

### Boot & Event System

`Boot.php` uses the Core Framework's event-driven lazy-loading — handlers only fire when the relevant subsystem initialises:

```php
public static array $listens = [
    AdminPanelBooting::class => 'onAdminPanel',
    ApiRoutesRegistering::class => 'onApiRoutes',
    WebRoutesRegistering::class => 'onWebRoutes',
    ConsoleBooting::class => 'onConsole',
];
```

Livewire components are registered inside `onAdminPanel()` and `onWebRoutes()`, not auto-discovered. All components use the `commerce.admin.*` or `commerce.web.*` naming prefix.

### Payment Gateways

Pluggable via `PaymentGatewayContract` in `Services/PaymentGateway/`:
- `BTCPayGateway` — Cryptocurrency (default when enabled)
- `StripeGateway` — Card payments (SaaS)

The default gateway is resolved by checking `config('commerce.gateways.btcpay.enabled')` — BTCPay takes priority when enabled.

### Multi-Entity Hierarchy (Commerce Matrix)

The system supports a three-tier entity model configured in `config.php` under `matrix` and `entities`:
- **M1 (Master Company)** — Owns the product catalogue, source of truth
- **M2 (Facade/Storefront)** — Selects from M1 catalogue, can override content
- **M3 (Dropshipper)** — Full catalogue inheritance, no management responsibility

`PermissionMatrixService` controls cross-entity access. It has a "training mode" (prompts for undefined permissions) and "strict mode" (undefined = denied).

### SKU System

SKUs encode entity lineage: `{m1_code}-{m2_code}-{master_sku}`. `SkuParserService` decodes them; `SkuBuilderService` constructs them; `SkuLineageService` tracks provenance.

### Domain Events

Events in `Events/` trigger listeners for loose coupling:
- `OrderPaid` → `CreateReferralCommission`
- `SubscriptionCreated` → `RewardAgentReferralOnSubscription`
- `SubscriptionRenewed` → `ResetUsageOnRenewal`
- `SubscriptionCancelled`, `SubscriptionUpdated`

`ProvisionSocialHostSubscription` is a subscriber (listens to multiple events).

### Livewire Components

Located in `View/Modal/` (not `Livewire/`):
- `View/Modal/Web/` — User-facing (checkout, invoices, subscription management)
- `View/Modal/Admin/` — Admin panel (managers for orders, coupons, products, etc.)

### Scheduled Commands

`Console/` has artisan commands registered in `onConsole()`: dunning processing, renewal reminders, exchange rate refresh, usage sync to Stripe, referral commission maturation, expired order cleanup, and tree planting for subscribers.

## Key Directories

```
Boot.php              # ServiceProvider, event registration, singleton bindings
config.php            # Currencies, gateways, tax, dunning, fraud, matrix settings
Service/Boot.php      # ServiceDefinition for platform registry (admin menus, entitlements)
Models/               # Eloquent models
Services/             # Business logic (singletons registered in Boot::register())
Services/PaymentGateway/  # Gateway contract + implementations
Contracts/            # Interfaces (e.g., Orderable)
Events/               # Domain events
Listeners/            # Event handlers
View/Modal/           # Livewire components (Admin/ and Web/)
Routes/               # web.php, api.php, admin.php, console.php
Migrations/           # Database schema
Console/              # Artisan commands
tests/                # Pest tests using Orchestra Testbench
```

## Conventions

- **UK English** — colour, organisation, centre, behaviour
- **PSR-12** via Laravel Pint
- **Pest** for testing (not PHPUnit syntax)
- **Strict types** — `declare(strict_types=1);` in all files
- **Final classes** by default unless inheritance is intended
- **Type hints** on all parameters and return types
- **Livewire + Flux Pro** for UI components (not vanilla Alpine)
- **Font Awesome Pro** for icons (not Heroicons)
- **Naming** — Models: singular PascalCase; Tables: plural snake_case; Livewire: `{Feature}Page`, `{Feature}Modal`

## Don't

- Don't use Heroicons (use Font Awesome Pro)
- Don't use vanilla Alpine components (use Flux Pro)
- Don't create controllers for Livewire pages
- Don't use American English spellings
