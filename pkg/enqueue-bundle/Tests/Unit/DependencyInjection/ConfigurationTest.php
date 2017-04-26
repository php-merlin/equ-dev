<?php

namespace Enqueue\Bundle\Tests\Unit\DependencyInjection;

use Enqueue\Bundle\DependencyInjection\Configuration;
use Enqueue\Bundle\Tests\Unit\Mocks\FooTransportFactory;
use Enqueue\Symfony\DefaultTransportFactory;
use Enqueue\Null\Symfony\NullTransportFactory;
use Enqueue\Test\ClassExtensionTrait;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    use ClassExtensionTrait;

    public function testShouldImplementConfigurationInterface()
    {
        $this->assertClassImplements(ConfigurationInterface::class, Configuration::class);
    }

    public function testCouldBeConstructedWithFactoriesAsFirstArgument()
    {
        new Configuration([]);
    }

    public function testThrowIfTransportNotConfigured()
    {
        $this->setExpectedException(
            InvalidConfigurationException::class,
            'The child node "transport" at path "enqueue" must be configured.'
        );

        $configuration = new Configuration([]);

        $processor = new Processor();
        $processor->processConfiguration($configuration, [[]]);
    }

    public function testShouldInjectFooTransportFactoryConfig()
    {
        $configuration = new Configuration([new FooTransportFactory()]);

        $processor = new Processor();
        $processor->processConfiguration($configuration, [[
            'transport' => [
                'foo' => [
                    'foo_param' => 'aParam',
                ],
            ],
        ]]);
    }

    public function testThrowExceptionIfFooTransportConfigInvalid()
    {
        $configuration = new Configuration([new FooTransportFactory()]);

        $processor = new Processor();

        $this->setExpectedException(
            InvalidConfigurationException::class,
            'The path "enqueue.transport.foo.foo_param" cannot contain an empty value, but got null.'
        );

        $processor->processConfiguration($configuration, [[
            'transport' => [
                'foo' => [
                    'foo_param' => null,
                ],
            ],
        ]]);
    }

    public function testShouldAllowConfigureDefaultTransport()
    {
        $configuration = new Configuration([new DefaultTransportFactory()]);

        $processor = new Processor();
        $processor->processConfiguration($configuration, [[
            'transport' => [
                'default' => ['alias' => 'foo'],
            ],
        ]]);
    }

    public function testShouldAllowConfigureNullTransport()
    {
        $configuration = new Configuration([new NullTransportFactory()]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [
                'null' => true,
            ],
        ]]);

        $this->assertArraySubset([
            'transport' => [
                'null' => [],
            ],
        ], $config);
    }

    public function testShouldAllowConfigureSeveralTransportsSameTime()
    {
        $configuration = new Configuration([
            new NullTransportFactory(),
            new DefaultTransportFactory(),
            new FooTransportFactory(),
        ]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [
                'default' => 'foo',
                'null' => true,
                'foo' => ['foo_param' => 'aParam'],
            ],
        ]]);

        $this->assertArraySubset([
            'transport' => [
                'default' => ['alias' => 'foo'],
                'null' => [],
                'foo' => ['foo_param' => 'aParam'],
            ],
        ], $config);
    }

    public function testShouldSetDefaultConfigurationForClient()
    {
        $configuration = new Configuration([new DefaultTransportFactory()]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [
                'default' => ['alias' => 'foo'],
            ],
            'client' => null,
        ]]);

        $this->assertArraySubset([
            'transport' => [
                'default' => ['alias' => 'foo'],
            ],
            'client' => [
                'prefix' => 'enqueue',
                'app_name' => 'app',
                'router_processor' => 'enqueue.client.router_processor',
                'router_topic' => 'router',
                'router_queue' => 'default',
                'default_processor_queue' => 'default',
                'traceable_producer' => false,
                'redelivered_delay_time' => 0,
            ],
        ], $config);
    }

    public function testThrowExceptionIfRouterTopicIsEmpty()
    {
        $this->setExpectedException(
            InvalidConfigurationException::class,
            'The path "enqueue.client.router_topic" cannot contain an empty value, but got "".'
        );

        $configuration = new Configuration([new DefaultTransportFactory()]);

        $processor = new Processor();
        $processor->processConfiguration($configuration, [[
            'transport' => [
                'default' => ['alias' => 'foo'],
            ],
            'client' => [
                'router_topic' => '',
            ],
        ]]);
    }

    public function testThrowExceptionIfRouterQueueIsEmpty()
    {
        $this->setExpectedException(
            InvalidConfigurationException::class,
            'The path "enqueue.client.router_queue" cannot contain an empty value, but got "".'
        );

        $configuration = new Configuration([new DefaultTransportFactory()]);

        $processor = new Processor();
        $processor->processConfiguration($configuration, [[
            'transport' => [
                'default' => ['alias' => 'foo'],
            ],
            'client' => [
                'router_queue' => '',
            ],
        ]]);
    }

    public function testShouldThrowExceptionIfDefaultProcessorQueueIsEmpty()
    {
        $configuration = new Configuration([new DefaultTransportFactory()]);

        $processor = new Processor();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The path "enqueue.client.default_processor_queue" cannot contain an empty value, but got "".');
        $processor->processConfiguration($configuration, [[
            'transport' => [
                'default' => ['alias' => 'foo'],
            ],
            'client' => [
                'default_processor_queue' => '',
            ],
        ]]);
    }

    public function testJobShouldBeDisabledByDefault()
    {
        $configuration = new Configuration([]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [],
        ]]);

        $this->assertArraySubset([
            'job' => false,
        ], $config);
    }

    public function testCouldEnableJob()
    {
        $configuration = new Configuration([]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [],
            'job' => true,
        ]]);

        $this->assertArraySubset([
            'job' => true,
        ], $config);
    }

    public function testDoctrinePingConnectionExtensionShouldBeDisabledByDefault()
    {
        $configuration = new Configuration([]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [],
        ]]);

        $this->assertArraySubset([
            'extensions' => [
                'doctrine_ping_connection_extension' => false,
            ],
        ], $config);
    }

    public function testDoctrinePingConnectionExtensionCouldBeEnabled()
    {
        $configuration = new Configuration([]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [],
            'extensions' => [
                'doctrine_ping_connection_extension' => true,
            ],
        ]]);

        $this->assertArraySubset([
            'extensions' => [
                'doctrine_ping_connection_extension' => true,
            ],
        ], $config);
    }

    public function testDoctrineClearIdentityMapExtensionShouldBeDisabledByDefault()
    {
        $configuration = new Configuration([]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [],
        ]]);

        $this->assertArraySubset([
            'extensions' => [
                'doctrine_clear_identity_map_extension' => false,
            ],
        ], $config);
    }

    public function testDoctrineClearIdentityMapExtensionCouldBeEnabled()
    {
        $configuration = new Configuration([]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [],
            'extensions' => [
                'doctrine_clear_identity_map_extension' => true,
            ],
        ]]);

        $this->assertArraySubset([
            'extensions' => [
                'doctrine_clear_identity_map_extension' => true,
            ],
        ], $config);
    }

    public function testSignalExtensionShouldBeEnabledByDefault()
    {
        $configuration = new Configuration([]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [],
        ]]);

        $this->assertArraySubset([
            'extensions' => [
                'signal_extension' => true,
            ],
        ], $config);
    }

    public function testSignalExtensionCouldBeDisabled()
    {
        $configuration = new Configuration([]);

        $processor = new Processor();
        $config = $processor->processConfiguration($configuration, [[
            'transport' => [],
            'extensions' => [
                'signal_extension' => false,
            ],
        ]]);

        $this->assertArraySubset([
            'extensions' => [
                'signal_extension' => false,
            ],
        ], $config);
    }
}
