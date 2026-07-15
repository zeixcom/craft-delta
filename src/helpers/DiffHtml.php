<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\helpers;

use Craft;
use craft\helpers\HtmlPurifier;
use HTMLPurifier_Config;
use zeixcom\craftdelta\i18n\TranslationKeys;

final class DiffHtml
{
    /**
     * Allowlist for diff markup (Jfcherng tables, del/ins, relation/asset lines).
     * Used at render time before |raw in Twig.
     */
    // tbody[class] is load-bearing: whole added/removed lines are coloured via the
    // change-* class jfcherng puts on <tbody>; drop it and they lose their highlight.
    private const DIFF_HTML_ALLOWED = 'div[class],span[class],del,ins,table[class],thead,tbody[class],tr[class],td[class|rowspan|colspan],th[class],img[class|src|alt|width|height|loading],em[class],p[class],br';

    private const PURIFIER_CONFIG = [
        'HTML.Allowed' => self::DIFF_HTML_ALLOWED,
        'URI.AllowedSchemes' => ['http' => true, 'https' => true],
        'Attr.AllowedFrameTargets' => [],
        'HTML.ForbiddenAttributes' => 'on*',
    ];

    public static function unableToDiffField(): string
    {
        return '<em class="delta-error">' . htmlspecialchars(Craft::t('craft-delta', TranslationKeys::UNABLE_TO_DIFF_FIELD)) . '</em>';
    }

    /** Single-line "old → new" scalar change, both sides escaped. */
    public static function scalarChange(string $old, string $new): string
    {
        return sprintf(
            '<span class="delta-del">%s</span> → <span class="delta-ins">%s</span>',
            htmlspecialchars($old, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($new, ENT_QUOTES, 'UTF-8'),
        );
    }

    /** Added/removed relation membership line, label escaped. */
    public static function relationLine(string $label, bool $added): string
    {
        return sprintf(
            '<div class="%s">%s %s</div>',
            $added ? 'delta-relation-added' : 'delta-relation-removed',
            $added ? '+' : '-',
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
        );
    }

    public static function purifyDiffHtml(string $html): string
    {
        return HtmlPurifier::process($html, static function(HTMLPurifier_Config $config): void {
            foreach (self::PURIFIER_CONFIG as $key => $value) {
                $config->set($key, $value);
            }

            // HTMLPurifier has no built-in definition for the img `loading`
            // attribute, so listing it in HTML.Allowed otherwise raises
            // "Attribute 'loading' in element 'img' not supported", which
            // Craft's dev-mode error handler escalates to a thrown exception.
            if ($def = $config->getHTMLDefinition(true)) {
                $def->addAttribute('img', 'loading', 'Enum#lazy,eager');
            }
        });
    }
}
