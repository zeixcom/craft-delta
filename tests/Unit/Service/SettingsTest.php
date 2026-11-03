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

    public function testDefaultReviewerIdNormalisesElementSelectPost(): void
    {
        $settings = new Settings();
        $settings->setAttributes(['defaultReviewerId' => ['12']], false);
        $this->assertSame(12, $settings->defaultReviewerId);

        $settings->setAttributes(['defaultReviewerId' => ''], false);
        $this->assertNull($settings->defaultReviewerId);

        $settings->setAttributes(['defaultReviewerId' => 7], false);
        $this->assertSame(7, $settings->defaultReviewerId);
    }
}
