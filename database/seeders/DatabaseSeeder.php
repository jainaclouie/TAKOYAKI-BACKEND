<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sample data so the app is usable right away.
 * All prices, costs and quantities are illustrative: edit them in the app.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::updateOrCreate(
            ['email' => 'owner@takoyaki.test'],
            ['name' => 'Owner', 'password' => 'password', 'role' => User::ROLE_OWNER]
        );

        User::updateOrCreate(
            ['email' => 'staff@takoyaki.test'],
            ['name' => 'Cashier', 'password' => 'password', 'role' => User::ROLE_STAFF]
        );

        // key => [name, unit, opening stock, reorder level, cost per unit]
        $catalog = [
            'octopus' => ['Octopus (boiled, diced)', 'g', 5000, 1000, 0.45],
            'flour' => ['Takoyaki flour', 'g', 10000, 2000, 0.10],
            'egg' => ['Eggs', 'pcs', 60, 12, 9.00],
            'dashi' => ['Dashi stock', 'ml', 8000, 1500, 0.05],
            'cabbage' => ['Cabbage', 'g', 3000, 600, 0.06],
            'tenkasu' => ['Tenkasu (tempura scraps)', 'g', 1000, 200, 0.30],
            'ginger' => ['Pickled ginger', 'g', 500, 100, 0.25],
            'sauce' => ['Takoyaki sauce', 'ml', 2000, 400, 0.12],
            'mayo' => ['Japanese mayonnaise', 'ml', 2000, 400, 0.20],
            'bonito' => ['Bonito flakes', 'g', 300, 60, 1.20],
            'aonori' => ['Aonori (seaweed powder)', 'g', 100, 20, 2.00],
            'oil' => ['Cooking oil', 'ml', 3000, 500, 0.12],
            'cheese' => ['Cheese (cubed)', 'g', 1500, 300, 0.35],
            'sausage' => ['Sausage', 'pcs', 100, 20, 8.00],
            'box' => ['Takeout boxes', 'pcs', 200, 50, 3.00],
            'sticks' => ['Toothpicks / skewers', 'pcs', 500, 100, 0.50],
        ];

        $ing = [];
        foreach ($catalog as $key => [$name, $unit, $stock, $reorder, $cost]) {
            $ing[$key] = Ingredient::firstOrCreate(
                ['name' => $name],
                ['unit' => $unit, 'stock_qty' => $stock, 'reorder_level' => $reorder, 'cost_per_unit' => $cost]
            );

            if ($ing[$key]->wasRecentlyCreated) {
                StockMovement::create([
                    'ingredient_id' => $ing[$key]->id,
                    'user_id' => $owner->id,
                    'type' => StockMovement::TYPE_OPENING,
                    'quantity' => $stock,
                    'note' => 'Opening stock',
                ]);
            }
        }

        // Quantity of each ingredient used per ball, and per order (box + stick).
        $perBall = [
            'octopus' => 5, 'flour' => 8, 'egg' => 0.08, 'dashi' => 10, 'cabbage' => 4,
            'tenkasu' => 1.5, 'ginger' => 0.8, 'sauce' => 3, 'mayo' => 2, 'bonito' => 0.3,
            'aonori' => 0.1, 'oil' => 1,
        ];
        $perOrder = ['box' => 1, 'sticks' => 1];

        /** Build a recipe for N balls; $swap replaces/adds per-ball ingredients. */
        $recipeFor = function (int $balls, array $swap = []) use ($perBall, $perOrder, $ing): array {
            $lines = array_merge($perBall, $swap);
            $lines = array_filter($lines, fn ($q) => $q > 0);

            $recipe = [];
            foreach ($lines as $key => $perOne) {
                $recipe[$ing[$key]->id] = ['quantity' => round($perOne * $balls, 3)];
            }
            foreach ($perOrder as $key => $qty) {
                $recipe[$ing[$key]->id] = ['quantity' => $qty];
            }

            return $recipe;
        };

        $products = [
            ['Original Takoyaki - 4 pcs', 'Takoyaki', 50, $recipeFor(4)],
            ['Original Takoyaki - 6 pcs', 'Takoyaki', 70, $recipeFor(6)],
            ['Original Takoyaki - 8 pcs', 'Takoyaki', 90, $recipeFor(8)],
            ['Original Takoyaki - 12 pcs', 'Takoyaki', 130, $recipeFor(12)],
            ['Cheese Takoyaki - 6 pcs', 'Takoyaki', 90, $recipeFor(6, ['cheese' => 4])],
            ['Sausage Takoyaki - 6 pcs', 'Takoyaki', 80, $recipeFor(6, ['octopus' => 0, 'sausage' => 0.5])],
            ['Extra Takoyaki Sauce', 'Add-ons', 10, [$ing['sauce']->id => ['quantity' => 15]]],
            ['Extra Mayo', 'Add-ons', 10, [$ing['mayo']->id => ['quantity' => 10]]],
            ['Extra Bonito Flakes', 'Add-ons', 15, [$ing['bonito']->id => ['quantity' => 2]]],
            ['Bottled Water', 'Drinks', 20, []],
            ['Iced Tea', 'Drinks', 35, []],
        ];

        foreach ($products as [$name, $category, $price, $recipe]) {
            $product = Product::updateOrCreate(
                ['name' => $name],
                ['category' => $category, 'price' => $price, 'is_active' => true]
            );
            $product->ingredients()->sync($recipe);
        }
    }
}
