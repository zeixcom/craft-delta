<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use zeixcom\craftdelta\differ\DifferInterface;
use zeixcom\craftdelta\differ\HtmlDiffer;
use zeixcom\craftdelta\differ\MatrixDiffer;
use zeixcom\craftdelta\differ\OptionDiffer;
use zeixcom\craftdelta\differ\RelationDiffer;
use zeixcom\craftdelta\differ\ScalarDiffer;
use zeixcom\craftdelta\differ\TableDiffer;
use zeixcom\craftdelta\differ\TextDiffer;
use zeixcom\craftdelta\events\RegisterDiffersEvent;
use zeixcom\craftdelta\models\FieldDiff;

/**
 * Resolves the appropriate differ for each field type and produces diffs.
 */
class FieldDiffService extends Component
{
    public const EVENT_REGISTER_DIFFERS = 'registerDiffers';

    /**
     * Built-in map of field class to differ class.
     *
     * @var array<class-string, class-string>
     */
    private array $differMap = [
        \craft\fields\PlainText::class => TextDiffer::class,
        \craft\fields\Email::class => ScalarDiffer::class,
        \craft\fields\Url::class => ScalarDiffer::class,
        \craft\ckeditor\Field::class => HtmlDiffer::class,
        \craft\fields\Matrix::class => MatrixDiffer::class,
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

    /**
     * Cached differ instances.
     *
     * @var array<class-string, DifferInterface>
     */
    private array $differInstances = [];

    private bool $differsRegistered = false;

    /**
     * Diff a single field's values and return a FieldDiff (or null if unchanged).
     */
    public function diff(FieldInterface $field, mixed $oldValue, mixed $newValue): ?FieldDiff
    {
        $differ = $this->resolveDiffer($field);
        /** @var \zeixcom\craftdelta\models\Settings|null $settings */
        $settings = \zeixcom\craftdelta\Delta::getInstance()?->getSettings();
        if ($settings !== null) {
            $maxLen = $settings->maxFieldLength;
            $oldLen = is_string($oldValue) ? mb_strlen($oldValue) : 0;
            $newLen = is_string($newValue) ? mb_strlen($newValue) : 0;
            if ($oldLen > $maxLen || $newLen > $maxLen) {
                return new FieldDiff([
                    'fieldHandle' => $field->handle,
                    'fieldLabel' => $field->name,
                    'fieldType' => get_class($field),
                    'hasChanges' => true,
                    'diffHtml' => htmlspecialchars(Craft::t('craft-delta', 'Field content too large to diff ({length} chars).', [
                        'length' => max($oldLen, $newLen),
                    ])),
                    'stats' => ['additions' => 1, 'deletions' => 1],
                ]);
            }
        }

        try {
            $diffHtml = $differ->diff($oldValue, $newValue);
        } catch (\Exception $e) {
            Craft::warning("Differ threw for field '{$field->handle}': {$e->getMessage()}", __METHOD__);

            return new FieldDiff([
                'fieldHandle' => $field->handle,
                'fieldLabel' => $field->name,
                'fieldType' => get_class($field),
                'hasChanges' => true,
                'diffHtml' => '<em class="delta-error">' . htmlspecialchars(Craft::t('craft-delta', 'Unable to diff this field.')) . '</em>',
                'stats' => ['additions' => 0, 'deletions' => 0],
            ]);
        }

        if ($diffHtml === null) {
            return null;
        }

        if ($field instanceof \craft\fields\Table && $diffHtml !== null) {
            $columns = [];
            foreach ($field->columns as $key => $col) {
                $columns[$key] = $col['heading'] ?? $key;
            }
            $decoded = json_decode($diffHtml, true);
            if (is_array($decoded)) {
                $diffHtml = json_encode([
                    'columns' => $columns,
                    'changes' => $decoded,
                ], JSON_THROW_ON_ERROR);
            }
        }

        return new FieldDiff([
            'fieldHandle' => $field->handle,
            'fieldLabel' => $field->name,
            'fieldType' => get_class($field),
            'hasChanges' => true,
            'diffHtml' => $diffHtml,
            'stats' => $differ->getStats($oldValue, $newValue),
        ]);
    }

    /**
     * Get the TextDiffer instance (used by DiffService for attribute diffs).
     */
    public function getTextDiffer(): TextDiffer
    {
        if (!isset($this->differInstances[TextDiffer::class])) {
            $this->differInstances[TextDiffer::class] = $this->createDiffer(TextDiffer::class);
        }

        /** @var TextDiffer */
        return $this->differInstances[TextDiffer::class];
    }

    /**
     * Resolve the appropriate differ for a field.
     */
    private function resolveDiffer(FieldInterface $field): DifferInterface
    {
        $this->registerThirdPartyDiffers();

        $fieldClass = get_class($field);
        $differClass = $this->differMap[$fieldClass] ?? null;

        if ($differClass === null) {
            Craft::info("No differ registered for field type: {$fieldClass}, falling back to ScalarDiffer.", __METHOD__);
            $differClass = ScalarDiffer::class;
        }

        if (!isset($this->differInstances[$differClass])) {
            $this->differInstances[$differClass] = $this->createDiffer($differClass);
        }

        return $this->differInstances[$differClass];
    }

    /**
     * Create a differ instance, passing settings where applicable.
     */
    private function createDiffer(string $differClass): DifferInterface
    {
        /** @var \zeixcom\craftdelta\models\Settings|null $settings */
        $settings = \zeixcom\craftdelta\Delta::getInstance()?->getSettings();
        $context = $settings?->diffContext ?? 3;

        if ($differClass === TextDiffer::class) {
            return new TextDiffer($context);
        }

        if ($differClass === HtmlDiffer::class) {
            return new HtmlDiffer($context);
        }

        return new $differClass();
    }

    /**
     * Fire the event to allow third-party plugins to register differs.
     */
    private function registerThirdPartyDiffers(): void
    {
        if ($this->differsRegistered) {
            return;
        }

        $this->differsRegistered = true;

        $event = new RegisterDiffersEvent([
            'differs' => $this->differMap,
        ]);

        $this->trigger(self::EVENT_REGISTER_DIFFERS, $event);

        $this->differMap = $event->differs;
    }
}
