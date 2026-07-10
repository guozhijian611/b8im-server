<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Phinx 迁移状态命令
// +----------------------------------------------------------------------
namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'phinx:status', description: '查看 Phinx 数据库迁移状态')]
class PhinxStatusCommand extends PhinxCommand
{
    protected function configure(): void
    {
        $this->addCommonOptions();
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, '输出格式：text 或 json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runPhinx('status', $input, $output, [], [
            '--format' => $input->getOption('format'),
        ]);
    }
}
