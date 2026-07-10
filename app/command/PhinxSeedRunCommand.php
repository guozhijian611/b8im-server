<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Phinx 执行种子命令
// +----------------------------------------------------------------------
namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'phinx:seed-run', description: '执行 Phinx 数据库种子')]
class PhinxSeedRunCommand extends PhinxCommand
{
    protected function configure(): void
    {
        $this->addCommonOptions();
        $this
            ->addOption('seed', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '指定种子类，可重复传入')
            ->addOption('dry-run', 'x', InputOption::VALUE_NONE, '只输出 SQL，不执行');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runPhinx('seed:run', $input, $output, [], [
            '--seed' => $input->getOption('seed'),
            '--dry-run' => $input->getOption('dry-run'),
        ]);
    }
}
