<?php

declare(strict_types=1);

/**
 * Shared safety guard for craft-delta smoke scripts.
 *
 * These scripts MUTATE real content — publishing revisions, seeding Matrix
 * blocks, creating users — and have NO teardown (a published revision is
 * immutable history that can't be cleanly rolled back). They are developer
 * tooling meant for a disposable dev/staging database, never production.
 *
 * Each smoke script `require`s this guard before doing any work.
 */

$env = \Craft::$app->env ?? (getenv('CRAFT_ENVIRONMENT') ?: null);

if ($env === 'production' && getenv('CRAFT_DELTA_SMOKE_ALLOW_PROD') !== '1') {
    fwrite(STDERR, "REFUSING: craft-delta smoke scripts mutate real content and must not run in the 'production' environment.\n");
    fwrite(STDERR, "If this really is a disposable database, set CRAFT_DELTA_SMOKE_ALLOW_PROD=1 to override.\n");
    exit(1);
}
