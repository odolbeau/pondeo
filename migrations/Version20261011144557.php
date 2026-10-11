<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011144557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add criterion.rank_from_lowest, to rank a criterion where a high value is a handicap from the lowest value.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE criterion ADD rank_from_lowest BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE criterion DROP rank_from_lowest');
    }
}
