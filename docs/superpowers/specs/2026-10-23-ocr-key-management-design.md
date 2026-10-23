# OCR.Space Key Management Design

## Intent

Add OCR key management to the existing `/dashboard/ocr_api_status.php` page so a Programmer Roxwood can replace or disable keys, inspect per-key monthly usage, and let identity scanning fail over when a key is rejected or its quota is exhausted. The application cannot create provider keys; an administrator obtains each key from OCR.space and enters it here.

## Approaches

1. **Same-page admin controls with encrypted database storage (selected).** Keeps status, usage, and key controls in one page, supports multiple keys without editing source, and restricts secret access to Programmer Roxwood. Requires an application encryption secret in `.env`.
2. **Server environment/config list.** Keeps secrets out of the database but every key change requires server-file access and restart/reload; this conflicts with the requested in-page editing.
3. **Per-user keys.** Rejected because staff should not need to supply personal OCR credentials, and it exposes credential management across the user base.

## Behavior

- Add, replace, enable, disable, and remove OCR.Space keys on the existing status page. Render only a masked form/value; never return stored plaintext keys to the browser.
- Store key records encrypted at rest using an application encryption secret from `.env`. Only Programmer Roxwood can read or change key settings.
- Run the existing OCR health probe against the selected key. A manual test consumes a normal OCR conversion when the provider processes the sample.
- On each identity scan, try enabled keys in round-robin order. Move to the next key only after a provider response clearly identifies an invalid/rejected key, quota exhaustion, or rate limit. Do not repeat scans on generic document errors or transport/TLS failures that a different key cannot fix. Bound attempts to one per enabled key.
- Read monthly use from `POST https://myapi.ocr.space/conversions`, using the key in the request header. Display Engine 1/2 combined use against the documented 25,000 free monthly conversions and Engine 3 separately against its separate allowance. The provider's monthly conversion data is delayed through the end of the previous day; show that timestamp and label the remaining count as an estimate. If the provider cannot distinguish an invalid key from a valid key with zero use, show usage as unverified until a health test succeeds.
- Do not create additional keys or automate account registration. Keep key count small and use only keys the organization owns, in line with OCR.Space's anti-abuse guidance.

## Constraints and safety

- OCR.Space's public MyAPI page still describes PRO access, while its support forum announced free-key access in May 2026. The configured free key was directly queried and returned counts successfully, so implementation should treat the endpoint as available but handle future denial gracefully.
- Free monthly quota values and daily/rate limits must be displayed as provider-published limits, not availability guarantees. MyAPI usage is stale by about one day and does not replace checking OCR responses for quota errors.
- Keep OCR image upload and parsing behavior unchanged except for bounded key selection/retry on key-specific errors.

## Acceptance

- Status page shows masked key entries, monthly usage/estimated remaining by key, freshness timestamp, and controls to manage/test keys.
- Saving/replacing a key never exposes the existing secret; disabling/removing it takes effect on the next OCR call.
- A mocked quota/auth failure on one key causes exactly one fallback attempt; generic OCR parse errors and TLS failures do not cause fallback.
- PHP lint and focused tests pass. A live read-only MyAPI check confirms usage retrieval for the configured key without printing the key.
