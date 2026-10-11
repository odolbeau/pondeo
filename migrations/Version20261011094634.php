<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011094634 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add station_access: the closest railway stations of each unité urbaine, with their car route.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE station_access (position SMALLINT NOT NULL, distance_meters INT NOT NULL, duration_seconds INT NOT NULL, period SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, unite_urbaine_id UUID NOT NULL, station_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_station_access ON station_access (unite_urbaine_id, station_id)');
        $this->addSql('CREATE INDEX IDX_C98B38B2883E5F8 ON station_access (unite_urbaine_id)');
        $this->addSql('CREATE INDEX IDX_C98B38B221BDB235 ON station_access (station_id)');
        $this->addSql('ALTER TABLE station_access ADD CONSTRAINT FK_C98B38B2883E5F8 FOREIGN KEY (unite_urbaine_id) REFERENCES unite_urbaine (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE station_access ADD CONSTRAINT FK_C98B38B221BDB235 FOREIGN KEY (station_id) REFERENCES poi (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_access DROP CONSTRAINT FK_C98B38B2883E5F8');
        $this->addSql('ALTER TABLE station_access DROP CONSTRAINT FK_C98B38B221BDB235');
        $this->addSql('DROP TABLE station_access');
    }
}
