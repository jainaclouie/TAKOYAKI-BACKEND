<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isOwner = (bool) $request->user()?->isOwner();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'price' => (float) $this->price,
            'is_active' => $this->is_active,
            'servings_available' => $this->servingsAvailable(),
            // Recipe and cost are only visible to the owner.
            $this->mergeWhen($isOwner, [
                'unit_cost' => $this->unitCost(),
                'recipe' => $this->ingredients->map(fn ($i) => [
                    'ingredient_id' => $i->id,
                    'name' => $i->name,
                    'unit' => $i->unit,
                    'quantity' => (float) $i->pivot->quantity,
                ])->values(),
            ]),
        ];
    }
}
