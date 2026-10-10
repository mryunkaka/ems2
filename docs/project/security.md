# Security Policy

EMS2 includes workflows for medical operations, employees, applicants, documents, and internal administration. Treat all real deployment data as sensitive.

## Supported Versions

Security fixes are prepared against the latest `main` branch unless a release branch is explicitly maintained.

## Reporting a Vulnerability

Do not open a public issue for vulnerabilities involving authentication bypass, file exposure, injection, private medical data, credentials, or production infrastructure.

Report privately to the primary maintainer:

- GitHub: [@mryunkaka](https://github.com/mryunkaka)
- Repository security advisories: <https://github.com/mryunkaka/ems2/security/advisories>

Include:

- A concise vulnerability summary.
- Affected module or file path.
- Reproduction steps.
- Impact and required privileges.
- Suggested fix, if known.

Please remove real patient, employee, applicant, salary, credential, and medical details from reports.

## Security Expectations

- Never commit `.env`, database dumps, production uploads, generated backups, or secrets.
- Block direct public access to private storage and backup directories.
- Disable PHP execution in upload directories.
- Rotate credentials before deployment and after any suspected exposure.
- Review SQL migrations before applying them to production.
- Prefer least-privilege database users for production.
