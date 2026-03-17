<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Currency Settings
    |--------------------------------------------------------------------------
    |
    | Multi-currency configuration for commerce transactions.
    |
    */

    'currency' => env('COMMERCE_CURRENCY', 'GBP'),

    'currencies' => [
        // Base currency for reporting and internal calculations
        'base' => env('COMMERCE_BASE_CURRENCY', 'GBP'),

        // Supported currencies with display properties
        'supported' => [
            'GBP' => [
                'name' => 'British Pound',
                'symbol' => '£',
                'symbol_position' => 'before', // 'before' or 'after'
                'decimal_places' => 2,
                'thousands_separator' => ',',
                'decimal_separator' => '.',
                'flag' => 'gb',
            ],
            'USD' => [
                'name' => 'US Dollar',
                'symbol' => '$',
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'thousands_separator' => ',',
                'decimal_separator' => '.',
                'flag' => 'us',
            ],
            'EUR' => [
                'name' => 'Euro',
                'symbol' => '€',
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'thousands_separator' => ' ',
                'decimal_separator' => ',',
                'flag' => 'eu',
            ],
            'AUD' => [
                'name' => 'Australian Dollar',
                'symbol' => 'A$',
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'thousands_separator' => ',',
                'decimal_separator' => '.',
                'flag' => 'au',
            ],
            'CAD' => [
                'name' => 'Canadian Dollar',
                'symbol' => 'C$',
                'symbol_position' => 'before',
                'decimal_places' => 2,
                'thousands_separator' => ',',
                'decimal_separator' => '.',
                'flag' => 'ca',
            ],
        ],

        // Exchange rate provider settings
        'exchange_rates' => [
            // Provider: 'stripe', 'ecb', 'openexchangerates', 'fixed'
            'provider' => env('COMMERCE_EXCHANGE_RATE_PROVIDER', 'ecb'),

            // API key for providers that require it (e.g., openexchangerates)
            'api_key' => env('COMMERCE_EXCHANGE_RATE_API_KEY'),

            // Cache duration in minutes (default: 60 minutes)
            'cache_ttl' => env('COMMERCE_EXCHANGE_RATE_CACHE_TTL', 60),

            // Update frequency in minutes for scheduled updates
            'update_frequency' => env('COMMERCE_EXCHANGE_RATE_UPDATE_FREQUENCY', 60),

            // Fixed rates (used when provider is 'fixed' or as fallback)
            'fixed' => [
                'GBP_USD' => 1.27,
                'GBP_EUR' => 1.17,
                'GBP_AUD' => 1.93,
                'GBP_CAD' => 1.72,
            ],
        ],

        // Auto-convert prices when no explicit currency price exists
        'auto_convert' => env('COMMERCE_AUTO_CONVERT_PRICES', true),

        // Default currency detection order: 'geolocation', 'browser', 'default'
        'detection_order' => ['geolocation', 'browser', 'default'],

        // Map countries to preferred currencies
        'country_currencies' => [
            'GB' => 'GBP',
            'US' => 'USD',
            'AU' => 'AUD',
            'CA' => 'CAD',
            // EU countries default to EUR
            'AT' => 'EUR', 'BE' => 'EUR', 'BG' => 'EUR', 'HR' => 'EUR',
            'CY' => 'EUR', 'CZ' => 'EUR', 'DK' => 'EUR', 'EE' => 'EUR',
            'FI' => 'EUR', 'FR' => 'EUR', 'DE' => 'EUR', 'GR' => 'EUR',
            'HU' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR', 'LV' => 'EUR',
            'LT' => 'EUR', 'LU' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR',
            'PL' => 'EUR', 'PT' => 'EUR', 'RO' => 'EUR', 'SK' => 'EUR',
            'SI' => 'EUR', 'ES' => 'EUR', 'SE' => 'EUR',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Settings
    |--------------------------------------------------------------------------
    |
    | Rate limiting and security for webhook endpoints.
    |
    */

    'webhooks' => [
        // IP-based rate limiting for webhook endpoints
        'rate_limits' => [
            // Default requests per minute for unknown IPs
            'default' => env('COMMERCE_WEBHOOK_RATE_LIMIT', 60),

            // Requests per minute for trusted gateway IPs
            'trusted' => env('COMMERCE_WEBHOOK_RATE_LIMIT_TRUSTED', 300),

            // Gateway-specific limits (optional, overrides above)
            'stripe' => [
                'default' => env('COMMERCE_WEBHOOK_RATE_LIMIT_STRIPE', 60),
                'trusted' => env('COMMERCE_WEBHOOK_RATE_LIMIT_STRIPE_TRUSTED', 300),
            ],
            'btcpay' => [
                'default' => env('COMMERCE_WEBHOOK_RATE_LIMIT_BTCPAY', 60),
                'trusted' => env('COMMERCE_WEBHOOK_RATE_LIMIT_BTCPAY_TRUSTED', 300),
            ],
        ],

        // Trusted IP addresses/CIDR ranges for payment gateways
        // These IPs get higher rate limits
        'trusted_ips' => [
            // Global trusted IPs (applies to all gateways)
            'global' => [],

            // Stripe webhook IPs (from https://docs.stripe.com/ips)
            // Note: Stripe recommends signature verification over IP allowlisting
            // These are provided as an additional layer of defence
            'stripe' => [
                // Stripe webhook source IPs (as of 2024)
                '3.18.12.63',
                '3.130.192.231',
                '13.235.14.237',
                '13.235.122.149',
                '18.211.135.69',
                '35.154.171.200',
                '52.15.183.38',
                '54.88.130.119',
                '54.88.130.237',
                '54.187.174.169',
                '54.187.205.235',
                '54.187.216.72',
            ],

            // BTCPay Server IPs (configure based on your BTCPay instance)
            // If self-hosted, add your server's IP here
            'btcpay' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | Configuration for payment gateways. BTCPay is the primary gateway,
    | with Stripe available but hidden from the UI initially.
    |
    */

    'gateways' => [
        'btcpay' => [
            'enabled' => env('BTCPAY_ENABLED', true),
            'url' => env('BTCPAY_URL', 'https://pay.host.uk.com'),
            'store_id' => env('BTCPAY_STORE_ID'),
            'api_key' => env('BTCPAY_API_KEY'),
            'webhook_secret' => env('BTCPAY_WEBHOOK_SECRET'),
            'default_payment_methods' => ['BTC', 'LTC', 'XMR'],
        ],

        'stripe' => [
            'enabled' => env('STRIPE_ENABLED', false), // Hidden initially
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing Settings
    |--------------------------------------------------------------------------
    |
    | General billing configuration.
    |
    */

    'billing' => [
        'invoice_prefix' => env('COMMERCE_INVOICE_PREFIX', 'INV-'),
        'order_prefix' => env('COMMERCE_ORDER_PREFIX', 'ORD-'),
        'default_tax_rate' => 20, // UK VAT
        'invoice_due_days' => 14,
        'auto_charge' => true,
        'send_invoice_emails' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dunning Settings
    |--------------------------------------------------------------------------
    |
    | Failed payment recovery configuration.
    |
    */

    'dunning' => [
        'enabled' => true,

        // Exponential backoff: days after initial failure to schedule each retry
        // [1, 3, 7] = retry at day 1, day 3, day 7 (total ~11 days of retries)
        'retry_days' => [1, 3, 7],

        // Days after subscription paused to suspend workspace entitlements
        // Paused = billing stopped but workspace accessible
        // Suspended = workspace features restricted
        'suspend_after_days' => 14,

        // Days after subscription paused to cancel entirely
        // After cancellation, workspace may be downgraded to free tier
        'cancel_after_days' => 30,

        // Grace period before first retry (hours)
        // Gives customer time to fix payment method before automated retries
        'initial_grace_hours' => 24,

        // Send email notifications at each dunning stage
        'send_notifications' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Settings
    |--------------------------------------------------------------------------
    |
    | Tax calculation configuration. Supports UK VAT, EU OSS, US state taxes,
    | and Australian GST.
    |
    */

    'tax' => [
        'enabled' => true,
        'validate_tax_ids' => true,
        'validate_tax_ids_api' => env('COMMERCE_VALIDATE_TAX_IDS_API', true), // Call HMRC/VIES APIs
        'digital_services' => true, // All our products are digital

        // Business details for invoices
        'business' => [
            'name' => env('COMMERCE_BUSINESS_NAME', 'Host UK Ltd'),
            'address_line1' => env('COMMERCE_BUSINESS_ADDRESS_1', ''),
            'address_line2' => env('COMMERCE_BUSINESS_ADDRESS_2', ''),
            'city' => env('COMMERCE_BUSINESS_CITY', 'London'),
            'postcode' => env('COMMERCE_BUSINESS_POSTCODE', ''),
            'country' => env('COMMERCE_BUSINESS_COUNTRY', 'United Kingdom'),
            'vat_number' => env('COMMERCE_VAT_NUMBER'),
            'company_number' => env('COMMERCE_COMPANY_NUMBER'),
            'email' => env('COMMERCE_BUSINESS_EMAIL', 'support@host.uk.com'),
        ],

        // UK VAT
        'uk' => [
            'rate' => 20,
            'reverse_charge_b2b' => true,
        ],

        // EU One-Stop Shop
        'eu_oss' => [
            'enabled' => true,
            'registered_country' => 'GB', // We're registered in UK
        ],

        // US State Taxes
        'us' => [
            'enabled' => true,
            'nexus_states' => ['CA', 'NY', 'TX', 'FL', 'WA'], // States where we have nexus
        ],

        // Australian GST
        'australia' => [
            'enabled' => true,
            'rate' => 10,
            'threshold' => 75000, // AUD threshold
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for recurring billing.
    |
    */

    'subscriptions' => [
        'allow_proration' => true,
        'proration_behaviour' => 'create_prorations', // or 'none'
        'cancel_at_period_end' => true, // Grace period instead of immediate cancellation
        'allow_pause' => true,
        'max_pause_cycles' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the checkout process.
    |
    */

    'checkout' => [
        'require_billing_address' => true,
        'require_tax_id_for_b2b' => false,
        'allowed_countries' => null, // null = all countries
        'blocked_countries' => [], // Countries we don't serve
        'session_ttl' => 30, // Minutes before checkout session expires
    ],

    /*
    |--------------------------------------------------------------------------
    | Fraud Detection Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for fraud detection and prevention.
    | Uses Stripe Radar for Stripe payments. BTCPay payments rely on
    | blockchain confirmations for security.
    |
    */

    'fraud' => [
        // Enable fraud detection
        'enabled' => env('COMMERCE_FRAUD_DETECTION', true),

        // Stripe Radar integration (requires Stripe Radar subscription)
        'stripe_radar' => [
            'enabled' => env('COMMERCE_STRIPE_RADAR', true),

            // Block payments with risk level equal or above this threshold
            // Options: 'highest', 'elevated', 'normal' (block highest only, elevated+, or all flagged)
            'block_threshold' => env('COMMERCE_STRIPE_RADAR_BLOCK_THRESHOLD', 'highest'),

            // Review payments at this risk level (manual review required)
            'review_threshold' => env('COMMERCE_STRIPE_RADAR_REVIEW_THRESHOLD', 'elevated'),

            // Store fraud scores on orders for analysis
            'store_scores' => true,
        ],

        // Velocity checks (rate limiting beyond checkout rate limiter)
        'velocity' => [
            'enabled' => env('COMMERCE_FRAUD_VELOCITY', true),

            // Maximum orders per IP per hour
            'max_orders_per_ip_hourly' => 5,

            // Maximum orders per email per day
            'max_orders_per_email_daily' => 10,

            // Maximum failed payments per workspace per hour
            'max_failed_payments_hourly' => 3,
        ],

        // Geo-anomaly detection
        'geo' => [
            'enabled' => env('COMMERCE_FRAUD_GEO', true),

            // Flag if billing country differs from IP country
            'flag_country_mismatch' => true,

            // High-risk countries (require manual review)
            'high_risk_countries' => [],
        ],

        // Actions on fraud detection
        'actions' => [
            // Log all fraud signals
            'log' => true,

            // Send notification to admin on high-risk orders
            'notify_admin' => true,

            // Automatically block orders above threshold
            'auto_block' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invoice PDF Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for invoice PDF generation.
    |
    */

    'pdf' => [
        'driver' => 'dompdf', // dompdf or snappy
        'paper' => 'a4',
        'orientation' => 'portrait',
        'font' => 'sans-serif',
        'storage_disk' => 'local',
        'storage_path' => 'invoices',
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    |
    | Email notifications for commerce events.
    |
    */

    'notifications' => [
        'order_confirmation' => true,
        'invoice_generated' => true,
        'payment_received' => true,
        'payment_failed' => true,
        'subscription_created' => true,
        'subscription_cancelled' => true,
        'subscription_renewed' => true,
        'refund_processed' => true,
        'upcoming_renewal' => true,
        'upcoming_renewal_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Toggle commerce features on/off.
    |
    */

    'features' => [
        'coupons' => true,
        'refunds' => true,
        'trials' => true,
        'setup_fees' => true,
        'usage_billing' => env('COMMERCE_USAGE_BILLING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage-Based Billing
    |--------------------------------------------------------------------------
    |
    | Configuration for metered/usage-based billing.
    |
    */

    'usage_billing' => [
        // Whether to sync usage to Stripe automatically
        'sync_to_stripe' => env('COMMERCE_USAGE_SYNC_STRIPE', true),

        // Sync frequency in minutes (for scheduled jobs)
        'sync_interval' => env('COMMERCE_USAGE_SYNC_INTERVAL', 60),

        // Whether to aggregate events in real-time or batch
        'realtime_aggregation' => true,

        // Maximum events to process per batch
        'batch_size' => 1000,

        // Retention period for usage events (days)
        'event_retention_days' => 90,

        // Default aggregation type for new meters
        'default_aggregation' => 'sum', // sum, max, last_value

        // Notifications
        'notifications' => [
            // Alert when usage reaches percentage of included quota
            'usage_threshold_alerts' => [50, 75, 90, 100],
            // Send weekly usage summary
            'weekly_summary' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Commerce Matrix (Multi-Entity Hierarchy)
    |--------------------------------------------------------------------------
    |
    | Configuration for the permission matrix system.
    |
    | Entity types:
    | - M1: Master Company (source of truth, owns product catalog)
    | - M2: Facades/Storefronts (select from M1, can override content)
    | - M3: Dropshippers (full inheritance, no management responsibility)
    |
    */

    'matrix' => [
        // Training mode - undefined permissions prompt for approval
        'training_mode' => env('COMMERCE_MATRIX_TRAINING', false),

        // Production mode - undefined = denied
        'strict_mode' => env('COMMERCE_MATRIX_STRICT', true),

        // Log all permission checks (for audit)
        'log_all_checks' => env('COMMERCE_MATRIX_LOG_ALL', false),

        // Log denied requests
        'log_denials' => true,

        // Default action when permission undefined (only if strict=false)
        'default_allow' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Types
    |--------------------------------------------------------------------------
    |
    | Configuration for commerce entity types.
    |
    */

    'entities' => [
        'types' => [
            'm1' => [
                'name' => 'Master Company',
                'can_have_children' => true,
                'child_types' => ['m2', 'm3'],
            ],
            'm2' => [
                'name' => 'Facade/Storefront',
                'can_have_children' => true,
                'child_types' => ['m3'],
            ],
            'm3' => [
                'name' => 'Dropshipper',
                'can_have_children' => true,  // Can have own M2s
                'child_types' => ['m2'],
                'inherits_catalog' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SKU Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for SKU lineage tracking.
    |
    */

    'sku' => [
        // SKU format: {m1_code}-{m2_code}-{master_sku}
        'separator' => '-',
        'include_m1' => true,
        'include_m2' => true,
    ],

];
