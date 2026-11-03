<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreSaleRequest;
use App\Models\Sale;
use App\Services\CustomerDebt;
use App\Services\PriceChanged;
use App\Services\SaleService;
use App\Support\PerPage;
use App\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function store(StoreSaleRequest $request, SaleService $sales): JsonResponse
    {
        try {
            $sale = $sales->create(
                $request->attributes->get('shop'),
                $request->user(),
                $request->validated(),
            );
        } catch (PriceChanged $e) {
            throw ValidationException::withMessages(['expected_total' => 'Prices changed. Review the current total before confirming again.']);
        }

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

        if ($request->filled('from') || $request->filled('to')) {
            $dates = $request->duplicate([
                'from' => $request->query('from') ?: $request->query('to'),
                'to' => $request->query('to') ?: $request->query('from'),
            ]);
            $range = ReportRange::fromRequest($dates, $shop);
            if ($request->filled('from')) {
                $query->where('created_at', '>=', $range->utcFrom());
            }
            if ($request->filled('to')) {
                $query->where('created_at', '<=', $range->utcTo());
            }
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
        $sale->loadMissing('items', 'payments', 'cashier:id,name', 'customer:id,name,phone', 'refunds.items', 'deliveryOrder');
        $shop = $request->attributes->get('shop');

        return $sale->toArray() + [
            'outstanding' => $sale->customer_id && $sale->status === 'completed' ? (int) (collect(app(CustomerDebt::class)->openSales([$sale->customer_id])[$sale->customer_id] ?? [])->firstWhere('sale_id', $sale->id)['owed'] ?? 0) : 0,
            'shop' => $shop->only('id', 'name', 'phone', 'address') + ['business_name' => $shop->organization?->name],
        ];
    }
}
