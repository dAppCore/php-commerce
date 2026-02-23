<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Tests\Feature;

use Core\Mod\Commerce\Models\ContentOverride;
use Core\Mod\Commerce\Models\Entity;
use Core\Mod\Commerce\Models\Product;
use Core\Mod\Commerce\Services\ContentOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentOverrideServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ContentOverrideService $service;

    protected Entity $m1;

    protected Entity $m2;

    protected Entity $m3;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ContentOverrideService::class);

        // Create entity hierarchy: M1 -> M2 -> M3
        $this->m1 = Entity::createMaster('ACME', 'Acme Corporation');
        $this->m2 = $this->m1->createFacade('SHOP', 'Acme Shop');
        $this->m3 = $this->m2->createDropshipper('DROP', 'Dropship Partner');

        // Create a product owned by M1
        $this->product = Product::create([
            'sku' => 'TEST-001',
            'owner_entity_id' => $this->m1->id,
            'name' => 'Original Product Name',
            'description' => 'Original description from M1.',
            'short_description' => 'Original short desc.',
            'price' => 1999,
            'currency' => 'GBP',
            'type' => Product::TYPE_SIMPLE,
        ]);
    }

    public function test_get_returns_original_when_no_override(): void
    {
        $name = $this->service->get($this->m2, $this->product, 'name');

        $this->assertEquals('Original Product Name', $name);
    }

    public function test_set_creates_override(): void
    {
        $this->service->set($this->m2, $this->product, 'name', 'Shop Custom Name');

        $this->assertDatabaseHas('commerce_content_overrides', [
            'entity_id' => $this->m2->id,
            'overrideable_type' => $this->product->getMorphClass(),
            'overrideable_id' => $this->product->id,
            'field' => 'name',
            'value' => 'Shop Custom Name',
        ]);
    }

    public function test_get_returns_override_when_set(): void
    {
        $this->service->set($this->m2, $this->product, 'name', 'Shop Custom Name');

        $name = $this->service->get($this->m2, $this->product, 'name');

        $this->assertEquals('Shop Custom Name', $name);
    }

    public function test_hierarchy_resolution_m3_inherits_m2_override(): void
    {
        // M2 sets override
        $this->service->set($this->m2, $this->product, 'name', 'M2 Custom Name');

        // M3 should inherit M2's override (not M1's original)
        $name = $this->service->get($this->m3, $this->product, 'name');

        $this->assertEquals('M2 Custom Name', $name);
    }

    public function test_hierarchy_resolution_m3_overrides_m2(): void
    {
        // M2 sets override
        $this->service->set($this->m2, $this->product, 'name', 'M2 Custom Name');

        // M3 sets its own override
        $this->service->set($this->m3, $this->product, 'name', 'M3 Custom Name');

        // M3 should see its own override
        $nameM3 = $this->service->get($this->m3, $this->product, 'name');
        $this->assertEquals('M3 Custom Name', $nameM3);

        // M2 should still see its own override
        $nameM2 = $this->service->get($this->m2, $this->product, 'name');
        $this->assertEquals('M2 Custom Name', $nameM2);

        // M1 should see original
        $nameM1 = $this->service->get($this->m1, $this->product, 'name');
        $this->assertEquals('Original Product Name', $nameM1);
    }

    public function test_clear_removes_override(): void
    {
        $this->service->set($this->m2, $this->product, 'name', 'Custom Name');

        $cleared = $this->service->clear($this->m2, $this->product, 'name');

        $this->assertTrue($cleared);
        $this->assertDatabaseMissing('commerce_content_overrides', [
            'entity_id' => $this->m2->id,
            'field' => 'name',
        ]);

        // Should now return original
        $name = $this->service->get($this->m2, $this->product, 'name');
        $this->assertEquals('Original Product Name', $name);
    }

    public function test_clear_after_parent_override_falls_back_to_parent(): void
    {
        // M2 sets override
        $this->service->set($this->m2, $this->product, 'name', 'M2 Name');

        // M3 sets override
        $this->service->set($this->m3, $this->product, 'name', 'M3 Name');

        // M3 clears its override
        $this->service->clear($this->m3, $this->product, 'name');

        // M3 should now inherit M2's override
        $name = $this->service->get($this->m3, $this->product, 'name');
        $this->assertEquals('M2 Name', $name);
    }

    public function test_get_effective_returns_merged_data(): void
    {
        // Set different overrides at M2
        $this->service->set($this->m2, $this->product, 'name', 'M2 Name');
        $this->service->set($this->m2, $this->product, 'description', 'M2 Description');

        $effective = $this->service->getEffective($this->m2, $this->product);

        $this->assertEquals('M2 Name', $effective['name']);
        $this->assertEquals('M2 Description', $effective['description']);
        $this->assertEquals('Original short desc.', $effective['short_description']); // Not overridden
        $this->assertEquals(1999, $effective['price']); // Not overridden
    }

    public function test_get_effective_respects_hierarchy(): void
    {
        // M2 overrides name
        $this->service->set($this->m2, $this->product, 'name', 'M2 Name');

        // M3 overrides description
        $this->service->set($this->m3, $this->product, 'description', 'M3 Description');

        $effective = $this->service->getEffective($this->m3, $this->product);

        // M3 should see: M2's name override, M3's description override, original for rest
        $this->assertEquals('M2 Name', $effective['name']);
        $this->assertEquals('M3 Description', $effective['description']);
        $this->assertEquals('Original short desc.', $effective['short_description']);
    }

    public function test_get_override_status(): void
    {
        // M2 overrides name
        $this->service->set($this->m2, $this->product, 'name', 'M2 Name');

        $status = $this->service->getOverrideStatus(
            $this->m3,
            $this->product,
            ['name', 'description']
        );

        // Name is inherited from M2
        $this->assertEquals('M2 Name', $status['name']['value']);
        $this->assertEquals('Original Product Name', $status['name']['original']);
        $this->assertEquals($this->m2->name, $status['name']['source']);
        $this->assertFalse($status['name']['is_overridden']); // Not by M3
        $this->assertEquals($this->m2->name, $status['name']['inherited_from']);

        // Description is original
        $this->assertEquals('Original description from M1.', $status['description']['value']);
        $this->assertEquals('original', $status['description']['source']);
        $this->assertFalse($status['description']['is_overridden']);
        $this->assertNull($status['description']['inherited_from']);
    }

    public function test_value_types_are_preserved(): void
    {
        // Integer
        $this->service->set($this->m2, $this->product, 'custom_int', 42);
        $override = ContentOverride::where('field', 'custom_int')->first();
        $this->assertEquals('integer', $override->value_type);
        $this->assertSame(42, $override->getCastedValue());

        // Boolean
        $this->service->set($this->m2, $this->product, 'custom_bool', true);
        $override = ContentOverride::where('field', 'custom_bool')->first();
        $this->assertEquals('boolean', $override->value_type);
        $this->assertSame(true, $override->getCastedValue());

        // Array/JSON
        $this->service->set($this->m2, $this->product, 'custom_json', ['foo' => 'bar']);
        $override = ContentOverride::where('field', 'custom_json')->first();
        $this->assertEquals('json', $override->value_type);
        $this->assertEquals(['foo' => 'bar'], $override->getCastedValue());

        // Decimal
        $this->service->set($this->m2, $this->product, 'custom_decimal', 3.14);
        $override = ContentOverride::where('field', 'custom_decimal')->first();
        $this->assertEquals('decimal', $override->value_type);
        $this->assertSame(3.14, $override->getCastedValue());
    }

    public function test_set_bulk_creates_multiple_overrides(): void
    {
        $this->service->setBulk($this->m2, $this->product, [
            'name' => 'Bulk Name',
            'description' => 'Bulk Description',
            'short_description' => 'Bulk Short',
        ]);

        $this->assertEquals('Bulk Name', $this->service->get($this->m2, $this->product, 'name'));
        $this->assertEquals('Bulk Description', $this->service->get($this->m2, $this->product, 'description'));
        $this->assertEquals('Bulk Short', $this->service->get($this->m2, $this->product, 'short_description'));
    }

    public function test_clear_all_removes_all_entity_overrides(): void
    {
        $this->service->setBulk($this->m2, $this->product, [
            'name' => 'Name',
            'description' => 'Desc',
        ]);

        $deleted = $this->service->clearAll($this->m2, $this->product);

        $this->assertEquals(2, $deleted);
        $this->assertEquals('Original Product Name', $this->service->get($this->m2, $this->product, 'name'));
    }

    public function test_has_overrides(): void
    {
        $this->assertFalse($this->service->hasOverrides($this->m2, $this->product));

        $this->service->set($this->m2, $this->product, 'name', 'Custom');

        $this->assertTrue($this->service->hasOverrides($this->m2, $this->product));
    }

    public function test_get_overridden_fields(): void
    {
        $this->service->setBulk($this->m2, $this->product, [
            'name' => 'Name',
            'description' => 'Desc',
        ]);

        $fields = $this->service->getOverriddenFields($this->m2, $this->product);

        $this->assertContains('name', $fields);
        $this->assertContains('description', $fields);
        $this->assertCount(2, $fields);
    }

    public function test_copy_overrides(): void
    {
        $this->service->setBulk($this->m2, $this->product, [
            'name' => 'M2 Name',
            'description' => 'M2 Desc',
        ]);

        // Create another M3 and copy M2's overrides to it
        $m3b = $this->m2->createDropshipper('DRP2', 'Dropship Partner 2');
        $copied = $this->service->copyOverrides($this->m2, $m3b, $this->product);

        $this->assertEquals(2, $copied);
        $this->assertEquals('M2 Name', $this->service->get($m3b, $this->product, 'name'));
        $this->assertEquals('M2 Desc', $this->service->get($m3b, $this->product, 'description'));
    }

    public function test_trait_methods_work(): void
    {
        // Test trait methods on Product model directly
        $this->product->setOverride($this->m2, 'name', 'Trait Name');

        $this->assertEquals('Trait Name', $this->product->getOverriddenAttribute('name', $this->m2));

        $effective = $this->product->forEntity($this->m2);
        $this->assertEquals('Trait Name', $effective['name']);

        $this->assertTrue($this->product->hasOverridesFor($this->m2));

        $this->product->clearOverride($this->m2, 'name');
        $this->assertFalse($this->product->hasOverridesFor($this->m2));
    }

    public function test_get_overrideable_fields(): void
    {
        $fields = $this->product->getOverrideableFields();

        $this->assertContains('name', $fields);
        $this->assertContains('description', $fields);
        $this->assertContains('image_url', $fields);
        $this->assertNotContains('price', $fields); // Price should not be in the list
        $this->assertNotContains('sku', $fields);   // SKU should not be in the list
    }
}
