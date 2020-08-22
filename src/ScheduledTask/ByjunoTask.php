<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ByjunoTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'byjuno.byjuno_invoice';
    }

    public static function getDefaultInterval(): int
    {
        return 10; // 10 sec
    }
}
