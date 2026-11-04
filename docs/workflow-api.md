# Workflow API additions

These additions implement part of the sidebar workflows in `../../docs/business-workflow.md`. They extend the imported Laravel backend; the Next.js frontend has not switched from its SQLite API to these endpoints yet.

All requests below use `/api/v1`, a signed-in active user and `Authorization: Bearer <token>`. JSON and query parameter names use camelCase.

## Apply the database change

From `backend/`, with the existing Docker setup running:

```sh
docker compose exec api php artisan migrate
```

Migration `2026_11_03_000001_add_stock_workflow` marks existing stock documents `legacy`. Their prior stock movements are preserved. New documents use `controlled` workflow rules. Do not run `migrate:fresh` on an existing shop database.

## Adjustment approval

1. `POST /stock/adjustments` with warehouseId and items creates a pending document. Creating it does not change stock. Each item needs productId, positive quantity and type (`addition` or `subtraction`).
2. A user with `stock.approve` calls `POST /stock/adjustments/{id}/approve` with an empty JSON object.
3. Approval locks the document, checks available stock, applies all movements and records approvedBy/approvedAt atomically. Repeating approval returns the same approved document without applying stock again.

Do not submit status `approved` to the create/update endpoints. Only pending controlled adjustments can be edited/deleted, with the existing stock.edit/stock.delete permissions. Posted documents are retained; corrections require new stock documents. Legacy adjustments cannot be approved again.

## Dispatch and receipt

`POST /stock/issues` requires warehouseId, toWarehouseId and items. It deducts source stock and sets status `dispatched`. It does not increase destination stock. Insufficient source stock rejects dispatch regardless of the POS overselling setting.

A receiving user with `stock.receive` calls:

```http
POST /api/v1/stock/issues/{issueId}/receive
Content-Type: application/json
Authorization: Bearer <token>
```

```json
{
  "id": "a-new-client-generated-uuid",
  "items": [{ "productId": "a-dispatched-product-uuid", "quantity": 2.5 }],
  "note": "First delivery"
}
```

Use real UUIDs in place of the example placeholders. The API derives the destination warehouse from the dispatch. It creates a Stock Receive linked by transferId and credits only the received quantities. Status changes to `partially_received` or `received`. Products must appear in the dispatch; total receipts cannot exceed the dispatched quantity. Quantities have at most three decimals and duplicate product lines are rejected.

Reuse the receipt UUID and quantities when retrying a failed connection. An identical retry returns the existing receipt; changed quantities or an ID used by another document returns 409. Each additional delivery needs a new UUID. The source document lock serializes concurrent receipts.

Legacy issues cannot use this endpoint because the old implementation already credited both warehouses. Posted receipts and dispatches cannot be edited or deleted. Ordinary standalone Stock Receive remains available for external stock intake; it must not also be used to receive the same transfer.

## Search and cashier activity

| Request | Behavior |
| --- | --- |
| `GET /branches?search=Central` | One search covers English name, Khmer name and city. |
| `GET /member-cards?search=012000222` | One search covers card number or linked customer phone, including common phone formatting. Returns linked customer details. |
| `GET /users?registerStatus=open` | Users with an open shift. Needs users.view. |
| `GET /users?registerStatus=closed` | Users with shift history and no open shift. |
| `GET /users?registerStatus=none` | Users who have never opened a shift. |
| `GET /users/{userId}/registers` | That user's shift history, with warehouse and cashier details. Needs users.view. |
| `GET /registers?status=open&userId=...&warehouseId=...` | Filter registers; users without sales.registers remain restricted to their own shifts. |

User list rows now expose registerStatus and hasOpenRegister so the frontend can retain its light red open-register highlight. Account active/inactive status is separate. List endpoints retain the existing page/pageSize/sort/direction parameters. Opening a register locks the user row before checking for another open shift.

## Validation and remaining work

PHP syntax checks passed for changed PHP files. Runtime/API tests were not run and the database migration has not been applied here. Docker was not available on PATH in this session.

These changes do not complete every workflow in the source document. Laravel reward redemption/history and return-point reversals, refund reconciliation, FEFO batches, report endpoints, full branch access rules and frontend integration still require implementation. Existing stock API tests that assumed immediate destination credit or editing posted stock documents need to be updated for the new contract before runtime validation.
