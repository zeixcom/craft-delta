<?php

/**
 * Craft Delta config
 *
 * Copy this file to your project's `config/craft-delta.php` to override settings
 * per environment. Anything set here takes precedence over the CP settings.
 * Multi-environment configs are supported (use '*', 'dev', 'production' keys).
 *
 * @see https://github.com/zeixcom/craft-delta
 */

return [
    // Unchanged lines of context shown around a change (0–20).
    'diffContext' => 3,

    // Character count above which a field falls back to a simplified diff (min 1000).
    'maxFieldLength' => 50000,

    // Whether unchanged fields are shown by default.
    'defaultShowUnchanged' => false,

    // Review Mode UI (Start Review, accept/reject, apply). False = read-only diff.
    'enableReviewMode' => true,

    // Submit-for-review workflow (Submit button, workflow toolbar + endpoints).
    'enableWorkflow' => true,

    /**
     * Override the notification email templates with your own.
     *
     * Each key maps to a template path resolved against your site `templates/`
     * folder (e.g. 'emails/delta/submitted' → templates/emails/delta/submitted.twig).
     * Omit a key — or point it at a template that doesn't exist — to keep the
     * plugin's bundled default. Templates render as plain text in the
     * recipient's preferred language.
     *
     * Variables available in each template:
     *
     *   submitted  (to a reviewer)
     *     reviewer   craft\elements\User    the assigned reviewer (recipient)
     *     author     craft\elements\User    who submitted the draft
     *     entry      craft\elements\Entry   the submitted draft
     *     url        string                 link to the review page
     *
     *   approved   (to the author)
     *     author     craft\elements\User    recipient
     *     entry      craft\elements\Entry   the draft
     *     url        string                 link to the review page
     *     note       string|null            always null for approvals
     *
     *   declined   (to the author)
     *     author     craft\elements\User    recipient
     *     entry      craft\elements\Entry   the draft
     *     url        string                 link to the review page
     *     note       string|null            the reviewer's optional decline note
     *
     *   published  (to the author)
     *     author       craft\elements\User       recipient
     *     entry        craft\elements\Entry      the draft that was applied
     *     url          string                    link to the entry's edit page
     *     scheduledFor DateTime|null             set when the publish is scheduled
     *
     *   comment    (to the other participant)
     *     author     craft\elements\User    recipient (the greeting target)
     *     entry      craft\elements\Entry   the entry/draft under review
     *     url        string                 link to the review page
     *     commenter  string                 display name of who commented
     *     comment    string                 the comment body
     */
    'emailTemplates' => [
        // 'submitted' => 'emails/delta/submitted',
        // 'approved'  => 'emails/delta/approved',
        // 'declined'  => 'emails/delta/declined',
        // 'published' => 'emails/delta/published',
        // 'comment'   => 'emails/delta/comment',
    ],
];
