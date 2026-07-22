<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
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
    ];

    protected function casts(): array
    {
        return [
            'conversion_qty' => 'decimal:3',
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'min_stock_level' => 'decimal:3',
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
     * Every batch this product has ever had, active or not — the batches
     * module (Phase 04) is the real owner of this relation, but the
     * product list already wants stock-on-hand and nearest expiry now.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }
}
