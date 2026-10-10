<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010092620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rebuild the schema around unités urbaines: drop region/departement/epci/poi_commune_location/zone_criterion_value, slim down commune, add unite_urbaine, criterion_value and poi.unite_urbaine_id.';
    }

    public function up(Schema $schema): void
    {
        // Hand-written rather than the raw schema diff: the old zone tables
        // and their rows are all re-importable reference data (see
        // docs/urban-units.md), so they are dropped and recreated instead
        // of migrated column by column — commune would otherwise need a
        // NOT NULL unite_urbaine_id on rows that cannot have one yet.
        // Re-run `app:zones:import` and `app:criteria:population:import`
        // afterwards.
        $this->addSql('DROP TABLE zone_criterion_value');
        $this->addSql('DROP TABLE poi_commune_location');
        $this->addSql('DROP TABLE commune');
        $this->addSql('DROP TABLE epci');
        $this->addSql('DROP TABLE departement');
        $this->addSql('DROP TABLE region');
        $this->addSql('ALTER TABLE criterion DROP zone_type');

        $this->addSql('CREATE TABLE unite_urbaine (insee_code VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, departement VARCHAR(3) DEFAULT NULL, boundary geometry(MULTIPOLYGON, 4326) DEFAULT NULL, centroid geography(POINT, 4326) GENERATED ALWAYS AS (ST_Centroid(boundary)::geography) STORED, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3E775A8115A3C1BC ON unite_urbaine (insee_code)');
        $this->addSql('CREATE INDEX unite_urbaine_boundary_gist ON unite_urbaine USING GIST (boundary)');
        $this->addSql('CREATE INDEX unite_urbaine_centroid_gist ON unite_urbaine USING GIST (centroid)');

        $this->addSql('CREATE TABLE commune (insee_code VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, departement VARCHAR(3) NOT NULL, boundary geometry(MULTIPOLYGON, 4326) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, unite_urbaine_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E2E2D1EE15A3C1BC ON commune (insee_code)');
        $this->addSql('CREATE INDEX IDX_E2E2D1EE883E5F8 ON commune (unite_urbaine_id)');
        $this->addSql('ALTER TABLE commune ADD CONSTRAINT FK_E2E2D1EE883E5F8 FOREIGN KEY (unite_urbaine_id) REFERENCES unite_urbaine (id) ON DELETE RESTRICT NOT DEFERRABLE');

        $this->addSql('CREATE TABLE criterion_value (period SMALLINT NOT NULL, value_decimal NUMERIC(15, 5) DEFAULT NULL, value_integer INT DEFAULT NULL, value_text TEXT DEFAULT NULL, value_boolean BOOLEAN DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, criterion_id UUID NOT NULL, unite_urbaine_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_criterion_value ON criterion_value (criterion_id, unite_urbaine_id, period)');
        $this->addSql('CREATE INDEX IDX_F68BF92C97766307 ON criterion_value (criterion_id)');
        $this->addSql('CREATE INDEX IDX_F68BF92C883E5F8 ON criterion_value (unite_urbaine_id)');
        $this->addSql('ALTER TABLE criterion_value ADD CONSTRAINT FK_F68BF92C97766307 FOREIGN KEY (criterion_id) REFERENCES criterion (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE criterion_value ADD CONSTRAINT FK_F68BF92C883E5F8 FOREIGN KEY (unite_urbaine_id) REFERENCES unite_urbaine (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Defense-in-depth mirror of CriterionValue::validateExactlyOneValue():
        // Doctrine attributes can't express a CHECK, and app-level validation
        // can be bypassed by direct SQL, fixtures or import scripts.
        $this->addSql('ALTER TABLE criterion_value ADD CONSTRAINT chk_cv_exactly_one_value CHECK (num_nonnulls(value_decimal, value_integer, value_text, value_boolean) = 1)');

        $this->addSql('ALTER TABLE poi ADD unite_urbaine_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_7DBB1FD6883E5F8 ON poi (unite_urbaine_id)');
        $this->addSql('ALTER TABLE poi ADD CONSTRAINT FK_7DBB1FD6883E5F8 FOREIGN KEY (unite_urbaine_id) REFERENCES unite_urbaine (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The region/departement/epci/zone tables and their rows are dropped, not migrated; restore from a backup or re-run the previous migrations and imports.');
    }
}
