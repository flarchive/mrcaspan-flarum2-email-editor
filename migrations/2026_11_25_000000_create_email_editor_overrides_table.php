<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists('email_editor_overrides', function (Blueprint $table) {
    $table->increments('id');
    $table->string('locale', 20);
    $table->string('key', 191);
    $table->text('value');
    $table->dateTime('updated_at')->nullable();

    $table->unique(['locale', 'key']);
});
