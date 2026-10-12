<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add import_state, to skip the imports whose sources have not changed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_state (name VARCHAR(100) NOT NULL, source_hash VARCHAR(64) NOT NULL, imported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE import_state');
    }
}
