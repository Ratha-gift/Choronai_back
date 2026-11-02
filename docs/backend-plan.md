# Backend plan

Status: the first version of the API is built in this project (see README.md): auth, permissions, settings, lists, products, stock documents, POS registers, sales, returns, PIN and offline sync. Not built yet: reports endpoints, file uploads for product images, the scale PLU export. The frontend still uses `localStorage`; nothing is connected yet.

This document describes the server Choronai needs so the same screens work for several cashiers, devices and branches, with real security. It is based on what the frontend stores today, so the API can return the same shapes and the frontend switch-over stays small.

## 1. Why a backend is needed

Today every browser has its own copy of the data. That means:

- Two cashiers on two devices see different stock, sales and registers.
- Passwords, the POS PIN and permissions are checked in the browser, so anyone can bypass them with the developer tools.
- Clearing the browser deletes the shop's data. Images are stored as text inside `localStorage`, which fills up quickly (about 5 MB).
- Reference numbers (`SALE/2026/0001`) are counted per browser, so two devices can create the same number.

The backend fixes these by keeping one database, checking every login and permission on the server, and making every stock change happen in one database transaction.

## 2. Stack (decided: Laravel)

| Part       | Choice                                                               | Why                                                                                 |
| ---------- | -------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Language   | PHP 8.3+                                                             | Decided by the team.                                                                |
| Framework  | Laravel 12                                                           | Routing, validation (Form Requests), policies for permissions, queues, migrations.  |
| Database   | MySQL 8 or PostgreSQL 16                                             | Both support transactions and row locks (`lockForUpdate()`); `decimal` for weights. |
| ORM        | Eloquent                                                             | Built into Laravel.                                                                 |
| Auth       | Laravel Sanctum (SPA cookie auth), `Hash::make` (bcrypt/Argon2)      | Works with the Next.js frontend; tokens not readable by page scripts.               |
| API shape  | API Resources (`JsonResource`) returning the same field names as now | Keeps the frontend switch-over small (camelCase keys, see `src/data/types.ts`).     |
| Files      | Laravel Storage (`public` disk, or S3)                               | Product images and logo instead of base64 in the database.                          |
| Background | Laravel queue (database driver is enough)                            | Processing offline sales batches, report caches.                                    |
| Hosting    | One VPS (Nginx + PHP-FPM + database), daily backup                   | The POS keeps working offline (section 7), so a cloud server is fine.               |

The Next.js frontend and the Laravel API run as two separate apps. Set `SANCTUM_STATEFUL_DOMAINS` and CORS so the frontend domain can send the session cookie.

## 3. Data model

Money is stored in **riel as integers** (`bigint`). Weights and quantities are `numeric(12,3)` (three decimals, e.g. 2.500 kg). Every table has `id` (UUID), `created_at`, `updated_at`; business tables also have `created_by` (user id). Deleting business records is a soft delete (`deleted_at`) so reports and audits stay correct.

### 3.1 People and access

| Table          | Main columns                                                                                       | Today in the frontend                    |
| -------------- | -------------------------------------------------------------------------------------------------- | ---------------------------------------- |
| `users`        | first_name, last_name, username (unique), email, password_hash, group_id, status (active/inactive) | `users` collection, `src/lib/auth.ts`    |
| `user_groups`  | name, description                                                                                  | `userGroups`                             |
| `permissions`  | group_id, key (e.g. `stock.receive`, `sales.cancel`), allowed                                      | `choronai-permissions-v1`                |
| `sessions`     | user_id, token_hash, expires_at, remember (12 h or 30 days), ip, user_agent                        | `choronai-session-v1`                    |
| `customers`    | code, name, company, phone, email, address, customer_group_id, price_group_id, award_points        | `customers`                              |
| `suppliers`    | company, name, phone, email, city, country                                                         | `suppliers` (no longer used by any page) |
| `member_cards` | card_no (unique), customer_id, award_points, total_spent, expiry, created_by                       | `memberCards` (linked by phone today)    |

Member cards should point to `customer_id` instead of a phone number.

### 3.2 Catalogue

| Table                           | Main columns                                                                                                                                                                                                                                                |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `products`                      | sku (unique), plu_code (scale item number, section 8), name, type (standard/service/combo), category_id, sub_category, brand_id, unit_id, price, cost, additional, alert_quantity, promo_price, promo_start, promo_end, details, invoice_details, image_url |
| `product_images`                | product_id, url, position (gallery)                                                                                                                                                                                                                         |
| `combo_items`                   | combo_product_id, product_id, quantity, price                                                                                                                                                                                                               |
| `product_suppliers`             | product_id, supplier_id, part_number, price                                                                                                                                                                                                                 |
| `price_history`                 | product_id, old_price, new_price, changed_by, changed_at                                                                                                                                                                                                    |
| `product_rewards`               | code, name, type, points                                                                                                                                                                                                                                    |
| `categories`, `units`, `brands` | as in Settings (units keep base_unit, operator, value for kg ↔ g)                                                                                                                                                                                           |

**Stock is not a column on `products`.** See `stock_levels` below.

### 3.3 Branches, warehouses and stock

| Table             | Main columns                                                                                                                                                                            |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `branches`        | name, name_km, vat, phone, email, city, country                                                                                                                                         |
| `warehouses`      | code, name, type (`main` / `branch`), branch_id (null for the main warehouse), price_group_id, phone, email, address                                                                    |
| `stock_levels`    | product_id, warehouse_id, quantity — one row per product per warehouse                                                                                                                  |
| `stock_movements` | product_id, warehouse_id, quantity (+/−), reason (sale, sale_return, receive, issue, adjustment, transfer_in, transfer_out), document_type, document_id, expiry, created_by, created_at |

`stock_movements` is the ledger: every change to stock writes one row, and `stock_levels` is updated in the same database transaction. Reports (Inventory In/Out, expiry alerts) read the ledger.

**Warehouse layout (decided):** one **main warehouse** plus **one warehouse per branch**.

```
Supplier ──Stock Receive──▶ Main warehouse ──Stock Issue──▶ Branch A warehouse ──POS sale──▶ customer
                                           └─Stock Issue──▶ Branch B warehouse ──POS sale──▶ customer
```

- **Stock Receive** adds stock to the main warehouse (or a branch, if a branch buys directly).
- **Stock Issue** is a transfer: `transfer_out` from the "From" warehouse and `transfer_in` to the "To" warehouse, in one transaction. Total stock in the company does not change.
- **POS sales** take stock from the warehouse of the cashier's branch.
- **Stock Adjustment** corrects one warehouse after a count.

The frontend today has one stock number per product. It will need a warehouse selector on stock pages and reports, and the POS reads the stock of its own branch only.

### 3.4 Stock documents

One header table and one line table per document type, or one shared pair with a `kind` column. A shared pair matches the frontend's `Transaction` type:

| Table                  | Main columns                                                                                                                                       |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `stock_documents`      | kind (receive/issue/adjustment), reference (unique), date, branch_id, to_branch_id (issue), warehouse_id, status, note, attachment_url, total_cost |
| `stock_document_lines` | document_id, product_id, quantity, unit_cost, type (addition/subtraction, adjustments), description, expiry                                        |

### 3.5 Sales and POS

| Table                                | Main columns                                                                                                                                                                                                      |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `sales`                              | reference (unique), date, branch_id, warehouse_id, customer_id, member_card_id, register_id, total, order_tax, discount, shipping, grand_total, paid, returned, balance, payment_status, status, note, created_by |
| `sale_lines`                         | sale_id, product_id, name, unit, quantity, price (copied at sale time so old invoices never change)                                                                                                               |
| `payments`                           | sale_id, method (Cash, ABA / KHQR, Card, Bank transfer), amount_riel, amount_usd, rate, tendered, paid_at, created_by                                                                                             |
| `sale_returns` + `sale_return_lines` | same shape, with sale_id                                                                                                                                                                                          |
| `suspended_sales`                    | customer_id, warehouse_id, items (JSON), adjustments (JSON), total, created_by                                                                                                                                    |
| `registers`                          | user_id, warehouse_id, opened_at, closed_at, status, cash_in_hand, open_note, counted_riel, counted_usd, counted_total, card_slips, expected_cash, difference, total_sales, invoices, note                        |

`payments` is a new table: today a sale stores one payment method. A separate table allows split payments (part cash, part ABA) later.

### 3.6 Settings and lookups

| Table                                                                                                        | Notes                                                                                                                            |
| ------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| `settings`                                                                                                   | key/value JSON per scope: `system`, `pos`. Same keys as `src/features/settings/definitions.ts`. The POS PIN is stored as a hash. |
| `reference_counters`                                                                                         | prefix, year, month, last_number — gives unique references across devices.                                                       |
| `currencies`, `tax_rates`, `customer_groups`, `price_groups`, `payment_terms`, `printers`, `email_templates` | as in Settings                                                                                                                   |
| `audit_log`                                                                                                  | user_id, action, entity, entity_id, before (JSON), after (JSON), at — deletes, voids, price changes, register closes             |

## 4. API

REST over HTTPS, JSON, under `/api/v1`. Lists accept `?search=&page=&pageSize=&sort=&from=&to=` and return `{ rows, total }`, which matches the DataTable component.

| Area           | Endpoints                                                                                                                                                                                                                                                                      |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Auth           | `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`, `POST /auth/change-password`                                                                                                                                                                                          |
| Users & groups | `GET/POST /users`, `PATCH/DELETE /users/:id`, `GET/POST /user-groups`, `GET/PUT /user-groups/:id/permissions`                                                                                                                                                                  |
| Customers      | `GET/POST /customers`, `PATCH/DELETE /customers/:id`                                                                                                                                                                                                                           |
| Member cards   | `GET/POST /member-cards`, `GET /member-cards/by-code/:cardNo`, `PATCH/DELETE /member-cards/:id`                                                                                                                                                                                |
| Products       | `GET/POST /products`, `GET/PATCH/DELETE /products/:id`, `GET /products/:id/price-history`, `POST /products/:id/images`                                                                                                                                                         |
| Stock          | `GET/POST /stock/receives`, `GET/PUT/DELETE /stock/receives/:id`; same for `/stock/issues` and `/stock/adjustments`; `GET /stock/levels?warehouseId=`                                                                                                                          |
| POS            | `POST /registers/open`, `GET /registers/current`, `POST /registers/:id/close`, `GET /registers/:id/summary`, `POST /sales`, `POST /sales/sync` (offline batch), `GET /pos/bootstrap` (data to cache on the device), `GET/POST/DELETE /suspended-sales`, `POST /pos/verify-pin` |
| Sales          | `GET /sales`, `GET /sales/:id`, `DELETE /sales/:id`, `GET/POST /sale-returns`                                                                                                                                                                                                  |
| Reports        | `GET /reports/overview`, `/reports/inventory-in-out`, `/reports/expiry-alerts`, `/reports/products`, `/reports/categories`, `/reports/best-sellers`, `/reports/quantity-alerts`, `/reports/adjustments`                                                                        |
| Settings       | `GET/PUT /settings/system`, `GET/PUT /settings/pos`, CRUD for each lookup (`/branches`, `/warehouses`, `/categories`, `/units`, `/brands`, `/currencies`, `/tax-rates`, …), `POST /settings/logo`                                                                              |
| Files          | `POST /uploads` → `{ url }`                                                                                                                                                                                                                                                    |

Reports are calculated on the server with SQL, so the browser no longer downloads every sale to draw a chart.

## 5. Rules the server must enforce

1. **Stock changes are atomic.** Saving a sale, receive, issue or adjustment writes the document, its lines, the `stock_movements` rows and the `stock_levels` update in one transaction. Lock the `stock_levels` rows (`SELECT … FOR UPDATE`, in Laravel `lockForUpdate()` inside `DB::transaction`) so two cashiers cannot oversell the last kilogram.
2. **Edits and deletes reverse stock on the server.** Editing a stock document = reverse the old movements + apply the new ones (the frontend does this today in `transaction-form.tsx`). Deleting a sale returns its stock.
3. **Combo products** expand into their components when stock moves (today: `adjustStock` in `src/features/products/store.ts`).
4. **Overselling** follows the `overSelling` setting; when it is off, the server rejects a sale that would make stock negative.
5. **References** come from `reference_counters` inside the same transaction, using the prefixes and reset rule from Settings.
6. **Permissions** are checked on every endpoint from the user's group, not only hidden in the menu.
7. **Registers:** a cashier can have at most one open register; `POST /sales` requires one and stores `register_id`. Closing calculates the summary on the server.
8. **POS PIN:** removing a cart line or cancelling a sale calls `POST /pos/verify-pin`; the PIN is never sent to the browser. Each success is written to `audit_log`.
9. **Member points:** awarded on the server when a sale is saved (rate from `customerPoints` setting).
10. **Prices are copied** into sale lines, so later price changes do not alter old invoices.

## 6. Connecting the frontend later

The pages already read data through a few hooks, so the switch can be done one area at a time:

| Today                                                            | Later                                                             |
| ---------------------------------------------------------------- | ----------------------------------------------------------------- |
| `useCollection(name)` in `src/data/collections.ts`               | Same hook, backed by `fetch` + a cache (e.g. TanStack Query)      |
| `useProducts`, `adjustStock` in `src/features/products/store.ts` | `GET /products`; stock changes only through stock/sale endpoints  |
| `login`, `useSession` in `src/lib/auth.ts`                       | `POST /auth/login`, `GET /auth/me`; session in an httpOnly cookie |
| `useSettings` in `src/features/settings/store.ts`                | `GET/PUT /settings/:scope`                                        |
| Reports in `src/features/reports/use-report-data.ts`             | `GET /reports/*`                                                  |

Suggested order: auth → settings & lookups → products → stock documents → POS & registers → reports. A one-time import endpoint can load an export of the current `localStorage` data so nothing typed so far is lost.

## 7. Offline POS (decided: yes)

The POS must keep selling when the internet is down. The rest of the admin (products, stock documents, reports, settings) can require a connection.

**On the device (frontend work):**

- Cache what the POS needs in IndexedDB: products, prices, stock of its branch, customers, member cards, POS settings, the open register. Refresh when online.
- A service worker caches the app itself so `/pos` opens without internet.
- A finished sale is saved to a local **outbox** first, then printed. The outbox is sent to the server when the connection returns.
- Each sale gets its id (UUID) on the device. Offline references use a device prefix, e.g. `SALE/A1/2026/0001`, so two devices never make the same number.
- Show a small online/offline badge and the number of sales waiting to sync.

**On the server:**

- `POST /api/v1/sales/sync` accepts a batch of sales. The client UUID makes it **idempotent**: sending the same sale twice saves it once.
- Sales are applied in the order they were made (`sold_at` from the device), each in its own transaction.
- Offline sales cannot be refused for low stock (the customer already left). The server saves them even if stock goes negative and adds them to a **"needs review"** list for the manager.
- Prices in an offline sale are trusted as sold, but differences from the current price are written to `audit_log`.
- Register open/close also goes through the outbox, so a cashier can open and close a shift offline.
- `devices` table: id, name, branch_id, warehouse_id, reference_prefix, last_sync_at. Each POS computer is registered once.

**PIN offline:** `POST /pos/verify-pin` cannot be called offline, so the device keeps a **hash** of the PIN (never the PIN itself) and checks it locally. Each use is still sent to `audit_log` on sync.

## 8. Printing and the scale (decided)

**Receipt printer: print automatically when a sale is completed.**

- Browsers always show a print dialog, except Chrome/Edge started with `--kiosk-printing`, which prints straight to the default printer. This is the simplest choice and works offline. Set the 80 mm receipt printer as the default printer on each POS computer.
- If a shop needs several printers (receipt + kitchen/label), use a small local print agent (e.g. QZ Tray) instead. The `printing` setting in POS Settings already has "Web browser" / "Local Print Server" for this.
- Printing happens on the device, not on the server, so it does not depend on the backend.
- Frontend work: a POS setting "Auto print receipt" (yes/no); when yes, the receipt prints right after payment without pressing Print.

**Label scale (price-computing scale with its own label printer):**

The meat is weighed on the scale, the scale prints a barcode label, and the cashier scans the label at the POS. The usual label is an EAN-13 "weight barcode":

```
2 0 | 0 1 2 3 4 | 0 2 5 0 0 | 7
prefix  PLU code   weight 2.500 kg  check digit
```

- Some scales put the **price** instead of the weight in the last 5 digits. This is a setting on the scale.
- Products need a `plu_code` column (the item number typed into the scale). Add it to `products`.
- The POS reads the prefix, finds the product by PLU, and adds the line with the weight (or price) from the label. This needs no server, so it also works offline.
- New POS settings: weight barcode prefix (e.g. `20`–`29`), PLU length, value type (weight/price), decimals.
- The product list (PLU, name, price per kg) must be loaded into the scale. Most scales come with PC software that imports a CSV/Excel file; the backend can provide `GET /api/v1/scale/plu-export` to download it. Check the scale's model and software before building this.

## 9. Still open

1. **Hosting:** cloud VPS (recommended now that the POS works offline) or a computer in the shop?
2. **ABA / KHQR:** manual confirmation by the cashier, or the bank's payment API? (The bank API needs internet, so offline sales would be manual anyway.)
3. **Suppliers:** keep for product supplier info, or remove now that purchasing is gone?
4. **Scale model:** which brand/model, and does its label carry the weight or the price?
