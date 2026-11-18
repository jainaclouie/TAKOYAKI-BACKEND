<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    protected $fillable = [
        'name',
        'unit',
        'stock_qty',
        'reorder_level',
        'cost_per_unit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stock_qty' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'cost_per_unit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_ingredients')->withPivot('quantity');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isLow(): bool
    {
        return (float) $this->stock_qty <= (float) $this->reorder_level;
    }
}
