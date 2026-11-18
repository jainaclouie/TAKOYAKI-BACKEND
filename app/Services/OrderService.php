<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Create an order, deduct ingredient stock per recipe and log the movements.
     * Everything happens in one transaction so stock and sales never drift apart.
     *
     * @param  array{items: array<int, array{product_id:int, quantity:int}>, discount?: float|int|string|null, payment_method: string, amount_tendered?: float|int|string|null, customer_note?: string|null}  $data
     */
    public function place(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data) {
            // 1. Merge duplicate product lines: [product_id => quantity]
            $lines = collect($data['items'])
                ->groupBy('product_id')
                ->map(fn ($rows) => (int) $rows->sum('quantity'));

            $products = Product::with('ingredients')
                ->whereIn('id', $lines->keys()->all())
                ->get()
                ->keyBy('id');

            foreach ($products as $product) {
                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => ["{$product->name} is not available."],
                    ]);
                }
            }

            // 2. Work out how much of each ingredient the whole order needs.
            $required = [];
            foreach ($lines as $productId => $qty) {
                foreach ($products[$productId]->ingredients as $ingredient) {
                    $required[$ingredient->id] = round(
                        ($required[$ingredient->id] ?? 0) + (float) $ingredient->pivot->quantity * $qty,
                        3
                    );
                }
            }

            // 3. Lock those ingredient rows and make sure there is enough.
            $ingredients = Ingredient::whereIn('id', array_keys($required))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if (! config('takoyaki.allow_negative_stock')) {
                $short = [];
                foreach ($required as $id => $need) {
                    $have = (float) $ingredients[$id]->stock_qty;
                    if ($have + 0.0001 < $need) {
                        $short[] = sprintf(
                            'Not enough %s: need %s %s, only %s %s left.',
                            $ingredients[$id]->name,
                            $this->fmt($need),
                            $ingredients[$id]->unit,
                            $this->fmt($have),
                            $ingredients[$id]->unit
                        );
                    }
                }
                if ($short) {
                    throw ValidationException::withMessages(['items' => $short]);
                }
            }

            // 4. Totals.
            $subtotal = 0.0;
            $totalCost = 0.0;
            $rows = [];
            foreach ($lines as $productId => $qty) {
                $product = $products[$productId];
                $unitCost = $product->unitCost();
                $lineTotal = round((float) $product->price * $qty, 2);

                $subtotal += $lineTotal;
                $totalCost += $unitCost * $qty;

                $rows[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $product->price,
                    'unit_cost' => $unitCost,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                ];
            }

            $subtotal = round($subtotal, 2);
            $discount = min(round((float) ($data['discount'] ?? 0), 2), $subtotal);
            $total = round($subtotal - $discount, 2);

            // 5. Payment.
            $method = $data['payment_method'];
            $tendered = isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : null;

            if ($method === 'cash') {
                if ($tendered === null || $tendered + 0.001 < $total) {
                    throw ValidationException::withMessages([
                        'amount_tendered' => ['Cash received is less than the total.'],
                    ]);
                }
                $change = round($tendered - $total, 2);
            } else {
                $tendered = $total;
                $change = 0.0;
            }

            // 6. Save the order.
            $order = Order::create([
                'order_number' => 'TMP-' . Str::uuid(),
                'user_id' => $user->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'total_cost' => round($totalCost, 2),
                'payment_method' => $method,
                'amount_tendered' => $tendered,
                'change_due' => $change,
                'status' => Order::STATUS_COMPLETED,
                'customer_note' => $data['customer_note'] ?? null,
            ]);

            $order->update([
                'order_number' => sprintf(
                    '%s-%s-%05d',
                    config('takoyaki.order_prefix'),
                    now()->format('ymd'),
                    $order->id
                ),
            ]);

            $order->items()->createMany($rows);

            // 7. Deduct stock and log it.
            foreach ($required as $ingredientId => $need) {
                $ingredients[$ingredientId]->decrement('stock_qty', $need);

                StockMovement::create([
                    'ingredient_id' => $ingredientId,
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => StockMovement::TYPE_USAGE,
                    'quantity' => -$need,
                    'note' => $order->order_number,
                ]);
            }

            return $order->load(['items', 'user:id,name']);
        });
    }

    /**
     * Void an order and put the exact ingredient quantities it used back into stock.
     */
    public function void(Order $order, User $user): Order
    {
        return DB::transaction(function () use ($order, $user) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === Order::STATUS_VOIDED) {
                throw ValidationException::withMessages([
                    'order' => ['This order is already voided.'],
                ]);
            }

            $usage = StockMovement::where('order_id', $order->id)
                ->where('type', StockMovement::TYPE_USAGE)
                ->get();

            foreach ($usage as $movement) {
                $qty = abs((float) $movement->quantity);

                Ingredient::whereKey($movement->ingredient_id)->increment('stock_qty', $qty);

                StockMovement::create([
                    'ingredient_id' => $movement->ingredient_id,
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => StockMovement::TYPE_VOID_RETURN,
                    'quantity' => $qty,
                    'note' => 'Void ' . $order->order_number,
                ]);
            }

            $order->update([
                'status' => Order::STATUS_VOIDED,
                'voided_at' => now(),
            ]);

            return $order->load(['items', 'user:id,name']);
        });
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}
