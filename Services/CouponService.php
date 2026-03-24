<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Contracts\Orderable;
use Core\Mod\Commerce\Data\CouponValidationResult;
use Core\Mod\Commerce\Models\Coupon;
use Core\Mod\Commerce\Models\CouponUsage;
use Core\Mod\Commerce\Models\Order;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

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
    public function findByCode(string $code): ?Coupon
    {
        $sanitised = $this->sanitiseCode($code);

        if ($sanitised === null) {
            return null;
        }

        return Coupon::byCode($sanitised)->first();
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
        // Trim whitespace and convert to uppercase
        $sanitised = strtoupper(trim($code));

        // Check length constraints
        $length = strlen($sanitised);
        if ($length < self::MIN_CODE_LENGTH || $length > self::MAX_CODE_LENGTH) {
            return null;
        }

        // Validate allowed characters (alphanumeric, hyphens, underscores only)
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
     * Validate a coupon for a workspace and package.
     */
    public function validate(Coupon $coupon, Workspace $workspace, ?Package $package = null): CouponValidationResult
    {
        // Check if coupon is valid (active, within dates, not maxed out)
        if (! $coupon->isValid()) {
            return CouponValidationResult::invalid('This coupon is no longer valid');
        }

        // Check workspace usage limit
        if (! $coupon->canBeUsedByWorkspace($workspace->id)) {
            return CouponValidationResult::invalid('You have already used this coupon');
        }

        // Check if coupon applies to the package
        if ($package && ! $coupon->appliesToPackage($package->id)) {
            return CouponValidationResult::invalid('This coupon does not apply to the selected plan');
        }

        return CouponValidationResult::valid($coupon);
    }

    /**
     * Validate a coupon for any Orderable entity (User or Workspace).
     *
     * Returns boolean for use in CommerceService order creation.
     */
    public function validateForOrderable(Coupon $coupon, Orderable&Model $orderable, ?Package $package = null): bool
    {
        // Check if coupon is valid (active, within dates, not maxed out)
        if (! $coupon->isValid()) {
            return false;
        }

        // Check orderable usage limit
        if (! $coupon->canBeUsedByOrderable($orderable)) {
            return false;
        }

        // Check if coupon applies to the package
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
        // Sanitise the code first - reject invalid formats early
        $sanitised = $this->sanitiseCode($code);

        if ($sanitised === null) {
            return CouponValidationResult::invalid('Invalid coupon code format');
        }

        $coupon = Coupon::byCode($sanitised)->first();

        if (! $coupon) {
            return CouponValidationResult::invalid('Invalid coupon code');
        }

        return $this->validate($coupon, $workspace, $package);
    }

    /**
     * Calculate discount for an amount.
     */
    public function calculateDiscount(Coupon $coupon, float $amount): float
    {
        return $coupon->calculateDiscount($amount);
    }

    /**
     * Record coupon usage after successful payment.
     */
    public function recordUsage(Coupon $coupon, Workspace $workspace, Order $order, float $discountAmount): CouponUsage
    {
        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'workspace_id' => $workspace->id,
            'order_id' => $order->id,
            'discount_amount' => $discountAmount,
        ]);

        // Increment global usage count
        $coupon->incrementUsage();

        return $usage;
    }

    /**
     * Record coupon usage for any Orderable entity.
     */
    public function recordUsageForOrderable(Coupon $coupon, Orderable&Model $orderable, Order $order, float $discountAmount): CouponUsage
    {
        $workspaceId = $orderable instanceof Workspace ? $orderable->id : null;

        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'workspace_id' => $workspaceId,
            'order_id' => $order->id,
            'discount_amount' => $discountAmount,
        ]);

        // Increment global usage count
        $coupon->incrementUsage();

        return $usage;
    }

    /**
     * Get usage history for a coupon.
     */
    public function getUsageHistory(Coupon $coupon, int $limit = 50): Collection
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
    public function getWorkspaceUsageCount(Coupon $coupon, Workspace $workspace): int
    {
        return $coupon->usages()
            ->where('workspace_id', $workspace->id)
            ->count();
    }

    /**
     * Get total discount amount for a coupon.
     */
    public function getTotalDiscountAmount(Coupon $coupon): float
    {
        return $coupon->usages()->sum('discount_amount');
    }

    /**
     * Create a new coupon.
     */
    public function create(array $data): Coupon
    {
        // Normalise code to uppercase
        $data['code'] = strtoupper($data['code']);

        return Coupon::create($data);
    }

    /**
     * Deactivate a coupon.
     */
    public function deactivate(Coupon $coupon): void
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

        // Ensure uniqueness
        while (Coupon::where('code', $code)->exists()) {
            $code = $this->generateCode($length);
        }

        return $code;
    }

    /**
     * Generate multiple coupons with unique codes.
     *
     * @param  int  $count  Number of coupons to generate (1-100)
     * @param  array  $baseData  Base coupon data (shared settings for all coupons)
     * @return array<Coupon> Array of created coupons
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
            $coupons[] = $this->create($data);
        }

        return $coupons;
    }
}
