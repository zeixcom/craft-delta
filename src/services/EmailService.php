<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\web\View;
use zeixcom\craftdelta\helpers\PlainText;
use zeixcom\craftdelta\i18n\TranslationKeys;
use zeixcom\craftdelta\models\Review;

/**
 * Composes and sends the review notification emails. Wraps
 * Craft::$app->getMailer() so callers stay short. A failed send never throws —
 * a notification must not abort the committed transition that triggered it.
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

    public function sendReRequested(Review $review, Entry $draft, int $reviewerUserId): void
    {
        $this->notifyReviewer(
            $review,
            $draft,
            $reviewerUserId,
            're-requested',
            fn() => Craft::t('craft-delta', TranslationKeys::EMAIL_DRAFT_RESUBMITTED, ['title' => $draft->title]),
            ['round' => $review->round],
        );
    }

    public function sendChangesRequested(Review $review, Entry $draft, User $author, ?string $note): void
    {
        $this->notifyAuthor($review, $author, $draft, 'changes-requested', TranslationKeys::EMAIL_CHANGES_REQUESTED_ON_DRAFT, $note);
    }

    public function sendDeclined(Review $review, Entry $draft, User $author, ?string $note): void
    {
        $this->notifyAuthor($review, $author, $draft, 'declined', TranslationKeys::EMAIL_DRAFT_DECLINED, $note);
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
            Craft::$app->getMailer()->compose()
                ->setTo($recipient->email)
                ->setSubject($subject())
                ->setTextBody(Craft::$app->getView()->renderTemplate("craft-delta/_emails/{$template}", $vars, View::TEMPLATE_MODE_CP))
                ->send();
        } catch (\Throwable $e) {
            Craft::warning("Craft Delta review notification failed: {$e->getMessage()}", __METHOD__);
        } finally {
            Craft::$app->language = $originalLanguage;
        }
    }

    private function editUrl(Entry $draft): string
    {
        // Link to the entry's own edit URL so a submitted draft opens the DRAFT
        // (with its proposed changes), not the live canonical entry.
        return $draft->getCpEditUrl() ?? UrlHelper::cpUrl("entries/{$draft->getCanonicalId()}");
    }

    /** The dedicated review workspace — the actionable destination for reviewers and authors. */
    private function reviewUrl(Review $review): string
    {
        return UrlHelper::cpUrl('delta-review', ['reviewId' => $review->id]);
    }
}
