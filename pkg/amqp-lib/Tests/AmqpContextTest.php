<?php

namespace Enqueue\AmqpLib\Tests;

use Enqueue\AmqpLib\AmqpContext;
use Enqueue\Null\NullQueue;
use Interop\Amqp\Impl\AmqpBind;
use Interop\Amqp\Impl\AmqpQueue;
use Interop\Amqp\Impl\AmqpTopic;
use Interop\Queue\InvalidDestinationException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PHPUnit\Framework\TestCase;

class AmqpContextTest extends TestCase
{
    public function testShouldDeclareTopic()
    {
        $channel = $this->createChannelMock();
        $channel
            ->expects($this->once())
            ->method('exchange_declare')
            ->with(
                $this->identicalTo('name'),
                $this->identicalTo('type'),
                $this->isTrue(),
                $this->isTrue(),
                $this->isTrue(),
                $this->isTrue(),
                $this->isTrue(),
                $this->identicalTo(['key' => 'value']),
                $this->isNull()
            )
        ;

        $connection = $this->createConnectionMock();
        $connection
            ->expects($this->once())
            ->method('channel')
            ->willReturn($channel)
        ;

        $topic = new AmqpTopic('name');
        $topic->setType('type');
        $topic->setArguments(['key' => 'value']);
        $topic->addFlag(AmqpTopic::FLAG_DURABLE);
        $topic->addFlag(AmqpTopic::FLAG_NOWAIT);
        $topic->addFlag(AmqpTopic::FLAG_PASSIVE);
        $topic->addFlag(AmqpTopic::FLAG_INTERNAL);
        $topic->addFlag(AmqpTopic::FLAG_AUTODELETE);

        $session = new AmqpContext($connection, '');
        $session->declareTopic($topic);
    }

    public function testShouldDeclareQueue()
    {
        $channel = $this->createChannelMock();
        $channel
            ->expects($this->once())
            ->method('queue_declare')
            ->with(
                $this->identicalTo('name'),
                $this->isTrue(),
                $this->isTrue(),
                $this->isTrue(),
                $this->isTrue(),
                $this->isTrue(),
                $this->identicalTo(['key' => 'value']),
                $this->isNull()
            )
        ;

        $connection = $this->createConnectionMock();
        $connection
            ->expects($this->once())
            ->method('channel')
            ->willReturn($channel)
        ;

        $queue = new AmqpQueue('name');
        $queue->setArguments(['key' => 'value']);
        $queue->addFlag(AmqpQueue::FLAG_AUTODELETE);
        $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        $queue->addFlag(AmqpQueue::FLAG_NOWAIT);
        $queue->addFlag(AmqpQueue::FLAG_PASSIVE);
        $queue->addFlag(AmqpQueue::FLAG_EXCLUSIVE);
        $queue->addFlag(AmqpQueue::FLAG_NOWAIT);

        $session = new AmqpContext($connection, '');
        $session->declareQueue($queue);
    }

    public function testDeclareBindShouldBindTopicToTopic()
    {
        $source = new AmqpTopic('source');
        $target = new AmqpTopic('target');

        $channel = $this->createChannelMock();
        $channel
            ->expects($this->once())
            ->method('exchange_bind')
            ->with($this->identicalTo('target'), $this->identicalTo('source'), $this->identicalTo('routing-key'), $this->isTrue())
        ;

        $connection = $this->createConnectionMock();
        $connection
            ->expects($this->once())
            ->method('channel')
            ->willReturn($channel)
        ;

        $context = new AmqpContext($connection, '');
        $context->bind(new AmqpBind($target, $source, 'routing-key', 12345));
    }

    public function testDeclareBindShouldBindTopicToQueue()
    {
        $source = new AmqpTopic('source');
        $target = new AmqpQueue('target');

        $channel = $this->createChannelMock();
        $channel
            ->expects($this->exactly(2))
            ->method('queue_bind')
            ->with($this->identicalTo('target'), $this->identicalTo('source'), $this->identicalTo('routing-key'), $this->isTrue())
        ;

        $connection = $this->createConnectionMock();
        $connection
            ->expects($this->once())
            ->method('channel')
            ->willReturn($channel)
        ;

        $context = new AmqpContext($connection, '');
        $context->bind(new AmqpBind($target, $source, 'routing-key', 12345));
        $context->bind(new AmqpBind($source, $target, 'routing-key', 12345));
    }

    public function testShouldCloseChannelConnection()
    {
        $channel = $this->createChannelMock();
        $channel
            ->expects($this->once())
            ->method('close')
        ;

        $connection = $this->createConnectionMock();
        $connection
            ->expects($this->once())
            ->method('channel')
            ->willReturn($channel)
        ;

        $context = new AmqpContext($connection, '');
        $context->createProducer();

        $context->close();
    }

    public function testShouldPurgeQueue()
    {
        $queue = new AmqpQueue('queue');

        $channel = $this->createChannelMock();
        $channel
            ->expects($this->once())
            ->method('queue_purge')
            ->with('queue')
        ;

        $connection = $this->createConnectionMock();
        $connection
            ->expects($this->once())
            ->method('channel')
            ->willReturn($channel)
        ;

        $context = new AmqpContext($connection, '');
        $context->purgeQueue($queue);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|AbstractConnection
     */
    public function createConnectionMock()
    {
        return $this->createMock(AbstractConnection::class);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|AMQPChannel
     */
    public function createChannelMock()
    {
        return $this->createMock(AMQPChannel::class);
    }
}
