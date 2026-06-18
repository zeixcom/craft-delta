<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\events;

use yii\base\Event;
use zeixcom\craftdelta\models\Review;

/**
 * Fired by WorkflowService when state transitions occur. Listeners can
 * integrate with Slack, audit logs, etc.
 */
class WorkflowEvent extends Event
{
    public Review $review;
}
