<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Core\Mod\Commerce\Contracts\Orderable;
use Core\Mod\Commerce\Data\Coupon as CouponData;
use Core\Mod\Commerce\Data\CouponValidationResult;
use Core\Mod\Commerce\Data\ValidationResult;
use Core\Mod\Commerce\Models\Coupon as CouponModel;
use Core\Mod\Commerce\Models\CouponUsage;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\OrderItem;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Coupon validation and application service.
 */
class CouponService
{
    /**
     * Maximum allowed length for coupon codes.
     *
     * Prevents excessive database queries and potential abuse.
     */
    private const MAX_CODE_LENGTH = 50;

    /**
     * Minimum allowed length for coupon codes.
     *
     * Prevents single-character brute force attempts.
     */
    private const MIN_CODE_LENGTH = 3;

    /**
     * Pattern for valid coupon code characters.
     *
     * Allows alphanumeric characters, hyphens, and underscores.
     */
    private const VALID_CODE_PATTERN = '/^[A-Z0-9\-_]+$/';

    /**
     * Find a coupon by code.
     *
     * Sanitises the code before querying to prevent abuse.
     */
    public function findByCode(string $code): ?CouponModel
    {
        $sanitised = $this->sanitiseCode($code);

        if ($sanitised === null) {
            return null;
        }

        return CouponModel::byCode($sanitised)->first();
    }

    /**
     * Sanitise and validate a coupon code.
     *
     * Performs the following transformations and validations:
     * - Trims whitespace
     * - Converts to uppercase (normalisation)
     * - Enforces length limits (3-50 characters)
     * - Validates allowed characters (alphanumeric, hyphens, underscores)
     *
     * @param  string  $code  The raw coupon code input
     * @return string|null The sanitised code, or null if invalid
     */
    public function sanitiseCode(string $code): ?string
    {
        $sanitised = strtoupper(trim($code));

        $length = strlen($sanitised);
        if ($length < self::MIN_CODE_LENGTH || $length > self::MAX_CODE_LENGTH) {
            return null;
        }

        if (! preg_match(self::VALID_CODE_PATTERN, $sanitised)) {
            return null;
        }

        return $sanitised;
    }

    /**
     * Check if a coupon code format is valid without looking it up.
     *
     * Useful for early validation before database queries.
     */
    public function isValidCodeFormat(string $code): bool
    {
        return $this->sanitiseCode($code) !== null;
    }

    /**
     * Create a persisted coupon.
     *
     * The scalar signature is the RFC API and returns a DTO. The array form is
     * retained for older module code that passes Eloquent attributes directly.
     *
     * @param  string|array<string, mixed>  $code
     */
    public function create(
        string|array $code,
        ?string $type = null,
        float|int|null $value = null,
        ?int $maxUses = null,
        CarbonInterface|string|null $expiresAt = null,
    ): CouponData|CouponModel {
        if (is_array($code)) {
            return $this->createModel($code);
        }

        if ($type === null || $value === null) {
            throw new InvalidArgumentException('Coupon type and value are required.');
        }

        $coupon = $this->createModel([
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'value' => $value,
            'max_uses' => $maxUses,
            'max_uses_per_workspace' => 1,
            'duration' => 'once',
            'valid_until' => $this->parseExpiresAt($expiresAt),
            'is_active' => true,
            'applies_to' => 'all',
            'used_count' => 0,
        ]);

        return CouponData::fromModel($coupon);
    }

    /**
     * Validate a coupon by code for an order, or use the legacy model/workspace flow.
     */
    public function validate(
        string|CouponModel $code,
        Order|Workspace $order,
        ?Package $package = null,
    ): ValidationResult|CouponValidationResult {
        if ($code instanceof CouponModel) {
            if (! $order instanceof Workspace) {
                throw new InvalidArgumentException('Legacy coupon validation requires a workspace.');
            }

            return $this->validateLegacy($code, $order, $package);
        }

        if (! $order instanceof Order) {
            throw new InvalidArgumentException('Coupon code validation requires an order.');
        }

        $sanitised = $this->sanitiseCode($code);

        if ($sanitised === null) {
            return ValidationResult::invalid('Invalid coupon code format');
        }

        $coupon = CouponModel::byCode($sanitised)->first();

        if (! $coupon) {
            return ValidationResult::invalid('Coupon not found');
        }

        return $this->validateCouponForOrder($coupon, $order);
    }

    /**
     * Apply a coupon to an order by mutating eligible line-item totals.
     */
    public function apply(CouponData|CouponModel $coupon, Order $order): Order
    {
        $couponModel = $this->resolveCouponModel($coupon);

        if (! $order->exists) {
            throw new InvalidArgumentException('Coupon application requires a persisted order.');
        }

        return DB::transaction(function () use ($couponModel, $order): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($this->hasAppliedCoupon($couponModel, $lockedOrder)) {
                return $lockedOrder->load('items', 'coupon');
            }

            if ($lockedOrder->coupon_id && (int) $lockedOrder->coupon_id !== (int) $couponModel->id) {
                throw new RuntimeException('Order already has a different coupon applied.');
            }

            $result = $this->validateCouponForOrder($couponModel, $lockedOrder);

            if (! $result->valid) {
                throw new RuntimeException($result->reason ?? 'Coupon is not valid for this order.');
            }

            $eligibleItems = $this->eligibleItems($couponModel, $lockedOrder);
            $discounts = $this->allocateDiscount($couponModel, $eligibleItems, $result->discountAmount);

            foreach ($eligibleItems as $item) {
                $baseLineTotal = $this->lineBaseTotal($item);
                $lineDiscount = $discounts[(int) $item->id] ?? 0.0;
                $metadata = $item->metadata ?? [];

                $item->forceFill([
                    'line_total' => round(max(0.0, $baseLineTotal - $lineDiscount), 2),
                    'metadata' => array_merge($metadata, [
                        'original_line_total' => $baseLineTotal,
                        'coupon_id' => $couponModel->id,
                        'coupon_code' => $couponModel->code,
                        'coupon_discount_amount' => round($lineDiscount, 2),
                    ]),
                ])->save();
            }

            $lockedOrder->load('items');

            $subtotal = round((float) $lockedOrder->items->sum(
                fn (OrderItem $item): float => $this->lineBaseTotal($item)
            ), 2);
            $lineTotal = round((float) $lockedOrder->items->sum(
                fn (OrderItem $item): float => (float) $item->line_total
            ), 2);
            $discountAmount = round(max(0.0, $subtotal - $lineTotal), 2);
            $taxAmount = (float) ($lockedOrder->tax_amount ?? 0);

            $lockedOrder->forceFill([
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'total' => round($lineTotal + $taxAmount, 2),
                'coupon_id' => $couponModel->id,
            ])->save();

            $this->recordOrderUsage($couponModel, $lockedOrder, $discountAmount);

            return $lockedOrder->load('items', 'coupon');
        });
    }

    /**
     * Expire a coupon immediately.
     */
    public function expire(CouponData|CouponModel $coupon): void
    {
        $couponModel = $this->resolveCouponModel($coupon);

        $couponModel->forceFill([
            'is_active' => false,
            'valid_until' => Carbon::now(),
        ])->save();
    }

    /**
     * Return redemption statistics for all coupons.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $now = Carbon::now();
        $couponRows = CouponModel::query()
            ->withCount('usages')
            ->withSum('usages as discount_total', 'discount_amount')
            ->orderByDesc('usages_count')
            ->orderBy('code')
            ->get();

        return [
            'total_coupons' => CouponModel::query()->count(),
            'active_coupons' => CouponModel::query()->where('is_active', true)->count(),
            'expired_coupons' => CouponModel::query()
                ->whereNotNull('valid_until')
                ->where('valid_until', '<', $now)
                ->count(),
            'total_redemptions' => CouponUsage::query()->count(),
            'total_discount_amount' => round((float) CouponUsage::query()->sum('discount_amount'), 2),
            'by_coupon' => $couponRows->map(function (CouponModel $coupon): array {
                $redemptions = (int) ($coupon->getAttribute('usages_count') ?? 0);

                return [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'type' => $this->discountType($coupon),
                    'value' => (float) $coupon->value,
                    'active' => (bool) $coupon->is_active,
                    'max_uses' => $coupon->max_uses,
                    'used_count' => max((int) $coupon->used_count, $redemptions),
                    'redemptions' => $redemptions,
                    'discount_total' => round((float) ($coupon->getAttribute('discount_total') ?? 0), 2),
                    'expires_at' => $coupon->valid_until?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Validate a coupon for any Orderable entity (User or Workspace).
     *
     * Returns boolean for use in CommerceService order creation.
     */
    public function validateForOrderable(CouponModel $coupon, Orderable&Model $orderable, ?Package $package = null): bool
    {
        if (! $coupon->isValid()) {
            return false;
        }

        if (! $coupon->canBeUsedByOrderable($orderable)) {
            return false;
        }

        if ($package && ! $coupon->appliesToPackage($package->id)) {
            return false;
        }

        return true;
    }

    /**
     * Validate a coupon by code.
     *
     * Sanitises the code before validation. Returns an invalid result
     * if the code format is invalid or the coupon doesn't exist.
     */
    public function validateByCode(string $code, Workspace $workspace, ?Package $package = null): CouponValidationResult
    {
        $sanitised = $this->sanitiseCode($code);

        if ($sanitised === null) {
            return CouponValidationResult::invalid('Invalid coupon code format');
        }

        $coupon = CouponModel::byCode($sanitised)->first();

        if (! $coupon) {
            return CouponValidationResult::invalid('Invalid coupon code');
        }

        return $this->validateLegacy($coupon, $workspace, $package);
    }

    /**
     * Calculate discount for an amount.
     */
    public function calculateDiscount(CouponModel $coupon, float $amount): float
    {
        return $coupon->calculateDiscount($amount);
    }

    /**
     * Record coupon usage after successful payment.
     */
    public function recordUsage(CouponModel $coupon, Workspace $workspace, Order $order, float $discountAmount): CouponUsage
    {
        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'discount_amount' => $discountAmount,
        ]);

        $coupon->incrementUsage();

        return $usage;
    }

    /**
     * Record coupon usage for any Orderable entity.
     */
    public function recordUsageForOrderable(
        CouponModel $coupon,
        Orderable&Model $orderable,
        Order $order,
        float $discountAmount,
    ): CouponUsage {
        $workspaceId = $orderable instanceof Workspace ? $orderable->id : null;

        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'workspace_id' => $workspaceId,
            'order_id' => $order->id,
            'discount_amount' => $discountAmount,
        ]);

        $coupon->incrementUsage();

        return $usage;
    }

    /**
     * Get usage history for a coupon.
     */
    public function getUsageHistory(CouponModel $coupon, int $limit = 50): Collection
    {
        return $coupon->usages()
            ->with(['workspace', 'order'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Get usage count for a workspace.
     */
    public function getWorkspaceUsageCount(CouponModel $coupon, Workspace $workspace): int
    {
        return $coupon->usages()
            ->where('workspace_id', $workspace->id)
            ->count();
    }

    /**
     * Get total discount amount for a coupon.
     */
    public function getTotalDiscountAmount(CouponModel $coupon): float
    {
        return (float) $coupon->usages()->sum('discount_amount');
    }

    /**
     * Deactivate a coupon.
     */
    public function deactivate(CouponModel $coupon): void
    {
        $coupon->update(['is_active' => false]);
    }

    /**
     * Generate a random coupon code.
     */
    public function generateCode(int $length = 8): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        while (CouponModel::where('code', $code)->exists()) {
            $code = $this->generateCode($length);
        }

        return $code;
    }

    /**
     * Generate multiple coupons with unique codes.
     *
     * @param  int  $count  Number of coupons to generate (1-100)
     * @param  array<string, mixed>  $baseData  Base coupon data (shared settings for all coupons)
     * @return array<CouponModel> Array of created coupons
     */
    public function generateBulk(int $count, array $baseData): array
    {
        $count = min(max($count, 1), 100);
        $coupons = [];
        $prefix = $baseData['code_prefix'] ?? '';
        unset($baseData['code_prefix']);

        for ($i = 0; $i < $count; $i++) {
            $code = $prefix ? $prefix.'-'.$this->generateCode(6) : $this->generateCode(8);
            $data = array_merge($baseData, ['code' => $code]);
            $coupons[] = $this->createModel($data);
        }

        return $coupons;
    }

    private function validateLegacy(CouponModel $coupon, Workspace $workspace, ?Package $package = null): CouponValidationResult
    {
        if (! $coupon->isValid()) {
            return CouponValidationResult::invalid('This coupon is no longer valid');
        }

        if (! $coupon->canBeUsedByWorkspace($workspace->id)) {
            return CouponValidationResult::invalid('You have already used this coupon');
        }

        if ($package && ! $coupon->appliesToPackage($package->id)) {
            return CouponValidationResult::invalid('This coupon does not apply to the selected plan');
        }

        return CouponValidationResult::valid($coupon);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createModel(array $data): CouponModel
    {
        if (! isset($data['code'])) {
            throw new InvalidArgumentException('Coupon code is required.');
        }

        $sanitised = $this->sanitiseCode((string) $data['code']);

        if ($sanitised === null) {
            throw new InvalidArgumentException('Invalid coupon code format.');
        }

        if (CouponModel::byCode($sanitised)->exists()) {
            throw new InvalidArgumentException('A coupon with this code already exists.');
        }

        if (! isset($data['type'])) {
            throw new InvalidArgumentException('Coupon type is required.');
        }

        if (! array_key_exists('value', $data)) {
            throw new InvalidArgumentException('Coupon value is required.');
        }

        $modelType = $this->normaliseModelType((string) $data['type']);
        $value = round((float) $data['value'], 2);

        if ($modelType === 'percentage' && ($value <= 0 || $value > 100)) {
            throw new InvalidArgumentException('Percentage coupon value must be between 0 and 100.');
        }

        if ($modelType === 'fixed_amount' && $value <= 0) {
            throw new InvalidArgumentException('Fixed coupon value must be greater than zero.');
        }

        $maxUses = $data['max_uses'] ?? null;
        if ($maxUses !== null && (int) $maxUses < 1) {
            throw new InvalidArgumentException('Coupon max uses must be at least one.');
        }

        $data['code'] = $sanitised;
        $data['name'] = $data['name'] ?? $sanitised;
        $data['type'] = $modelType;
        $data['value'] = $value;
        $data['max_uses'] = $maxUses === null ? null : (int) $maxUses;
        $data['max_uses_per_workspace'] = (int) ($data['max_uses_per_workspace'] ?? 1);
        $data['used_count'] = (int) ($data['used_count'] ?? 0);
        $data['duration'] = $data['duration'] ?? 'once';
        $data['applies_to'] = $data['applies_to'] ?? 'all';
        $data['valid_until'] = $this->parseExpiresAt($data['valid_until'] ?? null);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return CouponModel::create($data);
    }

    private function validateCouponForOrder(CouponModel $coupon, Order $order): ValidationResult
    {
        $discountType = $this->discountType($coupon);
        $couponData = CouponData::fromModel($coupon);

        if (! $coupon->is_active) {
            return ValidationResult::invalid('Coupon is inactive', $discountType, $couponData);
        }

        if ($coupon->valid_from && $coupon->valid_from->isFuture()) {
            return ValidationResult::invalid('Coupon is not active yet', $discountType, $couponData);
        }

        if ($coupon->valid_until && $coupon->valid_until->isPast()) {
            return ValidationResult::invalid('Coupon has expired', $discountType, $couponData);
        }

        if ($this->usageCount($coupon) >= $this->usageLimit($coupon)) {
            return ValidationResult::invalid('Coupon usage limit reached', $discountType, $couponData);
        }

        $workspaceId = $this->resolveWorkspaceId($order);
        if ($workspaceId !== null && $this->workspaceUsageLimitReached($coupon, $workspaceId)) {
            return ValidationResult::invalid('Coupon already used by this workspace', $discountType, $couponData);
        }

        $eligibleItems = $this->eligibleItems($coupon, $order);

        if ($eligibleItems->isEmpty()) {
            return ValidationResult::invalid('Coupon is not applicable to this order', $discountType, $couponData);
        }

        $discountAmount = $this->calculateOrderDiscount($coupon, $eligibleItems);

        if ($discountAmount <= 0) {
            return ValidationResult::invalid('Order has no discountable amount', $discountType, $couponData);
        }

        return ValidationResult::valid($couponData, $discountAmount, $discountType);
    }

    private function resolveCouponModel(CouponData|CouponModel $coupon): CouponModel
    {
        if ($coupon instanceof CouponModel) {
            return $coupon;
        }

        return CouponModel::query()->findOrFail($coupon->id);
    }

    private function parseExpiresAt(CarbonInterface|string|null $expiresAt): ?Carbon
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }

        if ($expiresAt instanceof CarbonInterface) {
            return Carbon::instance($expiresAt->toDateTime());
        }

        return Carbon::parse($expiresAt);
    }

    private function normaliseModelType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'percent', 'percentage' => 'percentage',
            'fixed', 'fixed_amount' => 'fixed_amount',
            default => throw new InvalidArgumentException('Coupon type must be percent or fixed.'),
        };
    }

    private function discountType(CouponModel $coupon): string
    {
        return $this->isPercentCoupon($coupon) ? 'percent' : 'fixed';
    }

    private function isPercentCoupon(CouponModel $coupon): bool
    {
        return in_array((string) $coupon->type, ['percent', 'percentage'], true);
    }

    private function usageLimit(CouponModel $coupon): int
    {
        return $coupon->max_uses === null ? PHP_INT_MAX : (int) $coupon->max_uses;
    }

    private function usageCount(CouponModel $coupon): int
    {
        return max((int) $coupon->used_count, $coupon->usages()->count());
    }

    private function workspaceUsageLimitReached(CouponModel $coupon, int $workspaceId): bool
    {
        $limit = (int) ($coupon->max_uses_per_workspace ?? 0);

        if ($limit <= 0) {
            return false;
        }

        return $coupon->usages()
            ->where('workspace_id', $workspaceId)
            ->count() >= $limit;
    }

    private function resolveWorkspaceId(Order $order): ?int
    {
        $rawWorkspaceId = $order->getAttributes()['workspace_id'] ?? null;

        if ($rawWorkspaceId !== null) {
            return (int) $rawWorkspaceId;
        }

        return $order->workspace_id;
    }

    private function eligibleItems(CouponModel $coupon, Order $order): Collection
    {
        $order->loadMissing('items');

        return $order->items
            ->filter(fn (OrderItem $item): bool => $this->lineBaseTotal($item) > 0
                && $this->couponAppliesToItem($coupon, $item))
            ->values();
    }

    private function couponAppliesToItem(CouponModel $coupon, OrderItem $item): bool
    {
        if ($coupon->applies_to === 'all' || $coupon->applies_to === null) {
            return true;
        }

        $allowedIds = array_map('intval', $coupon->package_ids ?? []);

        if ($allowedIds === []) {
            return false;
        }

        if (in_array($coupon->applies_to, ['package', 'packages', 'product', 'products'], true)) {
            return $item->item_id !== null && in_array((int) $item->item_id, $allowedIds, true);
        }

        return false;
    }

    private function lineBaseTotal(OrderItem $item): float
    {
        $metadata = $item->metadata ?? [];

        if (isset($metadata['original_line_total'])) {
            return round((float) $metadata['original_line_total'], 2);
        }

        return round((float) $item->line_total, 2);
    }

    private function calculateOrderDiscount(CouponModel $coupon, Collection $eligibleItems): float
    {
        $subtotal = round((float) $eligibleItems->sum(
            fn (OrderItem $item): float => $this->lineBaseTotal($item)
        ), 2);

        if ($subtotal <= 0) {
            return 0.0;
        }

        if ($this->isPercentCoupon($coupon)) {
            return round(min($subtotal, $subtotal * ((float) $coupon->value / 100)), 2);
        }

        return round(min($subtotal, (float) $coupon->value), 2);
    }

    /**
     * @return array<int, float>
     */
    private function allocateDiscount(CouponModel $coupon, Collection $eligibleItems, float $discountAmount): array
    {
        $discountAmount = round($discountAmount, 2);
        $allocated = [];
        $allocatedTotal = 0.0;
        $items = $eligibleItems->values();
        $lastIndex = $items->count() - 1;
        $eligibleSubtotal = round((float) $items->sum(
            fn (OrderItem $item): float => $this->lineBaseTotal($item)
        ), 2);

        foreach ($items as $index => $item) {
            $baseLineTotal = $this->lineBaseTotal($item);

            if ($index === $lastIndex) {
                $lineDiscount = round($discountAmount - $allocatedTotal, 2);
            } elseif ($this->isPercentCoupon($coupon)) {
                $lineDiscount = round($baseLineTotal * ((float) $coupon->value / 100), 2);
            } else {
                $lineDiscount = round($discountAmount * ($baseLineTotal / $eligibleSubtotal), 2);
            }

            $lineDiscount = round(min($baseLineTotal, max(0.0, $lineDiscount)), 2);
            $allocated[(int) $item->id] = $lineDiscount;
            $allocatedTotal = round($allocatedTotal + $lineDiscount, 2);
        }

        return $allocated;
    }

    private function hasAppliedCoupon(CouponModel $coupon, Order $order): bool
    {
        if ((int) ($order->coupon_id ?? 0) !== (int) $coupon->id) {
            return false;
        }

        return CouponUsage::query()
            ->where('coupon_id', $coupon->id)
            ->where('order_id', $order->id)
            ->exists();
    }

    private function recordOrderUsage(CouponModel $coupon, Order $order, float $discountAmount): void
    {
        if (CouponUsage::query()
            ->where('coupon_id', $coupon->id)
            ->where('order_id', $order->id)
            ->exists()) {
            return;
        }

        $workspaceId = $this->resolveWorkspaceId($order);

        if ($workspaceId !== null) {
            CouponUsage::create([
                'coupon_id' => $coupon->id,
                'workspace_id' => $workspaceId,
                'order_id' => $order->id,
                'discount_amount' => $discountAmount,
            ]);
        }

        $coupon->incrementUsage();
    }
}
