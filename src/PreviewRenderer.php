<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Formatter\Formatter;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\Translator;
use Flarum\Mail\MailFormatter;
use Flarum\Mail\SafeSubstitution;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use Symfony\Component\Translation\Formatter\IntlFormatter;
use Symfony\Component\Translation\MessageCatalogueInterface;

/**
 * Renders an email the way the forum would send it, through core's own email
 * views (and so through this extension's layout), with sample values in
 * place of the real ones.
 *
 * It's a close preview, not an exact copy: each extension builds its emails
 * in its own view, and those views need real discussions and posts. Here the
 * email's text is shown inside core's standard notification or account email.
 */
class PreviewRenderer
{
    public function __construct(
        protected Factory $views,
        protected Formatter $formatter,
        protected Translator $translator,
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url,
        protected LayoutSettings $layout,
        protected Mailer $mailer,
        protected EmailTags $tags
    ) {
    }

    /** @var array<string, string> */
    protected array $tagValues = [];

    /**
     * @return array{subject: string, html: string, text: string}
     */
    public function render(User $actor, array $input): array
    {
        $previousLocale = $this->translator->getLocale();
        $locale = Arr::get($input, 'locale');

        if (is_string($locale) && $locale !== '') {
            $this->translator->setLocale($locale);
        }

        $layout = Arr::get($input, 'layout');
        $this->layout->setPreview(is_array($layout) ? $layout : null);

        $restore = $this->useUnsavedTexts(Arr::get($input, 'texts'));

        try {
            return $this->build($actor, $input);
        } finally {
            $restore();
            $this->layout->setPreview(null);
            $this->translator->setLocale($previousLocale);
        }
    }

    /**
     * Put unsaved email texts into the translator for this one render, so the
     * preview shows them everywhere they're used, the greeting, sign-off and
     * footers included. A text with a broken placeholder is skipped (its
     * saved wording shows) rather than failing the whole preview.
     *
     * @return callable(): void puts the saved texts back
     */
    protected function useUnsavedTexts(mixed $texts): callable
    {
        if (! is_array($texts) || ! $texts) {
            return fn () => null;
        }

        $catalogue = $this->translator->getCatalogue();
        $domain = 'messages'.MessageCatalogueInterface::INTL_DOMAIN_SUFFIX;
        $intl = new IntlFormatter();
        $saved = [];

        foreach ($texts as $key => $value) {
            if (! is_string($key) || ! is_string($value) || ! EmailCatalog::isEmailKey($key)) {
                continue;
            }

            $value = str_replace("\r\n", "\n", $value);

            if ($value !== '' && class_exists(\MessageFormatter::class)) {
                try {
                    $intl->formatIntl($value, $this->translator->getLocale(), []);
                } catch (\Throwable) {
                    continue;
                }
            }

            $saved[$key] = $catalogue->get($key, 'messages');
            $catalogue->set($key, $value, $domain);
        }

        return function () use ($catalogue, $domain, $saved) {
            foreach ($saved as $key => $value) {
                $catalogue->set($key, $value, $domain);
            }
        };
    }

    public function send(User $actor, array $rendered): void
    {
        $format = $this->settings->get('mail_format');
        $subject = $this->translator->trans('mrcaspan-flarum2-email-editor.admin.test_subject_prefix').' '.$rendered['subject'];

        $this->mailer->send([], [], function (Message $message) use ($actor, $rendered, $format, $subject) {
            $message->to($actor->email, $actor->display_name);
            $message->subject($subject);

            $email = $message->getSymfonyMessage();

            if ($format !== 'plain') {
                $email->html($rendered['html']);
            }

            if ($format !== 'html') {
                $email->text($rendered['text']);
            }
        });
    }

    protected function build(User $actor, array $input): array
    {
        $mail = new MailFormatter($this->formatter);
        $kind = Arr::get($input, 'kind') === 'information' ? 'information' : 'notification';
        $locale = $this->translator->getLocale();

        $text = fn (string $name) => is_string($v = Arr::get($input, $name)) ? $v : null;

        // The shared tags get the values they'd have in a real email to this admin.
        $this->tagValues = $this->tags->values([
            'user' => $actor,
            'userEmail' => $actor->email,
            'username' => $actor->display_name,
            'unsubscribeLink' => $kind === 'notification' ? $this->url->to('forum')->base() : null,
            'type' => $this->translator->trans('core.email.notification.types.postCreated'),
        ]);

        $subject =$this->fill($text('subject') ?? '', $actor, $locale);

        if (trim($subject) === '') {
            $subject = $this->translator->trans('mrcaspan-flarum2-email-editor.admin.sample_subject');
        }

        $htmlSource = $text('html') ?? $text('body');
        $plainSource = $text('plain') ?? $text('body');

        if ($htmlSource === null && $plainSource === null) {
            $htmlSource = $plainSource = $this->translator->trans('mrcaspan-flarum2-email-editor.admin.sample_body');
        }

        $source = $this->fill($htmlSource ?? $plainSource, $actor, $locale);

        // Account emails arrive as plain text; notification emails as Markdown.
        $bodyHtml = $kind === 'information' ? $mail->plainToHtml($source) : $mail->convert($source);
        $bodyPlain = $plainSource !== null ? $this->fill($plainSource, $actor, $locale) : $mail->htmlToPlain($bodyHtml);

        $forumTitle = (string) $this->settings->get('forum_title');

        // The layouts read these as shared view data, as they do when core sends mail.
        $this->views->share([
            'username' => $actor->display_name,
            'userEmail' => $actor->email,
            'forumTitle' => $forumTitle,
            'title' => $subject,
        ]);

        $data = [
            'title' => $subject,
            'user' => $actor,
            'type' => $this->translator->trans('core.email.notification.types.postCreated'),
            // Account emails have no unsubscribe link, so {unsubscribeLink} previews empty there, as it sends.
            'unsubscribeLink' => $kind === 'notification' ? $this->url->to('forum')->base() : null,
            'settingsLink' => $this->url->to('forum')->route('settings'),
            'userEmail' => $actor->email,
            'forumTitle' => $forumTitle,
        ];

        $html = $this->views->make("mail::html.$kind", $data + ['body' => $bodyHtml])->render();
        $plain = $this->views->make("mail::plain.$kind", $data + ['body' => $bodyPlain])->render();

        return [
            'subject' => SafeSubstitution::restore($subject, escape: false),
            'html' => SafeSubstitution::restore($html),
            'text' => trim(SafeSubstitution::restore($plain, escape: false)),
        ];
    }

    /**
     * Put sample values in a text's placeholders.
     */
    public function fill(string $text, User $actor, string $locale): string
    {
        preg_match_all('/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*[,}]/', $text, $m);

        $values = [];
        foreach (array_unique($m[1]) as $name) {
            $values[$name] = $this->tagValues[$name] ?? $this->sample($name, $actor);
        }

        if (! $values) {
            return $text;
        }

        // Proper ICU formatting first, so plurals and the like come out right.
        if (class_exists(\MessageFormatter::class)) {
            try {
                return (new IntlFormatter())->formatIntl($text, $locale, $values);
            } catch (\Throwable) {
                // Fall through to simple replacement.
            }
        }

        return preg_replace_callback(
            '/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}/',
            fn ($match) => (string) ($values[$match[1]] ?? $match[0]),
            $text
        );
    }

    protected function sample(string $name, User $actor): string|int
    {
        $n = strtolower($name);
        $forum = $this->url->to('forum')->base();

        return match (true) {
            str_contains($n, 'url') || str_contains($n, 'link') => $forum,
            str_contains($n, 'email') => (string) $actor->email,
            $n === 'forum' || str_contains($n, 'forumtitle') || str_contains($n, 'forum_title') => (string) $this->settings->get('forum_title'),
            str_contains($n, 'count') || str_contains($n, 'number') => 3,
            str_contains($n, 'hours') => 24,
            str_contains($n, 'title') => $this->translator->trans('mrcaspan-flarum2-email-editor.admin.sample_title'),
            str_contains($n, 'content') || str_contains($n, 'message') || str_contains($n, 'reason') => $this->translator->trans('mrcaspan-flarum2-email-editor.admin.sample_content'),
            $n === 'provider' => 'Google',
            $n === 'type' => $this->translator->trans('core.email.notification.types.postCreated'),
            in_array($n, ['package', 'replacement'], true) => 'vendor/sample-extension',
            str_contains($n, 'name') || str_contains($n, 'user') || str_contains($n, 'actor') || str_contains($n, 'poster') => $this->translator->trans('mrcaspan-flarum2-email-editor.admin.sample_user'),
            default => '['.$name.']',
        };
    }
}
