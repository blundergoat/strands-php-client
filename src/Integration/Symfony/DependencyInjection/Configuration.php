<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines and validates the shape of the app's `strands:` config.
 *
 * Symfony uses this schema to check the bundle config an app writes in YAML:
 * which agents exist and, for each, its endpoint, auth driver, timeouts, and
 * retry policy. Bad or missing values are rejected at container-compile time,
 * so misconfiguration surfaces at deploy rather than on the first user request.
 */
class Configuration implements ConfigurationInterface
{
    /**
     * Build the validation schema Symfony applies to the app's `strands:` config.
     *
     * @return TreeBuilder The config tree Symfony validates the app's YAML against.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('strands');
        /** @var ArrayNodeDefinition $rootNode validated before app code uses it. */
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
     * Supports three drivers:
     *   'null'    - NullAuth (no authentication, default)
     *   'api_key' - ApiKeyAuth (sends API key in an HTTP header)
     *   'sigv4'   - SigV4Auth (AWS Signature V4 for IAM-protected endpoints)
     *
     * @return ArrayNodeDefinition The `auth` sub-schema (driver plus its per-driver options).
     */
    private function authNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('auth');
        /** @var ArrayNodeDefinition $node validated before app code uses it. */
        $node = $treeBuilder->getRootNode();

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('driver')
                    ->values(['null', 'api_key', 'sigv4'])
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
                ->scalarNode('region')
                    ->defaultNull()
                    ->info('AWS region (required when driver is sigv4)')
                ->end()
                ->scalarNode('service')
                    ->defaultValue('execute-api')
                    ->info('AWS service name for SigV4 signing')
                ->end()
                ->scalarNode('access_key_id')
                    ->defaultNull()
                    ->info('AWS access key ID (falls back to environment if not set)')
                ->end()
                ->scalarNode('secret_access_key')
                    ->defaultNull()
                    ->info('AWS secret access key (falls back to environment if not set)')
                ->end()
                ->scalarNode('session_token')
                    ->defaultNull()
                    ->info('AWS session token for temporary credentials')
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
     * @return \Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition A validated seconds-based timeout node (minimum 1).
     */
    private function timeoutNode(string $name, int $default, string $description): \Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition
    {
        $treeBuilder = new TreeBuilder($name, 'integer');
        /** @var \Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition $node validated before app code uses it. */
        $node = $treeBuilder->getRootNode();

        $node
            ->defaultValue($default)
            ->min(1)
            ->info($description)
        ;

        return $node;
    }
}
