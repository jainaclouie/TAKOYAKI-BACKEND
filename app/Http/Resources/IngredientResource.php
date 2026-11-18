<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Ingredient */
class IngredientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isOwner = (bool) $request->user()?->isOwner();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit,
            'stock_qty' => (float) $this->stock_qty,
            'reorder_level' => (float) $this->reorder_level,
            'is_active' => $this->is_active,
            'is_low' => $this->isLow(),
            // Costs are only visible to the owner.
            $this->mergeWhen($isOwner, [
                'cost_per_unit' => (float) $this->cost_per_unit,
            ]),
        ];
    }
}
