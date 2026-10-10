<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010163754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add poi.external_id, unique per category, to make POI imports idempotent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poi ADD external_id VARCHAR(100) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_poi_category_external_id ON poi (category_id, external_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_poi_category_external_id');
        $this->addSql('ALTER TABLE poi DROP external_id');
    }
}
