<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
// | Phinx 迁移命令基类
// +----------------------------------------------------------------------
namespace app\command;

use Phinx\Console\PhinxApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class PhinxCommand extends Command
{
    protected function addCommonOptions(): void
    {
        $this
            ->addOption('configuration', 'c', InputOption::VALUE_REQUIRED, 'Phinx 配置文件', BASE_PATH . '/phinx.php')
            ->addOption('environment', 'e', InputOption::VALUE_REQUIRED, '目标环境')
            ->addOption('parser', 'p', InputOption::VALUE_REQUIRED, '配置解析器')
            ->addOption('no-info', null, InputOption::VALUE_NONE, '隐藏 Phinx 版本信息');
    }

    protected function runPhinx(
        string $command,
        InputInterface $input,
        OutputInterface $output,
        array $arguments = [],
        array $options = []
    ): int {
        $phinxInput = [
            'command' => $command,
            '--configuration' => $input->getOption('configuration'),
        ];

        foreach (['environment', 'parser'] as $name) {
            $value = $input->getOption($name);
            if ($value !== null && $value !== '') {
                $phinxInput['--' . $name] = $value;
            }
        }

        if ($input->getOption('no-info')) {
            $phinxInput['--no-info'] = true;
        }

        foreach ($arguments as $name => $value) {
            if ($value !== null && $value !== '') {
                $phinxInput[$name] = $value;
            }
        }

        foreach ($options as $name => $value) {
            if ($value !== null && $value !== false && $value !== '') {
                $phinxInput[$name] = $value;
            }
        }

        $phinx = new PhinxApplication();
        $phinx->setAutoExit(false);

        $arrayInput = new ArrayInput($phinxInput);
        $arrayInput->setInteractive($input->isInteractive());

        return $phinx->run($arrayInput, $output);
    }
}
