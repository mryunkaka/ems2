# AI Usage Monitoring Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Programmer Roxwood an easy-to-scan AI usage history and immediately block disabled users whose resign was approved.

**Architecture:** Reuse `system_ai_request_logs` as the usage source, showing user, model/provider, feature purpose, time, and tokens without prompt or response content. Add a Programmer Roxwood-only dashboard page and nav link. Make `auth_guard.php` reject an already-open session when the current database user is inactive.

**Tech Stack:** PHP, PDO, existing dashboard partials and styles, MySQL.

**Spec:** Approved design in the conversation on 2026-10-25.

## Global Constraints

- Reuse the existing dashboard design system; create no new assets or visual theme.
- Restrict the monitoring page and menu item to the Programmer Roxwood account.
- Never display prompt or response text; payloads may be read server-side solely to recover or estimate token counts.
- Do not add a separate usage log table or cost estimates.
- Keep the existing resign approval flow and login checks; enforce inactive status for already-open sessions.
- Do not add or run tests unless requested; perform syntax and focused code review only.

## Review Focus

- Direct URL access by another account must be denied.
- Existing session after account deactivation must be ended on the next request.
- Empty log table must render without warnings.
- Null token counts or a missing user record must render safely.
- Monitoring must not render prompt/response payloads or error content.

---

### Task 1: Enforce active account status for existing sessions

**Files:**
- Modify: `auth/auth_guard.php`
- Modify: `auth/login_process.php`

**Interfaces:**
- Use the existing `$pdo` connection and current `user_rh` session ID.
- Preserve the existing unauthorized redirect/JSON response behavior.

- [x] Extend the current-user lookup to fetch active and resign metadata; synchronize stale resign metadata to `is_active = 0` and delete remember tokens.
- [x] At login, also reject and deactivate a user whose resign metadata is already present even if `is_active` is stale.
- [x] When the row is inactive or resigned, clear the session, expire the remember-login cookie, and route through the existing unauthorized response or login redirect.
- [x] Review that active users and users whose database lookup temporarily errors retain current behavior.

### Task 2: Add Programmer Roxwood AI usage history page

**Files:**
- Create: `dashboard/ai_usage_monitoring.php`
- Modify: `partials/sidebar.php`

**Interfaces:**
- Use `ems_current_user_is_programmer_roxwood()` for both page authorization and menu visibility.
- Read `system_ai_request_logs` joined to `user_rh` by `created_by`.
- Use existing `partials/header.php`, `partials/sidebar.php`, `partials/footer.php`, icon helpers, and dashboard card/table styles.

- [x] Deny direct access unless the current account is Programmer Roxwood.
- [x] Show a compact latest-use summary and newest-first history with date/time, user, provider/model, readable purpose derived from `feature_key`, status, and prompt/response/total token counts.
- [x] Use null-safe fallbacks for deleted/missing users and unavailable token counts; show a clear empty state when there are no records.
- [x] Never render `request_payload` or `response_payload`; these are read only server-side when token columns or response usage are missing.
- [x] Add a sidebar link visible only to Programmer Roxwood, following the existing AI management menu pattern.
- [x] Review authorization, empty-state rendering, query fields, and menu visibility without adding or running tests.

### Follow-up: DataTables sorting and missing provider usage

**Files:**
- Modify: `dashboard/ai_usage_monitoring.php`
- Modify: `actions/ai_custom_client.php`
- Modify: `actions/ai_gemini_client.php`
- Create: `docs/sql/103_2026-10-25_ai_usage_estimated_flag.sql`

- [x] Replace server pagination with the dashboard's existing DataTables assets; default to descending timestamp and retain the Indonesian language file.
- [x] Persist standard OpenAI-compatible input/output/total usage values from provider responses, including 9Router.
- [x] For existing rows, recover usage from the saved response payload where available; otherwise estimate from stored text at roughly four characters per token and mark the value with `≈`.
- [x] Keep prompt and response text server-side and remove payload fields before rendering table data.
- [x] Persist whether custom-provider token values are estimates; keep logging compatible before the optional column migration is applied.
- [x] Avoid estimating output from provider error bodies; only use a recognizable completion-shaped truncated payload.
