# OCR.Space Key Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add encrypted multi-key OCR.Space management, per-key usage monitoring and bounded key-specific failover, all managed from `dashboard/ocr_api_status.php`.

**Architecture:** Persist OCR keys as authenticated ciphertext in a dedicated database table, with the encryption secret supplied by the application environment. Add a small OCR key service for CRUD, provider usage checks, health tests, and bounded key selection; keep the existing status page as the only management UI. Integrate key selection into identity scanning while preserving existing OCR request and parsing behavior except for retries after explicit key rejection, quota exhaustion, or rate limiting.

**Tech Stack:** PHP 8+, PDO, cURL, OpenSSL AES-256-GCM, existing PHP test harness.

**Spec:** `docs/superpowers/specs/2026-10-23-ocr-key-management-design.md`

## Global Constraints

- Manage keys on the existing OCR API status page.
- Only Programmer Roxwood may read or change OCR key settings.
- Never render stored plaintext keys to the browser; encrypt keys at rest.
- Retry at most once per enabled key and only on clearly key-specific provider errors.
- Do not retry generic OCR/document errors, transport failures, or TLS failures.
- Show Engine 1+2 usage against 25,000 monthly conversions and Engine 3 separately; label usage freshness through end of previous day.
- Do not automate key/account creation or encourage quota evasion.

## Review Focus

- Missing/invalid encryption secret: saving and decryption must fail closed without writing plaintext.
- Tampered/invalid ciphertext: key must not be returned as usable.
- CSRF, unauthorized role, and invalid input: management mutations must be rejected.
- Provider responses with invalid-key, quota, rate-limit, generic parse, malformed JSON, HTTP/TLS errors: retry classification and displayed state must remain accurate.
- Disabled/deleted keys and concurrent request ordering: the next scan must use only currently enabled keys and make no more than one attempt per enabled key.

## Files and Responsibilities

- Create `helpers/ocr_key_manager.php`: encryption, database access, OCR.Space usage probe, test probe, error classification, and bounded failover orchestration.
- Create `migrations/2026_10_23_create_ocr_api_keys.sql`: encrypted key records and nonsecret health/usage metadata.
- Modify `dashboard/ocr_api_status.php`: status/usage listing, guarded same-page management actions, CSRF, masked key list, and provider limits/freshness.
- Modify `dashboard/identity_test.php`: use the manager for enabled-key selection and explicit key-specific failover.
- Create `tests/ocr_key_manager_test.php`: deterministic unit/integration tests using injected HTTP transport and temporary SQLite/PDO where supported.
- Modify local `.env` only to add a generated encryption secret if none is configured; never print or commit it.

## Tasks

### Task 1: Add encrypted OCR key persistence

**Files:**
- Create: `migrations/2026_10_23_create_ocr_api_keys.sql`
- Create: `helpers/ocr_key_manager.php`
- Test: `tests/ocr_key_manager_test.php`

**Interfaces:**
- Produces `OcrKeyManager::encryptKey(string $key): string`, `decryptKey(string $ciphertext): string`, `listKeys(): array`, and `saveKey(string $key, ?int $id = null, ?string $label = null): int`.
- Uses `EMS_OCR_KEYS_ENCRYPTION_KEY` from environment/config; encryption uses AES-256-GCM with random IV and authentication tag.

- [x] Write failing tests for round-trip encryption, ciphertext tamper rejection, empty-secret rejection, and masked listing without plaintext.
- [x] Run `php tests/ocr_key_manager_test.php` and confirm expected missing-class/API failures.
- [x] Add migration and minimal manager implementation, including validation that keys are nonempty and bounded in length.
- [x] Rerun the focused tests and confirm encryption/storage behaviors pass.

### Task 2: Add health and monthly usage probes

**Files:**
- Modify: `helpers/ocr_key_manager.php`
- Test: `tests/ocr_key_manager_test.php`

**Interfaces:**
- Produces `probeUsage(int $id): array`, `testKey(int $id): array`, and `classifyProviderFailure(array $response): ?string`.
- HTTP transport is injectable for tests; production usage probe posts to `https://myapi.ocr.space/conversions` with the OCR key in the request header.

- [x] Add failing tests for usage count parsing, stale-through-yesterday freshness, Engine 1+2 monthly remaining estimate, and Engine 3 separate reporting.
- [x] Add failing tests that classify explicit auth/quota/rate errors as retryable and generic parse/TLS/network failures as nonretryable.
- [x] Run focused tests and verify they fail for the missing probe/classification behavior.
- [x] Implement provider parsing, health state updates, sanitized errors, and non-secret metadata persistence.
- [x] Rerun focused tests and confirm all provider response classifications pass.

### Task 3: Add bounded failover to identity scanning

**Files:**
- Modify: `helpers/ocr_key_manager.php`
- Modify: `dashboard/identity_test.php`
- Test: `tests/ocr_key_manager_test.php`

**Interfaces:**
- Produces `withKeyFailover(callable $scanWithKey): array`, passing each decrypted key once in round-robin order and returning the first success or final failure.

- [x] Add failing tests proving explicit quota/auth failure falls back once, while parse errors, TLS/transport errors, and success do not cause another request.
- [x] Run focused tests and confirm expected failures.
- [x] Wire identity scanning through the failover helper while retaining existing OCR payload, parsing, and user-facing result behavior.
- [x] Rerun focused tests and lint `dashboard/identity_test.php`.

### Task 4: Add same-page Programmer Roxwood key management

**Files:**
- Modify: `dashboard/ocr_api_status.php`
- Modify: `helpers/ocr_key_manager.php`
- Test: `tests/ocr_key_manager_test.php`

**Interfaces:**
- Page actions: add, replace, enable, disable, remove, test, refresh usage.
- Mutating actions require Programmer Roxwood access, POST, and the application's CSRF token mechanism.

- [x] Add failing tests for input validation and role/CSRF guards using existing project guard patterns.
- [x] Run tests and confirm expected failures.
- [x] Add masked key cards, action forms, usage estimates/freshness, provider limit labels, and sanitized result messages to the existing page.
- [x] Run focused tests and PHP lint on all changed PHP files.

### Task 5: Bootstrap existing key and verify end-to-end behavior

**Files:**
- Modify: local `.env` only if encryption secret is absent (untracked/local secret; never commit).
- Modify: `helpers/ocr_key_manager.php` for one-time import from `EMS_OCR_API_KEY`.
- Test: `tests/ocr_key_manager_test.php`

- [x] Add failing test that an existing configured key is imported once and repeated bootstrap does not duplicate it.
- [x] Implement idempotent one-time import; preserve the current configured key without outputting its value.
- [x] Run focused test, full PHP lint for changed files, project test suite, and `git diff --check`.
- [x] Run a live read-only MyAPI usage query using a stored key and confirm counts render without logging or displaying the secret.
- [x] Exercise controlled failover tests and inspect the final diff against every acceptance criterion in the spec.
