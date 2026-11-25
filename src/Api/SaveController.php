<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor\Api;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\LocaleManager;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use MrCaspan\EmailEditor\EmailCatalog;
use MrCaspan\EmailEditor\OverrideRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Translation\Formatter\IntlFormatter;

/**
 * POST /api/email-editor  { locale, values: { "<key>": "new text" | null } }
 *
 * Saves edited texts. null, or text identical to the original, removes the
 * edit so the key goes back to following the original (and its updates).
 */
class SaveController implements RequestHandlerInterface
{
    public function __construct(
        protected EmailCatalog $catalog,
        protected OverrideRepository $overrides,
        protected LocaleManager $locales,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $locale = (string) Arr::get($body, 'locale', '');
        $values = Arr::get($body, 'values');

        if ($locale === '' || ! $this->catalog->hasLocale($locale) || ! is_array($values)) {
            throw new ValidationException(['email-editor' => $this->translator->trans('mrcaspan-flarum2-email-editor.admin.error_bad_request')]);
        }

        $defaults = $this->catalog->defaultTexts($locale);
        $live = $this->catalog->liveTexts($locale);
        $intl = new IntlFormatter();
        $errors = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            // Only real email texts can be edited through this endpoint.
            if (! EmailCatalog::isEmailKey($key) || (! array_key_exists($key, $defaults) && ! array_key_exists($key, $live))) {
                $errors[$key] = $this->translator->trans('mrcaspan-flarum2-email-editor.admin.error_unknown_key');
                continue;
            }

            if ($value === null || (is_string($value) && array_key_exists($key, $defaults) && $defaults[$key] === $value)) {
                $this->overrides->delete($locale, $key);
                continue;
            }

            if (! is_string($value)) {
                $errors[$key] = $this->translator->trans('mrcaspan-flarum2-email-editor.admin.error_bad_request');
                continue;
            }

            $value = str_replace("\r\n", "\n", $value);

            // A text with a broken placeholder (an unclosed "{", say) would make
            // every email using it fail to send, so refuse it here.
            if ($value !== '' && class_exists(\MessageFormatter::class)) {
                try {
                    $intl->formatIntl($value, $locale, []);
                } catch (\Throwable $e) {
                    $errors[$key] = $this->translator->trans('mrcaspan-flarum2-email-editor.admin.error_bad_placeholder');
                    continue;
                }
            }

            $this->overrides->set($locale, $key, $value);
        }

        $this->overrides->changed($this->locales);

        if ($errors) {
            return new JsonResponse(['errors' => $errors] + EmailsController::payload($this->catalog, $locale), 422);
        }

        return new JsonResponse(EmailsController::payload($this->catalog, $locale));
    }
}
