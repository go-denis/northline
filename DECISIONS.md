# DECISIONS

## 1) Customer email filter uses partial match (`LIKE`) instead of exact match

### Options considered

1. Exact match (`where email = ?`)
2. Partial match (`where email like %...%`)

### Chosen option

Partial match with server-side validation (`customer_email` as bounded string)
in `OrderController@index`.

### Why this option

- Better operator UX for internal review tooling (users can type fragment or
  domain part, not only full address).
- Matches practical filtering usage while keeping status filter strict.
- Covered by tests for exact and partial email behavior.

### Why the alternative was rejected

Exact match is simpler but too rigid for this UI and increases failed searches
for normal operator behavior.

---

## 2) Delete authorization enforced via Policy + backend `authorize`

### Options considered

1. Hide Delete button only in React
2. Inline conditional checks in controller
3. Laravel Policy (`OrderPolicy`) + backend authorization

### Chosen option

`OrderPolicy::delete` with rule: only creator and only `new` status; enforced
in `OrderController::destroy` using `Gate::authorize('delete', $order)`.

### Why this option

- Centralized authorization logic with Laravel convention.
- Enforces security at backend regardless of UI behavior.
- Produces correct semantics (`403 Forbidden`) for unauthorized deletion.
- UI restriction remains only UX synchronization, not access control.

### Why the alternatives were rejected

- UI-only control is bypassable and fails requirement.
- Inline controller checks work but scatter policy logic and scale worse.

---

## 3) Side effects moved to two independent queued jobs after commit

### Options considered

1. Keep email + CRM synchronous in request
2. Dispatch one combined job for both side effects
3. Dispatch two independent jobs after persistence commit

### Chosen option

Two jobs (`SendOrderPlacedEmail`, `SyncOrderToCrm`) dispatched inside
`DB::afterCommit(...)` callbacks.

### Why this option

- Order creation response is not blocked by slow/failing external work.
- Failures are isolated: one side effect failing does not cancel the other.
- Keeps transaction boundary strict: only order + items persistence inside
  `DB::transaction`.
- Email status semantics are explicit (`email_sent = true` only after successful
  mail send).

### Why the alternatives were rejected

- Synchronous processing violates assignment goal and adds user-facing latency.
- Single combined job couples unrelated concerns and creates unnecessary
  cross-failure impact.
