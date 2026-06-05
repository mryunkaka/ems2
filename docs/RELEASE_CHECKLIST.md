# Release Checklist

Use this checklist before publishing a GitHub Release.

## Before Tagging

- Confirm the working tree is clean.
- Review merged pull requests and closed issues.
- Run CSS build: `npm run build:css`.
- Run available tests: `npm test`.
- Syntax-check changed PHP files with `php -l`.
- Review new SQL migrations in `docs/sql/`.
- Confirm no `.env`, database dump, production upload, log, or private backup is staged.

## Database

- Back up the production database.
- Apply migrations in filename order.
- Record the applied migration range in release notes.
- Verify rollback expectations for risky migrations.

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
