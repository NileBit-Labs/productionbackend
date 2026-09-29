<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\SyncService;
use App\Support\PosProductPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    public function push(Request $request, SyncService $sync): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:100'],
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.local_event_id' => ['required', 'string', 'max:100'],
            'events.*.entity_type' => ['required', 'in:sale'],
            'events.*.operation' => ['required', 'in:create'],
            'events.*.payload' => ['required', 'array'],
            'events.*.client_created_at' => ['nullable', 'date'],
            'events.*.cashier_id' => ['nullable', 'integer'],
            'events.*.accept_agreed_prices' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'results' => $sync->push(
                $request->attributes->get('shop'),
                $request->user(),
                $request->attributes->get('shopRole'),
                $data['device_id'],
                $data['events'],
            ),
        ]);
    }

    /**
     * Catalogue changes since a cursor: products (with live stock) that were
     * edited, or whose stock moved, after it. Without a cursor it returns the
     * whole active catalogue. Archived products are included in a delta, with
     * their status, so the device can drop them.
     */
    public function pull(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $cursor = $request->filled('cursor') ? Carbon::parse($request->query('cursor')) : null;

        // Taken before the query, and backed off a little, so a change made
        // while this request runs is picked up by the next pull, not lost.
        $nextCursor = now()->subSeconds(2)->toIso8601String();

        $query = Product::where('shop_id', $shop->id)
            ->with(['units', 'category:id,name'])
            ->withSum('stockMovements as stock', 'quantity_delta')
            ->orderBy('id');

        if ($cursor) {
            $query->where(function ($q) use ($cursor) {
                $q->where('updated_at', '>', $cursor)
                    ->orWhereHas('stockMovements', fn ($m) => $m->where('created_at', '>', $cursor))
                    ->orWhereHas('units', fn ($u) => $u->where('updated_at', '>', $cursor));
            });
        } else {
            $query->where('status', 'active');
        }

        return response()->json([
            'cursor' => $nextCursor,
            'full' => $cursor === null,
            'products' => $query->get()->map(fn (Product $p) => PosProductPresenter::format($p))->values(),
        ]);
    }
}
