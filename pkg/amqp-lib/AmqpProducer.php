<?php

namespace Enqueue\AmqpLib;

use Interop\Amqp\AmqpMessage as InteropAmqpMessage;
use Interop\Amqp\AmqpProducer as InteropAmqpProducer;
use Interop\Amqp\AmqpQueue as InteropAmqpQueue;
use Interop\Amqp\AmqpTopic as InteropAmqpTopic;
use Interop\Queue\DeliveryDelayNotSupportedException;
use Interop\Queue\InvalidDestinationException;
use Interop\Queue\InvalidMessageException;
use Interop\Queue\PsrDestination;
use Interop\Queue\PsrMessage;
use Interop\Queue\PsrTopic;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage as LibAMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class AmqpProducer implements InteropAmqpProducer
{
    /**
     * @var int|null
     */
    private $priority;

    /**
     * @var int|float|null
     */
    private $timeToLive;

    /**
     * @var AMQPChannel
     */
    private $channel;

    /**
     * @param AMQPChannel $channel
     */
    public function __construct(AMQPChannel $channel)
    {
        $this->channel = $channel;
    }

    /**
     * @param InteropAmqpTopic|InteropAmqpQueue $destination
     * @param InteropAmqpMessage                $message
     */
    public function send(PsrDestination $destination, PsrMessage $message)
    {
        $destination instanceof PsrTopic
            ? InvalidDestinationException::assertDestinationInstanceOf($destination, InteropAmqpTopic::class)
            : InvalidDestinationException::assertDestinationInstanceOf($destination, InteropAmqpQueue::class)
        ;

        InvalidMessageException::assertMessageInstanceOf($message, InteropAmqpMessage::class);

        if (null !== $this->priority && null === $message->getPriority()) {
            $message->setPriority($this->priority);
        }

        if (null !== $this->timeToLive && null === $message->getExpiration()) {
            $message->setExpiration($this->timeToLive);
        }

        $amqpProperties = $message->getHeaders();

        if ($appProperties = $message->getProperties()) {
            $amqpProperties['application_headers'] = new AMQPTable($appProperties);
        }

        $amqpMessage = new LibAMQPMessage($message->getBody(), $amqpProperties);

        if ($destination instanceof InteropAmqpTopic) {
            $this->channel->basic_publish(
                $amqpMessage,
                $destination->getTopicName(),
                $message->getRoutingKey(),
                (bool) ($message->getFlags() & InteropAmqpMessage::FLAG_MANDATORY),
                (bool) ($message->getFlags() & InteropAmqpMessage::FLAG_IMMEDIATE)
            );
        } else {
            $this->channel->basic_publish(
                $amqpMessage,
                '',
                $destination->getQueueName(),
                (bool) ($message->getFlags() & InteropAmqpMessage::FLAG_MANDATORY),
                (bool) ($message->getFlags() & InteropAmqpMessage::FLAG_IMMEDIATE)
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setDeliveryDelay($deliveryDelay)
    {
        throw new DeliveryDelayNotSupportedException('The provider does not support delivery delay feature');
    }

    /**
     * {@inheritdoc}
     */
    public function getDeliveryDelay()
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function setPriority($priority)
    {
        $this->priority = $priority;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority()
    {
        return $this->priority;
    }

    /**
     * {@inheritdoc}
     */
    public function setTimeToLive($timeToLive)
    {
        $this->timeToLive = $timeToLive;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimeToLive()
    {
        return $this->timeToLive;
    }
}
