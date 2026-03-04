<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\events;

use yii\base\Event;

/**
 * Event for registering third-party field differs.
 *
 * Usage:
 *   Event::on(
 *       FieldDiffService::class,
 *       FieldDiffService::EVENT_REGISTER_DIFFERS,
 *       function (RegisterDiffersEvent $event) {
 *           $event->differs[\myvendor\fields\CustomField::class] = MyCustomDiffer::class;
 *       }
 *   );
 */
class RegisterDiffersEvent extends Event
{
    /**
     * Map of field class => differ class.
     *
     * @var array<class-string, class-string>
     */
    public array $differs = [];
}
