<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /** Defaults to the current month. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->toDateString()
            : now()->startOfMonth()->toDateString();
        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->toDateString()
            : now()->toDateString();

        $expenses = Expense::with('user:id,name')
            ->whereBetween('expense_date', [$from, $to])
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $expenses->map(fn (Expense $e) => $this->format($e))->values(),
            'total' => round((float) $expenses->sum('amount'), 2),
            'from' => $from,
            'to' => $to,
            'categories' => config('takoyaki.expense_categories'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $expense = Expense::create($this->validated($request) + [
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->format($expense->load('user:id,name'))], 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $expense->update($this->validated($request));

        return response()->json(['data' => $this->format($expense->load('user:id,name'))]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(['message' => 'Expense deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(config('takoyaki.expense_categories'))],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'expense_date' => ['required', 'date'],
        ]);
    }

    private function format(Expense $e): array
    {
        return [
            'id' => $e->id,
            'category' => $e->category,
            'description' => $e->description,
            'amount' => (float) $e->amount,
            'expense_date' => $e->expense_date->format('Y-m-d'),
            'recorded_by' => $e->user?->name,
        ];
    }
}
