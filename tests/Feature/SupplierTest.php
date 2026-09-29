<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    public function test_a_manager_adds_and_edits_a_supplier_and_it_is_audited(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $id = $this->api($owner, $shop)->postJson('/api/suppliers', ['name' => 'Kampala Wholesale', 'phone' => '0700 111 222'])
            ->assertCreated()->assertJsonPath('balance', 0)->assertJsonPath('is_active', true)->json('id');

        $this->api($owner, $shop)->patchJson("/api/suppliers/{$id}", ['phone' => '0700 999 000', 'notes' => 'Pays on Fridays'])
            ->assertOk()->assertJsonPath('phone', '0700 999 000');

        $this->assertSame(1, AuditLog::where('action', 'supplier.create')->count());
        $this->assertSame('0700 111 222', AuditLog::where('action', 'supplier.update')->first()->before_data['phone']);
    }

    public function test_supplier_names_are_unique_per_shop_ignoring_case(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        Supplier::create(['shop_id' => $shop->id, 'name' => 'Kampala Wholesale']);

        $this->api($owner, $shop)->postJson('/api/suppliers', ['name' => 'kampala wholesale '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->api($other, $otherShop)->postJson('/api/suppliers', ['name' => 'Kampala Wholesale'])->assertCreated();
    }

    public function test_a_supplier_can_be_deactivated_and_is_then_hidden_from_the_default_list(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Old Supplier']);
        Supplier::create(['shop_id' => $shop->id, 'name' => 'Current Supplier']);

        $this->api($owner, $shop)->patchJson("/api/suppliers/{$supplier->id}", ['is_active' => false])->assertOk();

        $this->api($owner, $shop)->getJson('/api/suppliers')->assertJsonPath('suppliers.total', 1);
        $this->api($owner, $shop)->getJson('/api/suppliers?status=all')->assertJsonPath('suppliers.total', 2);
    }

    public function test_the_list_summarises_what_is_owed_and_can_be_filtered_to_who_is_owed(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $owed = Supplier::create(['shop_id' => $shop->id, 'name' => 'Owed']);
        Supplier::create(['shop_id' => $shop->id, 'name' => 'Settled']);
        $product = $this->productWithStock($shop, $owner, stock: 0);

        $this->api($owner, $shop)->postJson('/api/purchases', [
            'supplier_id' => $owed->id, 'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 2500]],
        ])->assertCreated();

        $this->api($owner, $shop)->getJson('/api/suppliers')
            ->assertJsonPath('summary.suppliers', 2)->assertJsonPath('summary.owing', 1)->assertJsonPath('summary.total_owed', 10000);

        $this->api($owner, $shop)->getJson('/api/suppliers?owing=1')
            ->assertJsonPath('suppliers.total', 1)->assertJsonPath('suppliers.data.0.name', 'Owed')->assertJsonPath('suppliers.data.0.balance', 10000);
    }

    public function test_the_ledger_lists_purchases_and_payments_newest_first(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $supplier = Supplier::create(['shop_id' => $shop->id, 'name' => 'Ledger Co']);
        $product = $this->productWithStock($shop, $owner, stock: 0);

        $this->api($owner, $shop)->postJson('/api/purchases', [
            'supplier_id' => $supplier->id, 'items' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 2500]],
        ])->assertCreated();
        $this->api($owner, $shop)->postJson("/api/suppliers/{$supplier->id}/payments", ['amount' => 4000, 'method' => 'MOBILE_MONEY', 'reference' => 'MM123'])->assertOk();

        $this->api($owner, $shop)->getJson("/api/suppliers/{$supplier->id}/ledger")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'PAYMENT')->assertJsonPath('data.0.amount', -4000)->assertJsonPath('data.0.note', 'Mobile Money · MM123')
            ->assertJsonPath('data.1.type', 'PURCHASE')->assertJsonPath('data.1.amount', 10000)->assertJsonPath('data.1.reference.type', 'Purchase');

        $this->api($owner, $shop)->getJson("/api/suppliers/{$supplier->id}")
            ->assertJsonPath('balance', 6000)->assertJsonPath('open_purchases.0.owed', 6000);
    }

    public function test_a_supplier_needs_a_name_and_a_valid_email(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->api($owner, $shop)->postJson('/api/suppliers', ['name' => '', 'email' => 'not-an-email'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
    }
}
