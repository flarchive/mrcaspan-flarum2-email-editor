<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use MrCaspan\EmailEditor\EmailCatalog;
use MrCaspan\EmailEditor\EmailTags;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/email-editor?locale=en
 * Every email text on the forum, grouped into emails.
 */
class EmailsController implements RequestHandlerInterface
{
    public function __construct(
        protected EmailCatalog $catalog
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $locale = (string) Arr::get($request->getQueryParams(), 'locale', '');

        return new JsonResponse(self::payload($this->catalog, $locale));
    }

    public static function payload(EmailCatalog $catalog, string $locale): array
    {
        if ($locale === '' || ! $catalog->hasLocale($locale)) {
            $locale = $catalog->defaultLocale();
        }

        return [
            'locale' => $locale,
            'locales' => $catalog->locales(),
            'groups' => $catalog->groups($locale),
            'tags' => array_map(fn (string $name) => [
                'name' => $name,
                'notificationOnly' => in_array($name, EmailTags::NOTIFICATION_ONLY, true),
            ], EmailTags::NAMES),
        ];
    }
}
