<?php

namespace Enqueue\Gearman\Tests\Spec;

use Enqueue\Gearman\GearmanConnectionFactory;
use Enqueue\Psr\PsrContext;
use Enqueue\Psr\PsrQueue;
use Enqueue\Psr\Spec\SendToAndReceiveFromQueueSpec;

/**
 * @group functional
 */
class GearmanSendToAndReceiveFromQueueTest extends SendToAndReceiveFromQueueSpec
{
    /**
     * {@inheritdoc}
     */
    protected function createContext()
    {
        $factory = new GearmanConnectionFactory(getenv('GEARMAN_DSN'));

        return $factory->createContext();
    }

    /**
     * @param PsrContext $context
     * @param string     $queueName
     *
     * @return PsrQueue
     */
    protected function createQueue(PsrContext $context, $queueName)
    {
        return $context->createQueue($queueName.time());
    }
}
