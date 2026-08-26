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

namespace Alengo\SuluRedirectBundle\DependencyInjection;

use Alengo\SuluRedirectBundle\EventSubscriber\RedirectListener;
use Alengo\SuluRedirectBundle\Redirect\RedirectMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\HttpKernel\KernelEvents;

class AlengoRedirectExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (!$config['enabled']) {
            return;
        }

        $mapDefinition = new Definition(RedirectMap::class, [
            $config['csv_path'],
            $config['delimiter'],
            // cache.app is always present in a full-stack app; degrade gracefully if not.
            new Reference('cache.app', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $mapDefinition->setPublic(false);
        $container->setDefinition(RedirectMap::class, $mapDefinition);

        $listenerDefinition = new Definition(RedirectListener::class, [
            new Reference(RedirectMap::class),
            $config['allowed_domains'],
            $config['status_code'],
        ]);
        $listenerDefinition->setPublic(false);
        $listenerDefinition->addTag('kernel.event_listener', [
            'event' => KernelEvents::REQUEST,
            'method' => 'onKernelRequest',
            'priority' => $config['priority'],
        ]);
        $container->setDefinition(RedirectListener::class, $listenerDefinition);
    }

    public function getAlias(): string
    {
        return 'alengo_redirect';
    }
}
