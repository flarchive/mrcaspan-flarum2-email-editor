<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Illuminate\Contracts\Container\Container;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Feeds the edited email texts to the translator. It only runs when the
 * translator (re)builds its cached catalogue, not on every request.
 */
class DatabaseLoader implements LoaderInterface
{
    public const FORMAT = 'email_editor_db';

    public function __construct(
        protected Container $container
    ) {
    }

    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        // Resolved lazily: the repository needs the database, which the
        // translator itself must not depend on while it's being built.
        $overrides = $this->container->make(OverrideRepository::class)->forLocale($locale);

        return new MessageCatalogue($locale, [$domain => $overrides]);
    }
}
