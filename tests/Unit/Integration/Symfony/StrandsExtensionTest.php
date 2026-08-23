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

/**
 * Verifies the Symfony extension registers the factory, named clients, aliases, logger, and middleware wiring.
 *
 * Use these tests when changing dependency-injection service definitions or autoconfiguration.
 * They protect the client service an application receives for each configured agent.
 */
class StrandsExtensionTest extends TestCase
{
    /**
     * Loads one Strands configuration into a fresh Symfony container builder.
     * Use it to inspect the services an application receives after extension registration.
     *
     * @param array<string, mixed> $config Bundle settings; empty represents an app with no configured agents.
     * @return ContainerBuilder Loaded container; never null and possibly free of named clients when agents are empty.
     */
    private function containerWithExtensionConfig(array $config): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setDefinition('logger', new Definition(NullLogger::class));
        $strandsExtension = new StrandsExtension();
        $strandsExtension->load([$config], $containerBuilder);

        return $containerBuilder;
    }

    /**
     * Confirms the extension registers the client factory so Symfony can build named agent services.
     *
     * @return void
     */
    public function testRegistersFactoryService(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $this->assertTrue($containerBuilder->hasDefinition('strands.client_factory'));

        $factoryDef = $containerBuilder->getDefinition('strands.client_factory');
        $this->assertSame(StrandsClientFactory::class, $factoryDef->getClass());
    }

    /**
     * Confirms the extension registers one service for each configured agent name.
     *
     * @return void
     */
    public function testRegistersNamedAgentServices(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
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
     * Confirms named clients stay retrievable via `$container->get()` after compile so Symfony apps resolve configured agent services predictably.
     *
     * @return void
     */
    public function testNamedAgentServicesArePublic(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        // Application docs promise direct access to a named client from Symfony's container.
        // A public definition prevents compilation from inlining or removing that caller-visible service.
        $this->assertTrue($containerBuilder->getDefinition('strands.client.analyst')->isPublic());
    }

    /**
     * Confirms first agent is default alias so Symfony apps resolve configured agent services predictably.
     *
     * @return void
     */
    public function testFirstAgentIsDefaultAlias(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
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
     * Confirms an empty agent map registers no named services or misleading default alias.
     *
     * @return void
     */
    public function testEmptyAgentsRegistersNothing(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
            'agents' => [],
        ]);

        $this->assertFalse($containerBuilder->hasDefinition('strands.client_factory'));
    }

    /**
     * Confirms each named agent service uses the client factory before application code resolves it.
     *
     * @return void
     */
    public function testAgentServiceUsesFactory(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
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
     * Confirms the client factory receives every configured agent definition.
     *
     * @return void
     */
    public function testFactoryReceivesAgentsArgument(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
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
     * Confirms the client factory receives Symfony's logger for operator-visible diagnostics.
     *
     * @return void
     */
    public function testFactoryReceivesLoggerArgument(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
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
     * Confirms each named service asks the factory for its own configured agent.
     *
     * @return void
     */
    public function testAgentServiceReceivesNameArgument(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $definition = $containerBuilder->getDefinition('strands.client.analyst');
        $this->assertSame('analyst', $definition->getArgument(0));
    }

    /**
     * Confirms the client factory receives tagged middleware in application order.
     *
     * @return void
     */
    public function testFactoryReceivesMiddlewareArgument(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $factoryDef = $containerBuilder->getDefinition('strands.client_factory');
        $middlewareArg = $factoryDef->getArgument('$middleware');

        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument::class, $middlewareArg);
    }

    /**
     * Confirms request middleware is autoconfigured so framework users receive the expected request behavior.
     *
     * @return void
     */
    public function testRequestMiddlewareAutoconfigured(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        $autoconfigured = $containerBuilder->getAutoconfiguredInstanceof();
        $this->assertArrayHasKey(\StrandsPhpClient\Http\RequestMiddleware::class, $autoconfigured);
    }

    /**
     * Confirms each configured agent keeps its name so framework users can resolve the intended assistant.
     *
     * @return void
     */
    public function testMultipleAgentsEachGetCorrectName(): void
    {
        $containerBuilder = $this->containerWithExtensionConfig([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
                'skeptic' => ['endpoint' => 'http://agent:8001'],
            ],
        ]);

        $this->assertSame('analyst', $containerBuilder->getDefinition('strands.client.analyst')->getArgument(0));
        $this->assertSame('skeptic', $containerBuilder->getDefinition('strands.client.skeptic')->getArgument(0));
    }
}
