<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ORM must never want to change the index layout built from migrations.
 *
 * Context (10.7): `users` used to carry `idx_user_email` and `idx_user_is_active`
 * inside `#[ORM\Table(indexes: [...])]`. ORM 3 stores that argument but its
 * driver never reads it (`Mapping\Table` triggers a deprecation and
 * `AttributeDriver` ignores the value), so the declarations never reached the
 * schema - which is also why the update diff stayed quiet. They are gone; the
 * guard pins the consequences: no index DDL in the diff, and no entity that
 * still passes the inert argument.
 *
 * Hand-written `ON DELETE CASCADE` foreign keys on `owner_id` are the one
 * accepted exception: ORM does not model them, so it proposes dropping both the
 * constraint and the index MySQL keeps for it (measured: the three DROP INDEX
 * statements do appear in the diff).
 *
 * Requires a reachable MySQL with migrations applied, same as
 * `OwnerCascadeDeletionTest`; the guard asserts that precondition so an
 * un-migrated database cannot make it pass vacuously.
 */
final class SchemaIndexDriftTest extends KernelTestCase
{
    /** Hand-written `ON DELETE CASCADE` foreign keys, invisible to ORM. */
    private const array HAND_WRITTEN_FK_DROPS = [
        'collections.owner_id' => 'FK_D325D3EE7E3C61F9',
        'likes.owner_id' => 'FK_49CA4E7D7E3C61F9',
        'comments.owner_id' => 'FK_5F9E962A7E3C61F9',
    ];

    /** Indexes MySQL keeps for those foreign keys, as Doctrine proposes dropping them. */
    private const array HAND_WRITTEN_FK_INDEXES = [
        'collections' => 'IDX_D325D3EE7E3C61F9',
        'likes' => 'IDX_49CA4E7D7E3C61F9',
        'comments' => 'IDX_5F9E962A7E3C61F9',
    ];

    public function testSchemaIsMigratedBeforeTheDiffIsTrusted(): void
    {
        $entityManager = $this->entityManager();

        self::assertTrue(
            $entityManager->getConnection()->createSchemaManager()->tablesExist(['users', 'likes', 'comments']),
            'Schema drift cannot be judged on an un-migrated database: introspection returns no tables and the diff is empty.',
        );
    }

    public function testMappingDoesNotDeclareIndexesMissingFromMigrations(): void
    {
        $creations = \array_values(\array_filter(
            $this->updateStatements(),
            static fn (string $sql): bool => 1 === \preg_match('/\bCREATE\s+(UNIQUE\s+)?INDEX\b/i', $sql),
        ));

        self::assertSame([], $creations, "Mapping declares an index no migration creates:\n".\implode("\n", $creations));
    }

    public function testMappingDoesNotDropIndexesThatTheSchemaHas(): void
    {
        $allowed = \array_values(self::HAND_WRITTEN_FK_INDEXES);
        $unexpected = [];

        // MySQL syntax: DROP INDEX name ON table. The repo targets MySQL only.
        foreach ($this->updateStatements() as $sql) {
            if (1 !== \preg_match('/\bDROP\s+(?:INDEX|KEY)\s+(\S+)\s+ON\s+/i', $sql, $matches)) {
                continue;
            }

            if (!\in_array(\strtoupper(\trim($matches[1], '`')), $allowed, true)) {
                $unexpected[] = $sql;
            }
        }

        self::assertSame([], $unexpected, "Mapping dropped an index the schema has:\n".\implode("\n", $unexpected));
    }

    public function testOnlyHandWrittenForeignKeysAreDropped(): void
    {
        $allowed = \array_values(self::HAND_WRITTEN_FK_DROPS);
        $unexpected = [];

        foreach ($this->updateStatements() as $sql) {
            if (1 !== \preg_match('/\bDROP\s+FOREIGN\s+KEY\s+(\S+)\s+ON\s+/i', $sql, $matches)) {
                continue;
            }

            if (!\in_array(\strtoupper(\trim($matches[1], '`')), $allowed, true)) {
                $unexpected[] = $sql;
            }
        }

        self::assertSame([], $unexpected, "Unexpected schema drift:\n".\implode("\n", $unexpected)."\n\nIf the migration added a hand-written foreign key, add its name and table to HAND_WRITTEN_FK_DROPS (and its backing index to HAND_WRITTEN_FK_INDEXES) with a comment saying why ORM cannot see it.");
    }

    public function testNoEntityPassesTheInertTableIndexesArgument(): void
    {
        $offenders = [];

        foreach ($this->entityManager()->getMetadataFactory()->getAllMetadata() as $metadata) {
            $reflection = new \ReflectionClass($metadata->getName());
            $attributes = $reflection->getAttributes(Table::class);

            if ([] === $attributes) {
                continue;
            }

            $table = $attributes[0]->newInstance();

            if (null !== $table->indexes || null !== $table->uniqueConstraints) {
                $offenders[] = $metadata->getName();
            }
        }

        self::assertSame([], $offenders, "ORM 3 ignores indexes/uniqueConstraints on the Table attribute; use #[ORM\\Index] / #[ORM\\UniqueConstraint]:\n".\implode("\n", $offenders));
    }

    /**
     * @return list<string>
     */
    private function updateStatements(): array
    {
        $entityManager = $this->entityManager();

        return \array_values((new SchemaTool($entityManager))
            ->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()));
    }

    private function entityManager(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
