<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010165959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add station_journey: what each railway station offers towards a destination city on a reference day.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE station_journey (destination VARCHAR(20) NOT NULL, reference_date DATE NOT NULL, transfers SMALLINT DEFAULT NULL, trains_per_day SMALLINT DEFAULT NULL, median_duration_minutes SMALLINT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, station_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_station_journey ON station_journey (station_id, destination)');
        $this->addSql('CREATE INDEX IDX_81B063FC21BDB235 ON station_journey (station_id)');
        $this->addSql('ALTER TABLE station_journey ADD CONSTRAINT FK_81B063FC21BDB235 FOREIGN KEY (station_id) REFERENCES poi (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_journey DROP CONSTRAINT FK_81B063FC21BDB235');
        $this->addSql('DROP TABLE station_journey');
    }
}
