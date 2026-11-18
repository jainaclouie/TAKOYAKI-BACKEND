<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Profit definitions used here:
     *   gross profit = sales - recipe cost of what was sold
     *   net profit   = gross profit - operating expenses
     * Ingredient purchases are NOT counted as expenses (that would double count
     * them with the recipe cost); they are reported separately as cash spent.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $range = $request->query('range', 'today');

        [$from, $to] = match ($range) {
            'week' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };

        $orders = Order::where('status', Order::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to]);

        $sales = (float) (clone $orders)->sum('total');
        $orderCount = (clone $orders)->count();
        $cogs = (float) (clone $orders)->sum('total_cost');

        $expenses = (float) Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        $purchases = (float) StockMovement::where('type', StockMovement::TYPE_PURCHASE)
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_cost');

        $itemsQuery = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', Order::STATUS_COMPLETED)
            ->whereBetween('orders.created_at', [$from, $to]);

        $itemsSold = (int) (clone $itemsQuery)->sum('order_items.quantity');

        $topProducts = (clone $itemsQuery)
            ->selectRaw('order_items.product_name as name, SUM(order_items.quantity) as quantity, SUM(order_items.line_total) as revenue')
            ->groupBy('order_items.product_name')
            ->orderByDesc('quantity')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'quantity' => (int) $r->quantity,
                'revenue' => round((float) $r->revenue, 2),
            ]);

        $payments = (clone $orders)
            ->selectRaw('payment_method, SUM(total) as total, COUNT(*) as orders')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->payment_method,
                'total' => round((float) $r->total, 2),
                'orders' => (int) $r->orders,
            ]);

        // Last 7 days, always shown in the chart regardless of the selected range.
        $start = now()->subDays(6)->startOfDay();
        $byDay = Order::where('status', Order::STATUS_COMPLETED)
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'total'])
            ->groupBy(fn ($o) => $o->created_at->toDateString());

        $series = collect(range(6, 0))->map(function (int $daysAgo) use ($byDay) {
            $day = now()->subDays($daysAgo);
            $rows = $byDay->get($day->toDateString(), collect());

            return [
                'date' => $day->toDateString(),
                'label' => $day->format('D'),
                'sales' => round((float) $rows->sum('total'), 2),
                'orders' => $rows->count(),
            ];
        })->values();

        $lowStock = Ingredient::where('is_active', true)
            ->whereColumn('stock_qty', '<=', 'reorder_level')
            ->orderBy('name')
            ->get()
            ->map(fn (Ingredient $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'unit' => $i->unit,
                'stock_qty' => (float) $i->stock_qty,
                'reorder_level' => (float) $i->reorder_level,
            ]);

        $gross = $sales - $cogs;

        return response()->json([
            'range' => $range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sales' => round($sales, 2),
            'orders' => $orderCount,
            'items_sold' => $itemsSold,
            'average_order' => $orderCount > 0 ? round($sales / $orderCount, 2) : 0,
            'cost_of_goods' => round($cogs, 2),
            'gross_profit' => round($gross, 2),
            'expenses' => round($expenses, 2),
            'net_profit' => round($gross - $expenses, 2),
            'ingredient_purchases' => round($purchases, 2),
            'sales_by_day' => $series,
            'top_products' => $topProducts,
            'payment_breakdown' => $payments,
            'low_stock' => $lowStock,
        ]);
    }
}
