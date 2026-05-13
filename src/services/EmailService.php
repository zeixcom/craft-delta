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

        $body = $this->render('submitted', [
            'assignee' => $assignee,
            'author' => $author,
            'entry' => $draft,
            'url' => $this->editUrl($draft),
        ]);

        Craft::$app->getMailer()->compose()
            ->setTo($assignee->email)
            ->setSubject(Craft::t('craft-delta', 'Draft awaiting your review: {title}', ['title' => $draft->title]))
            ->setTextBody($body)
            ->send();
    }

    public function sendApproved(DraftWorkflow $wf, Entry $draft): void
    {
        $author = Craft::$app->getUsers()->getUserById($wf->submittedBy);
        $reviewer = $wf->decidedBy ? Craft::$app->getUsers()->getUserById($wf->decidedBy) : null;
        if (!$author || !$reviewer) {
            return;
        }

        $body = $this->render('approved', [
            'author' => $author,
            'reviewer' => $reviewer,
            'entry' => $draft,
            'url' => $this->editUrl($draft),
            'scheduledFor' => $wf->scheduledFor,
        ]);

        Craft::$app->getMailer()->compose()
            ->setTo($author->email)
            ->setSubject(Craft::t('craft-delta', 'Your draft was approved: {title}', ['title' => $draft->title]))
            ->setTextBody($body)
            ->send();
    }

    public function sendRejected(DraftWorkflow $wf, Entry $draft): void
    {
        $author = Craft::$app->getUsers()->getUserById($wf->submittedBy);
        $reviewer = $wf->decidedBy ? Craft::$app->getUsers()->getUserById($wf->decidedBy) : null;
        if (!$author || !$reviewer) {
            return;
        }

        $body = $this->render('rejected', [
            'author' => $author,
            'reviewer' => $reviewer,
            'entry' => $draft,
            'url' => $this->editUrl($draft),
            'note' => $wf->rejectNote,
        ]);

        Craft::$app->getMailer()->compose()
            ->setTo($author->email)
            ->setSubject(Craft::t('craft-delta', 'Your draft was rejected: {title}', ['title' => $draft->title]))
            ->setTextBody($body)
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
