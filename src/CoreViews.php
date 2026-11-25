<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Mail\Mailer;
use ReflectionClass;

/**
 * Where core keeps its email views (`vendor/flarum/core/views/email`), found
 * from a core class rather than a hard-coded vendor path so it works however
 * Flarum is installed.
 */
class CoreViews
{
    public static function emailPath(): string
    {
        // .../flarum/core/src/Mail/Mailer.php -> .../flarum/core
        $core = dirname((string) (new ReflectionClass(Mailer::class))->getFileName(), 3);

        return $core.'/views/email';
    }
}
