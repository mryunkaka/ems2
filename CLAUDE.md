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

**Penanggung Jawab (PIC) + Roster + Generate Dispatcher (added 2026-08-05)**:
a major extension on top of the base module above, migration
`docs/sql/56_2026-08-05_dispatcher_supervisor_roster.sql` (2 new tables).
- **Penanggung Jawab (PIC)**: `dispatcher_supervisors` (user_id+unit_code
  unique) — any `manager-plus` role can toggle themself on/off via a button
  at the top of `dispatcher.php` (`toggle_supervisor` action, simple
  insert/delete). **Multiple PICs can be active simultaneously** — no
  single-owner constraint. `ems_dispatcher_is_supervisor()` /
  `ems_dispatcher_active_supervisors()` in `config/dispatcher.php`.
  Becoming PIC is the gate for everything else below — manager-plus alone
  is no longer sufficient to control the board (see next point).
- **Roster replaces "all Medis medics" as the board's source**:
  `dispatcher_roster` (medic_user_id+unit_code unique, `added_at` drives
  ordering — first-added shows first, matching "urutan pertama adalah
  orang pertama yang online lebih duluan"). Papan Status Medis now queries
  `dispatcher_roster JOIN user_rh` instead of "every active Medis-division
  medic in the unit" — a medic must be explicitly added by a PIC
  (`add_roster_member` action, multi-select search picker like the
  existing status-assign modal) before they appear on the board at all.
  `remove_roster_member` also auto-clears that medic's active assignment
  (if any) so they don't leave a dangling status after leaving the roster.
- **Manual assign/clear is now PIC-only, not just manager-plus**: the
  original `create_assignment`/`clear_assignment` actions were regated
  from `ems_is_manager_plus_role()` to `ems_dispatcher_is_supervisor()` —
  a manager who hasn't toggled themself on as PIC can still view the
  board/history but can't mutate it; they can become PIC anytime via the
  toggle. `create_assignment` additionally now rejects any selected medic
  who isn't currently on the roster (defensive server-side check mirroring
  what the UI's medic-picker already restricts to).
- **Farmasi duty is authoritative and untouchable from Dispatcher**: a
  roster medic currently `online` in `user_farmasi_status` (the same
  "Medis Online" concept `rekap_farmasi.php` already tracks —
  `ems_dispatcher_farmasi_online_medic_ids()`) renders as a locked
  read-only "Jaga Farmasi" badge on the board — no edit/clear button, and
  both `create_assignment` and `generate_assignments` hard-reject/exclude
  them server-side. They only become eligible again once they go offline
  from farmasi duty via `rekap_farmasi.php` itself; nothing in
  `dispatcher.php`/`dispatcher_action.php` can touch their farmasi status
  or force a status change while they're on duty.
- **"Generate Dispatcher"** (`generate_assignments` action, one-click, no
  input modal — confirm() only): auto-assigns the *eligible* roster subset
  (excludes farmasi-online, and excludes anyone currently on a manual
  `off_duty`/`rapat`/`kunjungan`/`lainnya` status so PIC overrides aren't
  clobbered) into `standby_resepsionis` / `bantu_igd` / `respon_lapangan`
  groups. Composition rules implemented in
  `ems_dispatcher_build_generate_plan()` (`config/dispatcher.php`):
  IGD/Resepsionis slot counts are proportional to roster size (~20%/~15%,
  min 1 each once total > 2 — no manual slot-count input, per explicit
  user choice), filled trainee-first (preserves seniors —
  paramedic/co_asst/general_practitioner/specialist — for field
  leadership); remaining seniors each lead a solo Respon Lapangan team,
  with leftover trainees distributed round-robin onto those teams (max 3
  per team) and any trainee surplus paired into trainee-only 2-person
  teams. **Fairness/anti-clique rotation**: eligible medics are sorted by
  `ems_dispatcher_last_duty_at()` (MAX started_at across their
  `respon_lapangan`/`bantu_igd`/`standby_resepsionis` history, this unit
  only) ascending, never-assigned-yet medics first — so Generate
  deliberately avoids repeatedly picking the same people, per the explicit
  "rolling terus, dengan orang berbeda-beda...tidak circel2an" request.
  Coordinate is left blank on generated Respon Lapangan groups by design
  (user's explicit choice over a "generate also picks a location" option)
  — PIC fills it in afterward via the existing pencil "Ubah Status" button,
  which was updated to pre-select **all** members of a group (not just the
  clicked medic) so editing a generated group's coordinate updates the
  whole team's assignment row, not just one person's.
- Before generating, any of the eligible medics' existing *auto-category*
  active assignment (respon_lapangan/bantu_igd/standby_resepsionis) is
  cleared first so each Generate run starts clean; anyone who doesn't land
  in a slot this round simply reverts to "Tersedia" (no replacement row
  created for them).
- Verified against the real local dev DB on 2026-08-05 with real `user_rh`
  rows (not synthetic fixtures): full cycle of PIC toggle-on (confirmed
  multiple simultaneous PICs coexist), roster add (7 medics), a planted
  farmasi-online row + a planted manual `off_duty` assignment (to prove
  both exclusions), Generate (correctly excluded both, correctly rotated
  around one medic's pre-existing duty history from the base module's
  2026-07-31 test data by deprioritizing him in the sort), and full
  cleanup — restoring the DB to its pre-test state including leaving a
  real user's live PIC toggle (made through the actual browser UI while
  this was being tested) untouched.
- **Rotation-freeze bug fixed same day (2026-08-05)**: user reported that
  clearing everyone and clicking Generate again produced the *exact same*
  people in the *exact same* roles, no rotation at all. Root cause: a
  single Generate run assigns most/all of the roster in one pass, so every
  medic touched in that run ends up with an (almost) identical
  `last_duty_at` afterward (MySQL `NOW()` called once per INSERT inside a
  fast loop — same second in practice). `ems_dispatcher_sort_by_fairness()`
  used a plain `usort()`, which is stable in PHP 8+, so exactly-tied medics
  always fell back to their original roster order — which never changes
  between runs. Net effect: the "fair" sort silently degenerated into a
  constant, deterministic order, and Generate reproduced the same
  composition forever. Fixed two ways together: (1)
  `dispatcher_action.php`'s `generate_assignments` now computes one
  `$generatedAt` timestamp in PHP before the insert loop and reuses it for
  every row in that run (instead of relying on per-row `NOW()`), so every
  medic touched in one Generate genuinely ties exactly; (2)
  `ems_dispatcher_sort_by_fairness()` now `shuffle()`s the input before the
  stable `usort()`, so tied medics (the common case right after a Generate)
  get randomized relative order each call instead of freezing on roster
  order — never-assigned/longer-idle medics still sort first, only the
  within-tie order is randomized. Verified with a real 17-medic live
  roster (discovered mid-test to already be in active use — the actual
  user had been adding real medics to the roster while this was being
  built): 3 consecutive clear→generate rounds now produce visibly
  different IGD/Resepsionis/Respon Lapangan compositions each time,
  confirmed via `php -r` script sharing the exact SQL used by
  `generate_assignments`. All 51 test assignment rows created during this
  verification (attributed to a test PIC account) were hard-deleted
  afterward; the real roster and the real live PIC toggle were left
  untouched.

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

### "Roxwood Hospital AI" suite — personal-key clinical tools (sidebar group)
A second, separate AI system from the recruitment-scoring one above: **every
user supplies their own personal Gemini API key** (not the shared
`system_ai_settings` key) via `dashboard/ai_settings_personal.php` +
`_action.php`, stored per-user in `user_ai_settings` (migration
`docs/sql/58_2026-08-11_ai_diagnosis_surgery_personal_settings.sql`). These
pages predate this file's tracking (found already built/undocumented on
2026-08-11) — noting them here now so a future session doesn't have to
rediscover them from scratch:
- `dashboard/ai_diagnosis_assistant.php`+`_action.php`+`ai_diagnosis_report.php`
  — free-text anamnesis in, full structured JSON ER report out (diagnosis,
  GCS, TTV, lab/radiology recs, step-by-step `/me`/`/do`/`/e` emergency
  actions with DPJP/Asisten role dialog). Persisted to `ai_diagnosis_reports`.
- `dashboard/ai_surgery_planner.php`+`_action.php`+`ai_surgery_report.php` —
  jenis operasi/anestesi/kompleksitas in, full operative note out
  (pharmacology per phase, step-by-step procedure, risks, pasca-op report).
  Persisted to `ai_surgery_plans`.
- Both share `config/ai_diagnosis_surgery.php`: domain reference data (SOP
  guardrails, `/e` animation-mantra dictionary, DPJP/Asisten authority
  rules, Minor/Mayor operation classification) get appended as a system-
  prompt suffix (`ems_ai_ds_reference_suffix()`) on every call, and
  `ems_ai_ds_call_gemini()` is the shared per-user-key Gemini caller (uses
  `responseMimeType: application/json`, text-only — see Radiology Center
  below for why image generation needed its own separate call path).
  `ai_settings_personal.php`'s **Base URL & Model fields are Programmer-
  Roxwood-only** (added 2026-08-11, `ems_current_user_is_programmer_roxwood()`
  gate both in the UI and — the part that actually matters — server-side in
  `ai_settings_personal_action.php`, which silently ignores any posted
  `gemini_base_url`/`default_model` from non-programmer users regardless of
  what's submitted, always falling back to their existing/default value).
  Everyone else only ever enters their API key.
- All these pages (plus Radiology Center below) are listed by filename in
  `ems_enforce_dashboard_page_access()`'s `$roxwoodHospitalAiPages` exception
  array in `config/helpers.php` — accessible to **every logged-in user
  regardless of division**, unlike most of the app's division-gated pages.
  If you add a new page to this suite, add its filename there too (and to
  the `'Roxwood Hospital AI'` sidebar group in `partials/sidebar.php`).

**Radiology Center (added 2026-08-11)** — `dashboard/radiology_center.php`
+`_action.php`+`radiology_report.php`: generates a synthetic diagnostic
scan **image** (X-Ray/CT Scan/MRI/USG) for roleplay, instead of a text
report. This needed its own code path because Gemini's image-generation
contract is fundamentally different from the JSON-text one every other AI
feature in this codebase uses:
- **New Gemini client functions** in `actions/ai_gemini_client.php`:
  `ems_gemini_generate_image()` (sets `generationConfig.responseModalities:
  ["IMAGE"]`, no `responseMimeType`) and `ems_gemini_extract_inline_image()`
  (reads `candidates[0].content.parts[].inlineData` — base64 + mimeType —
  instead of `.text`). Response logging to `system_ai_request_logs`
  deliberately **omits the base64 image body** (replaced with a
  `[image binary omitted, mime=...]` placeholder) so a single image
  generation call doesn't bloat that table by hundreds of KB–MB the way
  storing the raw response would.
- **Model is hardcoded, not user-selectable**: `ems_ai_radiology_model()`
  in `config/ai_radiology.php` returns `gemini-2.5-flash-image` — this is
  intentionally *not* read from `user_ai_settings.default_model` (that
  field is for the text/JSON models Diagnosis/Surgery use; mixing an
  image-generation model into that same dropdown would break those two
  features). Radiology Center reuses the same per-user API key from
  `user_ai_settings` (via `ems_ai_ds_get_user_settings()`) but always calls
  the image model regardless of what `default_model` is set to.
- **Cascading form, 4 levels deep** (reworked 2026-08-12 after user feedback
  that the first pass was "not as complete as the original" reference tool
  and didn't disable child selects until their parent was chosen): Modality
  → Category → Body Region → Projection/Options, each select populated
  client-side from `ems_ai_radiology_catalog()` (one JSON blob embedded in
  the page) and left `disabled` with a "-- Pilih X dulu --" placeholder
  until its parent has a value — exactly matching a real PACS/RIS order-
  entry workstation, not a flat generic dropdown. `ems_ai_radiology_catalog()`
  in `config/ai_radiology.php` is a hand-built nested array covering **9
  modalities** (X-Ray, CT Scan, MRI, Ultrasound, Angiography, PET Scan,
  Mammography, Fluoroscopy, DEXA Scan), ~203 total category→region→
  projection leaf combinations (e.g. X-Ray → Head & Neck → Cervical Spine →
  AP/Lateral/Open Mouth (Odontoid); CT Scan → Abdomen & Pelvis → CT Abdomen
  Kontras (Triple Phase) → Arterial/Portal Venous/Delayed Phase). Server-side
  validated top-to-bottom via `ems_ai_radiology_categories()` /
  `_body_regions_for()` / `_projections_for()` / `_is_valid_selection()` in
  `radiology_center_action.php` — a request can't skip a level or submit a
  combination that doesn't exist in the catalog. On top of the 4 cascading
  fields sits one more, non-cascading **Clinical Finding** dropdown (Normal/
  Fraktur/Perdarahan/Massa/Inflamasi/Benda Asing/Pasca Operasi) — this is an
  addition beyond what the reference tool had, and is what actually drives
  what abnormality the model renders into the image. Plus patient info
  (name/DOB/citizen ID), free-text anamnesis (enriches the prompt context),
  and examining doctor name — all folded into a single prompt by
  `ems_ai_radiology_build_prompt()`, which also carries fixed style
  instructions (authentic scan aesthetic tuned per modality — radiograph/
  tomographic/soft-tissue/sonogram/subtraction-angiogram/fusion-colormap/
  compressed-tissue/real-time-fluoro/bone-density look as appropriate — no
  patient face/real identifying info, no on-image text/watermark, black
  PACS-viewer-style background) since this is fictional roleplay imagery,
  not a real diagnostic tool. `category` is a required column on
  `ai_radiology_images` (added same day, before this migration had shipped
  anywhere — edited migration 59 in place rather than adding a new one,
  since no real data existed yet).
- **Storage**: generated PNG/JPEG bytes are base64-decoded and written to
  `storage/radiology/` (`ems_ai_radiology_save_image_file()`), never
  exposed directly — served exclusively through `ajax/secure_file.php`
  (new `storage/radiology/` prefix rule added there, authorization = the
  path must exist in `ai_radiology_images.image_path`; no per-owner
  restriction, matching how `ai_diagnosis_reports`/`ai_surgery_plans` are
  already viewable by any logged-in user in the unit, not just their
  creator). History table on `radiology_center.php` shows a thumbnail per
  row; `radiology_report.php` shows the full image with a click-to-zoom
  modal (plain CSS/JS overlay, no external lightbox library) and manager-
  plus-gated permanent delete (also unlinks the file from `storage/`).
- Persisted to `ai_radiology_images` (migration
  `docs/sql/59_2026-08-11_ai_radiology_center.sql`) — one row per
  generation attempt (`status: done`/`error`), storing the full prompt
  used (`prompt_used`) for audit/regeneration reference even on failure.
- Verified locally: table creation, catalog/prompt-builder logic, full
  file-save → DB-insert → `secure_file.php` authorization-query round trip
  (using a synthetic 1x1 PNG, not a real Gemini call — this environment has
  no live Gemini API key to test the actual network call/image response
  parsing against; that part needs verification against a real key, e.g.
  via the actual browser UI).

**Diagnosis → Surgery/Radiology case-chaining via reference code (added
2026-08-12)**: closes the gap where a medic would have to manually retype
the same case context three times across AI Diagnosis, AI Surgery Planner,
and Radiology Center — explicitly framed by the user as the first step
toward eventually auto-populating a real `medical_records` entry from this
chain (not built yet, out of scope for this pass).
- **`report_code`**: every `ai_diagnosis_reports` row (migration
  `docs/sql/60_2026-08-12_ai_diagnosis_report_code.sql`, e.g.
  `DGN-20260812-143012-A1B2` via `ems_ai_ds_generate_report_code()`,
  uniqueness checked with a retry loop at insert time) is displayed on
  `ai_diagnosis_report.php` with a one-click copy button. Existing rows
  were backfilled locally via `php -r` (not a migration step — codes are
  random per-row, can't be expressed as SQL).
- **`radiologi_terstruktur`**: the diagnosis JSON schema
  (`ems_ai_ds_default_diagnosis_system_prompt()`) gained a new required
  field alongside the existing free-text `radiologi` array — one object
  `{modality, category, body_region, projection, clinical_finding}` that
  the model must pick **verbatim** from a reference block appended to the
  system prompt in `ai_diagnosis_assistant_action.php`:
  `ems_ai_radiology_catalog_reference_text()` (renders the entire
  Radiology Center catalog as `Modality > Category > Body Region >
  [projection options]` lines) + `ems_ai_radiology_clinical_findings_reference_text()`.
  This is what lets the diagnosis's radiology recommendation map onto a
  real, valid Radiology Center selection instead of free text a human
  would have to reinterpret. **Server-side sanitization is mandatory, not
  optional** — `ems_ai_ds_sanitize_structured_radiology()` in the action
  script re-validates every field against the real catalog
  (`ems_ai_radiology_is_valid_selection()` etc.) before it's ever stored;
  an invalid/hallucinated combination is stored as `null`, never as
  unvalidated data, so downstream auto-fill can trust "not null" to mean
  "definitely a real catalog path." Also defensively normalizes a field
  the model occasionally returns as an array instead of the requested
  single string (observed live: `projection` came back as all 3 options
  for a body region instead of picking one, despite the prompt saying
  "SATU string tunggal") via `ems_ai_ds_scalar_or_first()` — takes the
  first element rather than rejecting the whole object, then the prompt
  wording was independently strengthened too (explicit "JANGAN menyalin
  seluruh isi kurung siku sebagai list" + a concrete single-value example
  in the JSON schema block) and confirmed fixed on a second live test
  case. If the patient needs no imaging at all, all four catalog fields
  are empty strings but `clinical_finding` still gets filled — handled as
  a distinct valid state (imaging genuinely not needed) from "invalid,
  discard."
- **Copy-paste made structural, not just textual**: `kasus_tindakan` in
  the diagnosis JSON was already Surgery Planner's exact input field name;
  rule 12 of the diagnosis prompt now explicitly requires it to "stand
  alone" as complete context (not assume the reader already has the
  original anamnesis) since it's meant to be copied verbatim — and
  `ai_diagnosis_report.php`'s Kasus Medis card got the same one-click-copy
  button treatment as the existing emergency-mantra copy buttons
  (`.mantra-copy-btn` / `data-copy` pattern, reused as-is).
- **`dashboard/ai_diagnosis_report_lookup.php`** (new, added to the
  `$roxwoodHospitalAiPages` ACL exception list in `config/helpers.php`):
  GET-only JSON endpoint, `?code=...` →
  `ems_ai_ds_find_diagnosis_report_by_code()` (unit-scoped, `status='done'`
  only) → returns `kasus_tindakan`, `jenis_operasi`, `jenis_anestesi`,
  `anamnesis`, `diagnosis_utama`, and the validated `radiologi_terstruktur`
  (or `null`). No CSRF token required since it's read-only and the codes
  are high-entropy/unguessable, not sequential.
- **Auto-fill UI**, added identically-styled "Ambil dari Laporan Diagnosis
  (opsional)" cards (code input + "Ambil Data" button) to the top of both
  input forms:
  - `ai_surgery_planner.php`: fills `kasus_tindakan` directly; fuzzy-matches
    `jenis_operasi` text for "minor"/else-Mayor and `jenis_anestesi` text
    against the 4 known option strings (umum/lokal/spinal/sedasi keyword
    matching) to pre-select those two dropdowns too — the diagnosis JSON
    already independently generates both fields, this just wires them
    through.
  - `radiology_center.php`: fills `anamnesis`, then drives the same 4-level
    cascade a manual user would trigger — `applyCascadeSelection()` calls
    the existing `fillSelect()` cascade logic programmatically (set
    modality → repopulate+set category → repopulate+set body_region →
    repopulate+set projection) so the dependent `<select>` option lists end
    up correctly populated, not just the values silently set on disabled/
    empty selects. Gracefully degrades per-field: a report with no
    structured radiology (predates this feature, or the model's pick
    failed sanitization) still fills anamnesis and tells the user to pick
    Modality/Category/Body Region/Projection manually, rather than failing
    the whole auto-fill.
- Verified end-to-end against the real Gemini API (correct PHP 8.4.22
  binary, see gotcha above) with two different real cases: a wrist fracture
  (X-Ray → Upper Extremity → Wrist → PA, correctly single-valued after the
  prompt fix) and suspected appendicitis (Ultrasound → Abdomen → USG
  Abdomen Bawah → B-Mode (Grayscale)) — both produced valid catalog paths
  that passed server-side sanitization untouched. Also verified the full
  insert → `ems_ai_ds_find_diagnosis_report_by_code()` lookup → JSON
  payload round trip with synthetic data matching exactly what the
  lookup endpoint would return to the browser; test rows deleted after.

**Patient identity fields added to the chain (2026-08-12, same day)**: the
Diagnosis→Surgery/Radiology chaining above only carried clinical case data;
user asked for `ai_diagnosis_assistant.php` to also capture Nama, Jenis
Kelamin, Tanggal Lahir, Citizen ID, and have that identity flow through to
`radiology_center.php`'s auto-fill too (Nama Pasien, Tanggal Lahir, Citizen
ID), plus have Radiology Center's "Dokter Pemeriksa" field default to
whoever is currently logged in (still editable).
- `ai_diagnosis_reports` gained 4 nullable columns (migration
  `docs/sql/62_2026-08-12_ai_diagnosis_patient_identity.sql`, same
  defensive `ems_column_exists()` guard pattern in `ems_ai_ds_ensure_tables()`
  as `report_code` before it): `patient_name`, `patient_gender` (only
  `Laki-laki`/`Perempuan` accepted, anything else stored as `NULL`),
  `patient_dob`, `patient_citizen_id`. All optional — the form works
  identically to before if left blank.
- **The identity data doesn't just get stored, it feeds the AI call
  itself**: `ai_diagnosis_assistant_action.php` prepends an `IDENTITAS
  PASIEN:` block (Nama/Jenis Kelamin/Usia — age computed from DOB via the
  same `ems_ai_radiology_age_label()` used for the Radiology Center image
  overlay) to the user prompt *before* the `ANAMNESIS:` block, whenever at
  least one identity field was filled in. This grounds the model in the
  real supplied identity instead of it inventing its own age/gender
  assumptions — confirmed with a real Gemini call: supplying "Usia: 28 th"
  produced a `roleplay_note` explicitly citing "usia (28 tahun)" instead of
  a fabricated value, which is what happens when no identity block is
  present (the base prompt's rule 1 already tells it to invent plausible
  values for anything unspecified).
- `ai_diagnosis_report.php` displays a new "Identitas Pasien" card
  (conditionally, only if at least one field is non-empty) above the
  Anamnesis card. `ai_diagnosis_report_lookup.php` now also returns
  `patient_name`/`patient_gender`/`patient_dob`/`patient_citizen_id` in its
  JSON payload — `patient_dob` comes back as a plain `Y-m-d` string, which
  is already exactly the value format an HTML `<input type="date">`
  expects, so the Radiology Center JS just assigns it directly with no
  reformatting.
- `radiology_center.php`'s "Ambil dari Laporan Diagnosis" fetch handler
  (added `id`s to the previously-anonymous `patient_name`/`patient_dob`/
  `patient_citizen_id` inputs to make them targetable) now also fills those
  three from the lookup response, alongside the existing anamnesis +
  radiologi_terstruktur cascade auto-fill. `doctor_name` is deliberately
  **not** part of this fetch-by-code auto-fill — instead it's pre-filled
  server-side from `$_SESSION['user_rh']` on every page load (whoever is
  currently logged in), independent of whether a diagnosis code was ever
  fetched, matching the user's framing that the examining doctor is "who's
  logged in right now, but can be changed" rather than something inherited
  from a diagnosis record.
- Verified end-to-end: prompt-block formatting, a real DB insert → lookup
  round trip confirming `patient_dob` survives as `Y-m-d`, and a real
  Gemini call proving the model actually uses the supplied age instead of
  fabricating one. Test rows deleted after.

**Model JSON-key typos + sparse-anamnesis completion (fixed 2026-08-12,
same day, user-reported)**: a real report (`ai_diagnosis_report.php?id=14`)
had an empty "Catatan Medis & Roleplay" card. Root cause: Gemini returned
the JSON key as `"rolepy_note"` (missing "la") instead of the exact
`"roleplay_note"` the prompt schema and the PHP display code both expect —
a plain `$result['roleplay_note'] ?? '-'` lookup can never find a
misspelled key, so it silently rendered as empty. This is a general LLM-
reliability risk (same category as the earlier `projection`-returned-as-
array bug), not specific to this one field, so the fix is a **reusable
recovery mechanism**, not a one-off patch:
- `ems_ai_ds_recover_field(array &$data, string $exactKey, string $containsNeedle)`
  in `config/ai_diagnosis_surgery.php` — if `$data[$exactKey]` is empty,
  scans every other top-level key for one containing `$containsNeedle`
  (case-insensitive) and adopts its value. Safe for this schema because
  `"note"` and `"anamnesis"` are each distinctive enough substrings to
  only ever match their intended field, not collide with anything else in
  the JSON structure.
- `ems_ai_ds_normalize_diagnosis_result(array $data): array` wraps the
  recovery calls for both `roleplay_note` (needle `note`) and the new
  `anamnesis_lengkap` (needle `anamnesis`, see below). **Called from two
  places, not just one**: `ai_diagnosis_assistant_action.php` right after
  a fresh AI response (so new reports self-correct immediately), AND
  `ai_diagnosis_report.php` / `ai_diagnosis_report_lookup.php` right after
  decoding `result_json` from the database (so **already-broken existing
  reports — like #14 — self-heal on every view**, with no need to
  regenerate or run a backfill migration). Verified directly against
  report #14's real stored `result_json`: normalization correctly
  recovered the roleplay note text from the misspelled key.
- Prompt hardening alongside the code fix (belt-and-suspenders, since a
  prompt instruction alone was already once insufficient for the
  `projection`-as-array bug): rule 15 in
  `ems_ai_ds_default_diagnosis_system_prompt()` now explicitly calls out
  "JANGAN salah ketik ... (contoh kesalahan yang PERNAH terjadi ...
  \"rolepy_note\")" — naming the actual historical mistake, not just a
  generic "spell correctly" instruction.
- **Second, independent feature bundled into the same fix** (explicitly
  requested alongside the bug report): rule 16 + new required JSON field
  `anamnesis_lengkap` — the model rewrites the user's raw anamnesis
  (however terse) into one full clinical-narrative paragraph, preserving
  every fact the user stated and filling in what's missing, kept
  consistent with the other generated fields (status/gcs/ttv/diagnosis).
  `ai_diagnosis_report.php`'s Anamnesis card now shows this completed
  version as the primary content (card header changes to "... (Lengkap &
  Direvisi AI)" when present), with the original raw input tucked into a
  collapsed `<details>` for audit reference — only shown at all if it
  actually differs from the completed version. `ai_diagnosis_report_lookup.php`
  now returns `anamnesis_lengkap` (falling back to the raw stored
  anamnesis for old reports that don't have one) as the `anamnesis` field
  in its JSON payload, so Radiology Center's auto-fill picks up the
  richer version automatically with no JS changes needed on that end —
  it was already just reading `data.anamnesis` from the lookup response.
  Verified live: a deliberately terse "orang ditembak di kaki" (4 words)
  produced a complete, coherent paragraph-length `anamnesis_lengkap`, and
  in that same call `roleplay_note` came back correctly spelled (prompt
  hardening working as intended, with the recovery function remaining as
  the safety net for whenever it doesn't).

**Laboratory AI (added 2026-08-12)** — `dashboard/laboratory_ai.php`
+`_action.php`+`laboratory_ai_report.php`: fourth module of the "Roxwood
Hospital AI" suite, built from a user-supplied reference HTML
("Roxwood Hospital Integrated Medical Systems" portal — Surgery/Diagnosis
and Radiology already existed here in equivalent form, Psychiatry in the
reference is an unbuilt placeholder, out of scope). Generates a full
laboratory result set (parameter/value/unit/reference range/flag) +
interpretation, exactly the text/JSON pattern of AI Diagnosis/Surgery —
**not** the image-generation pattern of Radiology Center, so it does
**not** touch Cloudflare/`ems_ai_radiology_generate_image()` at all; it
calls `ems_ai_ds_call_gemini()` directly (same per-user `user_ai_settings`
key as Diagnosis/Surgery/Radiology text calls).
- **`config/ai_laboratory.php`**: `ems_ai_laboratory_ensure_tables()`
  (guards `ai_laboratory_results`, migration
  `docs/sql/63_2026-08-12_ai_laboratory_center.sql`) +
  `ems_ai_laboratory_catalog()` — a hand-transcribed PHP array of the
  reference's `dbHierarchy`, **13 departments** (Hematologi, Kimia Klinik,
  Urinalisis, Imunologi & Serologi, Mikrobiologi, Patologi Anatomi,
  Patologi Klinik, Toksikologi, Bank Darah, Koagulasi, Molekuler (PCR),
  Parasitologi, Analisis Feses), each with a department-level default
  specimen list plus per-category overrides (e.g. Toksikologi has no
  department-level specimens at all — every category defines its own).
  Categories are either `type: 'none'` (no further choice) or
  `type: 'select'` (a Level-3 dropdown of specific options). A **separate**
  `ems_ai_laboratory_custom_trigger_options()` map (not just "category has
  a `custom` list") gates when a custom-parameter checkbox grid actually
  appears — only 4 exact category+Level-3-option combinations trigger it
  (CBC+"Custom Parameter", Urinalisis Lengkap+"Kimia", Drug Screening
  Urine+"Custom", Drug Screening (Toksikologi)+"Custom") — matching the
  reference's conditional-visibility logic exactly rather than showing the
  checklist any time a `custom` array happens to exist on a category.
- **Deliberately does NOT use `ems_ai_ds_build_system_prompt()`** (the
  Diagnosis/Surgery shared wrapper) — that function unconditionally
  appends animation-mantra/role-authority/operation-classification
  reference text via `ems_ai_ds_reference_suffix()`, all of which is
  emergency-roleplay-specific and irrelevant to a lab report. Laboratory
  AI has its own standalone `ems_ai_laboratory_default_system_prompt()`
  (Kepala Laboratorium Sp.PK persona) instead, but still reuses
  `featureKey: 'ai_laboratory'` when calling `ems_ai_ds_call_gemini()` so
  requests land in the same `system_ai_request_logs` table as every other
  personal-key feature.
- **Flag sanitization**: `ems_ai_laboratory_sanitize_result()` normalizes
  every result row's `flag` to exactly `Normal`/`High`/`Low` (case/wording
  variations like "Tinggi"/"H" are coerced) before storage — the UI's
  color-coded badges (`laboratory_ai_report.php`) trust this invariant
  rather than re-parsing free text.
- **Report-code chaining reuses the Diagnosis endpoint unchanged**: the
  "Ambil dari Laporan Diagnosis" card on `laboratory_ai.php` calls the
  existing `ai_diagnosis_report_lookup.php` (no new endpoint needed) and
  fills Patient Name/DOB/Citizen ID + a combined Diagnosis+Anamnesis block
  into Clinical Info — same pattern as Radiology Center's auto-fill, minus
  the 4-level cascade (Laboratory's own department→category→Level3→
  specimen cascade is separate client-side JS, driven by the catalog JSON
  embedded in the page, structurally mirroring but not sharing code with
  Radiology Center's `applyCascadeSelection()`).
- **Report code prefix `LAB-`** (`ems_ai_laboratory_generate_report_code()`,
  format `LAB-YYYYMMDD-HHMMSS-XXXX`), separate column/table from
  `ai_diagnosis_reports.report_code` (`DGN-` prefix) — no cross-feature
  uniqueness constraint needed since they're different tables.
- **Print/PDF**: same client-side-only pattern as the reference — a hidden
  `#aiLabPrintTemplate` block is cloned into a new `window.open()` document
  and `window.print()` is called; no server-side PDF generation, no
  `spipu/html2pdf` involvement (that library is used elsewhere in the app
  for different documents, not here).
- Registered in `config/helpers.php`'s `$roxwoodHospitalAiPages` exception
  array (all 3 filenames — accessible to every logged-in user regardless
  of division, same as the rest of the suite) and in `partials/sidebar.php`'s
  "Roxwood Hospital AI" group (`beaker` icon).
- Verified against the real local dev DB and a real Gemini call (per-user
  key, `gemini-3.5-flash-lite` via `ems_ai_ds_call_gemini()`'s default):
  table creation, a full CBC/Custom-Parameter request with a real anemia
  clinical scenario (all 9 requested parameters present, clinically
  coherent Low/Normal/High flags, correct "Anemia mikrositik hipokromik
  suspek Anemia Defisiensi Besi" impression), report-code round trip, and
  cleanup — plus static validation of all 13 departments'
  category/specimen/Level-3-option resolution and the 4 custom-parameter
  trigger combinations. HTTP-level (logged-in browser) verification was
  **not** performed this session — the only account with a working
  personal Gemini key on this local DB ("Programmer Roxwood") had an
  active real session at the time, and this app's single-device-login
  behavior would have force-logged the real user out to test via curl, so
  that was deliberately skipped in favor of the CLI-level DB+Gemini
  verification above. A future session should still click through the
  actual page once a safe opportunity exists.

**Diagnosis → Laboratory case-chaining, mirroring the existing Radiology
chaining (added 2026-08-12, same day, user-reported gap)**: user pointed out
that "Rekomendasi Laboratorium" on `ai_diagnosis_report.php` was free text
only — no guidance on which Department/Category/Level3/Specimen to actually
pick on `laboratory_ai.php` when filling it in manually. Fixed by giving
Laboratory AI the exact same structured-recommendation treatment Radiology
Center already had:
- New required JSON field `laboratorium_terstruktur` (rule 13a in
  `ems_ai_ds_default_diagnosis_system_prompt()`) — one object
  `{department, category, level3_option, specimen_type}` the model must pick
  **verbatim** from a reference block appended to the system prompt in
  `ai_diagnosis_assistant_action.php`: `ems_ai_laboratory_catalog_reference_text()`
  (new function in `config/ai_laboratory.php`, renders the entire Laboratory
  AI catalog as `Department > Category > [level3 options] > Spesimen: [...]`
  lines, same rendering pattern as `ems_ai_radiology_catalog_reference_text()`).
  `level3_option` is an empty string when the category has no Level-3 choice
  (`type: 'none'`) — mirrors how radiology's `body_region`/`projection` are
  always required but laboratory's schema is one level shallower for `type:
  'none'` categories.
- **Server-side sanitization is mandatory**, same invariant as radiology:
  `ems_ai_ds_sanitize_structured_laboratory()` (new function alongside the
  existing `ems_ai_ds_sanitize_structured_radiology()` in
  `ai_diagnosis_assistant_action.php`) re-validates against the real catalog
  (`ems_ai_laboratory_is_valid_selection()` etc.) before storage — an
  invalid/hallucinated combination is stored as `null`, never as unvalidated
  data. "Patient needs no lab work" is a distinct valid state (all 3 fields
  empty string → stored as `null`), same as radiology's "no imaging needed."
- `ai_diagnosis_report.php`'s "Rekomendasi Laboratorium" card gained the same
  cyan "Pilihan Laboratory AI" info box radiology already had under
  "Rekomendasi Radiologi" — shows `Department › Category › [Level3]` +
  Spesimen, with a note that filling the report's code into Laboratory AI
  auto-fills this, or that it's what to pick manually otherwise.
- `ai_diagnosis_report_lookup.php` now also returns `laboratorium_terstruktur`
  (null-safe, same empty-department-means-null check as the radiology field).
- `laboratory_ai.php`'s "Ambil dari Laporan Diagnosis" auto-fill JS gained
  `applyLabCascadeSelection(department, category, level3Option, specimen)`,
  structurally mirroring Radiology Center's `applyCascadeSelection()` — walks
  the same client-side cascade a manual user would trigger (populate
  Category options from Department, populate/show Level3 from Category,
  trigger the custom-parameter-checkbox visibility check, then populate
  Specimen), so a report with a valid structured recommendation lands with
  all fields correctly pre-selected (not just values silently set on
  disabled/empty selects) and the custom-parameter checklist correctly
  shown/hidden if the auto-filled category+level3 combo happens to be one of
  the 4 trigger combinations. Degrades gracefully per-field like radiology's
  equivalent: a report with no structured lab data (predates this feature,
  or the model's pick failed sanitization) still fills patient identity +
  clinical info and tells the user to pick manually.
- Verified against the real Gemini API (correct PHP 8.4.22 binary) with a
  real dengue-fever case (demam tinggi, nyeri sendi, bintik merah kulit):
  model correctly picked `Imunologi & Serologi > Demam > Dengue NS1 > Serum`
  — a real, valid path in the Laboratory AI catalog — which passed
  server-side sanitization untouched and matches the free-text `lab`
  recommendations ("NS1 Antigen Dengue") it generated in the same response.

**Real bug found and fixed the same day it shipped (2026-08-12,
user-reported)**: first real click-through of `laboratory_ai.php` failed
with a generic "Gagal" after the AI call had actually already succeeded
(confirmed via `system_ai_request_logs`: `http_status=200`,
`success_flag=1`, a fully valid JSON result) — the failure was downstream,
in `laboratory_ai_action.php`'s own success-path `INSERT`. Root cause: the
`INSERT INTO ai_laboratory_results (... 17 columns ...)` had a `VALUES (...)`
clause with only 14 `?` placeholders (plus the two literals `'done'`/`NULL`
for `status`/`error_message`) — one short of the 15 placeholders actually
needed for the other 15 non-literal columns — while the PHP `execute([...])`
array correctly passed all 15 values. PDO threw `PDOException: SQLSTATE
[HY093]: Invalid parameter number: number of bound variables does not match
number of tokens`, visible in nginx's error log
(`nginx-1.28.3/logs/error.log`) but invisible to the user beyond the generic
overlay error, since the fatal error broke the JSON response entirely (JS
`fetch().then(res => res.json())` rejected, falling into the generic
"Tidak dapat menghubungi server" catch-all message rather than showing the
real cause). Fixed by adding the missing `?` for `result_json`. **Debugging
pattern worth remembering**: when a Laboratory/Diagnosis/Surgery/Radiology
AI page shows a generic "Gagal" with no specific message, check
`system_ai_request_logs` first to see whether the Gemini call itself
actually succeeded — if it did, the bug is downstream in this codebase's own
post-response handling (JSON parsing, sanitization, or the `INSERT`), not in
prompt/model/API-key territory, and nginx's `error.log` will usually have
the real PHP fatal-error stack trace even though the browser only shows a
generic message. Verified fixed by replicating the exact same `INSERT` with
the corrected placeholder count against the real local dev DB (Kimia Klinik
> Fungsi Ginjal > Kreatinin > Urine 24 Jam, matching the real case that
originally failed) — insert succeeded, `result_json` persisted correctly,
row cleaned up after.

**Radiology formal text report added (2026-08-12/13, user-reported gap:
"buat dokumen Radiology seperti yang saya lampirkan")**: Radiology Center
previously only produced the generated scan image (with a 4-corner text
overlay) — no accompanying formal radiologist reading, unlike a real
hospital workflow (or the user's reference tool, which generates a
`TECHNIQUE`/`FINDINGS`/`IMPRESSION`/`RECOMMENDATION` text report alongside
the image via a separate AI call). Added that missing text-report half:
- Migration `docs/sql/64_2026-08-13_ai_radiology_report_columns.sql` adds 6
  nullable columns to `ai_radiology_images`: `report_findings`,
  `report_diagnosis`, `report_recommendations`, `report_text`,
  `report_status` (`done`/`error`, separate from the existing image
  `status`), `report_error_message` — guarded in
  `ems_ai_radiology_ensure_tables()` with the standard
  `ems_column_exists()` check, same defensive pattern as every other
  incremental-column addition in this codebase.
- **New functions in `config/ai_radiology.php`**:
  `ems_ai_radiology_default_report_system_prompt()` (Sp.Rad persona,
  requires the JSON response's `report_text` to contain the 4 section
  headers verbatim, each on its own line, in order),
  `ems_ai_radiology_build_report_user_prompt()`,
  `ems_ai_radiology_sanitize_report()` (coerces `findings`/`recommendations`
  to string arrays defensively, in case the model returns a bare string
  instead), and `ems_ai_radiology_generate_report()` — the orchestrator,
  which calls `ems_ai_ds_call_gemini()` (the same shared text/JSON caller
  Diagnosis/Surgery/Laboratory use, `featureKey: 'ai_radiology_report'`) —
  **not** the image-generation path at all, so the text report is
  completely independent of Gemini image quota / Cloudflare status.
- **`radiology_center_action.php` generates both independently and never
  lets one failure silently discard the other**: report generation (2
  retries) always runs, then image generation (2 retries) always runs,
  regardless of whether the other succeeded. The row is inserted with
  whatever combination of `status`/`report_status` actually resulted. The
  HTTP response is only `ok: false` (hard failure, nothing shown to the
  user) if **both** failed — if either the image or the report succeeded,
  the response is `ok: true` with the row's `image_id` so the frontend JS
  (which already just checks `ok` + `image_id`, unchanged) redirects to
  `radiology_report.php`, which independently shows an error alert for
  whichever half failed and the successful content for whichever
  succeeded. Before this fix, an image failure would discard an already-
  successful text report with a generic "Gagal" and nothing saved-and-
  shown to the user; verified this doesn't regress the original
  both-succeed and both-fail paths.
- **`radiology_report.php`** gained a "Bacaan Radiologi (Sp.Rad)" card
  (Findings bullet list / Impression / Recommendations bullet list, plus
  the full `report_text` rendered with its 4 section headers
  syntax-highlighted) and a **Print / Save PDF** button — same
  client-side-only pattern Laboratory AI already established (hidden
  `#radPrintTemplate` block cloned into a `window.open()` document,
  `window.print()` called after a short delay; no server-side PDF
  generation), formatted to match the reference's own radiology report
  layout (PATIENT block, EXAMINATION block, monospace report body,
  doctor-signature block, "ROXWOOD HOSPITAL / DEPARTMENT OF RADIOLOGY"
  header).
- Verified against the real local dev DB and real APIs (correct PHP
  8.4.22 binary): the 6 new columns migrate cleanly via
  `ems_ai_radiology_ensure_tables()`; a real Gemini text-report call for a
  wrist-fracture case produced a clinically coherent Colles-fracture
  reading with all 4 section headers present verbatim on their own lines;
  a **combined** run (real text report + real Cloudflare image generation
  together, Cloudflare currently enabled and working) succeeded end-to-end
  — both halves generated, both stored in one row, image file saved and
  passed the same `secure_file.php` path-authorization check used in
  production, row and file cleaned up after.

**"Simulasi" wording removed from user-visible copy (same request,
2026-08-12/13)**: user asked that generated documents "feel real" rather
than being undercut by simulation caveats. Removed the word "simulasi"
from 3 places that were visible to the user reading the page/printed
document (not from internal AI system-prompt scaffolding, which already
doesn't leak the word into generated output — confirmed empirically, see
above): `radiology_center.php`'s page subtitle, `laboratory_ai.php`'s page
subtitle, and `laboratory_ai_report.php`'s printed-report header subtitle
("— Simulasi Roleplay" suffix dropped entirely, matching how the printed
Laboratory report and the new Radiology report both otherwise read as
plain professional hospital documents). Deliberately left the internal
system-prompt phrase "untuk simulasi/roleplay EMS" (in
`ems_ai_ds_default_diagnosis_system_prompt()`,
`ems_ai_ds_default_surgery_system_prompt()`, and
`ems_ai_laboratory_default_system_prompt()`) and the image-generation
prompt's "this image is fictional, generated purely for a roleplay medical
training simulation" line (`ems_ai_radiology_build_prompt()`) untouched —
those are backend instructions to the AI model, never rendered to a user,
and the image-generation one specifically exists to keep the model willing
to generate identifiable-looking medical imagery / avoid real-patient
safety-policy refusals; changing that wording risks regressing a
already-fragile, multiple-times-debugged feature (quota/mime/prompt-length
bugs, see above) for a change with zero user-visible effect.

**Psychiatry Center (added 2026-08-13)** — `dashboard/psychiatry_center.php`
+`_action.php`+`psychiatry_report.php`: fifth module of the "Roxwood
Hospital AI" suite, built from a user-supplied reference HTML
("Roxwood Hospital Psychiatry CDSS" — a standalone single-page reference
tool distinct from the earlier "Integrated Medical Systems" portal
reference that Laboratory AI was built from; Surgery/Diagnosis/Radiology/
Laboratory already existed here in equivalent form). Structurally the most
different module in the suite: **multi-turn AI-led clinical interview**
(the AI asks up to 4 sequential questions, updating a probability-ranked
differential diagnosis after each answer) that culminates in a formal
DSM-5/ICD-10 psychiatric assessment — every other AI module in this suite
is single-shot (one form submit → one generation).
- **`config/ai_psychiatry.php`**: three independent prompt/sanitize/call
  pairs for the three interview stages — `ems_ai_psychiatry_generate_start()`
  (initial clinical impressions + first question, from chief complaint +
  anamnesis only), `ems_ai_psychiatry_generate_next()` (updated impressions
  + next question, from the full dialog transcript so far), and
  `ems_ai_psychiatry_generate_final()` (full structured report: MSE across
  all 12 standard parameters, DSM-5/ICD-10 diagnosis + differential, risk
  assessment, treatment plan, medications, clinical summary). All three
  reuse `ems_ai_ds_call_gemini()` (same per-user key as every other text/
  JSON module) with distinct `featureKey`s (`ai_psychiatry_start`/`_next`/
  `_final`) for `system_ai_request_logs` auditing.
- **Multi-turn state lives in client-side JS, not a server session table**
  (deliberate architecture choice, mirroring the reference tool's own
  design): `psychiatry_center.php` keeps a `chatHistory` array
  (`{role: 'ai'|'user', text}`) in memory across the 4 interview turns and
  re-sends the whole thing as a JSON string (`chat_history` POST field) on
  every `next`/`finalize` call — the server is fully stateless per-request
  and re-derives the dialog transcript text via
  `ems_ai_psychiatry_render_dialog_context()` rather than trusting a
  client-formatted string. This avoids needing an interview-session DB
  table entirely.
- **Only a *finalized* assessment is ever persisted** — `start` and `next`
  never write to `ai_psychiatry_assessments`. An abandoned/incomplete
  interview (user navigates away mid-wawancara) leaves no trace in the
  history table, which is intentional: there is no coherent report to show
  for a half-finished interview, unlike the other single-shot modules
  where every attempt (success or failure) gets a history row. `finalize`
  itself DOES insert a row in both the success and failure case (matching
  the other modules' convention at that one point), storing the full
  `chat_transcript` (JSON) alongside `result_json` so the report page can
  show the interview transcript that led to the diagnosis.
- **Report code prefix `PSY-`** (`ems_ai_psychiatry_generate_report_code()`,
  format `PSY-YYYYMMDD-HHMMSS-XXXX`) — own table/column, no cross-feature
  uniqueness constraint needed.
- **Enum normalization for risk fields**: the final-report schema requires
  `risk_assessment.severity` to be exactly one of `Ringan`/`Sedang`/`Berat`
  and `suicide_risk`/`violence_risk`/`self_harm_risk` to be exactly one of
  `Rendah`/`Sedang`/`Tinggi` (Indonesian words chosen over the reference's
  English Low/Moderate/High, consistent with this module's fully-Indonesian
  UI) — `ems_ai_psychiatry_normalize_enum()` coerces any model variance
  (English words, different casing, synonyms) into the canonical value
  before storage, same defensive spirit as Laboratory AI's flag
  normalizer. `psychiatry_report.php` uses the canonical value directly to
  pick a colored risk dot (green/amber/red) — no need to re-parse free text.
- **Report-code chaining reuses the Diagnosis endpoint unchanged**: the
  "Ambil dari Laporan Diagnosis" card on `psychiatry_center.php` calls the
  existing `ai_diagnosis_report_lookup.php` (no new endpoint) and fills
  Patient Name/DOB/Citizen ID + Chief Complaint/Anamnesis — same card
  markup/JS pattern as Laboratory AI and Radiology Center (light-blue
  `card-section` before the form, `arrow-path` icon, single always-visible
  `<p>` status note) after that pattern was standardized across all three
  pages on 2026-08-13 (see below).
- **Print/PDF**: same client-side-only pattern as every other module in
  the suite — hidden `#psyPrintTemplate` block cloned into a
  `window.open()` document, `window.print()` called after a short delay.
- Registered in `config/helpers.php`'s `$roxwoodHospitalAiPages` exception
  array (all 3 filenames) and in `partials/sidebar.php`'s "Roxwood
  Hospital AI" group (`chat-bubble-left-right` icon).
- Verified against the real local dev DB and a real multi-call Gemini
  sequence (per-user key, 5 total calls: 1 start + 3 next + 1 finalize) for
  a realistic major-depressive-episode case: clinical impressions
  correctly converged toward "Major Depressive Episode" as simulated
  patient answers accumulated across turns, final report produced a
  complete 12-parameter MSE, a coherent DSM-5/ICD-10 diagnosis (F32.1) with
  differential, correctly-enumerated risk assessment, a clinically sound
  treatment plan, and an appropriate SSRI medication recommendation with a
  suicide-risk monitoring note — full DB insert → report_code lookup →
  round-trip verified, test row cleaned up after.

**"Ambil dari Laporan Diagnosis" card standardized across all 3 pages that
have it (2026-08-13, user-reported inconsistency)**: when Laboratory AI was
first built, its version of this auto-fill card was accidentally
implemented with different markup/styling than the two earlier pages that
already had it (`radiology_center.php`, `ai_surgery_planner.php`) — nested
inside the `<form>` as its own mini `card` with a grey background and a
`magnifying-glass` icon button, versus the established pattern of a
`card-section` with a light-blue (`#f0f9ff`) background sitting *before*
the `<form>` tag (a sibling section inside the outer card, not nested), an
`arrow-path` icon button, and a status message rendered into an
always-present `<p class="page-subtitle">` (color toggled via JS) rather
than a hidden/unhidden `<div>`. Fixed by rewriting Laboratory AI's card to
match exactly (`laboratory_ai.php`'s `aiLabLookupMsg` div replaced with an
`aiLabLookupNote` paragraph, JS updated to match `radiology_center.php`'s
`showLookupMsg()`/event-listener style). Psychiatry Center's card was
built directly against this now-standardized pattern from the start, so
all three (soon four) pages with this feature share identical markup/CSS/
JS structure — **if a future module needs this same auto-fill card, copy
it from `radiology_center.php` or `psychiatry_center.php`, not from memory.**

**Report-code reuse guard: "1 kode referensi = 1x pakai per halaman"
(added 2026-08-13, user-requested)**: previously a `DGN-` diagnosis report
code could be pasted into "Ambil dari Laporan Diagnosis" and submitted
repeatedly on the same page with no limit — user asked that each code only
be consumable **once per destination page**, while remaining freely usable
across *different* pages (a code used on AI Surgery Planner can still be
used once on Radiology Center, Laboratory AI, and Psychiatry Center — the
restriction is per-table, not global), plus a "Generate Ulang" (regenerate)
escape hatch in each page's history list for when a result quality is bad
and the user wants another AI attempt with the identical inputs+code.
- Migration `docs/sql/66_2026-08-13_ai_report_code_reuse_guard.sql` adds a
  nullable `source_report_code` VARCHAR(40) column to all 4 consumer
  tables (`ai_surgery_plans`, `ai_radiology_images`,
  `ai_laboratory_results`, `ai_psychiatry_assessments`) — chosen over a
  separate relation/usage table so "has this code been used on this page"
  is a single indexed query against the table itself, no JOIN needed.
  **Runtime guard**: each table's own `ensure_tables()` function
  (`ai_diagnosis_surgery.php` for `ai_surgery_plans`, `ai_radiology.php`,
  `ai_laboratory.php`, `ai_psychiatry.php` respectively) ALSO adds the
  column defensively via `ems_column_exists()` — **a real bug was caught
  here during testing**: the first pass only added this runtime guard to
  `ai_surgery_plans` and relied on the standalone migration file alone for
  the other 3 tables, forgetting that this codebase's convention (§12)
  requires BOTH a migration file AND a defensive runtime
  `ems_column_exists()` guard for every new column — the 3 missing guards
  were caught immediately by the end-to-end test (real `PDOException:
  Unknown column` on a live INSERT) and fixed before considering the
  feature done.
- **Shared guard function** `ems_ai_ds_report_code_used_on(PDO $pdo,
  string $table, string $code, string $unitCode): bool` in
  `config/ai_diagnosis_surgery.php` — `$table` is always a hardcoded
  string literal from our own code (never user input), so it's safely
  interpolated into the query. Checks `source_report_code = ? AND
  unit_code = ? AND status = 'done'` — **except for
  `ai_radiology_images`**, which has two independent success states
  (image `status` + text-report `report_status`, see the Radiology text-
  report feature above); there the condition is `(status = 'done' OR
  report_status = 'done')` since either half succeeding represents real
  consumption of that code's clinical context on that page. **This
  asymmetry was also caught by the end-to-end test** (a first pass that
  checked only `status = 'done'` incorrectly reported a code as "unused"
  on Radiology after a report-only success with a deliberately-failed
  image, which would have let the same code silently bypass the guard).
- **Each of the 4 `_action.php` scripts follows the identical pattern**:
  read `regenerate_of` (an existing row ID) before anything else — if
  present, load that row's ORIGINAL stored inputs from the DB (never trust
  resubmitted form fields for a regenerate; Psychiatry's regenerate in
  particular re-derives the dialog transcript from the row's own
  `chat_transcript` JSON rather than re-running the multi-turn interview),
  force the action to proceed, and skip the reuse check entirely — this
  is what makes "Generate Ulang" work even though the code is already
  "used". Otherwise (a genuine fresh submission), read `diagnosis_code`
  from a hidden form field and reject with **HTTP 409** + a message
  pointing at the "Generate Ulang" button if
  `ems_ai_ds_report_code_used_on()` returns true. On success (fresh or
  regenerate), `source_report_code` is stored on the new row either way —
  regenerating deliberately creates a **new** row rather than overwriting
  the old one, so history keeps every attempt.
- **Frontend wiring, standardized across all 4 pages**: the existing
  "Ambil dari Laporan Diagnosis" code input already present on each page
  gained a same-named-pattern hidden field (`aiSurgDiagCodeHidden` /
  `radDiagCodeHidden` / `aiLabDiagCodeHidden` / `psyDiagCodeHidden`) that's
  populated with `data.report_code` from the lookup response on success,
  and explicitly cleared by an `input` listener on the visible code field
  (typing a different code without re-fetching must not silently keep
  associating the OLD code with the next submission). Each history table
  gained a **"Generate Ulang"** button (`arrow-path` icon, same as the
  lookup button), shown only on rows where `source_report_code` is
  non-null, POSTing `regenerate_of=<id>` + reusing each page's existing
  progress-overlay JS (`resetOverlay`/`showError`/`finishSuccess` on the 3
  form-based pages; `showOverlay`/`hideOverlay`/`overlayError` on
  Psychiatry Center, which has no single `<form>` to begin with). A
  `confirm()` dialog gates the action since it consumes an AI call.
  Psychiatry Center's "Generate Ulang" is notably **not** a full interview
  redo — it calls `action=finalize&regenerate_of=<id>` directly (no
  `start`/`next` round trip), re-running only the final structured-report
  generation against the already-completed transcript, matching the
  user's framing that regenerate exists for "the result wasn't good", not
  "the interview needs to happen again".
- Verified end-to-end against the real local dev DB and repeated real
  Gemini calls: a code freshly used on AI Surgery Planner correctly reads
  as "used" only on `ai_surgery_plans` and remains free on the other 3
  tables (cross-page independence confirmed positively, not just assumed);
  the same code was then successfully consumed once each on Radiology
  Center (report-only), Laboratory AI, and Psychiatry Center
  (finalize-only), confirming all 4 tables end up correctly marked;
  "Generate Ulang" was proven to bypass the guard by creating a genuine
  second `ai_surgery_plans` row with the identical `source_report_code`
  via a real second Gemini call; a direct fresh (non-regenerate) resubmit
  of an already-used code was confirmed blocked by the guard function; an
  unrelated random code was confirmed still free. All test rows deleted
  after.

**Reuse guard: warn at fetch time, not just at submit time (2026-08-13,
same day, user-reported UX gap)**: the guard above only surfaced the
"already used" 409 error when the user actually clicked
Generate/Submit — meaning a user could paste a code, watch it auto-fill
the whole form, fill in more fields, and only THEN discover (via a
generic error) that the code was already consumed on this page. User
asked for the warning to appear immediately when clicking "Ambil Data",
including who used it, so they don't waste time filling in a doomed
submission.
- `ems_ai_ds_report_code_used_on()` (`config/ai_diagnosis_surgery.php`)
  refactored to delegate to a new `ems_ai_ds_report_code_usage_info(PDO
  $pdo, string $table, string $code, string $unitCode): ?array` — same
  matching logic (including the `ai_radiology_images` dual-status OR
  condition) but returns `{created_at, user_name}` of the **first** row
  that consumed the code on that table (`ORDER BY t.id ASC`, joined to
  `user_rh`) instead of just a bool.
- `ai_diagnosis_report_lookup.php` now accepts an optional `&target=`
  query param, validated against a hardcoded whitelist of the 4 real
  table names (`ai_surgery_plans`/`ai_radiology_images`/
  `ai_laboratory_results`/`ai_psychiatry_assessments` — an unrecognized
  value is silently ignored, never reaches SQL) and includes a
  `used_on_target: {user_name, created_at} | null` field in the JSON
  response.
- All 4 pages' "Ambil dari Laporan Diagnosis" fetch now pass their own
  `&target=<table>`, and a shared-shaped `formatUsedOnTargetWarning(data)`
  JS helper (duplicated per-page, same pattern as the rest of this
  auto-fill card) turns a non-null `used_on_target` into an amber
  (`#d97706`) warning naming who used it and when, **overriding** whatever
  the normal success message would have said — the form still auto-fills
  (so the data can be reviewed / a "Generate Ulang" can be found in
  history) but the status line makes it unambiguous upfront that a fresh
  submit will be rejected, rather than letting the user discover that only
  after filling in the rest of the form and clicking submit.
- Verified directly against the real local dev DB: a real `ai_surgery_plans`
  row's `source_report_code` correctly resolves to the actual creating
  user's `full_name` + `created_at` via `ems_ai_ds_report_code_usage_info()`;
  confirmed still `null` for the same code on a table it hasn't touched yet
  (`ai_radiology_images`); confirmed an invalid/unrecognized `target` value
  is safely ignored rather than erroring.

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

**Assistant Manager track de-GA'd (wording + a real functional fix, 2026-08-11)**:
the assistant-manager track was originally built assuming General Affair
specifically, and had leftover "GA"/"General Affair" copy in
`assistant_manager_candidates.php` (page subtitle, portal-status badge)
and in the public forms (`public/ga_recruitment.php`,
`public/recruitment_form_assistant_manager.php`) — all reworded to
generic "Asisten Manager" language so the same flow reads correctly for
any division. **The functional part**:
`recruitment_form_assistant_manager.php` had a hidden
`<input name="target_division" value="General Affair">` that unconditionally
submitted "General Affair" regardless of which division the applicant
actually belonged to — since this track recruits already-existing staff
(see "Public recruitment portal journey" above), their real division is
already known via `user_rh.division` the moment their Citizen ID is
verified. Fixed by (1) having the citizen-autocomplete JS populate the
hidden field from the selected user's own `item.division` instead of a
static value, and (2) — since a hidden form field is client-controlled and
shouldn't be trusted alone — `recruitment_submit.php` now also selects
`division` in `recruitmentFetchVerifiedUser()` and overrides
`$targetDivision` server-side from the verified `user_rh` row whenever
it's non-empty, so the stored value is authoritative regardless of what
the client submitted. The old silent fallback to `'General Affair'` when
`target_division` was empty was also removed (falls back to `null` now)
since defaulting to GA was the exact bug being fixed. Note:
`medical_applicants.target_division` is currently write-only — nothing
in `assistant_manager_candidates.php` or the candidate-review pages
filters or displays by it yet, so this fix corrects the stored data for
future use but doesn't itself change who can see/manage a candidate.
Verified against a real local `user_rh` row (division `Medis`) that the
new server-side derivation correctly resolves to `Medis` instead of the
old hardcoded `General Affair`.

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
6a. **This local dev box runs TWO separate PHP installs — testing via plain
    `php` on the CLI does NOT validate what the live site actually does.**
    `php` on PATH resolves to `C:\Server\php-8.5.7-nts-Win32-vs17-x64\`, but
    the real web server (`localhost:8081`, nginx reverse-proxying to
    `php-cgi.exe` on `127.0.0.1:9000`, started via
    `C:\Server\nginx_php_runner.ps1`) runs
    `C:\Server\php-8.4.22-nts-Win32-vs17-x64\php-cgi.exe` — a **different
    binary with a different `php.ini`**. Discovered 2026-08-12 after a false
    diagnosis: 8.5.7's `php.ini` has empty `curl.cainfo`/`openssl.cafile`
    (breaks outbound HTTPS/cURL entirely), which looked like it explained a
    reported Gemini-call failure — but 8.4.22's `php.ini` already had a
    working `curl.cainfo`/`openssl.cafile` pointing at a real
    `cacert.pem` the whole time, so that was never the actual bug hitting
    real users. **When testing anything that touches an external HTTPS call
    (Gemini, OCR.Space, etc.) or needs to match production PHP behavior,
    invoke `C:\Server\php-8.4.22-nts-Win32-vs17-x64\php.exe` explicitly**,
    not bare `php`. (The CA-probing defensive fix added during the false
    diagnosis — `actions/ai_gemini_client.php::emsFindCaBundlePath()`,
    which sets `CURLOPT_CAINFO` from a probed path when the active php.ini
    doesn't have one configured — was left in place anyway since it's
    harmless, matches the existing `emsFindHeadlessBrowserPath()` defensive-
    probe pattern, and protects any other environment that genuinely does
    have this misconfiguration; it just wasn't the fix for this bug.)
6b. **Real root cause of the `ai_diagnosis_assistant.php` "Gagal" report
    (fixed 2026-08-12)**: Google deprecated the entire Gemini 2.5 model
    generation for this project's *personal* API keys — confirmed via live
    calls (using the correct 8.4.22 binary, see above) that
    `gemini-2.5-flash`, `gemini-2.5-flash-lite`, and `gemini-2.5-pro` all
    return `"This model ... is no longer available to new users"` for the
    per-user keys stored in `user_ai_settings`, while `gemini-3.5-flash-lite`
    still works fine on the same keys. The **separate, older** API key in
    `system_ai_settings` (used by recruitment scoring/birthday messages/
    training-group naming — see §6 AI recruitment engine) was verified
    still working fine on `gemini-2.5-flash-lite`, so this is specific to
    newer keys, not a blanket Google-wide sunset — don't assume the global
    recruitment key is also broken. Fixed by (1) a one-time `UPDATE
    user_ai_settings SET default_model='gemini-3.5-flash-lite' WHERE
    default_model IN ('gemini-2.5-flash','gemini-2.5-flash-lite',
    'gemini-2.5-pro')` on the affected rows, and (2) changing every
    hardcoded `'gemini-2.5-flash'` fallback in the **personal-key suite**
    (`dashboard/ai_settings_personal.php`, `ai_settings_personal_action.php`,
    `config/ai_diagnosis_surgery.php`) to `'gemini-3.5-flash-lite'`, so new
    users (and the column-level `DEFAULT` on fresh `user_ai_settings`
    installs) don't get stuck with a dead model either. Deliberately did
    **not** touch the `system_ai_settings`-based recruitment/birthday/
    training-group fallbacks (`config/ai_settings.php`,
    `actions/ai_recruitment_service.php`, `config/birthday_helper.php`,
    `config/training_groups.php`, `dashboard/ai_settings_action.php`) since
    that key was confirmed still working — if Google later deprecates 2.5
    for that key too, apply the same fix there. Verified end-to-end with
    the real affected account (`user_id=1`) on the correct PHP binary: a
    genuine diagnosis call now succeeds (e.g. "Vulner laceratum pedis /
    cruris dextra" for a test anamnesis).

**Radiology Center image quota — confirmed account/billing-side, not fixable
in code (investigated thoroughly 2026-08-12)**: after a user report that
Radiology Center still failed post-fix, queried the real
`GET {base_url}/models` endpoint for the affected personal key to get
Google's own list of every model actually available to it (not guessing
names), then live-tested **7 different image-generation-capable models**
spanning every generation and preview tier: `gemini-2.5-flash-image`,
`gemini-3.1-flash-image`, `gemini-3.1-flash-lite-image`,
`gemini-3-pro-image`, `gemini-3.1-flash-image-preview`,
`gemini-3-pro-image-preview`, `nano-banana-pro-preview`. **Every single
one** returned `Quota exceeded ... limit: 0` for
`generativelanguage.googleapis.com/generate_content_free_tier_requests`
(and `_input_token_count`) — conclusively ruling out "wrong/deprecated
model name" as the cause (unlike the text-model bug above, which *was*
model-specific). This account's Google Cloud project simply has **zero
free-tier quota for image generation entirely**, regardless of model —
Gemini image generation typically requires billing enabled on the
project, unlike text generation which has a generous free tier. Not
fixable from this codebase; the user needs to enable billing on the
Google Cloud project tied to this API key (via Google AI Studio or Cloud
Console) or supply a different key from a billing-enabled project.
`ems_ai_radiology_model()` in `config/ai_radiology.php` was bumped from
`gemini-2.5-flash-image` to `gemini-3.1-flash-image` anyway (both equally
quota-blocked today, but 3.1 is less likely to hit the same "no longer
available to new users" deprecation the 2.5 *text* models already hit) —
purely a forward-looking hygiene change, not a fix for this specific
error. **If a future session gets asked to "fix" this again, don't re-
litigate the model name — check whether billing has been enabled on the
Google Cloud project first.**

**Cloudflare Workers AI added as a free alternative provider (2026-08-12)**:
since Gemini image generation is blocked by Google account billing (not
fixable from this codebase, see above), added a second, independent image-
generation provider — Cloudflare Workers AI has a genuine free tier
(~100k requests/day, no credit card) that includes real image models
(FLUX.1 schnell, Stable Diffusion XL, etc.) via the same `generateContent`-
style single-model-per-request REST pattern, just with different auth
(Account ID + Bearer API Token, not a single key) and endpoint shape
(`POST https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run/{model}`).
- **New, fully separate settings system** — global, Programmer-Roxwood-only
  (like `system_ai_settings`, unlike the per-user `user_ai_settings`, since
  Cloudflare account+token setup is meaningfully more involved than pasting
  a Gemini key): `system_cloudflare_settings` table (migration
  `docs/sql/61_2026-08-12_cloudflare_workers_ai_settings.sql`),
  `config/cloudflare_settings.php` (ensure/get/save/model-options/mask
  helpers), `dashboard/cloudflare_settings.php`+`_action.php` (mirrors
  `ai_settings.php`'s structure/gate exactly — `ems_require_programmer_roxwood_access()`,
  no `ems_enforce_dashboard_page_access()` call, same as its sibling — plus
  a "Cara Setup" card with the exact dashboard click-path since a fresh
  session/user won't know Cloudflare's UI). Sidebar entry added next to
  "Setting AI" in the Pengaturan group (superuser-only block in
  `partials/sidebar.php`).
- **`actions/cloudflare_client.php`**: `ems_cloudflare_generate_image()`.
  The response format genuinely differs by model on Workers AI — some
  return raw binary image bytes (`Content-Type: image/*`), others return
  JSON with a base64 `result.image` field — so the client checks the
  response `Content-Type` header rather than assuming either shape, and
  treats a raw-binary response as already-decoded bytes (re-encodes to
  base64 itself to match the uniform `['mime_type', 'data']` shape the
  rest of the Radiology Center pipeline expects from
  `ems_ai_radiology_save_image_file()`). Reuses `ems_ai_log_request()`
  from `ai_gemini_client.php` for the shared `system_ai_request_logs`
  table (passes `provider: 'cloudflare'`) rather than inventing a second
  logging path.
- **Provider selection is automatic, not a per-request toggle**:
  `ems_ai_radiology_generate_image()` in `config/ai_radiology.php` is the
  new single entry point `radiology_center_action.php` calls (replacing
  the old direct `ems_ai_radiology_call_gemini_image()` call) — it checks
  `system_cloudflare_settings.is_enabled` (+ both credentials non-empty)
  and routes to Cloudflare if so, otherwise transparently falls back to
  the existing per-user Gemini path. Flipping the "Aktifkan" checkbox on
  `cloudflare_settings.php` is the only thing that changes which provider
  every user's Radiology Center generation uses — no other code/config
  needs touching to switch back once Gemini billing is eventually sorted.
- **Real bug found and fixed while building this**: `ems_table_exists()`
  (`config/helpers.php`) caches its result in a `static` array for the
  life of the PHP process/request, keyed by table name. Calling it to
  *check before creating* a table (as `ensure_tables()`-style functions
  throughout this codebase do) permanently poisons that cache entry to
  `false` for the rest of the request, even after the table is actually
  created moments later — so any function that re-checks the same table's
  existence later in the *same* request (e.g. a settings page that calls
  `ensure_tables()` then immediately reads settings) incorrectly believes
  the table still doesn't exist. Caught this concretely:
  `ems_cloudflare_get_settings()` returned defaults immediately after
  `ems_cloudflare_save_settings()` had *just* successfully written a row
  in the same script — the data was correctly in the database (verified
  via a raw query bypassing the helper) but invisible to the app until the
  next request. Fixed by having `ems_cloudflare_get_settings()` query
  directly and catch the exception if the table genuinely doesn't exist,
  instead of pre-checking with the cached `ems_table_exists()`. **This
  same latent bug likely exists anywhere else in the codebase that calls
  an `ensure_tables()`-style function and then immediately re-derives
  "does table X exist" in the same request** (the Radiology Center and AI
  Diagnosis modules built earlier this session happened not to hit it
  because nothing in their flow re-checked table existence after creating
  it — this one did, because the settings page's read path went through
  a table-existence check that the write path's `ensure_tables()` call
  had already poisoned).
- Verified end-to-end against the **real** Cloudflare API (not mocked):
  provider-fallback logic (Cloudflare disabled → correctly routes to
  Gemini, reproducing the known quota error), and Cloudflare-enabled with
  intentionally-fake credentials → got back a real, specific Cloudflare
  API error ("Could not route to .../fake_account_id..., perhaps your
  object identifier is invalid?"), proving the request construction,
  auth headers, and error-parsing path all work correctly end-to-end —
  only a genuine Account ID + API Token from the user's own Cloudflare
  account is missing to make it actually generate images. Test
  credentials were cleared from `system_cloudflare_settings` afterward
  (left disabled/empty, not pointing at anything fake).
- **User completed the real Cloudflare signup same day and it's now live**:
  `system_cloudflare_settings` has a genuine Account ID + API Token, `is_enabled=1`
  — confirmed with a real `ems_cloudflare_test_connection()` call (got back an
  actual 335KB PNG). One rough edge surfaced during the user's own real setup:
  the checkbox on `cloudflare_settings.php` **must actually be ticked before
  clicking Simpan Setting** — the user saved credentials once without ticking
  it, which is indistinguishable from "not yet configured" from the outside
  (Radiology Center silently falls back to Gemini, which was still failing on
  the quota issue above) and looked like "Cloudflare setup didn't work" until
  traced back to the checkbox. Not a code bug, just a UX trap worth knowing
  about if this gets reported again — check `is_enabled` in the table first.
- **Real mime-type bug found and fixed same day**: Cloudflare's FLUX.1
  [schnell] model (the default) returns its JSON `result.image` field as
  **JPEG** bytes, not PNG, despite nothing in the response envelope saying
  so — `ems_cloudflare_generate_image()` originally hardcoded
  `'mime_type' => 'image/png'` for that response path (reasonable-looking
  default, wrong in practice), which meant `ems_ai_radiology_save_image_file()`
  wrote a `.png`-extensioned file containing actual JPEG bytes — silently
  broken until something tried to actually decode it as PNG (see next point).
  Fixed with `ems_cloudflare_detect_image_mime()`: sniffs the real format
  from the decoded bytes' magic number (PNG/JPEG/WEBP signatures) instead of
  guessing — never trust a provider's response shape implies a specific
  format when the bytes can just be inspected directly.
- **Patient/doctor/finding text overlay (added 2026-08-12, same day)**: user
  asked for doctor name, patient name, age, and the clinical finding burned
  into the generated image itself (like a real PACS export's corner
  annotations) — deliberately **not** delegated to the image-generation
  model itself (diffusion models are unreliable at rendering accurate,
  correctly-spelled multi-line text; asking for it risks garbled output).
  Instead `ems_ai_radiology_apply_overlay()` in `config/ai_radiology.php`
  opens the just-saved file with GD and burns in 4 corner text blocks
  (top-left: name/age/citizen ID, top-right: modality+region+finding,
  bottom-left: doctor+timestamp, bottom-right: "ROXWOOD HOSPITAL / SIMULASI
  ROLEPLAY" watermark) using GD's **built-in bitmap font** (`imagestring()`,
  font size 5) rather than `imagettftext()` — deliberately avoids needing a
  bundled/located TTF file (no font ships with this repo), matching the
  project's established caution around Windows-vs-Linux path portability
  (see the headless-browser gotcha in §10). This is what surfaced the mime-
  detection bug above — GD's `imagecreatefrompng()` silently failed on the
  mislabeled JPEG bytes, so overlay application returned `false` with no
  visible error (`radiology_center_action.php` treats overlay failure as
  non-fatal by design — a raw un-annotated image is still better than no
  image — so this would NOT have surfaced as a user-facing error, only as
  images silently missing their info overlay). `ems_ai_radiology_age_label()`
  computes age from `patient_dob` via `DateTime::diff()`. Verified visually
  with a real generated wrist X-ray (Cloudflare, post-mime-fix): all 4
  corner blocks rendered legibly over a real generated image, confirmed by
  reading the output file directly.
- **Cloudflare's content moderation can false-positive on legitimate
  clinical prompts**: observed live — a CT abdomen prompt for "Massa /
  Tumor" (a completely normal clinical finding option in the catalog) was
  rejected with `AiError: Input prompt contains NSFW content`, while the
  wrist-fracture X-ray prompt succeeded fine. This is Cloudflare's safety
  filter being overly cautious about certain anatomical/clinical wording
  combinations, not a bug in this codebase, and not something the existing
  2-attempt retry loop in `radiology_center_action.php` can work around
  (retrying the identical prompt against a content filter just fails the
  same way twice). Not fixed — flagging so a future session doesn't
  mistake a `AiError: ... NSFW content` response for an app bug.
- **Real bug found and fixed 2026-08-12 (user-reported, live production +
  local repro)**: Radiology Center generate started failing consistently
  right after the same-day "Diagnosis → Surgery/Radiology case-chaining"
  feature shipped (see AI recruitment section above) — root cause was
  **prompt length**, not auth/quota/moderation like the earlier entries in
  this list. Cloudflare Workers AI hard-rejects any `/prompt` longer than
  2048 characters (`Bad input: Error: Length of '/prompt' must be <= 2048`),
  and the new `anamnesis_lengkap` auto-fill (a full AI-written clinical
  paragraph, easily 800-1500+ characters) replaced what used to be a short
  manually-typed anamnesis inside `ems_ai_radiology_build_prompt()`'s
  prompt — pushing the total over the limit. Confirmed by reading the real
  stored `error_message` on the failed rows in `ai_radiology_images`
  (`dashboard/radiology_center.php`'s history table) rather than guessing —
  worth remembering as a debugging pattern: **the actual error text is
  always stored in that table's `error_message` column**, so check it
  directly before re-diagnosing from scratch. Fixed by budgeting and
  truncating the anamnesis portion specifically:
  `EMS_AI_RADIOLOGY_PROMPT_MAX_LENGTH` (1900, a safety margin under
  Cloudflare's 2048) minus the already-known-length fixed parts of the
  prompt (modality/category/region/projection/finding lines + the ~991-
  character STYLE REQUIREMENTS block) leaves a remaining budget for
  anamnesis; if the anamnesis doesn't fit, it's truncated with `mb_strimwidth()`
  + `...`, and if there's no budget left at all (region/finding names
  themselves unusually long) anamnesis is dropped entirely rather than
  failing the whole generation. Applied universally (not just for the
  Cloudflare path) since Gemini doesn't have this exact limit but also
  isn't hurt by a shorter, still-informative anamnesis. Verified against
  the real failing case (5 consecutive "Richard Harley" / X-Ray Tibia-
  Fibula failures in the live history table) by reproducing the same
  long-anamnesis prompt locally — first attempt at the fix undershot the
  STYLE REQUIREMENTS block's real length (guessed 620, actual 991) and
  still produced an over-limit prompt (2271 chars); corrected the constant
  and re-verified with three cases (1215-char anamnesis → 1881 total,
  extreme 5700-char anamnesis → 1883 total, no anamnesis → 1321 total),
  then confirmed with one real Cloudflare API call reproducing the exact
  failing scenario — succeeded, real JPEG returned.
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
