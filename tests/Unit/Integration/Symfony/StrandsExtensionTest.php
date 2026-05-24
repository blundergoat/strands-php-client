<?php

declare(strict_types=1);

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsClientFactory;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsExtension;
use StrandsPhpClient\StrandsClient;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class StrandsExtensionTest extends TestCase
{
    /**
     * Load extension for the test scenario.
     *
     * @param array<string, mixed> $config Configuration values passed to the helper.
     * @return ContainerBuilder Value produced by the method.
     */
    private function loadExtension(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('logger', new Definition(NullLogger::class));
        $extension = new StrandsExtension();
        $extension->load([$config], $container);

        return $container;
    }

    /**
     * Verifies that registers factory service.
     *
     * @return void
     */
    public function testRegistersFactoryService(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $this->assertTrue($container->hasDefinition('strands.client_factory'));

        $factoryDef = $container->getDefinition('strands.client_factory');
        $this->assertSame(StrandsClientFactory::class, $factoryDef->getClass());
    }

    /**
     * Verifies that registers named agent services.
     *
     * @return void
     */
    public function testRegistersNamedAgentServices(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8000'],
                'strategist' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $this->assertTrue($container->hasDefinition('strands.client.analyst'));
        $this->assertTrue($container->hasDefinition('strands.client.skeptic'));
        $this->assertTrue($container->hasDefinition('strands.client.strategist'));
    }

    /**
     * Verifies that first agent is default alias.
     *
     * @return void
     */
    public function testFirstAgentIsDefaultAlias(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $this->assertTrue($container->hasAlias(StrandsClient::class));
        $alias = $container->getAlias(StrandsClient::class);
        $this->assertSame('strands.client.analyst', (string) $alias);
    }

    /**
     * Verifies that empty agents registers nothing.
     *
     * @return void
     */
    public function testEmptyAgentsRegistersNothing(): void
    {
        $container = $this->loadExtension([
            'agents' => [],
        ]);

        $this->assertFalse($container->hasDefinition('strands.client_factory'));
    }

    /**
     * Verifies that agent service uses factory.
     *
     * @return void
     */
    public function testAgentServiceUsesFactory(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'primary' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $def = $container->getDefinition('strands.client.primary');
        $factory = $def->getFactory();

        $this->assertIsArray($factory);
        $this->assertSame('create', $factory[1]);
    }

    /**
     * Verifies that factory receives agents argument.
     *
     * @return void
     */
    public function testFactoryReceivesAgentsArgument(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $factoryDef = $container->getDefinition('strands.client_factory');
        $agentsArg = $factoryDef->getArgument('$agents');

        $this->assertIsArray($agentsArg);
        $this->assertArrayHasKey('analyst', $agentsArg);
        $this->assertSame('http://agent:8000', $agentsArg['analyst']['endpoint']);
    }

    /**
     * Verifies that factory receives logger argument.
     *
     * @return void
     */
    public function testFactoryReceivesLoggerArgument(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $factoryDef = $container->getDefinition('strands.client_factory');
        $loggerArg = $factoryDef->getArgument('$logger');

        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Reference::class, $loggerArg);
        $this->assertSame('logger', (string) $loggerArg);
    }

    /**
     * Verifies that agent service receives name argument.
     *
     * @return void
     */
    public function testAgentServiceReceivesNameArgument(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $def = $container->getDefinition('strands.client.analyst');
        $this->assertSame('analyst', $def->getArgument(0));
    }

    /**
     * Verifies that factory receives middleware argument.
     *
     * @return void
     */
    public function testFactoryReceivesMiddlewareArgument(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $factoryDef = $container->getDefinition('strands.client_factory');
        $middlewareArg = $factoryDef->getArgument('$middleware');

        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument::class, $middlewareArg);
    }

    /**
     * Verifies that request middleware autoconfigured.
     *
     * @return void
     */
    public function testRequestMiddlewareAutoconfigured(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $autoconfigured = $container->getAutoconfiguredInstanceof();
        $this->assertArrayHasKey(\StrandsPhpClient\Http\RequestMiddleware::class, $autoconfigured);
    }

    /**
     * Verifies that multiple agents each get correct name.
     *
     * @return void
     */
    public function testMultipleAgentsEachGetCorrectName(): void
    {
        $container = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8001'],
            ],
        ]);

        $this->assertSame('analyst', $container->getDefinition('strands.client.analyst')->getArgument(0));
        $this->assertSame('skeptic', $container->getDefinition('strands.client.skeptic')->getArgument(0));
    }
}
