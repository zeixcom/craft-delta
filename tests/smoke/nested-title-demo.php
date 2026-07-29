<?php
/**
 * Demo fixture for #15 — leaves a draft behind to look at in the CP.
 *
 * Creates a draft off an entry that has a Matrix field with a title-bearing
 * block type, and changes, in ONE block, both the native Title and a custom
 * sub-field — so the block-diff lists the two next to each other. Nothing is
 * merged: the draft stays around for as long as you want to look at it.
 *
 * Usage from the Craft web root:
 *
 *     ddev craft craft-delta/smoke/nested-title-demo
 *
 * Override the owner entry with CRAFT_DELTA_SMOKE_ENTRY_ID.
 */

declare(strict_types=1);

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Matrix;

require __DIR__ . '/_guard.php';

$entryId = (int)(getenv('CRAFT_DELTA_SMOKE_ENTRY_ID') ?: 2);

$adminUser = User::find()->admin(true)->status(null)->one();
if (!$adminUser instanceof User) {
    fwrite(STDERR, "FAIL: no admin user found\n");
    exit(1);
}
Craft::$app->getUser()->setIdentity($adminUser);

$canonical = Entry::find()->id($entryId)->status(null)->one();
if (!$canonical instanceof Entry) {
    fwrite(STDERR, "FAIL: canonical entry {$entryId} not found\n");
    exit(2);
}

$draft = Craft::$app->getDrafts()->createDraft($canonical, $adminUser->id, '#15 demo — Titel + Feld');
if (!$draft instanceof Entry) {
    fwrite(STDERR, "FAIL: createDraft failed\n");
    exit(3);
}

/** First sub-field holding text, so an edit to it is visible in the diff. */
$textSubField = static function (Entry $block): ?string {
    $serialized = $block->getSerializedFieldValues();
    foreach ($block->getFieldLayout()?->getCustomFields() ?? [] as $field) {
        if (is_string($serialized[$field->handle] ?? null) && trim($serialized[$field->handle]) !== '') {
            return $field->handle;
        }
    }
    return null;
};

// A Matrix block whose entry type shows the Title field — preferring one that
// also has text content, so the demo can change a title AND a field at once.
$matrixFieldHandle = null;
$targetBlock = null;
$targetFieldHandle = null;
foreach ($draft->getFieldLayout()->getCustomFields() as $field) {
    if (!$field instanceof Matrix) {
        continue;
    }
    foreach ($draft->getFieldValue($field->handle)->all() as $block) {
        if (!$block->type->hasTitleField) {
            continue;
        }
        $handle = $textSubField($block);
        if ($handle !== null) {
            [$matrixFieldHandle, $targetBlock, $targetFieldHandle] = [$field->handle, $block, $handle];
            break 2;
        }
        // remember the first title-only block as a fallback
        $matrixFieldHandle ??= $field->handle;
        $targetBlock ??= $block;
    }
}
if ($matrixFieldHandle === null || $targetBlock === null) {
    fwrite(STDERR, "FAIL: entry {$entryId} has no Matrix block whose entry type shows the Title field\n");
    exit(2);
}

$stamp = date('H:i:s');
$newTitle = 'Titel geändert um ' . $stamp;
$payload = [];
$sortOrder = [];
foreach ($draft->getFieldValue($matrixFieldHandle)->all() as $block) {
    $sortOrder[] = $block->uid;
    $isTarget = $block->canonicalUid === $targetBlock->canonicalUid;
    $fields = $block->getSerializedFieldValues();
    if ($isTarget && $targetFieldHandle !== null) {
        $fields[$targetFieldHandle] = $fields[$targetFieldHandle] . ' — Feld ebenfalls geändert um ' . $stamp;
    }
    $payload['uid:' . $block->uid] = [
        'type' => $block->type->handle,
        'title' => $isTarget ? $newTitle : $block->title,
        'fields' => $fields,
    ];
}
$draft->setFieldValue($matrixFieldHandle, ['entries' => $payload, 'sortOrder' => $sortOrder]);

if (!Craft::$app->getElements()->saveElement($draft)) {
    fwrite(STDERR, "FAIL: saveElement on draft returned false: " . json_encode($draft->getErrors()) . "\n");
    exit(3);
}

$cp = rtrim(Craft::$app->getConfig()->general->cpTrigger ?? 'admin', '/');

echo "── #15 demo draft created ──\n";
echo "Entry:        \"{$canonical->title}\" (id={$canonical->id})\n";
echo "Matrix field: {$matrixFieldHandle}\n";
echo "Block:        {$targetBlock->type->name} ({$targetBlock->canonicalUid})\n";
echo "  Titel:      \"{$targetBlock->title}\" → \"{$newTitle}\"\n";
echo $targetFieldHandle !== null
    ? "  Feld:       {$targetFieldHandle} — Marker angehängt\n"
    : "  Feld:       (kein Textfeld auf diesem Blocktyp — nur der Titel wurde geändert)\n";
echo "Draft:        \"{$draft->draftName}\" (draftId={$draft->draftId})\n\n";
echo "Ansehen:      /{$cp}/delta-compare?entryId={$canonical->id}\n";
echo "              → links \"Aktuell\", rechts \"{$draft->draftName}\"\n";
