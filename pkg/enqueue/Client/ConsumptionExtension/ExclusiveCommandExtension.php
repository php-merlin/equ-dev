<?php

namespace Enqueue\Client\ConsumptionExtension;

use Enqueue\Client\Config;
use Enqueue\Client\DriverInterface;
use Enqueue\Client\Route;
use Enqueue\Consumption\Context;
use Enqueue\Consumption\EmptyExtensionTrait as ConsumptionEmptyExtensionTrait;
use Enqueue\Consumption\ExtensionInterface as ConsumptionExtensionInterface;

final class ExclusiveCommandExtension implements ConsumptionExtensionInterface
{
    use ConsumptionEmptyExtensionTrait;

    /**
     * @var DriverInterface
     */
    private $driver;

    /**
     * @var Route[]
     */
    private $queueToRouteMap;

    public function __construct(DriverInterface $driver)
    {
        $this->driver = $driver;
    }

    public function onPreReceived(Context $context)
    {
        $message = $context->getInteropMessage();
        $queue = $context->getPsrQueue();

        if ($message->getProperty(Config::PARAMETER_TOPIC_NAME)) {
            return;
        }
        if ($message->getProperty(Config::PARAMETER_COMMAND_NAME)) {
            return;
        }
        if ($message->getProperty(Config::PARAMETER_PROCESSOR_NAME)) {
            return;
        }

        if (null === $this->queueToRouteMap) {
            $this->queueToRouteMap = $this->buildMap();
        }

        if (array_key_exists($queue->getQueueName(), $this->queueToRouteMap)) {
            $context->getLogger()->debug('[ExclusiveCommandExtension] This is a exclusive command queue and client\'s properties are not set. Setting them');

            $route = $this->queueToRouteMap[$queue->getQueueName()];
            $message->setProperty(Config::PARAMETER_PROCESSOR_NAME, $route->getProcessor());
            $message->setProperty(Config::PARAMETER_COMMAND_NAME, $route->getSource());
        }
    }

    private function buildMap(): array
    {
        $map = [];
        foreach ($this->driver->getRouteCollection()->all() as $route) {
            if (false == $route->isCommand()) {
                continue;
            }

            if (false == $route->isProcessorExclusive()) {
                continue;
            }

            $queueName = $this->driver->createQueue($route->getQueue())->getQueueName();
            if (array_key_exists($queueName, $map)) {
                throw new \LogicException('The queue name has been already bound by another exclusive command processor');
            }

            $map[$queueName] = $route;
        }

        return $map;
    }
}
