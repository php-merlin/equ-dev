<?php

namespace Enqueue\Bundle\Tests\Functional\Events;

use Enqueue\Bundle\Tests\Functional\App\TestAsyncListener;
use Enqueue\Bundle\Tests\Functional\WebTestCase;
use Enqueue\Client\TraceableProducer;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * @group functional
 */
class AsyncListenerTest extends WebTestCase
{
    protected function tearDown()
    {
        parent::tearDown();

        static::$container = null;
    }

    public function testShouldNotCallRealListenerIfMarkedAsAsync()
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::$container->get('event_dispatcher');

        $dispatcher->dispatch('test_async', new GenericEvent('aSubject'));

        /** @var TestAsyncListener $listener */
        $listener = static::$container->get('test_async_listener');

        $this->assertEmpty($listener->calls);
    }

    public function testShouldSendMessageToExpectedCommandInsteadOfCallingRealListener()
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::$container->get('event_dispatcher');

        $event = new GenericEvent('theSubject', ['fooArg' => 'fooVal']);

        $dispatcher->dispatch('test_async', $event);

        /** @var TraceableProducer $producer */
        $producer = static::$container->get('enqueue.producer');

        $traces = $producer->getCommandTraces('symfony_events');

        $this->assertCount(1, $traces);

        $this->assertEquals('symfony_events', $traces[0]['command']);
        $this->assertEquals('{"subject":"theSubject","arguments":{"fooArg":"fooVal"}}', $traces[0]['body']);
    }

    public function testShouldSendMessageForEveryDispatchCall()
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::$container->get('event_dispatcher');

        $dispatcher->dispatch('test_async', new GenericEvent('theSubject', ['fooArg' => 'fooVal']));
        $dispatcher->dispatch('test_async', new GenericEvent('theSubject', ['fooArg' => 'fooVal']));
        $dispatcher->dispatch('test_async', new GenericEvent('theSubject', ['fooArg' => 'fooVal']));

        /** @var TraceableProducer $producer */
        $producer = static::$container->get('enqueue.producer');

        $traces = $producer->getCommandTraces('symfony_events');

        $this->assertCount(3, $traces);
    }

    public function testShouldSendMessageIfDispatchedFromInsideListener()
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::$container->get('event_dispatcher');

        $dispatcher->addListener('foo', function (Event $event, $eventName, EventDispatcherInterface $dispatcher) {
            $dispatcher->dispatch('test_async', new GenericEvent('theSubject', ['fooArg' => 'fooVal']));
        });

        $dispatcher->dispatch('foo');

        /** @var TraceableProducer $producer */
        $producer = static::$container->get('enqueue.producer');

        $traces = $producer->getCommandTraces('symfony_events');

        $this->assertCount(1, $traces);
    }
}
