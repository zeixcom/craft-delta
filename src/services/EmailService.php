<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\View;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\Review;

/**
 * Composes and sends the review notification emails. Wraps
 * Craft::$app->getMailer() so callers stay short. A failed send never throws —
 * a notification must not abort the committed transition that triggered it.
 */
class EmailService extends Component
{
    /** A draft was submitted for review — notify one requested reviewer. */
    public function sendSubmitted(Review $review, Entry $draft, int $reviewerUserId): void
    {
        $reviewer = Craft::$app->getUsers()->getUserById($reviewerUserId);
        $author = Craft::$app->getUsers()->getUserById($review->submittedBy);
        if (!$reviewer || !$author) {
            return;
        }

        $this->dispatch(
            $reviewer,
            fn() => Craft::t('craft-delta', TranslationKeys::EMAIL_DRAFT_AWAITING_REVIEW, ['title' => $draft->title]),
            'submitted',
            [
                'reviewer' => $reviewer,
                'author' => $author,
                'entry' => $draft,
                'url' => $this->editUrl($draft),
            ],
        );
    }

    /** The author revised and re-requested review — notify one reviewer. */
    public function sendReRequested(Review $review, Entry $draft, int $reviewerUserId): void
    {
        $reviewer = Craft::$app->getUsers()->getUserById($reviewerUserId);
        $author = Craft::$app->getUsers()->getUserById($review->submittedBy);
        if (!$reviewer || !$author) {
            return;
        }

        $this->dispatch(
            $reviewer,
            fn() => Craft::t('craft-delta', TranslationKeys::EMAIL_DRAFT_RESUBMITTED, ['title' => $draft->title]),
            're-requested',
            [
                'reviewer' => $reviewer,
                'author' => $author,
                'entry' => $draft,
                'url' => $this->editUrl($draft),
                'round' => $review->round,
            ],
        );
    }

    /** A reviewer requested changes — notify the author. */
    public function sendChangesRequested(Review $review, Entry $draft, User $author, ?string $note): void
    {
        $this->dispatch(
            $author,
            fn() => Craft::t('craft-delta', TranslationKeys::EMAIL_CHANGES_REQUESTED_ON_DRAFT, ['title' => $draft->title]),
            'changes-requested',
            [
                'author' => $author,
                'entry' => $draft,
                'url' => $this->editUrl($draft),
                'note' => $note,
            ],
        );
    }

    /** A reviewer declined the draft — notify the author. */
    public function sendDeclined(Review $review, Entry $draft, User $author, ?string $note): void
    {
        $this->dispatch(
            $author,
            fn() => Craft::t('craft-delta', TranslationKeys::EMAIL_DRAFT_DECLINED, ['title' => $draft->title]),
            'declined',
            [
                'author' => $author,
                'entry' => $draft,
                'url' => $this->editUrl($draft),
                'note' => $note,
            ],
        );
    }

    /** The draft was approved/published (or scheduled) — notify the author. */
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

    /**
     * Render a template and send it as a plain-text email, in the RECIPIENT's
     * preferred language — not the acting user's request language (a German
     * reviewer declining must not produce a German email to a French author).
     * The subject is a closure so its Craft::t() also runs under the switched
     * language. A failed send (SMTP down, template error) is logged and
     * swallowed — the workflow transition is already committed by now.
     *
     * @param callable(): string $subject
     */
    private function dispatch(User $recipient, callable $subject, string $template, array $vars): void
    {
        $originalLanguage = Craft::$app->language;
        Craft::$app->language = $recipient->preferredLanguage ?? $originalLanguage;

        try {
            Craft::$app->getMailer()->compose()
                ->setTo($recipient->email)
                ->setSubject($subject())
                ->setTextBody($this->render($template, $vars))
                ->send();
        } catch (\Throwable $e) {
            Craft::warning("Craft Delta review notification failed: {$e->getMessage()}", __METHOD__);
        } finally {
            Craft::$app->language = $originalLanguage;
        }
    }

    private function render(string $template, array $vars): string
    {
        $view = Craft::$app->getView();
        return $view->renderTemplate("craft-delta/_emails/{$template}", $vars, View::TEMPLATE_MODE_CP);
    }

    private function editUrl(Entry $draft): string
    {
        // Link to the entry's own edit URL so a submitted draft opens the DRAFT
        // (with its proposed changes), not the live canonical entry. Falls back
        // to the canonical URL if the element can't produce one.
        return $draft->getCpEditUrl() ?? UrlHelper::cpUrl("entries/{$draft->getCanonicalId()}");
    }
}
