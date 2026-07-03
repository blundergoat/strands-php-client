<?php

declare(strict_types=1);

namespace StrandsPhpClient\Integration\Symfony\DependencyInjection;

use StrandsPhpClient\Http\RequestMiddleware;
use StrandsPhpClient\Http\ResponseObserver;
use StrandsPhpClient\StrandsClient;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Turns the app's validated `strands:` config into real container services.
 *
 * Runs once while Symfony compiles the container: it reads each configured
 * agent, registers a "strands.client.<name>" service for it, aliases the first
 * as the default StrandsClient, and auto-tags app middleware/observers so they
 * are injected into every client.
 */
class StrandsExtension extends Extension
{
    /**
     * Register a client service per configured agent as the container compiles.
     *
     * @param array<int, array<string, mixed>> $configs Symfony config arrays merged for the app.
     * @param ContainerBuilder $container Symfony container receiving client services.
     * @return void No returned value; updates client or observer state.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        /** @var array<string, array<string, mixed>> $agents validated before app code uses it. */
        $agents = is_array($config['agents'] ?? null) ? $config['agents'] : [];

        // No agents configured means the app isn't using Strands yet — register nothing.
        if ($agents === []) {
            return;
        }

        $container->registerForAutoconfiguration(RequestMiddleware::class)
            ->addTag('strands.middleware');
        $container->registerForAutoconfiguration(ResponseObserver::class)
            ->addTag('strands.response_observer');

        $factoryDefinition = new Definition(StrandsClientFactory::class);
        $factoryDefinition->setArgument('$agents', $agents);
        $factoryDefinition->setArgument('$logger', new Reference('logger'));
        $factoryDefinition->setArgument('$middleware', new TaggedIteratorArgument('strands.middleware'));
        $factoryDefinition->setArgument('$responseObservers', new TaggedIteratorArgument('strands.response_observer'));
        $container->setDefinition('strands.client_factory', $factoryDefinition);

        $firstServiceId = null;

        // Register one injectable client service per configured agent.
        foreach (array_keys($agents) as $name) {
            $serviceId = 'strands.client.' . (string) $name;

            $definition = new Definition(StrandsClient::class);
            $definition->setFactory([new Reference('strands.client_factory'), 'create']);
            $definition->setArgument(0, $name);

            $container->setDefinition($serviceId, $definition);

            $firstServiceId ??= $serviceId;
        }

        $container->setAlias(StrandsClient::class, $firstServiceId);
    }
}
