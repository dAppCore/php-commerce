<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Admin;

use Core\Mod\Commerce\Models\Entity;
use Core\Mod\Commerce\Models\Product;
use Core\Mod\Commerce\Models\ProductAssignment;
use Core\Mod\Commerce\Services\ProductCatalogService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('hub::admin.layouts.app')]
class ProductManager extends Component
{
    use WithPagination;

    // Filters
    #[Url]
    public ?int $entityId = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $stockFilter = '';

    // Modal state
    public bool $showModal = false;

    public bool $showAssignModal = false;

    public ?int $editingId = null;

    // Product form
    public array $form = [
        'sku' => '',
        'name' => '',
        'description' => '',
        'short_description' => '',
        'category' => '',
        'subcategory' => '',
        'price' => 0,
        'cost_price' => null,
        'rrp' => null,
        'currency' => 'GBP',
        'tax_class' => 'standard',
        'type' => 'simple',
        'track_stock' => true,
        'stock_quantity' => 0,
        'low_stock_threshold' => 5,
        'allow_backorder' => false,
        'is_active' => true,
        'is_featured' => false,
        'is_visible' => true,
    ];

    // Assignment form
    public array $assignForm = [
        'entity_id' => null,
        'product_id' => null,
        'price_override' => null,
        'margin_percent' => null,
        'name_override' => '',
        'description_override' => '',
        'is_active' => true,
        'is_featured' => false,
    ];

    protected ProductCatalogService $catalog;

    public function boot(ProductCatalogService $catalog): void
    {
        $this->catalog = $catalog;
    }

    #[Computed]
    public function selectedEntity(): ?Entity
    {
        return $this->entityId ? Entity::find($this->entityId) : null;
    }

    #[Computed]
    public function masterEntities()
    {
        return Entity::masters()->active()->get();
    }

    #[Computed]
    public function allEntities()
    {
        return Entity::active()->orderBy('path')->get();
    }

    #[Computed]
    public function categories(): array
    {
        $query = Product::query()->distinct();
        if ($this->entityId) {
            $entity = Entity::find($this->entityId);
            if ($entity?->isM1()) {
                $query->where('owner_entity_id', $this->entityId);
            }
        }

        $categories = $query->pluck('category')->filter()->unique()->sort()->values()->toArray();

        // Convert to associative array for filter component
        return array_combine($categories, $categories);
    }

    #[Computed]
    public function stockFilters(): array
    {
        return [
            'in_stock' => __('commerce::commerce.filters.in_stock'),
            'low_stock' => __('commerce::commerce.filters.low_stock'),
            'out_of_stock' => __('commerce::commerce.filters.out_of_stock'),
            'backorder' => __('commerce::commerce.filters.backorder'),
        ];
    }

    #[Computed]
    public function entityOptions(): array
    {
        return $this->masterEntities->mapWithKeys(function ($entity) {
            return [$entity->id => "{$entity->code} - {$entity->name}"];
        })->all();
    }

    #[Computed]
    public function tableColumns(): array
    {
        return [
            __('commerce::commerce.table.product'),
            __('commerce::commerce.table.sku'),
            ['label' => __('commerce::commerce.table.price'), 'align' => 'right'],
            __('commerce::commerce.table.stock'),
            ['label' => __('commerce::commerce.table.status'), 'align' => 'center'],
            __('commerce::commerce.table.assignments'),
            ['label' => __('commerce::commerce.table.actions'), 'align' => 'center'],
        ];
    }

    #[Computed]
    public function tableRows(): array
    {
        $stockColors = [
            'in_stock' => 'green',
            'low_stock' => 'amber',
            'out_of_stock' => 'red',
            'backorder' => 'blue',
        ];

        return $this->products->map(function ($product) use ($stockColors) {
            $assignments = $this->getAssignmentsForProduct($product->id);

            // Build product name lines with image
            $productLines = [
                ['bold' => $product->name],
                ['muted' => $product->category ?? __('commerce::commerce.products.uncategorised')],
            ];

            // Build price lines
            $priceLines = [['bold' => $product->formatted_price]];
            if ($product->cost_price) {
                $priceLines[] = ['muted' => 'Cost: £'.number_format($product->cost_price / 100, 2)];
            }

            // Build stock display
            $stockCell = $product->track_stock
                ? ['badge' => $product->stock_quantity.' '.__('commerce::commerce.products.units'), 'color' => $stockColors[$product->stock_status] ?? 'gray']
                : ['muted' => __('commerce::commerce.products.not_tracked')];

            // Build status toggles
            $statusLines = [];
            if ($product->is_active) {
                $statusLines[] = ['badge' => __('commerce::commerce.status.active'), 'color' => 'green'];
            } else {
                $statusLines[] = ['badge' => __('commerce::commerce.status.inactive'), 'color' => 'gray'];
            }
            if ($product->is_featured) {
                $statusLines[] = ['badge' => __('commerce::commerce.status.featured'), 'color' => 'amber'];
            }

            return [
                [
                    'image' => $product->image_url,
                    'lines' => $productLines,
                ],
                ['mono' => $product->sku],
                ['lines' => $priceLines],
                $stockCell,
                ['lines' => $statusLines],
                [
                    'lines' => [
                        ['muted' => $assignments->count().' '.__('commerce::commerce.products.entities')],
                    ],
                    'actions' => [
                        ['icon' => 'plus', 'click' => "openAssign({$product->id})", 'title' => __('commerce::commerce.actions.assign')],
                    ],
                ],
                [
                    'actions' => [
                        ['icon' => 'pencil', 'click' => "openEdit({$product->id})", 'title' => __('commerce::commerce.actions.edit')],
                        ['icon' => 'trash', 'click' => "delete({$product->id})", 'confirm' => __('commerce::commerce.products.actions.delete_confirm'), 'title' => __('commerce::commerce.actions.delete')],
                    ],
                ],
            ];
        })->all();
    }

    public function getProductsProperty()
    {
        $query = Product::with('ownerEntity');

        // Filter by entity
        if ($this->entityId) {
            $entity = Entity::find($this->entityId);
            if ($entity?->isM1()) {
                $query->where('owner_entity_id', $this->entityId);
            }
        }

        // Search
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('sku', 'like', '%'.$this->search.'%');
            });
        }

        // Category filter
        if ($this->category) {
            $query->where('category', $this->category);
        }

        // Stock filter
        if ($this->stockFilter) {
            $query->where('stock_status', $this->stockFilter);
        }

        return $query->orderBy('sort_order')->orderBy('name')->paginate(20);
    }

    public function openCreate(): void
    {
        if (! $this->entityId) {
            $master = $this->masterEntities->first();
            if ($master) {
                $this->entityId = $master->id;
            }
        }

        $this->resetForm();
        $this->form['sku'] = Product::generateSku();
        $this->showModal = true;
    }

    public function openEdit(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $this->editingId = $productId;

        $this->form = [
            'sku' => $product->sku,
            'name' => $product->name,
            'description' => $product->description ?? '',
            'short_description' => $product->short_description ?? '',
            'category' => $product->category ?? '',
            'subcategory' => $product->subcategory ?? '',
            'price' => $product->price,
            'cost_price' => $product->cost_price,
            'rrp' => $product->rrp,
            'currency' => $product->currency,
            'tax_class' => $product->tax_class,
            'type' => $product->type,
            'track_stock' => $product->track_stock,
            'stock_quantity' => $product->stock_quantity,
            'low_stock_threshold' => $product->low_stock_threshold,
            'allow_backorder' => $product->allow_backorder,
            'is_active' => $product->is_active,
            'is_featured' => $product->is_featured,
            'is_visible' => $product->is_visible,
        ];

        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'form.sku' => 'required|string|max:64',
            'form.name' => 'required|string|max:255',
            'form.price' => 'required|integer|min:0',
            'form.type' => 'required|in:simple,variable,bundle,virtual,subscription',
        ]);

        $entity = Entity::findOrFail($this->entityId);

        if ($this->editingId) {
            $product = Product::findOrFail($this->editingId);
            $this->catalog->updateProduct($product, $this->form);
        } else {
            $this->catalog->createProduct($entity, $this->form);
        }

        $this->showModal = false;
        $this->resetForm();
    }

    public function delete(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $this->catalog->deleteProduct($product);
    }

    public function toggleActive(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $product->update(['is_active' => ! $product->is_active]);
    }

    public function toggleFeatured(int $productId): void
    {
        $product = Product::findOrFail($productId);
        $product->update(['is_featured' => ! $product->is_featured]);
    }

    // Assignment methods

    public function openAssign(int $productId): void
    {
        $this->assignForm = [
            'entity_id' => null,
            'product_id' => $productId,
            'price_override' => null,
            'margin_percent' => null,
            'name_override' => '',
            'description_override' => '',
            'is_active' => true,
            'is_featured' => false,
        ];
        $this->showAssignModal = true;
    }

    public function saveAssignment(): void
    {
        $this->validate([
            'assignForm.entity_id' => 'required|exists:commerce_entities,id',
            'assignForm.product_id' => 'required|exists:commerce_products,id',
        ]);

        $entity = Entity::findOrFail($this->assignForm['entity_id']);
        $product = Product::findOrFail($this->assignForm['product_id']);

        $overrides = array_filter([
            'price_override' => $this->assignForm['price_override'],
            'margin_percent' => $this->assignForm['margin_percent'],
            'name_override' => $this->assignForm['name_override'] ?: null,
            'description_override' => $this->assignForm['description_override'] ?: null,
            'is_active' => $this->assignForm['is_active'],
            'is_featured' => $this->assignForm['is_featured'],
        ], fn ($v) => $v !== null && $v !== '');

        $this->catalog->assignProduct($entity, $product, $overrides);

        $this->showAssignModal = false;
    }

    public function removeAssignment(int $assignmentId): void
    {
        $assignment = ProductAssignment::findOrFail($assignmentId);
        $this->catalog->removeAssignment($assignment);
    }

    public function getAssignmentsForProduct(int $productId)
    {
        return ProductAssignment::with('entity')
            ->where('product_id', $productId)
            ->get();
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'sku' => '',
            'name' => '',
            'description' => '',
            'short_description' => '',
            'category' => '',
            'subcategory' => '',
            'price' => 0,
            'cost_price' => null,
            'rrp' => null,
            'currency' => 'GBP',
            'tax_class' => 'standard',
            'type' => 'simple',
            'track_stock' => true,
            'stock_quantity' => 0,
            'low_stock_threshold' => 5,
            'allow_backorder' => false,
            'is_active' => true,
            'is_featured' => false,
            'is_visible' => true,
        ];
    }

    public function render(): View
    {
        return view('commerce::admin.product-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Product Catalog']);
    }
}
