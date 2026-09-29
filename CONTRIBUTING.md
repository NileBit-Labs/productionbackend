# Contributing to retailbackend

Laravel API for NileBit POS for Retail. PostgreSQL for local dev, Sanctum for API auth.

## Setup

Install Postgres and start it on port 5433, kept separate from any other local Postgres you may have running on the default 5432:

```bash
brew install postgresql@17
brew services start postgresql@17
```

Homebrew's default port is 5432 — edit `port = 5432` to `port = 5433` in `$(brew --prefix)/var/postgresql@17/postgresql.conf` before starting if you already have something on 5432, then `brew services restart postgresql@17`.

Create the shared role and database (local trust auth, no password):

```bash
createdb -h localhost -p 5433 nilebit_retail
psql -h localhost -p 5433 -d postgres -c "CREATE ROLE nilebit WITH LOGIN SUPERUSER;"
psql -h localhost -p 5433 -d postgres -c "ALTER DATABASE nilebit_retail OWNER TO nilebit;"
```

Then the app:

```bash
git clone https://github.com/NileBit-Labs/Retailshopbackend.git
cd Retailshopbackend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

API is now at `http://localhost:8200` — pinned via `SERVER_PORT` in `.env.example`, not Laravel's default 8000, since port 8000 is the default for basically every Laravel project and will collide with any other one you have running locally. If you ever see a working login form suddenly fail with "The route api/... could not be found", it almost always means something *else* is squatting on this port — check `lsof -nP -iTCP -sTCP:LISTEN` before assuming the code is broken.

Run `php artisan test` before opening a PR — it must pass (tests run against an in-memory SQLite, not Postgres, so no extra setup needed there).

## Demo data

A fresh database has no products, so the POS has nothing to sell. For local development:

```bash
php artisan db:seed --class=DemoCatalogSeeder
```

This gives every shop that has no products yet a realistic 24-product catalogue (with boxes/crates and opening stock). It skips shops that already have products; it is for development only.

## Rules every module follows

These come from the plan's "business logic freeze". Most are release blockers, and each one is enforced by tests — please keep it that way.

- **Money is an integer** number of UGX (`unsignedBigInteger`), never a float. Quantities are `decimal(12,3)`.
- **Stock only changes through `StockService`.** Stock is never a stored number; it is the sum of the append-only `stock_movements` ledger. Never `update` a product's quantity, never write to `stock_movements` directly.
- **Balances are ledgers.** A customer's balance is the sum of `customer_ledger_entries` (`CustomerLedger` is the only writer); a supplier's is the sum of `supplier_ledger_entries` (`SupplierLedger`). Repayments are applied to the oldest unpaid credit sale / purchase first (`CustomerDebt`, `SupplierDebt`), so nothing is stored per invoice that could drift from the ledger.
- **Paged lists take `per_page`.** Use `paginate(PerPage::from($request))` (`App\Support\PerPage`, allowed 10/25/50/100, anything else falls back to the list's default) so the screens' "rows per page" works and one request can't pull a whole table.
- **Ask Your Shop (AI) can only look, and only where the person could look.** Its tools live in `App\Services\Ask\ShopTools`: read-only, handed the shop the person is signed in to (the model never picks one), built on the same report services as the screens, and cost/profit tools are owner-only *and not offered* to managers. A new tool needs a test for its role gating and its shop scoping. The Groq key is `GROQ_API_KEY` in the server's `.env` only: never in the frontend, never in git, never in chat. `php artisan ask:check` proves a key against the real service.
- **Cost is a weighted average.** Receiving a purchase (`PurchaseService`) blends the product's `current_cost` with the stock already on the shelf; cancelling it puts the cost back only if nothing has moved it since. Payments to suppliers are shop money, not till money, so they don't count towards a shift's expected cash.
- **Financial records are append-only.** Money going back out (void, refund) is a *new* `payments` row with `direction = 'out'`; a correction is a new opposite entry. Don't edit or delete past sales, payments, movements or ledger rows.
- **History is immutable.** Price and cost are copied onto `sale_items` at sale time so a later price change can never rewrite past profit.
- **Never trust the client's totals.** Recompute prices/totals on the server (see `SaleService`). A field the client sends is data, not authority.
- **Everything is shop-scoped.** Put shop-level routes behind `shop.access` (optionally `shop.access:owner,manager`) and always filter by `$request->attributes->get('shop')`. Cross-shop access must return 404/422, and there should be a test for it.
- **Sensitive actions are audited.** Use `AuditLogger` for voids, refunds, edits to financial records, shift closes, etc.
- **Writes that can be retried take an idempotency key** (`idempotency_key` body field or `Idempotency-Key` header) and must return the original result instead of doing it twice. Offline devices retry constantly.
- **Multi-step writes go in one `DB::transaction`,** and anything two people could race on (last item in stock, a customer's balance) is row-locked with `lockForUpdate()`.
- **Roles:** owners/managers see and do everything; cashiers only what a sale needs. Enforce it on the server; the UI hiding a button is not security.

Tests are the spec: cover the happy path, the permission boundary, the cross-shop boundary, and (for money/stock) a reconciliation check that the totals still add up. When you write a rule test, break the rule once and confirm the test fails.

## Module ownership

| Person | Track |
| --- | --- |
| Elioda Muhangi (CTO) | Foundation (auth/RBAC), Sales/POS, Offline Sync, Customers & Credit, Expenses, Refunds & Shifts |
| Collins Shema (COO) | Products & Inventory, Suppliers & Purchases |
| Douglas Bagambe (CEO) | Users & Security (staff, audit-log viewer), Dashboard & Reports |

Stick to your own module's tables/routes/controllers unless you're coordinating a shared change — flag those in a PR description or ask before touching another track's files. Two are already shared and are the ones to be careful with: the `stock_movements` ledger (Sales writes `SALE`/`SALE_RETURN` rows, Inventory writes the rest, all through `StockService`) and the `payments` table (Sales, Customers and Purchases all write to it).

## Branching & PRs

- Branch off `main`: `feature/<your-module>-<short-description>` (e.g. `feature/inventory-low-stock-alerts`)
- No direct pushes to `main` — open a PR, get at least one review before merging
- Keep PRs scoped to one module/feature at a time
