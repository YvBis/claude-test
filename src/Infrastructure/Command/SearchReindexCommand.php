<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Infrastructure\Search\SearchIndexRebuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds both search indexes from the database.
 *
 * Thin by design: the order (provision, optional wipe, walk) lives in
 * `SearchIndexRebuilder`, which `app:seed:demo` shares - two callers, one
 * ordering rule. `--reset` exists because a rebuild that only adds documents
 * cannot remove the ones whose rows are gone; without it, a deleted row leaves
 * a ghost document forever (observed in 6.5, tracked for 9.4).
 */
#[AsCommand(name: 'search:reindex', description: 'Provision the search indexes and rebuild them from the database')]
final class SearchReindexCommand extends Command
{
    public function __construct(
        private readonly SearchIndexRebuilder $rebuilder,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Items and collections fetched per page', '100');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Wipe both indexes before rebuilding, removing documents whose rows no longer exist');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = $input->getOption('batch-size');
        if (!\is_string($batchSize) || !\ctype_digit($batchSize) || '0' === $batchSize) {
            $output->writeln('<error>--batch-size must be a positive integer.</error>');

            return Command::INVALID;
        }

        $result = $this->rebuilder->rebuild((int) $batchSize, (bool) $input->getOption('reset'));

        $output->writeln(\sprintf('Indexed %d item(s), %d collection(s).', $result['items'], $result['collections']));

        return Command::SUCCESS;
    }
}
