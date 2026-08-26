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

namespace Alengo\SuluRedirectBundle;

use Alengo\SuluRedirectBundle\DependencyInjection\AlengoRedirectExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class AlengoRedirectBundle extends AbstractBundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AlengoRedirectExtension();
    }
}
