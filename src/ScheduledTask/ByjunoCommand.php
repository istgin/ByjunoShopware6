<?php declare(strict_types = 1);

namespace Byjuno\ByjunoPayments\ScheduledTask;
use Byjuno\ByjunoPayments\Service\ByjunoCoreTask;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ByjunoCommand extends Command
{
    /**
     * @var ByjunoCoreTask
     */
    private $byjunoCoreTask;

    public function __construct(
        ByjunoCoreTask $byjunoCoreTask
    )
    {
        parent::__construct('byjuno-document:process');
        $this->byjunoCoreTask = $byjunoCoreTask;
    }

    protected static $defaultName = 'byjuno-document:process';
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->byjunoCoreTask->TaskRun();
        return 1;
    }
}
