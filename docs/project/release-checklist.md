# Release Checklist

Use this checklist before publishing a GitHub Release.

## Before Tagging

- Confirm the working tree is clean.
- Review merged pull requests and closed issues.
- Run CSS build: `npm run build:css`.
- Run available tests: `npm test`.
- Syntax-check changed PHP files with `php -l`.
- Review new SQL migrations in `docs/sql/`.
- For cPanel auto-deploy, add new migrations as the next numbered file after `102_`; never edit a migration already deployed.
- Confirm no `.env`, database dump, production upload, log, or private backup is staged.

## Database

- Back up the production database before schema or data changes.
- cPanel auto-deploy applies numbered migrations above baseline `102` before activating the new code; other environments still apply migrations in filename order.
- Record the migration filenames in release notes.
- Review recovery steps for risky or destructive migrations; MySQL DDL may not roll back transactionally.

## Manual Verification

- Login and logout.
- Dashboard load.
- Role-based access checks for affected modules.
- File upload and secure preview.
- Export flows.
- Cron jobs touched by the release.
- Realtime features when Firebase config changed.

## GitHub Release Notes

Include:

- Summary.
- Changed modules.
- Migration files.
- Upgrade steps.
- Security notes.
- Known issues.

## After Release

- Create follow-up issues for deferred work.
- Watch errors and user reports.
- Patch release quickly for regressions.
