<?php

namespace App\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Replays confirmed bot operations without repeating a shop mutation. */
class OperationReplay
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-NileBit-Operation');
        if ($key === null || $request->isMethodSafe()) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid operation ID is required.']);
        }
        $shop = $request->attributes->get('shop');
        $hash = hash('sha256', json_encode([$request->user()->id, $request->method(), $request->path(), $this->canonical($request->all())], JSON_THROW_ON_ERROR));
        DB::beginTransaction();
        try {
            $this->lock($shop->id, $key);
            DB::table('pos_operation_requests')->insertOrIgnore(['shop_id' => $shop->id, 'actor_id' => $request->user()->id, 'operation_key' => $key,
                'request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            $operation = DB::table('pos_operation_requests')->where('shop_id', $shop->id)->where('operation_key', $key)->lockForUpdate()->firstOrFail();
            if (! hash_equals($operation->request_hash, $hash)) {
                throw ValidationException::withMessages(['idempotency_key' => 'This operation ID belongs to a different request.']);
            }
            if ($operation->response_body !== null) {
                $response = response(Crypt::decryptString($operation->response_body), $operation->status_code)
                    ->header('Content-Type', $operation->content_type)->header('X-NileBit-Replayed', 'true');
                DB::commit();

                return $response;
            }
            $response = $next($request);
            if ($response->getStatusCode() >= 400) {
                DB::rollBack();

                return $response;
            }
            DB::table('pos_operation_requests')->where('id', $operation->id)->update([
                'response_body' => Crypt::encryptString($response->getContent()), 'status_code' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type', 'application/json'), 'updated_at' => now(),
            ]);
            DB::commit();

            return $response;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /** Share a transaction lock with outcome lookup while a keyed write is in flight. */
    public function lock(int $shopId, string $key): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $lock = (int) hexdec(substr(hash('sha256', $shopId.':'.$key), 0, 15));
            DB::select('select pg_advisory_xact_lock(?)', [$lock]);
        }
    }

    private function canonical(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input);
        }
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $input;
    }
}
