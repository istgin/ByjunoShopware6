<?php declare(strict_types=1);

namespace Byjuno\ByjunoPayments\ScheduledTask;

use Byjuno\ByjunoPayments\Service\ByjunoCoreTask;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ByjunoTaskHandler extends ScheduledTaskHandler
{
    /**
     * @var ByjunoCoreTask
     */
    private $byjunoCoreTask;

    public function __construct(
        EntityRepository $scheduledTaskRepository,
        ByjunoCoreTask $byjunoCoreTask
    )
    {
        parent::__construct($scheduledTaskRepository);
        $this->byjunoCoreTask = $byjunoCoreTask;
    }

    public static function getHandledMessages(): iterable
    {
        return [ ByjunoTask::class ];
    }

    public function run(): void
    {
        $this->byjunoCoreTask->TaskRun();
    }
}
