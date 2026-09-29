<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreSaleRequest;
use App\Models\Sale;
use App\Services\SaleService;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function store(StoreSaleRequest $request, SaleService $sales): JsonResponse
    {
        $sale = $sales->create(
            $request->attributes->get('shop'),
            $request->user(),
            $request->validated(),
        );

        return response()->json($this->receipt($request, $sale), $sale->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        $query = Sale::where('shop_id', $shop->id)
            ->with('cashier:id,name', 'payments', 'customer:id,name')
            ->withCount('items')
            ->latest('id');

        if ($this->isCashier($request)) {
            $query->where('cashier_id', $request->user()->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from')->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')->endOfDay());
        }

        return $query->paginate(PerPage::from($request, 25));
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        return response()->json($this->receipt($request, $this->find($request, $sale)));
    }

    public function void(Request $request, SaleService $sales, int $sale): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $voided = $sales->void(
            $request->attributes->get('shop'),
            $request->user(),
            $this->find($request, $sale),
            $data['reason'],
        );

        return response()->json($this->receipt($request, $voided));
    }

    private function find(Request $request, int $id): Sale
    {
        $query = Sale::where('shop_id', $request->attributes->get('shop')->id);

        if ($this->isCashier($request)) {
            $query->where('cashier_id', $request->user()->id);
        }

        return $query->with('items', 'payments', 'cashier:id,name', 'customer:id,name,phone', 'refunds.items')->findOrFail($id);
    }

    private function isCashier(Request $request): bool
    {
        return $request->attributes->get('shopRole') === Role::Cashier;
    }

    /** @return array<string, mixed> */
    private function receipt(Request $request, Sale $sale): array
    {
        $sale->loadMissing('items', 'payments', 'cashier:id,name', 'customer:id,name,phone', 'refunds.items');
        $shop = $request->attributes->get('shop');

        return $sale->toArray() + [
            'shop' => $shop->only('id', 'name', 'phone', 'address'),
        ];
    }
}
