<?php
namespace Enqueue\Client;

use Enqueue\AmqpExt\Symfony\AmqpTransportFactory;
use Enqueue\AmqpExt\Symfony\RabbitMqAmqpTransportFactory;
use Enqueue\Client\Meta\QueueMetaRegistry;
use Enqueue\Client\Meta\TopicMetaRegistry;
use Enqueue\Consumption\CallbackProcessor;
use Enqueue\Consumption\ExtensionInterface;
use Enqueue\Consumption\QueueConsumer;
use Enqueue\Psr\PsrContext;
use Enqueue\Symfony\DefaultTransportFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Experimental class. Use it speedup setup process and learning but consider to switch to custom solution (build your own client).
 */
final class SimpleClient
{
    /**
     * @var ContainerBuilder
     */
    private $container;

    /**
     * $config = [
     *   'transport' => [
     *     'rabbitmq_amqp' => [],
     *     'amqp'          => [],
     *     ....
     *   ],
     *   'client' => [
     *     'prefix'                   => 'enqueue',
     *     'app_name'                 => 'app',
     *     'router_topic'             => 'router',
     *     'router_queue'             => 'default',
     *     'default_processor_queue'  => 'default',
     *     'redelivered_delay_time'   => 0
     *   ],
     *   'extensions' => [
     *     'signal_extension' => true,
     *   ]
     * ]
     *
     *
     * @param string|array $config
     */
    public function __construct($config)
    {
        $this->container = $this->buildContainer($config);
    }

    /**
     * @param array|string $config
     *
     * @return ContainerBuilder
     */
    private function buildContainer($config)
    {
        $config = $this->buildConfig($config);
        $extension = $this->buildContainerExtension($config);

        $container = new ContainerBuilder();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        $container->compile();

        return $container;
    }

    /**
     * @param array $config
     *
     * @return SimpleClientContainerExtension
     */
    private function buildContainerExtension($config)
    {
        $map = [
            'default' => DefaultTransportFactory::class,
            'amqp' => AmqpTransportFactory::class,
            'rabbitmq_amqp' => RabbitMqAmqpTransportFactory::class,
        ];

        $extension = new SimpleClientContainerExtension();

        foreach (array_keys($config['transport']) as $transport) {
            if (false == isset($map[$transport])) {
                throw new \LogicException(sprintf('Transport is not supported: "%s"', $transport));
            }

            $extension->addTransportFactory(new $map[$transport]);
        }

        return $extension;
    }

    /**
     * @param array|string $config
     *
     * @return array
     */
    private function buildConfig($config)
    {
        if (is_string($config)) {
            $extConfig = [
                'client' => [],
                'transport' => [
                    'default' => $config,
                    $config => [],
                ],
            ];
        } elseif (is_array($config)) {
            $extConfig = array_merge_recursive([
                'client' => [],
                'transport' => [],
            ], $config);

            $transport = current(array_keys($extConfig['transport']));

            if (false == $transport) {
                throw new \LogicException('There is no transport configured');
            }

            $extConfig['transport']['default'] = $transport;
        } else {
            throw new \LogicException('Expects config is string or array');
        }

        return $extConfig;
    }

    /**
     * @param string   $topic
     * @param string   $processorName
     * @param callback $processor
     */
    public function bind($topic, $processorName, callable $processor)
    {
        $queueName = $this->getConfig()->getDefaultProcessorQueueName();

        $this->getTopicMetaRegistry()->addProcessor($topic, $processorName);
        $this->getQueueMetaRegistry()->addProcessor($queueName, $processorName);
        $this->getProcessorRegistry()->add($processorName, new CallbackProcessor($processor));
        $this->getRouterProcessor()->add($topic, $queueName, $processorName);
    }

    /**
     * @param string       $topic
     * @param string|array $message
     * @param bool         $setupBroker
     */
    public function send($topic, $message, $setupBroker = false)
    {
        $this->getProducer($setupBroker)->send($topic, $message);
    }

    /**
     * @param ExtensionInterface|null $runtimeExtension
     */
    public function consume(ExtensionInterface $runtimeExtension = null)
    {
        $this->setupBroker();
        $processor = $this->getDelegateProcessor();
        $queueConsumer = $this->getQueueConsumer();

        $defaultQueueName = $this->getConfig()->getDefaultProcessorQueueName();
        $defaultTransportQueueName = $this->getConfig()->createTransportQueueName($defaultQueueName);

        $queueConsumer->bind($defaultTransportQueueName, $processor);
        if ($this->getConfig()->getRouterQueueName() != $defaultQueueName) {
            $routerTransportQueueName = $this->getConfig()->createTransportQueueName($this->getConfig()->getRouterQueueName());

            $queueConsumer->bind($routerTransportQueueName, $processor);
        }

        $queueConsumer->consume($runtimeExtension);
    }

    /**
     * @return PsrContext
     */
    public function getContext()
    {
       return $this->container->get('enqueue.transport.context');
    }

    /**
     * @return QueueConsumer
     */
    public function getQueueConsumer()
    {
        return $this->container->get('enqueue.client.queue_consumer');
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->container->get('enqueue.client.config');
    }

    /**
     * @return DriverInterface
     */
    public function getDriver()
    {
        return $this->container->get('enqueue.client.driver');
    }

    /**
     * @return TopicMetaRegistry
     */
    public function getTopicMetaRegistry()
    {
        return $this->container->get('enqueue.client.meta.topic_meta_registry');
    }

    /**
     * @return QueueMetaRegistry
     */
    public function getQueueMetaRegistry()
    {
        return $this->container->get('enqueue.client.meta.queue_meta_registry');
    }

    /**
     * @param bool $setupBroker
     *
     * @return ProducerInterface
     */
    public function getProducer($setupBroker = false)
    {
        $setupBroker && $this->setupBroker();

        return $this->container->get('enqueue.client.producer');
    }

    public function setupBroker()
    {
        $this->getDriver()->setupBroker();
    }

    /**
     * @return ArrayProcessorRegistry
     */
    public function getProcessorRegistry()
    {
        return $this->container->get('enqueue.client.processor_registry');
    }

    /**
     * @return DelegateProcessor
     */
    public function getDelegateProcessor()
    {
        return $this->container->get('enqueue.client.delegate_processor');
    }

    /**
     * @return RouterProcessor
     */
    public function getRouterProcessor()
    {
        return $this->container->get('enqueue.client.router_processor');
    }
}
