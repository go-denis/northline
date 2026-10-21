# SOLUTION

## Summary

This take-home was completed incrementally and merged into `main` with
meaningful commits.

Implemented all required items from `README.md`:

1. Status + customer-email filters
2. Server + client pagination
3. Delete allowed only for creator and only for `new` orders
4. Email and CRM side effects moved out of create request into queue jobs
5. Tests updated/added for changed behavior
6. Submission docs added: `SOLUTION.md`, `DECISIONS.md`, `AI_USAGE.md`

## Git history used for implementation

Repository: https://github.com/go-denis/northline

Relevant history (`git log --oneline`):

- `56e41b7` — `feat: implement order filtering and pagination`
- `5f86b5c` / `2bf075b` — `fix: implement order deletion authorization and related tests`
- `684885b` — `feat: implement queued order side effects for email and CRM synchronization`
- merged into `main` via PR merge commits (`b046ef1`, `28ade7d`)

## What changed

### 1) Filters and pagination

Files:
- `app/Http/Controllers/OrderController.php`
- `resources/js/pages/OrdersPage.jsx`
- `tests/Feature/OrderDeskTest.php`

Backend:
- Added list query validation for:
  - `status` (`new`, `processing`, `completed`, `cancelled`)
  - `customer_email` (bounded string)
  - `page` (integer >= 1)
- Added filtering by:
  - status
  - partial customer email (`LIKE %...%`)
- Added server pagination (`15` per page).
- Response includes `data`, `meta`, and `links`.

Frontend:
- Added missing `cancelled` status in filter options.
- Sends `status`, `customer_email`, and `page` to `/api/orders`.
- Resets to page 1 when filters change.
- Added previous/next controls and page summary.
- Polling keeps current filters and page, preventing page-2 filter loss.

Tests:
- Added/updated tests for:
  - status filter
  - exact + partial customer email filter
  - combined filters
  - pagination meta and page navigation
  - invalid status (422)
  - filters preserved when requesting page 2

### 2) Deletion authorization

Files:
- `app/Policies/OrderPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `app/Http/Controllers/OrderController.php`
- `resources/js/pages/OrdersPage.jsx`
- `tests/Feature/OrderDeskTest.php`

Backend:
- Added `OrderPolicy::delete`:
  - allowed only when current user is creator
  - and order status is `new`
- Registered policy in `AppServiceProvider`.
- Enforced authorization in `OrderController::destroy` via
  `Gate::authorize('delete', $order)`.
- Forbidden delete returns `403`.

Frontend:
- Delete button is shown only for orders that satisfy the same rule
  (owner + `new`) as UX alignment.
- For orders not owned by the user (or not in `new`), delete action is not
  displayed.

Tests:
- Can delete own `new` order.
- Cannot delete another user’s `new` order (`403`).
- Cannot delete own non-`new` order (`403`).

### 3) Queued side effects for order creation

Files:
- `app/Http/Controllers/OrderController.php`
- `app/Jobs/SendOrderPlacedEmail.php`
- `app/Jobs/SyncOrderToCrm.php`
- `app/Mail/OrderPlacedMail.php`
- `resources/views/mail/order-placed.blade.php`
- `tests/Feature/OrderDeskTest.php`

Behavior:
- Order + items are persisted inside `DB::transaction(...)`.
- Side effects are dispatched inside `DB::afterCommit(...)` callbacks,
  so jobs are enqueued only after the surrounding transaction commits.
- Two independent jobs are used:
  - `SendOrderPlacedEmail`
  - `SyncOrderToCrm`

Email job:
- Sends `OrderPlacedMail`.
- Sets `email_sent = true` only after successful send.

CRM job:
- Calls `CrmSyncService::push(...)`.
- Does not swallow exceptions (retries/failed-job flow stays intact).
- Logs diagnostics in `failed(...)`.

Reliability settings:
- Both jobs define `tries`, `backoff`, and `timeout` explicitly.

Tests:
- Create-order test checks:
  - order is created,
  - `email_sent` is `false` immediately after create,
  - both jobs are queued once on expected queues.
- Added job tests:
  - email job marks `email_sent` on success,
  - CRM job surfaces failures,
  - email success remains true even if CRM fails for the same order.
- Validation failure test now also asserts no jobs are queued.

## Existing tests updated because behavior changed

`OrderDeskTest` previously expected synchronous side effects from create.
That behavior changed by design (requirement #4), so assertions were updated to:

- assert queued jobs are pushed,
- assert `email_sent` remains `false` right after order creation,
- assert state changes happen during job handling.

No unrelated tests were weakened to force green status.

## What was intentionally left unchanged (and why)

- No Redis / Docker / alternate broker / CI pipeline:
  assignment explicitly says database queue is sufficient and to avoid infra
  expansion.
- No extra abstraction layer (repository/service per class):
  current scope is covered by Laravel controllers, policies, and jobs.
- No soft-delete for orders:
  requirement is explicit about who can delete and when; adding soft-delete
  would change semantics and increase scope.
- No authentication rewrite:
  explicitly out of scope per assignment.

## Checks actually run

Environment: project root in local WSL shell.

- `php artisan test`
  - Result: **20 passed**, **0 failed**, **72 assertions**
  - Duration: `0.91s`
- `npm run build`
  - Result: build succeeded.

## Queue worker verification

Verified manually with worker:

- Ran `php artisan queue:work`.
- Created an order with one item:
  - `SendOrderPlacedEmail` and `SyncOrderToCrm` were processed.
  - `orders.email_sent` became `true` after email job processing.
- Created an order for Westbridge Hydraulics:
  - CRM job failed and logged an error in `storage/logs/laravel.log`.
  - Order remained persisted.
  - Email job remained independent.

## Manual smoke test

- Signed in as seeded users (`alex.morgan@northline.test`,
  `sam.rivera@northline.test`).
- Confirmed status + email filters work together with pagination.
- Confirmed page navigation preserves active filters.
- Confirmed delete action is shown only for creator-owned `new` orders and
  hidden/blocked otherwise.

## What I would do next with more time

1. Replace boolean `email_sent` with delivery states
   (`pending` / `sent` / `failed`) to distinguish “not processed yet” from
   “processed and failed”.
2. Add idempotency key strategy for CRM sync to reduce duplicate external
   writes on retries.
3. Revisit email-search strategy for scale (`LIKE %...%` trade-off vs
   prefix/indexed alternatives).
4. Add failed-job monitoring/alerting for operations visibility.
5. Add lightweight integration checks around worker processing in CI.
