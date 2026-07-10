<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Phinx 创建迁移命令
// +----------------------------------------------------------------------
namespace app\command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'phinx:create', description: '创建 Phinx 数据库迁移文件')]
class PhinxCreateCommand extends PhinxCommand
{
    protected function configure(): void
    {
        $this->addCommonOptions();
        $this
            ->addArgument('name', InputArgument::OPTIONAL, '迁移类名，使用 CamelCase')
            ->addOption('template', 't', InputOption::VALUE_REQUIRED, '迁移模板文件')
            ->addOption('class', 'l', InputOption::VALUE_REQUIRED, '迁移模板生成类')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, '迁移文件生成目录')
            ->addOption('style', null, InputOption::VALUE_REQUIRED, '迁移文件风格');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runPhinx('create', $input, $output, [
            'name' => $input->getArgument('name'),
        ], [
            '--template' => $input->getOption('template'),
            '--class' => $input->getOption('class'),
            '--path' => $input->getOption('path'),
            '--style' => $input->getOption('style'),
        ]);
    }
}
