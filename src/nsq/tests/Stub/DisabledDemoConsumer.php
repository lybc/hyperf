<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace HyperfTest\Nsq\Stub;

use Hyperf\Nsq\AbstractConsumer;
use Hyperf\Nsq\Message;
use Hyperf\Nsq\Result;

class DisabledDemoConsumer extends AbstractConsumer
{
    public function consume(Message $message): ?string
    {
        return Result::ACK;
    }

    public function isEnable(): bool
    {
        return false;
    }
}
