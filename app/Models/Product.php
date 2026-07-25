<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image_path',
        'category_id',
        'supplier_id',
        'barcode',
        'purchase_unit_id',
        'selling_unit_id',
        'conversion_qty',
        'cost_price',
        'selling_price',
        'min_stock_level',
        'status',
        'promo_discount_type',
        'promo_discount_value',
        'promo_starts_at',
        'promo_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'conversion_qty' => 'decimal:3',
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'min_stock_level' => 'decimal:3',
            'promo_discount_value' => 'decimal:2',
            'promo_starts_at' => 'date',
            'promo_ends_at' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function sellingUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'selling_unit_id');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * A standing promotional discount (Sec. 20.19 request) — always-on when
     * neither bound is set, or scoped to a date window when they are.
     */
    public function hasActivePromo(): bool
    {
        if ($this->promo_discount_type === 'none') {
            return false;
        }

        $today = now()->startOfDay();

        if ($this->promo_starts_at && $today->lt($this->promo_starts_at)) {
            return false;
        }

        if ($this->promo_ends_at && $today->gt($this->promo_ends_at)) {
            return false;
        }

        return true;
    }

    public function effectiveSellingPrice(): float
    {
        if (! $this->hasActivePromo()) {
            return (float) $this->selling_price;
        }

        $price = (float) $this->selling_price;
        $value = (float) $this->promo_discount_value;

        $discounted = $this->promo_discount_type === 'percentage'
            ? $price - round($price * $value / 100, 2)
            : $price - $value;

        return max(0.0, $discounted);
    }

    /**
     * Every batch this product has ever had, active or not — the batches
     * module (Phase 04) is the real owner of this relation, but the
     * product list already wants stock-on-hand and nearest expiry now.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
