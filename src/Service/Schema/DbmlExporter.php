<?php

declare(strict_types=1);

namespace App\Service\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

class DbmlExporter
{
    private const array SKIPPED_TABLES = ['doctrine_migration_versions'];

    private const array TYPE_ALIASES = [
        'guid' => 'uuid',
        'integer' => 'int',
        'datetime_immutable' => 'timestamp',
        'datetimetz_immutable' => 'timestamptz',
        'date_immutable' => 'date',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function export(): string
    {
        $schema = new SchemaTool($this->entityManager)
            ->getSchemaFromMetadata($this->entityManager->getMetadataFactory()->getAllMetadata());

        $tables = array_filter(
            $schema->getTables(),
            fn (Table $table): bool => !\in_array($this->tableName($table), self::SKIPPED_TABLES, true),
        );
        usort($tables, fn (Table $a, Table $b): int => $this->tableName($a) <=> $this->tableName($b));

        $blocks = array_map($this->renderTable(...), $tables);
        $refs = array_merge(...array_map($this->renderRefs(...), $tables));

        return implode("\n\n", [...$blocks, ...$refs])."\n";
    }

    private function tableName(Table $table): string
    {
        return $table->getObjectName()->getUnqualifiedName()->getValue();
    }

    /**
     * @param list<UnqualifiedName> $names
     *
     * @return list<string>
     */
    private function names(array $names): array
    {
        return array_map(static fn (UnqualifiedName $name): string => $name->getIdentifier()->getValue(), $names);
    }

    /**
     * @return list<string>
     */
    private function primaryColumns(Table $table): array
    {
        $primaryKey = $table->getPrimaryKeyConstraint();

        return null === $primaryKey ? [] : $this->names($primaryKey->getColumnNames());
    }

    /**
     * @return list<string>
     */
    private function indexColumns(Index $index): array
    {
        return array_map(
            static fn (IndexedColumn $column): string => $column->getColumnName()->getIdentifier()->getValue(),
            $index->getIndexedColumns(),
        );
    }

    private function renderTable(Table $table): string
    {
        $lines = [\sprintf('Table %s {', $this->tableName($table))];

        $primaryColumns = $this->primaryColumns($table);
        foreach ($table->getColumns() as $column) {
            $isPrimary = [$column->getObjectName()->getIdentifier()->getValue()] === $primaryColumns;
            $lines[] = '  '.$this->renderColumn($column, $isPrimary);
        }

        $indexes = [];
        if (\count($primaryColumns) > 1) {
            $indexes[] = \sprintf('(%s) [pk]', implode(', ', $primaryColumns));
        }

        foreach ($table->getIndexes() as $index) {
            // The primary key is already rendered inline or above.
            if ($this->indexColumns($index) !== $primaryColumns) {
                $indexes[] = $this->renderIndex($index);
            }
        }

        if ([] !== $indexes) {
            $lines[] = '';
            $lines[] = '  indexes {';
            foreach ($indexes as $index) {
                $lines[] = '    '.$index;
            }

            $lines[] = '  }';
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function renderColumn(Column $column, bool $primaryKey): string
    {
        $settings = [];
        if ($primaryKey) {
            $settings[] = 'pk';
        }

        $settings[] = $column->getNotnull() ? 'not null' : 'null';
        if (null !== $column->getDefault()) {
            $settings[] = 'default: '.$this->renderDefault($column->getDefault());
        }

        if ('' !== $column->getComment()) {
            $settings[] = \sprintf("note: '%s'", addslashes($column->getComment()));
        }

        return \sprintf(
            '%s %s [%s]',
            $column->getObjectName()->getIdentifier()->getValue(),
            $this->renderType($column),
            implode(', ', $settings),
        );
    }

    private function renderType(Column $column): string
    {
        $name = $column->getTypeName();

        return match ($name) {
            'string' => \sprintf('varchar(%d)', $column->getLength() ?? 255),
            'decimal' => \sprintf('decimal(%d,%d)', $column->getPrecision() ?? 10, $column->getScale()),
            default => self::TYPE_ALIASES[$name] ?? $name,
        };
    }

    private function renderDefault(mixed $default): string
    {
        return match (true) {
            \is_bool($default) => $default ? 'true' : 'false',
            \is_int($default), \is_float($default) => (string) $default,
            default => \sprintf("'%s'", addslashes(\is_string($default) ? $default : (string) json_encode($default))),
        };
    }

    private function renderIndex(Index $index): string
    {
        $columns = $this->indexColumns($index);
        $settings = IndexType::UNIQUE === $index->getType() ? ['unique'] : [];
        $settings[] = \sprintf("name: '%s'", $index->getObjectName()->getIdentifier()->getValue());

        return \sprintf('%s [%s]', $this->columnList($columns), implode(', ', $settings));
    }

    /**
     * @return list<string>
     */
    private function renderRefs(Table $table): array
    {
        return array_map(
            fn (ForeignKeyConstraint $fk): string => \sprintf(
                'Ref: %s.%s > %s.%s%s',
                $this->tableName($table),
                $this->columnList($this->names($fk->getReferencingColumnNames())),
                $fk->getReferencedTableName()->getUnqualifiedName()->getValue(),
                $this->columnList($this->names($fk->getReferencedColumnNames())),
                $this->renderReferentialAction($fk->getOnDeleteAction()),
            ),
            array_values($table->getForeignKeys()),
        );
    }

    /**
     * @param list<string> $columns
     */
    private function columnList(array $columns): string
    {
        return 1 === \count($columns) ? $columns[0] : \sprintf('(%s)', implode(', ', $columns));
    }

    private function renderReferentialAction(ReferentialAction $action): string
    {
        if (ReferentialAction::NO_ACTION === $action) {
            return '';
        }

        return \sprintf(' [delete: %s]', strtolower($action->value));
    }
}
