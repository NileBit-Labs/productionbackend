<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Services\PurchaseReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseReturnController extends Controller
{
    public function store(Request $request, PurchaseReturnService $returns, int $purchase): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:100'], 'reason' => ['required', 'string', 'min:3', 'max:500'], 'refund_received' => ['nullable', 'boolean'], 'method' => ['nullable', Rule::enum(PaymentMethod::class)], 'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.purchase_item_id' => ['required', 'integer', 'distinct'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000']]);
        $return = $returns->create($request->attributes->get('shop'), $request->user(), $purchase, $data);

        return response()->json($return, $return->wasRecentlyCreated ? 201 : 200);
    }
}
