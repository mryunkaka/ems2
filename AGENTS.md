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
`restaurant_consumption.php` edit (added 2026-07-31): gated to General
Affair division manager-plus roles or Director (`$canEditConsumption` in
the page, mirrored in `restaurant_consumption_action.php`'s `update`
action) — mirrors the existing `delete` action's director-only pattern but
scoped to GA managers instead. Only editable while `status` is `pending`
or `approved` (never `paid`, to protect the financial record once
settled); price/tax/subtotal/total are always recomputed server-side from
`restaurant_settings` + the submitted `packet_count`, never trusted from
client input, matching the `create` action's approach. Re-uploading a KTP
photo on edit replaces and deletes the old file; leaving it blank keeps
the existing one.

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

### Roxy Internal Assistant (updated 2026-09-28)

`dashboard/ai_assistant.php` and the footer bubble share
`actions/roxy_chat_action.php`, `ajax/roxy_conversations.php`, and
`config/roxy_chatbot.php`. Conversation history, lookup, and deletion are
user- and active-unit-scoped. Chat answers are generated before message
persistence; the user/bot pair is then written in one transaction so provider
failures do not leave incomplete turns. Corrections from chat go to
`actions/roxy_correction_action.php`; pending corrections are reviewed in
`dashboard/ai_assistant_monitoring.php` by manager-plus. Only approved answers
are reused, and only for the exact same normalized question within that unit.
Manager-plus maintains unit-scoped SOP/how-to articles in
`dashboard/ai_assistant_knowledge.php`; that page is added to both the
dashboard whitelist in `config/helpers.php` and the Roxwood AI
sidebar group. Migration `docs/sql/89_2026-09-28_roxy_answer_corrections.sql`
adds correction and learned-answer storage. Pending or rejected corrections
must never be sent back to the model as trusted knowledge.
For policy/SOP questions, Roxy compares `document_files.source_file_sha256`
with the stored source; if the file changed, it refreshes extraction before
retrieval/comparison. Section paths (Pasal/Poin) are extracted from source
text and included as evidence; do not let the model invent section numbers.
Migration `docs/sql/90_2026-09-28_document_source_integrity_hash.sql` adds
the source hash used by this check.

Medical name suffixes/prefixes are display-only: use
`ems_format_medical_display_name()` and never modify `user_rh.full_name`
(login and identity matching depend on the raw name). Trainees have no title;
paramedics show `A. Md Kep`, co-assistants `S. Ked`, general practitioners
`dr.`, and specialists `dr.` plus the comma-separated `specialist_degrees`.
Specialist degree entry is required in Setting Akun and enforced by
`auth/auth_guard.php` for dashboard access. Migration
`docs/sql/91_2026-09-28_user_specialist_degrees.sql` adds the field.

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

`ai_settings_personal.php` exposes the OpenAI-compatible custom provider to
all signed-in users. Its provider, API key, endpoint, and model are stored
under the current user's `user_ai_settings` row; the personal action must
always take the user ID from the authenticated session. Since custom endpoints
are called server-side, require public HTTPS destinations, reject internal or
reserved DNS addresses, and pin the validated address for the request to
prevent SSRF and DNS-rebinding access to private services. Global Gemini
configuration remains Programmer Roxwood-only. For text features that call
`ems_ai_provider_call_json()`, try the user's Gemini first, then the configured
custom OpenAI-compatible provider if Gemini throws or returns empty/invalid
JSON. If Gemini is not configured, use the custom provider directly. Both
providers use the same prompt and JSON contract; never save an invalid
fallback response as a completed report.
