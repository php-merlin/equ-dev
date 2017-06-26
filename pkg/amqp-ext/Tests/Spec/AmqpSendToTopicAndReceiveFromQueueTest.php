<?php

namespace Enqueue\AmqpExt\Tests\Spec;

use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\AmqpExt\AmqpContext;
use Enqueue\Psr\PsrContext;
use Enqueue\Psr\Spec\SendToTopicAndReceiveFromQueueSpec;

/**
 * @group functional
 */
class AmqpSendToTopicAndReceiveFromQueueTest extends SendToTopicAndReceiveFromQueueSpec
{
    /**
     * {@inheritdoc}
     */
    protected function createContext()
    {
        $factory = new AmqpConnectionFactory(getenv('AMQP_DSN'));

        return $factory->createContext();
    }

    /**
     * {@inheritdoc}
     *
     * @param AmqpContext $context
     */
    protected function createQueue(PsrContext $context, $queueName)
    {
        $queue = $context->createQueue($queueName);
        $context->declareQueue($queue);
        $context->purge($queue);

        $context->bind($context->createTopic($queueName), $queue);

        return $queue;
    }

    /**
     * {@inheritdoc}
     *
     * @param AmqpContext $context
     */
    protected function createTopic(PsrContext $context, $topicName)
    {
        $topic = $context->createTopic($topicName);
        $topic->setType(\AMQP_EX_TYPE_FANOUT);
        $topic->addFlag(\AMQP_DURABLE);
        $context->declareTopic($topic);

        return $topic;
    }
}
