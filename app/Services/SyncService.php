<?php

namespace App\Services;

use App\Enums\Role;
use App\Http\Requests\StoreSaleRequest;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\SyncEvent;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Applies events a device recorded while offline. The rules it enforces:
 *
 *  - Pushing the same event again never applies it twice.
 *  - Each event stands alone: one failing sale never blocks the ones after it.
 *  - Nothing is silently changed. If the server can't apply an event exactly
 *    as the device recorded it, the event is reported back as "conflict" or
 *    "rejected" with the reason, and nothing is written for it.
 *  - A person can only sync their own sales; an owner/manager can sync (and
 *    approve price changes on) anyone's in the shop, and the sale stays
 *    attributed to the person who actually made it.
 */
class SyncService
{
    public function __construct(private SaleService $sales) {}

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public function push(Shop $shop, User $pusher, Role $role, string $deviceId, array $events): array
    {
        return array_map(
            fn (array $event) => $this->processEvent($shop, $pusher, $role, $deviceId, $event),
            $events,
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function processEvent(Shop $shop, User $pusher, Role $role, string $deviceId, array $event): array
    {
        $localId = (string) $event['local_event_id'];
        $payload = $event['payload'] ?? [];
        $hash = hash('sha256', json_encode($payload));
        $isManager = in_array($role, [Role::Owner, Role::Manager], true);

        try {
            return DB::transaction(function () use ($shop, $pusher, $deviceId, $event, $localId, $payload, $hash, $isManager) {
                // Claim the event before applying sale effects. On PostgreSQL,
                // the unique key makes a concurrent claim wait for this
                // transaction; the loser is handled below from its final row.
                $record = SyncEvent::where('shop_id', $shop->id)
                    ->where('device_id', $deviceId)
                    ->where('local_event_id', $localId)
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    $record = SyncEvent::create([
                        'shop_id' => $shop->id,
                        'user_id' => $pusher->id,
                        'device_id' => $deviceId,
                        'local_event_id' => $localId,
                        'entity_type' => $event['entity_type'],
                        'operation' => $event['operation'],
                        'payload_hash' => $hash,
                        'status' => 'processing',
                    ]);
                }

                if ($record->status === 'processed') {
                    return $this->storedOutcome($record, $shop, $localId, $hash);
                }

                $cashier = $this->resolveCashier($shop, $pusher, $isManager, $event['cashier_id'] ?? null);

                if (! $cashier) {
                    return $this->record($record, $this->outcome($localId, 'rejected', [
                        'message' => 'Only a manager can sync another person\'s sales, and they must belong to this shop.',
                    ]));
                }

                $validator = Validator::make($payload, (new StoreSaleRequest)->rules());

                if ($validator->fails()) {
                    return $this->record($record, $this->outcome($localId, 'rejected', [
                        'message' => 'The sale details were not valid.',
                        'errors' => $validator->errors()->toArray(),
                    ]));
                }

                $approved = (bool) ($event['accept_agreed_prices'] ?? false);

                if ($approved && ! $isManager) {
                    return $this->record($record, $this->outcome($localId, 'conflict', [
                        'message' => 'A manager must approve recording this sale at the price charged.',
                    ]));
                }

                $data = $validator->validated();
                $data['idempotency_key'] = $data['idempotency_key'] ?? "sync-{$deviceId}-{$localId}";
                $data['synced_at'] = now();
                $data['client_created_at'] = $data['client_created_at'] ?? $event['client_created_at'] ?? null;
                $data['allow_price_override'] = $approved;

                if (isset($payload['expected_total'])) {
                    $data['expected_total'] = (int) $payload['expected_total'];
                }

                try {
                    $sale = $this->sales->create($shop, $cashier, $data);
                } catch (PriceChanged $e) {
                    return $this->record($record, $this->outcome($localId, 'conflict', [
                        'message' => 'Prices changed since this sale was made offline.',
                        'expected_total' => $e->expected,
                        'server_total' => $e->serverTotal,
                    ]));
                } catch (ValidationException $e) {
                    return $this->record($record, $this->outcome($localId, 'rejected', [
                        'message' => collect($e->errors())->flatten()->first() ?? 'The sale could not be recorded.',
                        'errors' => $e->errors(),
                    ]));
                }

                return $this->record($record, $this->outcome($localId, 'processed', [
                    'sale_id' => $sale->id,
                    'sale_number' => $sale->sale_number,
                    'total' => $sale->total,
                    'duplicate' => ! $sale->wasRecentlyCreated,
                ]), $sale->id);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The only expected contention is the event claim above. Never
            // turn another unique error into success: return only a completed
            // matching event whose persisted result can be verified.
            $record = SyncEvent::where('shop_id', $shop->id)
                ->where('device_id', $deviceId)
                ->where('local_event_id', $localId)
                ->first();

            if ($record && $record->status !== 'processing') {
                return $this->storedOutcome($record, $shop, $localId, $hash);
            }

            throw $e;
        }
    }

    private function resolveCashier(Shop $shop, User $pusher, bool $isManager, mixed $requested): ?User
    {
        if ($requested === null || (int) $requested === $pusher->id) {
            return $pusher;
        }

        if (! $isManager) {
            return null;
        }

        return User::whereHas('shopRoles', fn ($q) => $q->where('shop_id', $shop->id))->find((int) $requested);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $outcome
     * @return array<string, mixed>
     */
    private function record(SyncEvent $record, array $outcome, ?int $entityId = null): array
    {
        $attributes = [
            'status' => $outcome['status'],
            'entity_id' => $entityId,
            'result' => $outcome,
            'processed_at' => now(),
        ];

        $record->update($attributes);

        return $outcome;
    }

    private function storedOutcome(SyncEvent $record, Shop $shop, string $localId, string $hash): array
    {
        if ($record->payload_hash !== $hash) {
            return $this->outcome($localId, 'conflict', [
                'message' => 'This event id was already used for a different sale.',
            ]);
        }

        $result = $record->result;

        if (! is_array($result)
            || ($result['local_event_id'] ?? null) !== $localId
            || ($result['status'] ?? null) !== $record->status) {
            throw new \LogicException('The stored sync event result is incomplete.');
        }

        if ($record->status === 'processed') {
            $saleId = (int) ($result['sale_id'] ?? 0);

            if ($saleId === 0
                || (int) $record->entity_id !== $saleId
                || ! Sale::where('shop_id', $shop->id)->whereKey($saleId)->exists()) {
                throw new \LogicException('The stored sync event does not match its sale.');
            }
        }

        return array_merge($result, ['duplicate' => true]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function outcome(string $localId, string $status, array $extra = []): array
    {
        return ['local_event_id' => $localId, 'status' => $status] + $extra;
    }
}
