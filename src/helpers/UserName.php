<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

use craft\elements\User;

/**
 * Consistent display names for CP payloads and models.
 */
final class UserName
{
    public static function of(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        return $user->fullName ?: $user->username;
    }
}
