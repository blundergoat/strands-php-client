<?php

declare(strict_types=1);

namespace Strands\Integration\Symfony\DependencyInjection;

use Strands\StrandsClient;
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
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        /** @var array<string, array<string, mixed>> $agents */
        $agents = is_array($config['agents'] ?? null) ? $config['agents'] : [];

        if ($agents === []) {
            return;
        }

        $factoryDef = new Definition(StrandsClientFactory::class);
        $factoryDef->setArgument('$agents', $agents);
        $factoryDef->setArgument('$logger', new Reference('logger'));
        $container->setDefinition('strands.client_factory', $factoryDef);

        $firstServiceId = null;

        foreach (array_keys($agents) as $name) {
            $serviceId = 'strands.client.' . (string) $name;

            $def = new Definition(StrandsClient::class);
            $def->setFactory([new Reference('strands.client_factory'), 'create']);
            $def->setArgument(0, $name);

            $container->setDefinition($serviceId, $def);

            $firstServiceId ??= $serviceId;
        }

        $container->setAlias(StrandsClient::class, $firstServiceId);
    }
}
