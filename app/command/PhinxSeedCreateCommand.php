<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Phinx 创建种子命令
// +----------------------------------------------------------------------
namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'phinx:seed-create', description: '创建 Phinx 数据库种子文件')]
class PhinxSeedCreateCommand extends PhinxCommand
{
    protected function configure(): void
    {
        $this->addCommonOptions();
        $this
            ->addArgument('name', InputArgument::REQUIRED, '种子类名，使用 CamelCase')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, '种子文件生成目录')
            ->addOption('template', 't', InputOption::VALUE_REQUIRED, '种子模板文件');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runPhinx('seed:create', $input, $output, [
            'name' => $input->getArgument('name'),
        ], [
            '--path' => $input->getOption('path'),
            '--template' => $input->getOption('template'),
        ]);
    }
}
