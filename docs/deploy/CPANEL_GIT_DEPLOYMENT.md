# cPanel Git Deployment

The cPanel-managed checkout and production application directory are both
`/home/fouf9972/public_html/rh_ems`.

The root `.cpanel.yml` materializes the checked-out `HEAD` in the production
directory using `git archive`, so only files tracked by Git are copied. It
excludes deployment metadata and `.env`. Existing `.git/`, `storage/`,
`vendor/`, uploads, and other runtime files remain intact; deployment does
not delete files. The production `.htaccess` denies public access to
`.cpanel.yml` as an added precaution.

This cPanel repository uses pull deployment. After a commit is pushed to
GitHub, open **Git Version Control → Manage → Pull or Deploy**, choose **Update
from Remote**, then **Deploy HEAD Commit**. Confirm the deployed HEAD commit in
cPanel before testing the site. This operation copies PHP source only; apply
any new SQL migration separately according to the release checklist.
