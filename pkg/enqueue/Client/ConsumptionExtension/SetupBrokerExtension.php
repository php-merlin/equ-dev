<?php

namespace Enqueue\Client\ConsumptionExtension;

use Enqueue\Client\DriverInterface;
use Enqueue\Consumption\EmptyExtensionTrait;
use Enqueue\Consumption\ExtensionInterface;
use Enqueue\Consumption\OnStartContext;

class SetupBrokerExtension implements ExtensionInterface
{
    use EmptyExtensionTrait;

    /**
     * @var DriverInterface
     */
    private $driver;

    /**
     * @var bool
     */
    private $isDone;

    /**
     * @param DriverInterface $driver
     */
    public function __construct(DriverInterface $driver)
    {
        $this->driver = $driver;
        $this->isDone = false;
    }

    /**
     * {@inheritdoc}
     */
    public function onStart(OnStartContext $context)
    {
        if (false == $this->isDone) {
            $this->isDone = true;
            $this->driver->setupBroker($context->getLogger());
        }
    }
}
