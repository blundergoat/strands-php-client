<?php

declare(strict_types=1);

/**
 * Exercises caller-visible Strands Extension behavior for app integrations.
 *
 * Use this file when changing Strands Extension or its integration boundary.
 * It protects the request, UI update, or failure an application user sees.
 */

namespace StrandsPhpClient\Tests\Unit\Integration\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsClientFactory;
use StrandsPhpClient\Integration\Symfony\DependencyInjection\StrandsExtension;
use StrandsPhpClient\StrandsClient;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Exercises Strands Extension through the public surface used by application code.
 *
 * Use these tests when changing the feature or its integration boundary.
 * They protect the request, UI update, or failure an application user sees.
 */
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
     * Confirms registers factory service so framework users receive a correctly configured client.
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
     * Confirms registers named agent services so framework users receive a correctly configured client.
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
     * Confirms named clients stay retrievable via `$container->get()` after compile so framework users receive a correctly configured client.
     *
     * @return void
     */
    public function testNamedAgentServicesArePublic(): void
    {
        $containerBuilder = $this->loadExtension([
            'agents' => [
                'analyst' => ['endpoint' => 'http://agent:8000'],
            ],
        ]);

        // Application docs promise direct access to a named client from Symfony's container.
        // A public definition prevents compilation from inlining or removing that caller-visible service.
        $this->assertTrue($containerBuilder->getDefinition('strands.client.analyst')->isPublic());
    }

    /**
     * Confirms first agent is default alias so framework users receive a correctly configured client.
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
     * Confirms empty agents registers nothing so framework users receive a correctly configured client.
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
     * Confirms agent service uses factory so framework users receive a correctly configured client.
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
     * Confirms factory receives agents argument so framework users receive a correctly configured client.
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
     * Confirms factory receives logger argument so framework users receive a correctly configured client.
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
     * Confirms agent service receives name argument so framework users receive a correctly configured client.
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
     * Confirms factory receives middleware argument so framework users receive a correctly configured client.
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
     * Confirms request middleware is autoconfigured so framework users receive the expected request behavior.
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
     * Confirms each configured agent keeps its name so framework users can resolve the intended assistant.
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
