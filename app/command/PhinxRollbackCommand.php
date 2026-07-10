<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Phinx 回滚迁移命令
// +----------------------------------------------------------------------
namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'phinx:rollback', description: '回滚 Phinx 数据库迁移')]
class PhinxRollbackCommand extends PhinxCommand
{
    protected function configure(): void
    {
        $this->addCommonOptions();
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, '回滚到指定版本')
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, '回滚到指定日期')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '忽略断点强制回滚')
            ->addOption('dry-run', 'x', InputOption::VALUE_NONE, '只输出 SQL，不执行')
            ->addOption('fake', null, InputOption::VALUE_NONE, '仅标记为已回滚，不实际执行');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runPhinx('rollback', $input, $output, [], [
            '--target' => $input->getOption('target'),
            '--date' => $input->getOption('date'),
            '--force' => $input->getOption('force'),
            '--dry-run' => $input->getOption('dry-run'),
            '--fake' => $input->getOption('fake'),
        ]);
    }
}
