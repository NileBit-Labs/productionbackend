<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\DeliveryOrder;
use App\Services\DeliveryService;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeliveryOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(array_keys(DeliveryService::TRANSITIONS))], 'search' => ['nullable', 'string', 'max:100']]);
        $query = DeliveryOrder::where('shop_id', $request->attributes->get('shop')->id)->with('sale.customer', 'sale.items')->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['search'])) {
            $query->where('recipient_name', 'like', '%'.$data['search'].'%');
        }

        return response()->json($query->paginate(PerPage::from($request)));
    }

    public function show(Request $request, int $order): JsonResponse
    {
        return response()->json(DeliveryOrder::where('shop_id', $request->attributes->get('shop')->id)->with('sale.customer', 'sale.items', 'sale.refunds')->findOrFail($order));
    }

    public function transition(Request $request, DeliveryService $delivery, int $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(DeliveryService::TRANSITIONS))], 'driver_name' => ['nullable', 'string', 'max:255'], 'driver_phone' => ['nullable', 'string', 'max:100'], 'proof_of_delivery' => ['nullable', 'string', 'max:1000'], 'failure_reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($delivery->transition($request->attributes->get('shop'), $request->user(), $order, $data));
    }

    public function pay(Request $request, DeliveryService $delivery, int $order): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'max:100'], 'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'method' => ['required', Rule::enum(PaymentMethod::class)], 'reference' => ['nullable', 'string', 'max:100']]);

        return response()->json($delivery->pay($request->attributes->get('shop'), $request->user(), $order, $data));
    }
}
