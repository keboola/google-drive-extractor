<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class ConfigDefinition implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('parameters');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('outputBucket')
                ->end()
                ->scalarNode('data_dir')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('#serviceAccount')
                    ->info('Service account credentials JSON (encrypted)')
                ->end()
                ->arrayNode('sheets')
                    ->isRequired()
                    ->prototype('array')
                        ->children()
                            ->integerNode('id')
                                ->isRequired()
                                ->min(0)
                            ->end()
                            ->scalarNode('fileId')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('fileTitle')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('sheetId')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('sheetTitle')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('outputTable')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->booleanNode('enabled')
                                ->defaultValue(true)
                            ->end()
                            ->scalarNode('columnRange')
                                ->validate()
                                    ->ifTrue(function ($v) {
                                        // Allow empty string for backwards compatibility
                                        if ($v === '') {
                                            return false;
                                        }
                                        return !preg_match('/^[A-Z]+([1-9]\d*)?:[A-Z]+([1-9]\d*)?$/i', $v);
                                    })
                                    ->thenInvalid(
                                        'Column range must be in format "A:E" (columns), ' .
                                        '"A1:E10" (bounded), "A10:E" (start row), or "A:E10" (end row)',
                                    )
                                ->end()
                            ->end()
                            ->arrayNode('header')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->integerNode('rows')
                                        ->defaultValue(1)
                                        ->min(0)
                                    ->end()
                                    ->arrayNode('columns')
                                        ->prototype('scalar')
                                        ->end()
                                    ->end()
                                    ->arrayNode('transpose')
                                        ->children()
                                            ->integerNode('row')
                                            ->end()
                                            ->scalarNode('name')
                                            ->end()
                                        ->end()
                                    ->end()
                                    ->booleanNode('sanitize')
                                    ->end()
                                ->end()
                            ->end()
                            ->arrayNode('transform')
                                ->children()
                                    ->arrayNode('transpose')
                                        ->children()
                                            ->scalarNode('from')
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
