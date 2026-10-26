<?php
declare(strict_types=1);

/**
 * The administrator-controlled active flag is the source of truth for access.
 * Resignation fields are history and may remain populated on legacy/reactivated
 * accounts; they must never silently override an explicit active status.
 */
function ems_user_access_is_active(array $user): bool
{
    return (int)($user['is_active'] ?? 0) === 1;
}
