<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Locale\LocaleManager;

/**
 * Reads the list of translation files registered with the LocaleManager, so
 * the admin page can show each text's original wording next to an edit.
 *
 * This is a read-only helper, never instantiated. As a subclass it may read
 * the manager's protected `$registered` list ("locale|prefix|file" entries).
 * If a future Flarum drops that list, it returns null and the admin page
 * simply can't show originals for edited texts. Nothing else is affected.
 */
final class LocaleFiles extends LocaleManager
{
    /**
     * @return list<string>|null
     */
    public static function of(LocaleManager $manager): ?array
    {
        if (! property_exists($manager, 'registered')) {
            return null;
        }

        $files = $manager->registered;

        return is_array($files) ? array_values($files) : null;
    }
}
