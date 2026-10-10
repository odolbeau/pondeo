<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010154445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add criterion.group_label, the display section a criterion is listed under.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE criterion ADD group_label VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE criterion DROP group_label');
    }
}
