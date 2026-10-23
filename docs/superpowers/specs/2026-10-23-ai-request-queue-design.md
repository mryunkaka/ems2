# AI Request Queue Design

## Goal

Prevent concurrent AI model calls from exhausting the shared hosting resources, while giving users a clear waiting screen until the requested AI work has finished.

## Current behavior

- Global Gemini and OpenAI-Compatible text calls are dispatched through `ems_gemini_generate_content()` and `ems_custom_chat_completion()` in the AI clients. Image generation also calls Gemini directly.
- The global AI settings enforce a daily request limit, but do not cap simultaneous calls.
- `candidate_interview_multi.php` can generate a candidate summary and an interview question pack during a page request when cached results are missing. In particular, an interview question pack contains 20 questions. Concurrent page opens can therefore make concurrent model calls and hold PHP web workers open.
- Other central callers include Roxy chat/deep research, candidate summaries and interview packs, birthday and work anniversary content, training group generation, and provider connection tests.
- The screenshot reports HTTP 500, but local source and the screenshot do not identify its production stack trace. Preventing AI bursts is the confirmed code-level issue; diagnosing any additional 500 cause requires production logs.

## Design

### Queue and worker

Add a durable MySQL queue for AI work. A queued record contains a job type, the requesting user ID, a short display label, minimal feature-specific references/options, timestamps, state, and result or sanitized error. It must not contain provider API keys or a rendered prompt. Inputs that are not already stored must be persisted by the feature before the job is enqueued; the worker reloads them by reference.

The lifecycle is `queued -> running -> completed|failed`. Jobs are claimed atomically in FIFO order. Database-backed worker-slot locks prevent overlapping cron invocations from exceeding the configured concurrency. The default maximum concurrent model jobs is **1**; administrators can choose 1, 2, or 3. The waiting-queue cap defaults to **50** and is configurable from 10 to 200. The CLI worker processes jobs until its 50-second run window expires and exits cleanly for the next cron invocation. A model call already in progress is allowed to finish even if it exceeds that window; slot locks prevent its replacement from starting early. Expired `running` jobs are marked failed after the configured provider timeout plus a 30-second lease margin and are not retried automatically, avoiding duplicate model cost or duplicate side effects.

The AI settings page will expose the concurrency and queue-cap settings and a compact queue monitor (queued/running/failed counts plus the current job). The deployment instructions will document a once-per-minute PHP CLI cron command. A full queue is rejected with a clear message rather than accumulating unbounded work. Repeated submission of the same user/feature/entity while it is queued or running returns the existing job. Completed and failed job rows are retained for seven days; cleanup never deletes active jobs.

### Request and user experience

AI-triggering interactions enqueue work and return a job ID immediately; they do not wait for a provider response inside the originating web request. An authenticated status endpoint returns only the caller's job result and safe queue metadata. A shared loading screen polls with a bounded interval/backoff and displays:

- the caller's name, requested AI feature, queue position, and current state;
- the name and feature label of the globally active job, without candidate or prompt details;
- a progress indicator for `menunggu`, `sedang diproses`, `selesai`, or `gagal` (provider progress is not available, so processing is an indeterminate progress state);
- the final result or a safe error, then the feature continues to its result page/state.

Status polling releases the PHP session lock before responding. Job IDs are authorization-checked; users cannot read another user's result. The global active-job display contains only the requesting user's display name and the configured feature label.

### Feature integration

All callers of the global AI provider, including text and image generation, must pass through the queue worker. Feature handlers preserve their current outputs and persistence rules while moving the model work out of web requests. The first page-level integration is candidate interview: opening the page must not trigger AI generation; if required summary/questions are absent, the user sees the queue screen, and the interview page opens or refreshes only after the results are stored. Existing cached results continue to load without a new job.

Roxy chat, training group generation, birthday/work-anniversary content, and connection tests will use the same queue path where they invoke the global provider. Non-interactive scheduled tasks enqueue work and leave delivery/persistence to their existing feature handler. Personal provider configuration remains subject to the global AI enablement and concurrency policy; no path may bypass the worker to call a configured global model directly.

### Failure handling and operations

- Provider/handler errors mark the job `failed`, save a sanitized user-facing message, and preserve diagnostic details in server logs.
- A worker crash leaves a `running` job that is failed after its lease expires; it is not silently lost or executed twice.
- The queue has a configurable maximum number of waiting jobs and bounded retention for completed/failed records. Active work is never removed by retention cleanup.
- Workers verify the global AI setting before execution. Disabling AI prevents new jobs and causes queued jobs to fail clearly without calling a provider.
- The queue monitor makes a stalled worker visible. The cron command must use the deployed PHP CLI binary and project path; it must not be web-accessible.

## Security and privacy

Use authenticated enqueue/status endpoints and CSRF protection for state-changing requests. Bind every status/result read to the requesting user, except for restricted administrator monitoring. Escape all names and labels in the loading UI. Do not expose prompts, provider responses, API keys, or candidate-sensitive fields in global queue status. Apply bounded size validation to job references/options and result payloads.

## Acceptance criteria

1. Ten simultaneous users requesting AI work create ten visible jobs, but at the default setting no more than one worker calls a model at a time; web requests return promptly instead of waiting on the model.
2. The loading screen identifies who is using the model for which feature and shows queue position/state while the result is pending.
3. Candidate interview page opening never starts an AI call. Missing AI-generated interview content is prepared as a queued job, and the interview opens with the saved result after completion.
4. Existing cached results do not enqueue duplicate work, and repeated clicks for the same active user/feature/entity reuse the active job.
5. Every global text/image provider call site is routed through the queue; there is no direct-call bypass from web requests.
6. Failed, full-queue, disabled-provider, stale-worker, and unauthorized status cases produce clear, bounded behavior without leaking another user's result.
7. The settings page shows concurrency and queue health, and deployment documentation describes the once-per-minute cron worker.

## Out of scope

This change does not prove or repair unrelated causes of the reported production HTTP 500 without server logs. It does not add automatic retries, provider streaming progress, priority scheduling, or a distributed message broker.
