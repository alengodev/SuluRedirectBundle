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

namespace Alengo\SuluRedirectBundle\Webspace;

use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * Resolves an incoming HTTP host to the key of the webspace it belongs to, using the
 * webspaces.xml configuration for the current environment.
 *
 * This runs before Sulu's RequestAnalyzer (which only populates the webspace inside the
 * RouterListener at priority 32), so the webspace is resolved directly via the
 * WebspaceManager. Passing environment = null lets the manager use the current
 * environment it was configured with.
 */
class WebspaceHostResolver
{
    public function __construct(
        private readonly WebspaceManagerInterface $webspaceManager,
    ) {
    }

    public function resolveWebspaceKey(string $host): ?string
    {
        $portalInformations = $this->webspaceManager->findPortalInformationsByHostIncludingSubdomains($host);

        foreach ($portalInformations as $portalInformation) {
            $webspaceKey = $portalInformation->getWebspaceKey();

            if (\is_string($webspaceKey) && '' !== $webspaceKey) {
                return $webspaceKey;
            }
        }

        return null;
    }
}
