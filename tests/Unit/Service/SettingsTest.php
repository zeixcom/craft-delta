<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use zeixcom\craftdelta\models\Settings;

class SettingsTest extends TestCase
{
    public function testEnableWorkflowDefaultsTrue(): void
    {
        $settings = new Settings();
        $this->assertTrue($settings->enableWorkflow);
    }

    public function testEnableWorkflowCanBeDisabled(): void
    {
        $settings = new Settings(['enableWorkflow' => false]);
        $this->assertFalse($settings->enableWorkflow);
    }
}
