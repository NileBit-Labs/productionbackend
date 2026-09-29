<?php

namespace App\Http\Controllers;

use App\Enums\WastageStage;
use App\Models\WastageRecord;
use App\Services\WastageService;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Owner/manager only (see routes). */
class WastageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $query = WastageRecord::where('wastage_records.shop_id', $shop->id)
            ->countable()
            ->with(['product:id,name,base_unit,kind', 'batch:id,batch_number', 'recorder:id,name'])
            ->orderByDesc('wastage_date')->orderByDesc('id');

        if ($request->filled('stage') && WastageStage::tryFrom($request->query('stage'))) {
            $query->where('stage', $request->query('stage'));
        }

        foreach (['product_id', 'production_batch_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }

        if ($request->filled('from')) {
            $query->where('wastage_date', '>=', $request->date('from')->toDateString());
        }

        if ($request->filled('to')) {
            $query->where('wastage_date', '<=', $request->date('to')->toDateString());
        }

        $total = (int) (clone $query)->sum('total_cost');

        return response()->json([
            'total_cost' => $total,
            'records' => $query->paginate(PerPage::from($request))->through(fn (WastageRecord $w) => self::format($w)),
        ]);
    }

    public function store(Request $request, WastageService $wastage): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'stage' => ['nullable', Rule::enum(WastageStage::class)],
            'wastage_date' => ['nullable', 'date', 'before_or_equal:'.$request->attributes->get('shop')->today()],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $wastage->record($request->attributes->get('shop'), $request->user(), $data);
        $record = $result['record']->load(['product:id,name,base_unit,kind', 'batch:id,batch_number', 'recorder:id,name']);

        return response()->json(self::format($record), $result['replayed'] ? 200 : 201);
    }

    /** @return array<string, mixed> */
    public static function format(WastageRecord $record): array
    {
        return [
            'id' => $record->id,
            'product_id' => $record->product_id,
            'product_name' => $record->product?->name,
            'unit' => $record->product?->base_unit,
            'stage' => $record->stage->value,
            'quantity' => $record->quantity,
            'unit_cost' => $record->unit_cost,
            'total_cost' => $record->total_cost,
            'reason' => $record->reason,
            'wastage_date' => $record->wastage_date->toDateString(),
            'batch' => $record->batch ? ['id' => $record->batch->id, 'batch_number' => $record->batch->batch_number] : null,
            'recorded_by' => $record->recorder?->name,
            'created_at' => $record->created_at,
        ];
    }
}
