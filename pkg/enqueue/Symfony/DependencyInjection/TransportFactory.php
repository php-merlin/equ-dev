<?php

namespace Enqueue\Symfony\DependencyInjection;

use Enqueue\ConnectionFactoryFactory;
use Enqueue\ConnectionFactoryFactoryInterface;
use Enqueue\Consumption\ChainExtension;
use Enqueue\Consumption\Extension\LogExtension;
use Enqueue\Consumption\QueueConsumer;
use Enqueue\Consumption\QueueConsumerInterface;
use Enqueue\Resources;
use Enqueue\Rpc\RpcClient;
use Enqueue\Rpc\RpcFactory;
use Enqueue\Symfony\ContainerProcessorRegistry;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Context;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
final class TransportFactory
{
    use FormatTransportNameTrait;

    /**
     * @var string
     */
    private $name;

    /**
     * @var bool
     */
    private $default;

    public function __construct(string $name, bool $default = false)
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('The name could not be empty.');
        }

        $this->name = $name;
        $this->default = $default;
    }

    public static function getConfiguration(string $name = 'transport'): NodeDefinition
    {
        $knownSchemes = array_keys(Resources::getKnownSchemes());
        $availableSchemes = array_keys(Resources::getAvailableSchemes());

        $builder = new ArrayNodeDefinition($name);
        $builder
            ->info('The transport option could accept a string DSN, an array with DSN key, or null. It accept extra options. To find out what option you can set, look at connection factory constructor docblock.')
            ->beforeNormalization()
                ->always(function ($v) {
                    if (empty($v)) {
                        return ['dsn' => 'null:'];
                    }

                    if (is_array($v)) {
                        if (isset($v['factory_class']) && isset($v['factory_service'])) {
                            throw new \LogicException('Both options factory_class and factory_service are set. Please choose one.');
                        }

                        if (isset($v['connection_factory_class']) && (isset($v['factory_class']) || isset($v['factory_service']))) {
                            throw new \LogicException('The option connection_factory_class must not be used with factory_class or factory_service at the same time. Please choose one.');
                        }

                        return $v;
                    }

                    if (is_string($v)) {
                        return ['dsn' => $v];
                    }

                    throw new \LogicException(sprintf('The value must be array, null or string. Got "%s"', gettype($v)));
                })
        ->end()
        ->isRequired()
        ->ignoreExtraKeys(false)
        ->children()
            ->scalarNode('dsn')
                ->cannotBeEmpty()
                ->isRequired()
                ->info(sprintf(
                    'The MQ broker DSN. These schemes are supported: "%s", to use these "%s" you have to install a package.',
                    implode('", "', $knownSchemes),
                    implode('", "', $availableSchemes)
                ))
            ->end()
            ->scalarNode('connection_factory_class')
                ->info(sprintf('The connection factory class should implement "%s" interface', ConnectionFactory::class))
            ->end()
            ->scalarNode('factory_service')
                ->info(sprintf('The factory class should implement "%s" interface', ConnectionFactoryFactoryInterface::class))
            ->end()
            ->scalarNode('factory_class')
                ->info(sprintf('The factory service should be a class that implements "%s" interface', ConnectionFactoryFactoryInterface::class))
            ->end()
        ->end()
        ;

        return $builder;
    }

    public static function getQueueConsumerConfiguration(string $name = 'consumption'): ArrayNodeDefinition
    {
        $builder = new ArrayNodeDefinition($name);

        $builder
            ->addDefaultsIfNotSet()->children()
                ->integerNode('receive_timeout')
                    ->min(0)
                    ->defaultValue(10000)
                    ->info('the time in milliseconds queue consumer waits for a message (100 ms by default)')
                ->end()
        ;

        return $builder;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function buildConnectionFactory(ContainerBuilder $container, array $config): void
    {
        $factoryId = $this->format('connection_factory');

        $factoryFactoryId = $this->format('connection_factory_factory');
        $container->register($factoryFactoryId, $config['factory_class'] ?? ConnectionFactoryFactory::class);

        $factoryFactoryService = new Reference(
            array_key_exists('factory_service', $config) ? $config['factory_service'] : $factoryFactoryId
        );

        unset($config['factory_service'], $config['factory_class']);

        if (array_key_exists('connection_factory_class', $config)) {
            $connectionFactoryClass = $config['connection_factory_class'];
            unset($config['connection_factory_class']);

            $container->register($factoryId, $connectionFactoryClass)
                ->addArgument($config)
            ;
        } else {
            $container->register($factoryId, ConnectionFactory::class)
                ->setFactory([$factoryFactoryService, 'create'])
                ->addArgument($config)
            ;
        }

        if ($this->default) {
            $container->setAlias(ConnectionFactory::class, $this->format('connection_factory'));
        }
    }

    public function buildContext(ContainerBuilder $container, array $config): void
    {
        $factoryId = $this->format('connection_factory');
        $this->assertServiceExists($container, $factoryId);

        $contextId = $this->format('context');

        $container->register($contextId, Context::class)
            ->setFactory([new Reference($factoryId), 'createContext'])
        ;

        $this->addServiceToLocator($container, 'context');

        if ($this->default) {
            $container->setAlias(Context::class, $this->format('context'));
        }
    }

    public function buildQueueConsumer(ContainerBuilder $container, array $config): void
    {
        $contextId = $this->format('context');
        $this->assertServiceExists($container, $contextId);

        $container->setParameter($this->format('receive_timeout'), $config['receive_timeout'] ?? 10000);

        $logExtensionId = $this->format('log_extension');
        $container->register($logExtensionId, LogExtension::class)
            ->addTag('enqueue.transport.consumption_extension', ['transport' => $this->name, 'priority' => -100])
        ;

        $container->register($this->format('consumption_extensions'), ChainExtension::class)
            ->addArgument([])
        ;

        $container->register($this->format('queue_consumer'), QueueConsumer::class)
            ->addArgument(new Reference($contextId))
            ->addArgument(new Reference($this->format('consumption_extensions')))
            ->addArgument([])
            ->addArgument(new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addArgument($this->parameter('receive_timeout'))
        ;

        $container->register($this->format('processor_registry'), ContainerProcessorRegistry::class);

        $this->addServiceToLocator($container, 'queue_consumer');
        $this->addServiceToLocator($container, 'processor_registry');

        if ($this->default) {
            $container->setAlias(QueueConsumerInterface::class, $this->format('queue_consumer'));
        }
    }

    public function buildRpcClient(ContainerBuilder $container, array $config): void
    {
        $contextId = $this->format('context');
        $this->assertServiceExists($container, $contextId);

        $container->register($this->format('rpc_factory'), RpcFactory::class)
            ->addArgument(new Reference($contextId))
        ;

        $container->register($this->format('rpc_client'), RpcClient::class)
            ->addArgument(new Reference($contextId))
            ->addArgument(new Reference($this->format('rpc_factory')))
        ;

        if ($this->default) {
            $container->setAlias(RpcClient::class, $this->format('rpc_client'));
        }
    }

    private function assertServiceExists(ContainerBuilder $container, string $serviceId): void
    {
        if (false == $container->hasDefinition($serviceId)) {
            throw new \InvalidArgumentException(sprintf('The service "%s" does not exist.', $serviceId));
        }
    }

    private function addServiceToLocator(ContainerBuilder $container, string $serviceName): void
    {
        $locatorId = 'enqueue.locator';

        if ($container->hasDefinition($locatorId)) {
            $locator = $container->getDefinition($locatorId);

            $map = $locator->getArgument(0);
            $map[$this->format($serviceName)] = $this->reference($serviceName);

            $locator->replaceArgument(0, $map);
        }
    }
}
