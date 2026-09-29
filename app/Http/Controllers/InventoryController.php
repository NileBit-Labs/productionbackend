<?php

namespace App\Http\Controllers;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\InventoryService;
use App\Support\ManagedProductPresenter;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Owner/manager only (see routes). */
class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $stockSql = '(select coalesce(sum(quantity_delta), 0) from stock_movements where stock_movements.product_id = products.id)';

        $base = Product::where('shop_id', $shop->id)->where('status', 'active');

        $all = (clone $base)->withSum('stockMovements as stock', 'quantity_delta')->get();
        $presented = $all->map(fn (Product $p) => ManagedProductPresenter::format($p));

        $summary = [
            'items' => $presented->count(),
            'low_stock' => $presented->where('is_low', true)->count(),
            'out_of_stock' => $presented->where('is_out', true)->count(),
            'stock_value' => (int) $presented->sum('stock_value'),
        ];

        $query = (clone $base)->with(['category:id,name'])
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->orderBy('name');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(coalesce(sku, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(barcode, \'\')) like ?', [$like]));
        }

        match ($request->query('filter')) {
            'low' => $query->whereRaw("low_stock_threshold > 0 and $stockSql > 0 and $stockSql <= low_stock_threshold"),
            'out' => $query->whereRaw("$stockSql <= 0"),
            default => null,
        };

        return response()->json([
            'summary' => $summary,
            'products' => $query->paginate(PerPage::from($request))->through(fn (Product $p) => ManagedProductPresenter::format($p)),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $query = StockMovement::where('stock_movements.shop_id', $shop->id)
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->join('users', 'users.id', '=', 'stock_movements.performed_by')
            ->select('stock_movements.*', 'products.name as product_name', 'products.base_unit', 'users.name as performed_by_name')
            ->orderByDesc('stock_movements.id');

        if ($request->filled('product_id')) {
            $query->where('stock_movements.product_id', $request->integer('product_id'));
        }

        if ($request->filled('type')) {
            $query->where('stock_movements.movement_type', $request->query('type'));
        }

        if ($request->filled('from')) {
            $query->where('stock_movements.created_at', '>=', $request->date('from')->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('stock_movements.created_at', '<=', $request->date('to')->endOfDay());
        }

        $page = $query->paginate(PerPage::from($request));
        $labels = $this->referenceLabels($page->getCollection());

        return response()->json($page->through(fn ($m) => [
            'id' => $m->id,
            'product_id' => $m->product_id,
            'product_name' => $m->product_name,
            'unit' => $m->base_unit,
            'type' => $m->movement_type,
            'quantity_delta' => (float) $m->quantity_delta,
            'unit_cost' => (int) $m->unit_cost,
            'reason' => $m->reason,
            'performed_by' => $m->performed_by_name,
            'reference' => $m->reference_type ? [
                'type' => class_basename($m->reference_type),
                'id' => $m->reference_id,
                'label' => $labels[$m->reference_type][$m->reference_id] ?? null,
            ] : null,
            'created_at' => $m->created_at,
        ]));
    }

    public function adjust(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity_delta' => ['nullable', 'numeric', 'between:-1000000,1000000', 'required_without:counted_quantity'],
            'counted_quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'required_without:quantity_delta'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        if (isset($data['quantity_delta'], $data['counted_quantity'])) {
            return $this->reject('Send either a change or a counted quantity, not both.');
        }

        $result = $inventory->adjust(
            $request->attributes->get('shop'), $request->user(), (int) $data['product_id'],
            isset($data['quantity_delta']) ? (float) $data['quantity_delta'] : null,
            isset($data['counted_quantity']) ? (float) $data['counted_quantity'] : null,
            $data['reason'], $data['idempotency_key'] ?? null,
        );

        return $this->respond($result);
    }

    public function damage(Request $request, InventoryService $inventory): JsonResponse
    {
        return $this->writeOff($request, $inventory, MovementType::Damage);
    }

    public function loss(Request $request, InventoryService $inventory): JsonResponse
    {
        return $this->writeOff($request, $inventory, MovementType::Loss);
    }

    public function openingStock(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'unit_cost' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->respond($inventory->openingStock(
            $request->attributes->get('shop'), $request->user(), (int) $data['product_id'],
            (float) $data['quantity'], $data['unit_cost'] ?? null, $data['idempotency_key'] ?? null,
        ));
    }

    private function writeOff(Request $request, InventoryService $inventory, MovementType $type): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->respond($inventory->writeOff(
            $request->attributes->get('shop'), $request->user(), (int) $data['product_id'],
            $type, (float) $data['quantity'], $data['reason'], $data['idempotency_key'] ?? null,
        ));
    }

    /** @param  array{movement: StockMovement, stock: float, replayed: bool}  $result */
    private function respond(array $result): JsonResponse
    {
        return response()->json([
            'movement' => [
                'id' => $result['movement']->id,
                'product_id' => $result['movement']->product_id,
                'type' => $result['movement']->movement_type,
                'quantity_delta' => (float) $result['movement']->quantity_delta,
                'reason' => $result['movement']->reason,
            ],
            'stock' => $result['stock'],
        ], $result['replayed'] ? 200 : 201);
    }

    private function reject(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['quantity' => [$message]]], 422);
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return array<string, array<int, string>>
     */
    private function referenceLabels($movements): array
    {
        $labels = [];

        $ids = fn (string $class) => $movements->where('reference_type', $class)->pluck('reference_id')->unique()->all();

        foreach (Sale::whereIn('id', $ids(Sale::class))->get(['id', 'sale_number']) as $sale) {
            $labels[Sale::class][$sale->id] = $sale->sale_number;
        }

        foreach (Purchase::whereIn('id', $ids(Purchase::class))->get(['id', 'purchase_number']) as $purchase) {
            $labels[Purchase::class][$purchase->id] = $purchase->purchase_number;
        }

        foreach ($ids(Refund::class) as $id) {
            $labels[Refund::class][$id] = "R-{$id}";
        }

        return $labels;
    }
}
