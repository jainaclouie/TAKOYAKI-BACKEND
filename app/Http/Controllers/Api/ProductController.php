<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with('ingredients')
            ->when(! $request->user()->isOwner(), fn ($q) => $q->where('is_active', true))
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return ProductResource::collection($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $product = DB::transaction(function () use ($data) {
            $product = Product::create([
                'name' => $data['name'],
                'category' => $data['category'],
                'price' => $data['price'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $product->ingredients()->sync($this->recipe($data));

            return $product;
        });

        return (new ProductResource($product->load('ingredients')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Product $product): ProductResource
    {
        $data = $request->validate($this->rules());

        DB::transaction(function () use ($data, $product) {
            $product->update([
                'name' => $data['name'],
                'category' => $data['category'],
                'price' => $data['price'],
                'is_active' => $data['is_active'] ?? $product->is_active,
            ]);

            $product->ingredients()->sync($this->recipe($data));
        });

        return new ProductResource($product->load('ingredients'));
    }

    public function destroy(Product $product): JsonResponse
    {
        // Past orders keep their own name/price snapshot, so deleting is safe.
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'recipe' => ['sometimes', 'array'],
            'recipe.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'recipe.*.quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /** @return array<int, array{quantity: float|int|string}> */
    private function recipe(array $data): array
    {
        return collect($data['recipe'] ?? [])
            ->mapWithKeys(fn ($line) => [
                (int) $line['ingredient_id'] => ['quantity' => $line['quantity']],
            ])
            ->all();
    }
}
