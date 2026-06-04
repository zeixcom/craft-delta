<?php

declare(strict_types=1);

namespace zeixcom\craftdelta\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\View;
use zeixcom\craftdelta\models\DraftWorkflow;

/**
 * Composes and sends the three workflow notification emails. Wraps
 * Craft::$app->getMailer() so callers stay short.
 */
class EmailService extends Component
{
    public function sendSubmitted(DraftWorkflow $wf, Entry $draft): void
    {
        if ($wf->assigneeId === null) {
            return;
        }
        $assignee = Craft::$app->getUsers()->getUserById($wf->assigneeId);
        $author = Craft::$app->getUsers()->getUserById($wf->submittedBy);
        if (!$assignee || !$author) {
            return;
        }

        $this->dispatch(
            $assignee->email,
            Craft::t('craft-delta', 'Draft awaiting your review: {title}', ['title' => $draft->title]),
            'submitted',
            [
                'assignee' => $assignee,
                'author' => $author,
                'entry' => $draft,
                'url' => $this->editUrl($draft),
            ],
        );
    }

    public function sendApproved(DraftWorkflow $wf, Entry $draft): void
    {
        [$author, $reviewer] = $this->resolveAuthorAndReviewer($wf);
        if (!$author || !$reviewer) {
            return;
        }

        $this->dispatch(
            $author->email,
            Craft::t('craft-delta', 'Your draft was approved: {title}', ['title' => $draft->title]),
            'approved',
            [
                'author' => $author,
                'reviewer' => $reviewer,
                'entry' => $draft,
                'url' => $this->editUrl($draft),
                'scheduledFor' => $wf->scheduledFor,
            ],
        );
    }

    public function sendRejected(DraftWorkflow $wf, Entry $draft): void
    {
        [$author, $reviewer] = $this->resolveAuthorAndReviewer($wf);
        if (!$author || !$reviewer) {
            return;
        }

        $this->dispatch(
            $author->email,
            Craft::t('craft-delta', 'Your draft was rejected: {title}', ['title' => $draft->title]),
            'rejected',
            [
                'author' => $author,
                'reviewer' => $reviewer,
                'entry' => $draft,
                'url' => $this->editUrl($draft),
                'note' => $wf->rejectNote,
            ],
        );
    }

    /**
     * The submitting author and the deciding reviewer, used by the approve/reject
     * notifications. Either may be null if the user no longer exists.
     *
     * @return array{0: \craft\elements\User|null, 1: \craft\elements\User|null}
     */
    private function resolveAuthorAndReviewer(DraftWorkflow $wf): array
    {
        $author = Craft::$app->getUsers()->getUserById($wf->submittedBy);
        $reviewer = $wf->decidedBy ? Craft::$app->getUsers()->getUserById($wf->decidedBy) : null;
        return [$author, $reviewer];
    }

    /**
     * Render a template and send it as a plain-text email. Single point where
     * the mailer chain lives, so recipient/subject/body handling stays uniform.
     */
    private function dispatch(string $to, string $subject, string $template, array $vars): void
    {
        Craft::$app->getMailer()->compose()
            ->setTo($to)
            ->setSubject($subject)
            ->setTextBody($this->render($template, $vars))
            ->send();
    }

    private function render(string $template, array $vars): string
    {
        $view = Craft::$app->getView();
        return $view->renderTemplate("craft-delta/_emails/{$template}", $vars, View::TEMPLATE_MODE_CP);
    }

    private function editUrl(Entry $draft): string
    {
        return UrlHelper::cpUrl("entries/{$draft->getCanonicalId()}");
    }
}
