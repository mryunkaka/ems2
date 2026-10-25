# Event Registration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Make event registration login-only with dashboard navigation, cancellation and re-registration reasons, and an admin view that separates active and cancelled users.

**Architecture:** Keep active rows in `event_participants` so existing participant and group relationships remain usable. Add a SQL history table recording `registered` and `cancelled` actions; an authenticated CSRF-protected POST endpoint updates the active row and appends history in one transaction. The event page follows the certificate registration page's auth/header/sidebar/footer pattern.

**Tech Stack:** PHP, PDO/MySQL, existing session auth guard, existing CSRF helpers, dashboard partials.

**Spec:** `docs/superpowers/specs/2026-10-25-event-registration-design.md`

## Global Constraints

- `dashboard/events.php` is available only after login.
- Event registration is associated with the logged-in `user_rh.id`.
- Cancellation and re-registration require a non-empty reason.
- Only active participant rows count or enter generated groups.
- Use the dashboard shell and sidebar pattern already used by `sertifikat_heli_pendaftaran.php`.

## Review Focus

- No authenticated session: auth guard redirects to login and returns to the requested event after login.
- Repeated cancellation or re-registration: current active row and every reason/action remain consistent.
- Duplicate/concurrent registration: unique `(event_id,user_id)` remains the final guard; failed transaction must not create misleading history.
- Empty or oversized reason: rejected without changing active status.
- Cancellation after groups exist: remove that user's group membership so cancelled people do not remain displayed in existing groups.

---

### Task 1: Add event registration history schema

**Files:**
- Create: `docs/sql/105_2026-10-25_event_registration_history.sql`

**Interfaces:**
- Produces table `event_registration_history` with `id`, `event_id`, `user_id`, `action` (`registered` or `cancelled`), nullable `reason`, and `created_at`; indexes support lookups by event/user and latest action.
- History uses no foreign keys. Event deletion explicitly deletes its history; user deletion does not cascade to history.

- [x] Add an idempotent `CREATE TABLE IF NOT EXISTS` SQL migration using the repository's MySQL/InnoDB conventions.
- [x] Review column types against `events.id` and `user_rh.id` in `config/fouf9972_ems.sql`, and verify the existing event participant uniqueness is preserved.
- [x] Document the migration file in the implementation handoff so it is applied alongside deployment.

### Task 2: Add authenticated register/cancel action

**Files:**
- Create: `dashboard/event_registration_action.php`
- Modify: `dashboard/events.php`
- Modify: `auth/auth_guard.php`
- Modify: `auth/login_process.php`
- Modify: `partials/sidebar.php`

**Interfaces:**
- POST fields: `event_id`, `action` (`register` or `cancel`), `reason` for cancellation and re-registration, `csrf_token`.
- Redirect target: `/dashboard/events.php?id=<event_id>`.
- Active state: existence of `(event_id,user_id)` in `event_participants`; latest history action is used to distinguish a cancelled registration.

- [x] Implement POST-only action guarded by `auth_guard.php` and `validateCsrfToken`; reject unknown action/event, missing session user, invalid event, and empty/overlong required reason.
- [x] Exempt event registration pages from division-only dashboard page filtering, matching the certificate registration page. Save an intended event URL for unauthenticated GET requests; after successful login, redirect only to a validated local `dashboard/events.php?id=<integer>` URL.
- [x] In one transaction, registering inserts the active participant and history action; cancellation deletes the active participant, appends history, and removes `event_group_members` rows for the event's groups and user.
- [x] Treat initial registration reason as nullable; require and store a reason on re-registration after cancellation.
- [x] Convert `events.php` to auth guard plus `csrf.php`, `partials/header.php`, `partials/sidebar.php`, and `partials/footer.php`; derive profile fields from the authenticated user, remove anonymous account creation, show current registration state, and render register/cancel forms with reasons as required.
- [x] Ensure the active Event link remains visible in compact sidebar variants.
- [x] Keep event details/statistics visible only within authenticated dashboard; count only active `event_participants` rows.

### Task 3: Show cancelled history and preserve admin behavior

**Files:**
- Modify: `dashboard/event_participants.php`
- Modify: `dashboard/event_delete.php`

**Interfaces:**
- `$participants` contains active rows only and remains the input to group generation.
- `$cancelledParticipants` contains each user's latest action `cancelled`, with reason/time from that latest cancellation.
- Event management participant totals count active rows only.

- [x] Add a separate cancelled-participants table with user, cancellation time, and reason; derive cancelled state from the latest event/user history action and absence from the active participant table.
- [x] Keep group generation scoped to active rows and participant numbering/counts reflect active users only.
- [x] Keep event management totals based on `event_participants`; cancellation removes the active row, so the existing count already excludes cancelled users.
- [x] Delete event history as part of event deletion so no orphaned history remains.

### Task 4: Verify changed PHP and SQL integration

**Files:**
- Review all files listed in Tasks 1–3.

- [x] Run `php -l` for each changed PHP file if PHP CLI is available; expected output is `No syntax errors detected`. If unavailable, record that limitation and use `git diff --check` plus a manual syntax review.
- [x] Review the SQL migration for repeatability and check that it does not alter existing `events`, `event_participants`, or group table data.
- [x] Inspect the final diff to confirm login gate, sidebar includes, CSRF protection, required reason handling, active-only totals/groups, and cancelled reason display are all present.




