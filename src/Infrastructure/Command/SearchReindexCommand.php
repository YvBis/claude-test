<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Application\Search\CollectionReindexer;
use App\Application\Search\ItemReindexer;
use App\Infrastructure\Search\MeilisearchSearchAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provisions the search indexes and rebuilds them from the database.
 *
 * Thin by design: provisioning lives on the adapter (it is engine-specific and
 * deliberately not on the port), the walks live in the reindexers. Provisioning
 * runs first and is idempotent, so the command is safe on a fresh engine and
 * on a populated one alike. It is also the repair path for fail-open indexing.
 */
#[AsCommand(name: 'search:reindex', description: 'Provision the search indexes and rebuild them from the database')]
final class SearchReindexCommand extends Command
{
    public function __construct(
        private readonly MeilisearchSearchAdapter $adapter,
        private readonly ItemReindexer $itemReindexer,
        private readonly CollectionReindexer $collectionReindexer,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Items and collections fetched per page', '100');
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
        $items = $this->itemReindexer->reindexAll((int) $batchSize);
        $collections = $this->collectionReindexer->reindexAll((int) $batchSize);

        $output->writeln(\sprintf('Indexed %d item(s), %d collection(s).', $items, $collections));

        return Command::SUCCESS;
    }
}
