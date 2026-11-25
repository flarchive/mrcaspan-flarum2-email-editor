<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Locale\LocaleManager;
use Flarum\Locale\PrefixedYamlFileLoader;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;

/**
 * Finds every email text on the forum and groups them into emails.
 *
 * Flarum 2.0 core and extensions keep the words of their emails in
 * translations named `<extension>.email.…`, so reading the translator finds
 * all of them, including those of extensions installed later, without a
 * hard-coded list.
 */
class EmailCatalog
{
    /** Key segments that say which part of an email a text is. */
    protected const FIELD_PARTS = ['subject', 'body', 'html', 'plain', 'text'];

    /**
     * Texts not listed in the editor. The account-email layout's fallback
     * heading is only used when an email has no title, and every account
     * email has one, so editing it changes nothing.
     */
    protected const HIDDEN = ['core.email.informational.default_title'];

    /** @var array<string, array<string, ?string>> */
    protected array $defaultsCache = [];

    public function __construct(
        protected LocaleManager $locales,
        protected OverrideRepository $overrides,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public static function isEmailKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]+\.email\.[A-Za-z0-9_.-]+$/', $key);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function locales(): array
    {
        $out = [];

        foreach ($this->locales->getLocales() as $code => $name) {
            $out[] = ['code' => (string) $code, 'name' => (string) $name];
        }

        return $out;
    }

    public function defaultLocale(): string
    {
        return (string) $this->settings->get('default_locale', 'en');
    }

    public function hasLocale(string $locale): bool
    {
        return $this->locales->hasLocale($locale) || $locale === 'en';
    }

    /**
     * Every email text as the forum currently has it (edits included).
     *
     * @return array<string, string>
     */
    public function liveTexts(string $locale): array
    {
        return $this->collect($this->locales->getTranslator()->getCatalogue($locale));
    }

    /**
     * The original texts, from the translation files alone, without our edits.
     * A value is null when it can't be worked out (see {@see LocaleFiles}).
     *
     * @return array<string, ?string>
     */
    public function defaultTexts(string $locale): array
    {
        if (isset($this->defaultsCache[$locale])) {
            return $this->defaultsCache[$locale];
        }

        $files = LocaleFiles::of($this->locales);

        if ($files === null) {
            // Fall back to the live texts, which are only the originals where
            // nothing has been edited.
            $edited = $this->overrides->forLocale($locale);
            $defaults = [];

            foreach ($this->liveTexts($locale) as $key => $value) {
                $defaults[$key] = array_key_exists($key, $edited) ? null : $value;
            }

            return $this->defaultsCache[$locale] = $defaults;
        }

        // A separate, uncached translator given the same files as the real one.
        $translator = new Translator($locale, null, null, false);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('prefixed_yaml', new PrefixedYamlFileLoader());

        $domain = 'messages'.MessageCatalogueInterface::INTL_DOMAIN_SUFFIX;

        foreach ($files as $entry) {
            [$fileLocale, $prefix, $file] = array_pad(explode('|', $entry, 3), 3, '');
            $translator->addResource('prefixed_yaml', compact('file', 'prefix'), $fileLocale, $domain);
        }

        return $this->defaultsCache[$locale] = $this->collect($translator->getCatalogue($locale));
    }

    /**
     * The email texts grouped into emails, ready for the admin page.
     */
    public function groups(string $locale): array
    {
        $edited = $this->overrides->forLocale($locale);
        $defaults = $this->defaultTexts($locale);
        $live = $this->liveTexts($locale);

        $keys = array_unique(array_merge(array_keys($defaults), array_keys($live)));
        sort($keys);

        $groups = [];

        foreach (array_diff($keys, self::HIDDEN) as $key) {
            [$namespace, $groupPath, $field] = $this->split($key);
            $id = $namespace.':'.$groupPath;

            $default = $defaults[$key] ?? null;
            $value = $edited[$key] ?? $default ?? $live[$key] ?? '';

            $groups[$id] ??= [
                'id' => $id,
                'namespace' => $namespace,
                'path' => $groupPath,
                'label' => $groupPath === '' ? null : $this->humanize($groupPath),
                'kind' => $this->kind($namespace, $groupPath),
                'edited' => false,
                'fields' => [],
            ];

            $groups[$id]['fields'][] = [
                'key' => $key,
                'field' => $field,
                'value' => $value,
                'default' => $default,
                'edited' => array_key_exists($key, $edited),
                'placeholders' => $this->placeholders(($default ?? '').' '.$value),
            ];

            if (array_key_exists($key, $edited)) {
                $groups[$id]['edited'] = true;
            }
        }

        foreach ($groups as &$group) {
            $hasHtml = (bool) array_filter($group['fields'], fn ($f) => str_contains('.'.$f['field'].'.', '.html.'));

            foreach ($group['fields'] as &$field) {
                $field['role'] = $this->role($field['field'], $hasHtml);
            }
            unset($field);

            usort($group['fields'], fn ($a, $b) => [$this->roleOrder($a['role']), $a['key']] <=> [$this->roleOrder($b['role']), $b['key']]);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * Placeholder names used in a text, like `title` in "New post in {title}".
     *
     * @return list<string>
     */
    public function placeholders(string $text): array
    {
        preg_match_all('/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*[,}]/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * @return array<string, string>
     */
    protected function collect(MessageCatalogueInterface $catalogue): array
    {
        $keys = [];

        for ($current = $catalogue; $current !== null; $current = $current->getFallbackCatalogue()) {
            foreach ($current->all() as $messages) {
                foreach ($messages as $key => $_) {
                    if (is_string($key) && self::isEmailKey($key)) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        $out = [];

        foreach (array_keys($keys) as $key) {
            // get() looks in the intl-icu domain first and follows fallbacks.
            $out[$key] = (string) $catalogue->get($key, 'messages');
        }

        return $out;
    }

    /**
     * "fof-subscribed.email.subject.newDiscussion" -> ['fof-subscribed', 'newDiscussion', 'subject']
     * "core.email.notification.footer.main_text"   -> ['core', 'notification.footer', 'main_text']
     * "core.email.greeting"                        -> ['core', '', 'greeting']
     *
     * @return array{0: string, 1: string, 2: string}
     */
    protected function split(string $key): array
    {
        $parts = explode('.', $key);
        $namespace = $parts[0];
        $rest = array_slice($parts, 2);

        $fieldParts = array_values(array_filter($rest, fn ($p) => in_array($p, self::FIELD_PARTS, true)));

        if ($fieldParts) {
            $groupParts = array_values(array_filter($rest, fn ($p) => ! in_array($p, self::FIELD_PARTS, true)));
            $field = implode('.', $fieldParts);
        } else {
            $field = (string) array_pop($rest);
            $groupParts = $rest;
        }

        return [$namespace, implode('.', $groupParts), $field];
    }

    protected function role(string $field, bool $groupHasHtml): string
    {
        $parts = explode('.', $field);

        if ($parts === ['subject']) {
            return 'subject';
        }

        if (in_array('html', $parts, true)) {
            return 'html';
        }

        if (in_array('plain', $parts, true) || in_array('text', $parts, true)) {
            return 'plain';
        }

        if ($parts === ['body']) {
            return $groupHasHtml ? 'plain' : 'body';
        }

        return 'other';
    }

    protected function roleOrder(string $role): int
    {
        return ['subject' => 0, 'html' => 1, 'body' => 2, 'plain' => 3][$role] ?? 4;
    }

    /**
     * Which of core's two email styles the email most likely uses, for the preview:
     * account emails ("information") or notification emails.
     */
    protected function kind(string $namespace, string $groupPath): string
    {
        if (in_array($namespace, ['core', 'fof-oauth'], true) && ! str_starts_with($groupPath, 'notification')) {
            return 'information';
        }

        return 'notification';
    }

    protected function humanize(string $path): string
    {
        $words = array_map(function ($part) {
            $part = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $part);

            return ucfirst(strtolower(str_replace(['_', '-'], ' ', $part)));
        }, explode('.', $path));

        return implode(' › ', $words);
    }
}
