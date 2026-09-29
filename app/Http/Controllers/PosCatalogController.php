<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\PosProductPresenter;
use Illuminate\Http\Request;

/**
 * The selling catalogue: a compact, sell-ready view of a shop's products
 * (price, stock, units), kept small so it can be cached on the device.
 */
class PosCatalogController extends Controller
{
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        $query = Product::where('shop_id', $shop->id)
            ->where('status', 'active')
            ->with(['units', 'category:id,name'])
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->orderBy('name');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(sku, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(barcode, \'\')) like ?', [$like]);
            });
        }

        return $query->get()->map(fn (Product $product) => PosProductPresenter::format($product))->values();
    }
}
