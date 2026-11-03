<?php

namespace Tests\Feature;

use App\Models\ProductionBatch;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShops;
use Tests\TestCase;

class BatchRevisionTest extends TestCase
{
    use CreatesShops, RefreshDatabase;

    public function test_stale_completion_is_refused_before_any_stock_changes(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $batch = $this->postJson('/api/production/batches', ['name' => 'Juice batch'])->assertCreated()->json();
        $this->assertSame(1, $batch['revision']);
        $this->patchJson('/api/production/batches/'.$batch['id'], ['notes' => 'Changed on web'])->assertOk()->assertJsonPath('revision', 2);
        $before = StockMovement::count();

        $this->postJson('/api/production/batches/'.$batch['id'].'/complete', ['expected_revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->assertSame($before, StockMovement::count());
        $this->assertDatabaseHas('production_batches', ['id' => $batch['id'], 'status' => 'draft', 'revision' => 2]);
    }

    public function test_stale_draft_edit_and_cancellation_do_not_overwrite_web_changes(): void
    {
        [$owner, $shop] = $this->shopWithMember();
        $this->actingAs($owner, 'sanctum')->withHeaders($this->shopHeader($shop));
        $id = $this->postJson('/api/production/batches', ['name' => 'Juice batch'])->assertCreated()->json('id');
        $this->patchJson('/api/production/batches/'.$id, ['notes' => 'Current notes'])->assertOk();

        $this->patchJson('/api/production/batches/'.$id, ['expected_revision' => 1, 'notes' => 'Stale notes'])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->postJson('/api/production/batches/'.$id.'/cancel', ['expected_revision' => 1, 'reason' => 'Stale cancel'])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->assertSame('Current notes', ProductionBatch::findOrFail($id)->notes);
        $this->postJson('/api/production/batches/'.$id.'/cancel', ['expected_revision' => 2, 'reason' => 'Owner cancelled'])->assertOk()->assertJsonPath('revision', 3);
    }
}
