<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductService;
use App\Support\ManagedProductPresenter;
use App\Support\PerPage;
use App\Support\ProductRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Owner/manager only (see routes): this is where cost prices live. */
class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $status = $request->query('status', 'active');
        $stockSql = '(select coalesce(sum(quantity_delta), 0) from stock_movements where stock_movements.product_id = products.id)';

        $query = Product::where('shop_id', $shop->id)
            ->with(['category:id,name', 'units'])
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->orderBy('name');

        if ($status !== 'all') {
            $query->where('status', $status === 'archived' ? 'archived' : 'active');
        }

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

        if ($request->boolean('low_stock')) {
            $query->whereRaw("low_stock_threshold > 0 and $stockSql > 0 and $stockSql <= low_stock_threshold");
        }

        if ($request->boolean('out_of_stock')) {
            $query->whereRaw("$stockSql <= 0");
        }

        return response()->json($query->paginate(PerPage::from($request))->through(fn (Product $p) => ManagedProductPresenter::format($p)));
    }

    public function store(Request $request, ProductService $products): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        $data = $request->validate(ProductRules::rules($shop->id) + [
            'opening_stock' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $product = $products->create($shop, $request->user(), $data, (float) ($data['opening_stock'] ?? 0));

        return response()->json($this->fresh($request, $product->id), 201);
    }

    public function show(Request $request, int $product): JsonResponse
    {
        return response()->json($this->fresh($request, $product));
    }

    public function update(Request $request, ProductService $products, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Product::where('shop_id', $shop->id)->findOrFail($product);

        $data = $request->validate(ProductRules::rules($shop->id, $model->id, partial: true));
        $products->update($shop, $request->user(), $model, $data);

        return response()->json($this->fresh($request, $model->id));
    }

    public function archive(Request $request, ProductService $products, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $products->setStatus($shop, $request->user(), Product::where('shop_id', $shop->id)->findOrFail($product), 'archived');

        return response()->json($this->fresh($request, $product));
    }

    public function restore(Request $request, ProductService $products, int $product): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $products->setStatus($shop, $request->user(), Product::where('shop_id', $shop->id)->findOrFail($product), 'active');

        return response()->json($this->fresh($request, $product));
    }

    /** @return array<string, mixed> */
    private function fresh(Request $request, int $id): array
    {
        $product = Product::where('shop_id', $request->attributes->get('shop')->id)
            ->with(['category:id,name', 'units'])
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->findOrFail($id);

        return ManagedProductPresenter::format($product);
    }
}
