<?php

declare(strict_types=1);

/*
 * This file is part of Alengo\RedirectBundle.
 *
 * (c) alengo
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Alengo\RedirectBundle\EventSubscriber;

use Alengo\RedirectBundle\Redirect\RedirectMap;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Redirects legacy URLs to their new target early in the request lifecycle.
 *
 * Registered on kernel.request with a high priority (configurable) so the redirect fires
 * before routing and security. The listener is a no-op unless the current host is in the
 * configured allow list (empty list = every host) and the absolute request URI matches an
 * entry in the {@see RedirectMap}.
 */
final class RedirectListener
{
    /**
     * @param list<string> $allowedDomains hosts the redirects apply to; empty = every host
     */
    public function __construct(
        private readonly RedirectMap $redirectMap,
        private readonly array $allowedDomains = [],
        private readonly int $statusCode = Response::HTTP_MOVED_PERMANENTLY,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ([] !== $this->allowedDomains && !\in_array($request->getHost(), $this->allowedDomains, true)) {
            return; // host not covered → let Symfony keep routing
        }

        $target = $this->redirectMap->resolve($request->getUri());

        if (null !== $target) {
            $event->setResponse(new RedirectResponse($target, $this->statusCode));
        }
    }
}
