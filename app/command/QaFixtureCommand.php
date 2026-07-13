<?php

declare(strict_types=1);

namespace app\command;

use plugin\saimulti\service\qa\QaFixtureService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'qa:fixtures', description: '幂等创建、验证或清理 b8im QA 账号与机构')]
final class QaFixtureCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'provision|status|cleanup', 'provision');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        if (!in_array($action, ['provision', 'status', 'cleanup'], true)) {
            $output->writeln('<error>action 必须是 provision、status 或 cleanup。</error>');
            return self::INVALID;
        }
        try {
            $service = new QaFixtureService();
            $result = $service->{$action}();
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return self::FAILURE;
        }
    }
}
