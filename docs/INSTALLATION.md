# Installation Guide

This guide explains how to install EMS2 for local development or a small self-hosted deployment.

## Prerequisites

- PHP 8.1 or newer with common extensions enabled: `mysqli`, `mbstring`, `zip`, `gd`, `fileinfo`, `openssl`, and `curl`.
- MariaDB 10.6+ or MySQL 8+.
- Composer 2.x.
- Node.js 20+ and npm.
- Apache or Nginx with PHP-FPM.

## 1. Clone and Install Dependencies

```bash
git clone https://github.com/mryunkaka/ems2.git
cd ems2
composer install
npm install
```

## 2. Configure Environment

```bash
cp .env.example .env
```

Update the database values:

```env
APP_ENV=local
DB_HOST=127.0.0.1
DB_NAME=ems2_local
DB_USER=ems2
DB_PASS=change_me
DB_TIMEZONE=+07:00
```

Firebase settings are optional unless you use realtime chat, live music, or presence features.

## 3. Prepare Database

Create a blank database:

```sql
CREATE DATABASE ems2_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ems2'@'localhost' IDENTIFIED BY 'change_me';
GRANT ALL PRIVILEGES ON ems2_local.* TO 'ems2'@'localhost';
FLUSH PRIVILEGES;
```

Apply SQL files from `docs/sql/` in chronological order. Review each file first, especially before using it against a non-empty database.

## 4. Build Assets

```bash
npm run build:css
```

For development, keep Tailwind running:

```bash
npm run watch:css
```

## 5. Run Locally

```bash
php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000>.

## 6. Configure Web Server

Recommended production setup:

- Serve the application through Apache or Nginx with PHP-FPM.
- Keep `.env`, `storage/`, `backup/`, `vendor/`, and private artifacts out of public web access.
- Disable script execution in upload directories.
- Use HTTPS.
- Rotate production credentials before public deployment.

Example hardening snippets are available in `docs/deploy/`.

## 7. Cron Jobs

Cron scripts live in `cron/`. Enable only the jobs needed by your deployment and run them with the same PHP version used by the web application.

Before enabling cron jobs:

- Verify `.env` database access.
- Confirm logs are writable.
- Run each job manually in a staging environment.

## 8. Upgrade Process

1. Back up the database and private uploads.
2. Pull the new release tag.
3. Run `composer install --no-dev --optimize-autoloader`.
4. Run `npm ci` and `npm run build:css` if frontend assets changed.
5. Apply new SQL migrations from `docs/sql/`.
6. Clear PHP opcode cache if enabled.
7. Verify login, dashboards, upload previews, exports, and cron jobs.
