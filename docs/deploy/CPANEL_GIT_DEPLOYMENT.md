# cPanel Git Deployment

The cPanel-managed checkout is `/home/fouf9972/public_html/rh_ems`. The
production application directory is `/home/fouf9972/public_html/roxwoodhospitalime`,
matching the existing server deployment scripts.

The root `.cpanel.yml` deploys the checked-out `HEAD` using `git archive`, so
only files tracked by Git are copied. It excludes deployment metadata and
`.env` files. Existing `storage/`, `vendor/`, uploads, and other runtime files
in the production directory are left intact; deployment does not delete files.
The production `.htaccess` denies public access to `.cpanel.yml` as an added
precaution.

This cPanel repository uses pull deployment. After a commit is pushed to
GitHub, open **Git Version Control → Manage → Pull or Deploy**, choose **Update
from Remote**, then **Deploy HEAD Commit**. Confirm the deployed HEAD commit in
cPanel before testing the site. This operation copies PHP source only; apply
any new SQL migration separately according to the release checklist.
