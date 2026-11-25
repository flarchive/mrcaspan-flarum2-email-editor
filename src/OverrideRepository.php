<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Carbon\Carbon;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * The email texts the admin has edited, stored per locale in
 * `email_editor_overrides`. A key with no row uses its normal text.
 */
class OverrideRepository
{
    public const TABLE = 'email_editor_overrides';

    /**
     * Comma-separated list of the locales that have edits. It's kept in a
     * setting (settings are already in memory) so that registering the edits
     * with the translator on each request never needs a database query.
     */
    public const LOCALES_KEY = 'mrcaspan-email-editor.override_locales';

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * @return array<string, string> key => edited text
     */
    public function forLocale(string $locale): array
    {
        return $this->db->table(self::TABLE)
            ->where('locale', $locale)
            ->pluck('value', 'key')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function localesWithOverrides(): array
    {
        $value = (string) $this->settings->get(self::LOCALES_KEY, '');

        return array_values(array_filter(explode(',', $value), fn ($l) => $l !== ''));
    }

    public function set(string $locale, string $key, string $value): void
    {
        $this->db->table(self::TABLE)->updateOrInsert(
            ['locale' => $locale, 'key' => $key],
            ['value' => $value, 'updated_at' => Carbon::now('UTC')]
        );
    }

    public function delete(string $locale, string $key): void
    {
        $this->db->table(self::TABLE)->where('locale', $locale)->where('key', $key)->delete();
    }

    /**
     * Call after any change: records which locales have edits and makes every
     * server rebuild its translation cache, using core's own "translations
     * changed outside the files" stamp.
     */
    public function changed(LocaleManager $locales): void
    {
        $withEdits = $this->db->table(self::TABLE)->distinct()->orderBy('locale')->pluck('locale')->all();

        $this->settings->set(self::LOCALES_KEY, implode(',', $withEdits));
        $this->settings->set(LocaleManager::REVISION_STAMP_KEY, (string) microtime(true));

        $locales->clearCache();
    }
}
