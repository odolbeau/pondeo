<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add poi.attributes, a JSON bag of source-specific details (e.g. reactors and power of a nuclear plant).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poi ADD attributes JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poi DROP attributes');
    }
}
