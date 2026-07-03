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
 * Symfony DI extension that registers Strands agent clients as services.
 */
class StrandsExtension extends Extension
{
    /**
     * Supports the load step in the app-facing flow.
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
