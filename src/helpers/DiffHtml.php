<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

use Craft;
use zeixcom\craftdelta\i18n\TranslationKeys;

/**
 * Shared diff markup fragments.
 */
final class DiffHtml
{
    public static function unableToDiffField(): string
    {
        return '<em class="delta-error">' . htmlspecialchars(Craft::t('craft-delta', TranslationKeys::UNABLE_TO_DIFF_FIELD)) . '</em>';
    }
}
