<?php

namespace Enqueue\Symfony;

use Enqueue\AmqpBunny\AmqpConnectionFactory as AmqpBunnyConnectionFactory;
use Enqueue\AmqpExt\AmqpConnectionFactory as AmqpExtConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as AmqpLibConnectionFactory;
use Enqueue\Client\Amqp\AmqpDriver;
use Interop\Amqp\AmqpConnectionFactory;
use Interop\Amqp\AmqpContext;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use function Enqueue\dsn_to_connection_factory;

class AmqpTransportFactory implements TransportFactoryInterface, DriverFactoryInterface
{
    /**
     * @var string
     */
    private $name;

    /**
     * @param string $name
     */
    public function __construct($name = 'amqp')
    {
        $this->name = $name;
    }

    /**
     * {@inheritdoc}
     */
    public function addConfiguration(ArrayNodeDefinition $builder)
    {
        $drivers = [];
        if (class_exists(AmqpExtConnectionFactory::class)) {
            $drivers[] = 'ext';
        }
        if (class_exists(AmqpLibConnectionFactory::class)) {
            $drivers[] = 'lib';
        }
        if (class_exists(AmqpBunnyConnectionFactory::class)) {
            $drivers[] = 'bunny';
        }

        $builder
            ->beforeNormalization()
                ->ifEmpty()
                ->then(function ($v) {
                    return ['dsn' => 'amqp:'];
                })
                ->ifString()
                ->then(function ($v) {
                    return ['dsn' => $v];
                })
            ->end()
            ->children()
                ->enumNode('driver')
                    ->values($drivers)
                ->end()
                ->scalarNode('dsn')
                    ->info('The connection to AMQP broker set as a string. Other parameters could be used as defaults')
                ->end()
                ->scalarNode('host')
                    ->info('The host to connect too. Note: Max 1024 characters')
                ->end()
                ->scalarNode('port')
                    ->info('Port on the host.')
                ->end()
                ->scalarNode('user')
                    ->info('The user name to use. Note: Max 128 characters.')
                ->end()
                ->scalarNode('pass')
                    ->info('Password. Note: Max 128 characters.')
                ->end()
                ->scalarNode('vhost')
                    ->info('The virtual host on the host. Note: Max 128 characters.')
                ->end()
                ->floatNode('connection_timeout')
                    ->min(0)
                    ->info('Connection timeout. Note: 0 or greater seconds. May be fractional.')
                ->end()
                ->floatNode('read_timeout')
                    ->min(0)
                    ->info('Timeout in for income activity. Note: 0 or greater seconds. May be fractional.')
                ->end()
                ->floatNode('write_timeout')
                    ->min(0)
                    ->info('Timeout in for outcome activity. Note: 0 or greater seconds. May be fractional.')
                ->end()
                ->floatNode('heartbeat')
                    ->min(0)
                    ->info('How often to send heartbeat. 0 means off.')
                ->end()
                ->booleanNode('persisted')->end()
                ->booleanNode('lazy')->end()
                ->enumNode('receive_method')
                    ->values(['basic_get', 'basic_consume'])
                    ->info('The receive strategy to be used. We suggest to use basic_consume as it is more performant. Though you need AMQP extension 1.9.1 or higher')
                ->end()
                ->floatNode('qos_prefetch_size')
                    ->min(0)
                    ->info('The server will send a message in advance if it is equal to or smaller in size than the available prefetch size. May be set to zero, meaning "no specific limit"')
                ->end()
                ->floatNode('qos_prefetch_count')
                    ->min(0)
                    ->info('Specifies a prefetch window in terms of whole messages')
                ->end()
                ->booleanNode('qos_global')
                    ->info('If "false" the QoS settings apply to the current channel only. If this field is "true", they are applied to the entire connection.')
                ->end()
                ->variableNode('driver_options')
                    ->info('The options that are specific to the amqp transport you chose. For example amqp+lib have insist, keepalive, stream options. amqp+bunny has tcp_nodelay extra option.')
                ->end()
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function createConnectionFactory(ContainerBuilder $container, array $config)
    {
        if (array_key_exists('driver_options', $config) && is_array($config['driver_options'])) {
            $driverOptions = $config['driver_options'];
            unset($config['driver_options']);

            $config = array_replace($driverOptions, $config);
        }

        $factory = new Definition(AmqpConnectionFactory::class);
        $factory->setFactory([self::class, 'createConnectionFactoryFactory']);
        $factory->setArguments([$config]);

        $factoryId = sprintf('enqueue.transport.%s.connection_factory', $this->getName());
        $container->setDefinition($factoryId, $factory);

        return $factoryId;
    }

    /**
     * {@inheritdoc}
     */
    public function createContext(ContainerBuilder $container, array $config)
    {
        $factoryId = sprintf('enqueue.transport.%s.connection_factory', $this->getName());

        $context = new Definition(AmqpContext::class);
        $context->setFactory([new Reference($factoryId), 'createContext']);

        $contextId = sprintf('enqueue.transport.%s.context', $this->getName());
        $container->setDefinition($contextId, $context);

        return $contextId;
    }

    /**
     * {@inheritdoc}
     */
    public function createDriver(ContainerBuilder $container, array $config)
    {
        $driver = new Definition(AmqpDriver::class);
        $driver->setArguments([
            new Reference(sprintf('enqueue.transport.%s.context', $this->getName())),
            new Reference('enqueue.client.config'),
            new Reference('enqueue.client.meta.queue_meta_registry'),
        ]);

        $driverId = sprintf('enqueue.client.%s.driver', $this->getName());
        $container->setDefinition($driverId, $driver);

        return $driverId;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    public static function createConnectionFactoryFactory(array $config)
    {
        if (array_key_exists('driver', $config)) {
            if ('ext' == $config['driver']) {
                return new AmqpExtConnectionFactory($config);
            }
            if ('lib' == $config['driver']) {
                return new AmqpLibConnectionFactory($config);
            }
            if ('bunny' == $config['driver']) {
                return new AmqpBunnyConnectionFactory($config);
            }

            throw new \LogicException(sprintf('Unexpected driver given "%s"', $config['driver']));
        }

        $dsn = array_key_exists('dsn', $config) ? $config['dsn'] : 'amqp:';
        $factory = dsn_to_connection_factory($dsn);

        if (false == $factory instanceof  AmqpConnectionFactory) {
            throw new \LogicException(sprintf('Factory must be instance of "%s" but got "%s"', AmqpConnectionFactory::class, get_class($factory)));
        }

        $factoryClass = get_class($factory);

        return new $factoryClass($config);
    }
}
