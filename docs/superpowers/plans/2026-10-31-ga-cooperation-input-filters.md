# GA Cooperation Input Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add date and institution filters to the GA cooperation input list and make Excel export match those filters for authorized GA managers.

**Architecture:** Keep filtering server-side. The listing GET form will submit the same date and institution parameters either to the listing page (Apply) or to the export endpoint (Export Excel). The export endpoint independently enforces the existing GA manager access rule.

**Tech Stack:** PHP, PDO, PhpSpreadsheet, existing EMS helpers.

**Spec:** `docs/superpowers/specs/2026-10-31-ga-cooperation-input-filters-design.md`

## Global Constraints

- The institution filter defaults to all institutions.
- Date filtering uses the existing `range`, `from`, and `to` parameters.
- Access uses `ems_require_general_affair_manager_access()`.
- Listing remains limited to the user's effective unit.
- Do not change input flow or Excel columns.

## Review Focus

- A specific institution is selected: the list and export must both restrict by its cooperation tag.
- No institution is selected: both must include all institutions for the effective unit.
- A custom date range is incomplete: export must use the same fallback dates as the listing.
- A non-GA manager directly opens the export endpoint: access must be denied.
- Non-manager/non-GA users: the Export Excel button must be absent.

### Task 1: Align listing filters and export access

**Files:**
- Modify: `dashboard/general_affair_kerjasama_input.php`
- Modify: `dashboard/general_affair_kerjasama_input_export.php`

**Interfaces:**
- Listing and export both consume GET parameters `range`, `from`, `to`, and `cooperation_id`.
- `cooperation_id=0` or an omitted value means all institutions; a positive valid ID filters to that institution within the user's effective unit.

- [x] Replace the export modal with an institution select in the main GET filter form; add a second submit button using `formaction` for Excel export.
- [x] Apply the selected institution to the listing query and show the export button only when `ems_normalize_division(...) === 'General Affair'` and `ems_is_manager_plus_role(...)` is true.
- [x] Require GA manager access at the export endpoint and allow all valid unit institutions when no institution is selected.
- [x] Apply the selected institution and the resolved date range to the export query so it matches the listing, including the date-range fallback for incomplete custom dates.
- [x] Remove modal-only export markup and JavaScript, and set the workbook title to the selected institution or “Semua Instansi”.
- [x] Run `php -l` on both modified PHP files and inspect the diff for matching GET parameter names and access checks.
