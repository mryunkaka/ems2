# EMS2

EMS2 is an open-source emergency medical, hospital administration, HR, recruitment, and operations management system built with PHP, MariaDB, Tailwind CSS, and Alpine.js.

The project started as a production workflow system for Roxwood Hospital operations and is maintained as a practical reference implementation for small and mid-sized medical organizations that need auditable internal tooling without a heavy framework stack.

## Why This Project Matters

Many clinics and hospital support teams still rely on spreadsheets, chat messages, paper forms, and manual approvals for daily operations. EMS2 brings those workflows into one role-based web application:

- Medical records and supporting document uploads.
- Pharmacy recaps, billing audit, quiz workflows, and live availability.
- HR attendance, leave, resignation, promotion, and user document verification.
- Recruitment intake, screening, interviews, blacklist checks, and candidate decisions.
- Secretary, disciplinary committee, cooperation, event, salary, and reimbursement workflows.
- Real-time chat, live music queue, push notifications, and Firebase-backed presence.
- SQL migration history for traceable operational changes.

## Project Status

- Status: active maintenance
- Primary maintainer: [@mryunkaka](https://github.com/mryunkaka)
- Repository: <https://github.com/mryunkaka/ems2>
- License: MIT
- Recent activity: active commits through June 2026
- Production note: this repository must never include real patient, employee, credential, or private hospital data.

## Tech Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.x |
| Database | MariaDB / MySQL |
| Frontend | Tailwind CSS, Alpine.js, jQuery |
| Tables and charts | DataTables, Chart.js |
| Documents | HTML2PDF, PhpSpreadsheet |
| Realtime | Firebase Realtime Database |
| Notifications | Web Push API |

## Main Modules

- Dashboard and role-based navigation.
- Rekam medis and forensic medical records.
- Pharmacy recap, online status, quizzes, and audit workflows.
- HR account settings, attendance, leave, resignation, promotion, and document verification.
- Recruitment portal, applicant documents, interview scoring, and candidate decisions.
- Secretary letters, file registry, visit agenda, and internal coordination.
- Disciplinary cases, warning letters, indication audit, and point reductions.
- General affair cooperation and event management.
- Salary, reimbursement, restaurant consumption, and reporting exports.
- Live chat, live music queue, push notifications, and cron automation.

## Requirements

- PHP 8.1 or newer.
- MariaDB 10.6+ or MySQL 8+.
- Composer 2.x.
- Node.js 20+ and npm.
- Apache or Nginx with PHP-FPM.
- Writable directories for runtime storage only: `storage/`, `logs/`, and any configured private backup directory.

## Quick Start

```bash
git clone https://github.com/mryunkaka/ems2.git
cd ems2
composer install
npm install
cp .env.example .env
```

Edit `.env` with your local database and optional Firebase values:

```env
APP_ENV=local
DB_HOST=127.0.0.1
DB_NAME=ems2_local
DB_USER=ems2
DB_PASS=change_me
DB_TIMEZONE=+07:00
```

Build frontend assets:

```bash
npm run build:css
```

Create the database, then apply SQL files in `docs/sql/` in chronological order. Existing deployments should review each migration before applying it to production.

Point your web server document root to the repository root or `public/`, depending on your deployment model. The root `index.php` redirects to `public/index.php`.

## Local Development

For CSS development:

```bash
npm run watch:css
```

For a basic PHP development server:

```bash
php -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000>.

## Database Migrations

Database changes are stored in `docs/sql/`. The filenames include dates and module descriptions so maintainers can audit changes before deployment.

Recommended process:

1. Back up the target database.
2. Review new SQL files.
3. Apply migrations in filename order.
4. Verify login, dashboards, uploads, exports, and affected cron jobs.
5. Record the deployed migration range in your release notes.

## Security and Privacy

EMS2 handles workflows that may involve sensitive medical, employee, and applicant information. Before publishing forks, issues, screenshots, or logs:

- Remove real names, patient identifiers, phone numbers, addresses, salaries, and medical details.
- Never commit `.env`, database dumps, production uploads, private backups, or Firebase secrets.
- Use private security reporting for vulnerabilities. See [SECURITY.md](SECURITY.md).
- Follow the credential rotation guidance in [docs/deploy/ROTATE_CREDENTIALS.md](docs/deploy/ROTATE_CREDENTIALS.md).

## Documentation

- [Installation Guide](docs/INSTALLATION.md)
- [Release Checklist](docs/RELEASE_CHECKLIST.md)
- [UI Design System](docs/ui-design-system.md)
- [Firebase Realtime Chat Setup](docs/FIREBASE_REALTIME_CHAT_SETUP.md)
- [Assistant Manager Recruitment Flow](docs/ASSISTANT_MANAGER_RECRUITMENT_FLOW.md)
- [Disciplinary Committee Module](docs/DISCIPLINARY_COMMITTEE_MODULE.md)
- [Forensic Module](docs/FORENSIC_MODULE.md)
- [Secretary Module](docs/SECRETARY_MODULE.md)

## Contributing

Contributions are welcome for bug fixes, documentation, tests, security hardening, deployment guides, and module improvements.

Start with [CONTRIBUTING.md](CONTRIBUTING.md), open an issue before large changes, and avoid submitting real operational data in test fixtures or screenshots.

## Releases

Releases should be published through GitHub Releases and summarized in [CHANGELOG.md](CHANGELOG.md). Each release should list:

- User-facing changes.
- Migration files added or changed.
- Security notes.
- Upgrade and rollback notes.
- Known limitations.

## Maintainer Notes for Codex for Open Source

This repository has active maintenance history, a clear open-source license, install documentation, issue and PR templates, release guidance, and security reporting instructions. The next strongest public signals are:

- Keep GitHub Issues enabled and label real bugs, feature requests, and documentation tasks.
- Publish GitHub Releases for stable deployment snapshots.
- Invite real users or collaborators to file issues, test releases, or contribute documentation.
- Add anonymized screenshots or a demo dataset only when no private data can leak.

## License

EMS2 is released under the [MIT License](LICENSE).
