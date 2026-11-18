<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'category',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredients')->withPivot('quantity');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Cost of one unit of this product, from the recipe and current ingredient costs.
     * Expects the `ingredients` relation to be loaded.
     */
    public function unitCost(): float
    {
        return round(
            $this->ingredients->sum(
                fn (Ingredient $i) => (float) $i->pivot->quantity * (float) $i->cost_per_unit
            ),
            4
        );
    }

    /**
     * How many units can be made from the stock on hand.
     * Returns null when the product has no recipe (stock is not tracked).
     */
    public function servingsAvailable(): ?int
    {
        if ($this->ingredients->isEmpty()) {
            return null;
        }

        $possible = $this->ingredients->map(function (Ingredient $i) {
            $needed = (float) $i->pivot->quantity;

            return $needed > 0
                ? floor(round((float) $i->stock_qty / $needed, 6))
                : PHP_INT_MAX;
        });

        return (int) max(0, $possible->min());
    }
}
