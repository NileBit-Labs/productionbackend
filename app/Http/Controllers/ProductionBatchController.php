<?php

namespace App\Http\Controllers;

use App\Enums\BatchStatus;
use App\Enums\ExpenseType;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchOutput;
use App\Services\ProductionService;
use App\Services\ProductionLotService;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Owner/manager only (see routes): batches carry cost prices. */
class ProductionBatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $query = ProductionBatch::where('shop_id', $shop->id)
            ->with(['recipe:id,name', 'responsible:id,name', 'outputs.product:id,name,size_label,base_unit'])
            ->orderByDesc('production_date')->orderByDesc('id');

        if ($request->filled('status') && BatchStatus::tryFrom($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('recipe_id')) {
            $query->where('recipe_id', $request->integer('recipe_id'));
        }

        // Which batches made this product: traceability from a finished good back to its batches.
        if ($request->filled('product_id')) {
            $query->whereHas('outputs', fn ($q) => $q->where('product_id', $request->integer('product_id')));
        }

        if ($request->filled('from')) {
            $query->where('production_date', '>=', $request->date('from')->toDateString());
        }

        if ($request->filled('to')) {
            $query->where('production_date', '<=', $request->date('to')->toDateString());
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(batch_number) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]));
        }

        return response()->json($query->paginate(PerPage::from($request))->through(fn (ProductionBatch $b) => $this->summary($b)));
    }

    public function store(Request $request, ProductionService $production): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'recipe_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'production_date' => ['nullable', 'date', 'before_or_equal:'.$shop->today()],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:production_date'],
            'planned_yield' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'yield_unit' => ['nullable', 'string', 'max:50'],
            'responsible_user_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ] + $this->inputRules('planned_quantity') + $this->outputRules());

        $result = $production->plan($shop, $request->user(), $data);

        return response()->json($this->detail($request, $result['batch']->id), $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, int $batch): JsonResponse
    {
        return response()->json($this->detail($request, $batch));
    }

    public function update(Request $request, ProductionService $production, int $batch): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'production_date' => ['sometimes', 'date', 'before_or_equal:'.$shop->today()],
            'expiry_date' => ['nullable', 'date'],
            'planned_yield' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'responsible_user_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + $this->inputRules('planned_quantity') + $this->outputRules());

        $production->updateDraft($shop, $request->user(), $batch, $data);

        return response()->json($this->detail($request, $batch));
    }

    public function complete(Request $request, ProductionService $production, int $batch): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $data = $request->validate([
            'production_date' => ['sometimes', 'date', 'before_or_equal:'.$shop->today()],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'wastage' => ['nullable', 'array', 'max:100'],
            'wastage.*.product_id' => ['required', 'integer'],
            'wastage.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'wastage.*.reason' => ['required', 'string', 'min:3', 'max:255'],
            'direct_expenses' => ['nullable', 'array', 'max:50'],
            'direct_expenses.*.type' => ['required', Rule::in([ExpenseType::DirectLabour->value, ExpenseType::DirectProduction->value])],
            'direct_expenses.*.category' => ['required', 'string', 'max:100'],
            'direct_expenses.*.amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'direct_expenses.*.description' => ['nullable', 'string', 'max:500'],
        ] + $this->inputRules('actual_quantity', min: 'min:0') + $this->outputRules());

        $production->complete($shop, $request->user(), $batch, $data);

        return response()->json($this->detail($request, $batch));
    }

    public function cancel(Request $request, ProductionService $production, int $batch): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $production->cancel($request->attributes->get('shop'), $request->user(), $batch, $data['reason']);

        return response()->json($this->detail($request, $batch));
    }

    /** @return array<string, mixed> */
    private function inputRules(string $quantityField, string $min = 'gt:0'): array
    {
        return [
            'inputs' => ['sometimes', 'array', 'max:100'],
            'inputs.*.product_id' => ['required', 'integer'],
            "inputs.*.$quantityField" => ['required', 'numeric', $min, 'max:1000000'],
        ];
    }

    /** @return array<string, mixed> */
    private function outputRules(): array
    {
        return [
            'outputs' => ['sometimes', 'array', 'max:50'],
            'outputs.*.product_id' => ['required', 'integer'],
            'outputs.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'outputs.*.output_equivalent' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'outputs.*.expiry_date' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    private function summary(ProductionBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'name' => $batch->name,
            'status' => $batch->status->value,
            'recipe' => $batch->recipe ? ['id' => $batch->recipe->id, 'name' => $batch->recipe->name] : null,
            'production_date' => $batch->production_date->toDateString(),
            'expiry_date' => $batch->expiry_date?->toDateString(),
            'planned_yield' => $batch->planned_yield,
            'yield_unit' => $batch->yield_unit,
            'output_quantity' => $batch->output_quantity,
            'yield_percent' => $this->yieldPercent($batch),
            'total_cost' => $batch->total_cost,
            'cost_per_yield_unit' => $batch->output_quantity > 0 ? (int) round($batch->total_cost / $batch->output_quantity) : null,
            'responsible' => $batch->responsible?->name,
            'outputs' => $batch->outputs->map(fn (ProductionBatchOutput $o) => [
                'product_id' => $o->product_id,
                'product_name' => $o->product->name,
                'size_label' => $o->product->size_label,
                'quantity' => $o->quantity,
                'unit_cost' => $o->unit_cost,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Request $request, int $id): array
    {
        $batch = ProductionBatch::where('shop_id', $request->attributes->get('shop')->id)
            ->with([
                'recipe:id,name,yield_quantity,yield_unit', 'responsible:id,name', 'creator:id,name', 'completer:id,name',
                'inputs.product:id,name,kind,base_unit,current_cost',
                'outputs.product:id,name,size_label,base_unit,output_equivalent,selling_price', 'outputs.lot.movements',
                'wastage.product:id,name,base_unit,kind', 'wastage.recorder:id,name',
                'expenses.recorder:id,name',
            ])
            ->findOrFail($id);

        $draft = $batch->status === BatchStatus::Draft;

        // array_merge, not +: the detailed inputs and outputs replace the summary's short ones.
        return array_merge($this->summary($batch), [
            'notes' => $batch->notes,
            'created_by' => $batch->creator?->name,
            'completed_by' => $batch->completer?->name,
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'cancelled_at' => $batch->cancelled_at?->toIso8601String(),
            'cancel_reason' => $batch->cancel_reason,
            'costs' => [
                'materials' => $batch->material_cost,
                'packaging' => $batch->packaging_cost,
                'direct_labour' => $batch->labour_cost,
                'direct_expenses' => $batch->direct_expense_cost,
                'wastage' => $batch->wastage_cost,
                'total' => $batch->total_cost,
            ],
            'inputs' => $batch->inputs->map(function ($i) use ($draft) {
                // A draft has no frozen cost yet, so show what it would cost at today's prices.
                $unitCost = $draft ? $i->product->current_cost : $i->unit_cost;
                $quantity = $i->actual_quantity ?? $i->planned_quantity ?? 0;

                return [
                    'product_id' => $i->product_id,
                    'product_name' => $i->product->name,
                    'kind' => $i->product->kindOrDefault()->value,
                    'unit' => $i->product->base_unit,
                    'planned_quantity' => $i->planned_quantity,
                    'actual_quantity' => $i->actual_quantity,
                    'variance' => $i->actual_quantity !== null && $i->planned_quantity !== null ? round($i->actual_quantity - $i->planned_quantity, 3) : null,
                    'unit_cost' => $unitCost,
                    'line_cost' => $draft ? (int) round($quantity * $unitCost) : $i->line_cost,
                ];
            })->values(),
            'outputs' => $batch->outputs->map(fn (ProductionBatchOutput $o) => [
                'product_id' => $o->product_id,
                'product_name' => $o->product->name,
                'size_label' => $o->product->size_label,
                'unit' => $o->product->base_unit,
                'quantity' => $o->quantity,
                'output_equivalent' => $o->output_equivalent,
                'allocated_cost' => $o->allocated_cost,
                'unit_cost' => $o->unit_cost,
                'selling_price' => $o->product->selling_price,
                'unit_margin' => $draft ? null : $o->product->selling_price - $o->unit_cost,
                'expiry_date' => $o->expiry_date?->toDateString(),
                'lot_id' => $o->lot?->id,
                'remaining_quantity' => $o->lot ? round($o->lot->produced_quantity + $o->lot->movements->sum('quantity_delta'), 3) : null,
            ])->values(),
            'wastage' => $batch->wastage->map(fn ($w) => WastageController::format($w))->values(),
            'expenses' => $batch->expenses->map(fn ($e) => [
                'id' => $e->id,
                'category' => $e->category,
                'type' => $e->type->value,
                'amount' => $e->amount,
                'description' => $e->description,
                'expense_date' => $e->expense_date->toDateString(),
                'recorded_by' => $e->recorder?->name,
            ])->values(),
        ]);
    }

    private function yieldPercent(ProductionBatch $batch): ?float
    {
        if ($batch->status !== BatchStatus::Completed || ! $batch->planned_yield) {
            return null;
        }

        return round($batch->output_quantity / $batch->planned_yield * 100, 1);
    }
}
