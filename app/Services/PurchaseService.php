<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receiving stock from a supplier. Stock still only moves through
 * StockService, and what is owed still only moves through SupplierLedger.
 */
class PurchaseService
{
    public function __construct(
        private StockService $stock,
        private SupplierLedger $ledger,
        private SupplierDebt $debt,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     * @return array{purchase: Purchase, replayed: bool}
     */
    public function receive(Shop $shop, User $by, array $data): array
    {
        $key = $data['idempotency_key'] ?? null;

        if ($key && ($existing = $this->findByKey($shop, $key))) {
            return ['purchase' => $existing, 'replayed' => true];
        }

        try {
            $purchase = DB::transaction(fn () => $this->create($shop, $by, $data, $key));
        } catch (UniqueConstraintViolationException $e) {
            if ($key && ($existing = $this->findByKey($shop, $key))) {
                return ['purchase' => $existing, 'replayed' => true];
            }

            throw $e;
        }

        return ['purchase' => $purchase, 'replayed' => false];
    }

    /** @param  array<string, mixed>  $data */
    private function create(Shop $shop, User $by, array $data, ?string $key): Purchase
    {
        $supplier = Supplier::where('shop_id', $shop->id)->lockForUpdate()->find($data['supplier_id']);

        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose a supplier from this shop.']);
        }

        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'This supplier is inactive. Reactivate them first.']);
        }

        $products = $this->lockProducts($shop, $data['items']);
        $lines = [];

        foreach ($data['items'] as $i => $item) {
            $lines[] = $this->priceLine($products[(int) $item['product_id']], $item, $i);
        }

        $total = array_sum(array_column($lines, 'line_total'));
        $paid = (int) ($data['amount_paid'] ?? 0);

        if ($paid > $total) {
            throw ValidationException::withMessages(['amount_paid' => "You can't pay more than the purchase total ({$total})."]);
        }

        DB::table('shops')->where('id', $shop->id)->increment('purchase_counter');
        $number = DB::table('shops')->where('id', $shop->id)->value('purchase_counter');

        $purchase = Purchase::create([
            'shop_id' => $shop->id,
            'supplier_id' => $supplier->id,
            'purchase_number' => sprintf('P-%06d', $number),
            'status' => 'received',
            'purchase_date' => $data['purchase_date'] ?? $shop->today(),
            'reference' => $data['reference'] ?? null,
            'total' => $total,
            'amount_paid' => $paid,
            'amount_due' => $total - $paid,
            'note' => $data['note'] ?? null,
            'received_by' => $by->id,
            'idempotency_key' => $key,
        ]);

        foreach ($lines as $line) {
            $product = $products[$line['product_id']];
            $item = PurchaseItem::create($line + ['purchase_id' => $purchase->id]);

            $stockBefore = $this->stock->current($shop->id, $product->id);

            $this->stock->record(
                $product, $item->base_quantity, MovementType::Purchase, $by, $purchase,
                null, $item->base_unit_cost,
            );

            // Weighted average: what the stock already on the shelf cost, blended with what just arrived.
            $costBefore = $product->current_cost;
            $costAfter = $stockBefore > 0
                ? (int) round(($stockBefore * $costBefore + $item->base_quantity * $item->base_unit_cost) / ($stockBefore + $item->base_quantity))
                : $item->base_unit_cost;

            $product->update(['current_cost' => $costAfter]);
            $item->update(['cost_before' => $costBefore, 'cost_after' => $costAfter]);
        }

        if ($paid > 0) {
            Payment::create([
                'shop_id' => $shop->id,
                'supplier_id' => $supplier->id,
                'purchase_id' => $purchase->id,
                'amount' => $paid,
                'method' => $data['payment_method'],
                'reference' => $data['payment_reference'] ?? null,
                'direction' => 'out',
                'recorded_by' => $by->id,
            ]);
        }

        // Only the part left on credit is owed; what was paid on the day never was.
        if ($purchase->amount_due > 0) {
            $this->ledger->record($supplier, SupplierLedger::PURCHASE, $purchase->amount_due, $by, $purchase, $purchase->purchase_number);
        }

        $this->audit->record($by, $shop, 'purchase.create', $purchase, null, [
            'purchase_number' => $purchase->purchase_number,
            'supplier_id' => $supplier->id,
            'total' => $total,
            'amount_paid' => $paid,
            'items' => count($lines),
        ]);

        return $purchase;
    }

    public function cancel(Shop $shop, User $by, int $purchaseId, string $reason): Purchase
    {
        return DB::transaction(function () use ($shop, $by, $purchaseId, $reason) {
            $purchase = Purchase::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($purchaseId);
            $supplier = Supplier::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($purchase->supplier_id);

            if ($purchase->status === 'cancelled') {
                throw ValidationException::withMessages(['purchase' => 'This purchase is already cancelled.']);
            }

            // Money that has left the till can't be un-paid by cancelling the paperwork.
            if ($purchase->amount_paid > 0 || $this->debt->owedOn($purchase) !== $purchase->amount_due) {
                throw ValidationException::withMessages(['purchase' => "Money has already been paid against {$purchase->purchase_number}, so it can't be cancelled."]);
            }

            $items = $purchase->items()->get();
            $products = Product::where('shop_id', $shop->id)->whereIn('id', $items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Stock that has already been sold can't be handed back.
            foreach ($items as $item) {
                $product = $products[$item->product_id];
                $onHand = $this->stock->current($shop->id, $product->id);

                if ($onHand + 0.0005 < $item->base_quantity) {
                    throw ValidationException::withMessages(['purchase' => "Some of the {$product->name} from this purchase has already been sold or written off, so it can't be cancelled."]);
                }
            }

            foreach ($items as $item) {
                $this->stock->record(
                    $products[$item->product_id], -$item->base_quantity, MovementType::PurchaseReturn, $by,
                    $purchase, "{$purchase->purchase_number} cancelled: {$reason}", $item->base_unit_cost,
                );
            }

            // Put each cost back, newest line first, but only where nothing has moved it since: a later
            // purchase's average already includes this stock and must not be overwritten.
            foreach ($items->sortByDesc('id') as $item) {
                $product = $products[$item->product_id];

                if ($item->cost_before !== null && $product->current_cost === $item->cost_after) {
                    $product->update(['current_cost' => $item->cost_before]);
                }
            }

            if ($purchase->amount_due > 0) {
                $this->ledger->record($supplier, SupplierLedger::PURCHASE_CANCEL, -$purchase->amount_due, $by, $purchase, "{$purchase->purchase_number} cancelled");
            }

            $purchase->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ]);

            $this->audit->record($by, $shop, 'purchase.cancel', $purchase, ['status' => 'received'], [
                'status' => 'cancelled',
                'reason' => $reason,
                'total' => $purchase->total,
            ]);

            return $purchase;
        });
    }

    /** Pays the supplier something towards what the shop owes them; applied to the oldest purchases first. */
    public function pay(Shop $shop, User $by, int $supplierId, int $amount, PaymentMethod $method, ?string $reference): Supplier
    {
        return DB::transaction(function () use ($shop, $by, $supplierId, $amount, $method, $reference) {
            $supplier = Supplier::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($supplierId);
            $balance = $this->ledger->balance($supplier);

            if ($amount > $balance) {
                throw ValidationException::withMessages([
                    'amount' => $balance > 0
                        ? "The shop only owes this supplier {$balance}. Enter that amount or less."
                        : 'The shop does not owe this supplier anything.',
                ]);
            }

            $payment = Payment::create([
                'shop_id' => $shop->id,
                'supplier_id' => $supplier->id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'direction' => 'out',
                'recorded_by' => $by->id,
            ]);

            $note = ucwords(strtolower(str_replace('_', ' ', $method->value))).($reference ? " · {$reference}" : '');

            $this->ledger->record($supplier, SupplierLedger::PAYMENT, -$amount, $by, $payment, $note);

            $this->audit->record($by, $shop, 'supplier.payment', $supplier, ['balance' => $balance], [
                'balance' => $balance - $amount,
                'amount' => $amount,
                'method' => $method->value,
            ]);

            return $supplier;
        });
    }

    /**
     * Locked in id order so two purchases touching the same products can't deadlock.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, Product>
     */
    private function lockProducts(Shop $shop, array $items)
    {
        $ids = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $products = Product::where('shop_id', $shop->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($items as $i => $item) {
            $product = $products->get((int) $item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages(["items.$i.product_id" => 'Choose a product from this shop.']);
            }

            if ($product->status !== 'active') {
                throw ValidationException::withMessages(["items.$i.product_id" => "{$product->name} is archived. Restore it before buying more."]);
            }
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function priceLine(Product $product, array $item, int $index): array
    {
        $conversion = 1.0;
        $unitName = null;

        if (! empty($item['unit_name']) && strcasecmp($item['unit_name'], $product->base_unit) !== 0) {
            $unit = $product->units()->whereRaw('lower(unit_name) = ?', [mb_strtolower($item['unit_name'])])->first();

            if (! $unit) {
                throw ValidationException::withMessages(["items.$index.unit_name" => "{$product->name} isn't sold by the {$item['unit_name']}."]);
            }

            $conversion = (float) $unit->conversion_to_base_unit;
            $unitName = $unit->unit_name;
        }

        $quantity = round((float) $item['quantity'], 3);
        $baseQuantity = round($quantity * $conversion, 3);
        $unitCost = (int) $item['unit_cost'];
        $lineTotal = (int) round($quantity * $unitCost);

        if ($baseQuantity <= 0) {
            throw ValidationException::withMessages(["items.$index.quantity" => 'Quantity is too small.']);
        }

        return [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_name' => $unitName,
            'conversion' => $conversion,
            'unit_cost' => $unitCost,
            'line_total' => $lineTotal,
            'base_quantity' => $baseQuantity,
            'base_unit_cost' => (int) round($lineTotal / $baseQuantity),
        ];
    }

    private function findByKey(Shop $shop, string $key): ?Purchase
    {
        return Purchase::where('shop_id', $shop->id)->where('idempotency_key', $key)->first();
    }
}
