# EMS2 — Project Guide for AI Assistants

> Read this file fully before touching code. It exists so a new session/model
> understands the whole system immediately, without re-exploring ~250 files
> from scratch. Last deep audit: 2026-07-30 (every directory read file-by-file).
> If you make a structural change, update the relevant section here in the
> same commit/session.

## 0. What this project actually is (read this first)

**EMS2 is NOT a real hospital system.** It is the internal staff-management /
admin panel for a **GTA V FiveM roleplay community**, run for the "Roxwood
Hospital" EMS faction (and a second, smaller unit called "Alta"). Every
domain word maps to a roleplay concept, not a real-world one:

| Term in code | Real meaning |
|---|---|
| "Patient" / "medical record" | An in-game roleplay medical scene write-up |
| "Medic" / "DPJP" / "doctor" | A roleplay staff member's in-character role |
| "Pharmacy sale" (`farmasi`) | In-game item/cash economy transaction logged for payroll |
| "Salary" / "bonus" | In-game currency payout to roleplay staff (real-money adjacent, not literal payroll) |
| "Police partnership" | Cooperative RP actions with the server's police factions (LSPD/LSSD/SASP/DOC) |
| "Recruitment" | Recruiting new roleplay medical staff / General Affair assistant managers |
| `docs/EMS/` (untracked) | In-character reference library: medical handbooks, SOPs, surgery "spell" scripts, faction vouchers — not source code |

Treat "PII" concerns (KTP/citizen ID, DOB, phone) as **in-game identity data**
tied to real Discord users, still worth protecting per `SECURITY.md`, but do
not assume real clinical/legal stakes when reasoning about correctness.

## 1. Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x, no framework — plain scripts + shared includes |
| DB | MariaDB/MySQL, PDO, prepared statements, `utf8mb4` (a few legacy `latin1` tables) |
| Frontend | Tailwind CSS 3.4 (custom design system, see §8), Alpine.js, jQuery |
| Tables/Charts | DataTables.net, Chart.js |
| Docs | `spipu/html2pdf`, `phpoffice/phpspreadsheet` (Excel import/export) |
| Realtime | Firebase Realtime Database (live chat + live music sync + presence) |
| Push | Web Push API via `minishlink/web-push`, VAPID |
| AI | Google Gemini (`gemini-2.5-flash` default) for recruitment scoring/summaries, birthday messages, training-group naming |
| OCR | OCR.Space cloud API (production); Tesseract.js-in-browser (dev/test tool only) |

No automated test framework is configured (`package.json` `"test"` is a stub).
No CI config found. `composer.json`/`package.json` are both denied direct web
access via `.htaccess`.

## 2. Directory map

```
auth/           Login/session/CSRF/access-guard logic (see §3)
config/         ~21 shared config + domain-helper files, incl. the ~2600-line config/helpers.php and config/dispatcher.php (Dispatcher module)
helpers/        3 small helper files (session refresh, user-docs JSON, GA cooperation)
partials/       header.php / sidebar.php / footer.php — the whole app shell + nav ACL
actions/        ~40 server-side action endpoints (AI recruitment engine, farmasi duty system, push, quiz)
ajax/           ~12 small autocomplete/search/file-preview endpoints for the dashboard
api/            External REST surface (Bearer-token auth) — currently just sales sync
cron/           Scheduled jobs (farmasi auto-offline, weekly salary, quiz finalize, cleanup)
dashboard/      ~131 pages — the actual application (see §5 for the module map)
public/         Unauthenticated recruitment portal + AI psychometric test + realtime chat embed
assets/design/  Self-built PHP "component" design system + Tailwind source/tokens (see §8)
storage/        Uploaded files (gitignored), served only via ajax/secure_file.php
migrations/     5 old one-off migration files (superseded by docs/sql/)
docs/sql/       61 chronological, numbered migration files — the REAL migration history
docs/           Product docs, module docs, deploy/security docs (see §9)
docs/EMS/       Untracked in-character reference binder (PDFs/handbooks) — not code, see §0
vendor/, node_modules/   Composer/npm deps (gitignored)
```

Root-level oddities worth knowing: `index.php` just redirects to
`dashboard/rekap_farmasi.php`. `pindah.php`, `backfill_operations.php`,
`.codex_tmp_boot.php`, `flush_setting_akun_cache.php`, `deploy-cron.php` are
one-off/ops scripts, all blocked from web access by `.htaccess`. A full
production DB dump `fouf9972_ems (1).sql` (~28MB) sits at repo root —
gitignored, don't read it wholesale (see §6 for extracted schema).

## 3. Auth, sessions, and access control

- **Login is full name + 4-digit PIN** (`password_hash`/`password_verify`),
  not email/password. `auth/login_process.php` handles it.
- **Single active device per account**: logging in elsewhere deletes all of
  the user's `remember_tokens`; a client-side poller
  (`auth/check_session.php`, called from `partials/footer.php` every ~30s)
  force-logs-out the old session's browser tab.
- **Remember-me**: `user_id:token` cookie, hashed token in `remember_tokens`,
  365-day expiry. `auth/auth_guard.php` auto-rehydrates `$_SESSION['user_rh']`
  from this cookie if the PHP session is gone.
- **Session shape**: `$_SESSION['user_rh']` = `{id, name, role, position,
  division, unit_code, can_view_all_units, cuti_*, tanggal_lahir_ic,
  file_kontrak_kerja}`. Also `$_SESSION['ems_active_unit']` for unit-switching.
- **CSRF**: `auth/csrf.php` keeps the **last 5 tokens valid** (not just the
  latest) to tolerate multi-tab use/regeneration races.
- **Rate limiting**: two independent file-based limiters
  (`storage/cache/login_rate_limit/`, `storage/cache/request_rate_limit/`) —
  not DB-backed, so they don't work correctly behind multiple app servers.
- **Profile-completion gate**: any `/dashboard/*` page (except
  `setting_akun*.php`) redirects back to `setting_akun.php` until
  `tanggal_lahir_ic` (and, for non-trainees, `file_kontrak_kerja`) is filled.
- **position_guard.php**: blocks `position === 'trainee'` from certain pages
  regardless of role.
- **request_guard.php**: `emsRequireJsonCsrf()` for JSON/AJAX CSRF checks,
  `emsRequireRateLimit($namespace, $identifier, $max, $window)` → 429.

### Role hierarchy (career/management ladder, `config/helpers.php`)
`staff` < `probation manager` / `assisten manager` / `lead manager` /
`head manager` (all "manager+") < `vice director` / `director`.
(`Interviewer & Trainer` and `Staff Manager` exist as later, narrower
recruitment/HR-access roles — added via migration `51_...`.)

### Position hierarchy (medical career ladder)
`trainee → paramedic → co_asst → general_practitioner → specialist`
(last hop is UI-flagged "coming soon", not fully wired into `ems_next_position()`).

### Division list (org unit, drives menu access)
`Medis, Executive, Secretary, Human Capital, Disciplinary Committee,
Human Resource, General Affair, Specialist Medical Authority, Forensic`.
Cross-division visibility rules live in `ems_can_access_division_menu()`:
Executive & Secretary see everything; Human Capital also sees Human
Resource + Disciplinary Committee; Forensic also sees Specialist Medical
Authority.

### Multi-unit ("unit_code"): roxwood vs alta
Most tables optionally carry `unit_code` (default `'roxwood'`, alt `'alta'`),
feature-detected at runtime via `SHOW COLUMNS`/`ems_column_exists()` rather
than assumed present. Users with `can_view_all_units=1` can switch context
via `?unit=` (persisted in `$_SESSION['ems_active_unit']`), exposed as a
toggle in the sidebar header. **Alta-scoped users get an almost entirely
different, smaller hardcoded sidebar** (`partials/sidebar.php`), and the
topbar hides notifications/inbox/live-music entirely when
`$currentUnit === 'alta'` (`$hideAltaTopbarUtilities`).

### Two parallel, hand-maintained ACL layers (can drift out of sync)
1. `config/helpers.php::ems_division_allowed_dashboard_pages()` +
   `ems_enforce_dashboard_page_access()` — the actual per-page server-side
   whitelist, called from `auth/auth_guard.php` on every dashboard request.
2. `partials/sidebar.php` — separately hand-built menu visibility logic.

A page removed from the sidebar may still be directly reachable, or vice
versa — **when adding a new page, update both.**

### Hardcoded superuser-by-name pattern (not a role/permission flag)
`ems_current_user_is_programmer_roxwood()` checks the literal full name
"Programmer Roxwood" (and a small hardcoded list including "Programmer
Alta") to unlock: AI settings (`dashboard/ai_settings.php`), storage audit
(`dashboard/storage_audit.php`), OCR API status, perf-instrumentation
overlays, and "protected account" self-only-edit rules in
`manage_users.php`. This is fragile (breaks if the account is renamed) —
know it exists before assuming role/division checks are the whole story.

## 4. Core shared files you'll touch often

- **`config/helpers.php`** (~2600 lines, guarded by `EMS_HELPERS_LOADED`) —
  the single biggest shared file: position/role/division/unit
  normalization, division ACL, cuti helpers, letter-formatting helpers,
  **file upload/compression pipeline** (`compressImageSmart()`,
  `uploadAndCompressFile()`, hard cap **1 MB** per upload via
  `emsUploadLimitBytes()` — note `.user.ini` allows 10MB at the PHP level,
  the app enforces the tighter cap itself), disciplinary point-threshold
  tables, and a Windows-only headless-Chrome document-preview feature
  (won't work the same on the Linux/cPanel production host implied by
  `deploy-cron.php`).
- **`partials/sidebar.php`** — full nav tree + role/division/unit branching +
  trainee page-blacklist filtering. Read before adding any menu item.
  Current group layout (main/non-Alta branch): **Utama** (Dashboard, Daftar
  Medis Roxwood, Kerja Sama Police, + conditional **Event** — only inserted
  if an active `events` row exists), **Medis** (EMS services, rekam medis,
  operasi plastik, sertifikat heli, + manager-only Input Dokumen Medis),
  **Farmasi** (rekap farmasi, konsumen, ranking, jam kerja, + manager-only
  Audit Billing Farmasi), **Keuangan** (reimbursement, konsumsi restoran,
  input kerja sama, gaji, regulasi medis/farmasi/roxwood hospital, rekap
  police), **Administrasi** (pengajuan jabatan, pengajuan cuti/resign,
  point pelanggaran saya, monitoring surat, + manager-only generator
  kelompok), **Pengaturan** (setting akun, + superuser AI/storage/OCR
  items), plus division-gated groups (Human Resource, Interview & Training
  / Recruitment, Disciplinary Committee, General Affair, Specialist Medical
  Authority, Forensic, Secretary). `struktur_organisasi.php`,
  `user_availability.php`, `emt_doj.php` are intentionally **not** in the
  nav (see §5 note) — don't assume every reachable dashboard page has a
  menu entry.
- **`partials/header.php`** / **`footer.php`** — farmasi duty "still
  online?" modal + heartbeat JS, inbox polling, birthday modal, live chat +
  live music widgets, session-validity poller.
- **`config/database.php`** — PDO bootstrap (`DB_HOST/NAME/USER/PASS/TIMEZONE`
  env vars, `Asia/Jakarta` timezone, `utf8mb4`).
- **`ems_secure_file_url()`** — every `storage/` path must be rewritten
  through `/ajax/secure_file.php?path=...`; direct `storage/` access is also
  blocked at the webserver level (`.htaccess`).

## 5. Dashboard module map (`dashboard/*.php`, ~131 files)

### Attendance / Leave / Resign
`absensi_ems.php` (weekly work-hour leaderboard from `user_farmasi_sessions`),
`pengajuan_cuti_resign.php`+`_action.php` (self-service leave/resign submit +
manager approval; approving resign deletes all `remember_tokens` and sets
`is_active=0`), `tracking_cuti_resign.php`+`_action.php` (manager monitoring +
early-return/"kembali kerja" action), `history_cuti_resign.php` (HR-only
audit), `user_availability.php` + `training_group_generator.php` (trainee
mentor-group formation, `config/training_groups.php`, AI-named via Gemini
with static fallback).

### Promotion / Position
`pengajuan_jabatan.php`+`_action.php` (self-service; auto-fills eligible
surgery cases from `medical_records`), `persyaratan_jabatan.php`+`_action.php`
(manager config of thresholds — **has an unconditional debug-log leak**,
see §7), `review_pengajuan_jabatan.php`+`_action.php` (manager
approve/reject, re-validates position path hasn't drifted since submission).

### Salary / Finance
`gaji.php`+`_action.php`+`_pay_process.php`+`_generate_manual.php`,
`rekap_gaji.php`, `reimbursement.php`+`_action.php`+`_delete.php`+`_pay.php`,
`restaurant_consumption*.php`, `restaurant_settings*.php`. **The 40%
bonus / 60% company split is real and verified in code** (`price * 0.4` /
`price * 0.6`), but duplicated independently in 3 places
(`dashboard_data.php`, `gaji_generate_manual.php`, `ranking.php`) rather
than centralized — a rate change needs all three touched.

### User / Org Management
`manage_users.php`+`_action.php`+`_export.php` (blocked for `staff` role;
"Kode Medis" `RH{batch}-{id}{name-letters}` generation; hardcoded
self-only-edit protection for "Programmer Alta"/"Programmer Roxwood"),
`setting_akun.php`+`_action.php`+`_quick_save.php` (self-service profile +
PIN change + document uploads), `struktur_organisasi.php` (org chart + PDF
export, excludes Medis division and specific user IDs — **removed from the
sidebar nav as of 2026-07-31**, reachable only by direct URL now, still
fully functional), `index.php` / `dashboard_data.php` /
`dashboard_data_medis.php` (landing page, routes Trainees to a
medical-only stats view).

### Events & Disciplinary
`events.php` (⚠ **no auth_guard** — open public registration, auto-creates
`user_rh` accounts from a bare name match; **the "Event" sidebar menu item
is now conditional** — `partials/sidebar.php` queries
`SELECT 1 FROM events WHERE is_active = 1 LIMIT 1` and only inserts the
menu entry into the "Utama" group when at least one active event exists),
`event_manage.php` /
`event_action.php` / `event_delete.php` / `event_participants.php` (manager
CRUD + random group generator). Disciplinary Committee module
(`disciplinary_cases.php`, `disciplinary_committee_action.php`,
`disciplinary_indications.php`, `disciplinary_points_monitor.php`,
`disciplinary_warning_letters.php`) — point thresholds:
≥100 final_warning, 70–99 written_warning_2, 40–69 written_warning_1,
20–39 verbal_warning, <20 coaching; active points = violation points minus
reduction points (floor 0).

### Other admin
`validasi.php`+`_action.php` (new-account verification; approve/reject
toggle `is_verified`+`is_active` together), `ranking.php`, `regulasi.php`
(editable pricing) / `regulasi_roxwood_hospital.php` (read-only poster),
`storage_audit.php` (superuser-only orphan-file scanner), `rekap_delete_bulk.php`
(⚠ **no CSRF or role check** — any logged-in user can mass-delete `ems_sales`
rows), `sync_from_sheet.php` (⚠ **no auth at all**, meant to run only via
cron — trusts a public Google Sheet CSV export to drive `sales` inserts),
`blacklist_names.php` (global, cross-unit citizen-ID blacklist), `emt_doj.php`
+`_action.php` (DOJ-delivery quota tracker — **removed from the sidebar nav
as of 2026-07-31**, direct-URL only, still fully functional).

**Sidebar visibility note (2026-07-31):** `struktur_organisasi.php`,
`user_availability.php`, and `emt_doj.php` were removed from every branch
of `partials/sidebar.php` (including both Alta-unit override blocks) per
user request — they still exist and work, they're just no longer linked
from the nav. If any of these should come back, re-add a `sidebarItem(...)`
call for them; don't restore by guessing the old array position, the
groups were also reorganized (see below).

### Dispatcher (added 2026-07-31)
`dashboard/dispatcher.php` (control board), `dashboard/dispatcher_action.php`
(controller), `dashboard/dispatcher_monitoring.php` (recap/history), plus
`config/dispatcher.php` (status catalog, code gen, formatters,
`ems_dispatcher_ensure_tables()`) and migration
`docs/sql/53_2026-07-31_dispatcher_module.sql`.

- **Purpose**: lets a dispatcher (any `manager-plus` role — no new division
  was created for this) coordinate what every Medis-division medic is
  currently doing: resting/10-7, meeting, visit, standby at reception,
  helping in the ER, or responding to a field call (with coordinate +
  optional location name), solo or as a group of 2+. Also serves as an
  accountability/monitoring log (who has responded outside, who helps in
  the ER, who is mostly idle) — this was the explicit point of the feature
  request: not just commanding medics, but recording their activity.
- **Data model**: `dispatcher_assignments` (one row = one status/task,
  historical — never deleted on completion, just flipped `status:
  active→cleared`) + `dispatcher_assignment_members` (pivot; 1 row = solo,
  2+ rows = group). A medic can only have **one active assignment at a
  time** — assigning them a new one auto-clears whatever active assignment
  they were previously part of (this also clears any teammates who were on
  that same shared assignment, by design, matching how the feature was
  specified: the dispatcher clears "the group that responded" as a unit,
  not individual members). No formal FK constraints (matches
  `police_partnership_records` convention — integrity enforced in PHP).
- **Status catalog** (`ems_dispatcher_status_options()` in
  `config/dispatcher.php`, static array, not DB-editable): `off_duty`
  (10-7/Istirahat), `rapat`, `kunjungan`, `standby_resepsionis`,
  `bantu_igd`, `respon_lapangan` (requires `coordinate`), `lainnya`
  (requires a free-text custom label). No assignment row = implicit
  "Tersedia" (available).
- **Access**: `dispatcher.php`/`dispatcher_monitoring.php` are viewable by
  anyone logged in (like `medical_roster.php`) — everyone can see the
  board and their own/others' history. Mutating actions (create/clear) in
  `dispatcher_action.php` require `ems_is_manager_plus_role()`; hard delete
  of history rows requires `ems_is_director_role()`. Since
  `ems_division_allowed_dashboard_pages()` in `config/helpers.php` only
  restricts **Medis**-division users (all other divisions return `null` =
  unrestricted), all three filenames were added to **all three** whitelist
  arrays there (Interviewer&Trainer-HR, Alta-medis, main Medis) — if you
  rename these files, update those arrays too.
- **Monitoring page** computes per-medic aggregates over a date range
  (counts per status code + total duration + a "Skor Kontribusi" = % of
  assignments that were `respon_lapangan`/`bantu_igd`/`standby_resepsionis`
  vs. total) plus a per-medic drill-down history modal (data baked into a
  `data-history` JSON attribute per row, same convention as the
  disciplinary edit-modal pattern).
- **Sidebar**: both pages live in the `Medis` group (`Dispatcher` +
  `Monitoring Dispatcher`), visible to everyone (button-level
  manager/director gating happens inside the pages, not the nav).
- Verified against the real local dev DB on 2026-07-31: migration applied
  cleanly, and a full create → board-query → clear → monitoring-query →
  cleanup cycle was smoke-tested with real `user_rh` rows before this note
  was written.

### Medical Records / Forensic
`rekam_medis.php`+`_action.php`+`_list.php`+`_view.php`+`_edit.php`+
`_edit_action.php`+`_delete.php` (Quill.js rich-text report; KTP mandatory,
supporting images optional; `visibility_scope` = `standard` or
`forensic_private`); the three `forensic_medical_records*.php` files are
thin wrappers that just force `mode=forensic_private`. Forensic case
workflow (`forensic_action.php`, `forensic_archive.php`,
`forensic_private_patients.php`, `forensic_visum_results.php`,
`forensic_medics*.php`) — all gated to `Forensic` division, case →
visum → archive pipeline with generated codes (`FCP-`/`FVR-`/`FAR-`).
**Edit/delete bypass**: creator OR Forensic division OR "programmer +
roxwood" name-match OR **Executive division** can edit/delete any record —
this rule is copy-pasted across 3 files, not centralized.
`input_dokumen_medis.php`+`_action.php` (manager uploads docs on behalf of a
medic — has its own duplicate compression function, not reusing the shared
one). `identity_test.php` — production KTP-OCR + versioned identity store
(`identity_master`/`identity_versions`); **not linked from the sidebar**,
reachable only by direct URL, and has its own hardcoded copy of the OCR API
key.

### Specialist Medical Authority
`specialist_authorizations.php`, `specialist_medical_authority_action.php`,
`specialist_medics.php`+`_export.php`, `specialist_operation_recap.php`+
`_export.php`, `specialist_operation_records.php`,
`specialist_promotion_assessment.php`, `specialist_training_recap.php` — all
gated to `Specialist Medical Authority` division; credentialing/training
records + surgical-activity recap built from `medical_records`.

### Pharmacy / Farmasi
`rekap_farmasi.php` (~7000 lines, the primary live sale-entry page — Citizen
ID based, blacklist check, daily caps 30 bandage/10 ifaks/10 painkiller,
1 tx/citizen-ID/day, 10s cooldown, auto duty-online tracking) vs
`rekap_farmasi_v2.php` (**parallel/likely-dead implementation** — identity_id
based, missing unit scoping/blacklist/cooldown — confirm which is actually
linked before assuming both are live). `farmasi_billing_audit.php`
(anomaly-scoring formula for suspicious sale patterns, see §7).
`konsumen.php` (consumer list + manual entry, Excel import).
`regulasi_farmasi.php` / `regulasi_medis.php` (pricing CRUD). Farmasi
duty-status (online/offline) lifecycle is implemented in `actions/*` (see
§6) and driven by `cron/check_farmasi_online.php`. Farmasi knowledge quiz
(10 Q per slot, pass = 7 correct, morning 06–18 / evening 18–06 WIB) lives
in `config/farmasi_quiz.php` + 3 `actions/*_quiz_*` endpoints.

### Certificates & Misc Medical Admin
`sertifikat_heli.php`+`_action.php`+`_pendaftaran.php` (GA opens a
registration window with slot cap; registering is open to any logged-in
user). `ems_services.php` (per-visit billing calculator — Pingsan/
Treatment/Surat/Operasi/Rawat Inap/Kematian/Plastik, gunshot-wound
medicine-cost swap, random price-within-tier for Operasi, fixed $20,280
total for Plastik). `ocr_api_status.php` / `tesseract_test.php` (OCR
diagnostics — see §7 for hardcoded key).

### Recruitment / Candidates (full pipeline — see §6 for the AI internals)
`candidates.php`+`_export.php` (medical track list), `assistant_manager_candidates.php`
(GA/assistant-manager track list — dedups to latest row per citizen_id,
unlike the medical track; grouped/filterable by GA's own "Pendaftaran N"
registration-round number (`medical_applicants.ga_batch` — unrelated to
`user_rh.batch`), and has its own independent open/close settings modal —
see §6 "Public recruitment portal journey"), `candidate_decision.php`
(final decision + lock interview), `candidate_detail.php`, `candidate_interview_multi.php`
(multi-HR scoring workspace, needs ≥2 distinct HRs to finalize).

### Secretary Module (`Secretary` division, also visible to `Executive`)
`secretary_action.php` (shared controller), `secretary_confidential_letters.php`,
`secretary_file_registry.php` (note: also surfaces GA "Input Kerja Sama"
rows tagged `ga_cooperation_input` in the same table, filtered out here),
`secretary_internal_coordination.php`, `secretary_visit_agenda.php`.

### General Affair / Kerjasama (institutional cooperation / free-package benefit)
`general_affair_kerjasama.php`+`_action.php` (institution + benefit config:
period type, claim scope per-person/per-institution, calc mode
manual/per-member-auto), `general_affair_kerjasama_history.php`+`_export.php`,
`general_affair_kerjasama_input.php`+`_input_action.php`+`_input_export.php`
(actual claim logging — reuses `secretary_file_records` table with a tag
convention rather than its own table).

### Police Partnership
`police_partnership.php`+`_action.php`+`_pay_process.php`+`_recap.php`+
`_recap_export.php` — logs cooperative RP actions with in-game police
factions; badge photo required; manager-only global recap + re-pricing
(per_qty/per_week/per_month) + "titip" (pay-on-behalf) support.

### Letters / Correspondence
`surat_menyurat.php`+`_action.php`, `surat_monitoring.php` (blocked for
`Medis` division), plus the public root-level `surat_instansi.php` (no
auth — external institutions request a meeting, auto-generates a letter
code). Tables: `incoming_letters`, `outgoing_letters`, `meeting_minutes`
(+ their `*_attachments` tables), revision tracking via `revision_count`/
`revision_label`.

### AI / Gemini Configuration
`ai_settings.php`+`_action.php` — gated to the single named "Programmer
Roxwood" superuser (documented stop-gap in
`docs/GEMINI_AI_RECRUITMENT_FOUNDATION_PLAN.md`); configures
`system_ai_settings` (API key, per-feature model, temperature/top_p/top_k,
daily request cap). AI features silently no-op/fall back if disabled —
the recruitment pipeline works fully without AI configured.

## 6. `actions/` + `ajax/` + `public/` — endpoint layer

### AI recruitment engine (the most complex subsystem in the codebase)
`actions/ai_gemini_client.php` (low-level Gemini REST client, daily quota +
full request/response logging to `system_ai_request_logs`) →
`actions/ai_scoring_engine.php` (pure psychometric scoring: 6 HEXACO-ish
traits — focus/social/obedience/consistency/emotional_stability/
honesty_humility — bias detection, cross-validation against free-text
answers, composite score, `recommended`/`consider`/`not_recommended`
decision) → `actions/ai_recruitment_service.php` (~1320 lines: Gemini-backed
or deterministic-fallback interview-question-pack generation, cached
candidate-summary generation) → `actions/interview_hybrid_scoring.php` +
`actions/interview_finalize.php` (combines ≥2 HRs' weighted scores into a
locked final grade) → `actions/status_validator.php` (enforces
`submitted → ai_completed → interview → final_review → accepted/rejected`).
Final combined-score formula used across candidate pages:
`combined = interview_score*0.6 + ai_score*0.3 + confidence*0.1`, auto-pass
if `combined >= 70 AND ai_recommendation !== 'not_recommended'`.

**Bias-detection bug fixed 2026-07-31**: `detectResponseBias()` originally
only checked the *literal* Ya/Tidak ratio and same-answer streak length.
This missed the classic "faking good" / social-desirability pattern: a
candidate who always picks whichever answer is *directionally favorable*
(Ya on normal-direction items, Tidak on reverse-scored ones) can look
"mixed" in raw Ya/Tidak counts (e.g. 29 Ya / 41 Tidak) while every single
trait scores ~100 — completely undetected by the old check. Fixed by
threading the per-trait `$traitItems` map (already built at every call
site for `calculateTraitScore()`) into `detectResponseBias($answers,
$traitItems)`, which now also computes a direction-adjusted "favorable
rate" across all non-trap items and flags `uniform_favorable_bias`
(rate ≥ 0.90) / `uniform_unfavorable_bias` (rate ≤ 0.10). Both feed into
`calculateCompositeScore()`'s penalty map (12/10 points) and — critically —
into `makeFinalDecision()`'s `count($biasFlags) === 0` requirement for a
`recommended` verdict, so a suspiciously perfect profile can no longer
auto-qualify as highly recommended. All 6 call sites (`ai_test_submit.php`,
`candidate_detail.php`, `candidate_decision.php`, `candidates.php`,
`candidates_export.php`, `assistant_manager_candidates.php`) were updated
to pass their trait-items map. Verified against real data reproducing the
reported bug (26 Ya/44 Tidak → all 6 traits at 100) — correctly flagged
after the fix, composite score dropped from 100 to ~88 (rule-based only).

**AI-assisted plausibility audit (added 2026-07-31)**: a second, optional
layer on top of the rule-based fix above.
`actions/ai_recruitment_service.php::ems_ai_review_assessment_plausibility()`
sends Gemini the trait scores + bias flags + Ya/Tidak counts + duration
(no raw answer text) and asks for a JSON verdict
`{"realistic": bool, "penalty": 0-25, "note": "..."}`. Wired into
`dashboard/candidate_detail.php` only (not the list/export pages) as a new
"Audit Kewajaran Skor (AI Gemini)" card: lazily generated on first view
(same cached-via-`system_ai_request_logs` convention as
`ems_ai_generate_candidate_summary()`), persisted into
`ai_test_results.risk_flags.ai_audit` (no schema migration needed — reuses
the existing `risk_flags` JSON column), with a manual "Audit Ulang dengan
AI" regenerate button. The stored `penalty` is threaded into
`calculateCompositeScore()`/`makeFinalDecision()` via a new optional
`$extraPenalty` param (composite-score caps raised 38→45 assistant_manager,
30→35 medical, to give both rule-based and AI penalties room to matter).
Silently no-ops if `system_ai_settings.is_enabled` is off or no API key —
scoring still works fully without it. Verified with a real Gemini call
(model `gemini-2.5-flash-lite`) against the same reproduced-bug scenario:
independently returned `realistic: false, penalty: 25` with a correct
Indonesian explanation, before persistence/UI were exercised via HTTP.

### Public recruitment portal journey
Two **independent** public entry points, each with its own open/close
toggle (see below) — as of 2026-07-31 they are deliberately decoupled so
closing one never blocks the other:
- Medical track: `public/index.php` (Citizen-ID gate, re-derives stage from
  DB every request — session is just a cache; new/unregistered citizen IDs
  default to `recruitment_type=medical_candidate` here) → `recruitment_form.php`
  → `recruitment_submit.php` → `public/ai_test.php` (50 fixed Ya/Tidak Qs)
  → `ai_test_submit.php` → `recruitment_done.php`.
- Assistant-manager (GA) track: `public/ga_recruitment.php` (same Citizen-ID
  gate shape, but forces `recruitment_type=assistant_manager` for new
  citizen IDs and checks the GA-specific portal setting) →
  `recruitment_form_assistant_manager.php` (has a hardcoded bypass Citizen
  ID `RH39IQLC` that skips document requirements; applicant must already
  exist in `user_rh` with KTP/SKB/KTA/SIM uploaded — this track recruits
  **existing staff**, not brand-new external people) → `recruitment_submit.php`
  (shared endpoint, branches on `recruitment_type`) → `public/ai_test.php`
  (70-of-500 random-but-deterministic Qs with "trap" reverse-scored items)
  → `ai_test_submit.php` (runs `ai_scoring_engine.php`, flips status to
  `ai_completed`) → `recruitment_done.php` (shared "thank you" page).

**Per-track open/close**: `recruitment_portal_settings` has one row per
`track` (`medical_candidate` id=1, `assistant_manager` id=2 — see
`config/recruitment_settings.php`, all functions take a `$track` param
defaulting to `medical_candidate` for backward compat). Every
`ems_public_recruitment_require_portal_open($track)` call site across
`public/*.php` derives the correct track before checking (from the gate
session, `$_GET`/`$_POST['recruitment_type']`, or a hardcoded value for
track-specific entry pages) so a mid-flow applicant is redirected to
`recruitment_closed.php?track=...` for *their own* track, never the other
one. `recruitment_closed.php` reads `?track=` to show the right message.
`dashboard/assistant_manager_candidates.php` has its own "Setting
Rekrutmen" modal (mirrors `candidates.php`'s, gated to General
Affair/HR/Executive/programmer) to toggle the GA track independently of
the medical one on `dashboard/candidates.php`.

**GA candidates are grouped by "Pendaftaran" (registration round), not
staff batch** (corrected 2026-07-31 — an earlier version of this doc/the
first implementation pass wrongly joined `user_rh.batch`; the user meant a
GA-specific recruitment-round counter instead, unrelated to a staff
member's original medical/staff intake batch). Model:
- `medical_applicants.ga_batch` (INT NULL, only meaningful when
  `recruitment_type = 'assistant_manager'`) — which "Pendaftaran N" round
  an applicant registered under. Set once at submission time in
  `public/recruitment_submit.php` from the GA settings row's current
  `current_batch` value.
- `recruitment_portal_settings.current_batch` (INT, per track, only
  actually used for `assistant_manager`) — the number GA admins are
  currently accepting registrations under. Edited via a plain "Nomor
  Pendaftaran Saat Ini" input in `assistant_manager_candidates.php`'s
  settings modal (manual, not auto-incremented — admin types `2` to start
  "Pendaftaran 2", etc.); saved through
  `ems_recruitment_save_settings(..., $track, $currentBatch)`.
- All GA candidates that existed before this feature (2026-07-31) were
  backfilled to `ga_batch = 1` via `docs/sql/55_2026-07-31_ga_batch_pendaftaran.sql`
  — i.e. "Pendaftaran 1" retroactively means "everyone recruited before
  per-round tracking existed."
- `assistant_manager_candidates.php` offers a `?batch=` filter
  ("Pendaftaran 1", "Pendaftaran 2", ...), shows it as a table column, and
  sorts newest-round-first by default. **When the page is opened with no
  `?batch=` param at all**, it auto-filters to the currently active
  `current_batch` (not "Semua Pendaftaran") so the admin lands directly on
  the round they're actively recruiting for; picking "Semua Pendaftaran"
  from the dropdown explicitly submits `?batch=` (empty string) to see
  everything. The active round is also injected into the dropdown (with a
  "(Aktif)" suffix) even if it has zero candidates yet.

**Cross-track applicant lookup bug fixed 2026-07-31**:
`ems_public_recruitment_find_applicant()` (`public/recruitment_gate.php`)
originally looked up the latest `medical_applicants` row by **citizen_id
alone**, with no `recruitment_type` filter. Since a single real person can
plausibly have applied under *both* tracks over time (e.g. medical trainee
months ago, now applying via GA as existing staff), this meant visiting
`public/ga_recruitment.php` with a citizen ID that had an old
`medical_candidate` row would silently hijack the gate into that old
application's track/stage — sending the person to the medical form, the
medical "done" page, or (if the medical portal happened to be closed, as
it usually is) `recruitment_closed.php?track=medical_candidate`, even
though they came in through the GA-specific entry point and the GA portal
itself was open. Fixed by adding a `$preferredType` param to
`ems_public_recruitment_find_applicant()` and having
`ems_public_recruitment_build_gate()` pass its own `$defaultType` through,
so each track-specific gate page (`public/index.php` → `medical_candidate`,
`public/ga_recruitment.php` → `assistant_manager`) only ever matches an
existing applicant **within its own track** — a citizen ID with no
application in that specific track is correctly treated as brand new.
Verified with a real citizen ID that had a completed medical application:
GA gate now correctly routes to `recruitment_form_assistant_manager.php`
instead of the medical flow; the medical gate's own behavior for that same
citizen ID is unchanged (still resumes its real medical application).

**GA candidate hard-delete is intentionally NOT shared with the medical
track's delete logic** (added 2026-07-31): `assistant_manager_candidates.php`
has its own `gaCandidateDeletePermanently()`, separate from
`candidates.php`'s `candidateDeletePermanently()`. Reusing the medical
version would be unsafe for two reasons specific to the assistant-manager
data model: (1) `applicant_documents.file_path` for a GA applicant points
at the **same file** as their existing `user_rh` KTP/SKB/KTA/SIM (copied
by reference at submission time, not a separate upload — see §6), so
unlinking it would delete the real staff member's actual profile document
from disk; (2) a GA applicant is required to already own a `user_rh`
account *before* applying (see §6 "Public recruitment portal journey" —
this is a promotion pathway, not new-hire creation), so the medical
version's "delete the linked `user_rh` row created by acceptance" cleanup
would, if ever triggered, delete a **pre-existing real staff account**
instead of one the recruitment flow created. `gaCandidateDeletePermanently()`
therefore only deletes `medical_applicants` + its recruitment-process child
rows (`applicant_documents`, `ai_test_results`, `applicant_interview_*`,
`applicant_final_decisions`) and never touches `user_rh` or the filesystem.
Gated to General Affair/Human Capital/Human Resource/Executive division
(`gaCandidateCanHardDelete()`) or the "Programmer Roxwood" superuser.
Verified locally: deleting a test GA applicant removed all of its rows
while the real `user_rh` account and its document files on disk were
untouched. **Separately discovered while building this (not yet fixed)**:
`candidateCreateManagerUserFromApplicant()` in `candidate_decision.php`
throws if a `user_rh` row already exists for the candidate's citizen_id/name
— since every GA applicant already has such a row by definition, accepting
("lolos") a real assistant-manager candidate today would likely error out
at that step. Flagged for a future session, out of scope here.

### Farmasi (pharmacy) duty/online-status lifecycle
Go online/offline: `actions/toggle_farmasi_status.php` (caps: `max_online_medics`,
per-user `cooldown_minutes` from `farmasi_online_settings`). Confirm-still-online:
`actions/confirm_farmasi_online.php`. Heartbeats: `actions/heartbeat.php` (rate
limited) + `actions/ping_farmasi_activity.php`. Auto-offline: `actions/
check_farmasi_duty_limits.php` (client poll) → `actions/auto_offline_farmasi.php`
(state change) — also enforced server-side by the `cron/check_farmasi_online.php`
sweep, which also fires push notifications. Admin force-offline:
`actions/force_offline_medis.php` (logs to `user_farmasi_force_logs`, notifies
the target via inbox). Fairness nudge: `actions/get_fairness_status.php`
(blocks the top-transaction online medic once the gap to the lowest is ≥10).
Global inter-sale cooldown: `actions/get_global_cooldown.php` (60s, waived
15:00–18:00 & 21:00–03:00 WIB "peak hours"). Settings editable only by
General Affair/Executive/Disciplinary Committee (`actions/save_farmasi_settings.php`).
A near-identical mini-system exists for **training availability**
(`actions/toggle_user_availability.php`, `config/training_groups.php`).

### Push notifications
`public/push-subscribe.js` → `actions/save_push_subscription.php` (stores
VAPID subscription) → `actions/push_send.php` (included by cron/trigger
scripts with `$PUSH_USERS`/`$PUSH_TYPE` preset; types: `idle_warning`,
`offline`, `operasi_plastik_request`, `medical_record_contact_incoming`).

### `ajax/secure_file.php`
The single authenticated file-streaming gateway for everything under
`storage/` — per-prefix rules for identity photos, reimbursements,
disciplinary attachments, secretary records, user/applicant docs,
restaurant KTP, letters, police badges, and medical records (enforcing
`forensic_private` → Forensic-division-only).

### `api/` (external REST surface)
Bearer-token auth (`api/middleware/auth.php` checks `Authorization` +
`X-Client-ID` against `api_tokens` table). Currently one real endpoint:
`api/sync_sales.php` (returns unsynced `sales` rows for an external
Sheets/game-server sync job).

## 7. Cron jobs (`cron/*.php`)

| File | Does | Cadence |
|---|---|---|
| `check_farmasi_online.php` | Force-offline over duty-limit / idle ≥30min warn → 2min deadline → auto-offline; push notifications; calls farmasi cleanup | Every few minutes |
| `cleanup_farmasi_tables.php` | 14-day retention on `farmasi_activities` + completed quiz questions | Daily |
| `cron_cleanup_identity_temp.php` | Deletes `storage/identity/tmp*.jpg` >24h old | Daily |
| `finalize_farmasi_quiz_weekly.php` | Rolls up weekly quiz winners | Weekly |
| `generate_weekly_salary.php` | Per-unit, per-week backfill of `salary` rows, `bonus_40 = floor(total*0.4)`, file-locked | Weekly |
| `send_birthday_evening_inbox.php` | Sends birthday inbox message (only fires after 19:00 local, self-guarded) | Evening, safe to run often |
| `update_cuti_status.php` | Auto-expires `user_rh.cuti_status` past `cuti_end_date` | Daily |
| `test_idle_push_1menit.php`, `test_push_cron.php` | Manual/debug scripts, not production cron | Ad hoc |

## 8. Design system (`assets/design/`)

Custom, framework-free "component" layer of PHP partials (no DB/business
logic allowed by convention — see `assets/design/components/README.md`),
loaded via `ems_component('ui/card', [...])`. `tokens/theme.json` is the
single source of truth for colors/spacing/radius/typography/shadows, mirrored
into `tailwind.config.js`. Primary color `#0ea5e9`. Build: `npm run
build:css` → `assets/design/tailwind/build.css`. The legacy dashboard is
being incrementally migrated onto this system — track status in
`docs/ui-refactor-progress.md` / `docs/ui-refactor-todo.md` before assuming
a page uses the new components. A **separate, not-yet-built** React/Laravel
frontend rewrite is planned (`docs/PRD_MEDICAL_SERVICE_FRONTEND_REDESIGN.md`)
— no code for it exists in this repo yet, it's forward-looking only.

## 9. Database — 100 tables (97 + `police_partnership_records` + 2 dispatcher tables added later)

Full authoritative history is `docs/sql/` — **64 chronological, numbered
migration files** (`01_...` → `55_...` plus a handful of unnumbered early
`2026-03-07_*` files), spanning 2026-03-07 → 2026-07-31. The 5 files under
`migrations/` are older/superseded one-off scripts (kept for history only).
The repo also has a full DB dump at root (`fouf9972_ems (1).sql`, gitignored,
~28MB) — extract schema with targeted `sed`/`grep`, never read it wholesale.

**Central tables** (see full column detail by grepping the dump if needed):
`user_rh` (staff roster/auth — role/position/division/unit_code, career
dates, document paths, cuti/resign lifecycle), `medical_records`,
`medical_record_assistants`, `medical_record_supporting_images`, `sales`
(farmasi tx), `ems_sales` (EMS-services tx), `cuti_requests`,
`resign_requests`, `salary`, `reimbursements`, `medical_applicants` (+
`applicant_documents/interview_question_packs/interview_question_responses/
interview_results/interview_scores/final_decisions`, `interview_criteria`,
`ai_test_results`), `disciplinary_cases` (+ `_items/_attachments`,
`disciplinary_indications`, `disciplinary_point_reductions`,
`disciplinary_warning_letters`+attachments), `position_promotion_requests`
(+ `_requirements`, `_request_operations`), `farmasi_online_settings`,
`user_farmasi_status`/`_sessions`/`_notifications`/`_force_logs`,
`farmasi_activities`, `farmasi_quiz_*` (5 tables), `farmasi_audit_reviews`,
`dispatcher_assignments`/`_members` (dispatcher status/field-response
coordination, see §5 Dispatcher),
`farmasi_hospital_billing_entries`, `forensic_private_patients`,
`forensic_visum_results`, `forensic_archives`, `specialist_authorizations`,
`specialist_promotion_assessments`, `specialist_training_records`,
`secretary_*` (visit agendas / internal coordinations / confidential
letters / file records, each + `_attachments`), `general_affair_cooperations`
(+ `_members`/`_packages`), `general_affair_visits`, `police_partnership_records`,
`incoming_letters`/`outgoing_letters`/`meeting_minutes` (+ attachments),
`identity_master`/`identity_versions`, `consumer_blacklist`, `packages`,
`medical_regulations`, `sertifikat_heli_registrations`/`_settings`,
`restaurant_consumptions`/`_settings`, `events`/`_participants`/`_groups`/
`_group_members`, `training_groups`/`_members`, `training_user_availability`
(+`_sessions`), `emt_doj`/`_deliveries`, `system_ai_settings`/
`_request_logs`/`_prompt_templates`, `user_inbox`/`_state`,
`user_push_subscriptions`, `remember_tokens`, `api_tokens`, `account_logs`/
`account_update_logs`, `recruitment_portal_settings`.

Schema evolution is almost entirely **feature-flagged at runtime**
(`ems_column_exists()`/`ems_table_exists()` checks scattered through nearly
every controller) rather than assumed-present — expect older columns/tables
to sometimes be missing on a given install, and write new code the same
defensive way.

## 10. Cross-cutting gotchas (things a fresh session would otherwise waste
    a session rediscovering)

1. **Hardcoded plaintext secrets committed to the repo**: OCR.Space API key
   in `config/ocr_config.php` (and duplicated again inline in
   `dashboard/identity_test.php`), and the VAPID push key pair in
   `config/push.php`. Low real-world stakes (RP community tool) but flag
   before treating as a template for new secrets, and rotate if this is
   ever forked/exposed more widely.
2. **Access-control inconsistency**: most mutations check CSRF + role/division,
   but `dashboard/rekap_delete_bulk.php`, `dashboard/events.php` (fully
   public, no auth_guard), `dashboard/sync_from_sheet.php` (no auth at all,
   cron-only by convention not enforcement), and the GET detail endpoints in
   `pengajuan_cuti_resign_action.php` are exceptions — don't assume every
   endpoint is guarded the same way.
3. **Duplicated logic across files** (candidates for refactor, not
   necessarily bugs): the 40%/60% salary split (3 places), image-compression
   routines (`compressImageSmart()` vs `compressManagerDocImage()` vs
   inline copies in `identity_test.php`/restaurant module), and the
   medical-records superuser-bypass rule (3 files).
4. **Two parallel farmasi sale implementations**: `rekap_farmasi.php`
   (primary, full business rules) vs `rekap_farmasi_v2.php` (older/simpler,
   missing several safeguards) — confirm which is actually linked from the
   sidebar before modifying either.
5. **Debug leftovers in production paths**: `persyaratan_jabatan_action.php`
   unconditionally dumps `$_POST` to `logs/debug_persyaratan*.log` on every
   save; `setting_akun*.php` has a perf-instrumentation overlay gated to the
   "Programmer Roxwood" superuser name.
6. **Windows-only dev feature**: `emsFindHeadlessBrowserPath()` in
   `config/helpers.php` hardcodes Windows Chrome/Edge paths for a
   document-preview feature — won't function on the Linux/cPanel production
   host implied elsewhere in the repo (`deploy-cron.php` paths).
7. **Upload size layering**: `.user.ini` allows 10MB at the PHP level, but
   the app enforces its own **1MB** cap (`emsUploadLimitBytes()`,
   disciplinary attachments capped tighter at 500KB) and aggressively
   re-compresses images to hit target sizes rather than just rejecting.
8. **`docs/EMS/`** is untracked (shows as `??` in `git status`) and contains
   real in-character PDFs/handbooks — not in `.gitignore` explicitly, so an
   accidental `git add -A` could stage tens of MB of binary docs. Consider
   adding an explicit `.gitignore` entry if that risk matters to you.
9. **Leaked "access denied" flash message pattern**: `ems_enforce_dashboard_page_access()`
   (`config/helpers.php`) queues `$_SESSION['flash_errors'][] = 'Akses
   halaman ditolak untuk division Anda.'` before redirecting away from a
   blocked page. Because `flash_errors` is only cleared by whichever page
   next reads it, this message can surface on the *next* page the user
   visits even if that page is universally accessible — several
   "everyone can access this" pages defensively filter this exact string
   out of their flash errors before display (`emt_doj.php`,
   `pengajuan_jabatan.php`, `police_partnership.php`, `rekap_farmasi.php`,
   `sertifikat_heli_pendaftaran.php`, `setting_akun.php`, and — fixed
   2026-07-31 — `konsumen.php`). **If you add a new page that's exempted in
   `ems_enforce_dashboard_page_access()`'s early-return list, add the same
   filter** on its flash-errors read, or the same stray-toast bug will
   reappear there.

## 11. Existing documentation index (read these for deep dives instead of
    re-deriving from code)

- `README.md` — quick start, tech stack, module list.
- `docs/INSTALLATION.md` — full local setup + hardening + upgrade checklist.
- `docs/RELEASE_CHECKLIST.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`.
- `docs/deploy/ROTATE_CREDENTIALS.md` + `apache-upload-exec-block.conf` +
  `nginx-security.conf` — production hardening reference.
- `docs/ui-design-system.md`, `docs/ui-migration-plan.md`,
  `docs/ui-refactor-progress.md` / `-todo.md` — design system rollout status.
- `docs/FIREBASE_REALTIME_CHAT_SETUP.md` — live chat/music/presence setup.
- `docs/PRD_MEDICAL_SERVICE_FRONTEND_REDESIGN.md` — planned (not yet built)
  React/Laravel frontend rewrite; phase one won't touch the current DB/schema.
- `docs/GEMINI_AI_RECRUITMENT_FOUNDATION_PLAN.md`, `docs/
  ASSISTANT_MANAGER_RECRUITMENT_FLOW.md` — AI recruitment design docs.
- `docs/DISCIPLINARY_COMMITTEE_MODULE.md`, `docs/FORENSIC_MODULE.md`,
  `docs/SECRETARY_MODULE.md`, `docs/SPECIALIST_MEDICAL_AUTHORITY_MODULE.md`.
- `docs/prd-rekam-medis.md`, `docs/IMPLEMENTATION_PLAN_REKAM_MEDIS.md`,
  `dashboard/README_REKAM_MEDIS.md` — medical-records feature history (note:
  the schema described there is an **earlier draft**, not what's deployed —
  see §5/§9 for the real runtime schema).
- `docs/progress_cuti_resign.md` — leave/resign feature build log.
- `docs/CODEX_FOR_OSS_APPLICATION.md` — OSS-program prep notes.
- Two other AI-generated snapshots exist at repo root
  (`PRODUCT_SPECIFICATION.md`, `PRODUCT_SPECIFICATION_CURRENT.md`,
  `IMPLEMENTATION_SUMMARY.md`) — gitignored, locally-only, may go stale;
  this `CLAUDE.md` supersedes them as the authoritative map going forward.

## 12. Working conventions

- Every protected page: `session_start()` → `require auth/auth_guard.php`
  (+ often `auth/request_guard.php`) → `require config/database.php`. Follow
  this order for new pages.
- All UI text is Bahasa Indonesia; icons via the Heroicons helper
  (`assets/design/ui/icon.php`), not emoji (emoji removal is an active
  cleanup goal per `docs/ui-migration-plan.md`).
- New DB columns/tables should be added via a new numbered file in
  `docs/sql/` (next number after `52_...`) and guarded in PHP with
  `ems_column_exists()`/`ems_table_exists()` until you're sure it's rolled
  out everywhere — this matches how every existing module was built.
- When adding a dashboard page: register it in both
  `config/helpers.php::ems_division_allowed_dashboard_pages()` (if
  division-restricted) and `partials/sidebar.php` (menu entry) — see §3.
