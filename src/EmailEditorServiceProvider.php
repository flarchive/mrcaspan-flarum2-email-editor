<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Locale\LocaleManager;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Translation\MessageCatalogueInterface;

class EmailEditorServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(LayoutSettings::class);
        $this->container->singleton(EmailCatalog::class);

        // afterResolving runs once every extension has added its translation
        // files (they use `resolving`), so the edited texts are added last and
        // win over the originals.
        $this->container->afterResolving(LocaleManager::class, function (LocaleManager $locales, Container $container) {
            $withEdits = $container->make(OverrideRepository::class)->localesWithOverrides();

            if (! $withEdits) {
                return;
            }

            $translator = $locales->getTranslator();
            $translator->addLoader(DatabaseLoader::FORMAT, new DatabaseLoader($container));

            // Same domain core puts every translation in.
            $domain = 'messages'.MessageCatalogueInterface::INTL_DOMAIN_SUFFIX;

            foreach ($withEdits as $locale) {
                $translator->addResource(DatabaseLoader::FORMAT, 'database', $locale, $domain);
            }
        });
    }
}
