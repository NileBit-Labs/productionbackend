<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryService
{
    public const TRANSITIONS = [
        'pending' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['out_for_delivery', 'delivered', 'cancelled'],
        'out_for_delivery' => ['delivered', 'failed'],
        'failed' => ['ready', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(private AuditLogger $audit, private CustomerLedger $ledger, private CustomerDebt $debt) {}

    public function transition(Shop $shop, User $by, int $id, array $data): DeliveryOrder
    {
        return DB::transaction(function () use ($shop, $by, $id, $data) {
            $candidate = DeliveryOrder::where('shop_id', $shop->id)->findOrFail($id);
            $sale = Sale::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($candidate->sale_id);
            $order = DeliveryOrder::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($id);
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'A voided sale cannot be fulfilled.']);
            }
            $next = $data['status'];
            if ($next === $order->status) {
                return $order->load('sale.customer', 'sale.items');
            }
            if (! in_array($next, self::TRANSITIONS[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This status transition is not permitted.']);
            }
            if (! in_array($next, ['cancelled', 'failed'], true) && $sale->items->every(fn ($item) => (float) DB::table('refund_items')->where('sale_item_id', $item->id)->sum('quantity') >= $item->quantity - .0005)) {
                throw ValidationException::withMessages(['status' => 'All goods on this sale have been returned. Cancel the fulfillment.']);
            }
            if ($next === 'out_for_delivery' && ($order->fulfillment_type !== 'delivery' || empty($data['driver_name']) || empty($data['driver_phone']))) {
                throw ValidationException::withMessages(['driver_name' => 'Dispatch needs a delivery person and contact.']);
            }
            if ($next === 'delivered' && ($order->fulfillment_type === 'delivery' && $order->status !== 'out_for_delivery')) {
                throw ValidationException::withMessages(['status' => 'Dispatch the delivery before completing it.']);
            }
            if ($next === 'delivered' && empty($data['proof_of_delivery'])) {
                throw ValidationException::withMessages(['proof_of_delivery' => 'Record recipient confirmation or a delivery note.']);
            }
            if (in_array($next, ['failed', 'cancelled'], true) && empty($data['failure_reason'])) {
                throw ValidationException::withMessages(['failure_reason' => 'Record why fulfillment failed or was cancelled.']);
            }
            $before = $order->only(['status']);
            $order->fill($data);
            if ($next === 'out_for_delivery') {
                $order->dispatched_at = now();
            }
            if ($next === 'delivered') {
                $order->completed_at = now();
            }
            $order->save();
            $this->audit->record($by, $shop, 'delivery.status', $order, $before, $order->only(['status', 'driver_name', 'proof_of_delivery', 'failure_reason']));

            return $order->load('sale.customer', 'sale.items');
        });
    }

    public function pay(Shop $shop, User $by, int $id, array $data): DeliveryOrder
    {
        return DB::transaction(function () use ($shop, $by, $id, $data) {
            $candidate = DeliveryOrder::where('shop_id', $shop->id)->with('sale')->findOrFail($id);
            $customer = Customer::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($candidate->sale->customer_id);
            $sale = Sale::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($candidate->sale_id);
            $order = DeliveryOrder::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($id);
            if (DB::table('delivery_payment_attempts')->where('delivery_order_id', $id)->where('idempotency_key', $data['idempotency_key'])->exists()) {
                return $order->load('sale.customer', 'sale.items');
            }
            $outstanding = (int) (collect($this->debt->openSales([$customer->id])[$customer->id] ?? [])->firstWhere('sale_id', $sale->id)['owed'] ?? 0);
            $amount = (int) $data['amount'];
            if ($sale->status !== 'completed' || $order->status === 'cancelled' || $amount > $outstanding || $amount > $this->ledger->balance($customer)) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the outstanding balance or the order is cancelled.']);
            }
            $payment = Payment::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'customer_id' => $customer->id, 'amount' => $amount, 'method' => $data['method'], 'reference' => $data['reference'] ?? 'Delivery collection', 'direction' => 'in', 'recorded_by' => $by->id]);
            $this->ledger->record($customer, CustomerLedger::PAYMENT, -$amount, $by, $payment, 'Delivery collection');
            DB::table('delivery_payment_attempts')->insert(['delivery_order_id' => $id, 'idempotency_key' => $data['idempotency_key'], 'payment_id' => $payment->id]);
            $this->audit->record($by, $shop, 'delivery.payment', $order, null, ['amount' => $amount, 'payment_id' => $payment->id]);

            return $order->load('sale.customer', 'sale.items');
        });
    }
}
