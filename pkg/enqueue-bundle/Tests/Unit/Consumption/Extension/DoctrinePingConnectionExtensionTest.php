<?php

namespace Enqueue\Bundle\Tests\Unit\Consumption\Extension;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use Enqueue\Bundle\Consumption\Extension\DoctrinePingConnectionExtension;
use Enqueue\Consumption\Context\MessageReceived;
use Interop\Queue\Consumer;
use Interop\Queue\Context as InteropContext;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DoctrinePingConnectionExtensionTest extends TestCase
{
    public function testCouldBeConstructedWithRequiredAttributes()
    {
        new DoctrinePingConnectionExtension($this->createRegistryMock());
    }

    public function testShouldNotReconnectIfConnectionIsOK()
    {
        $connection = $this->createConnectionMock();
        $connection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true)
        ;
        $connection
            ->expects($this->once())
            ->method('ping')
            ->willReturn(true)
        ;
        $connection
            ->expects($this->never())
            ->method('close')
        ;
        $connection
            ->expects($this->never())
            ->method('connect')
        ;

        $context = $this->createContext();
        $context->getLogger()
            ->expects($this->never())
            ->method('debug')
        ;

        $registry = $this->createRegistryMock();
        $registry
            ->expects($this->once())
            ->method('getConnections')
            ->willReturn([$connection])
        ;

        $extension = new DoctrinePingConnectionExtension($registry);
        $extension->onMessageReceived($context);
    }

    public function testShouldDoesReconnectIfConnectionFailed()
    {
        $connection = $this->createConnectionMock();
        $connection
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true)
        ;
        $connection
            ->expects($this->once())
            ->method('ping')
            ->willReturn(false)
        ;
        $connection
            ->expects($this->once())
            ->method('close')
        ;
        $connection
            ->expects($this->once())
            ->method('connect')
        ;

        $context = $this->createContext();
        $context->getLogger()
            ->expects($this->at(0))
            ->method('debug')
            ->with('[DoctrinePingConnectionExtension] Connection is not active trying to reconnect.')
        ;
        $context->getLogger()
            ->expects($this->at(1))
            ->method('debug')
            ->with('[DoctrinePingConnectionExtension] Connection is active now.')
        ;

        $registry = $this->createRegistryMock();
        $registry
            ->expects($this->once())
            ->method('getConnections')
            ->willReturn([$connection])
        ;

        $extension = new DoctrinePingConnectionExtension($registry);
        $extension->onMessageReceived($context);
    }

    public function testShouldSkipIfConnectionWasNotOpened()
    {
        $connection1 = $this->createConnectionMock();
        $connection1
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(false)
        ;
        $connection1
            ->expects($this->never())
            ->method('ping')
        ;

        // 2nd connection was opened in the past
        $connection2 = $this->createConnectionMock();
        $connection2
            ->expects($this->once())
            ->method('isConnected')
            ->willReturn(true)
        ;
        $connection2
            ->expects($this->once())
            ->method('ping')
            ->willReturn(true)
        ;

        $context = $this->createContext();
        $context->getLogger()
            ->expects($this->never())
            ->method('debug')
        ;

        $registry = $this->createRegistryMock();
        $registry
            ->expects($this->once())
            ->method('getConnections')
            ->willReturn([$connection1, $connection2])
        ;

        $extension = new DoctrinePingConnectionExtension($registry);
        $extension->onMessageReceived($context);
    }

    protected function createContext(): MessageReceived
    {
        return new MessageReceived(
            $this->createMock(InteropContext::class),
            $this->createMock(Consumer::class),
            $this->createMock(Message::class),
            $this->createMock(Processor::class),
            1,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return MockObject|ManagerRegistry
     */
    protected function createRegistryMock()
    {
        return $this->createMock(ManagerRegistry::class);
    }

    /**
     * @return MockObject|Connection
     */
    protected function createConnectionMock(): Connection
    {
        return $this->createMock(Connection::class);
    }
}
