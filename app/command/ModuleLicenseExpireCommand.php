<?php
// +----------------------------------------------------------------------
// | saithink [ saithink快速开发框架 ]
// +----------------------------------------------------------------------
namespace app\command;

use plugin\saimulti\service\module\ModuleServiceFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'module:license-expire', description: '扫描并收敛已到期租户模块授权')]
final class ModuleLicenseExpireCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('batch', 'b', InputOption::VALUE_REQUIRED, '单批处理数量');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = $input->getOption('batch');
        if ($batch !== null && (!ctype_digit((string) $batch) || (int) $batch <= 0)) {
            $output->writeln('<error>--batch 必须为正整数。</error>');
            return self::INVALID;
        }

        try {
            $result = ModuleServiceFactory::expiryScanner()->run($batch === null ? null : (int) $batch);
            $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
    }
}
