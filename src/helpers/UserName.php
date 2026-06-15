<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

use Craft;
use craft\elements\User;

final class UserName
{
    public static function of(?User $user): ?string
    {
        return $user === null ? null : ($user->fullName ?: $user->username);
    }

    public static function byId(?int $id): ?string
    {
        return $id === null ? null : self::of(Craft::$app->getUsers()->getUserById($id));
    }
}
