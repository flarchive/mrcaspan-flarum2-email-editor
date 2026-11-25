<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Locale\TranslatorInterface;

/**
 * The translator email views get from this extension: core's mail
 * translator, with the {@see EmailTags} added to every email text's
 * placeholders. A text's own placeholders always win over a tag of the same
 * name. The values go through core's mail translator, so they are escaped
 * exactly like the text's own placeholders.
 */
class TagTranslator implements TranslatorInterface
{
    /**
     * @param array<string, string> $tags
     */
    public function __construct(
        protected TranslatorInterface $translator,
        protected array $tags
    ) {
    }

    public function trans($id, array $parameters = [], $domain = null, $locale = null): string
    {
        return $this->translator->trans($id, $this->with($id, $parameters), $domain, $locale);
    }

    public function get($key, array $replace = [], $locale = null): string
    {
        return $this->translator->get($key, $this->with($key, $replace), $locale);
    }

    public function choice($key, $number, array $replace = [], $locale = null): string
    {
        return $this->translator->choice($key, $number, $this->with($key, $replace), $locale);
    }

    public function getLocale(): string
    {
        return $this->translator->getLocale();
    }

    public function setLocale($locale): void
    {
        $this->translator->setLocale($locale);
    }

    protected function with(mixed $key, array $parameters): array
    {
        return is_string($key) && EmailCatalog::isEmailKey($key) ? $parameters + $this->tags : $parameters;
    }
}
