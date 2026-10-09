<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\ProductKind;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionLot;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReturnService
{
    public function __construct(private StockService $stock, private SupplierLedger $ledger, private SupplierDebt $debt, private AuditLogger $audit) {}

    public function create(Shop $shop, User $by, int $id, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($shop, $by, $id, $data) {
            $candidate = Purchase::where('shop_id', $shop->id)->findOrFail($id);
            $supplier = Supplier::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($candidate->supplier_id);
            $purchase = Purchase::where('shop_id', $shop->id)->with('items')->lockForUpdate()->findOrFail($id);
            $existing = PurchaseReturn::where('shop_id', $shop->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
            if ($purchase->status !== 'received') {
                throw ValidationException::withMessages(['purchase' => 'Only received purchases can be returned.']);
            }
            $products = Product::where('shop_id', $shop->id)->whereIn('id', $purchase->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            $total = 0;
            $needed = [];
            foreach ($data['lines'] as $line) {
                $item = $purchase->items->firstWhere('id', $line['purchase_item_id']);
                if (! $item) {
                    throw ValidationException::withMessages(['lines' => 'Choose items on this purchase.']);
                }
                $done = DB::table('purchase_return_items')->where('purchase_item_id', $item->id)->first([DB::raw('coalesce(sum(quantity), 0) as qty'), DB::raw('coalesce(sum(amount), 0) as value')]);
                $quantity = round((float) $line['quantity'], 3);
                if ($quantity <= 0 || $quantity > $item->quantity - (float) $done->qty + .0005) {
                    throw ValidationException::withMessages(['lines' => 'Return quantity exceeds what remains on the purchase.']);
                }
                $base = round($quantity * $item->conversion, 3);
                $product = $products[$item->product_id];
                if ($product->kindOrDefault() === ProductKind::FinishedGood && ProductionLot::where('product_id', $product->id)->exists()) {
                    throw ValidationException::withMessages(['lines' => 'This finished product has production lots. Return unlotted materials or packaging separately.']);
                }
                $amount = abs($quantity - ($item->quantity - (float) $done->qty)) < .0005 ? $item->line_total - (int) $done->value : (int) round($quantity * $item->unit_cost);
                $needed[$product->id] = ($needed[$product->id] ?? 0) + $base;
                $lines[] = compact('item', 'quantity', 'base', 'amount', 'product');
                $total += $amount;
            }
            $levels = $this->stock->currentFor($shop->id, array_keys($needed));
            foreach ($needed as $productId => $quantity) {
                if ($quantity > ($levels[$productId] ?? 0) + .0005) {
                    throw ValidationException::withMessages(['lines' => 'Not enough stock remains to return these items.']);
                }
            }
            $credit = min($total, $this->debt->owedOn($purchase), max(0, $this->ledger->balance($supplier)));
            $cash = $total - $credit;
            if ($cash > 0 && (empty($data['refund_received']) || empty($data['method']))) {
                throw ValidationException::withMessages(['refund_received' => 'Confirm the supplier refund was received and select its payment method.']);
            }
            $return = PurchaseReturn::create(['shop_id' => $shop->id, 'purchase_id' => $id, 'total' => $total, 'balance_credit' => $credit, 'cash_refund' => $cash, 'reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key'], 'recorded_by' => $by->id]);
            foreach ($lines as $line) {
                ['item' => $item, 'product' => $product, 'base' => $base, 'quantity' => $quantity, 'amount' => $amount] = $line;
                $beforeQuantity = $this->stock->current($shop->id, $product->id);
                $remainingValue = (int) round($beforeQuantity * $product->current_cost) - $amount;
                if ($remainingValue < 0 || (abs($beforeQuantity - $base) < .0005 && $remainingValue !== 0)) {
                    throw ValidationException::withMessages(['lines' => 'Return cost cannot be reconciled with remaining inventory value. Ask the owner to review valuation.']);
                }
                DB::table('purchase_return_items')->insert(['purchase_return_id' => $return->id, 'purchase_item_id' => $item->id, 'quantity' => $quantity, 'amount' => $amount]);
                $this->stock->record($product, -$base, MovementType::PurchaseReturn, $by, $return, $data['reason'], $item->base_unit_cost);
                $product->update(['current_cost' => $beforeQuantity > $base ? (int) round($remainingValue / ($beforeQuantity - $base)) : $product->current_cost]);
            }
            if ($credit > 0) {
                $this->ledger->record($supplier, 'PURCHASE_RETURN', -$credit, $by, $return, $data['reason']);
            }
            if ($cash > 0) {
                Payment::create(['shop_id' => $shop->id, 'supplier_id' => $supplier->id, 'purchase_id' => $id, 'amount' => $cash, 'method' => $data['method'], 'direction' => 'in', 'reference' => 'Supplier return '.$return->id, 'recorded_by' => $by->id]);
            }
            $this->audit->record($by, $shop, 'purchase.return', $return, null, ['total' => $total, 'balance_credit' => $credit, 'cash_refund' => $cash]);

            return $return;
        });
    }
}
