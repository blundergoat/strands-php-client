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
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition('logger', new Definition(NullLogger::class));
        $strandsExtension = new StrandsExtension();
        $strandsExtension->load([$config], $containerBuilder);

        return $containerBuilder;
    }

    /**
     * Verifies that registers factory service.
     *
     * @return void
     */
    public function testRegistersFactoryService(): void
    {
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $this->assertTrue($containerBuilder->hasDefinition('strands.client_factory'));

        $factoryDef = $containerBuilder->getDefinition('strands.client_factory');
        $this->assertSame(StrandsClientFactory::class, $factoryDef->getClass());
    }

    /**
     * Verifies that registers named agent services.
     *
     * @return void
     */
    public function testRegistersNamedAgentServices(): void
    {
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8000'],
                'strategist' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $this->assertTrue($containerBuilder->hasDefinition('strands.client.analyst'));
        $this->assertTrue($containerBuilder->hasDefinition('strands.client.skeptic'));
        $this->assertTrue($containerBuilder->hasDefinition('strands.client.strategist'));
    }

    /**
     * Verifies that first agent is default alias.
     *
     * @return void
     */
    public function testFirstAgentIsDefaultAlias(): void
    {
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $this->assertTrue($containerBuilder->hasAlias(StrandsClient::class));
        $alias = $containerBuilder->getAlias(StrandsClient::class);
        $this->assertSame('strands.client.analyst', (string) $alias);
    }

    /**
     * Verifies that empty agents registers nothing.
     *
     * @return void
     */
    public function testEmptyAgentsRegistersNothing(): void
    {
        $containerBuilder = $this->loadExtension([
            'agents' => [],
        ]);

        $this->assertFalse($containerBuilder->hasDefinition('strands.client_factory'));
    }

    /**
     * Verifies that agent service uses factory.
     *
     * @return void
     */
    public function testAgentServiceUsesFactory(): void
    {
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'primary' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $definition = $containerBuilder->getDefinition('strands.client.primary');
        $factory = $definition->getFactory();

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
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $factoryDef = $containerBuilder->getDefinition('strands.client_factory');
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
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $factoryDef = $containerBuilder->getDefinition('strands.client_factory');
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
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $definition = $containerBuilder->getDefinition('strands.client.analyst');
        $this->assertSame('analyst', $definition->getArgument(0));
    }

    /**
     * Verifies that factory receives middleware argument.
     *
     * @return void
     */
    public function testFactoryReceivesMiddlewareArgument(): void
    {
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $factoryDef = $containerBuilder->getDefinition('strands.client_factory');
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
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $autoconfigured = $containerBuilder->getAutoconfiguredInstanceof();
        $this->assertArrayHasKey(\StrandsPhpClient\Http\RequestMiddleware::class, $autoconfigured);
    }

    /**
     * Verifies that multiple agents each get correct name.
     *
     * @return void
     */
    public function testMultipleAgentsEachGetCorrectName(): void
    {
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8001'],
            ],
        ]);

        $this->assertSame('analyst', $containerBuilder->getDefinition('strands.client.analyst')->getArgument(0));
        $this->assertSame('skeptic', $containerBuilder->getDefinition('strands.client.skeptic')->getArgument(0));
    }
}
