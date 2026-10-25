<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Infrastructure\Search\MeilisearchSearchAdapter;
use App\Infrastructure\Search\SearchIndexRebuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Replaces the database with the demo dataset and rebuilds the search indexes.
 *
 * Lives in `tests/` on purpose: it depends on `DemoStory`, and `App\Tests\` is
 * autoload-dev. A command in `src/` would make the prod container resolve a
 * class that does not exist there. The class is registered by the
 * `App\Tests\Story\` resource in `config/packages/fixtures.yaml`
 * (`when@dev`/`when@test`), and `#[AsCommand]` is picked up by autoconfigure.
 *
 * The environment guard is defense in depth: even if someone registers this
 * command elsewhere, it refuses to run against anything but dev/test.
 */
#[AsCommand(name: 'app:seed:demo', description: 'Replace the database with demo data and rebuild the search indexes')]
final class SeedDemoCommand extends Command
{
    /**
     * Tasks are asynchronous: the rebuild returns before the engine has applied
     * them, so document counts are polled rather than read once.
     */
    private const int DOCUMENT_WAIT_ATTEMPTS = 40;

    private const int DOCUMENT_WAIT_MICROSECONDS = 250_000;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $entityManager,
        private readonly SearchIndexRebuilder $rebuilder,
        private readonly MeilisearchSearchAdapter $adapter,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Items and collections fetched per page', '100');
        $this->addOption('skip-search', null, InputOption::VALUE_NONE, 'Do not touch the search indexes (the index then keeps documents of deleted rows)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->isAllowedEnvironment()) {
            $output->writeln(\sprintf(
                '<error>app:seed:demo refuses to run in "%s"; only dev and test are allowed.</error>',
                $this->kernel->getEnvironment(),
            ));

            return Command::FAILURE;
        }

        $batchSize = $input->getOption('batch-size');
        if (!\is_string($batchSize) || !\ctype_digit($batchSize) || '0' === $batchSize) {
            $output->writeln('<error>--batch-size must be a positive integer.</error>');

            return Command::INVALID;
        }

        $this->purgeDatabase();
        DemoStory::load();

        if ($input->getOption('skip-search')) {
            $output->writeln('<comment>Search indexes were not touched. Their documents now refer to deleted rows; run "search:reindex --reset" before using search.</comment>');

            return Command::SUCCESS;
        }

        $result = $this->rebuilder->rebuild((int) $batchSize, true);
        $expected = $this->databaseCounts();

        if (!$this->waitForDocumentCounts($expected)) {
            $actual = $this->adapter->documentCounts();
            $output->writeln(\sprintf(
                '<error>Index counts did not reach the database: items %d/%d, collections %d/%d. The write circuit breaker may be open.</error>',
                $actual['items'],
                $expected['items'],
                $actual['collections'],
                $expected['collections'],
            ));

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            'Seeded %d item(s) and %d collection(s); indexed %d and %d.',
            $expected['items'],
            $expected['collections'],
            $result['items'],
            $result['collections'],
        ));

        return Command::SUCCESS;
    }

    private function isAllowedEnvironment(): bool
    {
        return self::allowsEnvironment($this->kernel->getEnvironment());
    }

    /**
     * Pure so the guard can be tested without building the command, whose
     * collaborators are final and cannot be doubled.
     */
    public static function allowsEnvironment(string $environment): bool
    {
        return \in_array($environment, ['dev', 'test'], true);
    }

    /**
     * Users cascade to collections, items, likes, comments, item tags and
     * collection fields. Tags are global and have no owner, so they survive the
     * cascade and are deleted explicitly.
     *
     * TRUNCATE is not an option: MySQL refuses it on tables referenced by a
     * foreign key (error 1701).
     */
    private function purgeDatabase(): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->beginTransaction();

        try {
            $connection->executeStatement('DELETE FROM users');
            $connection->executeStatement('DELETE FROM tags');

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        // The purge bypasses the ORM, so identity-map entries now point at rows
        // that no longer exist.
        $this->entityManager->clear();
    }

    /**
     * @return array{items: int, collections: int}
     */
    private function databaseCounts(): array
    {
        $connection = $this->entityManager->getConnection();

        return [
            'items' => (int) $connection->fetchOne('SELECT COUNT(*) FROM items'),
            'collections' => (int) $connection->fetchOne('SELECT COUNT(*) FROM collections'),
        ];
    }

    /**
     * @param array{items: int, collections: int} $expected
     */
    private function waitForDocumentCounts(array $expected): bool
    {
        for ($attempt = 0; $attempt < self::DOCUMENT_WAIT_ATTEMPTS; ++$attempt) {
            if ($this->adapter->documentCounts() === $expected) {
                return true;
            }

            \usleep(self::DOCUMENT_WAIT_MICROSECONDS);
        }

        return false;
    }
}
