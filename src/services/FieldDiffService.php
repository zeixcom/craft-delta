<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\fields\Table;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\differ\DifferInterface;
use zeixcom\craftdelta\differ\HtmlDiffer;
use zeixcom\craftdelta\differ\MatrixDiffer;
use zeixcom\craftdelta\differ\NeoDiffer;
use zeixcom\craftdelta\differ\NestedFieldDiffInterface;
use zeixcom\craftdelta\differ\OptionDiffer;
use zeixcom\craftdelta\differ\RelationDiffer;
use zeixcom\craftdelta\differ\ScalarDiffer;
use zeixcom\craftdelta\differ\TableDiffer;
use zeixcom\craftdelta\differ\TextDiffer;
use zeixcom\craftdelta\events\RegisterDiffersEvent;
use zeixcom\craftdelta\helpers\DiffHtml;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\FieldDiff;
use zeixcom\craftdelta\models\Settings;

class FieldDiffService extends Component implements NestedFieldDiffInterface
{
    public const EVENT_REGISTER_DIFFERS = 'registerDiffers';

    /** @var array<class-string, class-string> */
    private array $differMap = [
        \craft\fields\PlainText::class => TextDiffer::class,
        \craft\fields\Email::class => ScalarDiffer::class,
        \craft\fields\Url::class => ScalarDiffer::class,
        \craft\ckeditor\Field::class => HtmlDiffer::class,
        // Third-party rich-text / code fields (optional plugins; keys are compile-time strings, inert when uninstalled)
        \spicyweb\tinymce\fields\TinyMCE::class => HtmlDiffer::class,
        \nystudio107\codefield\fields\Code::class => TextDiffer::class,
        \craft\fields\Matrix::class => MatrixDiffer::class,
        \benf\neo\Field::class => NeoDiffer::class,
        \craft\fields\Table::class => TableDiffer::class,
        \craft\fields\Entries::class => RelationDiffer::class,
        \craft\fields\Assets::class => RelationDiffer::class,
        \craft\fields\Categories::class => RelationDiffer::class,
        \craft\fields\Tags::class => RelationDiffer::class,
        \craft\fields\Users::class => RelationDiffer::class,
        \craft\fields\Dropdown::class => OptionDiffer::class,
        \craft\fields\RadioButtons::class => OptionDiffer::class,
        \craft\fields\Checkboxes::class => OptionDiffer::class,
        \craft\fields\MultiSelect::class => OptionDiffer::class,
        \craft\fields\ButtonGroup::class => OptionDiffer::class,
        \craft\fields\Number::class => ScalarDiffer::class,
        \craft\fields\Date::class => ScalarDiffer::class,
        \craft\fields\Lightswitch::class => ScalarDiffer::class,
        \craft\fields\Color::class => ScalarDiffer::class,
        \craft\fields\Money::class => ScalarDiffer::class,
        \craft\fields\Country::class => ScalarDiffer::class,
        \craft\fields\Time::class => ScalarDiffer::class,
        \craft\fields\Link::class => ScalarDiffer::class,
        \craft\fields\Icon::class => ScalarDiffer::class,
        \craft\fields\Range::class => ScalarDiffer::class,
        \craft\fields\Json::class => ScalarDiffer::class,
    ];

    /** @var array<class-string, DifferInterface> */
    private array $differInstances = [];

    private bool $differsRegistered = false;

    public function diff(FieldInterface $field, mixed $oldValue, mixed $newValue): ?FieldDiff
    {
        $differ = $this->resolveDiffer($field);

        $settings = Delta::getInstance()?->getSettings();
        if ($settings instanceof Settings) {
            $maxLen = $settings->maxFieldLength;
            $oldLen = is_string($oldValue) ? mb_strlen($oldValue) : 0;
            $newLen = is_string($newValue) ? mb_strlen($newValue) : 0;
            if ($oldLen > $maxLen || $newLen > $maxLen) {
                return FieldDiff::make(
                    $field,
                    true,
                    htmlspecialchars(Craft::t('craft-delta', TranslationKeys::FIELD_TOO_LARGE, ['length' => max($oldLen, $newLen)])),
                    ['additions' => 1, 'deletions' => 1],
                );
            }
        }

        try {
            $diffHtml = $differ->diff($oldValue, $newValue);
            if ($diffHtml === null) {
                return null;
            }

            if ($field instanceof Table) {
                $decoded = json_decode($diffHtml, true);
                if (is_array($decoded)) {
                    $columns = [];
                    foreach ($field->columns as $key => $col) {
                        $columns[$key] = $col['heading'] ?? $key;
                    }
                    $diffHtml = json_encode(['columns' => $columns, 'changes' => $decoded], JSON_THROW_ON_ERROR);
                }
            }

            return FieldDiff::make($field, true, $diffHtml, $differ->getStats($oldValue, $newValue));
        } catch (\Throwable $e) {
            Craft::error("Differ threw for field '{$field->handle}': {$e}", __METHOD__);
            return FieldDiff::make($field, true, DiffHtml::unableToDiffField(), ['additions' => 0, 'deletions' => 0]);
        }
    }

    public function getTextDiffer(): TextDiffer
    {
        /** @var TextDiffer $differ */
        $differ = $this->differInstances[TextDiffer::class]
            ??= $this->createDiffer(TextDiffer::class);
        return $differ;
    }

    private function resolveDiffer(FieldInterface $field): DifferInterface
    {
        $this->registerThirdPartyDiffers();
        $differClass = $this->differMap[$field::class] ?? ScalarDiffer::class;
        if (!isset($this->differMap[$field::class])) {
            Craft::info("No differ registered for field type: " . $field::class . ", falling back to ScalarDiffer.", __METHOD__);
        }
        return $this->differInstances[$differClass] ??= $this->createDiffer($differClass);
    }

    private function createDiffer(string $differClass): DifferInterface
    {
        $context = Delta::getInstance()?->getSettings()?->diffContext ?? 3;
        return match ($differClass) {
            TextDiffer::class => new TextDiffer($context),
            HtmlDiffer::class => new HtmlDiffer($context),
            MatrixDiffer::class => new MatrixDiffer($this),
            NeoDiffer::class => new NeoDiffer($this),
            default => new $differClass(),
        };
    }

    private function registerThirdPartyDiffers(): void
    {
        if ($this->differsRegistered) {
            return;
        }
        $this->differsRegistered = true;
        $event = new RegisterDiffersEvent(['differs' => $this->differMap]);
        $this->trigger(self::EVENT_REGISTER_DIFFERS, $event);
        $this->differMap = $event->differs;
    }
}
