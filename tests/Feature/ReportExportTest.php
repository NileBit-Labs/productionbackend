<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use CreatesShops;
    use RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    private function reportData(): array
    {
        [$owner, $shop] = $this->shopWithMember();
        [$manager] = $this->shopWithMember(Role::Manager, $shop);
        $product = $this->productWithStock($shop, $owner, price: 1000, cost: 600, stock: 3, attributes: ['name' => 'Our Product']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Customer Owing']);

        $this->api($owner, $shop)->postJson('/api/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [],
            'customer_id' => $customer->id,
        ])->assertCreated();
        Expense::create(['shop_id' => $shop->id, 'category' => 'Transport', 'amount' => 100, 'recorded_by' => $owner->id, 'expense_date' => $shop->today()]);

        return compact('owner', 'manager', 'shop', 'product');
    }

    public function test_an_owner_can_download_a_branded_pdf_for_the_current_shop_and_period(): void
    {
        ['owner' => $owner, 'shop' => $shop] = $this->reportData();

        $response = $this->api($owner, $shop)->get('/api/reports/export/pdf?from='.$shop->today().'&to='.$shop->today());

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('nilebit-pos-test-shop-summary-', (string) $response->headers->get('content-disposition'));
    }

    public function test_csv_has_clean_context_columns_and_uses_only_the_current_shop(): void
    {
        ['owner' => $owner, 'shop' => $shop] = $this->reportData();
        [$otherOwner, $otherShop] = $this->shopWithMember();
        $other = $this->productWithStock($otherShop, $otherOwner, price: 9000, stock: 1, attributes: ['name' => 'Other Shop Secret']);
        $this->api($otherOwner, $otherShop)->postJson('/api/sales', [
            'items' => [['product_id' => $other->id, 'quantity' => 1]],
            'payments' => [['method' => 'CASH', 'amount' => 9000]],
        ])->assertCreated();

        $response = $this->api($owner, $shop)->get('/api/reports/export/csv?report=sales&from='.$shop->today().'&to='.$shop->today());
        $csv = $response->streamedContent();

        $response->assertOk();
        $this->assertStringContainsString('Shop,Report,Period,"Generated at",Date,Sales', $csv);
        $this->assertStringContainsString('"Test Shop",Sales', $csv);
        $this->assertStringNotContainsString('Other Shop Secret', $csv);
        $this->assertStringContainsString('nilebit-pos-test-shop-sales-', (string) $response->headers->get('content-disposition'));
    }

    public function test_manager_export_never_contains_owner_cost_fields_and_cannot_export_profit(): void
    {
        ['manager' => $manager, 'shop' => $shop] = $this->reportData();

        $stock = $this->api($manager, $shop)->get('/api/reports/export/csv?report=stock');
        $stock->assertOk();
        $this->assertStringNotContainsString('Value at cost', $stock->streamedContent());
        $this->api($manager, $shop)->get('/api/reports/export/csv?report=profit')->assertForbidden();

        $pdf = $this->api($manager, $shop)->get('/api/reports/export/pdf');
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }
}
