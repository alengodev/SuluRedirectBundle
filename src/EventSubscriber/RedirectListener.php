<?php

declare(strict_types=1);

/*
 * This file is part of Alengo\SuluRedirectBundle.
 *
 * (c) alengo
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Alengo\SuluRedirectBundle\EventSubscriber;

use Alengo\SuluRedirectBundle\Redirect\RedirectMap;
use Alengo\SuluRedirectBundle\Webspace\WebspaceHostResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Redirects legacy URLs to their new target early in the request lifecycle.
 *
 * Registered on kernel.request at a high priority (configurable, default 40) so it fires
 * before Sulu's RouterListener (priority 32) — which would otherwise throw a 404 for
 * legacy URLs that have no route. The incoming host is mapped to a webspace key; the
 * webspace's CSV is consulted for the (domainless) request URI; on a match a redirect to
 * the same host + target path is issued.
 */
final class RedirectListener
{
    public function __construct(
        private readonly WebspaceHostResolver $webspaceHostResolver,
        private readonly RedirectMap $redirectMap,
        private readonly int $statusCode = Response::HTTP_MOVED_PERMANENTLY,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $webspaceKey = $this->webspaceHostResolver->resolveWebspaceKey($request->getHost());

        if (null === $webspaceKey) {
            return; // host belongs to no webspace in this environment
        }

        $target = $this->redirectMap->resolve($webspaceKey, $request->getRequestUri());

        if (null === $target) {
            return; // no redirect rule for this URI
        }

        $event->setResponse(new RedirectResponse(
            $request->getSchemeAndHttpHost() . $target,
            $this->statusCode,
        ));
    }
}
