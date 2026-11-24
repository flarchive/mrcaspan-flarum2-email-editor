<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    // Registers the edited texts with the translator and the layout settings service.
    (new Extend\ServiceProvider())
        ->register(EmailEditorServiceProvider::class),

    (new Extend\View())
        // Our versions of the two layouts every email is wrapped in. While the
        // custom layout is switched off they hand straight back to core's own.
        ->extendNamespace('mail', __DIR__.'/views/mail')
        // The custom layouts themselves.
        ->namespace('email-editor', __DIR__.'/views/layout')
        // Core's original layouts under a second name, so ours can fall back to them.
        ->namespace('email-editor-core', CoreViews::emailPath()),

    // Admin-only API used by the extension's admin page.
    (new Extend\Routes('api'))
        ->get('/email-editor', 'email-editor.index', Api\EmailsController::class)
        ->post('/email-editor', 'email-editor.save', Api\SaveController::class)
        ->post('/email-editor/preview', 'email-editor.preview', Api\PreviewController::class)
        ->post('/email-editor/test', 'email-editor.test', Api\PreviewController::class),
];
