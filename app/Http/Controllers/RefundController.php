<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\CustomerLedger;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RefundController extends Controller
{
    public function refundable(Request $request, RefundService $refunds, CustomerLedger $ledger, int $sale): JsonResponse
    {
        $model = $this->find($request, $sale);

        return response()->json([
            'status' => $model->status,
            'items' => $refunds->refundable($model),
            'customer' => $model->customer_id ? [
                'name' => $model->customer->name,
                'balance' => $ledger->balance(Customer::find($model->customer_id)),
                'owed_on_this_sale' => $model->amount_due,
            ] : null,
        ]);
    }

    public function store(Request $request, RefundService $refunds, int $sale): JsonResponse
    {
        $dryRun = $request->boolean('dry_run');

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.sale_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.restock' => ['nullable', 'boolean'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'reason' => [$dryRun ? 'nullable' : 'required', 'string', 'min:3', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $model = $this->find($request, $sale);

        if ($dryRun) {
            return response()->json($refunds->plan($model, $data));
        }

        $refund = $refunds->refund($request->attributes->get('shop'), $request->user(), $model, $data);

        return response()->json($refund, $refund->wasRecentlyCreated ? 201 : 200);
    }

    private function find(Request $request, int $id): Sale
    {
        return Sale::where('shop_id', $request->attributes->get('shop')->id)
            ->with('items', 'customer:id,name,phone')
            ->findOrFail($id);
    }
}
