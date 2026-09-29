# NileBit POS for Production — Backend API (production engine)

For Collins (frontend) and Douglas (integration/QA). This covers everything added on top of the
inherited Retail API. All inherited endpoints keep working as before, except for the changes listed
under **Changes to existing endpoints**.

All endpoints below:

- need `Authorization: Bearer <token>` and `X-Shop-Id: <shop id>`;
- are **owner/manager only** unless stated (cashiers get `403`);
- use integer UGX for money and up to 3 decimals for quantities;
- return `422` with `{ message, errors: { field: [..] } }` for business-rule failures, as the Retail API does.

## Core ideas

| Concept | How it is modelled |
|---|---|
| Raw material / packaging / finished good | One `products` table with a `kind`: `raw_material`, `packaging`, `finished_good`. Inputs reuse purchases, suppliers, stock and low-stock alerts unchanged. |
| Sizes (300ml, 500ml, 1L) | Each size is its own `finished_good` product (own stock, price, cost), grouped by `family`, with a `size_label`. |
| `output_equivalent` | How much of a batch's yield one unit holds, in the recipe's yield unit (1L bottle = `1`, 300ml = `0.3`, a loaf = `1`). Batch cost is shared between sizes in proportion to quantity × output equivalent. |
| Stock | Still the append-only `stock_movements` ledger. New movement types: `PRODUCTION_INPUT`, `PRODUCTION_OUTPUT`, `PRODUCTION_REVERSAL`, `WASTAGE`. |
| Cost | Finished goods get their real unit cost when a batch completes (weighted average with stock already on hand). The inherited sale flow freezes that cost on each sale line, so **COGS and gross profit need no extra work**. |

### Costing rules (implemented in the backend)

```
Batch cost      = materials + packaging + direct labour + direct production expenses (+ inputs wasted inside the batch)
Unit cost       = output's share of batch cost / output quantity
COGS            = sum of unit cost of finished units actually sold (frozen per sale line)
Gross profit    = net sales - COGS
Net profit      = gross profit - operating expenses - wastage outside batches
```

Direct expenses are **never** counted as operating expenses too: they are already in COGS.

---

## Changes to existing endpoints

### Products — `GET/POST/PATCH /api/products`

New fields (in requests and responses):

| Field | Type | Notes |
|---|---|---|
| `kind` | `raw_material` \| `packaging` \| `finished_good` | Defaults to `finished_good`. |
| `family` | string, nullable | e.g. `"Mango Juice"`. |
| `size_label` | string, nullable | e.g. `"500ml"`. |
| `output_equivalent` | number > 0, nullable | Needed on finished goods when a batch makes more than one size. |
| `shelf_life_days` | integer, nullable | Used to default a batch output's expiry date. |

- `selling_price` is **optional** when `kind` is `raw_material` or `packaging` (it is stored as 0). It is still required for `finished_good`.
- `GET /api/products?kind=raw_material` or `?kind=raw_material,packaging` filters by kind. The same filter works on `GET /api/inventory`.

### POS and sales

- `GET /api/pos/products` returns only `finished_good` products.
- `POST /api/sales` rejects raw materials and packaging (`422` on `items.N.product_id`).

### Expenses — `GET/POST/PATCH /api/expenses`

New request fields:

| Field | Type | Notes |
|---|---|---|
| `type` | `operating` (default) \| `direct_labour` \| `direct_production` | |
| `production_batch_id` | integer, nullable | **Required** for the two direct types and **not allowed** for `operating`. The batch must still be a `draft`. |

- Once the batch is completed, a direct expense can only have its `description` edited.
- New `GET` filters: `?type=`, `?production_batch_id=`.
- New response keys: `operating_total`, `direct_total`, `by_type[]`. `categories` now comes from the shop's configurable categories (below).

### Profit report — `GET /api/reports/profit` (owner only)

`summary` gains `operating_expenses`, `wastage_losses`, `net_profit` and `net_margin`. The existing `expenses` and `operating_profit` keys now count **operating expenses only**.

### Dashboard — `GET /api/reports/dashboard`

Owners and managers get a new `production` object:

```json
{
  "batches_today": 1, "made_today": [{ "product_id": 7, "name": "Mango Juice 1L", "quantity": 30 }],
  "drafts": 1, "low_inputs": [ ... ], "finished_stock_units": 60,
  "expiring_soon": 2, "expiring": [ ... ],
  "production_cost_today": 158000, "wastage_cost_today": 1000,
  "stock_value": { "raw_material": 0, "packaging": 0, "finished_good": 0 }
}
```

The last three keys are **owner only**. Cashiers don't get `production`.

### Inventory movements — `GET /api/inventory/movements`

Movements made by a batch have `reference.type = "ProductionBatch"` and `reference.label = "B-000001"`.

---

## New endpoints

### Measurement units

| Method | Path | Body |
|---|---|---|
| GET | `/api/measurement-units` | — (a new shop gets kg, g, L, ml, pcs, pack, carton, bag) |
| POST | `/api/measurement-units` | `name`, `symbol` (unique per shop), `dimension?` (`mass`, `volume`, `count`, `length`, `other`) |
| PATCH | `/api/measurement-units/{id}` | any of the above |
| DELETE | `/api/measurement-units/{id}` | `422` if a product or recipe uses it |

A product's `base_unit` is still a free string. Use this list to fill the dropdown.

### Expense categories

| Method | Path | Body |
|---|---|---|
| GET | `/api/expense-categories[?include_inactive=1]` | — (a new shop gets Electricity, Water, Fuel, Delivery, Rent, Production labour, …) |
| POST | `/api/expense-categories` | `name`, `default_type?` |
| PATCH | `/api/expense-categories/{id}` | `name?`, `default_type?`, `is_active?` |

Use `default_type` to pre-select the expense type in the form.

### Recipes (bill of materials)

| Method | Path |
|---|---|
| GET | `/api/recipes?status=active\|archived\|all&search=` (paginated) |
| POST | `/api/recipes` |
| GET / PATCH | `/api/recipes/{id}` |
| POST | `/api/recipes/{id}/archive`, `/api/recipes/{id}/restore` |

Request body:

```json
{
  "name": "Mango Juice", "family": "Mango Juice",
  "yield_quantity": 100, "yield_unit": "L", "instructions": "…",
  "items": [ { "product_id": 1, "quantity": 80, "note": null } ]
}
```

- Item quantities are in each input product's own base unit.
- A `PATCH` that sends `items` replaces all of them.

The response includes `items[].unit_cost`, `items[].estimated_cost`, `estimated_cost` and `estimated_cost_per_yield_unit`, all at **today's** input costs.

### Production batches

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/production/batches?status=&recipe_id=&product_id=&from=&to=&search=` | List (paginated). `product_id` = batches that made that product (traceability). |
| POST | `/api/production/batches` | Plan a **draft** (no stock moves). |
| GET | `/api/production/batches/{id}` | Full detail. |
| PATCH | `/api/production/batches/{id}` | Edit a draft. |
| POST | `/api/production/batches/{id}/complete` | Record actuals, move stock, freeze cost. |
| POST | `/api/production/batches/{id}/cancel` | `{ "reason": "…" }`. Abandons a draft or reverses a completed batch. |

**Plan (POST):**

```json
{
  "recipe_id": 1, "planned_yield": 50, "production_date": "2026-09-30",
  "expiry_date": null, "responsible_user_id": 3, "notes": "…", "idempotency_key": "…",
  "inputs":  [ { "product_id": 1, "planned_quantity": 40 } ],
  "outputs": [ { "product_id": 7, "quantity": 30 } ]
}
```

- `name` is needed only when there is no `recipe_id`.
- Without `inputs`, the recipe is scaled to `planned_yield` (a 100 L recipe planned at 50 L halves every ingredient).
- `PATCH` accepts the same fields. Changing `planned_yield` without sending `inputs` rescales the recipe.

**Complete (POST):**

```json
{
  "production_date": "2026-09-30", "expiry_date": null, "notes": "…",
  "inputs":  [ { "product_id": 1, "actual_quantity": 42 }, { "product_id": 3, "actual_quantity": 40 } ],
  "outputs": [ { "product_id": 6, "quantity": 40, "output_equivalent": 0.5, "expiry_date": null },
               { "product_id": 7, "quantity": 30 } ],
  "wastage": [ { "product_id": 4, "quantity": 2, "reason": "Cracked on the filling line" } ],
  "direct_expenses": [ { "type": "direct_labour", "category": "Production labour", "amount": 20000, "description": null } ]
}
```

- Every key is optional; anything missing falls back to the draft. Inputs without an actual quantity use the planned quantity.
- Put packaging (bottles, caps, labels) in `inputs`. Its cost is reported separately as `costs.packaging`.
- `outputs[].output_equivalent` falls back to the product's own value. With more than one output it must be known, otherwise you get `422` on `outputs.N.output_equivalent`.
- An output's expiry is its own `expiry_date`, else the batch's `expiry_date`, else `production_date + shelf_life_days`.
- The whole operation is **one transaction**. If any input or wastage line is short of stock, you get `422` on `inputs` listing every shortage, and nothing moves.
- Only `finished_good` products can be outputs, and a product can't be both an input and an output.

**Batch detail response (abridged):**

```json
{
  "id": 1, "batch_number": "B-000001", "name": "Mango Juice", "status": "completed",
  "recipe": { "id": 1, "name": "Mango Juice" }, "production_date": "2026-09-30", "expiry_date": null,
  "planned_yield": 50, "yield_unit": "L", "output_quantity": 50, "yield_percent": 100,
  "total_cost": 158000, "cost_per_yield_unit": 3160, "responsible": "Elioda",
  "costs": { "materials": 104000, "packaging": 27000, "direct_labour": 20000, "direct_expenses": 6000, "wastage": 1000, "total": 158000 },
  "inputs":  [ { "product_id": 1, "product_name": "Mangoes", "kind": "raw_material", "unit": "kg",
                 "planned_quantity": 40, "actual_quantity": 42, "variance": 2, "unit_cost": 2000, "line_cost": 84000 } ],
  "outputs": [ { "product_id": 7, "product_name": "Mango Juice 1L", "size_label": "1L", "unit": "bottle", "quantity": 30,
                 "output_equivalent": 1, "allocated_cost": 94800, "unit_cost": 3160, "selling_price": 5500,
                 "unit_margin": 2340, "expiry_date": "2026-10-30" } ],
  "wastage": [ … ], "expenses": [ … ],
  "notes": null, "created_by": "…", "completed_by": "…", "completed_at": "…", "cancelled_at": null, "cancel_reason": null
}
```

On a draft, `inputs[].unit_cost` and `line_cost` show today's prices as an estimate, and `costs.*` are 0.

**Cancel rules:**

- A completed batch can only be cancelled while **all** of its output is still in stock. Otherwise you get `422` on `batch`.
- Cancelling reverses every stock movement (inputs, wastage and outputs) and restores the output's previous cost.
- The batch's direct expenses become `operating`, because the money was still spent.

### Wastage (outside a batch)

| Method | Path |
|---|---|
| GET | `/api/wastage?stage=&product_id=&production_batch_id=&from=&to=` returns `{ total_cost, records: <paginated> }` |
| POST | `/api/wastage` |

Request body:

```json
{ "product_id": 1, "quantity": 3, "reason": "Rotten in storage", "stage": null, "wastage_date": null, "idempotency_key": null }
```

- `stage` defaults from the product's kind: `raw_material`, `packaging` or `finished_goods`. `production` is used for losses recorded inside a batch.
- The loss is valued at the product's current cost, and you can't write off more than is in stock.

### Production report — `GET /api/reports/production?from=&to=` (owner/manager)

The range defaults to this month. Response keys:

| Key | Contents |
|---|---|
| `summary` | `batches`, `total_cost`, `materials`, `packaging`, `direct_labour`, `direct_expenses`, `batch_wastage`, `wastage_cost`, `drafts` |
| `products[]` | Per finished product made in the period: `quantity`, `production_cost`, `average_unit_cost`, `selling_price`, `unit_margin` |
| `batches[]` | Up to 50 completed batches, with yield and cost per yield unit |
| `wastage` | `total_cost`, `standalone_cost`, `in_batches_cost`, `by_stage[]`, `top_products[]` |
| `stock` | Per kind: `products`, `units`, `value_at_cost`, `low`, `out` |
| `low_inputs[]` | Raw materials and packaging that are out, or at or below their low-stock level |
| `expiring[]` | Finished stock expiring within 14 days, or already expired, per batch |

Sales are not tied to batches, so `expiring[].estimated_remaining` is estimated first-in-first-out: the stock on hand is assumed to come from the newest batches.

"Which sizes are most profitable" comes from the inherited `GET /api/reports/profit` → `products[]`, which now uses the real production cost.

---

## For Douglas: setup, demo data and QA

- **Migrations:** `php artisan migrate` adds 6 migrations dated `2026_09_30_1000xx`. They are additive only: existing products become `finished_good` and existing expenses become `operating`.
- **Demo data:** `php artisan db:seed --class=ProductionDemoSeeder`
  - It creates **Nile Fresh Juices** with 3 suppliers, purchases, 2 recipes, 4 completed batches in 3 sizes, 1 draft, cash and credit sales, expenses and wastage.
  - Login: `demo@production.nilebitlabs.com` with `DEMO_PASSWORD` (default `demo-password`).
  - It refuses to run in the production environment unless `ALLOW_DEMO_SEED=true`. It does nothing on a second run.
- **Tests:** `php artisan test tests/Feature/Production` covers the full manufacturer journey end to end, including hand-checked cost and profit figures.
  - The suite runs on SQLite. Please also run it once against the Production PostgreSQL database.
- **`.env`:** the `.env.example` inherited from Retail still names the database `nilebit_retail`. Point Production at its own database.
