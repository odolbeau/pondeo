<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010162736 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add the aire d'attraction category and area to unite_urbaine.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unite_urbaine ADD attraction_category VARCHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE unite_urbaine ADD attraction_area_code VARCHAR(3) DEFAULT NULL');
        $this->addSql('ALTER TABLE unite_urbaine ADD attraction_area_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unite_urbaine DROP attraction_category');
        $this->addSql('ALTER TABLE unite_urbaine DROP attraction_area_code');
        $this->addSql('ALTER TABLE unite_urbaine DROP attraction_area_name');
    }
}
