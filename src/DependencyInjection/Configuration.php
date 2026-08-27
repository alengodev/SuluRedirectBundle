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

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpFoundation\Response;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('alengo_redirect');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Master switch. When false the listener is not registered at all.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('csv_dir')
                    ->info('Directory holding the per-webspace redirect CSV files.')
                    ->cannotBeEmpty()
                    ->defaultValue('%kernel.project_dir%/config/app')
                ->end()
                ->scalarNode('csv_pattern')
                    ->info('CSV filename pattern; {webspace} is replaced by the webspace key.')
                    ->cannotBeEmpty()
                    ->defaultValue('{webspace}_redirects.csv')
                ->end()
                ->scalarNode('delimiter')
                    ->info('CSV field delimiter.')
                    ->cannotBeEmpty()
                    ->defaultValue(';')
                ->end()
                ->integerNode('status_code')
                    ->info('HTTP status code used for the redirect (301 permanent, 302 temporary).')
                    ->defaultValue(Response::HTTP_MOVED_PERMANENTLY)
                ->end()
                ->integerNode('priority')
                    ->info('kernel.request listener priority. Must be > 32 so the redirect fires before the (Sulu) RouterListener, which would otherwise throw a 404 for legacy URLs that have no route.')
                    ->defaultValue(40)
                ->end()
            ->end();

        return $treeBuilder;
    }
}
