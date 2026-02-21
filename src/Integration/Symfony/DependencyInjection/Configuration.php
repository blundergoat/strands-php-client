<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree (schema) for the "strands:" config key.
 */
class Configuration implements ConfigurationInterface
{
    /**
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('strands');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('agents')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('endpoint')
                                ->isRequired()
                                ->cannotBeEmpty()
                            ->end()
                            ->append($this->authNode())
                            ->append($this->timeoutNode('timeout', 120, 'Response timeout in seconds'))
                            ->append($this->timeoutNode('connect_timeout', 10, 'Connection timeout in seconds'))
                            ->integerNode('max_retries')
                                ->defaultValue(0)
                                ->min(0)
                                ->max(20)
                                ->info('Maximum number of retries on transient errors (0-20)')
                            ->end()
                            ->integerNode('retry_delay_ms')
                                ->defaultValue(500)
                                ->min(1)
                                ->info('Base delay between retries in milliseconds (doubles each retry)')
                            ->end()
                            ->arrayNode('retryable_status_codes')
                                ->integerPrototype()->end()
                                ->defaultValue([429, 502, 503, 504])
                                ->info('HTTP status codes that trigger automatic retry')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * Define the auth configuration node.
     *
     * Supports two drivers:
     *   'null'    → NullAuth (no authentication)
     *   'api_key' → ApiKeyAuth (sends API key in an HTTP header)
     */
    private function authNode(): ArrayNodeDefinition
    {
        $builder = new TreeBuilder('auth');
        /** @var ArrayNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('driver')
                    ->values(['null', 'api_key'])
                    ->defaultValue('null')
                ->end()
                ->scalarNode('api_key')
                    ->defaultNull()
                    ->info('API key (required when driver is api_key)')
                ->end()
                ->scalarNode('header_name')
                    ->defaultValue('Authorization')
                    ->info('HTTP header name for the API key')
                ->end()
                ->scalarNode('value_prefix')
                    ->defaultValue('Bearer ')
                    ->info('Prefix before the API key value (e.g. "Bearer ")')
                ->end()
            ->end()
        ;

        return $node;
    }

    /**
     * Define a timeout integer node with min(1) validation.
     *
     * @param string $name         The config key name
     * @param int    $default      The default value in seconds
     * @param string $description  Human-readable description
     *
     * @return \Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition
     */
    private function timeoutNode(string $name, int $default, string $description): \Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition
    {
        $builder = new TreeBuilder($name, 'integer');
        /** @var \Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition $node */
        $node = $builder->getRootNode();

        $node
            ->defaultValue($default)
            ->min(1)
            ->info($description)
        ;

        return $node;
    }
}
