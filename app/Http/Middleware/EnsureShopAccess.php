<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the shop a request applies to (from the X-Shop-Id header) and
 * checks the authenticated user has a role on it. Every route that touches
 * shop-scoped data (products, sales, customers, ...) should use this.
 *
 * Usage: ->middleware('shop.access') for any member, or
 *        ->middleware('shop.access:owner,manager') to restrict by role.
 */
class EnsureShopAccess
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $shopId = $request->header('X-Shop-Id') ?? $request->route('shop');

        // A deactivated account is refused even if a token somehow survived.
        abort_if($request->user()->status !== 'active', 403, 'This account has been deactivated.');

        abort_if(! $shopId, 400, 'X-Shop-Id header is required.');

        $shop = Shop::find($shopId);

        abort_if(! $shop, 404, 'Shop not found.');

        $userRole = $request->user()->shopRoles()->where('shop_id', $shop->id)->first();

        abort_if(! $userRole, 403, 'You do not have access to this shop.');

        if ($roles !== [] && ! in_array($userRole->role, array_map(fn (string $r) => Role::from($r), $roles), true)) {
            abort(403, 'Your role does not permit this action.');
        }

        $request->attributes->set('shop', $shop);
        $request->attributes->set('shopRole', $userRole->role);

        return $next($request);
    }
}
