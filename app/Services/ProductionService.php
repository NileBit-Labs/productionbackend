<?php

namespace App\Services;

use App\Enums\BatchStatus;
use App\Enums\ExpenseType;
use App\Enums\MovementType;
use App\Enums\ProductKind;
use App\Enums\WastageStage;
use App\Models\Expense;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchInput;
use App\Models\ProductionBatchOutput;
use App\Models\ProductionLot;
use App\Models\Recipe;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserShopRole;
use App\Models\WastageRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning inputs into finished goods.
 *
 * A batch is planned as a draft (usually from a recipe), then completed with what was
 * really used and made. Completion is one transaction: inputs and in-process wastage
 * leave stock, outputs arrive, and the batch's cost is frozen and shared across its
 * outputs. Stock still only moves through StockService.
 *
 * Batch cost = materials + packaging + direct labour + direct production expenses
 *              (+ inputs wasted during the batch, which the batch caused).
 * Unit cost  = the output's share of that cost / its quantity, where the share is by
 *              quantity x output equivalent (how much of the yield one unit holds).
 */
class ProductionService
{
    public function __construct(
        private StockService $stock,
        private ProductionLotService $lots,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     * @return array{batch: ProductionBatch, replayed: bool}
     */
    public function plan(Shop $shop, User $by, array $data): array
    {
        $key = $data['idempotency_key'] ?? null;

        if ($key && ($existing = $this->findByKey($shop, $key))) {
            return ['batch' => $existing, 'replayed' => true];
        }

        try {
            $batch = DB::transaction(fn () => $this->createDraft($shop, $by, $data, $key));
        } catch (UniqueConstraintViolationException $e) {
            if ($key && ($existing = $this->findByKey($shop, $key))) {
                return ['batch' => $existing, 'replayed' => true];
            }

            throw $e;
        }

        return ['batch' => $batch, 'replayed' => false];
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(Shop $shop, User $by, int $batchId, array $data): ProductionBatch
    {
        return DB::transaction(function () use ($shop, $by, $batchId, $data) {
            $batch = $this->lockBatch($shop, $batchId);
            $this->assertDraft($batch);

            $before = $batch->only(['name', 'production_date', 'expiry_date', 'planned_yield', 'yield_unit', 'responsible_user_id', 'notes']);

            if (array_key_exists('responsible_user_id', $data)) {
                $this->assertMember($shop, $data['responsible_user_id']);
            }

            $batch->fill(array_intersect_key($data, array_flip(['name', 'production_date', 'expiry_date', 'planned_yield', 'yield_unit', 'responsible_user_id', 'notes'])));

            // A new planned yield rescales the recipe unless the caller also sent their own inputs.
            if ($batch->isDirty('planned_yield') && ! array_key_exists('inputs', $data) && $batch->recipe_id) {
                $this->replaceInputs($shop, $batch, $this->scaledRecipe($batch->recipe()->with('items')->first(), (float) $batch->planned_yield));
            }

            $batch->draft_payload = array_intersect_key($data, array_flip(['direct_expenses', 'wastage'])) + ($batch->draft_payload ?? []);
            if ($batch->expiry_date && $batch->expiry_date->lt($batch->production_date)) {
                throw ValidationException::withMessages(['expiry_date' => 'Expiry cannot be before production.']);
            }
            $batch->save();

            if (array_key_exists('inputs', $data)) {
                $this->replaceInputs($shop, $batch, $data['inputs'] ?? []);
            }

            if (array_key_exists('outputs', $data)) {
                $this->replaceOutputs($shop, $batch, $data['outputs'] ?? []);
            }

            $this->audit->record($by, $shop, 'production.update', $batch, $before, $batch->only(array_keys($before)));

            return $batch;
        });
    }

    /**
     * Records what was really used and made, moves the stock and freezes the cost.
     *
     * @param  array<string, mixed>  $data  validated request data
     */
    public function complete(Shop $shop, User $by, int $batchId, array $data): ProductionBatch
    {
        return DB::transaction(function () use ($shop, $by, $batchId, $data) {
            $batch = $this->lockBatch($shop, $batchId);
            if (! empty($data['idempotency_key']) && $batch->status === BatchStatus::Completed && $batch->completion_idempotency_key === $data['idempotency_key']) {
                return $batch;
            }
            $this->assertDraft($batch);
            $data += $batch->draft_payload ?? [];

            $batch->fill(array_intersect_key($data, array_flip(['production_date', 'expiry_date', 'notes'])));

            if (array_key_exists('inputs', $data)) {
                $this->replaceInputs($shop, $batch, $data['inputs'], actual: true);
            }

            if (array_key_exists('outputs', $data)) {
                $this->replaceOutputs($shop, $batch, $data['outputs']);
            }

            $inputs = $batch->inputs()->get();
            $outputs = $batch->outputs()->get();
            $wastage = collect($data['wastage'] ?? []);

            if ($outputs->isEmpty()) {
                throw ValidationException::withMessages(['outputs' => 'Record at least one finished product this batch made.']);
            }

            if ($both = $inputs->pluck('product_id')->intersect($outputs->pluck('product_id'))->first()) {
                throw ValidationException::withMessages(['outputs' => 'A product can\'t be both an input and an output of the same batch (product #'.$both.').']);
            }

            // Unless told otherwise, what was used is what was planned.
            foreach ($inputs as $input) {
                if ($input->actual_quantity === null) {
                    $input->actual_quantity = (float) $input->planned_quantity;
                }
            }

            $ids = $inputs->pluck('product_id')->merge($outputs->pluck('product_id'))->merge($wastage->pluck('product_id'))
                ->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $products = Product::where('shop_id', $shop->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($wastage as $i => $line) {
                if (! $products->has((int) $line['product_id'])) {
                    throw ValidationException::withMessages(["wastage.$i.product_id" => 'Choose a product from this shop.']);
                }
            }

            $this->assertEnoughStock($shop, $products, $inputs, $wastage);

            $date = $batch->production_date->toDateString();

            foreach ($data['direct_expenses'] ?? [] as $expense) {
                Expense::create([
                    'shop_id' => $shop->id,
                    'category' => $expense['category'],
                    'type' => $expense['type'],
                    'production_batch_id' => $batch->id,
                    'amount' => (int) $expense['amount'],
                    'description' => $expense['description'] ?? null,
                    'recorded_by' => $by->id,
                    'expense_date' => $date,
                ]);
            }

            $material = 0;
            $packaging = 0;

            foreach ($inputs as $input) {
                $product = $products[$input->product_id];
                $input->unit_cost = $product->current_cost;
                $input->line_cost = (int) round($input->actual_quantity * $product->current_cost);
                $input->save();

                if ($product->kindOrDefault() === ProductKind::Packaging) {
                    $packaging += $input->line_cost;
                } else {
                    $material += $input->line_cost;
                }

                if ($input->actual_quantity > 0) {
                    $this->stock->record($product, -$input->actual_quantity, MovementType::ProductionInput, $by, $batch, "Used in {$batch->batch_number}", $product->current_cost);
                }
            }

            $wastageCost = 0;

            foreach ($wastage as $line) {
                $product = $products[(int) $line['product_id']];
                $quantity = round((float) $line['quantity'], 3);
                $movement = $this->stock->record($product, -$quantity, MovementType::Wastage, $by, $batch, $line['reason'], $product->current_cost);
                $cost = (int) round($quantity * $product->current_cost);
                $wastageCost += $cost;

                WastageRecord::create([
                    'shop_id' => $shop->id,
                    'product_id' => $product->id,
                    'production_batch_id' => $batch->id,
                    'stage' => WastageStage::Production,
                    'quantity' => $quantity,
                    'unit_cost' => $product->current_cost,
                    'total_cost' => $cost,
                    'reason' => $line['reason'],
                    'wastage_date' => $date,
                    'stock_movement_id' => $movement->id,
                    'recorded_by' => $by->id,
                ]);
            }

            $direct = Expense::where('production_batch_id', $batch->id)->get();
            $labour = (int) $direct->where('type', ExpenseType::DirectLabour)->sum('amount');
            $directProduction = (int) $direct->where('type', ExpenseType::DirectProduction)->sum('amount');
            $total = $material + $packaging + $labour + $directProduction + $wastageCost;

            $outputQuantity = $this->allocate($outputs, $products, $total);

            foreach ($outputs as $output) {
                $product = $products[$output->product_id];
                $stockBefore = $this->stock->current($shop->id, $product->id);

                $output->expiry_date ??= $batch->expiry_date
                    ?? ($product->shelf_life_days ? CarbonImmutable::parse($date)->addDays($product->shelf_life_days)->toDateString() : null);

                $this->stock->record($product, $output->quantity, MovementType::ProductionOutput, $by, $batch, "Made in {$batch->batch_number}", $output->unit_cost);

                // Weighted average, as for a purchase: stock already on the shelf blended with what was just made.
                $costBefore = $product->current_cost;
                $costAfter = $stockBefore > 0
                    ? (int) round(($stockBefore * $costBefore + $output->quantity * $output->unit_cost) / ($stockBefore + $output->quantity))
                    : $output->unit_cost;

                $product->update(['current_cost' => $costAfter]);
                $output->fill(['cost_before' => $costBefore, 'cost_after' => $costAfter])->save();
                $this->lots->create($shop, $batch, $output);
            }

            $batch->fill([
                'status' => BatchStatus::Completed,
                'material_cost' => $material,
                'packaging_cost' => $packaging,
                'labour_cost' => $labour,
                'direct_expense_cost' => $directProduction,
                'wastage_cost' => $wastageCost,
                'total_cost' => $total,
                'output_quantity' => $outputQuantity,
                'completed_at' => now(),
                'completed_by' => $by->id,
                'completion_idempotency_key' => $data['idempotency_key'] ?? null,
            ])->save();

            $this->audit->record($by, $shop, 'production.complete', $batch, ['status' => BatchStatus::Draft->value], [
                'status' => BatchStatus::Completed->value,
                'batch_number' => $batch->batch_number,
                'total_cost' => $total,
                'output_quantity' => $outputQuantity,
                'inputs' => $inputs->count(),
                'outputs' => $outputs->count(),
            ]);

            return $batch;
        });
    }

    /**
     * A draft is simply abandoned. A completed batch is reversed - inputs back on the shelf,
     * outputs taken off - but only while all of its output is still in stock.
     */
    public function cancel(Shop $shop, User $by, int $batchId, string $reason): ProductionBatch
    {
        return DB::transaction(function () use ($shop, $by, $batchId, $reason) {
            $batch = $this->lockBatch($shop, $batchId);

            if ($batch->status === BatchStatus::Cancelled) {
                throw ValidationException::withMessages(['batch' => 'This batch is already cancelled.']);
            }

            $wasCompleted = $batch->status === BatchStatus::Completed;

            if ($wasCompleted) {
                $this->reverse($shop, $by, $batch, $reason);
            }

            // The money was still spent: it stops being part of a batch cost and becomes an ordinary expense.
            $moved = Expense::where('production_batch_id', $batch->id)->where('type', '!=', ExpenseType::Operating)
                ->update(['type' => ExpenseType::Operating]);

            $batch->update([
                'status' => BatchStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ]);

            $this->audit->record($by, $shop, 'production.cancel', $batch, ['status' => $wasCompleted ? 'completed' : 'draft'], [
                'status' => BatchStatus::Cancelled->value,
                'reason' => $reason,
                'expenses_made_operating' => $moved,
            ]);

            return $batch;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function createDraft(Shop $shop, User $by, array $data, ?string $key): ProductionBatch
    {
        $recipe = null;

        if (! empty($data['recipe_id'])) {
            $recipe = Recipe::where('shop_id', $shop->id)->with('items')->find($data['recipe_id']);

            if (! $recipe) {
                throw ValidationException::withMessages(['recipe_id' => 'Choose a recipe from this shop.']);
            }

            if ($recipe->status !== 'active') {
                throw ValidationException::withMessages(['recipe_id' => 'This recipe is archived. Restore it before producing from it.']);
            }
        }

        if (! $recipe && empty($data['name'])) {
            throw ValidationException::withMessages(['name' => 'Give the batch a name, or choose a recipe.']);
        }

        if (! empty($data['responsible_user_id'])) {
            $this->assertMember($shop, $data['responsible_user_id']);
        }

        DB::table('shops')->where('id', $shop->id)->increment('batch_counter');
        $number = DB::table('shops')->where('id', $shop->id)->value('batch_counter');

        $plannedYield = $data['planned_yield'] ?? $recipe?->yield_quantity;

        $batch = ProductionBatch::create([
            'shop_id' => $shop->id,
            'batch_number' => sprintf('B-%06d', $number),
            'recipe_id' => $recipe?->id,
            'draft_payload' => array_intersect_key($data, array_flip(['direct_expenses', 'wastage'])),
            'name' => $data['name'] ?? $recipe->name,
            'status' => BatchStatus::Draft,
            'production_date' => $data['production_date'] ?? $shop->today(),
            'expiry_date' => $data['expiry_date'] ?? null,
            'planned_yield' => $plannedYield,
            'yield_unit' => $recipe?->yield_unit ?? ($data['yield_unit'] ?? null),
            'responsible_user_id' => $data['responsible_user_id'] ?? $by->id,
            'notes' => $data['notes'] ?? null,
            'created_by' => $by->id,
            'idempotency_key' => $key,
        ]);

        $inputs = $data['inputs'] ?? ($recipe ? $this->scaledRecipe($recipe, (float) $plannedYield) : []);
        $this->replaceInputs($shop, $batch, $inputs);
        $this->replaceOutputs($shop, $batch, $data['outputs'] ?? []);

        $this->audit->record($by, $shop, 'production.plan', $batch, null, [
            'batch_number' => $batch->batch_number,
            'recipe_id' => $recipe?->id,
            'planned_yield' => $plannedYield,
        ]);

        return $batch;
    }

    /**
     * The recipe's ingredients for the given yield: a 100 L recipe planned at 250 L needs 2.5x each.
     *
     * @return array<int, array{product_id: int, planned_quantity: float}>
     */
    private function scaledRecipe(Recipe $recipe, float $plannedYield): array
    {
        $factor = $recipe->yield_quantity > 0 && $plannedYield > 0 ? $plannedYield / $recipe->yield_quantity : 1.0;

        return $recipe->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'planned_quantity' => round($item->quantity * $factor, 3),
        ])->all();
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function replaceInputs(Shop $shop, ProductionBatch $batch, array $lines, bool $actual = false): void
    {
        $this->shopProducts($shop, $lines, 'inputs');
        $existing = $batch->inputs()->get()->keyBy('product_id');

        $batch->inputs()->delete();

        foreach ($lines as $line) {
            $productId = (int) $line['product_id'];
            $planned = $line['planned_quantity'] ?? $existing->get($productId)?->planned_quantity;

            ProductionBatchInput::create([
                'production_batch_id' => $batch->id,
                'product_id' => $productId,
                'planned_quantity' => $planned !== null ? round((float) $planned, 3) : null,
                'actual_quantity' => $actual
                    ? round((float) ($line['actual_quantity'] ?? $planned ?? 0), 3)
                    : (isset($line['actual_quantity']) ? round((float) $line['actual_quantity'], 3) : null),
            ]);
        }
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function replaceOutputs(Shop $shop, ProductionBatch $batch, array $lines): void
    {
        $products = $this->shopProducts($shop, $lines, 'outputs');
        $inputIds = $batch->inputs()->pluck('product_id')->map(fn ($id) => (int) $id)->all();

        foreach ($lines as $i => $line) {
            $product = $products[(int) $line['product_id']];

            if ($product->kindOrDefault() !== ProductKind::FinishedGood) {
                throw ValidationException::withMessages(["outputs.$i.product_id" => "{$product->name} is not a finished product. Only finished products can be made by a batch."]);
            }

            if (in_array($product->id, $inputIds, true)) {
                throw ValidationException::withMessages(["outputs.$i.product_id" => "{$product->name} is already an input of this batch."]);
            }
        }

        $batch->outputs()->delete();

        foreach ($lines as $line) {
            ProductionBatchOutput::create([
                'production_batch_id' => $batch->id,
                'product_id' => (int) $line['product_id'],
                'quantity' => round((float) $line['quantity'], 3),
                'output_equivalent' => $line['output_equivalent'] ?? $products[(int) $line['product_id']]->output_equivalent ?? 0,
                'expiry_date' => $line['expiry_date'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<int, Product>
     */
    private function shopProducts(Shop $shop, array $lines, string $field): Collection
    {
        $products = Product::where('shop_id', $shop->id)
            ->whereIn('id', collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id))
            ->get()->keyBy('id');

        $seen = [];

        foreach ($lines as $i => $line) {
            $product = $products->get((int) $line['product_id']);

            if (! $product) {
                throw ValidationException::withMessages(["$field.$i.product_id" => 'Choose a product from this shop.']);
            }

            if ($product->status !== 'active') {
                throw ValidationException::withMessages(["$field.$i.product_id" => "{$product->name} is archived. Restore it first."]);
            }

            if (isset($seen[$product->id])) {
                throw ValidationException::withMessages(["$field.$i.product_id" => "{$product->name} is listed twice."]);
            }

            $seen[$product->id] = true;
        }

        return $products;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductionBatchInput>  $inputs
     * @param  Collection<int, array<string, mixed>>  $wastage
     */
    private function assertEnoughStock(Shop $shop, Collection $products, Collection $inputs, Collection $wastage): void
    {
        $needed = [];

        foreach ($inputs as $input) {
            $needed[$input->product_id] = ($needed[$input->product_id] ?? 0) + (float) $input->actual_quantity;
        }

        foreach ($wastage as $line) {
            $needed[(int) $line['product_id']] = ($needed[(int) $line['product_id']] ?? 0) + (float) $line['quantity'];
        }

        $levels = $this->stock->currentFor($shop->id, array_keys($needed));
        $short = [];

        foreach ($needed as $productId => $quantity) {
            $onHand = $levels[$productId] ?? 0.0;

            if ($quantity > 0 && $onHand + 0.0005 < $quantity) {
                $product = $products[$productId];
                $short[] = "{$product->name}: need {$quantity} {$product->base_unit}, only {$onHand} in stock";
            }
        }

        if ($short !== []) {
            throw ValidationException::withMessages(['inputs' => 'Not enough stock to complete this batch. '.implode('; ', $short).'.']);
        }
    }

    /**
     * Shares the batch cost across its outputs by quantity x output equivalent. Shares are whole
     * shillings and always add up to the total exactly (largest remainder goes first).
     *
     * @param  Collection<int, ProductionBatchOutput>  $outputs
     * @param  Collection<int, Product>  $products
     * @return float the saleable output, in the yield unit
     */
    private function allocate(Collection $outputs, Collection $products, int $total): float
    {
        $single = $outputs->count() === 1;
        $weights = [];

        foreach ($outputs->values() as $i => $output) {
            $equivalent = (float) $output->output_equivalent;

            if ($equivalent <= 0) {
                if (! $single) {
                    $name = $products[$output->product_id]->name;

                    throw ValidationException::withMessages(["outputs.$i.output_equivalent" => "Say how much of the batch one {$name} holds (its output equivalent), so the cost can be shared between sizes."]);
                }

                $equivalent = 1.0;
                $output->output_equivalent = 1.0;
            }

            $weights[$output->id] = $output->quantity * $equivalent;
        }

        $sum = array_sum($weights);

        if ($sum <= 0) {
            throw ValidationException::withMessages(['outputs' => 'The batch made nothing to sell.']);
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $id => $weight) {
            $exact = $total * $weight / $sum;
            $shares[$id] = (int) floor($exact);
            $remainders[$id] = $exact - $shares[$id];
        }

        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, $total - array_sum($shares)) as $id) {
            $shares[$id]++;
        }

        foreach ($outputs as $output) {
            $output->allocated_cost = $shares[$output->id];
            $output->unit_cost = (int) round($shares[$output->id] / $output->quantity);
        }

        return round($sum, 3);
    }

    private function reverse(Shop $shop, User $by, ProductionBatch $batch, string $reason): void
    {
        $outputs = $batch->outputs()->get();
        $inputs = $batch->inputs()->get();
        $wastage = $batch->wastage()->get();

        $ids = $outputs->pluck('product_id')->merge($inputs->pluck('product_id'))->merge($wastage->pluck('product_id'))->unique()->sort()->values();
        $products = Product::where('shop_id', $shop->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Product-level stock cannot prove that this batch's own output still exists:
        // later production or a purchase may have replenished the same SKU. Lots can.
        foreach ($outputs as $output) {
            $lot = ProductionLot::where('production_batch_output_id', $output->id)->lockForUpdate()->first();
            if (! $lot) {
                throw ValidationException::withMessages(['batch' => 'This legacy batch has no lot ledger and cannot be safely cancelled.']);
            }
            $this->lots->assertWholeLotAvailable($lot);
        }

        $note = "{$batch->batch_number} cancelled: {$reason}";

        foreach ($outputs as $output) {
            $this->stock->record($products[$output->product_id], -$output->quantity, MovementType::ProductionReversal, $by, $batch, $note, $output->unit_cost);
            $lot = ProductionLot::where('production_batch_output_id', $output->id)->lockForUpdate()->firstOrFail();
            $this->lots->consumeLot($lot, $output->quantity, $by, 'PRODUCTION_REVERSAL', $batch, $note);
        }

        foreach ($outputs->sortByDesc('id') as $output) {
            $product = $products[$output->product_id];

            if ($output->cost_before !== null && $product->current_cost === $output->cost_after) {
                $product->update(['current_cost' => $output->cost_before]);
            }
        }

        $returns = [];

        foreach ($inputs as $input) {
            $returns[] = [$input->product_id, (float) $input->actual_quantity, $input->unit_cost];
        }

        foreach ($wastage as $record) {
            $returns[] = [$record->product_id, (float) $record->quantity, $record->unit_cost];
        }

        foreach ($returns as [$productId, $quantity, $unitCost]) {
            if ($quantity <= 0) {
                continue;
            }

            $product = $products[$productId];
            $stockBefore = $this->stock->current($shop->id, $product->id);
            $this->stock->record($product, $quantity, MovementType::ProductionReversal, $by, $batch, $note, $unitCost);

            if ($stockBefore > 0 && $product->current_cost !== $unitCost) {
                $product->update(['current_cost' => (int) round(($stockBefore * $product->current_cost + $quantity * $unitCost) / ($stockBefore + $quantity))]);
            } elseif ($stockBefore <= 0) {
                $product->update(['current_cost' => $unitCost]);
            }
        }
    }

    private function lockBatch(Shop $shop, int $batchId): ProductionBatch
    {
        return ProductionBatch::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($batchId);
    }

    private function assertDraft(ProductionBatch $batch): void
    {
        if ($batch->status !== BatchStatus::Draft) {
            throw ValidationException::withMessages(['batch' => "{$batch->batch_number} is {$batch->status->value} and can no longer be changed."]);
        }
    }

    private function assertMember(Shop $shop, ?int $userId): void
    {
        if ($userId && ! UserShopRole::where('shop_id', $shop->id)->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['responsible_user_id' => 'Choose someone who works in this shop.']);
        }
    }

    private function findByKey(Shop $shop, string $key): ?ProductionBatch
    {
        return ProductionBatch::where('shop_id', $shop->id)->where('idempotency_key', $key)->first();
    }
}
