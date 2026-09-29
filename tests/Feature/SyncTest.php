<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\SyncEvent;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    /** @return array<string, mixed> */
    private function saleEvent(string $id, int $productId, int $quantity = 1, int $paid = 1000, array $extra = [], ?int $unitPrice = null): array
    {
        return [
            'local_event_id' => $id,
            'entity_type' => 'sale',
            'operation' => 'create',
            'client_created_at' => '2026-09-01T10:15:00Z',
            'payload' => [
                'idempotency_key' => $id,
                'items' => [['product_id' => $productId, 'quantity' => $quantity] + ($unitPrice !== null ? ['unit_price' => $unitPrice] : [])],
                'payments' => [['method' => 'CASH', 'amount' => $paid]],
            ] + $extra,
        ];
    }

    private function push($user, $shop, array $events, string $device = 'device-A')
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->postJson('/api/sync/push', ['device_id' => $device, 'events' => $events]);
    }

    public function test_an_offline_sale_is_applied_and_keeps_the_time_it_happened(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->push($user, $shop, [$this->saleEvent('e1', $product->id, 2, 2000)])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'processed')
            ->assertJsonPath('results.0.total', 2000);

        $sale = Sale::firstOrFail();
        $this->assertSame('2026-09-01 10:15:00', $sale->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($sale->synced_at);
        $this->assertEquals(8.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_pushing_the_same_event_again_never_duplicates_the_sale_payment_or_stock_movement(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);
        $event = $this->saleEvent('e1', $product->id, 3, 3000);

        $this->push($user, $shop, [$event])->assertJsonPath('results.0.status', 'processed');
        $this->push($user, $shop, [$event])
            ->assertJsonPath('results.0.status', 'processed')
            ->assertJsonPath('results.0.duplicate', true);
        $this->push($user, $shop, [$event, $event])->assertOk();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE')->count());
        $record = SyncEvent::sole();
        $this->assertSame('processed', $record->status);
        $this->assertSame(Sale::firstOrFail()->id, $record->result['sale_id']);
        $this->assertEquals(7.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_an_event_already_applied_online_is_recognised_by_its_idempotency_key(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);
        $event = $this->saleEvent('sale-777', $product->id, 1, 1000);

        // The online attempt reached the server but the response was lost...
        $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->postJson('/api/sales', $event['payload'])->assertCreated();

        // ...so the device queued it and pushes it later.
        $this->push($user, $shop, [$event])
            ->assertJsonPath('results.0.status', 'processed')
            ->assertJsonPath('results.0.duplicate', true);

        $this->assertDatabaseCount('sales', 1);
        $this->assertEquals(9.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_reusing_an_event_id_for_a_different_sale_is_a_conflict(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->push($user, $shop, [$this->saleEvent('e1', $product->id, 1, 1000)])->assertOk();
        $this->push($user, $shop, [$this->saleEvent('e1', $product->id, 5, 5000)])
            ->assertJsonPath('results.0.status', 'conflict');

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_one_failing_sale_does_not_block_the_others_in_the_batch(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $plenty = $this->productWithStock($shop, $user, price: 1000, stock: 10);
        $scarce = $this->productWithStock($shop, $user, price: 1000, stock: 1);

        $response = $this->push($user, $shop, [
            $this->saleEvent('ok-1', $plenty->id, 1, 1000),
            $this->saleEvent('too-many', $scarce->id, 5, 5000),
            $this->saleEvent('ok-2', $plenty->id, 1, 1000),
        ])->assertOk();

        $response->assertJsonPath('results.0.status', 'processed')
            ->assertJsonPath('results.1.status', 'rejected')
            ->assertJsonPath('results.2.status', 'processed');
        $this->assertStringContainsString('in stock', $response->json('results.1.message'));
        $this->assertDatabaseCount('sales', 2);
    }

    public function test_a_price_change_since_the_offline_sale_is_reported_and_nothing_is_written(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);
        $event = $this->saleEvent('e1', $product->id, 2, 2000, ['expected_total' => 2000]);

        $product->update(['selling_price' => 1500]);

        $this->push($user, $shop, [$event])
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.expected_total', 2000)
            ->assertJsonPath('results.0.server_total', 3000);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertEquals(10.0, app(StockService::class)->current($shop->id, $product->id));
    }

    public function test_a_manager_can_approve_a_conflicted_sale_at_the_price_the_customer_was_charged(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000);
        $event = $this->saleEvent('e1', $product->id, 2, 2000, ['expected_total' => 2000], unitPrice: 1000);
        $product->update(['selling_price' => 1500]);

        $this->push($cashier, $shop, [$event])->assertJsonPath('results.0.status', 'conflict');

        $event['cashier_id'] = $cashier->id;
        $event['accept_agreed_prices'] = true;
        $this->push($owner, $shop, [$event])
            ->assertJsonPath('results.0.status', 'processed')
            ->assertJsonPath('results.0.total', 2000);

        $sale = Sale::with('items')->firstOrFail();
        $this->assertSame($cashier->id, $sale->cashier_id);
        $this->assertSame(1000, $sale->items->first()->unit_price);
        $this->assertSame(1500, $product->fresh()->selling_price);
        $this->assertDatabaseHas('sync_events', ['local_event_id' => 'e1', 'status' => 'processed']);
    }

    public function test_a_cashier_cannot_approve_their_own_price_conflict(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000);
        $event = $this->saleEvent('e1', $product->id, 2, 2000, ['expected_total' => 2000], unitPrice: 1000);
        $product->update(['selling_price' => 1500]);

        $event['accept_agreed_prices'] = true;
        $this->push($cashier, $shop, [$event])->assertJsonPath('results.0.status', 'conflict');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_the_agreed_price_is_ignored_unless_a_manager_approved_it(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        // No expected_total, no approval: a client-sent price must not be trusted.
        $this->push($user, $shop, [$this->saleEvent('e1', $product->id, 2, 2000, unitPrice: 1)])
            ->assertJsonPath('results.0.status', 'processed')
            ->assertJsonPath('results.0.total', 2000);
    }

    public function test_only_a_manager_can_sync_another_persons_sale_and_only_within_the_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashierA] = $this->shopWithMember(Role::Cashier, $shop);
        [$cashierB] = $this->shopWithMember(Role::Cashier, $shop);
        [$stranger] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner, price: 1000, stock: 10);

        $this->push($cashierA, $shop, [$this->saleEvent('e1', $product->id, 1, 1000) + ['cashier_id' => $cashierB->id]])
            ->assertJsonPath('results.0.status', 'rejected');

        $this->push($owner, $shop, [$this->saleEvent('e2', $product->id, 1, 1000) + ['cashier_id' => $stranger->id]])
            ->assertJsonPath('results.0.status', 'rejected');

        $this->push($owner, $shop, [$this->saleEvent('e3', $product->id, 1, 1000) + ['cashier_id' => $cashierB->id]])
            ->assertJsonPath('results.0.status', 'processed');

        $this->assertDatabaseCount('sales', 1);
        $this->assertSame($cashierB->id, Sale::firstOrFail()->cashier_id);
    }

    public function test_a_rejected_sale_can_be_retried_once_stock_is_fixed(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000, stock: 1);
        $event = $this->saleEvent('e1', $product->id, 3, 3000);

        $this->push($user, $shop, [$event])->assertJsonPath('results.0.status', 'rejected');

        StockMovement::create([
            'shop_id' => $shop->id, 'product_id' => $product->id, 'quantity_delta' => 5, 'unit_cost' => 600,
            'movement_type' => MovementType::Adjustment, 'performed_by' => $user->id,
        ]);

        $this->push($user, $shop, [$event])->assertJsonPath('results.0.status', 'processed');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_an_invalid_payload_is_rejected_per_event_without_failing_the_batch(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000);

        $this->push($user, $shop, [
            ['local_event_id' => 'bad', 'entity_type' => 'sale', 'operation' => 'create', 'payload' => ['items' => []]],
            $this->saleEvent('good', $product->id, 1, 1000),
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.1.status', 'processed');
    }

    public function test_events_are_scoped_to_the_device_and_shop(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $user, price: 1000, stock: 10);

        $this->push($user, $shop, [$this->saleEvent('e1', $product->id, 1, 1000)], 'device-A');
        // Same local id from a different device is a different event (and a different sale).
        $this->push($user, $shop, [$this->saleEvent('e1-b', $product->id, 1, 1000)], 'device-B')
            ->assertJsonPath('results.0.status', 'processed');

        $this->assertDatabaseCount('sales', 2);
    }

    public function test_the_sale_is_attributed_to_the_cashier_who_pushes_it(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000);

        $this->push($cashier, $shop, [$this->saleEvent('e1', $product->id, 1, 1000)])->assertOk();

        $this->assertSame($cashier->id, Sale::firstOrFail()->cashier_id);
    }

    public function test_an_outsider_cannot_push_to_a_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$outsider] = $this->shopWithMember();
        $product = $this->productWithStock($shop, $owner);

        $this->push($outsider, $shop, [$this->saleEvent('e1', $product->id)])->assertForbidden();
    }

    public function test_pull_returns_the_full_catalogue_then_only_what_changed(): void
    {
        [$user, $shop] = $this->shopWithMember();
        $a = $this->productWithStock($shop, $user, stock: 10, attributes: ['name' => 'A']);
        $b = $this->productWithStock($shop, $user, stock: 10, attributes: ['name' => 'B']);

        $full = $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/sync/pull')->assertOk()->assertJsonPath('full', true)->assertJsonCount(2, 'products');
        $cursor = $full->json('cursor');

        $this->travel(10)->seconds();
        StockMovement::create([
            'shop_id' => $shop->id, 'product_id' => $a->id, 'quantity_delta' => -3, 'unit_cost' => 600,
            'movement_type' => MovementType::Sale, 'performed_by' => $user->id,
        ]);
        $b->update(['status' => 'archived']);

        $delta = $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/sync/pull?cursor='.urlencode($cursor))
            ->assertOk()->assertJsonPath('full', false)->assertJsonCount(2, 'products');

        $byName = collect($delta->json('products'))->keyBy('name');
        $this->assertEquals(7, $byName['A']['stock']);
        $this->assertSame('archived', $byName['B']['status']);

        // The cursor backs off 2s so a change made mid-request is never missed;
        // re-sent products are harmless because devices merge by id.
        $this->travel(10)->seconds();
        $again = $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/sync/pull?cursor='.urlencode($delta->json('cursor')))
            ->assertJsonCount(2, 'products');

        $this->travel(10)->seconds();
        $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/sync/pull?cursor='.urlencode($again->json('cursor')))
            ->assertJsonCount(0, 'products');
    }

    public function test_pull_never_leaks_another_shops_products(): void
    {
        [$user, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $this->productWithStock($otherShop, $other, attributes: ['name' => 'Secret']);

        $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop))
            ->getJson('/api/sync/pull')->assertJsonCount(0, 'products');
    }
}
