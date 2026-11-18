<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    /**
     * Owner: all orders, filterable by date range and status.
     * Staff: only their own orders from today.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in([Order::STATUS_COMPLETED, Order::STATUS_VOIDED])],
        ]);

        $query = Order::with(['items', 'user:id,name'])->latest('id');

        if ($user->isOwner()) {
            if ($request->filled('from')) {
                $query->where('created_at', '>=', Carbon::parse($request->query('from'))->startOfDay());
            }
            if ($request->filled('to')) {
                $query->where('created_at', '<=', Carbon::parse($request->query('to'))->endOfDay());
            }
            if ($request->filled('status')) {
                $query->where('status', $request->query('status'));
            }
        } else {
            $query->where('user_id', $user->id)
                ->where('created_at', '>=', now()->startOfDay());
        }

        return OrderResource::collection($query->paginate(20)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:500'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(config('takoyaki.payment_methods'))],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'customer_note' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->orders->place($request->user(), $data);

        return (new OrderResource($order))->response()->setStatusCode(201);
    }

    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless(
            $request->user()->isOwner() || $order->user_id === $request->user()->id,
            403,
            'You can only view your own orders.'
        );

        return new OrderResource($order->load(['items', 'user:id,name']));
    }

    public function void(Request $request, Order $order): OrderResource
    {
        return new OrderResource($this->orders->void($order, $request->user()));
    }
}
