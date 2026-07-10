<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Phinx 执行迁移命令
// +----------------------------------------------------------------------
namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'phinx:migrate', description: '执行 Phinx 数据库迁移')]
class PhinxMigrateCommand extends PhinxCommand
{
    protected function configure(): void
    {
        $this->addCommonOptions();
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, '迁移到指定版本')
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, '迁移到指定日期')
            ->addOption('count', 'k', InputOption::VALUE_REQUIRED, '执行指定数量的迁移')
            ->addOption('dry-run', 'x', InputOption::VALUE_NONE, '只输出 SQL，不执行')
            ->addOption('fake', null, InputOption::VALUE_NONE, '仅标记为已执行，不实际执行');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runPhinx('migrate', $input, $output, [], [
            '--target' => $input->getOption('target'),
            '--date' => $input->getOption('date'),
            '--count' => $input->getOption('count'),
            '--dry-run' => $input->getOption('dry-run'),
            '--fake' => $input->getOption('fake'),
        ]);
    }
}
