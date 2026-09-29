<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    /** Owners/managers only (see routes). Defaults to the current month. */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $today = Carbon::parse($shop->today());
        $from = $request->filled('from') ? $request->date('from') : $today->copy()->startOfMonth();
        $to = $request->filled('to') ? $request->date('to') : $today;

        $query = Expense::where('shop_id', $shop->id)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        $expenses = (clone $query)->with('recorder:id,name')->orderByDesc('expense_date')->orderByDesc('id')->get();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => $expenses->sum('amount'),
            'by_category' => $expenses->groupBy('category')
                ->map(fn ($group, $category) => ['category' => $category, 'total' => $group->sum('amount')])
                ->sortByDesc('total')->values(),
            'categories' => Expense::CATEGORIES,
            'data' => $expenses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $expense = Expense::create($this->validated($request) + [
            'shop_id' => $shop->id,
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json($expense->load('recorder:id,name'), 201);
    }

    /** Edits are allowed but never silent: the before/after is audit-logged. */
    public function update(Request $request, AuditLogger $audit, int $expense): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Expense::where('shop_id', $shop->id)->findOrFail($expense);

        $before = $model->only('category', 'amount', 'description', 'expense_date');
        $model->update($this->validated($request, partial: true));

        $audit->record($request->user(), $shop, 'expense.update', $model, $before, $model->only('category', 'amount', 'description', 'expense_date'));

        return response()->json($model->load('recorder:id,name'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'category' => [$required, 'string', 'max:100'],
            'amount' => [$required, 'integer', 'min:1', 'max:1000000000000'],
            'description' => ['nullable', 'string', 'max:500'],
            'expense_date' => [$required, 'date', 'before_or_equal:'.$request->attributes->get('shop')->today()],
        ]);
    }
}
