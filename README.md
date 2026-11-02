# Choronai API

Backend for the Choronai meat and fish shop: POS, stock, sales, members.
Built with **Laravel 12 + PostgreSQL 16 + Eloquent**, and runs in **Docker**.

The frontend (Next.js) is a separate project. Why things are built this way is explained in [docs/backend-plan.md](docs/backend-plan.md).

---

## 1. What you need

- **Git**
- **Docker** with Docker Compose:
    - Linux: Docker Engine.
    - Windows or Mac: Docker Desktop. On Windows, clone the project inside WSL 2 (it is much faster than a Windows folder).

You do **not** need PHP, Composer or PostgreSQL on your computer. Everything runs inside the containers.

## 2. First-time setup

```bash
git clone https://github.com/Ratha-gift/Choronai_back.git Choronai-backend
cd Choronai-backend

cp .env.example .env                                     # local settings (never commit .env)
docker compose up -d --build                             # start PostgreSQL + the API
docker compose exec api composer install                 # download PHP packages into vendor/
docker compose exec api php artisan key:generate         # create APP_KEY in .env
docker compose exec api php artisan migrate --seed       # create tables + sample data
```

Check that it works:

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"username":"admin021","password":"admin123"}'
```

You should get back `{"token":"...","user":{...}}`.

### Sample accounts (from the seeder)

| Username    | Password     | Group                          |
| ----------- | ------------ | ------------------------------ |
| `admin021`  | `admin123`   | Owner (all permissions)        |
| `cashier01` | `cashier123` | Sale (POS, returns, customers) |

The seeder also creates:

- One branch (Central).
- Two warehouses: `MAIN` (main warehouse) and `CEN` (Central branch).
- 5 products with PLU codes 1–5. `MAIN` holds 70 kg of each and `CEN` holds 30 kg.
- Customers, member cards, currencies and tax rates.

## 3. Everyday commands

| What                        | Command                                                     |
| --------------------------- | ----------------------------------------------------------- |
| Start                       | `docker compose up -d`                                      |
| Stop                        | `docker compose down`                                       |
| See API logs / errors       | `docker compose logs -f api` or `storage/logs/laravel.log`  |
| Run the tests               | `docker compose exec api php artisan test`                  |
| List all endpoints          | `docker compose exec api php artisan route:list --path=api` |
| Reset the database          | `docker compose exec api php artisan migrate:fresh --seed`  |
| Format code (before commit) | `docker compose exec api ./vendor/bin/pint`                 |
| Open a shell in the API     | `docker compose exec api bash`                              |
| Open PostgreSQL (psql)      | `docker compose exec db psql -U choronai choronai`          |

After `git pull`, run these in case a teammate added packages or tables:

```bash
docker compose exec api composer install
docker compose exec api php artisan migrate
```

If `docker/Dockerfile` changed, also run `docker compose up -d --build`.

### Run `artisan` inside the container

Run `php artisan …` through `docker compose exec api …`, not straight on your computer. Your own PHP has no PostgreSQL driver and connects to the wrong host.

## 4. Ports and connection details

| Service    | Address                        | Login                                                     |
| ---------- | ------------------------------ | --------------------------------------------------------- |
| API        | `http://localhost:8000/api/v1` | `POST /auth/login`, then `Authorization: Bearer <token>`  |
| PostgreSQL | `localhost:5432`               | database `choronai`, user `choronai`, password `choronai` |

You can open the database with DBeaver, TablePlus or pgAdmin using the details above.

The frontend may call the API from `FRONTEND_URL` in `.env` (default `http://localhost:3000`, see `config/cors.php`).

## 5. Troubleshooting

| Problem                                                        | Fix                                                                                                                                                     |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `port is already allocated` (5432 or 8000)                     | Something else uses the port, often a PostgreSQL installed on your computer. Stop it, or change the left number in `compose.yaml` (e.g. `"5433:5432"`). |
| `Failed to open stream: vendor/autoload.php`                   | You skipped `docker compose exec api composer install`.                                                                                                 |
| `No application encryption key has been specified`             | Run `docker compose exec api php artisan key:generate`.                                                                                                 |
| `could not find driver` or `Connection refused 127.0.0.1:5432` | You ran `php artisan` on your computer. Use `docker compose exec api php artisan …`.                                                                    |
| `Permission denied` on `storage/` or `vendor/` (Linux)         | The container runs as user id 1000. If `id -u` shows another number, start with `UID=$(id -u) GID=$(id -g) docker compose up -d`.                       |
| Want a completely empty database                               | `docker compose down -v` (deletes the database volume), then the setup steps again.                                                                     |

## 6. How the code is organised

| Path                                        | What                                                                                             |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| `routes/api.php`                            | Every endpoint and the permission it needs, e.g. `perm:sales.pos`.                               |
| `app/Http/Controllers/`                     | One controller per area (Products, StockDocument, Sale, Register, Pos …).                        |
| `app/Http/Controllers/CrudController.php`   | Shared list/create/edit/delete for simple lists (branches, warehouses, customers, currencies …). |
| `app/Support/Resources.php`                 | The simple lists: model, validation rules, permission. Add a new list here.                      |
| `app/Services/StockService.php`             | **The only place stock changes.** It writes the ledger, locks rows and expands combo products.   |
| `app/Services/SaleService.php`              | POS sales, returns, member points.                                                               |
| `app/Services/RegisterService.php`          | Cashier register summary and closing.                                                            |
| `app/Http/Middleware/ConvertCase.php`       | JSON is camelCase for the frontend (`grandTotal`); PHP and the database use snake_case.          |
| `app/Http/Middleware/RequirePermission.php` | The `perm:` check.                                                                               |
| `app/Support/Settings.php`                  | System / POS settings and the hashed POS PIN.                                                    |
| `app/Support/References.php`                | Document numbers like `SALE/2026/11/0001`.                                                       |
| `database/migrations/`                      | Table definitions.                                                                               |
| `database/seeders/DatabaseSeeder.php`       | Sample data, the same as the frontend.                                                           |
| `tests/Feature/`                            | API tests (they use an in-memory SQLite database, not your PostgreSQL data).                     |
| `docker/Dockerfile`, `compose.yaml`         | The development containers.                                                                      |

## 7. Rules to keep

- **Money** is stored in riel as whole numbers (`bigint`). **Quantities and weights** have 3 decimals (2.500 kg).
- **Stock is never edited directly.** It only changes through Stock Receive / Issue / Adjustment and sales, always via `StockService` inside `DB::transaction`.
- **Warehouses:** one `main` warehouse and one `branch` warehouse per branch. Stock Issue moves stock from one warehouse to another.
- **Every new endpoint needs a permission** (`perm:` in `routes/api.php`) and a test in `tests/Feature/`.
- **POS:**
    - A sale needs an open register (`POST /registers/open`).
    - Offline sales come in through `POST /pos/sync`; sending the same sale twice saves it once.
- **Before you push:** run `pint` and `php artisan test` (section 3).

## 8. API overview

All endpoints are under `/api/v1`. Run `docker compose exec api php artisan route:list --path=api` for the full list.

| Area     | Endpoints                                                                                                                                                             |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Auth     | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout`, `POST /auth/change-password`                                                                                 |
| People   | `/users`, `/user-groups`, `/user-groups/{id}/permissions`, `/customers`, `/suppliers`, `/member-cards`, `/member-cards/by-code/{no}`                                  |
| Settings | `GET/PUT /settings/system`, `GET/PUT /settings/pos`, `/branches`, `/warehouses`, `/categories`, `/units`, `/currencies`, `/tax-rates`, …                              |
| Products | `/products`, `/products/{id}/price-history`, `/products/{id}/movements`, `/stock/levels?warehouseId=`                                                                 |
| Stock    | `/stock/receives`, `/stock/issues`, `/stock/adjustments` (GET, POST, PUT, DELETE)                                                                                     |
| POS      | `/registers/open`, `/registers/current`, `/registers/{id}/close`, `POST /sales`, `/suspended-sales`, `/pos/verify-pin`, `/pos/bootstrap`, `/pos/sync`, `/pos/devices` |
| Sales    | `GET /sales`, `GET/DELETE /sales/{id}`, `POST /sales/{id}/review`, `/sale-returns`                                                                                    |

List endpoints accept `?search=&sort=&direction=asc&page=1&pageSize=50` and return `{ "rows": [...], "total": 123 }`. Without `page` they return every row.

**Not built yet:** report endpoints, image uploads for products, the PLU export for the label scale.
