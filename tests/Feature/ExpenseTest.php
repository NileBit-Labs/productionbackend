<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    private function api($user, $shop)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->shopHeader($shop));
    }

    public function test_a_manager_records_an_expense_against_themselves(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        $this->api($owner, $shop)->postJson('/api/expenses', [
            'category' => 'Rent', 'amount' => 500000, 'expense_date' => now()->toDateString(), 'description' => 'September',
        ])->assertCreated()->assertJsonPath('recorded_by', $owner->id)->assertJsonPath('recorder.name', $owner->name);
    }

    public function test_just_after_midnight_in_kampala_todays_expense_is_accepted_and_listed(): void
    {
        [$owner, $shop] = $this->shopWithMember();

        // 00:30 on the 1st in Kampala is still the last day of the previous month in UTC.
        $this->travelTo(Carbon::parse('2026-10-01 00:30:00', 'Africa/Kampala'));

        $this->api($owner, $shop)->postJson('/api/expenses', ['category' => 'Rent', 'amount' => 900, 'expense_date' => '2026-10-01'])->assertCreated();
        $this->api($owner, $shop)->postJson('/api/expenses', ['category' => 'Rent', 'amount' => 900, 'expense_date' => '2026-10-02'])
            ->assertUnprocessable()->assertJsonValidationErrors('expense_date');

        // The default "this month" is October on the shop's calendar, so it includes the expense.
        $this->api($owner, $shop)->getJson('/api/expenses')->assertOk()->assertJsonPath('total', 900);
    }

    public function test_cashiers_have_no_access_to_expenses(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$cashier] = $this->shopWithMember(Role::Cashier, $shop);

        $this->api($cashier, $shop)->getJson('/api/expenses')->assertForbidden();
        $this->api($cashier, $shop)->postJson('/api/expenses', ['category' => 'Rent', 'amount' => 1, 'expense_date' => now()->toDateString()])->assertForbidden();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_the_list_defaults_to_this_month_and_totals_by_category(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $make = fn (string $category, int $amount, string $date) => Expense::create([
            'shop_id' => $shop->id, 'category' => $category, 'amount' => $amount, 'expense_date' => $date, 'recorded_by' => $owner->id,
        ]);

        $make('Rent', 500000, now()->startOfMonth()->toDateString());
        $make('Transport', 20000, now()->toDateString());
        $make('Transport', 15000, now()->toDateString());
        $make('Rent', 999999, now()->subMonths(2)->toDateString());

        $response = $this->api($owner, $shop)->getJson('/api/expenses')->assertOk();
        $response->assertJsonPath('total', 535000)->assertJsonCount(3, 'data')
            ->assertJsonPath('by_category.0.category', 'Rent')->assertJsonPath('by_category.0.total', 500000)
            ->assertJsonPath('by_category.1.total', 35000);

        $this->api($owner, $shop)->getJson('/api/expenses?category=Transport')->assertJsonPath('total', 35000);
        $this->api($owner, $shop)->getJson('/api/expenses?from='.now()->subMonths(3)->toDateString())->assertJsonPath('total', 1534999);
    }

    public function test_an_expense_cannot_be_dated_in_the_future_or_be_zero(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->travelTo(Carbon::parse('2026-09-21 09:00:00', 'Africa/Kampala'));

        $this->api($owner, $shop)->postJson('/api/expenses', ['category' => 'Rent', 'amount' => 1000, 'expense_date' => '2026-09-22'])
            ->assertUnprocessable()->assertJsonValidationErrors('expense_date');
        $this->api($owner, $shop)->postJson('/api/expenses', ['category' => 'Rent', 'amount' => 0, 'expense_date' => '2026-09-21'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_editing_an_expense_is_audit_logged_with_before_and_after(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $id = $this->api($owner, $shop)->postJson('/api/expenses', ['category' => 'Rent', 'amount' => 500000, 'expense_date' => now()->toDateString()])->json('id');

        $this->api($owner, $shop)->patchJson("/api/expenses/{$id}", ['amount' => 450000])->assertOk()->assertJsonPath('amount', 450000);

        $log = AuditLog::where('action', 'expense.update')->firstOrFail();
        $this->assertSame(500000, $log->before_data['amount']);
        $this->assertSame(450000, $log->after_data['amount']);
    }

    public function test_an_expense_from_another_shop_cannot_be_edited(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        [$other, $otherShop] = $this->shopWithMember();
        $id = $this->api($other, $otherShop)->postJson('/api/expenses', ['category' => 'Rent', 'amount' => 1000, 'expense_date' => now()->toDateString()])->json('id');

        $this->api($owner, $shop)->patchJson("/api/expenses/{$id}", ['amount' => 1])->assertNotFound();
        $this->assertSame(1000, Expense::find($id)->amount);
    }
}
