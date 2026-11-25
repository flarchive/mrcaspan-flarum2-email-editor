<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\View\Factory;

/**
 * Tags that work in every email text, on top of the placeholders each text
 * already has, so a text isn't limited to the values its own email passes it.
 *
 * Values come from what core shares with every email while it is built
 * (the recipient's email and name, and for notifications the unsubscribe
 * link and type), plus forum-wide values. A tag an email can't fill (the
 * unsubscribe link in an account email, say) comes out empty.
 */
class EmailTags
{
    /** Every tag, in the order the admin page lists them. */
    public const NAMES = ['forumTitle', 'forumUrl', 'settingsLink', 'userEmail', 'username', 'unsubscribeLink', 'type', 'date', 'year'];

    /** Tags only notification emails can fill. */
    public const NOTIFICATION_ONLY = ['unsubscribeLink', 'type'];

    /**
     * Unsubscribe links of emails already sent. Core leaves the last
     * notification's values shared on the view factory, so without this an
     * account email sent later by the same queue worker could pick up
     * someone else's unsubscribe link.
     *
     * @var array<string, true>
     */
    protected array $sentLinks = [];

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url,
        protected Factory $views,
        protected TranslatorInterface $translator
    ) {
    }

    public function markSent(mixed $unsubscribeLink): void
    {
        if (is_string($unsubscribeLink) && $unsubscribeLink !== '') {
            $this->sentLinks[$unsubscribeLink] = true;
        }
    }

    /**
     * The value of every tag for the email being built.
     *
     * @param array<string, mixed> $data the email's view data, which wins over what's shared
     * @return array<string, string>
     */
    public function values(array $data = []): array
    {
        $data += $this->views->getShared();

        $link = $data['unsubscribeLink'] ?? null;
        $user = $data['user'] ?? null;

        // Account emails re-share the recipient's address but not the
        // notification values, so a notification user who isn't the
        // recipient means those values are left over from an earlier email.
        $isNotification = is_string($link) && $link !== ''
            && ! isset($this->sentLinks[$link])
            && is_object($user) && isset($user->email) && $user->email === ($data['userEmail'] ?? null);

        return [
            'forumTitle' => (string) $this->settings->get('forum_title'),
            'forumUrl' => $this->url->to('forum')->base(),
            'settingsLink' => $this->url->to('forum')->route('settings'),
            'userEmail' => $this->text($data['userEmail'] ?? null),
            'username' => $this->text($data['username'] ?? null),
            'unsubscribeLink' => $isNotification ? $link : '',
            'type' => $isNotification ? $this->text($data['type'] ?? null) : '',
            'date' => $this->date(),
            'year' => date('Y'),
        ];
    }

    /**
     * Put tag values into a text that hasn't been through the translator,
     * like the subject line or the layout's extra footer text. Only the
     * exact tag names are touched; any other {…} is left alone.
     *
     * @param array<string, string> $values
     * @param callable(string): string|null $escape applied to each value
     */
    public static function fill(string $text, array $values, ?callable $escape = null): string
    {
        if (! str_contains($text, '{')) {
            return $text;
        }

        return preg_replace_callback(
            '/\{\s*('.implode('|', self::NAMES).')\s*\}/',
            fn ($m) => $escape ? $escape($values[$m[1]] ?? '') : ($values[$m[1]] ?? ''),
            $text
        ) ?? $text;
    }

    protected function text(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    protected function date(): string
    {
        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
            $formatted = $formatter->format(time());

            if (is_string($formatted)) {
                return $formatted;
            }
        }

        return date('F j, Y');
    }
}
