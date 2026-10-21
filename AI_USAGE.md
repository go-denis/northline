# AI_USAGE

## Tool 1: Zed AI coding assistant

#### Tasks

- Analyze existing Laravel + React codebase against assignment requirements.
- Implement filters, pagination, deletion authorization, and queued side
  effects.
- Update and extend feature tests without weakening existing assertions.
- Prepare submission documentation files.

#### Prompt / request summary

- Work step-by-step by roadmap branches.
- Preserve transaction boundaries.
- Prevent pagination+filter regression on page navigation.
- Keep correct HTTP semantics for authorization (`403` for forbidden delete).
- Do not over-engineer beyond assignment scope.

#### Accepted unchanged

- Policy-based deletion guard (`OrderPolicy` + `Gate::authorize`).
- Queue-based decomposition into two independent jobs.
- Pagination + filter contract updates and related tests.

#### Edited by hand / narrowed

- Email filtering behavior was narrowed to partial-match search (`LIKE`) with
  explicit tests.
- Queue assertions were adjusted for framework compatibility (see correction
  below).

#### One incorrect/incomplete suggestion and how it was detected

- Suggested using `Queue::assertPushedTimes(...)` in tests.
- Local test run failed with:
  `BadMethodCallException: undefined method QueueFake::assertPushedTimes()`.
- Fixed by switching to supported assertion form:
  `Queue::assertPushed(JobClass::class, 1)`.

---

### 2) Context7 (documentation lookup)

#### Tasks

- Verify Laravel queue/transaction guidance for dispatch after DB commit.
- Verify available queue fake assertion patterns.

#### Query summary

- Laravel docs on dispatching queued jobs after transactions (`afterCommit`,
  `after_commit` behavior).
- Laravel docs on queue testing assertions (`assertPushed`, count assertions,
  push-on-queue checks).

#### Accepted unchanged

- Principle that queued side effects should be dispatched after transaction
  commit for consistency with persisted records.

#### Edited by hand / narrowed

- Chosen implementation uses explicit `DB::afterCommit(...)` callbacks so side
  effects are enqueued only after successful transaction commit.

---

## Notes on boundaries

- No secrets or private credentials were shared with AI tools.
- Suggestions were applied selectively; implementation was constrained to README
  scope and existing project architecture.
