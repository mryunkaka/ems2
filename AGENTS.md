# Project access invariant

Programmer Roxwood has full dashboard access, independent of the account's division. Identify the account with `ems_current_user_is_programmer_roxwood()` (which normalizes an optional `dr.` prefix); do not hide authorized pages from this account or reject it in page/action guards. When changing a page's access, check both its server-side guard and sidebar visibility.
