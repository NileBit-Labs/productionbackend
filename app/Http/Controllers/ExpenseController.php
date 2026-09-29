<?php

namespace App\Http\Controllers;

use App\Enums\BatchStatus;
use App\Enums\ExpenseType;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ProductionBatch;
use App\Services\AuditLogger;
use App\Support\ShopDefaults;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    private const AUDITED = ['category', 'type', 'production_batch_id', 'amount', 'description', 'expense_date'];

    /** Owners/managers only (see routes). Defaults to the current month. */
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        ShopDefaults::expenseCategories($shop);

        $today = Carbon::parse($shop->today());
        $from = $request->filled('from') ? $request->date('from') : $today->copy()->startOfMonth();
        $to = $request->filled('to') ? $request->date('to') : $today;

        $query = Expense::where('shop_id', $shop->id)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('type') && ExpenseType::tryFrom($request->query('type'))) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('production_batch_id')) {
            $query->where('production_batch_id', $request->integer('production_batch_id'));
        }

        $expenses = (clone $query)->with(['recorder:id,name', 'batch:id,batch_number'])->orderByDesc('expense_date')->orderByDesc('id')->get();
        $direct = $expenses->filter(fn (Expense $e) => $e->type->isDirect());

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => $expenses->sum('amount'),
            // Direct costs are part of batch costs (and so of cost of goods); only operating ones reduce profit directly.
            'operating_total' => $expenses->sum('amount') - $direct->sum('amount'),
            'direct_total' => $direct->sum('amount'),
            'by_category' => $expenses->groupBy('category')
                ->map(fn ($group, $category) => ['category' => $category, 'total' => $group->sum('amount')])
                ->sortByDesc('total')->values(),
            'by_type' => collect(ExpenseType::cases())
                ->map(fn (ExpenseType $type) => ['type' => $type->value, 'total' => $expenses->where('type', $type)->sum('amount')])->values(),
            'categories' => ExpenseCategory::where('shop_id', $shop->id)->where('is_active', true)->orderBy('name')->pluck('name'),
            'data' => $expenses,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $data = $this->validated($request);
        $this->assertBatchLink($request, $data['type'] ?? ExpenseType::Operating->value, $data['production_batch_id'] ?? null);

        $expense = Expense::create($data + [
            'shop_id' => $shop->id,
            'recorded_by' => $request->user()->id,
        ]);

        return response()->json($expense->fresh()->load(['recorder:id,name', 'batch:id,batch_number']), 201);
    }

    /** Edits are allowed but never silent: the before/after is audit-logged. */
    public function update(Request $request, AuditLogger $audit, int $expense): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Expense::where('shop_id', $shop->id)->findOrFail($expense);
        $data = $this->validated($request, partial: true);

        // A completed batch has already turned this into the cost of its goods.
        if ($model->production_batch_id && $model->type->isDirect()
            && ProductionBatch::whereKey($model->production_batch_id)->where('status', BatchStatus::Completed)->exists()
            && array_diff_key($data, array_flip(['description'])) !== []) {
            throw ValidationException::withMessages(['expense' => 'This expense is part of a completed batch\'s cost, so only its description can change.']);
        }

        $type = $data['type'] ?? $model->type->value;
        $batchId = array_key_exists('production_batch_id', $data) ? $data['production_batch_id'] : $model->production_batch_id;

        if (array_key_exists('type', $data) || array_key_exists('production_batch_id', $data)) {
            $this->assertBatchLink($request, $type, $batchId);
        }

        $before = $model->only(self::AUDITED);
        $model->update($data);

        $audit->record($request->user(), $shop, 'expense.update', $model, $before, $model->only(self::AUDITED));

        return response()->json($model->load(['recorder:id,name', 'batch:id,batch_number']));
    }

    /**
     * A direct expense belongs to exactly one batch that is still being planned; general overhead
     * is never attached to a batch unless someone explicitly allocates it as a direct cost.
     */
    private function assertBatchLink(Request $request, string $type, ?int $batchId): void
    {
        $direct = ExpenseType::from($type)->isDirect();

        if (! $direct && $batchId) {
            throw ValidationException::withMessages(['production_batch_id' => 'Only a direct expense can be allocated to a batch. Set its type to direct labour or direct production.']);
        }

        if (! $direct) {
            return;
        }

        if (! $batchId) {
            throw ValidationException::withMessages(['production_batch_id' => 'Choose the batch this direct expense belongs to.']);
        }

        $batch = ProductionBatch::where('shop_id', $request->attributes->get('shop')->id)->find($batchId);

        if (! $batch) {
            throw ValidationException::withMessages(['production_batch_id' => 'Choose a batch from this shop.']);
        }

        if ($batch->status !== BatchStatus::Draft) {
            throw ValidationException::withMessages(['production_batch_id' => "{$batch->batch_number} is {$batch->status->value}; its cost is already final. Record this as an operating expense instead."]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'category' => [$required, 'string', 'max:100'],
            'type' => ['sometimes', Rule::enum(ExpenseType::class)],
            'production_batch_id' => ['nullable', 'integer'],
            'amount' => [$required, 'integer', 'min:1', 'max:1000000000000'],
            'description' => ['nullable', 'string', 'max:500'],
            'expense_date' => [$required, 'date', 'before_or_equal:'.$request->attributes->get('shop')->today()],
        ]);
    }
}
