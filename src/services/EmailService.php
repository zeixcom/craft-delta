<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\View;
use zeixcom\craftdelta\Delta;
use zeixcom\craftdelta\helpers\PlainText;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\Review;

/**
 * Composes and sends the review notification emails. A failed send never
 * throws — a notification must not abort the committed transition that
 * triggered it.
 *
 * @phpstan-import-type EmailDispatchVars from \zeixcom\craftdelta\types\ArrayTypes
 * @phpstan-import-type EmailExtraVars from \zeixcom\craftdelta\types\ArrayTypes
 */
class EmailService extends Component
{
    public function sendSubmitted(Review $review, Entry $draft, int $reviewerUserId): void
    {
        $this->notifyReviewer($review, $draft, $reviewerUserId, 'submitted', fn() => Craft::t('craft-delta', TranslationKeys::EMAIL_DRAFT_AWAITING_REVIEW, ['title' => $draft->title]));
    }

    public function sendApproved(Review $review, Entry $draft, User $author): void
    {
        $this->notifyAuthor($review, $author, $draft, 'approved', TranslationKeys::EMAIL_DRAFT_APPROVED, null);
    }

    public function sendDeclined(Review $review, Entry $draft, User $author, ?string $note): void
    {
        $this->notifyAuthor($review, $author, $draft, 'declined', TranslationKeys::EMAIL_DRAFT_DECLINED, $note);
    }

    /**
     * Notify a participant (the author, or a reviewer) that someone else left a
     * comment on the review. `author` in the vars is the greeting recipient.
     */
    public function sendCommentNotification(Review $review, Entry $entry, User $recipient, string $commenter, string $comment): void
    {
        $this->dispatch(
            $recipient,
            fn() => Craft::t('craft-delta', TranslationKeys::EMAIL_NEW_COMMENT, ['title' => $entry->title]),
            'comment',
            [
                'author' => $recipient,
                'entry' => $entry,
                'url' => $this->reviewUrl($review),
                'commenter' => $commenter,
                'comment' => $comment,
            ],
        );
    }

    public function sendPublished(Review $review, Entry $draft, User $author): void
    {
        $this->dispatch(
            $author,
            fn() => $review->isScheduled()
                ? Craft::t('craft-delta', TranslationKeys::EMAIL_DRAFT_APPROVED_SCHEDULED, ['title' => $draft->title])
                : Craft::t('craft-delta', TranslationKeys::EMAIL_DRAFT_PUBLISHED, ['title' => $draft->title]),
            'published',
            [
                'author' => $author,
                'entry' => $draft,
                'url' => $this->editUrl($draft),
                'scheduledFor' => $review->scheduledFor,
            ],
        );
    }

    /** @param EmailExtraVars $extraVars */
    private function notifyReviewer(Review $review, Entry $draft, int $reviewerUserId, string $template, callable $subject, array $extraVars = []): void
    {
        $reviewer = Craft::$app->getUsers()->getUserById($reviewerUserId);
        $author = Craft::$app->getUsers()->getUserById($review->submittedBy);
        if (!$reviewer || !$author) {
            return;
        }

        $this->dispatch($reviewer, $subject, $template, [
            'reviewer' => $reviewer,
            'author' => $author,
            'entry' => $draft,
            'url' => $this->reviewUrl($review),
            ...$extraVars,
        ]);
    }

    private function notifyAuthor(Review $review, User $author, Entry $draft, string $template, string $subjectKey, ?string $note): void
    {
        $this->dispatch(
            $author,
            fn() => Craft::t('craft-delta', $subjectKey, ['title' => $draft->title]),
            $template,
            [
                'author' => $author,
                'entry' => $draft,
                'url' => $this->reviewUrl($review),
                'note' => PlainText::normalize($note),
            ],
        );
    }

    /**
     * Render a template and send it as a plain-text email, in the RECIPIENT's
     * preferred language — not the acting user's request language (a German
     * reviewer declining must not produce a German email to a French author).
     * The subject is a closure so its Craft::t() also runs under the switched
     * language. A failed send (SMTP down, template error) is logged and
     * swallowed — the workflow transition is already committed by now.
     *
     * @param callable(): string $subject
     * @param EmailDispatchVars $vars
     */
    private function dispatch(User $recipient, callable $subject, string $template, array $vars): void
    {
        $originalLanguage = Craft::$app->language;
        Craft::$app->language = $recipient->preferredLanguage ?? $originalLanguage;

        try {
            [$path, $mode] = $this->resolveTemplate($template);
            Craft::$app->getMailer()->compose()
                ->setTo($recipient->email)
                ->setSubject($subject())
                ->setTextBody(Craft::$app->getView()->renderTemplate($path, $vars, $mode))
                ->send();
        } catch (\Throwable $e) {
            Craft::warning("Craft Delta review notification failed: {$e->getMessage()}", __METHOD__);
        } finally {
            Craft::$app->language = $originalLanguage;
        }
    }

    /**
     * A configured `emailTemplates` override (a SITE template) when set and it
     * exists, else the bundled CP default. A configured-but-missing override
     * falls back rather than erroring the send.
     *
     * @return array{0: string, 1: string} [template path, template mode]
     */
    private function resolveTemplate(string $template): array
    {
        $plugin = Delta::getInstance();
        $override = $plugin !== null ? ($plugin->getSettings()->emailTemplates[$template] ?? null) : null;

        if (is_string($override) && $override !== '' && Craft::$app->getView()->doesTemplateExist($override, View::TEMPLATE_MODE_SITE)) {
            return [$override, View::TEMPLATE_MODE_SITE];
        }

        return ["craft-delta/_emails/{$template}", View::TEMPLATE_MODE_CP];
    }

    private function editUrl(Entry $draft): string
    {
        // Link to the entry's own edit URL so a submitted draft opens the DRAFT
        // (with its proposed changes), not the live canonical entry.
        return $draft->getCpEditUrl() ?? UrlHelper::cpUrl("entries/{$draft->getCanonicalId()}");
    }

    private function reviewUrl(Review $review): string
    {
        return UrlHelper::cpUrl('delta-review', ['reviewId' => $review->id]);
    }
}
