# Browser Worker for the AI Queue Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` and `superpowers:test-driven-development` to implement this plan task by task. No commits are requested.

**Goal:** Start one existing AI queue job from an authenticated wait page immediately, while keeping CLI cron and database concurrency/lease rules intact.

**Architecture:** Extract a single-job worker operation shared by CLI and a dedicated POST endpoint. The endpoint validates session and CSRF before setting a server-only browser-worker context; wait-page JavaScript invokes it without overlapping requests and continues status polling with bounded backoff.

**Tech Stack:** PHP 8, PDO, SQLite in-memory tests, browser JavaScript, existing AI queue tables and handlers.

**Spec:** `docs/superpowers/specs/2026-10-23-ai-queue-browser-worker-design.md`

## Global Constraints

- Keep cron per minute as fallback.
- Use the existing claim, lease, concurrency, handler, and state-transition services.
- A browser request processes at most one job and returns `idle` when no slot or queued job is available.
- Never return prompts, provider results, or credentials from the trigger endpoint.
- Trigger requests require authenticated session, POST, and CSRF.
- No provider access in tests; use SQLite and fake handlers.
- Do not run `git add`, `git commit`, or `git push`.

## Review Focus

1. Concurrent tabs must not claim the same job or exceed configured slots: test shared claim and one-job calls.
2. Missing/invalid session, GET, and invalid CSRF must not run work: test the endpoint policy before defining browser-worker context.
3. An empty queue or full capacity must return idle promptly: test one-job outcomes.
4. Repeated browser triggers must not overlap within a tab, and queued status must trigger again with backoff: verify the JS control flow and syntax.
5. CLI behavior must remain bounded and unchanged: run existing core suite and CLI-worker guard tests.

---

### Task 1: Shared One-Job Worker and Browser Trigger

**Files:**
- Modify: `config/ai_queue.php`
- Modify: `actions/ai_gemini_client.php`
- Create: `ajax/ai_queue_work.php`
- Modify: `partials/ai_queue_screen.php`
- Modify: `assets/js/ai_queue.js`
- Modify: `tests/ai_queue/core_test.php`
- Modify: `tests/ai_queue/http_test.php`
- Modify: `docs/deploy/ai-queue-worker.md`

**Interfaces:**
- `ems_ai_queue_run_one(PDO $pdo): array` processes at most one job and returns `['processed' => 0|1]`.
- Browser context is accepted only when the server endpoint has validated POST, authenticated user, and CSRF; it is represented by `EMS_AI_QUEUE_BROWSER_WORKER`.
- Existing `ems_ai_queue_run(PDO $pdo, int $maxRunSeconds = 50)` remains CLI-only and reuses the shared one-job processing path.
- `POST /ajax/ai_queue_work.php` returns only `{'ok':true,'status':'processed'|'idle'}`.

- [x] Add failing SQLite tests for one-job processing, empty queue, no-claim-at-capacity, and preservation of CLI-only worker execution.
- [x] Add failing HTTP policy tests for authenticated user + POST + CSRF and rejection of each invalid condition.
- [x] Implement a shared one-job operation using existing `ems_ai_queue_claim`, handler, finish/fail, lease recovery, and retention logic; keep the CLI time-window loop.
- [x] Implement the dedicated endpoint with POST/session/CSRF checks before setting browser context; close the session before invoking the handler; return only status and generic errors.
- [x] Update provider worker guard to accept the validated server-only browser context while still rejecting ordinary web requests.
- [x] Update wait screen and JavaScript to trigger immediately and after queued status, keep at most one trigger request active per tab, and apply bounded backoff after idle/errors.
- [x] Document browser-trigger behavior, hosting timeout limits, and the cron fallback.
- [x] Run `php tests/ai_queue/run.php core`, `php tests/ai_queue/run.php http`, JS syntax check, PHP lint on changed PHP files, and `git diff --check`.

---
