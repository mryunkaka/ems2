# Contributing to EMS2

Thank you for helping improve EMS2. This project welcomes practical contributions that make hospital operations, HR workflows, recruitment, reporting, security, or deployment easier to maintain.

## Good First Contributions

- Improve installation and deployment documentation.
- Add anonymized test data or migration notes.
- Fix PHP warnings, edge cases, and broken UI states.
- Improve accessibility and responsive behavior.
- Add issue reproduction steps and screenshots with private data removed.
- Add focused tests for helpers, guards, and high-risk workflows.

## Ground Rules

- Do not include real patient, employee, applicant, salary, medical, credential, or production data.
- Keep changes scoped to the module being fixed.
- Open an issue before large rewrites or schema changes.
- Include migration notes for any database change.
- Keep generated dependencies out of commits unless the project explicitly requires the lockfile update.

## Development Workflow

1. Fork the repository.
2. Create a feature branch from `main`.
3. Install dependencies with `composer install` and `npm install`.
4. Configure `.env` from `.env.example`.
5. Make a focused change.
6. Run the relevant local checks.
7. Open a pull request using the template.

## Suggested Checks

```bash
npm run build:css
npm test
```

For PHP changes, run a syntax check on changed PHP files:

```bash
php -l path/to/changed-file.php
```

## Pull Request Expectations

Each PR should explain:

- The problem being solved.
- The main implementation approach.
- Database migrations added or changed.
- Manual test steps.
- Screenshots for UI changes, with sensitive data removed.

## Reporting Bugs

Use the bug report issue template and include:

- Module or page.
- Expected behavior.
- Actual behavior.
- Steps to reproduce.
- Browser, PHP version, and database version if relevant.
- Sanitized logs only.
