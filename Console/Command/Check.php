<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Console\Command;

use Magenx\AiMcp\Model\Diagnostics\ConnectionCheck;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento magenx:mcp:check --admin=<username> | --integration=<name>
 *
 * Runs the endpoint's own authorization and listing code for one caller, in
 * the frontend area the endpoint runs in, and prints what that caller would
 * get. Exits non-zero when the caller could not use the endpoint, so it can
 * gate a deploy.
 */
class Check extends Command
{
    /**
     * @param ConnectionCheck $connectionCheck
     * @param State $appState
     */
    public function __construct(
        private readonly ConnectionCheck $connectionCheck,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('magenx:mcp:check')
            ->setDescription('Show what an admin or integration gets from the AI MCP endpoint.')
            ->addOption('admin', null, InputOption::VALUE_REQUIRED, 'Admin username (OAuth connection or admin token)')
            ->addOption('integration', null, InputOption::VALUE_REQUIRED, 'Integration name');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $admin = (string) $input->getOption('admin');
        $integration = (string) $input->getOption('integration');
        if (($admin === '') === ($integration === '')) {
            $output->writeln('<error>Pass exactly one of --admin or --integration.</error>');

            return Command::INVALID;
        }

        $report = $this->appState->emulateAreaCode(
            Area::AREA_FRONTEND,
            fn (): array => $admin !== ''
                ? $this->connectionCheck->forAdmin($admin)
                : $this->connectionCheck->forIntegration($integration)
        );

        foreach ($report['facts'] as $label => $value) {
            $output->writeln(sprintf('  %-26s %s', $label, $value));
        }
        foreach ($report['problems'] as $problem) {
            $output->writeln('<error>' . $problem . '</error>');
        }
        $output->writeln($report['ok'] ? '<info>OK</info>' : '<error>NOT USABLE</error>');

        return $report['ok'] ? Command::SUCCESS : Command::FAILURE;
    }
}
