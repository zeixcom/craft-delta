<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\console\controllers;

use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Smoke-test runner for craft-delta. Not part of the user-facing plugin —
 * a developer convenience for running end-to-end checks against a real
 * Craft install when PHPUnit kernel boot isn't wired up.
 *
 * Invoke via: `ddev craft craft-delta/smoke/matrix-apply`
 */
class SmokeController extends Controller
{
    /**
     * Runs the Matrix-apply end-to-end smoke test.
     */
    public function actionMatrixApply(): int
    {
        $script = dirname(__DIR__, 3) . '/tests/smoke/matrix-apply-smoke.php';
        if (!is_file($script)) {
            $this->stderr("Script not found: $script\n");
            return ExitCode::IOERR;
        }

        require $script;

        // matrix-apply-smoke.php calls exit() on failure; if we get here it passed.
        return ExitCode::OK;
    }
}
