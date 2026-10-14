<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the "hospital_emergency" POIs and category: an emergency department is now a flag on its hospital.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM poi WHERE category_id IN (SELECT id FROM poi_category WHERE code = 'hospital_emergency')");
        $this->addSql("DELETE FROM poi_category WHERE code = 'hospital_emergency'");
    }

    public function down(Schema $schema): void
    {
        // The re-import of the hospitals gave the flag; nothing to restore.
    }
}
