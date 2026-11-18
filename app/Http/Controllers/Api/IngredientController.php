<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IngredientResource;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $ingredients = Ingredient::query()
            ->when(! $request->user()->isOwner(), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return IngredientResource::collection($ingredients);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:ingredients,name'],
            'unit' => ['required', 'string', 'max:20'],
            'stock_qty' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $ingredient = DB::transaction(function () use ($data, $request) {
            $opening = (float) ($data['stock_qty'] ?? 0);

            $ingredient = Ingredient::create([
                'name' => $data['name'],
                'unit' => $data['unit'],
                'stock_qty' => $opening,
                'reorder_level' => $data['reorder_level'] ?? 0,
                'cost_per_unit' => $data['cost_per_unit'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            if ($opening > 0) {
                StockMovement::create([
                    'ingredient_id' => $ingredient->id,
                    'user_id' => $request->user()->id,
                    'type' => StockMovement::TYPE_OPENING,
                    'quantity' => $opening,
                    'note' => 'Opening stock',
                ]);
            }

            return $ingredient;
        });

        return (new IngredientResource($ingredient))->response()->setStatusCode(201);
    }

    /** Edit details only. Stock levels change through restock / adjust. */
    public function update(Request $request, Ingredient $ingredient): IngredientResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('ingredients', 'name')->ignore($ingredient->id)],
            'unit' => ['required', 'string', 'max:20'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $ingredient->update([
            'name' => $data['name'],
            'unit' => $data['unit'],
            'reorder_level' => $data['reorder_level'] ?? $ingredient->reorder_level,
            'cost_per_unit' => $data['cost_per_unit'] ?? $ingredient->cost_per_unit,
            'is_active' => $data['is_active'] ?? $ingredient->is_active,
        ]);

        return new IngredientResource($ingredient);
    }

    public function destroy(Ingredient $ingredient): JsonResponse
    {
        if ($ingredient->products()->exists()) {
            return response()->json([
                'message' => 'This ingredient is used in a product recipe. Remove it from the recipes first, or mark it inactive instead.',
            ], 422);
        }

        $ingredient->delete();

        return response()->json(['message' => 'Ingredient deleted.']);
    }

    /**
     * Record a purchase. If the total amount paid is given, the ingredient's
     * cost per unit is updated using a weighted average with the stock on hand.
     */
    public function restock(Request $request, Ingredient $ingredient): IngredientResource
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0'],
            'total_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $request, $ingredient) {
            $locked = Ingredient::whereKey($ingredient->id)->lockForUpdate()->firstOrFail();

            $qty = (float) $data['quantity'];
            $onHand = max(0.0, (float) $locked->stock_qty);

            if (isset($data['total_cost'])) {
                $newUnitCost = (float) $data['total_cost'] / $qty;
                $locked->cost_per_unit = round(
                    (($onHand * (float) $locked->cost_per_unit) + $qty * $newUnitCost) / ($onHand + $qty),
                    4
                );
            }

            $locked->stock_qty = round((float) $locked->stock_qty + $qty, 3);
            $locked->save();

            StockMovement::create([
                'ingredient_id' => $locked->id,
                'user_id' => $request->user()->id,
                'type' => StockMovement::TYPE_PURCHASE,
                'quantity' => $qty,
                'total_cost' => $data['total_cost'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $ingredient->setRawAttributes($locked->getAttributes(), true);
        });

        return new IngredientResource($ingredient);
    }

    /**
     * type=count: set stock to a physically counted quantity.
     * type=waste: remove spoiled / dropped stock.
     */
    public function adjust(Request $request, Ingredient $ingredient): IngredientResource
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['count', 'waste'])],
            'quantity' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $request, $ingredient) {
            $locked = Ingredient::whereKey($ingredient->id)->lockForUpdate()->firstOrFail();
            $current = (float) $locked->stock_qty;
            $qty = (float) $data['quantity'];

            if ($data['type'] === 'count') {
                $delta = round($qty - $current, 3);
                $type = StockMovement::TYPE_ADJUSTMENT;
            } else {
                if ($qty > $current + 0.0001) {
                    throw ValidationException::withMessages([
                        'quantity' => ['Waste is more than the stock on hand.'],
                    ]);
                }
                $delta = -round($qty, 3);
                $type = StockMovement::TYPE_WASTE;
            }

            if ($delta == 0.0) {
                return;
            }

            $locked->stock_qty = round($current + $delta, 3);
            $locked->save();

            StockMovement::create([
                'ingredient_id' => $locked->id,
                'user_id' => $request->user()->id,
                'type' => $type,
                'quantity' => $delta,
                'note' => $data['note'] ?? null,
            ]);

            $ingredient->setRawAttributes($locked->getAttributes(), true);
        });

        return new IngredientResource($ingredient->refresh());
    }

    public function movements(Ingredient $ingredient): JsonResponse
    {
        $rows = $ingredient->movements()
            ->with('user:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantity' => (float) $m->quantity,
                'total_cost' => $m->total_cost !== null ? (float) $m->total_cost : null,
                'note' => $m->note,
                'user' => $m->user?->name,
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }
}
