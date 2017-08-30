<?php

namespace Enqueue\RdKafka\Tests;

use Enqueue\RdKafka\JsonSerializer;
use Enqueue\RdKafka\RdKafkaMessage;
use Enqueue\RdKafka\Serializer;
use Enqueue\Test\ClassExtensionTrait;
use PHPUnit\Framework\TestCase;

class JsonSerializerTest extends TestCase
{
    use ClassExtensionTrait;

    public function testShouldImplementSerializerInterface()
    {
        $this->assertClassImplements(Serializer::class, JsonSerializer::class);
    }

    public function testCouldBeConstructedWithoutAnyArguments()
    {
        new JsonSerializer();
    }

    public function testShouldConvertMessageToJsonString()
    {
        $serializer = new JsonSerializer();

        $message = new RdKafkaMessage('theBody', ['aProp' => 'aPropVal'], ['aHeader' => 'aHeaderVal']);

        $json = $serializer->toString($message);

        $this->assertSame('{"body":"theBody","properties":{"aProp":"aPropVal"},"headers":{"aHeader":"aHeaderVal"}}', $json);
    }

    public function testThrowIfFailedToEncodeMessageToJson()
    {
        $serializer = new JsonSerializer();

        $message = new RdKafkaMessage('theBody', ['aProp' => STDIN]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The malformed json given. Error 8 and message Type is not supported');
        $serializer->toString($message);
    }

    public function testShouldConvertJsonStringToMessage()
    {
        $serializer = new JsonSerializer();

        $message = $serializer->toMessage('{"body":"theBody","properties":{"aProp":"aPropVal"},"headers":{"aHeader":"aHeaderVal"}}');

        $this->assertInstanceOf(RdKafkaMessage::class, $message);

        $this->assertSame('theBody', $message->getBody());
        $this->assertSame(['aProp' => 'aPropVal'], $message->getProperties());
        $this->assertSame(['aHeader' => 'aHeaderVal'], $message->getHeaders());
    }

    public function testThrowIfFailedToDecodeJsonToMessage()
    {
        $serializer = new JsonSerializer();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The malformed json given. Error 2 and message State mismatch (invalid or malformed JSON)');
        $serializer->toMessage('{]');
    }
}
