<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Called inside the settlement transaction, before locking the customer or supplier. */
class PaymentReplay
{
    /** @param array<string, mixed> $data */
    public function attributes(Shop $shop, User $actor, string $target, array $data): array
    {
        $key = $data['idempotency_key'] ?? null;
        if ($key === null || $key === '') {
            return [];
        }

        return [
            'idempotency_key' => $key,
            'request_hash' => hash('sha256', json_encode([
                $actor->id, $target, (int) $data['amount'], $data['method'], $data['reference'] ?? null,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string, string> $attributes */
    public function wasRecorded(Shop $shop, array $attributes): bool
    {
        if ($attributes === []) {
            return false;
        }

        Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
        $payment = Payment::where('shop_id', $shop->id)
            ->where('idempotency_key', $attributes['idempotency_key'])->first();

        if (! $payment) {
            return false;
        }

        if (! hash_equals((string) $payment->request_hash, $attributes['request_hash'])) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This operation ID was already used for a different payment. Use a new ID for a new payment.',
            ]);
        }

        return true;
    }
}
