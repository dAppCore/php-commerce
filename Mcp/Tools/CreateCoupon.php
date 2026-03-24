<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Mcp\Tools;

use Carbon\Carbon;
use Core\Mod\Commerce\Models\Coupon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateCoupon extends Tool
{
    protected string $description = 'Create a new discount coupon code';

    public function handle(Request $request): Response
    {
        $code = strtoupper($request->input('code'));
        $name = $request->input('name');
        $type = $request->input('type', 'percentage');
        $value = $request->input('value');
        $duration = $request->input('duration', 'once');
        $maxUses = $request->input('max_uses');
        $validUntil = $request->input('valid_until');

        // Validate code format
        if (! preg_match('/^[A-Z0-9_-]+$/', $code)) {
            return Response::text(json_encode([
                'error' => 'Invalid code format. Use only uppercase letters, numbers, hyphens, and underscores.',
            ]));
        }

        // Check for existing code
        if (Coupon::where('code', $code)->exists()) {
            return Response::text(json_encode([
                'error' => 'A coupon with this code already exists.',
            ]));
        }

        // Validate type
        if (! in_array($type, ['percentage', 'fixed_amount'])) {
            return Response::text(json_encode([
                'error' => 'Invalid type. Use percentage or fixed_amount.',
            ]));
        }

        // Validate value
        if ($type === 'percentage' && ($value < 1 || $value > 100)) {
            return Response::text(json_encode([
                'error' => 'Percentage value must be between 1 and 100.',
            ]));
        }

        try {
            $coupon = Coupon::create([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'value' => $value,
                'duration' => $duration,
                'max_uses' => $maxUses,
                'max_uses_per_workspace' => 1,
                'valid_until' => $validUntil ? Carbon::parse($validUntil) : null,
                'is_active' => true,
                'applies_to' => 'all',
            ]);

            return Response::text(json_encode([
                'success' => true,
                'coupon' => [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'name' => $coupon->name,
                    'type' => $coupon->type,
                    'value' => (float) $coupon->value,
                    'duration' => $coupon->duration,
                    'max_uses' => $coupon->max_uses,
                    'valid_until' => $coupon->valid_until?->toDateString(),
                    'is_active' => $coupon->is_active,
                ],
            ], JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            return Response::text(json_encode([
                'error' => 'Failed to create coupon: '.$e->getMessage(),
            ]));
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string('Unique coupon code (uppercase letters, numbers, hyphens, underscores)')->required(),
            'name' => $schema->string('Display name for the coupon')->required(),
            'type' => $schema->string('Discount type: percentage or fixed_amount (default: percentage)'),
            'value' => $schema->number('Discount value (percentage 1-100 or fixed amount)')->required(),
            'duration' => $schema->string('How long discount applies: once, repeating, or forever (default: once)'),
            'max_uses' => $schema->integer('Maximum total uses (null for unlimited)'),
            'valid_until' => $schema->string('Expiry date in YYYY-MM-DD format'),
        ];
    }
}
