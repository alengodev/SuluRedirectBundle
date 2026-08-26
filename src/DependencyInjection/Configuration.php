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
                ->scalarNode('csv_path')
                    ->info('Absolute path to the CSV file mapping old URLs to new ones (delimiter-separated).')
                    ->cannotBeEmpty()
                    ->defaultValue('%kernel.project_dir%/config/redirects.csv')
                ->end()
                ->scalarNode('delimiter')
                    ->info('CSV field delimiter.')
                    ->cannotBeEmpty()
                    ->defaultValue(';')
                ->end()
                ->arrayNode('allowed_domains')
                    ->info('Hosts the redirects apply to. Empty list = apply on every host.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->integerNode('status_code')
                    ->info('HTTP status code used for the redirect (301 permanent, 302 temporary).')
                    ->defaultValue(Response::HTTP_MOVED_PERMANENTLY)
                ->end()
                ->integerNode('priority')
                    ->info('kernel.request listener priority. High so redirects fire before routing/security.')
                    ->defaultValue(20)
                ->end()
            ->end();

        return $treeBuilder;
    }
}
