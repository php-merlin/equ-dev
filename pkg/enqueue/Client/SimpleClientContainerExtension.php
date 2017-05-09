<?php
namespace Enqueue\Client;

use Enqueue\Client\ConsumptionExtension\DelayRedeliveredMessageExtension;
use Enqueue\Client\ConsumptionExtension\SetRouterPropertiesExtension;
use Enqueue\Client\Meta\QueueMetaRegistry;
use Enqueue\Client\Meta\TopicMetaRegistry;
use Enqueue\Consumption\ChainExtension as ConsumptionChainExtension;
use Enqueue\Consumption\QueueConsumer;
use Enqueue\Symfony\TransportFactoryInterface;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

class SimpleClientContainerExtension extends Extension
{
    /**
     * @var TransportFactoryInterface[]
     */
    private $factories;

    public function __construct()
    {
        $this->factories = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias()
    {
        return 'enqueue';
    }

    /**
     * {@inheritdoc}
     */
    private function getConfigTreeBuilder()
    {
        $tb = new TreeBuilder();
        $rootNode = $tb->root('enqueue');

        $transportChildren = $rootNode->children()
            ->arrayNode('transport')->isRequired()->children();

        foreach ($this->factories as $factory) {
            $factory->addConfiguration(
                $transportChildren->arrayNode($factory->getName())
            );
        }

        $rootNode->children()
            ->arrayNode('client')->children()
                ->scalarNode('prefix')->defaultValue('enqueue')->end()
                ->scalarNode('app_name')->defaultValue('app')->end()
                ->scalarNode('router_topic')->defaultValue('router')->cannotBeEmpty()->end()
                ->scalarNode('router_queue')->defaultValue(Config::DEFAULT_PROCESSOR_QUEUE_NAME)->cannotBeEmpty()->end()
                ->scalarNode('default_processor_queue')->defaultValue(Config::DEFAULT_PROCESSOR_QUEUE_NAME)->cannotBeEmpty()->end()
                ->integerNode('redelivered_delay_time')->min(0)->defaultValue(0)->end()
            ->end()->end()
            ->arrayNode('extensions')->addDefaultsIfNotSet()->children()
                ->booleanNode('signal_extension')->defaultValue(function_exists('pcntl_signal_dispatch'))->end()
            ->end()->end()
        ;

        return $tb;
    }

    /**
     * @param TransportFactoryInterface $transportFactory
     */
    public function addTransportFactory(TransportFactoryInterface $transportFactory)
    {
        $name = $transportFactory->getName();

        if (empty($name)) {
            throw new \LogicException('Transport factory name cannot be empty');
        }
        if (array_key_exists($name, $this->factories)) {
            throw new \LogicException(sprintf('Transport factory with such name already added. Name %s', $name));
        }

        $this->factories[$name] = $transportFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configProcessor = new Processor();
        $config = $configProcessor->process($this->getConfigTreeBuilder()->buildTree(), $configs);

        foreach ($config['transport'] as $name => $transportConfig) {
            $this->factories[$name]->createConnectionFactory($container, $transportConfig);
            $this->factories[$name]->createContext($container, $transportConfig);
            $this->factories[$name]->createDriver($container, $transportConfig);
        }

        $container->register('enqueue.client.config', Config::class)
            ->setArguments([
                $config['client']['prefix'],
                $config['client']['app_name'],
                $config['client']['router_topic'],
                $config['client']['router_queue'],
                $config['client']['default_processor_queue'],
                'enqueue.client.router_processor',
                $config['transport'][$config['transport']['default']['alias']],
        ]);

        $container->register('enqueue.client.producer', Producer::class)
            ->setArguments([
                new Reference('enqueue.client.driver')
        ]);

        $container->register('enqueue.client.meta.topic_meta_registry', TopicMetaRegistry::class)
            ->setArguments([[]]);

        $container->register('enqueue.client.meta.queue_meta_registry', QueueMetaRegistry::class)
            ->setArguments([
                new Reference('enqueue.client.config'),
                [],
        ]);

        $container->register('enqueue.client.processor_registry', ArrayProcessorRegistry::class);

        $container->register('enqueue.client.delegate_processor', DelegateProcessor::class)
            ->setArguments([new Reference('enqueue.client.processor_registry')]);

        $container->register('enqueue.client.queue_consumer', QueueConsumer::class)
            ->setArguments([
                new Reference('enqueue.transport.context'),
                new Reference('enqueue.consumption.extensions')
            ]);

        // router
        $container->register('enqueue.client.router_processor', RouterProcessor::class)
            ->setArguments([new Reference('enqueue.client.driver'), []]);
        $container->getDefinition('enqueue.client.processor_registry')
            ->addMethodCall('add', ['enqueue.client.router_processor', new Reference('enqueue.client.router_processor')]);
        $container->getDefinition('enqueue.client.meta.queue_meta_registry')
            ->addMethodCall('addProcessor', [$config['client']['router_queue'], 'enqueue.client.router_processor']);

        // extensions
        $extensions = [];
        if ($config['client']['redelivered_delay_time']) {
            $container->register('enqueue.client.delay_redelivered_message_extension', DelayRedeliveredMessageExtension::class)
                ->setArguments([
                    new Reference('enqueue.client.driver'),
                    $config['client']['redelivered_delay_time']
            ]);

            $extensions[] = new Reference('enqueue.client.delay_redelivered_message_extension');
        }

        $container->register('enqueue.client.extension.set_router_properties', SetRouterPropertiesExtension::class)
            ->setArguments([new Reference('enqueue.client.driver')]);

        $extensions[] = new Reference('enqueue.client.extension.set_router_properties');

        $container->register('enqueue.consumption.extensions', ConsumptionChainExtension::class)
            ->setArguments([$extensions]);
    }
}
