<?php

namespace Enqueue\Consumption\Extension;

use Enqueue\Consumption\Context;
use Enqueue\Consumption\Context\PreConsume;
use Enqueue\Consumption\EmptyExtensionTrait;
use Enqueue\Consumption\ExtensionInterface;
use Psr\Log\LoggerInterface;

class LimitConsumptionTimeExtension implements ExtensionInterface
{
    use EmptyExtensionTrait;

    /**
     * @var \DateTime
     */
    protected $timeLimit;

    /**
     * @param \DateTime $timeLimit
     */
    public function __construct(\DateTime $timeLimit)
    {
        $this->timeLimit = $timeLimit;
    }

    public function onPreConsume(PreConsume $context): void
    {
        if ($this->shouldBeStopped($context->getLogger())) {
            $context->interruptExecution();
        }
    }

    public function onIdle(Context $context)
    {
        if ($this->shouldBeStopped($context->getLogger())) {
            $context->setExecutionInterrupted(true);
        }
    }

    public function onPostReceived(Context $context)
    {
        if ($this->shouldBeStopped($context->getLogger())) {
            $context->setExecutionInterrupted(true);
        }
    }

    protected function shouldBeStopped(LoggerInterface $logger): bool
    {
        $now = new \DateTime();
        if ($now >= $this->timeLimit) {
            $logger->debug(sprintf(
                '[LimitConsumptionTimeExtension] Execution interrupted as limit time has passed.'.
                ' now: "%s", time-limit: "%s"',
                $now->format(DATE_ISO8601),
                $this->timeLimit->format(DATE_ISO8601)
            ));

            return true;
        }

        return false;
    }
}
