<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\View\Modal\Admin;

use Core\Mod\Commerce\Models\Coupon;
use Core\Mod\Commerce\Services\CouponService;
use Core\Tenant\Models\Package;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('hub::admin.layouts.app')]
#[Title('Coupons')]
class CouponManager extends Component
{
    use WithPagination;

    // Bulk selection
    public array $selected = [];

    public bool $selectAll = false;

    public bool $showBulkDeleteModal = false;

    public bool $showBulkGenerateModal = false;

    public bool $showModal = false;

    public ?int $editingId = null;

    // Filters
    public string $search = '';

    public string $statusFilter = '';

    // Form fields
    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $type = 'percentage';

    public float $value = 0;

    public ?float $min_amount = null;

    public ?float $max_discount = null;

    public string $applies_to = 'all';

    public array $package_ids = [];

    public ?int $max_uses = null;

    public int $max_uses_per_workspace = 1;

    public string $duration = 'once';

    public ?int $duration_months = null;

    public ?string $valid_from = null;

    public ?string $valid_until = null;

    public bool $is_active = true;

    // Bulk generation fields
    public int $bulk_count = 10;

    public string $bulk_code_prefix = '';

    public string $bulk_name = '';

    public string $bulk_type = 'percentage';

    public float $bulk_value = 0;

    public ?float $bulk_min_amount = null;

    public ?float $bulk_max_discount = null;

    public string $bulk_applies_to = 'all';

    public array $bulk_package_ids = [];

    public ?int $bulk_max_uses = 1;

    public int $bulk_max_uses_per_workspace = 1;

    public string $bulk_duration = 'once';

    public ?int $bulk_duration_months = null;

    public ?string $bulk_valid_from = null;

    public ?string $bulk_valid_until = null;

    public bool $bulk_is_active = true;

    /**
     * Authorize access - Hades tier only.
     */
    public function mount(): void
    {
        if (! auth()->user()?->isHades()) {
            abort(403, 'Hades tier required for coupon management.');
        }
    }

    protected function rules(): array
    {
        $uniqueRule = $this->editingId
            ? 'unique:coupons,code,'.$this->editingId
            : 'unique:coupons,code';

        return [
            'code' => ['required', 'string', 'max:50', $uniqueRule, 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'in:percentage,fixed_amount'],
            'value' => ['required', 'numeric', 'min:0.01'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['required', 'in:all,packages'],
            'package_ids' => ['array'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_workspace' => ['required', 'integer', 'min:1'],
            'duration' => ['required', 'in:once,repeating,forever'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['boolean'],
        ];
    }

    protected $messages = [
        'code.regex' => 'Code must contain only uppercase letters, numbers, hyphens, and underscores.',
    ];

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
        $this->selectAll = false;
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selected = $this->coupons->pluck('id')->map(fn ($id) => (string) $id)->all();
        } else {
            $this->selected = [];
        }
    }

    public function exportSelected(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('commerce::commerce.bulk.no_selection'));

            return;
        }

        $coupons = Coupon::whereIn('id', $this->selected)->get();

        $csv = "Code,Name,Type,Value,Duration,Max Uses,Used Count,Active,Valid From,Valid Until\n";
        foreach ($coupons as $coupon) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $coupon->code,
                str_replace(',', ' ', $coupon->name),
                $coupon->type,
                $coupon->value,
                $coupon->duration,
                $coupon->max_uses ?? 'unlimited',
                $coupon->used_count,
                $coupon->is_active ? 'Yes' : 'No',
                $coupon->valid_from?->format('Y-m-d') ?? '',
                $coupon->valid_until?->format('Y-m-d') ?? ''
            );
        }

        $this->dispatch('download-csv', filename: 'coupons-export-'.now()->format('Y-m-d').'.csv', content: $csv);
        session()->flash('message', __('commerce::commerce.bulk.export_success', ['count' => count($this->selected)]));
        $this->selected = [];
        $this->selectAll = false;
    }

    public function bulkActivate(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('commerce::commerce.bulk.no_selection'));

            return;
        }

        $count = Coupon::whereIn('id', $this->selected)->update(['is_active' => true]);

        session()->flash('message', __('commerce::commerce.bulk.activated', ['count' => $count]));
        $this->selected = [];
        $this->selectAll = false;
    }

    public function bulkDeactivate(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('commerce::commerce.bulk.no_selection'));

            return;
        }

        $count = Coupon::whereIn('id', $this->selected)->update(['is_active' => false]);

        session()->flash('message', __('commerce::commerce.bulk.deactivated', ['count' => $count]));
        $this->selected = [];
        $this->selectAll = false;
    }

    public function confirmBulkDelete(): void
    {
        if (empty($this->selected)) {
            session()->flash('error', __('commerce::commerce.bulk.no_selection'));

            return;
        }

        $this->showBulkDeleteModal = true;
    }

    public function bulkDelete(): void
    {
        if (empty($this->selected)) {
            return;
        }

        // Only delete coupons that haven't been used
        $deletable = Coupon::whereIn('id', $this->selected)->where('used_count', 0)->pluck('id');
        $skipped = count($this->selected) - $deletable->count();

        Coupon::whereIn('id', $deletable)->delete();

        $message = __('commerce::commerce.bulk.deleted', ['count' => $deletable->count()]);
        if ($skipped > 0) {
            $message .= ' '.__('commerce::commerce.bulk.skipped_used', ['count' => $skipped]);
        }

        session()->flash('message', $message);
        $this->selected = [];
        $this->selectAll = false;
        $this->showBulkDeleteModal = false;
    }

    public function closeBulkDeleteModal(): void
    {
        $this->showBulkDeleteModal = false;
    }

    public function openBulkGenerate(): void
    {
        $this->resetBulkForm();
        $this->showBulkGenerateModal = true;
    }

    public function closeBulkGenerateModal(): void
    {
        $this->showBulkGenerateModal = false;
        $this->resetBulkForm();
    }

    protected function resetBulkForm(): void
    {
        $this->bulk_count = 10;
        $this->bulk_code_prefix = '';
        $this->bulk_name = '';
        $this->bulk_type = 'percentage';
        $this->bulk_value = 0;
        $this->bulk_min_amount = null;
        $this->bulk_max_discount = null;
        $this->bulk_applies_to = 'all';
        $this->bulk_package_ids = [];
        $this->bulk_max_uses = 1;
        $this->bulk_max_uses_per_workspace = 1;
        $this->bulk_duration = 'once';
        $this->bulk_duration_months = null;
        $this->bulk_valid_from = null;
        $this->bulk_valid_until = null;
        $this->bulk_is_active = true;
    }

    protected function bulkGenerateRules(): array
    {
        return [
            'bulk_count' => ['required', 'integer', 'min:1', 'max:100'],
            'bulk_code_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9_-]*$/'],
            'bulk_name' => ['required', 'string', 'max:100'],
            'bulk_type' => ['required', 'in:percentage,fixed_amount'],
            'bulk_value' => ['required', 'numeric', 'min:0.01'],
            'bulk_min_amount' => ['nullable', 'numeric', 'min:0'],
            'bulk_max_discount' => ['nullable', 'numeric', 'min:0'],
            'bulk_applies_to' => ['required', 'in:all,packages'],
            'bulk_package_ids' => ['array'],
            'bulk_max_uses' => ['nullable', 'integer', 'min:1'],
            'bulk_max_uses_per_workspace' => ['required', 'integer', 'min:1'],
            'bulk_duration' => ['required', 'in:once,repeating,forever'],
            'bulk_duration_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'bulk_valid_from' => ['nullable', 'date'],
            'bulk_valid_until' => ['nullable', 'date', 'after_or_equal:bulk_valid_from'],
            'bulk_is_active' => ['boolean'],
        ];
    }

    public function generateBulk(CouponService $couponService): void
    {
        $this->bulk_code_prefix = strtoupper($this->bulk_code_prefix);

        $this->validate($this->bulkGenerateRules());

        $baseData = [
            'code_prefix' => $this->bulk_code_prefix ?: null,
            'name' => $this->bulk_name,
            'type' => $this->bulk_type,
            'value' => $this->bulk_value,
            'min_amount' => $this->bulk_min_amount,
            'max_discount' => $this->bulk_max_discount,
            'applies_to' => $this->bulk_applies_to,
            'package_ids' => $this->bulk_applies_to === 'packages' ? $this->bulk_package_ids : null,
            'max_uses' => $this->bulk_max_uses,
            'max_uses_per_workspace' => $this->bulk_max_uses_per_workspace,
            'duration' => $this->bulk_duration,
            'duration_months' => $this->bulk_duration === 'repeating' ? $this->bulk_duration_months : null,
            'valid_from' => $this->bulk_valid_from ? \Carbon\Carbon::parse($this->bulk_valid_from) : null,
            'valid_until' => $this->bulk_valid_until ? \Carbon\Carbon::parse($this->bulk_valid_until) : null,
            'is_active' => $this->bulk_is_active,
        ];

        $coupons = $couponService->generateBulk($this->bulk_count, $baseData);

        session()->flash('message', __('commerce::commerce.coupons.bulk.generated', ['count' => count($coupons)]));
        $this->closeBulkGenerateModal();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        // Generate a random code
        $this->code = strtoupper(substr(md5(uniqid()), 0, 8));
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $coupon = Coupon::findOrFail($id);

        $this->editingId = $id;
        $this->code = $coupon->code;
        $this->name = $coupon->name;
        $this->description = $coupon->description ?? '';
        $this->type = $coupon->type;
        $this->value = (float) $coupon->value;
        $this->min_amount = $coupon->min_amount ? (float) $coupon->min_amount : null;
        $this->max_discount = $coupon->max_discount ? (float) $coupon->max_discount : null;
        $this->applies_to = $coupon->applies_to ?? 'all';
        $this->package_ids = $coupon->package_ids ?? [];
        $this->max_uses = $coupon->max_uses;
        $this->max_uses_per_workspace = $coupon->max_uses_per_workspace ?? 1;
        $this->duration = $coupon->duration ?? 'once';
        $this->duration_months = $coupon->duration_months;
        $this->valid_from = $coupon->valid_from?->format('Y-m-d');
        $this->valid_until = $coupon->valid_until?->format('Y-m-d');
        $this->is_active = $coupon->is_active;

        $this->showModal = true;
    }

    public function save(): void
    {
        // Ensure code is uppercase
        $this->code = strtoupper($this->code);

        $this->validate();

        $data = [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'type' => $this->type,
            'value' => $this->value,
            'min_amount' => $this->min_amount,
            'max_discount' => $this->max_discount,
            'applies_to' => $this->applies_to,
            'package_ids' => $this->applies_to === 'packages' ? $this->package_ids : null,
            'max_uses' => $this->max_uses,
            'max_uses_per_workspace' => $this->max_uses_per_workspace,
            'duration' => $this->duration,
            'duration_months' => $this->duration === 'repeating' ? $this->duration_months : null,
            'valid_from' => $this->valid_from ? \Carbon\Carbon::parse($this->valid_from) : null,
            'valid_until' => $this->valid_until ? \Carbon\Carbon::parse($this->valid_until) : null,
            'is_active' => $this->is_active,
        ];

        if ($this->editingId) {
            Coupon::findOrFail($this->editingId)->update($data);
            session()->flash('message', 'Coupon updated successfully.');
        } else {
            Coupon::create($data);
            session()->flash('message', 'Coupon created successfully.');
        }

        $this->closeModal();
    }

    public function toggleActive(int $id): void
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->update(['is_active' => ! $coupon->is_active]);

        session()->flash('message', $coupon->is_active ? 'Coupon activated.' : 'Coupon deactivated.');
    }

    public function delete(int $id): void
    {
        $coupon = Coupon::findOrFail($id);

        // Check if coupon has been used
        if ($coupon->used_count > 0) {
            session()->flash('error', 'Cannot delete coupon that has been used. Deactivate it instead.');

            return;
        }

        $coupon->delete();
        session()->flash('message', 'Coupon deleted successfully.');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->code = '';
        $this->name = '';
        $this->description = '';
        $this->type = 'percentage';
        $this->value = 0;
        $this->min_amount = null;
        $this->max_discount = null;
        $this->applies_to = 'all';
        $this->package_ids = [];
        $this->max_uses = null;
        $this->max_uses_per_workspace = 1;
        $this->duration = 'once';
        $this->duration_months = null;
        $this->valid_from = null;
        $this->valid_until = null;
        $this->is_active = true;
    }

    #[Computed]
    public function coupons()
    {
        return Coupon::query()
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('code', 'like', "%{$this->search}%")
                        ->orWhere('name', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->statusFilter === 'valid', fn ($q) => $q->valid())
            ->when($this->statusFilter === 'expired', function ($q) {
                $q->where(function ($query) {
                    $query->where('valid_until', '<', now())
                        ->orWhere(function ($q2) {
                            $q2->whereNotNull('max_uses')
                                ->whereRaw('used_count >= max_uses');
                        });
                });
            })
            ->latest()
            ->paginate(25);
    }

    #[Computed]
    public function packages()
    {
        return Package::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function statusOptions(): array
    {
        return [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'valid' => 'Currently valid',
            'expired' => 'Expired or maxed',
        ];
    }

    #[Computed]
    public function tableColumns(): array
    {
        return [
            'Code',
            'Name',
            'Discount',
            'Duration',
            ['label' => 'Usage', 'align' => 'center'],
            ['label' => 'Status', 'align' => 'center'],
            'Validity',
            ['label' => 'Actions', 'align' => 'center'],
        ];
    }

    #[Computed]
    public function tableRowIds(): array
    {
        return $this->coupons->pluck('id')->all();
    }

    #[Computed]
    public function tableRows(): array
    {
        return $this->coupons->map(function ($c) {
            // Discount display
            $discount = $c->isPercentage()
                ? "{$c->value}% off"
                : 'GBP '.number_format($c->value, 2).' off';
            $discountLines = [['bold' => $discount]];
            if ($c->min_amount) {
                $discountLines[] = ['muted' => 'Min: GBP '.number_format($c->min_amount, 2)];
            }

            // Duration display
            $durationLabel = match ($c->duration) {
                'once' => 'Once',
                'repeating' => "{$c->duration_months} months",
                'forever' => 'Forever',
                default => ucfirst($c->duration),
            };
            $durationColor = match ($c->duration) {
                'once' => 'gray',
                'repeating' => 'blue',
                'forever' => 'purple',
                default => 'gray',
            };

            // Status display
            $statusLabel = $c->is_active ? ($c->isValid() ? 'Active' : 'Exhausted') : 'Inactive';
            $statusColor = $c->is_active ? ($c->isValid() ? 'green' : 'amber') : 'gray';

            // Validity display
            $validityLines = [];
            if ($c->valid_from || $c->valid_until) {
                if ($c->valid_from) {
                    $validityLines[] = ['muted' => 'From: '.$c->valid_from->format('d M Y')];
                }
                if ($c->valid_until) {
                    $validityLines[] = $c->valid_until->isPast()
                        ? ['badge' => 'Until: '.$c->valid_until->format('d M Y'), 'color' => 'red']
                        : ['muted' => 'Until: '.$c->valid_until->format('d M Y')];
                }
            }

            // Actions
            $actions = [
                ['icon' => 'pencil', 'click' => "openEdit({$c->id})", 'title' => 'Edit'],
                ['icon' => $c->is_active ? 'pause' : 'play', 'click' => "toggleActive({$c->id})", 'title' => $c->is_active ? 'Deactivate' : 'Activate'],
            ];
            if ($c->used_count === 0) {
                $actions[] = ['icon' => 'trash', 'click' => "delete({$c->id})", 'confirm' => 'Are you sure you want to delete this coupon?', 'title' => 'Delete', 'class' => 'text-red-600'];
            }

            return [
                ['mono' => $c->code],
                [
                    'lines' => array_filter([
                        ['bold' => $c->name],
                        $c->description ? ['muted' => \Illuminate\Support\Str::limit($c->description, 30)] : null,
                    ]),
                ],
                ['lines' => $discountLines],
                ['badge' => $durationLabel, 'color' => $durationColor],
                $c->max_uses ? "{$c->used_count} / {$c->max_uses}" : (string) $c->used_count,
                ['badge' => $statusLabel, 'color' => $statusColor],
                ! empty($validityLines) ? ['lines' => $validityLines] : ['muted' => 'No date limits'],
                ['actions' => $actions],
            ];
        })->all();
    }

    public function render()
    {
        return view('commerce::admin.coupon-manager')
            ->layout('hub::admin.layouts.app', ['title' => 'Coupons']);
    }
}
