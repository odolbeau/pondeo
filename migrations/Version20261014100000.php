<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unite_urbaine.shop_types, the shop counts by type and year shown on the detail page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unite_urbaine ADD shop_types JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unite_urbaine DROP shop_types');
    }
}
