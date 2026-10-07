<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Application\Search\ItemReindexer;
use App\Infrastructure\Search\MeilisearchSearchAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provisions the search indexes and rebuilds the items index from the database.
 *
 * Thin by design: provisioning lives on the adapter (it is engine-specific and
 * deliberately not on the port), the walk lives in ItemReindexer. Provisioning
 * runs first and is idempotent, so the command is safe on a fresh engine and
 * on a populated one alike. It is also the repair path for fail-open indexing.
 */
#[AsCommand(name: 'search:reindex', description: 'Provision the search indexes and rebuild them from the database')]
final class SearchReindexCommand extends Command
{
    public function __construct(
        private readonly MeilisearchSearchAdapter $adapter,
        private readonly ItemReindexer $itemReindexer,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Items fetched per page', '100');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = $input->getOption('batch-size');
        if (!\is_string($batchSize) || !\ctype_digit($batchSize) || '0' === $batchSize) {
            $output->writeln('<error>--batch-size must be a positive integer.</error>');

            return Command::INVALID;
        }

        $this->adapter->ensureIndexes();
        $count = $this->itemReindexer->reindexAll((int) $batchSize);

        $output->writeln(\sprintf('Indexed %d item(s).', $count));

        return Command::SUCCESS;
    }
}
