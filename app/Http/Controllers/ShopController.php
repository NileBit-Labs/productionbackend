<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Shop;
use App\Models\UserShopRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $shop = DB::transaction(function () use ($data, $user) {
            $shop = Shop::create([
                ...$data,
                'organization_id' => $user->organization_id,
            ]);

            UserShopRole::create([
                'user_id' => $user->id,
                'shop_id' => $shop->id,
                'role' => Role::Owner,
            ]);

            return $shop;
        });

        return response()->json($shop, 201);
    }

    public function show(Request $request, Shop $shop)
    {
        $this->authorizeShopAccess($request, $shop);

        return $shop;
    }

    public function update(Request $request, Shop $shop)
    {
        $this->authorizeShopAccess($request, $shop, [Role::Owner]);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'business_type' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $shop->update($data);

        return $shop;
    }

    protected function authorizeShopAccess(Request $request, Shop $shop, array $allowedRoles = []): void
    {
        $userRole = $request->user()->shopRoles()->where('shop_id', $shop->id)->first();

        abort_if(! $userRole, 403, 'You do not have access to this shop.');

        if ($allowedRoles !== [] && ! in_array($userRole->role, $allowedRoles, true)) {
            abort(403, 'Your role does not permit this action.');
        }
    }
}
