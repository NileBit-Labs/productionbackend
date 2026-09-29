<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Services\SupplierDebt;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Owner/manager only (see routes): cost prices live here. */
class PurchaseController extends Controller
{
    public function index(Request $request, SupplierDebt $debt): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $query = Purchase::where('shop_id', $shop->id)
            ->with('supplier:id,name')
            ->withCount('items')
            ->orderByDesc('purchase_date')
            ->orderByDesc('id');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }

        if (in_array($request->query('status'), ['received', 'cancelled'], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('from')) {
            $query->where('purchase_date', '>=', $request->date('from')->toDateString());
        }

        if ($request->filled('to')) {
            $query->where('purchase_date', '<=', $request->date('to')->toDateString());
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(purchase_number) like ?', [$like])
                ->orWhereRaw('lower(coalesce(reference, \'\')) like ?', [$like]));
        }

        $page = $query->paginate(PerPage::from($request));
        $open = $debt->openPurchases($page->getCollection()->pluck('supplier_id')->unique()->values()->all());

        return response()->json($page->through(fn (Purchase $p) => $this->format($p, $this->owed($p, $open))));
    }

    public function store(Request $request, PurchaseService $purchases, SupplierDebt $debt): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:'.$request->attributes->get('shop')->today()],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'integer', 'min:0'],
            'items.*.unit_name' => ['nullable', 'string', 'max:50'],
            'amount_paid' => ['nullable', 'integer', 'min:0'],
            'payment_method' => [Rule::requiredIf(fn () => (int) $request->input('amount_paid') > 0), 'nullable', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $purchases->receive($request->attributes->get('shop'), $request->user(), $data);

        return response()->json($this->detail($request, $debt, $result['purchase']->id), $result['replayed'] ? 200 : 201);
    }

    public function show(Request $request, SupplierDebt $debt, int $purchase): JsonResponse
    {
        return response()->json($this->detail($request, $debt, $purchase));
    }

    public function cancel(Request $request, PurchaseService $purchases, SupplierDebt $debt, int $purchase): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        $purchases->cancel($request->attributes->get('shop'), $request->user(), $purchase, $data['reason']);

        return response()->json($this->detail($request, $debt, $purchase));
    }

    /** @return array<string, mixed> */
    private function detail(Request $request, SupplierDebt $debt, int $id): array
    {
        $purchase = Purchase::where('shop_id', $request->attributes->get('shop')->id)
            ->with(['supplier:id,name', 'receiver:id,name', 'items.product:id,name,base_unit'])
            ->withCount('items')
            ->findOrFail($id);

        $payments = Payment::where('purchase_id', $purchase->id)->orderBy('id')->get(['id', 'amount', 'method', 'reference', 'created_at']);

        return $this->format($purchase, $debt->owedOn($purchase)) + [
            'received_by' => $purchase->receiver->name,
            'cancel_reason' => $purchase->cancel_reason,
            'cancelled_at' => $purchase->cancelled_at?->toIso8601String(),
            'items' => $purchase->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product->name,
                'base_unit' => $i->product->base_unit,
                'quantity' => $i->quantity,
                'unit_name' => $i->unit_name,
                'conversion' => $i->conversion,
                'unit_cost' => $i->unit_cost,
                'line_total' => $i->line_total,
                'base_quantity' => $i->base_quantity,
                'base_unit_cost' => $i->base_unit_cost,
            ])->values(),
            'payments' => $payments->map(fn ($p) => [
                'id' => $p->id,
                'amount' => (int) $p->amount,
                'method' => $p->method->value,
                'reference' => $p->reference,
                'created_at' => $p->created_at,
            ])->values(),
        ];
    }

    /** @param  array<int, array<int, array<string, mixed>>>  $open */
    private function owed(Purchase $purchase, array $open): int
    {
        foreach ($open[$purchase->supplier_id] ?? [] as $row) {
            if ($row['purchase_id'] === $purchase->id) {
                return $row['owed'];
            }
        }

        return 0;
    }

    /** @return array<string, mixed> */
    private function format(Purchase $purchase, int $owed): array
    {
        $payment = match (true) {
            $purchase->status === 'cancelled' => 'cancelled',
            $owed === 0 => 'paid',
            $owed >= $purchase->total => 'unpaid',
            default => 'partial',
        };

        return [
            'id' => $purchase->id,
            'purchase_number' => $purchase->purchase_number,
            'status' => $purchase->status,
            'payment_status' => $payment,
            'supplier' => ['id' => $purchase->supplier->id, 'name' => $purchase->supplier->name],
            'purchase_date' => $purchase->purchase_date->toDateString(),
            'reference' => $purchase->reference,
            'note' => $purchase->note,
            'total' => $purchase->total,
            'amount_paid' => $purchase->amount_paid,
            'owed' => $owed,
            'items_count' => $purchase->items_count,
        ];
    }
}
