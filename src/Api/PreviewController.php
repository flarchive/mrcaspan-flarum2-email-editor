<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use MrCaspan\EmailEditor\PreviewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/email-editor/preview  render an email with sample values
 * POST /api/email-editor/test     the same, then email it to the admin
 *
 * Body: { locale, kind: "notification"|"information", subject, html, plain, body, layout: {...} }
 * Every part is optional, and unsaved text and layout options can be sent,
 * so the admin sees changes before saving them.
 */
class PreviewController implements RequestHandlerInterface
{
    public function __construct(
        protected PreviewRenderer $renderer
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $input = (array) $request->getParsedBody();
        $rendered = $this->renderer->render($actor, $input);

        if (str_ends_with($request->getUri()->getPath(), '/test')) {
            $this->renderer->send($actor, $rendered);
            $rendered['sentTo'] = $actor->email;
        }

        return new JsonResponse($rendered);
    }
}
