<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PaymentMethod;
use App\Enums\ProductKind;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Completes and voids sales. Client-sent prices and totals are never trusted:
 * everything is recomputed here from the products' own prices, inside one
 * transaction that also writes the payments and the stock-out movements.
 */
class SaleService
{
    public function __construct(
        private StockService $stock,
        private ProductionLotService $lots,
        private AuditLogger $audit,
        private CustomerLedger $ledger,
    ) {}

    /**
     * Creates the sale, or - when the idempotency key was already used for
     * this shop - returns the sale that key created, without touching stock
     * or payments a second time. Check $sale->wasRecentlyCreated to tell which.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Shop $shop, User $cashier, array $data): Sale
    {
        $key = $data['idempotency_key'] ?? null;

        if ($key && ($existing = $this->findByKey($shop, $key))) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->complete($shop, $cashier, $data));
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests raced; the loser returns the winner's sale.
            if ($key && ($existing = $this->findByKey($shop, $key))) {
                return $existing;
            }

            throw $e;
        }
    }

    public function void(Shop $shop, User $user, Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($shop, $user, $sale, $reason) {
            $candidate = Sale::where('shop_id', $shop->id)->findOrFail($sale->id);
            if ($candidate->customer_id) {
                Customer::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($candidate->customer_id);
            }
            $locked = Sale::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($sale->id);

            if ($locked->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'This sale has already been voided.']);
            }

            if (Refund::where('sale_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['sale' => 'This sale has been refunded, so it can no longer be voided.']);
            }

            $locked->load('items', 'payments');

            $products = Product::where('shop_id', $shop->id)->whereIn('id', $locked->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($locked->items as $item) {
                $product = $products[$item->product_id];
                $baseQuantity = round($item->quantity * $item->unit_conversion, 3);

                $this->stock->record(
                    $product, $baseQuantity, MovementType::SaleReturn, $user, $locked,
                    "Void of {$locked->sale_number}: {$reason}", $item->historical_cost,
                );
                if ($product->kindOrDefault() === ProductKind::FinishedGood) {
                    $this->lots->restoreFromReference($shop, 'SALE', $item, $baseQuantity, $user, $locked, 'SALE_VOID', $reason);
                }
            }

            foreach ($locked->payments->where('direction', 'in') as $payment) {
                Payment::create([
                    'shop_id' => $shop->id,
                    'sale_id' => $locked->id,
                    'customer_id' => $payment->customer_id,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'reference' => "Void of {$locked->sale_number}",
                    'direction' => 'out',
                    'recorded_by' => $user->id,
                ]);
            }

            if ($locked->amount_due > 0 && $locked->customer_id) {
                $customer = Customer::lockForUpdate()->find($locked->customer_id);
                $collected = (int) Payment::where('sale_id', $locked->id)->whereIn('id', DB::table('delivery_payment_attempts')->select('payment_id'))->sum('amount');
                $this->ledger->record($customer, CustomerLedger::SALE_VOID, -max(0, $locked->amount_due - $collected), $user, $locked, "Void of {$locked->sale_number}");
            }

            $before = ['status' => $locked->status];

            $locked->update([
                'status' => 'voided',
                'void_reason' => $reason,
                'voided_by' => $user->id,
                'voided_at' => now(),
            ]);

            DeliveryOrder::where('sale_id', $locked->id)->update(['status' => 'cancelled', 'failure_reason' => 'Sale voided: '.$reason]);

            $this->audit->record($user, $shop, 'sale.void', $locked, $before, [
                'status' => 'voided',
                'reason' => $reason,
                'total' => $locked->total,
            ]);

            return $this->fresh($locked);
        });
    }

    private function findByKey(Shop $shop, string $key): ?Sale
    {
        $sale = Sale::where('shop_id', $shop->id)->where('idempotency_key', $key)->first();

        return $sale ? $this->fresh($sale) : null;
    }

    private function fresh(Sale $sale): Sale
    {
        $wasCreated = $sale->wasRecentlyCreated;
        $sale = Sale::with('items', 'payments', 'cashier:id,name', 'customer:id,name,phone')->findOrFail($sale->id);
        $sale->wasRecentlyCreated = $wasCreated;

        return $sale;
    }

    /** @param  array<string, mixed>  $data */
    private function complete(Shop $shop, User $cashier, array $data): Sale
    {
        $lines = $data['items'];

        $customer = null;
        if (! empty($data['customer_id'])) {
            $customer = Customer::where('shop_id', $shop->id)->lockForUpdate()->find($data['customer_id']);

            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'This customer is not in this shop.']);
            }
        }

        // Lock the products (in id order, so concurrent sales can't deadlock)
        // before reading stock, so two sales can't both take the last item.
        $products = Product::with('units')
            ->where('shop_id', $shop->id)
            ->saleable()
            ->whereIn('id', collect($lines)->pluck('product_id')->unique())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $priced = [];
        $baseNeeded = [];
        $gross = 0;
        $lineDiscounts = 0;

        foreach ($lines as $i => $line) {
            $product = $products->get((int) $line['product_id']);

            if (! $product) {
                throw ValidationException::withMessages(["items.$i.product_id" => 'This product is not available in this shop.']);
            }

            $quantity = (float) $line['quantity'];
            $unitName = $line['unit'] ?? $product->base_unit;

            if ($unitName === $product->base_unit) {
                $conversion = 1.0;
                $unitPrice = $product->selling_price;
            } else {
                $unit = $product->units->firstWhere('unit_name', $unitName);

                if (! $unit) {
                    throw ValidationException::withMessages(["items.$i.unit" => "{$product->name} is not sold by the {$unitName}."]);
                }

                $conversion = $unit->conversion_to_base_unit;
                $unitPrice = $unit->selling_price;
            }

            // Only the sync path sets this, after a manager approves it: a sale
            // rung up offline is recorded at the price the customer was charged.
            if (($data['allow_price_override'] ?? false) && isset($line['unit_price'])) {
                $unitPrice = (int) $line['unit_price'];
            }

            $lineGross = (int) round($quantity * $unitPrice);
            $lineDiscount = (int) ($line['discount'] ?? 0);

            if ($lineDiscount > $lineGross) {
                throw ValidationException::withMessages(["items.$i.discount" => 'A discount cannot be more than the line amount.']);
            }

            $baseNeeded[$product->id] = round(($baseNeeded[$product->id] ?? 0) + $quantity * $conversion, 3);
            $gross += $lineGross;
            $lineDiscounts += $lineDiscount;

            $priced[] = [
                'product' => $product,
                'quantity' => $quantity,
                'unit' => $unitName,
                'conversion' => $conversion,
                'unit_price' => $unitPrice,
                'historical_cost' => (int) round($product->current_cost * $conversion),
                'discount' => $lineDiscount,
                'line_total' => $lineGross - $lineDiscount,
            ];
        }

        $available = $this->stock->currentFor($shop->id, array_keys($baseNeeded));

        foreach ($lines as $i => $line) {
            $productId = (int) $line['product_id'];
            $stock = ($available[$productId] ?? 0.0) - $this->lots->expiredQuantity($shop, $productId);

            if ($baseNeeded[$productId] > $stock + 0.0005) {
                $name = $products[$productId]->name;
                throw ValidationException::withMessages(["items.$i.quantity" => "Only {$stock} of {$name} in stock."]);
            }
        }

        $orderDiscount = (int) ($data['discount'] ?? 0);
        $discount = $lineDiscounts + $orderDiscount;

        if ($discount > $gross) {
            throw ValidationException::withMessages(['discount' => 'Discounts cannot be more than the sale amount.']);
        }

        $deliveryFee = ($data['fulfillment']['type'] ?? null) === 'delivery' ? (int) ($data['fulfillment']['delivery_fee'] ?? 0) : 0;
        $total = $gross - $discount + $deliveryFee;

        if ($total <= 0) {
            throw ValidationException::withMessages(['items' => 'The sale total must be more than zero.']);
        }

        if (isset($data['expected_total']) && ! ($data['allow_price_override'] ?? false) && $total !== (int) $data['expected_total']) {
            throw new PriceChanged($total, (int) $data['expected_total']);
        }

        [$payments, $amountPaid] = $this->settlePayments($data['payments'] ?? [], $total);
        $amountDue = $total - $amountPaid;

        if ($amountDue > 0 && ! $customer) {
            throw ValidationException::withMessages([
                'customer_id' => 'Choose a customer to sell on credit — the unpaid '.$amountDue.' has to be owed by someone.',
            ]);
        }

        // Atomic per-shop counter; the row lock it takes serialises numbering.
        DB::table('shops')->where('id', $shop->id)->increment('sale_counter');
        $number = DB::table('shops')->where('id', $shop->id)->value('sale_counter');

        $sale = Sale::create([
            'shop_id' => $shop->id,
            'sale_number' => sprintf('S-%06d', $number),
            'customer_id' => $customer?->id,
            'cashier_id' => $cashier->id,
            'subtotal' => $gross,
            'delivery_fee' => $deliveryFee,
            'discount' => $discount,
            'total' => $total,
            'amount_paid' => $amountPaid,
            'amount_due' => $amountDue,
            'due_date' => $data['due_date'] ?? null,
            'status' => 'completed',
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'client_created_at' => $data['client_created_at'] ?? null,
            'synced_at' => $data['synced_at'] ?? null,
        ]);

        if (! empty($data['fulfillment'])) {
            $details = $data['fulfillment'];
            DeliveryOrder::create(array_intersect_key($details, array_flip(['recipient_name', 'recipient_phone', 'address', 'location_notes', 'instructions', 'notes', 'requested_at'])) + ['shop_id' => $shop->id, 'sale_id' => $sale->id, 'fulfillment_type' => $details['type'], 'status' => 'pending']);
            $this->audit->record($cashier, $shop, 'delivery.create', $sale, null, ['fulfillment_type' => $details['type'], 'delivery_fee' => $deliveryFee]);
        }

        if (! empty($data['client_created_at'])) {
            $happenedAt = Carbon::parse($data['client_created_at']);
            $sale->forceFill(['created_at' => $happenedAt->isFuture() ? now() : $happenedAt])->save();
        }

        foreach ($priced as $line) {
            $product = $line['product'];

            $saleItem = SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_conversion' => $line['conversion'],
                'unit_price' => $line['unit_price'],
                'historical_cost' => $line['historical_cost'],
                'discount' => $line['discount'],
                'line_total' => $line['line_total'],
            ]);

            $this->stock->record(
                $product,
                -round($line['quantity'] * $line['conversion'], 3),
                MovementType::Sale,
                $cashier,
                $sale,
                null,
                $line['historical_cost'],
            );

            // Finished goods made by production must leave a concrete batch/expiry lot.
            // Consume all available lot stock first (FEFO/FIFO). A product may have
            // pre-production legacy stock too; the remainder stays non-lotted rather
            // than making a valid mixed sale fail or pretending it came from a lot.
            if ($product->kindOrDefault() === ProductKind::FinishedGood) {
                $needed = round($line['quantity'] * $line['conversion'], 3);
                $lotStock = max(0, $this->lots->availableQuantity($shop, $product->id, true) - $this->lots->expiredQuantity($shop, $product->id));
                if ($lotStock > 0) {
                    $this->lots->consume($shop, $product, min($needed, $lotStock), $cashier, 'SALE', $saleItem);
                }
            }
        }

        foreach ($payments as $payment) {
            Payment::create([
                'shop_id' => $shop->id,
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'amount' => $payment['amount'],
                'method' => $payment['method'],
                'reference' => $payment['reference'],
                'direction' => 'in',
                'recorded_by' => $cashier->id,
            ]);
        }

        if ($amountDue > 0) {
            $this->ledger->record($customer, CustomerLedger::CREDIT_SALE, $amountDue, $cashier, $sale, $sale->sale_number);
        }

        return $this->fresh($sale);
    }

    /**
     * Turns what the customer handed over into what was actually received.
     * Overpayment is only allowed in cash (that is where change comes from)
     * and is removed from the recorded payment, so the books show net money.
     *
     * @param  array<int, array<string, mixed>>  $payments
     * @return array{0: array<int, array{method: string, amount: int, reference: ?string}>, 1: int}
     */
    private function settlePayments(array $payments, int $total): array
    {
        $rows = array_map(fn ($p) => [
            'method' => $p['method'],
            'amount' => (int) $p['amount'],
            'reference' => $p['reference'] ?? null,
        ], $payments);

        $tendered = array_sum(array_column($rows, 'amount'));

        if ($tendered > $total) {
            $excess = $tendered - $total;
            $cashIndex = null;

            foreach ($rows as $index => $row) {
                if ($row['method'] === PaymentMethod::Cash->value && $row['amount'] >= $excess) {
                    $cashIndex = $index;
                }
            }

            if ($cashIndex === null) {
                throw ValidationException::withMessages(['payments' => 'Payments are more than the total, and only cash can give change.']);
            }

            $rows[$cashIndex]['amount'] -= $excess;
            $rows = array_values(array_filter($rows, fn ($row) => $row['amount'] > 0));
            $tendered = $total;
        }

        return [$rows, $tendered];
    }
}
