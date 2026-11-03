<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class OperationReplayTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_customer_creation_replay_returns_the_same_record_once(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop) + ['X-NileBit-Operation' => 'wa-operation-1234']);
        $first = $this->postJson('/api/customers', ['name' => 'Sarah', 'phone' => null])->assertCreated();
        $this->postJson('/api/customers', ['phone' => null, 'name' => 'Sarah'])->assertCreated()
            ->assertHeader('X-NileBit-Replayed', 'true')->assertJsonPath('id', $first->json('id'));

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('pos_operation_requests', 1);
        $this->assertStringNotContainsString('Sarah', DB::table('pos_operation_requests')->sole()->response_body);
    }

    public function test_changed_payload_or_actor_cannot_reuse_an_operation_key(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop) + ['X-NileBit-Operation' => 'wa-operation-1234']);
        $this->postJson('/api/customers', ['name' => 'Sarah'])->assertCreated();
        $this->postJson('/api/customers', ['name' => 'Peter'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->actingAs($cashier, 'sanctum');
        $this->postJson('/api/customers', ['name' => 'Sarah'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_validation_failure_does_not_consume_the_operation_key(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop) + ['X-NileBit-Operation' => 'wa-operation-1234']);
        $this->postJson('/api/customers', ['name' => ''])->assertUnprocessable();
        $this->assertDatabaseCount('pos_operation_requests', 0);
        $this->postJson('/api/customers', ['name' => 'Sarah'])->assertCreated();

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_revoked_access_is_checked_before_a_cached_response_is_returned(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop) + ['X-NileBit-Operation' => 'wa-operation-1234']);
        $this->postJson('/api/customers', ['name' => 'Sarah'])->assertCreated();
        $shop->update(['status' => 'inactive']);

        $this->postJson('/api/customers', ['name' => 'Sarah'])->assertForbidden();
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_the_same_operation_key_in_another_shop_does_not_leak_a_record(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop) + ['X-NileBit-Operation' => 'wa-operation-1234']);
        $first = $this->postJson('/api/customers', ['name' => 'Sarah'])->assertCreated()->json('id');
        $this->actingAs($other, 'sanctum')->withHeaders($this->shopHeader($otherShop));
        $second = $this->postJson('/api/customers', ['name' => 'Peter'])->assertCreated()->json('id');

        $this->assertNotSame($first, $second);
        $this->assertSame($otherShop->id, Customer::findOrFail($second)->shop_id);
    }

    public function test_operation_lookup_returns_the_receipt_only_to_its_actor_in_the_same_shop(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop) + ['X-NileBit-Operation' => 'wa-operation-1234']);
        $id = $this->postJson('/api/customers', ['name' => 'Sarah'])->assertCreated()->json('id');
        $this->getJson('/api/bot/operations/wa-operation-1234')->assertOk()->assertJsonPath('recorded', true)->assertJsonPath('result.id', $id);

        $this->actingAs($cashier, 'sanctum');
        $this->getJson('/api/bot/operations/wa-operation-1234')->assertNotFound();
        $this->assertDatabaseCount('customers', 1);
    }
}
