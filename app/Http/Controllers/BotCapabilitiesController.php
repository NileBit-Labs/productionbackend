<?php

namespace App\Http\Controllers;

use App\Services\OperationReplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class BotCapabilitiesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['operation_replay' => 1, 'scoped_bot_tokens' => 1, 'operation_lookup' => 1, 'sale_price_review' => 1, 'batch_revision' => 1, 'today' => $request->attributes->get('shop')->today()]);
    }

    public function operation(Request $request, string $key): JsonResponse
    {
        return DB::transaction(function () use ($request, $key): JsonResponse {
            app(OperationReplay::class)->lock($request->attributes->get('shop')->id, $key);
            $operation = DB::table('pos_operation_requests')
                ->where('shop_id', $request->attributes->get('shop')->id)->where('actor_id', $request->user()->id)
                ->where('operation_key', $key)->whereNotNull('response_body')->first();
            abort_unless($operation, 404);

            return response()->json(['recorded' => true, 'status' => $operation->status_code,
                'result' => json_decode(Crypt::decryptString($operation->response_body), true, flags: JSON_THROW_ON_ERROR)]);
        });
    }
}
