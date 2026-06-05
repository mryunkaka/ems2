# Codex for Open Source Application Notes

Use this file as preparation for the OpenAI Codex for Open Source form.

Official program page: <https://openai.com/form/codex-for-oss/>

## Current Public Signals

- Repository: <https://github.com/mryunkaka/ems2>
- Visibility: public
- Primary maintainer: `mryunkaka`
- Active maintenance: commits through June 2026
- License: MIT
- Install docs: `README.md` and `docs/INSTALLATION.md`
- Security policy: `SECURITY.md`
- Contribution docs: `CONTRIBUTING.md`
- Issue templates: `.github/ISSUE_TEMPLATE/`
- Release process: `CHANGELOG.md` and `docs/RELEASE_CHECKLIST.md`

## Signals That Still Need Real GitHub Activity

Do these on GitHub before submitting, because they cannot be honestly created only through documentation:

1. Publish at least one GitHub Release, for example `v1.0.0`.
2. Open several real issues:
   - `good first issue`: improve installation docs.
   - `bug`: known reproducible defect.
   - `documentation`: add anonymized demo setup guide.
   - `security`: harden upload directory deployment docs.
3. Invite at least one real user, tester, or collaborator to:
   - open an issue,
   - test a release,
   - submit a docs PR,
   - or be listed as a contributor after a real contribution.
4. Add repo topics in GitHub settings:
   - `php`
   - `mariadb`
   - `hospital-management`
   - `medical-records`
   - `hris`
   - `open-source`
5. Add anonymized screenshots or demo data only if all private data is removed.

## Form Field Drafts

### Describe your role

Primary maintainer. I created and maintain EMS2, review changes, manage SQL migrations, maintain deployment/security docs, triage operational bugs, and prepare releases for the open-source repository.

### Why does this repository qualify? 500 characters max

EMS2 is an active open-source PHP/MariaDB hospital operations system covering medical records, HR, recruitment, pharmacy, secretary, disciplinary, salary, realtime chat, and audit workflows. It helps smaller medical organizations replace spreadsheets and manual approvals with maintainable, auditable tooling. The repo has active commits through June 2026, MIT license, install docs, security policy, and release process.

### How will you use API credits? 500 characters max

I will use API credits to improve OSS maintenance: issue triage, PR review support, security review of PHP upload/auth/database flows, migration review, release note drafting, documentation updates, and optional maintainer automation that summarizes bugs, flags risky changes, and helps contributors understand module ownership.

### Anything else we should know? 500 characters max

EMS2 is maintained from real hospital workflow needs, but the public repository is being prepared to avoid private patient, employee, applicant, credential, and production data. Codex would reduce review and maintenance load across many PHP modules, SQL migrations, deployment docs, and security-sensitive file handling workflows.

## Suggested First GitHub Release

Tag: `v1.0.0`

Title: `EMS2 v1.0.0 - Open Source Maintenance Baseline`

Release notes:

```markdown
## Summary

First open-source maintenance baseline for EMS2.

## Highlights

- Medical records, HR, recruitment, pharmacy, secretary, disciplinary, salary, and realtime workflows.
- SQL migration history in `docs/sql/`.
- MIT license, install guide, contribution guide, security policy, issue templates, and release checklist.

## Upgrade Notes

- Review `.env.example`.
- Install Composer and npm dependencies.
- Apply SQL migrations in `docs/sql/` in chronological order.
- Keep private data, uploads, database dumps, and credentials out of the public repository.

## Known Limitations

- Automated test coverage is not yet configured.
- Demo dataset is not included yet because real operational data must be anonymized first.
```
