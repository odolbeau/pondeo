<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011115232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unite_urbaine.age_pyramid, the population by sex and age bracket shown as a pyramid on the detail page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unite_urbaine ADD age_pyramid JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unite_urbaine DROP age_pyramid');
    }
}
