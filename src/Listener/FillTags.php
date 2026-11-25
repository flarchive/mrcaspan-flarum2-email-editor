<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor\Listener;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use MrCaspan\EmailEditor\EmailTags;

/**
 * Fills the shared tags in the parts of an email that aren't built in an
 * email view: the subject line, and the body of account emails (activation,
 * password reset, email change), which core translates before the view
 * sees it. Unknown placeholders survive translation as literal "{tag}", so
 * they can be filled here.
 *
 * Notification bodies are left alone: their texts already got the tags in
 * the view, and what's left in them is post content, which must not change.
 */
class FillTags
{
    public function __construct(
        protected EmailTags $tags
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MessageSending::class, [$this, 'sending']);
    }

    /**
     * Returns nothing: MessageSending is dispatched with until(), and any
     * return value would stop later listeners or cancel the email.
     */
    public function sending(MessageSending $event): void
    {
        $message = $event->message;
        $data = $event->data;

        if (! isset($data['userEmail']) && ($to = $message->getTo())) {
            $data['userEmail'] = $to[0]->getAddress();
        }

        $values = $this->tags->values($data);

        // The email is fully built by now, so its unsubscribe link is spent,
        // even if a later listener stops the email from being sent.
        $this->tags->markSent($data['unsubscribeLink'] ?? null);

        $subject = $message->getSubject();

        if (is_string($subject) && str_contains($subject, '{')) {
            $message->subject(EmailTags::fill($subject, $values));
        }

        if (isset($data['blueprint'])) {
            return;
        }

        $html = $message->getHtmlBody();

        if (is_string($html) && str_contains($html, '{')) {
            $message->html(
                EmailTags::fill($html, $values, fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8')),
                $message->getHtmlCharset() ?? 'utf-8'
            );
        }

        $text = $message->getTextBody();

        if (is_string($text) && str_contains($text, '{')) {
            $message->text(EmailTags::fill($text, $values), $message->getTextCharset() ?? 'utf-8');
        }
    }
}
