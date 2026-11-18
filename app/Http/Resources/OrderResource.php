<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isOwner = (bool) $request->user()?->isOwner();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'payment_method' => $this->payment_method,
            'amount_tendered' => $this->amount_tendered !== null ? (float) $this->amount_tendered : null,
            'change_due' => (float) $this->change_due,
            'customer_note' => $this->customer_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'cashier' => $this->whenLoaded('user', fn () => $this->user?->name),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'unit_price' => (float) $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => (float) $item->line_total,
            ])->values()),
            $this->mergeWhen($isOwner, [
                'total_cost' => (float) $this->total_cost,
                'profit' => round((float) $this->total - (float) $this->total_cost, 2),
            ]),
        ];
    }
}
