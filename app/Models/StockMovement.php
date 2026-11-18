<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_USAGE = 'usage';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_WASTE = 'waste';
    public const TYPE_VOID_RETURN = 'void_return';
    public const TYPE_OPENING = 'opening';

    protected $fillable = [
        'ingredient_id',
        'user_id',
        'order_id',
        'type',
        'quantity',
        'total_cost',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'total_cost' => 'decimal:2',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
