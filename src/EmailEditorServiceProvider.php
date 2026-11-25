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
use Flarum\Locale\TranslatorInterface;
use Flarum\Mail\MailTranslator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Symfony\Component\Translation\MessageCatalogueInterface;

class EmailEditorServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(LayoutSettings::class);
        $this->container->singleton(EmailCatalog::class);
        $this->container->singleton(EmailTags::class);

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

    public function boot(Factory $views): void
    {
        // Core gives every email view a translator that keeps placeholder
        // values safe (see Flarum\Mail\MailServiceProvider). This runs after
        // core's composer and wraps that translator, so every email text can
        // use the shared tags as well as its own placeholders.
        $views->composer('*', function (View $view) {
            $name = $view->name();

            if (! str_starts_with($name, 'mail::') && ! str_contains($name, 'email')) {
                return;
            }

            $data = $view->getData();
            $translator = $data['translator'] ?? null;

            if ($translator instanceof TagTranslator) {
                return;
            }

            if (! $translator instanceof MailTranslator) {
                $translator = new MailTranslator($this->container->make(TranslatorInterface::class));
            }

            $tags = $this->container->make(EmailTags::class)->values($data);

            $view->with([
                'translator' => new TagTranslator($translator, $tags),
                'emailEditorTags' => $tags,
            ]);
        });
    }
}
