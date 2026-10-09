<?php

namespace Tests\Feature\Integration;

use App\Models\Organization;
use App\Models\ProductionLot;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\ProductionService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

/** Opt-in PostgreSQL races. Never resets a database or deletes existing records. */
class PostgresConcurrencyTest extends TestCase
{
    use CreatesShops;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('NILEBIT_TEST_DATABASE_URL') || getenv('NILEBIT_TEST_DATABASE_ISOLATED') !== '1') {
            $this->markTestSkipped('Requires an explicitly isolated migrated PostgreSQL database.');
        }
        config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => getenv('NILEBIT_TEST_DATABASE_URL'), 'database.connections.pgsql.port' => 5432]);
        DB::purge('pgsql');
    }

    private function race(array $data): array
    {
        $data['start'] = microtime(true) + 5;
        $command = [PHP_BINARY, base_path('tests/Support/ProductionRaceWorker.php'), base64_encode(json_encode($data))];
        $workers = [new Process($command, base_path()), new Process($command, base_path())];
        foreach ($workers as $worker) {
            $worker->setTimeout(90)->start();
        }
        $results = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $output = $worker->getOutput();
            $marker = strpos($output, 'NILEBIT_RESULT=');
            $this->assertNotFalse($marker, 'Worker did not return a result.');
            $results[] = json_decode(substr($output, $marker + strlen('NILEBIT_RESULT=')), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_duplicate_checkouts_and_competing_stock_updates_are_serialized(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        Organization::whereKey($shop->organization_id)->update(['name' => 'QA Nutrawell isolated checkout race']);
        $product = $this->productWithStock($shop, $owner, 1000, 500, 2);
        $payload = ['idempotency_key' => 'race-sale', 'items' => [['product_id' => $product->id, 'quantity' => 1]], 'payments' => [['method' => 'CASH', 'amount' => 1000]]];
        $results = $this->race(['operation' => 'sale', 'shop' => $shop->id, 'user' => $owner->id, 'payload' => $payload]);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, Sale::where('shop_id', $shop->id)->count());
        $this->assertSame(1.0, app(StockService::class)->current($shop->id, $product->id));
        $payload['idempotency_key'] = null;
        $results = $this->race(['operation' => 'sale', 'shop' => $shop->id, 'user' => $owner->id, 'payload' => $payload]);
        $this->assertSame(1, count(array_filter($results, fn ($result) => isset($result['validation']))));
        $this->assertSame(0.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_duplicate_batch_completion_creates_one_lot_and_one_input_movement(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        Organization::whereKey($shop->organization_id)->update(['name' => 'QA Nutrawell isolated completion race']);
        $input = $this->productWithStock($shop, $owner, 0, 500, 5, ['kind' => 'raw_material']);
        $output = $this->productWithStock($shop, $owner, 1000, 0, 0);
        $batch = app(ProductionService::class)->plan($shop, $owner, ['name' => 'QA Nutrawell concurrent completion', 'production_date' => $shop->today(), 'inputs' => [['product_id' => $input->id, 'planned_quantity' => 2]], 'outputs' => [['product_id' => $output->id, 'quantity' => 2]]])['batch'];
        $results = $this->race(['operation' => 'batch', 'shop' => $shop->id, 'user' => $owner->id, 'batch' => $batch->id, 'payload' => ['idempotency_key' => 'race-completion']]);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, ProductionLot::where('production_batch_id', $batch->id)->count());
        $this->assertSame(1, StockMovement::where('shop_id', $shop->id)->where('movement_type', 'PRODUCTION_INPUT')->count());
        $this->assertSame(3.0, app(StockService::class)->current($shop->id, $input->id));
    }
}
