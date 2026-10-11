<?php

declare(strict_types=1);

namespace App\Tests\Service\Schema;

use App\Service\Schema\DbmlExporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbmlExporterTest extends KernelTestCase
{
    public function testExportDescribesTablesIndexesAndRelations(): void
    {
        $exporter = self::getContainer()->get(DbmlExporter::class);
        self::assertInstanceOf(DbmlExporter::class, $exporter);
        $dbml = $exporter->export();

        self::assertStringContainsString('Table poi {', $dbml);
        self::assertMatchesRegularExpression('/^  id uuid \[pk, not null\]$/m', $dbml);
        self::assertStringContainsString("name: 'uniq_poi_category_external_id'", $dbml);
        self::assertStringContainsString('Ref: poi.category_id > poi_category.id [delete: restrict]', $dbml);
        self::assertStringContainsString('Ref: criterion_value.unite_urbaine_id > unite_urbaine.id [delete: cascade]', $dbml);
        self::assertStringNotContainsString('doctrine_migration_versions', $dbml);
    }
}
