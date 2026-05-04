<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Configuration;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class QueryConfigDefinition implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('parameters');

        $treeBuilder->getRootNode()
            ->ignoreExtraKeys()
            ->children()
                ->scalarNode('data_dir')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('#serviceAccount')
                    ->info('Service account credentials JSON (encrypted)')
                ->end()
                ->scalarNode('fileId')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Google Spreadsheet ID')
                ->end()
                ->scalarNode('query')
                    ->info('A1 notation range, optionally prefixed with sheet name (e.g. "Sheet1!A1:E50"). When omitted, action returns spreadsheet metadata.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
